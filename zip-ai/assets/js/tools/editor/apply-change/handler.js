/**
 * ZipWP MCP — Vibe Editing v2: editor/apply-change handler.
 *
 * The WRITE sibling of editor/get-context. The brain's AgentBrowserLoop dispatches
 * a `js_rpc` envelope carrying a serialized Gutenberg EXECUTION PLAN; this handler
 * runs each operation against the LIVE Gutenberg tree via wp.data dispatch, then
 * returns a structured reply that the bridge POSTs to /agent/rpc-reply so the loop
 * folds it IN THE SAME TURN.
 *
 * Contract:
 *   args = { version, post_id, operations:[ { function, ...named args } ] }
 *   Each operation maps to ONE-OR-MORE real wp.data.dispatch('core/block-editor')[function](...)
 *   calls — one per op, except moveBlocksToPosition's reorder ('order') and
 *   multi-parent forms, which fan out to N native moveBlocksToPosition dispatches.
 *   Allowed functions: updateBlockAttributes | insertBlocks | removeBlocks |
 *   moveBlocksToPosition | replaceBlocks | replaceInnerBlocks | duplicateBlocks |
 *   selectBlock. The brain drives them with its native Gutenberg API knowledge.
 *   - updateBlockAttributes PRESERVES clientId (read-merge-dispatch; className
 *     verbatim — the GBS JIT renders it; NO style translation).
 *   - insertBlocks / replace* MINT clientIds (createBlock stamps them synchronously)
 *     → returned in applied[].new_client_ids so the brain can target them next turn.
 *   - moveBlocksToPosition PRESERVES clientIds (a splice). insert/move accept a
 *     before|after anchor that resolves to root+index here.
 *   post_id two-tab guard: abort ALL on mismatch (returned as a `refused` reply,
 *   ok:true, so the LLM sees the real reason — not "browser unreachable").
 *   Partial-apply: a per-op failure (stale clientId) records into failed[] by index
 *   and continues; earlier ops stay applied. Targeting is liveness-checked here;
 *   the brain holds no durable handle.
 *
 * @package
 */
