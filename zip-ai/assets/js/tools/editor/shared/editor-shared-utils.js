/**
 * ZipWP MCP — Vibe Editing v2: shared editor utilities.
 *
 * The ONE source of truth for helpers the editor handlers (get-context,
 * apply-change, get/set-scripts, get/set-styles) need identically — so they can
 * never drift:
 *   - currentPostId: the live post id from the editor store, with the same
 *     null-guard. apply-change uses it for the two-tab post_id guard;
 *     get-context returns it in the response.
 *   - editorSelect / editorDispatch: the null-guarded `core/editor` store
 *     select/dispatch accessors. styles read the editing session through these
 *     (getEditedPostAttribute / editPost).
 *   - currentMeta: the live post meta object from the session (or {}), the
 *     read side of the session-scoped styles write.
 *   - blockEditorSelect / blockEditorDispatch / rootClientId: the null-guarded
 *     `core/block-editor` store accessors + the page root container's clientId.
 *     get/set-scripts read/patch a block's `spectraCustomJS` attribute through
 *     these (getBlockAttributes / updateBlockAttributes).
 *   - bannedVisualAttrs / isBannedVisualAttr: the 8 GBS-banned per-block visual
 *     styling props (style, *Color(Hover), boxShadow(Hover), styleAttributes) —
 *     styling lives in `className` ONLY (the Spectra GBS JIT grammar). apply-change
 *     strips them from an incoming attrs write; get-context omits them from the
 *     authorable attr-keys it advertises. ONE list so the two can never drift
 *     (mirrors Laravel's StrictAttrValidator). The 8 props are a sanctioned
 *     constant, not an allowlist — every OTHER attr is decided by the registry.
 *   - isJsCapable: is `spectraCustomJS` actually a registered attribute on this
 *     block type? spectra-blocks-pro only registers it on spectra/spectra-pro
 *     blocks + core/image + core/heading (global-styles/helpers.js
 *     SUPPORTED_BLOCKS) — writing it anywhere else paints the session but is
 *     dropped on Save. Read LIVE off `wp.blocks.getBlockType`, not a mirrored
 *     name list, so it can't drift from whatever the plugin actually registered.
 *
 * Dual-mode: attaches to window.zipwpEditorShared in the browser; CommonJS
 * export for jest.
 *
 * Load order: enqueued before the editor handlers in source mode
 * (react-manager.php) and concatenated ahead of handler.js in the production
 * grunt bundle (matches the `tools/**\/*-utils.js` glob). The handlers also
 * resolve it lazily at call time, so a missing global degrades consistently
 * (post id -> null = the two-tab guard no-ops, exactly as a null
 * getCurrentPostId() would).
 *
 * @package
 */
