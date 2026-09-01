/**
 * ZipWP MCP — Vibe Editing v2: editor/get-context handler.
 *
 * Reads a slice of the LIVE Gutenberg block tree from wp.data and returns it as
 * structured JSON. Dispatched by the brain's AgentBrowserLoop as a `js_rpc`
 * tool (same eager-execute path as js_hook); the result is POSTed back to the
 * Laravel /agent/rpc-reply route so the loop resolves it IN THE SAME TURN.
 *
 * Input:
 *   scope (optional) — a block clientId. Present → return that block's subtree;
 *   absent → the page's top-level sections (a cheap outline the brain can then
 *   expand by re-calling with a section clientId).
 *
 * Returns { success, data: { scope, postId, blocks: [row] } } where each row is
 * { clientId, blockName, text, className, path, html?, computed? } — a
 * DESIGN-FAITHFUL view: `text` identifies the block (stripped), `html` is the
 * rich content WITH inline markup (the design pattern, when present), `computed`
 * is the rendered-style digest (fontSize/color/background/padding/fontWeight,
 * best-effort) so the brain edits relative to RENDERED reality, not class
 * strings. The clientId is the LIVE targeting handle (re-snapshotted every
 * call); the brain never persists it across turns.
 *
 * @package
 */
( function () {
    'use strict';

    // Content-read budget. A get_context row must carry the block's REAL copy so
    // the editor LLM rewrites what is actually on the page — the old 120/600 caps
    // meant a long paragraph came back as ~120 chars and the model invented the
    // rest (generic, off-page output). Sized to hold any single real block whole
    // (~1300 words); only paid on an explicit scoped read, and a section's
    // containers carry little own-text so the outline map stays lean.
    const CONTENT_READ_MAX = 8000;

    // Trim to the budget, but make any clip VISIBLE — a silent truncation is the
    // content-loss bug we are closing (the model confidently rewrites a long block
    // it only half-saw and drops the tail). With the marker it knows the copy is
    // incomplete and preserves/appends instead of replacing blind.
    function clip( s, max ) {
        return s.length > max ? s.slice( 0, max ) + ' …[+' + ( s.length - max ) + ' more chars not shown]' : s;
    }

    // Plain-text of a block's own copy — the brain identifies the block by it AND,
    // for a plain (no-markup) leaf, rewrites THIS string. Full copy up to the read
    // budget so a content edit sees the whole thing, not a stub.
    function textOf( block ) {
        const a = block.attributes || {};
        const raw = a.text || a.content || a.label || a.title || '';
        return clip( String( raw ).replace( /<[^>]*>/g, '' ).trim(), CONTENT_READ_MAX );
    }

    // RICH content WITH inline markup intact — the carrier of the page's design
    // PATTERN (per-word colour <span>s, emphasis). `text` above is stripped for
    // identification; this is what the brain must edit so a content rewrite can
    // re-author the SAME inline structure with new words instead of flattening
    // it to a plain string (the headline-flattening defect). Surfaced ONLY when
    // the content actually carries inline markup, so plain blocks stay lean.
    function htmlOf( block ) {
        const a = block.attributes || {};
        // eslint-disable-next-line eqeqeq
        const raw = a.content != null ? a.content : ( a.text != null ? a.text : null );
        if ( typeof raw !== 'string' || raw.indexOf( '<' ) === -1 ) {
return null;
}
        return clip( raw, CONTENT_READ_MAX );
    }

    // The live editor canvas document (iframe in the block editor; falls back to
    // the main document for the no-iframe mount). Shared shape with apply-change's
    // canvasCtx — kept minimal here (read-only lookup) to avoid a cross-handler
    // load-order dependency.
    function canvasDoc() {
        const f = document.querySelector( 'iframe[name="editor-canvas"]' );
        return f && f.contentDocument ? f.contentDocument : document;
    }

    // The canvas node for a block (the live rendered element). Resolved ONCE per
    // row by the caller and shared by computedOf + renderedContentOf so the
    // [data-block] lookup runs once, not per-field. null when not mounted
    // (off-screen/virtualized).
    function nodeForBlock( doc, clientId ) {
        return doc.querySelector( '[data-block="' + clientId + '"]' );
    }

    // RENDERED design facts for a block — the load-bearing axes the brain
    // otherwise cannot perceive (it sees authored class tokens, never the
    // rendered px/colour). Lets "bigger" be relative to the real size and lets
    // the brain see when an authored class did NOT move a property (a gs-*
    // !important rule owns it). Best-effort: null when the canvas node isn't
    // mounted (off-screen/virtualized) — the doctrine then falls back to
    // className. Read-only; never throws.
    function computedOf( el, win ) {
        try {
            if ( ! el ) {
return null;
}
            const cs = win.getComputedStyle( el );
            return {
                fontSize: cs.fontSize,
                color: cs.color,
                backgroundColor: cs.backgroundColor,
                padding: cs.padding,
                fontWeight: cs.fontWeight,
            };
        } catch ( e ) {
            return null;
        }
    }

    // Collapse whitespace + trim — for the RENDERED DOM text, which the browser
    // already decoded to plain text (no entities/tags). Deliberately NOT routed
    // through an innerHTML round-trip: that would mangle a literal "<" in the text.
    function collapseWs( s ) {
        return ( s === null || s === undefined ? '' : String( s ) ).replace( /\s+/g, ' ' ).trim();
    }

    // Decode HTML entities + strip tags + collapse whitespace — for the AUTHORED
    // attr, which may carry entities/markup. A detached element (cached, plain
    // `document` — decoding is document-agnostic) does the exact decode the browser
    // does; under jest (no DOM) it degrades to a tag strip. Pairs with collapseWs
    // so authored + rendered compare like-for-like — entity/markup/whitespace
    // differences never cause a false divergence.
    let _decodeEl = null;
    function normContent( s ) {
        let str = s === null || s === undefined ? '' : String( s );
        try {
            if ( _decodeEl === null ) {
_decodeEl = document.createElement( 'div' );
}
            _decodeEl.innerHTML = str;
            str = _decodeEl.textContent || '';
        } catch ( e ) {
            str = str.replace( /<[^>]*>/g, '' );
        }
        return str.replace( /\s+/g, ' ' ).trim();
    }

    // CONTENT OWNERSHIP (the content analog of get-styles' styleContext): the
    // RENDERED text of a LEAF content block vs its own authored content attr.
    // When they DIFFER, the block's own attr is a DEAD LEVER — the rendered value
    // is owned UPSTREAM (a parent composite computes it / pushes it via block
    // context: a countdown unit's label comes from the parent's `{unit}sLabel`).
    // Editing the child attr then silently no-ops on render. Surfacing the
    // rendered value (NOT a server-computed boolean — the brain compares `text`
    // vs `rendered` and judges) lets the brain redirect the edit to the owning
    // parent attr, WITHOUT any per-block knowledge — the render is the source of
    // truth, exactly like styleContext's `effective`.
    //
    // Scoped HARD to avoid false positives: LEAF blocks only (a container's
    // textContent is its whole subtree → would always "differ"); only TEXT attrs
    // (content/text/label) — NOT `number`, which leaves self-format/animate
    // (1000→"1,000", count-up mid-flight), so value-inequality there is not
    // ownership; both sides normalized; empty/partial renders (unmounted, or
    // mid-typewriter where rendered is a prefix of authored) are skipped. Takes the
    // pre-resolved canvas node. Returns the normalized rendered value, or null.
    function renderedContentOf( block, el ) {
        if ( ( block.innerBlocks || [] ).length > 0 ) {
return null;
} // leaf-only — never a container subtree
        const a = block.attributes || {};
        // eslint-disable-next-line eqeqeq
        const authoredRaw = a.content != null ? a.content : a.text != null ? a.text : a.label != null ? a.label : null;
        if ( authoredRaw === null || authoredRaw === '' ) {
return null;
}
        if ( ! el ) {
return null;
}
        try {
            const rendered = collapseWs( el.textContent || '' );
            if ( ! rendered ) {
return null;
} // unmounted / cleared (mid-stream)
            // indexOf===0 covers BOTH equality (child owns it) and rendered being a
            // strict prefix of authored (mid-typewriter) — either way, not shadowed.
            if ( normContent( authoredRaw ).indexOf( rendered ) === 0 ) {
return null;
}
            return rendered.slice( 0, 120 );
        } catch ( e ) {
            return null;
        }
    }

    // Current className (L2 styling). Surfaced so an apply_change.setAttributes
    // can PRESERVE the existing utility/gs classes instead of replacing them
    // wholesale — the brain appends to this string.
    function classOf( block ) {
        const a = block.attributes || {};
        return typeof a.className === 'string' ? a.className : '';
    }

    // The 8 GBS-banned visual styling props are NEVER advertised as authorable
    // attrs (styling lives in `className` ONLY, the Spectra GBS JIT grammar). The
    // list is the SHARED SSOT (editor-shared-utils.isBannedVisualAttr) so this +
    // apply-change can never drift. If the shared module isn't resolved we
    // advertise the raw registry keys (the brain's zod still rejects the 8 on
    // write — degrade consistently, never a duplicate list).
    function isBannedVisualAttr( key ) {
        const u = sharedEditorUtils();
        return !! ( u && typeof u.isBannedVisualAttr === 'function' && u.isBannedVisualAttr( key ) );
    }

    // The block's VALID structural attr keys — straight from the registry
    // (getBlockType(blockName).attributes, the browser-side SSOT for what
    // Gutenberg accepts) minus the 8 GBS-banned styling attrs. Surfaced so the
    // model authors valid `attrs` first-try (e.g. counter `endNumber`, button
    // `linkURL`, container `isRootBlock`) instead of guessing against a brain
    // allowlist — the registry, not a hand-maintained list, decides validity.
    // null when the type isn't registered (forked/unknown block) → row omits the
    // field rather than advertise an empty/false set.
    function attrKeysOf( name ) {
        const t = window.wp && window.wp.blocks && window.wp.blocks.getBlockType
            ? window.wp.blocks.getBlockType( name )
            : null;
        if ( ! t || ! t.attributes ) {
return null;
}
        const out = [];
        Object.keys( t.attributes ).forEach( function ( k ) {
            // `spectraId` is an internal identifier that renders as a conditional
            // `data-spectra-id` ATTRIBUTE, never a queryable class/id — advertising
            // it as authorable lured the model into `querySelector('.<spectraId>')`
            // dead selectors. The sanctioned identity channel is `anchor` (→ the
            // element's frontend `id`), surfaced at the row level instead.
            if ( k === 'spectraId' ) {
return;
}
            if ( ! isBannedVisualAttr( k ) ) {
out.push( k );
}
        } );
        return out;
    }

    // SET (non-default) attribute VALUES — the higher-specificity styling/layout
    // layer that OUTRANKS className (cascade: block attribute > GBS class > block
    // default). Surfaced so the model SEES a pinned `style.*` / `layout` / spacing
    // attr BEFORE it sets a class that would silently no-op against it, and can
    // clear/move it. Uses the PROPER Gutenberg notion of "comment attributes": walk
    // the registered schema (getBlockType), drop attrs SOURCE-d from markup
    // (content/html — those are the text, not a styling override) and attrs still at
    // their declared `default` — exactly the set Gutenberg's serializer persists.
    // FULLY registry-driven — no hardcoded key list: only the schema's own `source`
    // (markup-backed) + `default` + the banned visual props filter it. So whatever
    // is pinned (style.*, layout, spacing, …) surfaces; the model picks the ones
    // relevant to its edit. null when nothing is overridden.
    function setAttrsOf( block ) {
        const t = window.wp && window.wp.blocks && window.wp.blocks.getBlockType
            ? window.wp.blocks.getBlockType( block.name )
            : null;
        if ( ! t || ! t.attributes ) {
return null;
}
        const a = block.attributes || {};
        const schema = t.attributes;
        const out = {};
        Object.keys( schema ).forEach( function ( k ) {
            const def = schema[ k ] || {};
            if ( def.source ) {
return;
} // sourced from the markup — it's the content, not a styling override
            if ( isBannedVisualAttr( k ) ) {
return;
} // never authorable in attrs anyway
            const v = a[ k ];
            if ( v === undefined || v === null || v === '' ) {
return;
}
            if ( Array.isArray( v ) && v.length === 0 ) {
return;
}
            if ( typeof v === 'object' && ! Array.isArray( v ) && Object.keys( v ).length === 0 ) {
return;
}
            try {
                if ( JSON.stringify( v ) === JSON.stringify( def.default ) ) {
return;
} // unchanged from default
            } catch ( e ) { /* unstringifiable → treat as a real override */ }
            out[ k ] = v;
        } );
        return Object.keys( out ).length ? out : null;
    }

    function rowOf( block, path ) {
        const row = {
            clientId: block.clientId,
            blockName: block.name,
            text: textOf( block ),
            className: classOf( block ),
            path,
        };
        // The block's HTML `anchor` → its frontend `id` (deterministic:
        // BlockAttributes wrapper sets id = anchor). The sanctioned way to TARGET
        // this block from custom JS is `#<anchor>` — surfaced beside className so
        // the model reads/reuses a real id instead of guessing a frontend class.
        const anchor = block.attributes && typeof block.attributes.anchor === 'string' ? block.attributes.anchor : '';
        if ( anchor !== '' ) {
row.anchor = anchor;
}
        // Registry-declared valid attr keys (minus GBS-banned) so the model
        // authors valid setAttributes.attrs first-try. Omitted for unregistered
        // types — never advertise a guess.
        const attrKeys = attrKeysOf( block.name );
        if ( attrKeys !== null ) {
row.attrKeys = attrKeys;
}
        // Pinned (non-default) attr VALUES — the higher-specificity layer that
        // overrides className. Lets the model reconcile specificity before a class no-ops.
        const setAttrs = setAttrsOf( block );
        if ( setAttrs !== null ) {
row.setAttrs = setAttrs;
}
        // Design-faithful enrichment — only present when meaningful, so the
        // payload stays lean: `html` for blocks carrying inline markup, `computed`
        // when the live canvas node is readable. The canvas node is resolved ONCE
        // here and shared by computedOf + renderedContentOf (one [data-block]
        // lookup per row, not per field).
        const html = htmlOf( block );
        if ( html !== null ) {
row.html = html;
}
        const cdoc = canvasDoc();
        const cel = nodeForBlock( cdoc, block.clientId );
        const computed = computedOf( cel, cdoc.defaultView || window );
        if ( computed !== null ) {
row.computed = computed;
}
        // Content ownership: present ONLY when the rendered text differs from this
        // leaf's own content attr — i.e. the attr is a dead lever and the value is
        // owned upstream (a parent composite). The brain compares `text` vs
        // `rendered` and redirects the edit to the parent's owning attr.
        const rendered = renderedContentOf( block, cel );
        if ( rendered !== null ) {
row.rendered = rendered;
}
        return row;
    }

    // Read bounds. An unbounded subtree dump on a large/whole page can exceed the
    // brain's compaction backstop and clear the active read with NO recovery
    // (the brain holds only the js_rpc envelope, not this payload). Cap rows +
    // depth + total bytes so a single read is always self-contained; a truncated
    // read flags `truncated` and stamps per-parent `childCount`/`hasMore` so the
    // model drills down with a narrower scope instead of getting a silent cut.
    const MAX_CONTEXT_ROWS = 250;
    const MAX_CONTEXT_DEPTH = 8;
    const MAX_CONTEXT_BYTES = 48000;

    // Push a row into the bounded accumulator. Returns false (caller stops) when
    // a cap is hit. Tracks an approximate serialized size so a few heavy rows
    // (long html / many attrKeys) trip the ceiling as readily as many light ones.
    // (The offset skip lives in flatten, BEFORE rowOf — see there.)
    function pushRow( state, row ) {
        if ( state.rows.length >= MAX_CONTEXT_ROWS ) {
 state.truncated = true; return false;
}
        let sz = 200;
        try {
 sz = JSON.stringify( row ).length;
} catch ( e ) {
 sz = 200;
}
        if ( state.bytes + sz > MAX_CONTEXT_BYTES && state.rows.length > 0 ) {
            state.truncated = true;
            return false;
        }
        state.rows.push( row );
        state.bytes += sz;
        return true;
    }

    // Row count of the walk flatten() would emit with NO row/byte caps (the
    // depth cap still applies — at it a parent is one row and its subtree is
    // not walked, exactly like flatten). Cheap (no serialization); powers the
    // reply's `totalRows` so a truncated read can SAY how much exists and the
    // model can page with `offset` instead of re-reading the same prefix.
    function countRows( block, depth ) {
        const inner = block.innerBlocks || [];
        if ( inner.length > 0 && depth >= MAX_CONTEXT_DEPTH ) {
            return 1;
        }
        let n = 1;
        for ( let i = 0; i < inner.length; i++ ) {
            n += countRows( inner[ i ], depth + 1 );
        }
        return n;
    }

    // Flatten a block + descendants into bounded rows (DFS): clientId (live
    // handle), block name, text snippet, className, dot-path. Stops at the
    // row/byte caps; at the depth cap it emits the node with childCount/hasMore
    // rather than descending, so deeper blocks stay discoverable via a scoped
    // re-read.
    function flatten( block, path, depth, state ) {
        const inner = block.innerBlocks || [];
        // `toSkip` implements the read's `offset`: the first N rows of the SAME
        // DFS order are consumed without being emitted or counted against the
        // caps, so `offset = <previous reply's offset + rows.length>` continues
        // a truncated read exactly where it stopped (measured 2026-08-22: an
        // email in the last of 114 children sat ~100 rows past the cap; the
        // model re-read the same prefix 20 times and burned the turn). The skip
        // is decided HERE, before rowOf: a skipped row must not pay rowOf's
        // DOM + computed-style + registry cost, or paging a big tree does
        // O(pages²) row-builds. Skip arithmetic mirrors the emit paths exactly —
        // a depth-capped node consumes ONE skip and its subtree is not walked.
        const skipping = state.toSkip > 0;
        if ( skipping ) {
            state.toSkip--;
        }
        if ( inner.length > 0 && depth >= MAX_CONTEXT_DEPTH ) {
            if ( skipping ) {
                return;
            }
            const capped = rowOf( block, path );
            capped.childCount = inner.length;
            capped.hasMore = true;
            pushRow( state, capped );
            return;
        }
        let row = null;
        if ( ! skipping ) {
            row = rowOf( block, path );
            if ( ! pushRow( state, row ) ) {
                return;
            }
        }
        for ( let i = 0; i < inner.length; i++ ) {
            if ( state.truncated ) {
                // The caps tripped before this parent's children were exhausted —
                // flag it so the omitted subtree is discoverable.
                if ( row ) {
                    row.childCount = inner.length;
                    row.hasMore = true;
                }
                return;
            }
            flatten( inner[ i ], path === '' ? String( i ) : path + '.' + i, depth + 1, state );
        }
    }

    // Shared editor utilities — resolved at call time from the ONE source
    // (editor/shared/editor-shared-utils.js): window in the browser, require()
    // under jest. currentPostId lives there so this handler and apply-change can
    // never drift; a missing module degrades consistently (null).
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

    function handleGetContext( args ) {
        if ( ! window.wp || ! window.wp.data ) {
            return { success: false, error: 'block_editor_unavailable' };
        }
        const sel = window.wp.data.select( 'core/block-editor' );
        if ( ! sel ) {
            return { success: false, error: 'block_editor_unavailable' };
        }

        // A scope that isn't a real clientId — empty, or a sentinel the model
        // sometimes emits when it means "the whole page" (the literal string
        // "null", "root", "page", …) — degrades to the page outline rather than
        // a hard miss. The outline is the cheap top-level map the model can
        // re-target from; a hard error here just burns a round-trip.
        const scopeRaw = args && args.scope ? String( args.scope ) : '';
        const SCOPE_SENTINELS = { '': 1, null: 1, undefined: 1, root: 1, page: 1, 0: 1, none: 1, false: 1 };
        let scope = SCOPE_SENTINELS[ scopeRaw.toLowerCase() ] ? '' : scopeRaw;
        // `offset` pages a capped read: skip the first N rows of the same DFS
        // order (see pushRow). Non-numeric / negative degrades to 0.
        const offsetRaw = args && args.offset !== undefined ? Number( args.offset ) : 0;
        const offset = Number.isFinite( offsetRaw ) && offsetRaw > 0 ? Math.floor( offsetRaw ) : 0;
        // Bounded accumulator (rows + running byte size + truncation flag).
        const state = { rows: [], bytes: 0, truncated: false, toSkip: offset };

        // A stale/rotated clientId — gone after a structural edit (replace/insert
        // mint new ids), a page change, or replayed from older conversation history
        // — must NOT dead-end the turn. A hard `scope_not_found` error makes the
        // model re-ask the SAME dead id (wasted round-trips, duplicate-call blocks,
        // token bloat). Instead DEGRADE to the page outline and FLAG the miss, so the
        // model re-grounds against the live tree and re-targets in the SAME turn. The
        // grounding tool is the model's only map; it must always return a usable one.
        let scopeMissed = '';
        let totalRows = 0;
        if ( scope ) {
            const root = sel.getBlock( scope );
            if ( root ) {
                totalRows = countRows( root, 0 );
                flatten( root, '', 0, state );
            } else {
                scopeMissed = scope;
                scope = '';
                // The requested subtree is gone — the fallback outline is a
                // re-grounding map and must be COMPLETE, so a stale read's
                // offset never clips it.
                state.toSkip = 0;
            }
        }
        if ( ! scope ) {
            const top = sel.getBlocks() || [];
            totalRows = top.length;
            for ( let i = 0; i < top.length; i++ ) {
                // Outline read = top-level sections only (no recurse). Still
                // row/byte-bounded so a page with hundreds of sections can't
                // blow the read.
                //
                // Same `toSkip` contract as flatten, and for the same reason:
                // this loop used to ignore it, so `offset` was silently a no-op
                // on outline reads while `data.offset` and the truncation note
                // still told the model to page. The re-call returned the SAME
                // prefix, relabelled "showing rows N+1..", and the model re-read
                // it until the turn burned — the exact failure the offset work
                // exists to end. Skip is consumed BEFORE rowOf so a skipped row
                // never pays its DOM + computed-style cost.
                if ( state.toSkip > 0 ) {
                    state.toSkip--;
                    continue;
                }
                if ( ! pushRow( state, rowOf( top[ i ], String( i ) ) ) ) {
break;
}
            }
        }
        const blocks = state.rows;
        const effectiveOffset = scopeMissed ? 0 : offset;

        const data = {
            scope: scope || null,
            postId: currentPostId(),
            blocks,
            // How many rows this walk holds in full (row/byte caps aside), so a
            // capped read can SAY what fraction it showed and the model pages
            // with `offset` instead of re-reading the same prefix.
            totalRows,
        };
        if ( effectiveOffset > 0 ) {
            data.offset = effectiveOffset;
        }
        // The read hit a cap (rows/bytes) — tell the model these rows are a
        // PREFIX, so it narrows the scope (re-read a child clientId) rather than
        // assuming it saw the whole tree. Pairs with per-parent childCount/hasMore.
        if ( state.truncated ) {
            data.truncated = true;
            data.note =
                'Showing rows ' + ( effectiveOffset + 1 ) + '-' + ( effectiveOffset + blocks.length ) +
                ' of ' + totalRows + '. Re-call with the same scope and offset: ' +
                ( effectiveOffset + blocks.length ) + ' for the next rows, or narrow scope to a child ' +
                'container (rows with childCount/hasMore have unread children).';
        }
        // Flag a degraded read so the model KNOWS the id it asked for is gone and
        // these rows are the live outline to re-target from (not the requested
        // subtree) — turns a silent substitution into an explicit re-ground signal.
        if ( scopeMissed ) {
            data.scopeNotFound = scopeMissed;
        }
        return { success: true, data };
    }

    // Test seam (jest `unit-handler` project): guarded CommonJS re-export of the
    // pure entry, same pattern as the other editor handlers. The browser IIFE
    // path is unaffected.
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = { handleGetContext };
    }

    // Register once the bridge is ready (same retry pattern as the other
    // editor tools — the bridge mounts asynchronously).
    function initHandler() {
        if ( window.zipwpMcp && window.zipwpMcp.registerTool ) {
            window.zipwpMcp.registerTool(
                'editor/get-context',
                async ( args ) => handleGetContext( args ),
                { previewMode: 'client' }
            );
        } else {
            setTimeout( initHandler, 100 );
        }
    }

    initHandler();
}() );