( function () {
    'use strict';

    function isPlainObject( v ) {
        return v !== null && typeof v === 'object' && ! Array.isArray( v );
    }

    // L3 boundary — per-block visual styling lives in `className` ONLY (the
    // Spectra GBS JIT grammar); these flat visual attrs are NEVER allowed on a
    // block. The brain's zod denylist is the contract owner; we re-enforce
    // client-side (defense in depth) so a stale/forked brain bundle can't paint
    // banned attrs into the live tree. The 8-prop list is the SHARED SSOT
    // (editor-shared-utils.isBannedVisualAttr) so this + get-context can never
    // drift. If the shared module isn't resolved we don't strip — the brain's zod
    // already rejected the 8 upstream, so we degrade consistently (never a 3rd copy).
    function isBannedVisualAttr( key ) {
        const u = sharedEditorUtils();
        return !! ( u && typeof u.isBannedVisualAttr === 'function' && u.isBannedVisualAttr( key ) );
    }
    function stripBannedAttrs( attrs ) {
        if ( ! isPlainObject( attrs ) ) {
return attrs;
}
        const clean = {};
        Object.keys( attrs ).forEach( function ( k ) {
            if ( ! isBannedVisualAttr( k ) ) {
clean[ k ] = attrs[ k ];
} else {
console.warn( '[ZIP AI:apply-change] dropped banned visual attr "%s" — styling belongs in className', k );
}
        } );
        return clean;
    }

    // Recursively strip banned visual attrs from an already-PARSED block tree
    // (wp.blocks.parse output). The section_markup insert path bypasses toBlock,
    // so this is where the block path's per-block stripBannedAttrs is reapplied —
    // and the ONLY guard on that path (the vibe-editor door never reaches
    // @bsf/wp-importer's fail-closed validator). Mutates each block's attributes.
    function stripBannedAttrsDeep( block ) {
        if ( ! block || typeof block !== 'object' ) {
            return;
        }
        if ( block.attributes ) {
            block.attributes = stripBannedAttrs( block.attributes );
        }
        if ( Array.isArray( block.innerBlocks ) ) {
            block.innerBlocks.forEach( stripBannedAttrsDeep );
        }
    }

    // Shared editor utilities — resolved at call time from the ONE source
    // (editor/shared/editor-shared-utils.js): window in the browser, require()
    // under jest. currentPostId (post_id guard) lives there so this handler and
    // get-context can never drift. A missing module degrades consistently (null)
    // — never a spurious post_id refusal.
    function sharedEditorUtils() {
        if ( typeof window !== 'undefined' && window.zipwpEditorShared ) {
return window.zipwpEditorShared;
}
        if ( typeof require === 'function' ) {
            try {
 return require( '../shared/editor-shared-utils.js' );
} catch ( e ) {
 return null;
}
        }
        return null;
    }
    function currentPostId() {
        const u = sharedEditorUtils();
        return u && u.currentPostId ? u.currentPostId() : null;
    }

    // Liveness (GAP-C / G34): the clientId must resolve against the live tree.
    // Throws a typed miss so the caller records it in failed[] and never mutates
    // the wrong block (a stale id must NEVER default to "top").
    function liveness( sel, id ) {
        const block = sel.getBlock( id );
        if ( ! block ) {
throw new Error( 'stale_client_id:' + id );
}
        return block;
    }

    // Block-safety guard (SCENARIO-003): a MUTATING op must not touch a block the
    // editor has LOCKED, nor escape THIS page by editing synced-pattern
    // (core/block) inner content or template-locked content. Gutenberg's own lock
    // selectors are authoritative — canEditBlock / canRemoveBlock / canMoveBlock
    // already fold in template lock, content lock, and synced-pattern instance
    // locks (the instance's inner blocks are content-locked). A blocked op throws
    // a typed miss → failed[], so the brain surfaces it (and can tell the user)
    // instead of silently clobbering shared/locked content. Degrades to ALLOW when
    // a selector is absent (older Gutenberg) — never a false block.
    const MUTATION_KIND = {
        updateBlockAttributes: 'edit',
        removeBlocks: 'remove',
        replaceBlocks: 'remove',
        replaceInnerBlocks: 'edit',
        moveBlocksToPosition: 'move',
    };
    function assertMutable( sel, id, kind ) {
        if ( ! id || ! sel.getBlock( id ) ) {
return;
} // stale / missing — liveness owns that miss
        const can = kind === 'remove' ? sel.canRemoveBlock
            : kind === 'move' ? sel.canMoveBlock
                : sel.canEditBlock;
        if ( typeof can === 'function' && can.call( sel, id ) === false ) {
            throw new Error( 'locked_block:' + id );
        }
    }

    // Insert-time lock guard (GBR-2): insertBlocks / duplicateBlocks ADD a block to
    // a CONTAINER — a case canEdit/Remove/Move (assertMutable) does NOT cover, so
    // those two ops previously bypassed the lock check. getTemplateLock on the
    // destination root is authoritative: 'all' (fully locked) and 'insert' (no
    // add/remove/move) both forbid the insertion. Throws a typed miss → failed[] so
    // the brain surfaces it instead of relying on the dispatch to reject. Degrades
    // to ALLOW when the selector is absent (older Gutenberg) — never a false block.
    function assertInsertable( sel, root ) {
        if ( typeof sel.getTemplateLock !== 'function' ) {
return;
}
        // eslint-disable-next-line eqeqeq
        const lock = sel.getTemplateLock( root == null ? '' : root );
        if ( lock === 'all' || lock === 'insert' ) {
            throw new Error( 'locked_block:' + ( root || 'root' ) );
        }
    }

    // ALLOWED-CHILD guard. A parent may restrict which block types it accepts
    // (`allowedBlocks` in block.json — spectra/buttons takes ONLY spectra/button).
    // Gutenberg does not throw on a rejected child: `insertBlocks` mints a clientId
    // and silently drops the block, so the reply carried `new_client_ids` and the
    // structural check then reported `landed:false` with no reason attached. Turn
    // 469d8aa1: "add a paragraph below for disclaimer" with a Button selected
    // inserted a spectra/content NEXT TO that button — inside spectra/buttons — so
    // it could never land. It was retried once, failed identically, and the turn
    // told the user "The disclaimer paragraph has been added below the buttons."
    //
    // Fail LOUD instead, and hand back the fact the model needs to place it well:
    // which type was refused, by which parent, and the nearest ancestor that WOULD
    // take it. The model decides where it belongs — or asks the user — rather than
    // guessing again into the same wall. Degrades to ALLOW when the editor doesn't
    // expose canInsertBlockType, matching assertInsertable's posture.
    function acceptingAncestor( sel, root, name ) {
        if ( typeof sel.getBlockParents !== 'function' ) {
            return null;
        }
        // Nearest ancestor first (getBlockParents returns top-first), then the
        // page root — one selector call, no arbitrary hop cap.
        const chain = root ? sel.getBlockParents( root ).slice().reverse() : [];
        chain.push( '' ); // page root
        for ( let i = 0; i < chain.length; i++ ) {
            const parent = chain[ i ];
            if ( sel.canInsertBlockType( name, parent || undefined ) ) {
                return parent || 'root';
            }
        }
        return null;
    }
    function assertAllowedChildren( sel, root, blocks ) {
        if ( typeof sel.canInsertBlockType !== 'function' ) {
return;
}
        // eslint-disable-next-line eqeqeq
        const target = root == null ? undefined : root;
        for ( let i = 0; i < blocks.length; i++ ) {
            const name = blocks[ i ] && blocks[ i ].name;
            if ( ! name || sel.canInsertBlockType( name, target ) ) {
continue;
}
            const parentBlock = target ? sel.getBlock( target ) : null;
            const parentName = parentBlock ? parentBlock.name : 'the page root';
            const where = acceptingAncestor( sel, target, name );
            throw new Error(
                'child_not_allowed:' + name + ' cannot go inside ' + parentName +
                ( where ? ' — the nearest container that accepts it is ' + where : '' ) +
                '. Place it there, or ask the user where they want it.'
            );
        }
    }

    // M2 — move-DESTINATION lock guard. canMoveBlock (assertMutable 'move')
    // folds in the SOURCE parent's lock only; moving INTO a fully-locked
    // container bypassed every check. templateLock semantics: 'all' forbids any
    // structural change inside the container; 'insert' forbids add/remove but
    // PERMITS moving existing children, so only 'all' blocks a move-in here.
    // Same degrade-to-ALLOW posture as assertInsertable.
    function assertMoveDestination( sel, root ) {
        if ( typeof sel.getTemplateLock !== 'function' ) {
return;
}
        // eslint-disable-next-line eqeqeq
        if ( sel.getTemplateLock( root == null ? '' : root ) === 'all' ) {
            throw new Error( 'locked_block:' + ( root || 'root' ) );
        }
    }

    // ── Subtree scope-lock (selection confinement — SaaS remediation ①) ───────
    // The brain stamps `scope_lock` (the selected container's clientId) on the
    // envelope ONLY when the turn bound a SUBTREE scope (a CONTAINER is selected
    // and the request is NOT page-wide). Every MUTATING op must then act on the
    // locked container or a DESCENDANT of it — editing / inserting / moving into
    // a DIFFERENT section is refused (→ failed[], so the brain surfaces it and
    // can tell the user). This is the tree-aware AIRTIGHT half of "stay inside
    // the selected element"; the brain gate is the soft (outline-only) half.
    // selectBlock is exempt (navigation, not a mutation). Degrades to ALLOW when
    // getBlockParents is absent (older Gutenberg) — never a false block; the
    // caller also drops a stale (non-live) lock id before enforcing.
    const SCOPE_EXEMPT_FUNCTIONS = { selectBlock: true };
    // `id` is undefined/null when a field simply wasn't provided (no constraint
    // from it); '' is the PAGE ROOT (Gutenberg's own rootClientId convention)
    // and must NOT short-circuit true — the page root is never inside a
    // subtree lock.
    function isWithinScope( sel, id, lockId ) {
        if ( id === undefined || id === null || id === lockId ) {
return true;
}
        if ( id === '' ) {
return false;
}
        if ( typeof sel.getBlockParents !== 'function' ) {
return true;
} // can't tell → allow
        const parents = sel.getBlockParents( id ) || [];
        return parents.indexOf( lockId ) !== -1;
    }
    // Every clientId a mutating op acts ON or places relative to (the surface a
    // "roam" would land on): clientIds[], order[], rootClientId, toRootClientId,
    // and the before/after placement anchors.
    function scopeGoverningIds( op ) {
        let ids = [];
        if ( Array.isArray( op.clientIds ) ) {
ids = ids.concat( op.clientIds );
}
        if ( Array.isArray( op.order ) ) {
ids = ids.concat( op.order );
}
        [ 'rootClientId', 'toRootClientId', 'before', 'after', 'clientId' ].forEach( function ( k ) {
            // eslint-disable-next-line eqeqeq
            if ( op[ k ] != null ) {
ids.push( op[ k ] );
}
        } );
        return ids.filter( function ( x ) {
 return typeof x === 'string' && x !== '';
} );
    }
    // The destination container insertBlocks/moveBlocksToPosition will ACTUALLY
    // land in — mirrors runOp's own resolution exactly (an anchor resolves to
    // its PARENT via resolveAnchor; an omitted INSERT root defaults to the PAGE
    // ROOT, ''; an omitted MOVE destination keeps the target's current parent,
    // so it imposes no destination constraint) — so the scope check can never
    // see a different landing spot
    // than the one that really applies. scopeGoverningIds() above checks the
    // RAW anchor/root id (is the id itself in scope); this checks where that
    // id/omission actually RESOLVES to, which is a different question for two
    // reasons: an anchor's parent can be outside scope even when the anchor
    // itself is in scope (before/after the locked container = its parent, one
    // level UP), and an OMITTED destination has no raw field for
    // scopeGoverningIds() to flag at all. What an omission resolves to now
    // depends on the op: an omitted INSERT root is the page root (never in
    // scope); an omitted MOVE keeps each target's current parent, so it adds no
    // destination constraint; an omitted `order` parent is the one those blocks
    // share, which IS resolved here and scope-checked for real.
    function resolvedDestinationOf( sel, op ) {
        const anchor = anchorOf( op );
        if ( anchor ) {
            if ( ! anchor.id || ! sel.getBlock( anchor.id ) ) {
return undefined;
} // stale anchor — liveness() surfaces this separately
            return resolveAnchor( sel, anchor.id, anchor.position ).parent;
        }
        if ( op.function === 'insertBlocks' ) {
// eslint-disable-next-line eqeqeq
return op.rootClientId == null ? '' : op.rootClientId;
}
        if ( op.function === 'moveBlocksToPosition' ) {
            // Mirrors runOp's destination resolution EXACTLY — the invariant this
            // helper exists for. Explicit null = page root. Omitted:
            //   • the `order` form re-orders inside the parent those blocks SHARE, so
            //     resolve it (orderParentOf) and scope-check it for real. Returning
            //     undefined here would skip the check while runOp still landed
            //     somewhere concrete — the exact drift this helper must not have.
            //   • the targeted form keeps each target in its OWN parent
            //     (KEEP_CURRENT_PARENT), which imposes no single destination, and the
            //     targets themselves are already checked by scopeGoverningIds.
            if ( op.toRootClientId === null ) {
return '';
}
            if ( op.toRootClientId === undefined ) {
                return op.order ? orderParentOf( sel, op.order ) : undefined;
            }
            return op.toRootClientId;
        }
        if ( op.function === 'duplicateBlocks' ) {
            // A duplicate lands as a SIBLING of the original, so its destination is
            // the original's PARENT. Without this, duplicating the locked container
            // itself passed the raw-id check (id === lockId) but the copy escaped to
            // the lock's parent, outside scope.
            const first = op.clientIds && op.clientIds[ 0 ];
            return first ? ( sel.getBlockRootClientId( first ) || '' ) : undefined;
        }
        return undefined; // op has no destination-shaped field
    }
    function assertWithinScope( sel, op, lockId ) {
        // Applying a PICKED section — the brain stamps `section_add` on every op
        // whose section_ref it resolved, whether that lands as a page-level ADD
        // (insertBlocks) or as a redesign of an existing one (replaceBlocks /
        // replaceInnerBlocks). Exempt from the subtree lock, exactly as the brain's
        // selection-binding guard does: the picker PINNED its target when it opened,
        // so a click elsewhere while the previews rendered must not redirect the
        // replace. Narrow by design — the marker rides only a brain-resolved ref;
        // free-hand edits, moves, and inline-`blocks` inserts stay confined.
        if ( ! lockId || SCOPE_EXEMPT_FUNCTIONS[ op.function ] || op.section_add === true ) {
return;
}
        const ids = scopeGoverningIds( op );
        if ( ids.length === 0 ) {
            // A mutating op with no resolved target = a page-root op (e.g. insert
            // at root). That lands OUTSIDE the selected container.
            throw new Error( 'out_of_scope: this edit has no in-section target (it would land at the page root), but you are scoped to the selected container ' + lockId + ' — insert/edit INSIDE it, or tell the user if a different section is intended.' );
        }
        for ( let i = 0; i < ids.length; i++ ) {
            if ( ! isWithinScope( sel, ids[ i ], lockId ) ) {
                throw new Error( 'out_of_scope: target ' + ids[ i ] + ' is outside the selected container ' + lockId + ' (and its children). You are scoped to that section — edit inside it, or tell the user if a different section is intended.' );
            }
        }
        const dest = resolvedDestinationOf( sel, op );
        // Duplicating the SELECTED container itself lands the copy as its sibling
        // (in the parent, one level outside the lock) — that IS the user's intent
        // when they select a section and say "duplicate this". Exempt ONLY that
        // exact case (the first duplicated id is the lock root, matching how
        // resolvedDestinationOf derives `dest`); every other out-of-scope
        // destination — a DIFFERENT block copied outside — still throws.
        const duplicatingLockRoot = op.function === 'duplicateBlocks' &&
            Array.isArray( op.clientIds ) && op.clientIds[ 0 ] === lockId;
        if ( dest !== undefined && ! duplicatingLockRoot && ! isWithinScope( sel, dest, lockId ) ) {
            throw new Error( 'out_of_scope: this would land at ' + ( dest === '' ? 'the page root' : dest ) + ', outside the selected container ' + lockId + ' — insert/move INSIDE it, or tell the user if a different section is intended.' );
        }
    }

    // The block's editable-content attribute(s), from the block REGISTRY — no
    // hardcoded per-type table. Core blocks declare content via a `source` of
    // 'rich-text'/'html' (core/heading→content, core/button→text). Spectra blocks
    // store content in a CUSTOM attribute with no standard source, so we fall
    // back to a declared `content`/`text` attribute (the Spectra convention,
    // e.g. spectra/content→text). Block types with neither (image, spacer) get
    // an empty list and are left untouched.
    function richTextAttrsOf( name ) {
        const t = window.wp.blocks && window.wp.blocks.getBlockType
            ? window.wp.blocks.getBlockType( name )
            : null;
        const attrs = t && t.attributes ? t.attributes : {};
        const out = [];
        for ( const k in attrs ) {
            if ( ! Object.prototype.hasOwnProperty.call( attrs, k ) ) {
continue;
}
            const src = attrs[ k ] && attrs[ k ].source;
            if ( src === 'rich-text' || src === 'html' ) {
out.push( k );
}
        }
        if ( out.length === 0 ) {
            [ 'content', 'text' ].forEach( function ( k ) {
                if ( Object.prototype.hasOwnProperty.call( attrs, k ) ) {
out.push( k );
}
            } );
        }
        return out;
    }

    // The block's REGISTRY attribute schema — the SSOT for which attrs are valid
    // on a given block type. getBlockType(name).attributes is the exact set
    // Gutenberg itself validates against; an attr absent from this set is one
    // updateBlockAttributes would silently ignore. Returns null when the type
    // isn't registered (a forked/unknown block) — caller then can't partition, so
    // it applies everything (degrade open, never block a write on a missing type).
    function registeredAttrKeysOf( name ) {
        const t = window.wp.blocks && window.wp.blocks.getBlockType
            ? window.wp.blocks.getBlockType( name )
            : null;
        if ( ! t || ! t.attributes ) {
return null;
}
        return Object.keys( t.attributes );
    }

    // Partition an incoming attrs object against the block's REGISTRY schema:
    // { valid, unknown }. `valid` is the subset whose keys exist in
    // getBlockType(name).attributes (the keys Gutenberg will actually accept);
    // `unknown` is the keys with no schema entry (Gutenberg would silently drop
    // them). `className` is always valid (Gutenberg's universal block-support
    // attr, not always re-declared per type). When the type isn't registered we
    // can't partition — return everything as valid (degrade open). Surfacing
    // `unknown` in the reply lets the brain LEARN that attr X isn't valid for
    // block Y, instead of inferring success from a silent ignore.
    function partitionAttrs( name, attrs ) {
        const valid = {};
        const unknown = [];
        if ( ! isPlainObject( attrs ) ) {
return { valid, unknown };
}
        const registered = registeredAttrKeysOf( name );
        Object.keys( attrs ).forEach( function ( k ) {
            if ( registered === null || k === 'className' || registered.indexOf( k ) !== -1 ) {
                valid[ k ] = attrs[ k ];
            } else {
                unknown.push( k );
            }
        } );
        return { valid, unknown };
    }

    // Route a generic content/text value onto the block's real rich-text attr(s).
    // `content`/`text` are universal authoring ALIASES — the model writes one and
    // we copy it onto whatever rich-text attr the block actually declares (e.g.
    // spectra/content -> `text`, core/heading -> `content`). After routing, DROP
    // any consumed alias the block does NOT itself declare, so a `content` the
    // model sent for a `text`-block isn't later flagged as an unknown attr (it was
    // applied, just under the real key) — a false-positive that would mis-fire the
    // unknown_attrs nudge.
    // Rich-text attrs that hold an ATTRIBUTION rather than the block's body copy, so
    // a generic `content`/`text` rewrite must never be routed into them (core/quote
    // and core/pullquote declare `citation` alongside `value`).
    const ATTRIBUTION_RICH_TEXT_ATTRS = [ 'citation' ];

    function placeContent( name, attributes ) {
        const a = Object.assign( {}, attributes || {} );
        const body = typeof a.content === 'string' && a.content !== '' ? a.content
            : typeof a.text === 'string' && a.text !== '' ? a.text : undefined;
        if ( body === undefined ) {
return a;
}
        const targets = richTextAttrsOf( name );
        // Route to ONE attr, never every rich-text attr the block declares. A block
        // with two (core/quote and core/pullquote both declare `value` AND `citation`
        // as source:html) got the SAME body copied into both, so "rewrite this quote"
        // silently overwrote the attribution with the quote text — a content loss that
        // reports clean, on both the insert and update paths.
        // Prefer the alias the MODEL actually wrote when the block declares it, else
        // the block's first NON-ATTRIBUTION rich-text attr. For every
        // single-rich-text-attr block this resolves to exactly what the fan-out
        // produced, so nothing else moves.
        const usedAlias = typeof a.content === 'string' && a.content !== '' ? 'content' : 'text';
        let primary;
        if ( targets.indexOf( usedAlias ) !== -1 ) {
            primary = usedAlias;
        } else {
            // Taking targets[0] alone would trust REGISTRY ENUMERATION ORDER to put the
            // body attr ahead of the attribution attr. core/quote and core/pullquote
            // happen to declare `value` first, so it is right for them — but a block
            // (third-party, or a future core revision) declaring the attribution first
            // would route the rewrite body straight into it: the same silent
            // content-swap this routing exists to prevent, one block type over. Skip
            // the known attribution attrs explicitly instead of relying on position.
            for ( let ti = 0; ti < targets.length; ti++ ) {
                if ( ATTRIBUTION_RICH_TEXT_ATTRS.indexOf( targets[ ti ] ) === -1 ) {
                    primary = targets[ ti ];
                    break;
                }
            }
            // Every rich-text attr IS an attribution attr (no body attr on this block)
            // — fall back to the first so the write still lands somewhere rather than
            // being silently dropped.
            if ( primary === undefined ) {
                primary = targets[ 0 ];
            }
        }
        if ( primary !== undefined && ( a[ primary ] === undefined || a[ primary ] === '' ) ) {
            a[ primary ] = body;
        }
        [ 'content', 'text' ].forEach( function ( alias ) {
            if ( targets.indexOf( alias ) === -1 ) {
delete a[ alias ];
}
        } );
        return a;
    }

    // Plain text the block renders — its rich-text attrs concatenated, tags
    // stripped. Used for the effectiveness check + the result_text the reply
    // surfaces so the brain SEES what actually landed.
    function renderedTextOf( block ) {
        const attrs = ( block && block.attributes ) || {};
        let s = '';
        richTextAttrsOf( block && block.name ).forEach( function ( k ) {
            // Core rich-text attrs become RichTextData OBJECTS after createBlock
            // (not strings); String() yields their text. Spectra stores plain
            // strings. A `typeof === "string"` check misses the core case and
            // falsely fires assertEffectiveContent's empty_content (live-found).
            const v = attrs[ k ];
            if ( v !== null && v !== undefined && v !== '' ) {
s += ' ' + String( v );
}
        } );
        return s.replace( /<[^>]*>/g, '' ).trim();
    }

    function intendedContent( spec ) {
        const a = ( spec && spec.attributes ) || {};
        return ( typeof a.content === 'string' && a.content !== '' ) ||
            ( typeof a.text === 'string' && a.text !== '' );
    }

    // A CONTENT rewrite that would DROP inline markup — the "make it 4/5" flatten.
    // True when the block's CURRENT value carries real inline markup (a tag, not a
    // bare entity) and the incoming value is PLAIN text. get-context surfaces the
    // rich `html` precisely so a rewrite re-authors the SAME inline structure; a
    // plain string instead silently strips the design pattern (the large number's
    // styling, an emphasis span, per-word colours). PURE so a unit test locks it.
    const CONTENT_TAG_RE = /<[a-z!/][^>]*>/i;
    function contentWouldFlatten( oldVal, newVal ) {
        // Only a PLAIN-text overwrite can flatten. A core RichTextData old value is
        // an object whose String() yields its HTML — coerce so both block families
        // are covered; the incoming model value is always a plain JSON string.
        if ( typeof newVal !== 'string' ) {
            return false;
        }
        const oldStr = ( oldVal === null || oldVal === undefined ) ? '' : String( oldVal );
        return CONTENT_TAG_RE.test( oldStr ) && ! CONTENT_TAG_RE.test( newVal );
    }

    // Fail CLOSED before dispatch when an updateBlockAttributes content write would
    // flatten the block's existing inline markup. The message steers recovery to
    // the correct path (re-author the rich `html` from get_context, keeping the
    // inline structure) rather than letting a lossy plain-text write report a false
    // success. Symmetric with assertEffectiveContent's empty_content guard.
    function assertNoContentFlatten( block, normAttrs ) {
        if ( ! block || ! normAttrs ) {
            return;
        }
        const contentKeys = richTextAttrsOf( block.name );
        const current = block.attributes || {};
        contentKeys.forEach( function ( key ) {
            if ( ! Object.prototype.hasOwnProperty.call( normAttrs, key ) ) {
                return;
            }
            if ( contentWouldFlatten( current[ key ], normAttrs[ key ] ) ) {
                throw new Error(
                    'content_flatten:' + block.name + ':' + key +
                    '. This block\'s content carries inline formatting that a plain-text value would DROP' +
                    ' (the design pattern: styled spans / emphasis). Re-read the block with editor__get_context' +
                    ' and rewrite its `html` (the rich value) with your new words INSIDE the same inline structure,' +
                    ' not a flat string.',
                );
            }
        } );
    }

    // A spec that ASKED for text content but produced an EMPTY block is a silent
    // content loss — fail it LOUD (before dispatch) so the brain never reports a
    // false success on an empty insert/replace. Generalized: it compares INTENT
    // (the spec carried content) against RESULT (the built block renders no text
    // and has no children) — never a per-block-type rule.
    function assertEffectiveContent( specs, blocks ) {
        ( specs || [] ).forEach( function ( spec, i ) {
            const blk = blocks[ i ];
            if ( ! intendedContent( spec ) || ! blk ) {
return;
}
            const hasChildren = blk.innerBlocks && blk.innerBlocks.length > 0;
            if ( renderedTextOf( blk ) === '' && ! hasChildren ) {
                throw new Error( 'empty_content:' + ( spec.name || 'block' ) );
            }
        } );
    }

    // JSON block-spec → Gutenberg block (recursive). NOT the markup-string path.
    // Applies ONLY the attrs the block's registry declares (partitionAttrs, the
    // getBlockType SSOT) and pushes the keys it doesn't into `unknownSink` (when
    // provided) — recursively across innerBlocks. The brain folds every attr
    // through with no allowlist; this is where insert/replace decide validity and
    // report back what they dropped, exactly like the setAttributes path (real
    // Gutenberg's createBlock already sanitizes undeclared attrs away — silently;
    // collecting them here is what makes the drop visible to the brain).
    function toBlock( spec, unknownSink, iconSink ) {
        // Insert-time block-name gate (SaaS remediation plan E2E-F1,
        // live-observed 2026-06-11): an UNREGISTERED name must fail the op
        // typed-and-loud — createBlock would otherwise mint a missing-block
        // placeholder ("Unsupported block") the user has to delete by hand
        // (the model invented `core/input`/`core/textarea` and the page
        // collected junk until read-back). Same feedback philosophy as
        // unknown attrs: structured, model-actionable, never silent.
        if ( ! ( window.wp.blocks &&
            window.wp.blocks.getBlockType &&
            window.wp.blocks.getBlockType( spec.name ) ) ) {
            throw new Error(
                'unregistered_block_type: "' + spec.name + '" is not a registered block on this site — ' +
                'never invent block names. Use registered blocks (spectra/container, spectra/content, ' +
                'core/paragraph, …) or embed a real form via { "name": "srfm/form", "attrs": { "id": <formId> } }.'
            );
        }
        const inner = ( spec.innerBlocks || [] ).map( function ( s ) {
 return toBlock( s, unknownSink, iconSink );
} );
        // S4 (PR #282): strip the 8 GBS-banned visual attrs FIRST, exactly like the
        // updateBlockAttributes path (normalizePlanAttrs) — so insert/replace can't
        // paint a banned visual attr into a freshly-minted block even if a stale/
        // forked brain bundle folds one through. Defense-in-depth behind the brain
        // blockSpec zod; symmetric with the update path.
        const parts = partitionAttrs( spec.name, placeContent( spec.name, stripBannedAttrs( spec.attributes ) ) );
        if ( unknownSink && parts.unknown.length ) {
            Array.prototype.push.apply( unknownSink, parts.unknown );
        }
        // Icon-name resolution — same boundary rule as the update path, so a
        // freshly-minted block can't be born with an inert icon value either.
        resolveIconAttrs( parts.valid, spec.name, iconSink );
        return window.wp.blocks.createBlock( spec.name, parts.valid, inner );
    }

    // updateBlockAttributes shallow-merges at the TOP key only, so deep-merge
    // plain-object values (layout, responsiveControls) to keep sibling nested keys.
    function deepMerge( cur, partial ) {
        const out = Object.assign( {}, cur );
        for ( const k in partial ) {
            if ( ! Object.prototype.hasOwnProperty.call( partial, k ) ) {
continue;
}
            const pv = partial[ k ];
            const cv = cur ? cur[ k ] : undefined;
            out[ k ] = isPlainObject( pv ) && isPlainObject( cv ) ? deepMerge( cv, pv ) : pv;
        }
        return out;
    }

    // INVARIANT — gs-* identity is immutable on a className write. updateBlockAttributes
    // replaces className wholesale, so an incoming string keeps the block's CURRENT gs-*
    // tokens verbatim and contributes only its NON-gs (utility) tokens; foreign gs-* are
    // dropped. Prevents the Edit-5 clobber (a paragraph's `gs-0a6168-text` overwritten by
    // a heading's `gs-ae2ba6-h1`) — the executor owns identity, the model owns utilities.
    function reconcileClassName( currentClassName, incomingClassName ) {
        const isGs = function ( t ) {
 return t.indexOf( 'gs-' ) === 0;
};
        const toTokens = function ( s ) {
            // eslint-disable-next-line eqeqeq
            return String( s == null ? '' : s ).trim().split( /\s+/ ).filter( Boolean );
        };
        const curGs = toTokens( currentClassName ).filter( isGs );
        const incUtil = toTokens( incomingClassName ).filter( function ( t ) {
 return ! isGs( t );
} );
        const seen = Object.create( null );
        return curGs.concat( incUtil ).filter( function ( t ) {
            if ( seen[ t ] ) {
return false;
}
            seen[ t ] = true;
            return true;
        } ).join( ' ' );
    }

    // INVARIANT, INSERT SIDE — the same rule as reconcileClassName above, applied to
    // blocks being CREATED. "The executor owns identity, the model owns utilities"
    // held only for updateBlockAttributes, because that path has a CURRENT className
    // to preserve. An insert has none, so identity was whatever the model typed — and
    // a `gs-…` token is an opaque hash it has to hand-copy from a get_context read
    // several steps earlier. Turn f5999cca: both existing buttons were
    // `pep-button gs-238780-pep-button`; the authored one was `pep-button`. It landed
    // near-black and pill-shaped beside its navy siblings, because the gs- token IS
    // the styling and the readable half paints nothing.
    //
    // So: adopt identity from the nearest sibling of the SAME block name, walking the
    // two subtrees in parallel. The model's non-gs (utility) tokens ride along exactly
    // as they do on an update. No block-type knowledge, no prompt instruction to
    // remember — a new peer simply cannot land without the design identity its
    // neighbours carry. Degrades silently: no sibling, no match, or a divergent shape
    // leaves the block exactly as authored.
    // (Posture note: this executor-owned identity rewrite is the opposite of
    // unknown_icons' apply-and-report — deliberate: gs- identity is the
    // update-path invariant the executor owns; icon values are model content.)
    function adoptSiblingIdentity( sel, root, blocks ) {
        if (
            typeof sel.getBlockOrder !== 'function' ||
            typeof sel.getBlockName !== 'function' ||
            typeof sel.getBlock !== 'function'
        ) {
            return;
        }
        // getBlockOrder + per-id name probe is O(siblings); getBlocks(root)
        // materialized the ENTIRE destination tree (114-section pages) just to
        // take the first same-name sibling.
        // eslint-disable-next-line eqeqeq
        const order = sel.getBlockOrder( root == null || root === '' ? undefined : root ) || [];
        blocks.forEach( function ( b ) {
            const matchId = order.find( function ( id ) {
                return sel.getBlockName( id ) === b.name;
            } );
            if ( matchId ) {
                adoptFrom( sel.getBlock( matchId ), b );
            }
        } );
    }
    function hasGsIdentity( block ) {
        const cls = block && block.attributes && block.attributes.className;
        return typeof cls === 'string' && /(^|\s)gs-/.test( cls );
    }
    function adoptFrom( from, to ) {
        // Adoption supplies identity a block LACKS. A block that already carries
        // its own `gs-` token is a DESIGNED artifact — a whole section off the
        // resolved-markup path, whose persisted GBS rules are keyed to exactly
        // that token. Overwriting it with a neighbour's (reconcileClassName keeps
        // the CURRENT gs- and drops the incoming one) made the placed section and
        // its whole subtree wear the neighbour's styling while its own rules
        // matched nothing: add a designed section beside an existing
        // same-type one and it rendered as the neighbour, not as the design the
        // user chose. Returning here also stops the positional subtree walk,
        // which is what carried the corruption down into the inner blocks.
        if ( hasGsIdentity( to ) ) {
            return;
        }
        const fromCls = from.attributes && from.attributes.className;
        if ( typeof fromCls === 'string' && fromCls.indexOf( 'gs-' ) !== -1 ) {
            to.attributes = to.attributes || {};
            to.attributes.className = reconcileClassName( fromCls, to.attributes.className );
        }
        const fromKids = from.innerBlocks || [];
        const toKids = to.innerBlocks || [];
        for ( let i = 0; i < toKids.length; i++ ) {
            // Pair by position, then by name — a divergent shape stops the walk.
            const peer = fromKids[ i ] && fromKids[ i ].name === toKids[ i ].name
                ? fromKids[ i ]
                : fromKids.filter( function ( k ) {
 return k && k.name === toKids[ i ].name;
} )[ 0 ];
            if ( peer ) {
adoptFrom( peer, toKids[ i ] );
}
        }
    }

    // Every listed block must actually be a child of `parent`. A re-order is only
    // meaningful among SIBLINGS, and Gutenberg does NOT refuse a non-child: with
    // from === to it takes its same-parent branch and computes
    // `subState.indexOf(clientIds[0])`, which is -1 for a stranger. The resulting
    // moveTo(order, -1, i) splices from the END, so the container's LAST child is
    // displaced while the block the plan actually named never moves — and the op
    // reports success. Refuse instead, on BOTH order paths: the derived parent
    // (below) and the caller-supplied one, which had no check at all.
    function assertOrderSiblings( sel, order, parent ) {
        ( order || [] ).forEach( function ( id ) {
            if ( ( sel.getBlockRootClientId( id ) || '' ) !== parent ) {
                throw new Error(
                    'order_parent_mismatch: block ' + id + ' is not a child of the container being ' +
                        're-ordered, so there is no single container to re-order these in. Re-order only ' +
                        'siblings, or move them one at a time.'
                );
            }
        } );
    }

    // The parent a bare `order` re-order should happen INSIDE — the parent the listed
    // blocks already share. '' (the page root) is a legitimate answer for a top-level
    // re-order. Falls back to '' only if the first id can't be resolved, which
    // liveness() surfaces separately.
    function orderParentOf( sel, order ) {
        const ids = order || [];
        const first = ids[ 0 ];
        if ( ! first ) {
            return '';
        }
        // Adopting the first id's parent when the others don't share it would
        // RELOCATE them into it — the very re-parenting this resolution exists to
        // prevent. (The brain now requires an explicit parent, so the derived path
        // is only reachable from an older or hand-built envelope.)
        const parent = sel.getBlockRootClientId( first ) || '';
        assertOrderSiblings( sel, ids, parent );
        return parent;
    }

    // Realize an explicit child order within `parent` (reorder). Place each id at
    // its target index in sequence — earlier indices are already correct, so each
    // move lands the next block at position i.
    function reorderChildren( sel, dis, parent, order ) {
        // M2 — the order form previously bypassed EVERY lock check (its targets
        // ride `order`, not `clientIds`, so the runOp pre-check resolved no
        // targets). canMoveBlock per child folds in the parent's template/content
        // locks — a locked container's reorder now fails typed instead of
        // silently applying.
        order.forEach( function ( id ) {
            liveness( sel, id );
            assertMutable( sel, id, 'move' );
        } );
        // Same omitted-vs-null distinction the move path makes (KEEP_CURRENT_PARENT).
        // Collapsing both to '' re-parented EVERY listed block to the page root, so a
        // plain "re-order these cards" un-nested the whole row. Explicit null still
        // means the page root; omitted means "the parent these blocks already share".
        let root;
        if ( parent === null ) {
            root = '';
            assertOrderSiblings( sel, order, root );
        } else if ( parent === undefined ) {
            root = orderParentOf( sel, order );
        } else {
            root = parent;
            // An EXPLICIT parent was trusted blindly, so a plan naming a container
            // the blocks don't belong to silently mangled that container instead of
            // failing (see assertOrderSiblings). Same check, same refusal.
            assertOrderSiblings( sel, order, root );
        }
        for ( let i = 0; i < order.length; i++ ) {
            // Undo coalescing: each move after the first merges into the same
            // undo level so the whole reorder reverts in one ⌘Z. (The caller
            // already marked the first dispatch when this isn't the batch's
            // first change.)
            if ( i > 0 && typeof dis.__unstableMarkNextChangeAsNotPersistent === 'function' ) {
                dis.__unstableMarkNextChangeAsNotPersistent();
            }
            dis.moveBlocksToPosition( [ order[ i ] ], root, root, i );
        }
    }

    // L5 — THE single documented use of a private Gutenberg API in this
    // codebase. `__unstableMarkNextChangeAsNotPersistent` coalesces undo levels
    // (one ⌘Z per plan) and keeps typewriter ticks out of the undo stack.
    // DEGRADATION CONTRACT: it is feature-checked everywhere; if a future
    // Gutenberg removes/renames it, every call becomes a no-op and behaviour
    // degrades to MORE undo levels (one per dispatch) — correct, just noisier.
    // Nothing may ever depend on it for correctness, only for undo ergonomics.
    function markNonPersistent( dis ) {
        if ( dis && typeof dis.__unstableMarkNextChangeAsNotPersistent === 'function' ) {
            dis.__unstableMarkNextChangeAsNotPersistent();
        }
    }

    // H4 — saving is LOCKED while a typewriter stream is live. The persistent
    // full text is applied by runOp BEFORE the stream starts, but the stream
    // then clears the attr and re-types it word-by-word — so for words×28ms the
    // LIVE edited attribute is partial. A manual Ctrl+S or the 60s autosave in
    // that window serializes the truncated text into the post (non-persistent
    // suppresses the undo level, NOT what getEditedPostContent reads). Lock
    // via the core/editor store while ANY stream is active; unlock on drain.
    // Feature-checked: an editor without lockPostSaving keeps today's window
    // (no false lock, no throw).
    const TYPEWRITER_SAVE_LOCK = 'zipwp-vibe-typewriter';
    function lockSavingForTypewriter() {
        try {
            const ed = window.wp && window.wp.data && window.wp.data.dispatch( 'core/editor' );
            if ( ed && typeof ed.lockPostSaving === 'function' ) {
ed.lockPostSaving( TYPEWRITER_SAVE_LOCK );
}
        } catch ( e ) { /* never break the apply on a lock failure */ }
    }
    function unlockSavingForTypewriter() {
        try {
            const ed = window.wp && window.wp.data && window.wp.data.dispatch( 'core/editor' );
            if ( ed && typeof ed.unlockPostSaving === 'function' ) {
ed.unlockPostSaving( TYPEWRITER_SAVE_LOCK );
}
        } catch ( e ) { /* unlock is best-effort; the lock name is idempotent */ }
    }

    // --- Typewriter (streaming text effect) -------------------------------
    // ANY text the agent writes — a rewrite (updateBlockAttributes) OR new copy in
    // an inserted/replaced block — STREAMS in word-by-word instead of snapping.
    // Ms between words; named single cadence source.
    const TYPEWRITER_WORD_MS = 28;

    // Animation off when the host can't animate (no matchMedia — e.g. jsdom under
    // jest) or the user prefers reduced motion. Keeps the persistent full content.
    function typewriterDisabled() {
        try {
            return ! window.matchMedia ||
                window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
        } catch ( e ) {
 return true;
}
    }

    // Current rich-text content of a block (the first content attr that HAS text),
    // '' if none. The op-agnostic text identity we diff before/after an op.
    function contentOf( blk ) {
        const cattr = firstRichTextWithContent( blk );
        return cattr ? String( blk.attributes[ cattr ] ) : '';
    }

    // Every clientId an op NAMES as an operand — generic id-bearing fields ONLY, no
    // switch on op.function. The before/after text diff over these (+ newly minted
    // ids) is what decides what streams, so no operation is ever special-cased.
    function opOperandIds( op ) {
        let ids = [];
        if ( op ) {
            if ( Array.isArray( op.clientIds ) ) {
ids = ids.concat( op.clientIds );
}
            // eslint-disable-next-line eqeqeq
            if ( op.rootClientId != null ) {
ids.push( op.rootClientId );
}
            // eslint-disable-next-line eqeqeq
            if ( op.clientId != null ) {
ids.push( op.clientId );
}
        }
        return ids;
    }

    // First rich-text content attr of a block that actually HAS text — used to find
    // what to stream in a freshly inserted/replaced block.
    function firstRichTextWithContent( blk ) {
        const cattrs = richTextAttrsOf( blk.name );
        for ( let i = 0; i < cattrs.length; i++ ) {
            const k = cattrs[ i ];
            const v = ( blk.attributes || {} )[ k ];
            if ( typeof v === 'string' && v.trim() ) {
return k;
}
        }
        return null;
    }

    // Active streams + the SINGLE shared ticker that advances them all together, so
    // a BULK operation (many blocks at once) types CONCURRENTLY off one timer.
    let _twStreams = [];
    let _twTimer = null;
    function _twTick() {
        let typing = false;
        for ( let s = 0; s < _twStreams.length; s++ ) {
            const st = _twStreams[ s ];
            if ( st.i >= st.tokens.length ) {
continue;
}
            // Liveness re-check: the block can vanish mid-stream (a later op in the
            // same plan removed/replaced it, or the user deleted it). Writing to a
            // gone block is a wasted dispatch that can warn — drop the stream.
            if ( st.sel && ! st.sel.getBlock( st.id ) ) {
 st.i = st.tokens.length; continue;
}
            // H4 — a throw from updateBlockAttributes must NOT escape the tick:
            // it would skip the drain/unlock below and leave post-saving locked
            // for the rest of the session (the whole page becomes unsavable).
            // The persistent full content is already applied by the caller, so a
            // failed cosmetic type-in is safe to abandon — drop just that stream.
            try {
                st.acc += st.tokens[ st.i ];
                st.i++;
                markNonPersistent( st.dis ); // VISUAL only — never an undo level
                const a = {};
                a[ st.contentAttr ] = st.acc;
                st.dis.updateBlockAttributes( st.id, a );
            } catch ( e ) {
                console.warn( '[ZIP AI] typewriter stream aborted for block', st.id, e ); // eslint-disable-line no-console -- intentional error surfacing
                st.i = st.tokens.length; // mark done so the drain path runs
                continue;
            }
            if ( st.i < st.tokens.length ) {
typing = true;
}
        }
        if ( typing ) {
 _twTimer = setTimeout( _twTick, TYPEWRITER_WORD_MS ); return;
}
        _twStreams = [];
        _twTimer = null;
        // H4 — every stream drained: the live attrs hold full text again, so
        // saving is safe. (Unlock here, the ONLY drain point.)
        unlockSavingForTypewriter();
    }

    // VISUAL typewriter overlay for block `id`'s content attr. The REAL change (the
    // full `target` content) is ALREADY applied PERSISTENTLY by the caller; this
    // only adds a non-persistent type-in, so it NEVER touches undo and works the
    // same for a rewrite or a freshly-inserted block. Clears the content
    // SYNCHRONOUSLY (same frame as the op → no flash of the full text) then types
    // it back word-by-word off the shared ticker. No-op when animation is disabled
    // or the text is too short (leaves the persistent full content).
    function typewriterStream( dis, sel, id, contentAttr, target ) {
        if ( typewriterDisabled() ) {
return;
}
        // eslint-disable-next-line eqeqeq
        const tokens = String( target != null ? target : '' ).split( /(\s+)/ );
        if ( tokens.length <= 2 ) {
return;
}
        // Same-block dedup: if a stream for this block+attr is already active (an
        // earlier op in the plan touched the same block), DROP it — two tickers
        // writing one block's content fight and garble. Last write wins.
        _twStreams = _twStreams.filter( function ( st ) {
            return ! ( st.id === id && st.contentAttr === contentAttr );
        } );
        // H4 — clear FIRST, then take the save-lock. If markNonPersistent or the
        // clear dispatch throws we have NOT locked yet, so the exception
        // propagates cleanly with no lock to leak (the earlier version locked
        // first, so a throwing clear left post-saving locked for the whole
        // session — the exact bug this guards). Once the clear succeeds the live
        // attribute is blank; lock on the very next statement (no async gap, so
        // no save can interleave) and hold it until _twTick drains + unlocks.
        // Idempotent per lock name, so a bulk plan's many streams lock once — and
        // if a later stream's clear throws, an already-running ticker still owns
        // the lock and releases it on drain.
        markNonPersistent( dis );
        const clear = {};
        clear[ contentAttr ] = '';
        dis.updateBlockAttributes( id, clear );
        lockSavingForTypewriter();
        _twStreams.push( { dis, sel, id, contentAttr, tokens, i: 0, acc: '' } );
        if ( ! _twTimer ) {
_twTimer = setTimeout( _twTick, TYPEWRITER_WORD_MS );
}
    }

    // Snapshot the rich-text content of block subtrees by clientId (BEFORE an op),
    // into `out`. Recurses innerBlocks so a container op also captures its children.
    function snapshotContent( sel, ids, out ) {
        ids.forEach( function ( cid ) {
            const root = sel.getBlock( cid );
            if ( ! root ) {
return;
}
            ( function walk( b ) {
                out[ b.clientId ] = contentOf( b );
                if ( b.innerBlocks ) {
b.innerBlocks.forEach( walk );
}
            }( root ) );
        } );
    }

    // SSOT — the ONLY place streaming is decided, AFTER an op: stream every
    // rich-text block (within the op's operand subtrees OR newly minted) whose text
    // CHANGED vs the pre-op snapshot, or is NEW (no snapshot). It's a pure text
    // diff — op.function is NEVER inspected, so a rewrite, an insert, a replace, or
    // any future operation all get the effect identically; a move / remove / style-
    // only change leaves the text equal and streams nothing.
    function streamChangedText( dis, sel, ids, before ) {
        if ( typewriterDisabled() ) {
return;
}
        const seen = {};
        ids.forEach( function ( cid ) {
            const root = sel.getBlock( cid );
            if ( ! root ) {
return;
}
            ( function walk( b ) {
                if ( seen[ b.clientId ] ) {
return;
}
                seen[ b.clientId ] = true;
                const cattr = firstRichTextWithContent( b );
                if ( cattr ) {
                    const now = String( b.attributes[ cattr ] );
                    if ( now && now !== ( before[ b.clientId ] || '' ) ) {
                        typewriterStream( dis, sel, b.clientId, cattr, now );
                    }
                }
                if ( b.innerBlocks ) {
b.innerBlocks.forEach( walk );
}
            }( root ) );
        } );
    }

    // Apply ONE change. Returns { new_client_ids?, result_text?, deferRebase? }
    // or throws a typed Error. `isAnchor` (batch's first applied change) controls
    // the streaming-text undo anchor; it's irrelevant to the non-animated kinds.
    // Resolve an anchor-relative placement (insert/move "before"/"after" a
    // sibling block) into the {parent, index} the dispatch API needs — using the
    // LIVE store (getBlockRootClientId + getBlockIndex). This is why the MODEL no
    // longer computes parent/index: it just names the neighbour and the layer that
    // OWNS the tree resolves the coordinates (closing the parent:null move-to-page-
    // root failure). A stale anchor throws the typed stale_client_id miss via
    // liveness → failed[]. parent is '' for page root (matches getBlockRootClientId
    // + the move toRoot convention). `position` is 'before' | 'after'.
    function resolveAnchor( sel, anchorId, position ) {
        liveness( sel, anchorId );
        return {
            parent: sel.getBlockRootClientId( anchorId ) || '',
            index: sel.getBlockIndex( anchorId ) + ( position === 'after' ? 1 : 0 ),
        };
    }

    // Pull the anchor (before/after) off a change, if present. before/after are
    // mutually exclusive (the brain superRefine enforces it); after wins only if
    // both somehow arrive.
    function anchorOf( c ) {
        if ( c.after !== undefined ) {
return { id: c.after, position: 'after' };
}
        if ( c.before !== undefined ) {
return { id: c.before, position: 'before' };
}
        return null;
    }

    // Blast radius of a DESTRUCTIVE op — the reverse edge the editor otherwise
    // never computes. Returns the JS on OTHER blocks that targets an anchor inside
    // the removed subtree (their `getElementById(deletedAnchor)` goes null and
    // THROWS, killing that block's whole spectraCustomJS IIFE — not a clean no-op).
    // SURFACED, not blocked: the model relays it to the user, and native undo /
    // "don't save" restores the deleted block. Computed BEFORE the dispatch (while
    // the blocks still exist) via the shared dependents SSOT.
    // The removed blocks' OWN subtree (ids + all descendants), walked via
    // getBlockOrder. NOT sel.getClientIdsWithDescendants( clientIds ) — that WP
    // selector ignores its argument and returns EVERY page id, so `subtree`
    // covered the whole page, anchorDependents excluded everything, and
    // orphaned_js was ALWAYS []; the impact warning never fired in production.
    function subtreeOf( sel, clientIds ) {
        const out = [];
        const stack = ( clientIds || [] ).slice();
        while ( stack.length ) {
            const id = stack.pop();
            out.push( id );
            const kids = sel.getBlockOrder ? sel.getBlockOrder( id ) : [];
            for ( let i = 0; i < kids.length; i++ ) {
                stack.push( kids[ i ] );
            }
        }
        return out;
    }
    function removalImpact( sel, clientIds ) {
        const u = sharedEditorUtils();
        if ( ! u || ! u.anchorDependents || ! clientIds || ! clientIds.length ) {
return undefined;
}
        const subtree = subtreeOf( sel, clientIds );
        const anchors = [];
        subtree.forEach( function ( id ) {
            const a = sel.getBlockAttributes ? sel.getBlockAttributes( id ) : null;
            if ( a && typeof a.anchor === 'string' && a.anchor !== '' ) {
anchors.push( a.anchor );
}
        } );
        if ( ! anchors.length ) {
return undefined;
}
        const orphaned = u.anchorDependents( sel, anchors, subtree );
        return orphaned.length ? { orphaned_js: orphaned } : undefined;
    }

    // --- Computed-effect verification: DELETED 2026-08-21 ------------------
    // It asked "did the PAINTED style move?" and treated the answer as a verdict
    // on whether the user's request was met. Those are different questions, and
    // they diverge exactly when the property ALREADY held the requested value —
    // "make this bold" on font-weight:700 text measures as unchanged and was
    // reported as a failure. See the note at the former phase-1/2 site below.
    // canvasCtx survives: phantom-animation detection still reads the canvas.
    function canvasCtx() {
        const iframe = document.querySelector( 'iframe[name="editor-canvas"]' );
        return iframe && iframe.contentDocument
            ? { doc: iframe.contentDocument, win: iframe.contentWindow }
            : { doc: document, win: window };
    }
    // ── Phantom-animation detection (the live-found `animate-bounce` no-op) ─────
    // An `animate-<ident>` utility compiles to a `.animate-<ident>{ animation:
    // <ident> … }` RULE even when NO `@keyframes <ident>` is registered on the
    // site (the JIT's animate-<ident> fallback + a Tailwind default like `bounce`
    // that the GBS system never defines). So the class "applies" and
    // classNameHasLiveUtility sees a rule — but with the keyframes missing the
    // browser paints NOTHING. That is why a bundled `border-red-500 animate-bounce`
    // reported applied (the border moved computed style) while the bounce silently
    // did nothing. We detect it from the RESOLVED cascade, not the token: read the
    // element's computed `animation-name` and flag any name (≠ `none`) that has no
    // matching `@keyframes` rule. Resolved-name based, so it's blind to how the
    // class maps to a keyframe (preset vs fallback) and never false-flags a
    // preset whose keyframes DO exist.

    // Does the canvas define `@keyframes <ident>`? Read via the CSSOM
    // (CSSKeyframesRule — rule type 7 — carries a `.name`), walking @media groups,
    // and skipping unreadable (cross-origin) sheets. Pure over (doc, ident).
    function hasKeyframesRule( doc, ident ) {
        if ( ! doc || ! ident ) {
return false;
}
        function walk( rules ) {
            for ( let i = 0; i < rules.length; i++ ) {
                const r = rules[ i ];
                // CSSRule.KEYFRAMES_RULE === 7.
                if ( r && r.type === 7 && r.name === ident ) {
return true;
}
                // Recurse into ANY grouping rule that carries child rules — @media
                // (4) and @supports (12) plus @layer / @container, whose legacy
                // numeric `type` is 0 and would otherwise be skipped, hiding
                // keyframes nested inside them. (Skip type 7 — its children are the
                // keyframe steps, already handled by the name check above.)
                if ( r && r.type !== 7 && r.cssRules && walk( r.cssRules ) ) {
return true;
}
            }
            return false;
        }
        // Scan both regular sheets and constructable adoptedStyleSheets (Gutenberg
        // / Spectra inject some canvas CSS this way; doc.styleSheets omits them).
        const styleSheets = ( doc && doc.styleSheets ) || [];
        const adopted = ( doc && doc.adoptedStyleSheets ) || [];
        const sheets = [];
        for ( let i = 0; i < styleSheets.length; i++ ) {
sheets.push( styleSheets[ i ] );
}
        for ( let i = 0; i < adopted.length; i++ ) {
sheets.push( adopted[ i ] );
}
        for ( let s = 0; s < sheets.length; s++ ) {
            let rules;
            try {
 rules = sheets[ s ].cssRules || sheets[ s ].rules;
} catch ( e ) {
 continue;
} // cross-origin
            if ( rules && walk( rules ) ) {
return true;
}
        }
        return false;
    }

    // Names in a computed `animation-name` value that have NO `@keyframes` rule →
    // phantom motion (paints nothing). Pure over (animationNameValue, doc) so a
    // unit test locks it. `none` and empty are ignored; duplicates collapse.
    function missingKeyframeNames( animationNameValue, doc ) {
        if ( typeof animationNameValue !== 'string' ) {
return [];
}
        const names = animationNameValue.split( ',' )
            .map( function ( n ) {
 return n.trim();
} )
            .filter( function ( n ) {
 return n && n !== 'none';
} );
        const missing = [];
        for ( let i = 0; i < names.length; i++ ) {
            if ( missing.indexOf( names[ i ] ) === -1 && ! hasKeyframesRule( doc, names[ i ] ) ) {
                missing.push( names[ i ] );
            }
        }
        return missing;
    }

    // The phantom animation names actually resolved on a block (its computed
    // animation-name minus any that have keyframes). '' / unreadable node → [].
    function phantomAnimationsOnBlock( clientId ) {
        const ctx = canvasCtx();
        const el = ctx.doc.querySelector( '[data-block="' + clientId + '"]' );
        if ( ! el ) {
return [];
}
        const cs = ctx.win.getComputedStyle( el );
        return missingKeyframeNames( cs.getPropertyValue( 'animation-name' ), ctx.doc );
    }

    // boostUtilitySpecificity + utilityRuleBody REMOVED — their own stated
    // REMOVAL CONDITION ("once editor-side utilities reach parity with the
    // server JIT") is met by ensureLiveUtilityCss below, which injects the
    // server compiler's OWN output (×5 base + media-wrapped responsive
    // variants) for every token a plan touches. The boost was also the root
    // cause of the stacked-grid-until-reload bug: it re-emitted BASE tokens
    // (e.g. grid-cols-1) at ×5 specificity WITHOUT their media context, so a
    // boosted base beat its md:/lg: variant siblings at every viewport width
    // until a reload dropped the session-scoped boost element. Any lingering
    // `zipwp-gbs-editor-boost` element from an older session dies on reload.

    // ── LIVE-JIT for tokens the canvas has never seen ─────────────────────────
    // ROOT CAUSE this closes: the per-post JIT stylesheet is compiled from SAVED
    // content at editor load (spectra-blocks Engine::enqueue_jit_for_current_post
    // → JitCache::get_for_post). Blocks inserted programmatically AFTER load
    // (Vibe Editing section inserts) can carry utility tokens with NO rule
    // anywhere in the canvas — they render unstyled (grids stack) until the next
    // save + reload. Fix at the source of truth: ask Spectra's OWN compiler
    // (REST /spectra-blocks/v1/global-styles/jit-compile — the same JitCompiler
    // that runs at save) for the missing tokens' CSS and inject it into the
    // canvas BEFORE the settle/no-op check reads computed styles, so the live
    // preview and the verification both converge to saved-page truth instantly.
    // Session-deduped per token; network-tolerant (the rpc reply must never
    // hang on this — hard 1500ms cap, failure = canvas converges on save).
    const _liveJitRequested = {}; // token → 1 (session-scoped request dedupe)
    function collectPlanClassNames( ops ) {
        const out = [];
        function fromBlocks( blocks ) {
            if ( ! blocks ) {
return;
}
            for ( let b = 0; b < blocks.length; b++ ) {
                const blk = blocks[ b ];
                if ( ! blk || typeof blk !== 'object' ) {
continue;
}
                const cn = blk.attributes && blk.attributes.className;
                if ( typeof cn === 'string' && cn.trim() !== '' ) {
out.push( cn );
}
                fromBlocks( blk.innerBlocks );
            }
        }
        for ( let i = 0; i < ops.length; i++ ) {
            const op = ops[ i ] || {};
            if ( op.attributes && typeof op.attributes.className === 'string' ) {
out.push( op.attributes.className );
}
            fromBlocks( op.blocks );
        }
        return out;
    }
    function ensureLiveUtilityCss( classNames ) {
        try {
            const ctx = canvasCtx();
            // Compile EVERY touched token (session-deduped) — not only the ones
            // with no live rule. The live/static sheets carry tokens at MIXED
            // specificities (static 0,2,0; persisted dynamic ×5), and partial
            // injection breaks the responsive cascade: a base token already
            // present at ×5 (e.g. grid-cols-1) beats a freshly-injected
            // md:/lg: variant unless the variant arrives at the SAME server
            // parity alongside it. Server output is the SSOT cascade — inject
            // it wholesale.
            const missing = [];
            for ( let c = 0; c < classNames.length; c++ ) {
                const toks = String( classNames[ c ] ).trim().split( /\s+/ );
                for ( let t = 0; t < toks.length; t++ ) {
                    const tok = toks[ t ];
                    if ( ! tok || tok.indexOf( 'gs-' ) === 0 || _liveJitRequested[ tok ] ) {
continue;
}
                    _liveJitRequested[ tok ] = 1;
                    missing.push( tok );
                }
            }
            if ( ! missing.length ) {
return Promise.resolve();
}
            const apiFetch = window.wp && window.wp.apiFetch;
            if ( ! apiFetch ) {
return Promise.resolve();
}
            const fetchP = apiFetch( {
                path: '/spectra-blocks/v1/global-styles/jit-compile',
                method: 'POST',
                data: { class_strings: [ missing.join( ' ' ) ] },
            } ).then( function ( res ) {
                const css = res && typeof res.css === 'string' ? res.css : '';
                if ( ! css ) {
return;
}
                let styleEl = ctx.doc.getElementById( 'zipwp-gbs-live-jit' );
                if ( ! styleEl ) {
                    styleEl = ctx.doc.createElement( 'style' );
                    styleEl.id = 'zipwp-gbs-live-jit';
                    ( ctx.doc.head || ctx.doc.documentElement ).appendChild( styleEl );
                }
                styleEl.textContent = ( styleEl.textContent || '' ) + '\n' + css;
            } ).catch( function () {
                // Un-mark on FAILURE so a later plan retries. Marking at collection
                // time is what dedupes a burst inside one plan, but keeping the mark
                // after a failed request meant one transient REST error (or a site
                // whose spectra-blocks predates the jit-compile route, where EVERY
                // request 404s) left those tokens uncompiled for the whole editor
                // session. Two user-visible consequences, not one: the section renders
                // unstyled until save+reload, AND classNameHasLiveUtility then reads a
                // correctly-applied className as a no-op — so the paint check reports a
                // false no-op and the brain nudges the model to "fix" working styling.
                // Not un-marked on the 1500ms cap: that request is still in flight and
                // will inject when it lands, so a retry there would just duplicate it.
                for ( let m = 0; m < missing.length; m++ ) {
                    delete _liveJitRequested[ missing[ m ] ];
                }
            } );
            const capP = new Promise( function ( resolve ) {
                setTimeout( resolve, 1500 );
            } );
            return Promise.race( [ fetchP, capP ] );
        } catch ( e ) {
            return Promise.resolve();
        }
    }

    // ── Persist a generated section's GBS class bodies (DEFERRED to Save) ─────
    // A generate_section insert carries `args.styles` (section-scoped schema-v1
    // buckets: classes/wrapperStyles/mediaQuery) authored with the site's SEMANTIC
    // tokens (var(--primary)/var(--heading)/…). ensureLiveUtilityCss above cannot
    // help — it SKIPS gs-* tokens because the JIT only compiles the utility ramp.
    // These custom class bodies do NOT ride the block className, so they need the
    // page GBS store — but writing it NOW would persist to the DB before the user
    // Saves (breaking the "nothing hits the DB until Save" contract and orphaning
    // CSS if they discard). So we QUEUE it (shared.queueSectionGbsForSave): it
    // RENDERS + injects the CSS for a live PREVIEW immediately (a pure compile — no
    // DB write) and flushes the payload to the store only when the editor completes
    // a real Save. Best-effort: a preview failure logs and the section converges on
    // Save; it NEVER fails the insert.
    function persistSectionStyles( styles, postId ) {
        try {
            const apiFetch = window.wp && window.wp.apiFetch;
            const u = sharedEditorUtils();
            if ( ! apiFetch || ! u || ! u.queueSectionGbsForSave ) {
                return Promise.resolve();
            }
            const persistP = u.queueSectionGbsForSave( apiFetch, styles, postId ).catch( function ( e ) {
                // eslint-disable-next-line no-console -- developer signal; the section still inserts + persists on Save
                console.warn( '[ZIP AI:apply-change] section GBS preview failed — section may render unstyled until Save', e );
            } );
            // The preview render folds into the reply-blocking liveCssReady below. A
            // REST *rejection* is caught above, but a stalled/hung socket never
            // settles, which would hold the apply_change reply until the brain's RPC
            // timeout (the false-timeout -> duplicate-content failure the settle
            // tuning avoids). Cap it the same way ensureLiveUtilityCss caps its fetch
            // so the reply always fires; the paint still converges on Save.
            const capP = new Promise( function ( resolve ) {
                setTimeout( resolve, 1500 );
            } );
            return Promise.race( [ persistP, capP ] );
        } catch ( e ) {
            return Promise.resolve();
        }
    }

    // ── Serialized Gutenberg execution plan ({ version, operations[] }). One
    // operation = ONE real wp.data.dispatch('core/block-editor') function + named
    // args, keyed on op.function. Each op reuses the shared guards
    // (createBlock/placeContent/partitionAttrs/stripBannedAttrs/resolveAnchor/
    // liveness/deepMerge). The brain zod already coerced grid + ran the GBS
    // denylist; we re-run them (defense-in-depth) so a direct/forked caller can't
    // crash the canvas or paint banned props.

    function coerceGridLayoutLengthAttrs( attrs ) {
        if ( ! isPlainObject( attrs ) ) {
return attrs;
}
        function coerce( layout ) {
            if ( ! isPlainObject( layout ) || typeof layout.minimumColumnWidth !== 'number' ) {
return layout;
}
            const c = {};
            for ( const k in layout ) {
if ( Object.prototype.hasOwnProperty.call( layout, k ) ) {
c[ k ] = layout[ k ];
}
}
            c.minimumColumnWidth = layout.minimumColumnWidth + 'px';
            return c;
        }
        const out = {};
        for ( const k in attrs ) {
if ( Object.prototype.hasOwnProperty.call( attrs, k ) ) {
out[ k ] = attrs[ k ];
}
}
        if ( 'layout' in out ) {
out.layout = coerce( out.layout );
}
        if ( isPlainObject( out.responsiveControls ) ) {
            const rc = {};
            for ( const bp in out.responsiveControls ) {
                if ( ! Object.prototype.hasOwnProperty.call( out.responsiveControls, bp ) ) {
continue;
}
                const v = out.responsiveControls[ bp ];
                rc[ bp ] = ( isPlainObject( v ) && 'layout' in v )
                    ? Object.assign( {}, v, { layout: coerce( v.layout ) } )
                    : v;
            }
            out.responsiveControls = rc;
        }
        return out;
    }

    // Image-attribute routing: a source tool (find-assets / import-media /
    // ai_generate_image) yields { id, url } and the agent sets a UNIFORM
    // { url, id, alt } on the target block. Map that to the block's REAL image
    // attribute so it lands regardless of block type — most importantly a Spectra
    // container BACKGROUND, which lives in `background:{ type:'image', media:{
    // id, url, type:'image' } }` (NOT a bare `url`, and NOT the `overlay*` keys —
    // those are a separate tint ON TOP of the background). This mirrors exactly
    // what Spectra's own Background control writes (components/background onSelect).
    // Keyed off the block's REGISTERED schema (not a hardcoded name): a native
    // image block (has `url`) keeps {url,id,alt}; a block exposing a `background`
    // object gets the background-media shape. deepMerge at apply time preserves
    // the container's other background keys (position/size/repeat). Conservative:
    // only fires on a bare image url/id with no agent-supplied `background` object.
    // Pull an { id, url } image ref out of a candidate object (any of the
    // url/id-bearing shapes weak models emit).
    function pickImageRef( o ) {
        if ( ! isPlainObject( o ) ) {
return null;
}
        const url = typeof o.url === 'string' && o.url !== '' ? o.url : undefined;
        const id = typeof o.id === 'number'
            ? o.id
            : ( typeof o.id === 'string' && o.id !== '' ? o.id : undefined );
        return url !== undefined || id !== undefined ? { url, id } : null;
    }

    // Find the image the agent intends, from EITHER a bare { url, id } (the
    // contract) OR a mis-built `background` object — models routinely invent the
    // old UAGB shape (`background.backgroundImageDesktop`) or guess `media`/`image`
    // sub-keys. We extract the ref from any of them so the SHAPE the model used
    // can't break the write. Overlay keys are intentionally NOT consulted — an
    // overlay is a separate tint, not the background.
    function extractImageRef( attrs ) {
        const bare = pickImageRef( attrs );
        if ( bare ) {
return bare;
}
        const bg = attrs.background;
        if ( isPlainObject( bg ) ) {
            const keys = [ 'media', 'backgroundImageDesktop', 'image', 'imageDesktop', 'backgroundImage' ];
            for ( let i = 0; i < keys.length; i++ ) {
                const ref = pickImageRef( bg[ keys[ i ] ] );
                if ( ref ) {
return ref;
}
            }
        }
        return null;
    }

    function normalizeImageAttrs( name, attrs, currentAttrs ) {
        if ( ! isPlainObject( attrs ) ) {
return attrs;
}
        const registered = registeredAttrKeysOf( name );
        if ( registered === null ) {
return attrs;
} // unknown block — degrade open
        // Native image block (core/image, spectra/image) takes {url,id,alt} as-is.
        if ( registered.indexOf( 'url' ) !== -1 ) {
return attrs;
}
        // Background-capable container (Spectra): the bg image lives in
        // background.{type:'image', media:{id,url,type:'image'}} — mirror exactly
        // what Spectra's Background control writes. We REBUILD it from the
        // extracted ref (ignoring the model's invented sub-keys), so any input
        // shape lands. deepMerge at apply preserves the block's other background
        // keys (position/size/repeat).
        if ( registered.indexOf( 'background' ) === -1 ) {
return attrs;
}
        const ref = extractImageRef( attrs );
        if ( ref === null ) {
return attrs;
} // no image intent (pure style/overlay edit)
        const out = {};
        Object.keys( attrs ).forEach( function ( k ) {
            if ( k !== 'url' && k !== 'id' && k !== 'alt' && k !== 'background' ) {
out[ k ] = attrs[ k ];
}
        } );
        function freshMedia() {
            const m = { type: 'image' };
            if ( ref.id !== undefined ) {
m.id = ref.id;
}
            if ( ref.url !== undefined ) {
m.url = ref.url;
}
            return m;
        }
        out.background = { type: 'image', media: freshMedia() };
        // A Spectra container ALSO holds per-breakpoint background overrides under
        // responsiveControls.{lg,md,sm}.background. The renderer reads the active
        // device's override FIRST, so a base-only update leaves the old image
        // showing at any breakpoint that has its own image background. Mirror the
        // new image into every responsive breakpoint that CURRENTLY has an image
        // background (don't create one where there isn't, and don't touch a
        // color/gradient/video override). deepMerge at apply preserves each
        // breakpoint's other keys (layout/height).
        const rc = currentAttrs && currentAttrs.responsiveControls;
        if ( isPlainObject( rc ) ) {
            // Merge the mirror ON TOP of any responsiveControls the agent set in
            // THIS op (out already carries it from the copy above) — replacing it
            // wholesale would drop a same-op RC edit (e.g. responsiveControls.md.layout).
            const rcOut = isPlainObject( out.responsiveControls ) ? Object.assign( {}, out.responsiveControls ) : {};
            let mirrored = false;
            Object.keys( rc ).forEach( function ( device ) {
                const dbg = rc[ device ] && rc[ device ].background;
                if ( isPlainObject( dbg ) && dbg.type === 'image' ) {
                    // Per-device shallow merge: keep the agent's other per-device
                    // keys (layout/height) for this breakpoint, swap the background.
                    rcOut[ device ] = Object.assign( {}, rcOut[ device ], {
                        background: { type: 'image', media: freshMedia() },
                    } );
                    mirrored = true;
                }
            } );
            if ( mirrored ) {
out.responsiveControls = rcOut;
}
        }
        return out;
    }

    // ── Icon-name resolution (the write boundary) ────────────────────────
    // Icon-valued attributes (`icon`, `hoverIcon`, any block) render via a
    // DICTIONARY lookup (spectra_blocks_info.uagb_svg_icons — the Font Awesome
    // free set keyed by bare slug) but the attribute TYPE is an open string,
    // so ANY value "applies". A FontAwesome CLASS string
    // ('fa-solid fa-arrow-right') — the grammar the authored-HTML section path
    // legitimately uses — therefore applies cleanly and renders NOTHING
    // (RenderSVG's lookup misses and returns null). Live false positive, turn
    // a226671f: "Added an arrow icon…", canvas unchanged.
    // Resolve at the ONE attrs boundary, symmetric with reconcileClassName:
    // exact library key → FA5 polyfill → the class string's last token minus
    // its fa- prefix. Resolvable → the value is REWRITTEN to the library key
    // (the write then paints). Unresolvable → the write still applies and the
    // name is reported as `unknown_icons` — a registry FACT, same family as
    // unknown_attrs; the model decides. Raw SVG strings ('<svg…'), uploaded-
    // icon OBJECTS, and empty strings (icon clear) pass through untouched; a
    // missing icon registry (non-editor context, jest) skips resolution
    // entirely — this never blocks or fails a write.
    // Icon-valued attrs are read off the block REGISTRY, never a hand list:
    // every icon attr in the library declares type ["string","object"] with an
    // icon-ish key (18 attrs / 14 blocks). No registry → no resolution.
    function iconAttrKeysOf( blockName ) {
        const bt = window.wp && window.wp.blocks && window.wp.blocks.getBlockType &&
            window.wp.blocks.getBlockType( blockName );
        const attrs = bt && bt.attributes;
        if ( ! attrs ) {
            return [];
        }
        return Object.keys( attrs ).filter( function ( k ) {
            const t = attrs[ k ] && attrs[ k ].type;
            return Array.isArray( t ) && t.indexOf( 'string' ) !== -1 &&
                t.indexOf( 'object' ) !== -1 && /icon/i.test( k );
        } );
    }
    function resolveIconName( value ) {
        const info = typeof window !== 'undefined' ? window.spectra_blocks_info : null;
        const lib = info && info.uagb_svg_icons;
        if ( ! lib || typeof value !== 'string' || ! value || value.indexOf( '<svg' ) !== -1 ) {
            return null; // not a resolvable name write — leave untouched
        }
        if ( lib[ value ] ) {
            return value;
        }
        const poly = info.font_awesome_5_polyfill || {};
        if ( poly[ value ] && lib[ poly[ value ] ] ) {
            return poly[ value ];
        }
        const tokens = value.trim().split( /\s+/ );
        const last = tokens[ tokens.length - 1 ].replace( /^fa-/, '' );
        if ( last !== value ) {
            if ( lib[ last ] ) {
                return last;
            }
            if ( poly[ last ] && lib[ poly[ last ] ] ) {
                return poly[ last ];
            }
        }
        return ''; // a name the library does not contain
    }
    function resolveIconAttrs( attrs, blockName, iconSink ) {
        const iconKeys = iconAttrKeysOf( blockName );
        for ( let i = 0; i < iconKeys.length; i++ ) {
            const key = iconKeys[ i ];
            if ( ! Object.prototype.hasOwnProperty.call( attrs, key ) ) {
                continue;
            }
            const resolved = resolveIconName( attrs[ key ] );
            if ( resolved === null ) {
                continue;
            }
            if ( resolved === '' ) {
                if ( iconSink ) {
                    iconSink.push( { block: blockName, attr: key, value: attrs[ key ] } );
                }
            } else if ( resolved !== attrs[ key ] ) {
                attrs[ key ] = resolved;
            }
        }
        return attrs;
    }

    // Normalize a bare attributes object for updateBlockAttributes: GBS strip +
    // grid coerce + content routing + image routing + registry partition
    // (collects unknown_attrs) + icon-name resolution (collects unknown_icons).
    // `currentAttrs` (the live block's attributes) lets image routing mirror a
    // container background into its responsive overrides.
    function normalizePlanAttrs( name, attributes, sink, currentAttrs, iconSink ) {
        let a = stripBannedAttrs( attributes || {} );
        a = coerceGridLayoutLengthAttrs( a );
        a = placeContent( name, a );
        a = normalizeImageAttrs( name, a, currentAttrs );
        const parts = partitionAttrs( name, a );
        if ( sink ) {
Array.prototype.push.apply( sink, parts.unknown );
}
        return resolveIconAttrs( parts.valid, name, iconSink );
    }

    // Sentinel destination meaning "keep each target in its OWN current parent"
    // (a pure re-order). Used when the plan carried an `index` but no explicit
    // `toRootClientId`: '' is the PAGE ROOT in Gutenberg, so defaulting an omitted
    // destination to '' silently RELOCATED a nested block out to the top level.
    // The brain now rejects that plan shape outright; this keeps an older or
    // hand-built envelope from doing damage here.
    const KEEP_CURRENT_PARENT = { __keepCurrentParent: true };

    // Move targets grouped by source root (multi-parent safe; preserves input order
    // at the destination by advancing insertAt per group). Shared shape with the
    // 5-kind move case. `toRoot` may be KEEP_CURRENT_PARENT, in which case each
    // group moves within its own parent.
    function moveTargetsGrouped( sel, dis, targets, toRoot, insertAt ) {
        const groups = {};
        const groupOrder = [];
        ( targets || [] ).forEach( function ( id ) {
            const fromRoot = sel.getBlockRootClientId( id ) || '';
            if ( ! Object.prototype.hasOwnProperty.call( groups, fromRoot ) ) {
                groups[ fromRoot ] = [];
                groupOrder.push( fromRoot );
            }
            groups[ fromRoot ].push( id );
        } );
        let moveIdx = 0;
        groupOrder.forEach( function ( fromRoot ) {
            if ( moveIdx > 0 && typeof dis.__unstableMarkNextChangeAsNotPersistent === 'function' ) {
                dis.__unstableMarkNextChangeAsNotPersistent();
            }
            moveIdx++;
            const grp = groups[ fromRoot ];
            const dest = toRoot === KEEP_CURRENT_PARENT ? fromRoot : toRoot;
            dis.moveBlocksToPosition( grp, fromRoot, dest, insertAt );
            // Advance ONLY when every group lands in the same container. The
            // accumulator exists to preserve input order at a SHARED destination;
            // under KEEP_CURRENT_PARENT each group lands in its own parent, so
            // advancing measures group 2's index against a container group 1 never
            // touched ({clientIds:['a','b'], index:0} across two parents put `b` at
            // index 1 of its own parent instead of the requested 0). An OMITTED
            // index must also stay omitted — `undefined + n` is NaN, which
            // dispatches a garbage position for every group after the first (that
            // half predates the sentinel and hit any multi-parent move that carried
            // no explicit index).
            if ( toRoot !== KEEP_CURRENT_PARENT && insertAt !== undefined ) {
                insertAt += grp.length;
            }
        } );
    }

    // Parse an op's AUTHORITATIVE resolved section markup, if it carries any.
    //
    // `section_markup` is present when the brain materialized the section against
    // the live site — real SureForms/SureDonation ids and re-hosted image urls. The
    // `op.blocks` array is the PRE-resolution snapshot (formId:0, remote urls) and
    // is stale once that happened, so whenever markup is present it WINS.
    //
    // Shared by insertBlocks, replaceBlocks and replaceInnerBlocks. It used to be
    // inline in the insert case only, which is why placing a form-bearing section
    // as a REVAMP shipped an unconfigured placeholder form while the identical
    // section ADDED came out working.
    //
    // Returns an array of blocks, or undefined to fall back to the op.blocks path.
    // Throws only for an unregistered block type — a real, typed op failure.
    function parseResolvedSectionMarkup( op ) {
        const markup = typeof op.section_markup === 'string' ? op.section_markup.trim() : '';
        if ( markup === '' || ! window.wp.blocks || typeof window.wp.blocks.parse !== 'function' ) {
            return undefined;
        }
        let blocks;
        // A parse throw is very unlikely (wp.blocks.parse is tolerant), but if it
        // does, DON'T fail the op — fall back to the op.blocks path.
        try {
            blocks = window.wp.blocks.parse( markup ).filter( function ( b ) {
                return b && b.name;
            } );
        } catch ( e ) {
            return undefined;
        }
        if ( ! Array.isArray( blocks ) || ! blocks.length ) {
            return undefined;
        }
        // F1: wp.blocks.parse maps an UNREGISTERED name to core/missing (which HAS a
        // name, so it survived the filter) — the unregistered_block_type gate toBlock()
        // enforces on the block path is bypassed here. Fail typed-and-loud instead of
        // planting an "Unsupported block" placeholder. Outside the try so it is a real
        // op failure, not swallowed into the fallback.
        const missing = blocks.filter( function ( b ) {
            return b.name === 'core/missing';
        } );
        if ( missing.length ) {
            const orig = missing[ 0 ].attributes && missing[ 0 ].attributes.originalName;
            throw new Error(
                'unregistered_block_type: "' + ( orig || 'unknown' ) +
                    '" is not registered on this site — the section cannot be placed.'
            );
        }
        // F2: the markup path bypasses toBlock → stripBannedAttrs. Reapply it here
        // (symmetric with the block path, S4/PR#282) so a spectra/* block in resolved
        // markup can't paint a GBS-banned visual attr onto the page + GBS store.
        blocks.forEach( stripBannedAttrsDeep );
        return blocks;
    }

    // Apply ONE operation. Returns { new_client_ids?, result_text?, unknown_attrs? }
    // or throws a typed Error (→ failed[]). The allowlist gate (defense-in-depth;
    // the brain zod already gated) is the default case.
    function runOp( sel, dis, op ) {
        // Block-safety pre-check for mutating ops (locked / synced / template).
        const mutKind = MUTATION_KIND[ op.function ];
        if ( mutKind ) {
            // eslint-disable-next-line eqeqeq
            const mutTargets = op.clientIds || ( op.rootClientId != null ? [ op.rootClientId ] : [] );
            mutTargets.forEach( function ( id ) {
 assertMutable( sel, id, mutKind );
} );
        }
        switch ( op.function ) {
            case 'updateBlockAttributes': {
                const ids = op.clientIds || [];
                ids.forEach( function ( id ) {
 liveness( sel, id );
} );
                const sinkU = [];
                const iconSinkU = [];
                // Normalize + fail-closed pre-checks for ALL targets BEFORE any
                // dispatch, so a rejected write (e.g. a content flatten) fails the
                // whole op with NO partial apply — the model gets a clean typed
                // error, not a half-applied bulk edit.
                const planned = ids.map( function ( id ) {
                    const blk = sel.getBlock( id );
                    const norm = normalizePlanAttrs( blk.name, op.attributes, sinkU, blk.attributes, iconSinkU );
                    // FAIL CLOSED — a plain-text content write must not silently
                    // strip the block's inline formatting (the "make it 4/5" defect).
                    assertNoContentFlatten( blk, norm );
                    // gs-* identity is immutable on a className write (anti-clobber):
                    // keep the block's own gs- tokens, apply only the model's utilities.
                    if ( typeof norm.className === 'string' ) {
                        norm.className = reconcileClassName(
                            blk.attributes && blk.attributes.className,
                            norm.className,
                        );
                    }
                    return { id, blk, norm };
                } );
                // Apply the final attrs (persistent). The typewriter flourish is
                // added uniformly afterwards by the runPlan before/after text diff
                // (streamChangedText, SSOT) — never per-op here.
                planned.forEach( function ( p, j ) {
                    // L2 — coalesce a BULK restyle into one undo level: only the
                    // first target's dispatch is persistent (whether THAT one is
                    // persistent is the caller's op-level decision); the rest
                    // merge into it, matching reorderChildren/moveTargetsGrouped.
                    if ( j > 0 ) {
markNonPersistent( dis );
}
                    dis.updateBlockAttributes( p.id, deepMerge( p.blk.attributes || {}, p.norm ) );
                } );
                return {
                    unknown_attrs: sinkU.length ? sinkU : undefined,
                    unknown_icons: iconSinkU.length ? iconSinkU : undefined,
                };
            }
            case 'insertBlocks': {
                const insAnchor = anchorOf( op );
                let insRoot = op.rootClientId;
                let insIndex = op.index;
                if ( insAnchor ) {
                    const ra = resolveAnchor( sel, insAnchor.id, insAnchor.position );
                    insRoot = ra.parent === '' ? null : ra.parent;
                    insIndex = ra.index;
                // eslint-disable-next-line eqeqeq
                } else if ( op.rootClientId != null ) {
                    liveness( sel, op.rootClientId );
                }
                assertInsertable( sel, insRoot ); // GBR-2 — destination container lock
                const sinkI = [];
                const iconSinkI = [];
                // Resolved markup WINS when present (see parseResolvedSectionMarkup).
                // assertEffectiveContent is skipped on that path — it aligns specs to
                // blocks by index, and the parsed blocks don't index-align with the
                // stale op.blocks specs.
                let insBlocks = parseResolvedSectionMarkup( op );
                if ( ! insBlocks || insBlocks.length === 0 ) {
                    // No resolved markup (plain section, no bound site, or it parsed
                    // to nothing) → the proven block-object path, unchanged.
                    insBlocks = ( op.blocks || [] ).map( function ( s ) {
 return toBlock( s, sinkI, iconSinkI );
} );
                    assertEffectiveContent( op.blocks, insBlocks );
                }
                adoptSiblingIdentity( sel, insRoot, insBlocks );
                // Refuse a child the destination will not accept, BEFORE dispatch —
                // Gutenberg would mint ids and drop the block silently.
                assertAllowedChildren( sel, insRoot, insBlocks );
                // eslint-disable-next-line eqeqeq
                dis.insertBlocks( insBlocks, insIndex, insRoot == null ? undefined : insRoot );
                return {
                    new_client_ids: insBlocks.map( function ( b ) {
 return b.clientId;
} ),
                    result_text: insBlocks.map( renderedTextOf ),
                    unknown_attrs: sinkI.length ? sinkI : undefined,
                    unknown_icons: iconSinkI.length ? iconSinkI : undefined,
                };
            }
            case 'removeBlocks': {
                ( op.clientIds || [] ).forEach( function ( id ) {
 liveness( sel, id );
} );
                const rmImpact = removalImpact( sel, op.clientIds );
                dis.removeBlocks( op.clientIds );
                return rmImpact ? { impact: rmImpact } : {};
            }
            case 'moveBlocksToPosition': {
                if ( op.order ) {
 reorderChildren( sel, dis, op.toRootClientId, op.order ); return {};
}
                ( op.clientIds || [] ).forEach( function ( id ) {
 liveness( sel, id );
} );
                const mvAnchor = anchorOf( op );
                let toRoot, insertAt;
                if ( mvAnchor ) {
                    const rm = resolveAnchor( sel, mvAnchor.id, mvAnchor.position );
                    toRoot = rm.parent;
                    insertAt = rm.index;
                } else {
                    // Distinguish an EXPLICIT page-root destination (null) from an
                    // OMITTED one (undefined). Collapsing both to '' meant a plan
                    // carrying only `{clientIds, index}` relocated nested blocks to
                    // the PAGE ROOT — a silent, destructive un-nesting. An omitted
                    // destination now means "re-order within the current parent".
                    if ( op.toRootClientId === null ) {
                        toRoot = '';
                    } else if ( op.toRootClientId === undefined ) {
                        toRoot = KEEP_CURRENT_PARENT;
                    } else {
                        toRoot = op.toRootClientId;
                    }
                    insertAt = op.index;
                }
                // M2 — the destination container must accept a move-in
                // (canMoveBlock above only folds in the SOURCE parent's lock).
                // A keep-current-parent move never crosses a container boundary, so
                // there is no new destination lock to check.
                if ( toRoot !== KEEP_CURRENT_PARENT ) {
                    assertMoveDestination( sel, toRoot );
                }
                moveTargetsGrouped( sel, dis, op.clientIds, toRoot, insertAt );
                return {};
            }
            case 'replaceBlocks': {
                ( op.clientIds || [] ).forEach( function ( id ) {
 liveness( sel, id );
} );
                const sinkR = [];
                const iconSinkR = [];
                // Resolved markup WINS (same as replaceInnerBlocks): an inner-improve
                // section_ref ships real SureForms ids + re-hosted image urls in
                // section_markup; parse that so the swapped-in block matches what the
                // add path produces, falling back to the block objects otherwise.
                let repBlocks = parseResolvedSectionMarkup( op );
                if ( ! repBlocks || repBlocks.length === 0 ) {
                    repBlocks = ( op.blocks || [] ).map( function ( s ) {
                        return toBlock( s, sinkR, iconSinkR );
                    } );
                    assertEffectiveContent( op.blocks, repBlocks );
                }
                const repImpact = removalImpact( sel, op.clientIds );
                dis.replaceBlocks( op.clientIds, repBlocks );
                return {
                    new_client_ids: repBlocks.map( function ( b ) {
 return b.clientId;
} ),
                    result_text: repBlocks.map( renderedTextOf ),
                    unknown_attrs: sinkR.length ? sinkR : undefined,
                    unknown_icons: iconSinkR.length ? iconSinkR : undefined,
                    impact: repImpact,
                };
            }
            case 'replaceInnerBlocks': {
                liveness( sel, op.rootClientId );
                // Structurally this ADDS/REMOVES the container's children — the
                // same class of change insertBlocks/duplicateBlocks need GBR-2
                // for (assertMutable's 'edit' kind above only covers editing the
                // container's OWN attributes, not manipulating its children).
                // Previously missing here, so a templateLock:'all'/'insert'
                // container's content could be wiped via this op alone.
                assertInsertable( sel, op.rootClientId );
                // The OLD children are removed by this op — surface any JS elsewhere
                // that targets an anchor inside them (computed before the swap).
                const innerRemovedImpact = removalImpact( sel, sel.getBlockOrder ? sel.getBlockOrder( op.rootClientId ) : [] );
                const sinkN = [];
                const iconSinkN = [];
                // Resolved markup WINS here too. Without this, placing a section as a
                // REVAMP shipped an unconfigured placeholder form while the identical
                // section ADDED came out working — the same content, two outcomes,
                // decided only by which op the model happened to pick.
                let innerBlocks = parseResolvedSectionMarkup( op );
                if ( ! innerBlocks || innerBlocks.length === 0 ) {
                    innerBlocks = ( op.blocks || [] ).map( function ( s ) {
                        return toBlock( s, sinkN, iconSinkN );
                    } );
                    // Same empty-content guard insertBlocks/replaceBlocks apply — a
                    // spec that asked for text but built an empty block must fail
                    // loud, not silently succeed. No-ops when op.blocks is legitimately
                    // [] (clearing the container is the intentional, allowed case).
                    // Skipped on the markup path for the same index-alignment reason
                    // as insertBlocks.
                    assertEffectiveContent( op.blocks, innerBlocks );
                }
                dis.replaceInnerBlocks( op.rootClientId, innerBlocks );
                return {
                    new_client_ids: innerBlocks.map( function ( b ) {
 return b.clientId;
} ),
                    result_text: innerBlocks.map( renderedTextOf ),
                    unknown_attrs: sinkN.length ? sinkN : undefined,
                    unknown_icons: iconSinkN.length ? iconSinkN : undefined,
                    impact: innerRemovedImpact,
                };
            }
            case 'duplicateBlocks': {
                // Group by ORIGINAL parent, like moveTargetsGrouped already does for
                // moveBlocksToPosition — native duplicateBlocks assumes a single-
                // parent selection (mirrors real UI multi-select, which can only
                // span one parent), so cross-parent clientIds in ONE call misplace
                // later duplicates into the FIRST group's parent (found live
                // 2026-07-14: duplicating a tab's trigger+panel together landed the
                // panel copy inside the trigger row, not beside its sibling panels).
                // Dispatching per root — one native call per parent — keeps each
                // duplicate next to its own original.
                //
                // Snapshotting each root's child order BEFORE its dispatch also
                // recovers the NEW clientIds (Gutenberg mints them internally — we
                // never see them from the dispatch call itself) by diffing after.
                // Without this, a "duplicate this, then customize the copy" plan
                // (e.g. add a tab) has no way to target the copy in a follow-up op.
                const groups = {};
                const groupOrder = [];
                // Drop a selected block whose ANCESTOR is also selected — duplicating
                // the ancestor already copies it, so keeping it mints a redundant copy.
                const selectedIds = op.clientIds || [];
                const topLevelIds = selectedIds.filter( function ( id ) {
                    const parents = typeof sel.getBlockParents === 'function' ? sel.getBlockParents( id ) || [] : [];
                    return ! parents.some( function ( p ) {
                        return selectedIds.indexOf( p ) !== -1;
                    } );
                } );
                topLevelIds.forEach( function ( id ) {
                    liveness( sel, id );
                    const root = sel.getBlockRootClientId( id ) || '';
                    // GBR-2 — a duplicate lands as a sibling, so the PARENT container
                    // must allow insertion (template-locked parent → refuse).
                    assertInsertable( sel, root );
                    if ( ! Object.prototype.hasOwnProperty.call( groups, root ) ) {
                        groups[ root ] = { ids: [], before: sel.getBlockOrder( root ).slice() };
                        groupOrder.push( root );
                    }
                    groups[ root ].ids.push( id );
                } );
                const newIds = [];
                groupOrder.forEach( function ( root, g ) {
                    if ( g > 0 ) {
markNonPersistent( dis );
} // one ⌘Z for the whole plan
                    dis.duplicateBlocks( groups[ root ].ids );
                    const before = groups[ root ].before;
                    sel.getBlockOrder( root ).forEach( function ( id ) {
                        if ( before.indexOf( id ) === -1 ) {
newIds.push( id );
}
                    } );
                } );
                return { new_client_ids: newIds };
            }
            case 'selectBlock': {
                liveness( sel, op.clientId );
                dis.selectBlock( op.clientId );
                return {};
            }
            default:
                throw new Error( 'forbidden_function:' + op.function );
        }
    }

    // [RTRACE] browser-side round-trip tracer. Always pushes structured entries
    // into window.__zipwpTrace (read via agent-browser eval); the console mirror is
    // GATED behind window.ZIPAI_CONFIG.debug so it doesn't spam every shopper's
    // devtools in production (PR #282 S5). Diagnostic only — wrapped so it can
    // never break an apply.
    function rtrace( hop, data ) {
        try {
            const entry = Object.assign( { hop, ts: Date.now() }, data || {} );
            ( window.__zipwpTrace = window.__zipwpTrace || [] ).push( entry );
            if ( window.ZIPAI_CONFIG && window.ZIPAI_CONFIG.debug ) {
                console.log( '[RTRACE] gutenberg ' + hop, entry );
            }
        } catch ( e ) { /* never break the apply on a trace failure */ }
    }

    // F4 — STRUCTURAL-effect verdict for a minting op. insertBlocks /
    // duplicateBlocks / replaceInnerBlocks all MINT clientIds; the op only
    // truly LANDED if those minted blocks are actually in the tree now. A
    // dispatch that produced an applied entry but whose minted ids never
    // appeared (target detached, coalesced away, silent drop) changed nothing —
    // the "Added a sixth card" false success. Non-minting ops
    // (updateBlockAttributes / move / remove) are not this verdict's concern and
    // return true (they carry their own noop/unverifiable verdicts). `getBlock`
    // is the live tree probe, bound by the caller. Pure + exported so a unit
    // test locks the REAL logic against a mock sel.
    function structurallyLanded( op, entry, getBlock ) {
        const fn = op && op.function;
        if ( fn !== 'insertBlocks' && fn !== 'duplicateBlocks' && fn !== 'replaceInnerBlocks' ) {
            return true;
        }
        const ids = ( entry && entry.new_client_ids ) || [];
        // Every id the op DID mint must be live in the tree — a minted-but-absent
        // id is the silent drop we're guarding against (the "sixth card" bug).
        if ( ! ids.every( function ( id ) {
            return !! getBlock( id );
        } ) ) {
            return false;
        }
        // insertBlocks / duplicateBlocks ALWAYS add ≥1 block — zero minted means
        // nothing was added. replaceInnerBlocks may legitimately mint zero
        // (clearing a container's children is a valid structural change; a
        // non-empty blocks[] always mints, so empty here ⇒ intended clear), so
        // it is NOT required to mint.
        if ( ( fn === 'insertBlocks' || fn === 'duplicateBlocks' ) && ids.length === 0 ) {
            return false;
        }
        return true;
    }

    function runPlan( args ) {
        if ( ! window.wp || ! window.wp.data || ! window.wp.blocks ) {
            return { success: false, error: 'block_editor_unavailable' };
        }
        const sel = window.wp.data.select( 'core/block-editor' );
        const dis = window.wp.data.dispatch( 'core/block-editor' );
        if ( ! sel || ! dis ) {
return { success: false, error: 'block_editor_unavailable' };
}

        const livePostId = currentPostId();
        const expectedPostId = args && args.post_id;
        // SEC-2 — FAIL CLOSED. This guard is the SOLE protection against applying a
        // plan to the WRONG open post (a second editor tab). If the brain stamped an
        // expected post_id but the live post id can't be resolved, we cannot verify
        // we are on the right post — refuse rather than apply blind. (Previously this
        // fell open when livePostId was null, skipping the check entirely.)
        // eslint-disable-next-line eqeqeq
        if ( expectedPostId != null && livePostId == null ) {
            return {
                success: true,
                data: {
                    post_id: null, applied: [], failed: [],
                    refused: { reason: 'post_id_unresolvable', detail: 'expected=' + expectedPostId + ',live=null' },
                },
            };
        }
        // eslint-disable-next-line eqeqeq
        if ( expectedPostId != null && livePostId != null && Number( livePostId ) !== Number( expectedPostId ) ) {
            return {
                success: true,
                data: {
                    post_id: livePostId, applied: [], failed: [],
                    refused: { reason: 'post_id_mismatch', detail: 'expected=' + expectedPostId + ',live=' + livePostId },
                },
            };
        }

        // Subtree scope-lock — enforce ONLY when the brain bound one AND the
        // locked container is still live this turn. A stale lock (selection
        // changed / block gone) degrades OPEN — never a false block.
        let scopeLock = args && typeof args.scope_lock === 'string' && args.scope_lock !== ''
            ? args.scope_lock
            : null;
        if ( scopeLock && ! sel.getBlock( scopeLock ) ) {
scopeLock = null;
}

        const ops = ( args && args.operations ) || [];
        const applied = [];
        const failed = [];

        // [RTRACE] EXEC #1 — the whole plan the brain handed to live Gutenberg.
        rtrace( 'runPlan:entry', {
            post_id: args && args.post_id, version: args && args.version,
            livePostId, op_count: ops.length,
            functions: ops.map( function ( o ) {
 return o && o.function;
} ),
        } );

        for ( let i = 0; i < ops.length; i++ ) {
            try {
                const op = ops[ i ] || {};
                // Subtree confinement: refuse a mutating op that reaches OUTSIDE
                // the selected container (→ failed[]); in-scope ops still apply.
                if ( scopeLock ) {
assertWithinScope( sel, op, scopeLock );
}
                // [RTRACE] EXEC #2 — the exact wp.data.dispatch('core/block-editor') call about
                // to fire, with its resolved args + the LIVE target block(s) it will hit.
                // eslint-disable-next-line eqeqeq
                const __tids = op.clientIds || ( op.clientId != null ? [ op.clientId ] : ( op.rootClientId != null ? [ op.rootClientId ] : [] ) );
                rtrace( 'runOp:dispatch', {
                    index: i, fn: op.function,
                    dispatch: "wp.data.dispatch('core/block-editor')." + op.function + '(...)',
                    clientIds: op.clientIds, clientId: op.clientId,
                    rootClientId: op.rootClientId, toRootClientId: op.toRootClientId,
                    index_arg: op.index, order: op.order, before: op.before, after: op.after,
                    blocks: ( op.blocks || [] ).map( function ( s ) {
 return s && s.name;
} ),
                    attr_keys: op.attributes ? Object.keys( op.attributes ) : undefined,
                    className: op.attributes && op.attributes.className,
                    targets: __tids.map( function ( id ) {
                        const blk = sel.getBlock( id );
                        return { clientId: id, live: !! blk, name: blk && blk.name };
                    } ),
                } );
                if ( applied.length > 0 ) {
markNonPersistent( dis );
} // undo coalescing → one ⌘Z
                // SSOT streaming: snapshot operand text BEFORE the op, then stream
                // whatever text CHANGED or APPEARED after — a pure diff, no check on
                // op.function. Works for any operation, present or future.
                const _before = {};
                snapshotContent( sel, opOperandIds( op ), _before );
                const res = runOp( sel, dis, op );
                streamChangedText(
                    dis, sel,
                    opOperandIds( op ).concat( ( res && res.new_client_ids ) || [] ),
                    _before
                );
                const entry = { index: i };
                if ( res && res.new_client_ids && res.new_client_ids.length ) {
entry.new_client_ids = res.new_client_ids;
}
                if ( res && res.result_text ) {
entry.result_text = res.result_text;
}
                if ( res && res.unknown_attrs && res.unknown_attrs.length ) {
entry.unknown_attrs = res.unknown_attrs;
}
                if ( res && res.unknown_icons && res.unknown_icons.length ) {
entry.unknown_icons = res.unknown_icons;
}
                if ( res && res.impact ) {
entry.impact = res.impact;
}
                applied.push( entry );
                // [RTRACE] EXEC #3 — what the dispatch returned (minted clientIds / rendered text / dropped attrs).
                rtrace( 'runOp:result', {
                    index: i, fn: op.function,
                    new_client_ids: res && res.new_client_ids, result_text: res && res.result_text,
                    unknown_attrs: res && res.unknown_attrs, unknown_icons: res && res.unknown_icons,
                } );
            } catch ( e ) {
                failed.push( { index: i, error: ( e && e.message ) || 'apply_failed' } );
                // [RTRACE] EXEC #3b — typed failure (stale_client_id / empty_content / forbidden_function).
                rtrace( 'runOp:failed', { index: i, fn: ( ops[ i ] || {} ).function, error: ( e && e.message ) || 'apply_failed' } );
            }
        }

        // LIVE-JIT: compile + inject CSS for utility tokens this plan introduced
        // (inserted blocks included — the className collector below only covers
        // updateBlockAttributes). Kicked off NOW so the network round-trip runs
        // in parallel with the 120ms paint settle; the settle body awaits it so
        // the no-op check reads computed styles WITH the new rules present.
        const utilCssReady = ensureLiveUtilityCss( collectPlanClassNames( ops ) );

        // Auto-focus: honour an explicit trailing selectBlock (model-driven), else leave selection.
        // eslint-disable-next-line eqeqeq
        const postId = livePostId != null ? livePostId : expectedPostId;
        // Fold the generated-section GBS persist into the readiness promise so the
        // paint settle below waits for the class bodies to land before it reads
        // computed styles. Only when the brain attached section styles AND a valid
        // post id resolved (a real post id is a positive int → truthy).
        const sectionStyles = args && args.styles;
        const gbsReady = sectionStyles && postId
            ? persistSectionStyles( sectionStyles, postId )
            : Promise.resolve();
        const liveCssReady = Promise.all( [ utilCssReady, gbsReady ] );
        return new Promise( function ( resolve ) {
            // M5 — TWO-PHASE settle. The 120ms snapshot is a heuristic: a slow
            // re-render (large page, busy main thread) can leave the computed
            // style untouched at 120ms and repaint later — a FALSE noop that
            // nudges the brain into a pointless restyle retry. So a noop is only
            // CONFIRMED by a second unchanged reading 160ms later; ops whose
            // style already moved at phase 1 (the common case) pay nothing extra
            // and the reply leaves at 120ms exactly as before.
            function finishReply( classNames ) {
                // F4 — stamp landed:false on any minting op whose blocks aren't in
                // the tree now (see structurallyLanded). Runs in BOTH settle paths
                // (fast + phase-2) because it lives here. Only sets the flag when
                // NOT landed — an absent flag means landed (brain degrades-open).
                for ( let s = 0; s < applied.length; s++ ) {
                    const landed = structurallyLanded(
                        ops[ applied[ s ].index ],
                        applied[ s ],
                        function ( id ) {
                            return sel.getBlock( id );
                        }
                    );
                    if ( ! landed ) {
                        applied[ s ].landed = false;
                    }
                    // Phantom motion: a className op whose block resolves an
                    // `animation-name` with NO `@keyframes` (e.g. `animate-bounce`
                    // with no `bounce` keyframes). The class applied but paints no
                    // motion — surface it so the brain never reports the animation
                    // as working (it stays applied; this is advisory, not a failure).
                    // Gate on the op actually INTRODUCING an `animate-*` token so a
                    // plain edit (e.g. `border-red-500`) on a block already carrying
                    // a theme/AOS animation can't mis-attribute that pre-existing
                    // animation as this op's phantom.
                    const op = ops[ applied[ s ].index ];
                    if ( op && op.function === 'updateBlockAttributes' &&
                        op.attributes && typeof op.attributes.className === 'string' &&
                        /(^|\s)animate-/.test( op.attributes.className ) &&
                        Array.isArray( op.clientIds ) ) {
                        const phantoms = [];
                        for ( let t = 0; t < op.clientIds.length; t++ ) {
                            phantomAnimationsOnBlock( op.clientIds[ t ] ).forEach( function ( name ) {
                                if ( phantoms.indexOf( name ) === -1 ) {
 phantoms.push( name );
}
                            } );
                        }
                        if ( phantoms.length ) {
                            applied[ s ].phantom_animations = phantoms;
                        }
                    }
                }
                // [RTRACE] EXEC #4 — the reply Gutenberg sends back (after the
                // paint-settle no-op check — 120ms, +160ms confirm when needed).
                // This is what the bridge POSTs to /agent/rpc-reply → brain folds it.
                rtrace( 'runPlan:reply', {
                    post_id: postId, applied_count: applied.length, failed_count: failed.length,
                    noop_indices: applied.filter( function ( a ) {
 return a.noop;
} ).map( function ( a ) {
 return a.index;
} ),
                    boosted_classNames: classNames,
                    applied: JSON.parse( JSON.stringify( applied ) ),
                    failed: JSON.parse( JSON.stringify( failed ) ),
                } );
                resolve( { success: true, data: { post_id: postId, applied, failed } } );
            }
            setTimeout( function () {
              liveCssReady.then( function () {
                const classNames = [];
                for ( let a = 0; a < applied.length; a++ ) {
                    const o = ops[ applied[ a ].index ];
                    if ( o && o.function === 'updateBlockAttributes' && o.attributes && typeof o.attributes.className === 'string' ) {
                        classNames.push( o.attributes.className );
                    }
                }
                // (COMPUTED-EFFECT CHECK DELETED 2026-08-21 — the verdict layer.)
                //
                // Phase 1 snapshotted every touched block's full computed style,
                // phase 2 re-read it 160ms later, and an unchanged string was
                // stamped `noop`. That is a CHANGE detector answering a
                // SATISFACTION question, and the two only diverge when the answer
                // was already correct — the case where it did the most damage.
                //
                // 50 scenarios through this handler (docs/checker-dryrun-2026-08-21.md
                // in the saas repo): "already bold at 700", "blocked by !important",
                // "blocked by inline style" and "genuinely changed" all produced the
                // SAME verdict — the only thing it truly discriminated was a real
                // token from a typo, which is a spelling check. It also needed four
                // correction layers (classNameHasLiveUtility for the wrong surface,
                // the two-phase settle for timing, renders_on for variants,
                // unverifiable for unreadable nodes) — the signal that the instrument
                // measured the wrong quantity.
                //
                // The reply still carries the FACTS the model cannot infer: `landed`
                // (structurallyLanded, in finishReply), `unknown_attrs`, `failed[]`.
                // Facts go back; the model holds the intent and decides.
                //
                // The 120ms wait STAYS: ensureLiveUtilityCss injects server-parity
                // CSS and the tree must settle before structurallyLanded reads it.
                finishReply( classNames );
              } );
            }, 120 );
        } );
    }

    function handleApplyChange( args ) {
        // Vibe Editing v2: every apply_change is a serialized execution plan
        // ({ post_id?, operations[] }). runPlan validates the live editor, enforces
        // the post_id two-tab guard, applies each operation in order, and returns
        // per-index applied[] / failed[] with computed-effect no-op detection. There
        // is no other shape — the brain zod emits operations[] only.
        return runPlan( args );
    }

    // Register once the bridge is ready (same retry pattern as get-context).
    function initHandler() {
        if ( window.zipwpMcp && window.zipwpMcp.registerTool ) {
            window.zipwpMcp.registerTool(
                'editor/apply-change',
                async function ( args ) {
 return handleApplyChange( args );
},
                { previewMode: 'client' }
            );
        } else {
            setTimeout( initHandler, 100 );
        }
    }

    initHandler();

    // Test-only surface (Node/CommonJS). The browser bundle has no `module`, so
    // this block is inert there and the IIFE registration path above is untouched.
    // Exposes the PURE functions so unit tests exercise the REAL logic (no
    // reimplementation) against a mocked window.wp.data / window.wp.blocks.
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = {
            handleApplyChange,
            runPlan,
            runOp,
            normalizePlanAttrs,
            resolveIconName,
            resolveIconAttrs,
            coerceGridLayoutLengthAttrs,
            placeContent,
            renderedTextOf,
            assertEffectiveContent,
            // Fail-closed content-flatten invariant — a plain-text write must not
            // silently strip a block's inline formatting. Exported PURE so a unit
            // test locks the predicate, not a reimplementation.
            contentWouldFlatten,
            deepMerge,
            // gs-* identity is immutable on a className write (anti-clobber).
            // Exported PURE so a unit test locks the reconcile rule.
            reconcileClassName,
            toBlock,
            // Phantom-animation detection (`animate-bounce` with no keyframes).
            // Exported PURE so unit tests lock the resolved-name → missing-keyframes
            // logic without a live canvas.
            hasKeyframesRule,
            missingKeyframeNames,
            stripBannedAttrs,
            // Registry-driven attr validation — getBlockType(name).attributes is
            // the SSOT for attr validity (mirrors what Gutenberg accepts). Exported
            // PURE so unit tests lock the partition, not a reimplementation.
            partitionAttrs,
            registeredAttrKeysOf,
            // Image-attribute routing — maps the agent's uniform {url,id,alt} to a
            // block's real image attr (incl. container background overlay). Exported
            // PURE so unit tests lock the routing per block type.
            normalizeImageAttrs,
            // Subtree scope-lock (selection confinement) — the tree-aware
            // airtight half of "stay inside the selected element". Exported PURE
            // so unit tests lock the in/out-of-scope decision against a mock sel.
            isWithinScope,
            scopeGoverningIds,
            resolvedDestinationOf,
            assertWithinScope,
            // F4 structural-effect verdict — a minting op only landed if its
            // minted clientIds are in the tree. Exported PURE so a unit test
            // locks the real "did it actually add a block?" logic.
            structurallyLanded,
        };
    }
}() );