( function () {
    'use strict';

    function editorSelect() {
        return ( typeof window !== 'undefined' && window.wp && window.wp.data && window.wp.data.select )
            ? window.wp.data.select( 'core/editor' )
            : null;
    }

    function editorDispatch() {
        return ( typeof window !== 'undefined' && window.wp && window.wp.data && window.wp.data.dispatch )
            ? window.wp.data.dispatch( 'core/editor' )
            : null;
    }

    function currentPostId() {
        const editor = editorSelect();
        return editor && typeof editor.getCurrentPostId === 'function'
            ? editor.getCurrentPostId()
            : null;
    }

    // The live post meta object from the editing session (or {} when absent) —
    // the read side of the session-scoped scripts/styles write.
    function currentMeta( sel ) {
        const meta = sel && sel.getEditedPostAttribute ? sel.getEditedPostAttribute( 'meta' ) : null;
        return meta && typeof meta === 'object' ? meta : {};
    }

    // The null-guarded `core/block-editor` store — the read/write side of a
    // block's attributes (get/set-scripts operate on `spectraCustomJS`, an attr).
    // Edits through it are inherently session-scoped: in-memory now, persisted on
    // Save, discarded with the session (same model apply-change uses).
    function blockEditorSelect() {
        return ( typeof window !== 'undefined' && window.wp && window.wp.data && window.wp.data.select )
            ? window.wp.data.select( 'core/block-editor' )
            : null;
    }
    function blockEditorDispatch() {
        return ( typeof window !== 'undefined' && window.wp && window.wp.data && window.wp.data.dispatch )
            ? window.wp.data.dispatch( 'core/block-editor' )
            : null;
    }

    // The page root container's clientId — the first top-level block — the
    // default owner for page-wide JS when no clientId is passed. null when the
    // tree is empty or the store is absent.
    function rootClientId( sel ) {
        const order = sel && sel.getBlockOrder ? sel.getBlockOrder() : null;
        return Array.isArray( order ) && order.length > 0 ? order[ 0 ] : null;
    }

    // The 13 GBS-banned per-block visual styling props. Per-block styling lives in
    // `className` ONLY (the Spectra GBS JIT grammar); these are never authorable
    // attrs even when a block's registry declares them. A sanctioned constant —
    // every OTHER attribute's validity is decided by the registry (getBlockType).
    //
    // HARDCODED MIRROR of config/spectra-contract.json `banned_attrs.keys` in the
    // api-scs-credits-system repo (the SSOT the brain zod + Laravel validator read).
    // Kept in lockstep by hand for now; a generated artifact + cross-repo CI pin is
    // the sanctioned fix (drift here is otherwise silent — apply_change attrs bypass
    // Laravel's validator, so this client strip is the editor's only write-point guard
    // besides the brain denylist). Per the contract's `notes.update_ops`, the full
    // 13-key ban applies name-agnostically on the clientId-targeted apply_change path
    // (no block name at validation time); insert/replace BlockSpecs carry a `name` and
    // are scope-aware (srfm/* exempt) — that scope-awareness is a separate follow-up.
    const bannedVisualAttrs = [
        'style', 'styleAttributes',
        'backgroundColor', 'backgroundColorHover',
        'boxShadow', 'boxShadowHover',
        'textColor', 'textColorHover',
        'numberColor', 'numberColorHover',
        'borderHover', 'iconColorHover',
        'backgroundGradientHover',
    ];
    function isBannedVisualAttr( key ) {
        return bannedVisualAttrs.indexOf( key ) !== -1;
    }

    // True when this block type actually persists `spectraCustomJS` (the
    // registry, not a mirrored name list — see the file header).
    function isJsCapable( blockName ) {
        const blocks = ( typeof window !== 'undefined' && window.wp && window.wp.blocks ) ? window.wp.blocks : null;
        if ( ! blocks || typeof blocks.getBlockType !== 'function' || typeof blockName !== 'string' ) {
            return false;
        }
        const blockType = blocks.getBlockType( blockName );
        return !! ( blockType && blockType.attributes &&
            Object.prototype.hasOwnProperty.call( blockType.attributes, 'spectraCustomJS' ) );
    }

    // ── Dependents (reverse edges) ──────────────────────────────────────────
    // The editor resolves OWNERSHIP (which layer owns a property) but never
    // DEPENDENTS ("who else relies on the resource I'm about to change"). These
    // pure helpers compute a mutating write's blast radius from the LIVE tree —
    // no persistent index — so a destructive/shared write can surface its impact
    // as a structured signal (act-first: the model informs the user, it doesn't
    // silently break things). SSOT here so every handler reads dependents identically.

    // Every id a block's JS references — `getElementById('x')` or a `#x` inside a
    // querySelector. Shared by set-scripts' WRITE-time dead-#id gate AND
    // apply-change's DELETE-time orphan-JS surface, so the two never drift on how
    // JS is parsed.
    function referencedIds( code ) {
        const ids = [];
        if ( typeof code !== 'string' || code === '' ) {
return ids;
}
        let m;
        const reGid = /getElementById\(\s*['"]([A-Za-z][\w-]*)['"]\s*\)/g;
        while ( ( m = reGid.exec( code ) ) !== null ) {
ids.push( m[ 1 ] );
}
        const reQs = /querySelector(?:All)?\(\s*(['"])([^'"]*)\1/g;
        while ( ( m = reQs.exec( code ) ) !== null ) {
            // eslint-disable-next-line no-var
            var idm,
reId = /#([A-Za-z][\w-]*)/g;
            while ( ( idm = reId.exec( m[ 2 ] ) ) !== null ) {
ids.push( idm[ 1 ] );
}
        }
        return ids;
    }

    // Every clientId in the live tree (top-level + descendants).
    function allClientIds( sel ) {
        return ( sel && sel.getClientIdsWithDescendants ) ? sel.getClientIdsWithDescendants() : [];
    }

    // Blocks (excluding `excludeIds`) whose `className` carries `gsToken` — the
    // reverse `class -> blocks` edge. Answers "editing this gs- class body also
    // restyles N other sections" BEFORE the write.
    function classDependents( sel, gsToken, excludeIds ) {
        const out = [];
        if ( ! sel || ! gsToken || ! sel.getBlockAttributes ) {
return out;
}
        const skip = Object.create( null );
        ( excludeIds || [] ).forEach( function ( id ) {
 skip[ id ] = true;
} );
        allClientIds( sel ).forEach( function ( cid ) {
            if ( skip[ cid ] ) {
return;
}
            const a = sel.getBlockAttributes( cid );
            const cn = ( a && typeof a.className === 'string' ) ? a.className : '';
            if ( cn.split( /\s+/ ).indexOf( gsToken ) !== -1 ) {
                out.push( { clientId: cid, blockName: sel.getBlockName ? sel.getBlockName( cid ) : null } );
            }
        } );
        return out;
    }

    // Blocks (excluding `excludeIds`) whose `spectraCustomJS` references any of
    // `anchors` (by `#id` / getElementById) — the reverse `anchor -> scripts`
    // edge. Answers "deleting this block orphans the JS on N others" BEFORE the
    // delete (their getElementById(...) would return null and THROW, killing the
    // whole per-block IIFE — not a clean no-op).
    function anchorDependents( sel, anchors, excludeIds ) {
        const out = [];
        if ( ! sel || ! anchors || ! anchors.length || ! sel.getBlockAttributes ) {
return out;
}
        const want = Object.create( null );
        anchors.forEach( function ( x ) {
 if ( x ) {
want[ x ] = true;
}
} );
        const skip = Object.create( null );
        ( excludeIds || [] ).forEach( function ( id ) {
 skip[ id ] = true;
} );
        allClientIds( sel ).forEach( function ( cid ) {
            if ( skip[ cid ] ) {
return;
}
            const a = sel.getBlockAttributes( cid );
            const js = ( a && typeof a.spectraCustomJS === 'string' ) ? a.spectraCustomJS : '';
            if ( ! js ) {
return;
}
            const hits = referencedIds( js ).filter( function ( id ) {
 return want[ id ];
} );
            if ( hits.length ) {
                out.push( { clientId: cid, blockName: sel.getBlockName ? sel.getBlockName( cid ) : null, refs: hits } );
            }
        } );
        return out;
    }

    // ── GBS page-store persist (SSOT) ──────────────────────────────────────────
    // ONE read→merge→write→render→inject path over the per-page GBS store, shared
    // by editor/set-styles (the styling tool) AND editor/apply-change (which uses
    // it to persist a generated section's semantic-token class bodies — the JIT
    // cannot synthesize gs-* class bodies, so without a store write the section
    // renders unstyled). `apiFetch` is injected by the caller (its own wp.apiFetch
    // wrapper); NS is the GBS route root.
    const GBS_NS = '/spectra-blocks/v1/global-styles';

    // Deep-merge one incoming schema-v1 payload onto an existing one, bucket by
    // bucket (null value = delete). Never full-replaces — importer chrome / other
    // sections' classes in untouched buckets survive.
    function mergePayload( existing, incoming ) {
        const out = Object.assign( {}, existing || {} );
        out.v = '1';
        Object.keys( incoming || {} ).forEach( function ( bucket ) {
            if ( bucket === 'v' ) {
                return;
            }
            const inc = incoming[ bucket ];
            if ( inc === null ) {
                delete out[ bucket ];
                return;
            }
            if ( Array.isArray( inc ) ) {
                out[ bucket ] = inc.slice();
                return;
            }
            if ( typeof inc !== 'object' ) {
                return;
            }
            const base = ( out[ bucket ] && typeof out[ bucket ] === 'object' && ! Array.isArray( out[ bucket ] ) )
                ? Object.assign( {}, out[ bucket ] )
                : {};
            Object.keys( inc ).forEach( function ( key ) {
                if ( inc[ key ] === null ) {
                    delete base[ key ];
                } else {
                    base[ key ] = inc[ key ];
                }
            } );
            out[ bucket ] = base;
        } );
        return out;
    }

    // The block-editor canvas runs in an iframe; styles must be injected THERE.
    function canvasDoc() {
        const ifr = document.querySelector( 'iframe[name="editor-canvas"]' );
        return ifr && ifr.contentDocument ? ifr.contentDocument : document;
    }
    function injectCss( elementId, css ) {
        const doc = canvasDoc();
        let el = doc.getElementById( elementId );
        if ( ! el ) {
            el = doc.createElement( 'style' );
            el.id = elementId;
            ( doc.head || doc.documentElement ).appendChild( el );
        }
        el.textContent = css || '';
    }

    // Tag a REST failure with which of the three GBS steps it came from so a
    // caller can label the outcome granularly. `step` is 'read' | 'write' |
    // 'render' — a render failure means the data WAS saved, only the live paint
    // failed (recoverable by reload), which is a different remediation than a
    // read/write failure where nothing persisted. The message is preserved.
    function taggedGbsError( step, e ) {
        const err = e instanceof Error ? e : new Error( String( e && e.message ? e.message : e ) );
        err.gbsStep = step;
        return err;
    }

    // Read-modify-write the per-page GBS store through the SSOT /save route (the
    // same endpoint the importer uses) then render + inject the merged CSS so it
    // paints live. Returns the merged payload; throws on any REST failure (tagged
    // with `gbsStep`) so the caller can decide whether to surface or swallow it
    // and, if surfacing, which step failed.
    async function persistPageGbs( apiFetch, incoming, postId ) {
        let existing;
        try {
            existing = await apiFetch( { path: GBS_NS + '/save?scope=page&post_id=' + postId } )
                .then( function ( g ) {
                    return ( g && g.payload && typeof g.payload === 'object' ) ? g.payload : {};
                } );
        } catch ( e ) {
            throw taggedGbsError( 'read', e );
        }
        const merged = mergePayload( existing, incoming );
        try {
            await apiFetch( {
                path: GBS_NS + '/save',
                method: 'POST',
                data: { scope: 'page', post_id: postId, payload: merged, replace: true },
            } );
        } catch ( e ) {
            throw taggedGbsError( 'write', e );
        }
        try {
            const r = await apiFetch( {
                path: GBS_NS + '/render',
                method: 'POST',
                data: { payload: merged, post_id: postId, scope: 'page' },
            } );
            injectCss( 'spectra-gen-custom-css-' + postId + '-inline-css', r && r.css );
        } catch ( e ) {
            throw taggedGbsError( 'render', e );
        }
        return merged;
    }

    // ── Deferred section-GBS persistence (persist on SAVE, never before) ─────────
    // A generate_section insert carries custom `gs-` class BODIES — semantic-token
    // CSS (var(--primary)/…) the JIT can't compile as utilities, so it can't ride
    // the block className the way per-block styling now does. We must NOT write it
    // to the DB immediately: that persists before the user Saves, breaking the
    // "nothing hits the DB until Save" contract (and orphaning CSS if they discard).
    // Instead: RENDER + inject it for a live PREVIEW now (a pure compile — NO /save,
    // no DB write), ACCUMULATE the payload, and FLUSH it to the GBS store only when
    // the editor completes a real Save. Never Saved → never persisted.

    // Preview-only: compile the payload via the SSOT renderer and inject it into the
    // canvas. NO /save — this never touches the DB. (The saved-meta render replaces
    // this element on the next reload.)
    async function previewSectionGbs( apiFetch, incoming, postId ) {
        const r = await apiFetch( {
            path: GBS_NS + '/render',
            method: 'POST',
            data: { payload: incoming, post_id: postId, scope: 'page' },
        } );
        injectCss( 'zipwp-gbs-pending-section-' + postId, r && r.css );
    }

    // Remove the pending-section preview <style> for a post — after Save flushes,
    // the persisted-meta render (spectra-gen-custom-css-<postId>) paints the
    // section, so the preview element is stale duplicate CSS.
    function removePendingSectionStyle( postId ) {
        const el = canvasDoc().getElementById( 'zipwp-gbs-pending-section-' + postId );
        if ( el && el.parentNode ) {
            el.parentNode.removeChild( el );
        }
    }

    // Build a deferred saver. Deps are injectable (apiFetch, editorStore, subscribe,
    // persist, preview, merge) so the accumulate → flush-on-save logic is testable
    // without a live wp.data. `queue(incoming, postId)` merges the payload into the
    // pending set (per post), paints the preview, and arms a ONE-TIME subscription
    // that flushes every pending payload through the real persist the first time a
    // NON-autosave Save completes successfully.
    function createSectionGbsSaver( deps ) {
        const apiFetch = deps.apiFetch;
        const editorStore = deps.editorStore; // () => core/editor select, or the select object
        const subscribe = deps.subscribe; // (cb) => unsubscribe
        const persist = deps.persist || persistPageGbs;
        const preview = deps.preview || previewSectionGbs;
        const removePreview = deps.removePreview || removePendingSectionStyle;
        const merge = deps.merge || mergePayload;
        const pending = {}; // postId -> merged payload
        let armed = false;
        let wasSaving = false;

        function resolveStore() {
            return typeof editorStore === 'function' ? editorStore() : editorStore;
        }
        function flush() {
            Object.keys( pending ).forEach( function ( postId ) {
                const payload = pending[ postId ];
                // Optimistically clear so a second Save with nothing new is a no-op…
                delete pending[ postId ];
                Promise.resolve( persist( apiFetch, payload, Number( postId ) ) ).then( function () {
                    // …persisted: the saved-meta render now owns the paint, so drop
                    // the stale preview <style> — but ONLY if nothing was re-queued
                    // for this post while the persist was in flight. A queue() during
                    // the flush re-creates the shared per-post preview element for a
                    // section that hasn't persisted yet; removing it here would leave
                    // that section unstyled until the next Save.
                    if ( ! pending[ postId ] ) {
                        removePreview( Number( postId ) );
                    }
                } ).catch( function ( e ) {
                    // A 'render'-tagged rejection means the /save DB write SUCCEEDED
                    // and only the live paint failed (recoverable by reload). The
                    // payload IS persisted, so treat it like success: drop the stale
                    // preview and do NOT re-queue (re-queuing would rewrite an
                    // already-saved body and, via the merge below, could clobber a
                    // newer queued edit).
                    if ( e && e.gbsStep === 'render' ) {
                        if ( ! pending[ postId ] ) {
                            removePreview( Number( postId ) );
                        }
                        return;
                    }
                    // A genuine not-persisted failure (read/write): re-queue so the
                    // NEXT Save retries — a transient REST error must not permanently
                    // lose the section's styling. `payload` is the OLDER failed batch,
                    // so merge it UNDER anything queued since (2nd arg wins per key)
                    // to keep a newer regenerate from being clobbered by the stale body.
                    pending[ postId ] = merge( payload, pending[ postId ] || {} );
                    // eslint-disable-next-line no-console -- developer signal; retried on the next Save
                    console.warn( '[ZIP AI] deferred section-styles flush failed on Save (will retry next Save)', e );
                } );
            } );
        }
        function onStoreChange() {
            const sel = resolveStore();
            if ( ! sel || typeof sel.isSavingPost !== 'function' ) {
                return;
            }
            // A real (non-autosave) save in flight.
            const saving = sel.isSavingPost() &&
                ! ( typeof sel.isAutosavingPost === 'function' && sel.isAutosavingPost() );
            // Transition saving → finished: flush IF the save succeeded (degrade-open
            // when the selector is absent — a completed save with no failure signal).
            if ( wasSaving && ! saving ) {
                const succeeded = typeof sel.didPostSaveRequestSucceed === 'function'
                    ? sel.didPostSaveRequestSucceed()
                    : true;
                if ( succeeded ) {
                    flush();
                }
            }
            wasSaving = saving;
        }
        function arm() {
            if ( armed ) {
                return;
            }
            armed = true;
            if ( typeof subscribe === 'function' ) {
                subscribe( onStoreChange );
            }
        }
        return {
            queue ( incoming, postId ) {
                if ( ! incoming || ! postId ) {
                    return Promise.resolve();
                }
                pending[ postId ] = merge( pending[ postId ] || {}, incoming );
                arm();
                // Preview the MERGED payload (not just this section) so a second
                // generate_section doesn't overwrite the single per-post preview
                // <style> with only its own CSS — earlier sections would go unstyled
                // until Save otherwise.
                return Promise.resolve( preview( apiFetch, pending[ postId ], postId ) ).catch( function () {} );
            },
            // Test seams — the pending set + the store-change handler.
            _pending: pending,
            _onStoreChange: onStoreChange,
        };
    }

    // Lazily-built browser singleton (ONE subscription per editor session), wired to
    // the live wp.data core/editor store. `queueSectionGbsForSave` is what the
    // apply-change section path calls.
    let _sectionGbsSaver = null;
    function sectionGbsSaver( apiFetch ) {
        if ( ! _sectionGbsSaver ) {
            _sectionGbsSaver = createSectionGbsSaver( {
                apiFetch,
                editorStore: editorSelect,
                subscribe ( cb ) {
                    return ( typeof window !== 'undefined' && window.wp && window.wp.data && window.wp.data.subscribe )
                        ? window.wp.data.subscribe( cb )
                        : function () {};
                },
            } );
        }
        return _sectionGbsSaver;
    }
    function queueSectionGbsForSave( apiFetch, incoming, postId ) {
        return sectionGbsSaver( apiFetch ).queue( incoming, postId );
    }

    if ( typeof window !== 'undefined' ) {
        window.zipwpEditorShared = window.zipwpEditorShared || {};
        window.zipwpEditorShared.mergePayload = mergePayload;
        window.zipwpEditorShared.canvasDoc = canvasDoc;
        window.zipwpEditorShared.injectCss = injectCss;
        window.zipwpEditorShared.persistPageGbs = persistPageGbs;
        window.zipwpEditorShared.previewSectionGbs = previewSectionGbs;
        window.zipwpEditorShared.createSectionGbsSaver = createSectionGbsSaver;
        window.zipwpEditorShared.queueSectionGbsForSave = queueSectionGbsForSave;
        window.zipwpEditorShared.currentPostId = currentPostId;
        window.zipwpEditorShared.editorSelect = editorSelect;
        window.zipwpEditorShared.editorDispatch = editorDispatch;
        window.zipwpEditorShared.currentMeta = currentMeta;
        window.zipwpEditorShared.blockEditorSelect = blockEditorSelect;
        window.zipwpEditorShared.blockEditorDispatch = blockEditorDispatch;
        window.zipwpEditorShared.rootClientId = rootClientId;
        window.zipwpEditorShared.bannedVisualAttrs = bannedVisualAttrs;
        window.zipwpEditorShared.isBannedVisualAttr = isBannedVisualAttr;
        window.zipwpEditorShared.isJsCapable = isJsCapable;
        window.zipwpEditorShared.referencedIds = referencedIds;
        window.zipwpEditorShared.classDependents = classDependents;
        window.zipwpEditorShared.anchorDependents = anchorDependents;
    }
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = {
            mergePayload,
            canvasDoc,
            injectCss,
            persistPageGbs,
            previewSectionGbs,
            createSectionGbsSaver,
            queueSectionGbsForSave,
            currentPostId,
            editorSelect,
            editorDispatch,
            currentMeta,
            blockEditorSelect,
            blockEditorDispatch,
            isJsCapable,
            referencedIds,
            classDependents,
            anchorDependents,
            rootClientId,
            bannedVisualAttrs,
            isBannedVisualAttr,
        };
    }
}() );
