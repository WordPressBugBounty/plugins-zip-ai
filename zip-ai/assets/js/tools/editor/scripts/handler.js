/**
 * editor/get-scripts + editor/set-scripts — typed read/patch over a BLOCK's
 * `spectraCustomJS` attribute (the per-block JS store). `spectraCustomJS` in
 * post_content is the single source of truth for page behaviour — Spectra Pro's
 * BlockJsCompiler renders it once at wp_footer (spectra-blocks-pro #165),
 * superseding the removed per-page `_spectra_blocks_page_scripts` meta.
 *
 * SESSION-SCOPED BY DESIGN (the apply_change philosophy): reads come from
 * `core/block-editor` getBlockAttributes (the live editing session, incl.
 * unsaved edits) and writes go through updateBlockAttributes — the block's JS
 * updates in the session immediately, is DISCARDED if the user discards the
 * session, and persists only on Save. A REST write here would race the open
 * editor's copy and mutate before save — never do that.
 *
 * Target a block by `clientId` (default = the page root container, the first
 * top-level block — page-wide behaviour). The stored code is raw JS; the
 * renderer wraps it in an IIFE and resolves the `_current_block_` token to the
 * block's scope class.
 */
( function () {
    // Block-editor store access comes from the ONE shared source
    // (editor/shared/editor-shared-utils.js): window in the browser, require()
    // under jest — so the editor handlers can't drift.
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
    function blockEditorSelect() {
 const u = sharedEditorUtils(); return u && u.blockEditorSelect ? u.blockEditorSelect() : null;
}
    function blockEditorDispatch() {
 const u = sharedEditorUtils(); return u && u.blockEditorDispatch ? u.blockEditorDispatch() : null;
}
    function rootClientId( sel ) {
 const u = sharedEditorUtils(); return u && u.rootClientId ? u.rootClientId( sel ) : null;
}
    function currentPostId() {
 const u = sharedEditorUtils(); return u && u.currentPostId ? u.currentPostId() : null;
}
    function isJsCapable( blockName ) {
 const u = sharedEditorUtils(); return !! ( u && u.isJsCapable && u.isJsCapable( blockName ) );
}

    // ── Anchor resolution for JS selectors ──────────────────────────────────
    // The AI can't see the rendered DOM. The sanctioned way to target a block in
    // JS is its HTML `anchor`, which renders as the element's frontend `id`
    // (BlockAttributes::get_wrapper_attributes → `$wrapper_attrs['id'] = anchor`).
    // collectAnchors gathers those; unresolvedSelectors reports the #ids a script
    // references that no anchor provides. Those are surfaced to the brain as a
    // FACT, not hard-blocked — the id may still resolve on the frontend (an
    // imported header/footer part the editor's page tree omits, or an id authored
    // inside block content: form fields, rich-text spans). Class/tag selectors are
    // never gated — they name runtime state, utilities, and block-internal DOM.
    function collectAnchors( sel ) {
        const anchors = Object.create( null );
        const ids = sel.getClientIdsWithDescendants ? sel.getClientIdsWithDescendants() : null;
        if ( ! ids ) {
return anchors;
}
        for ( let i = 0; i < ids.length; i++ ) {
            const a = sel.getBlockAttributes( ids[ i ] );
            if ( a && typeof a.anchor === 'string' && a.anchor !== '' ) {
anchors[ a.anchor ] = true;
}
        }
        return anchors;
    }

    // Every id the JS references (getElementById / #id) — from the shared SSOT, so
    // the write-time gate parses JS identically to apply-change's delete-time
    // orphan-JS surface (drift here would be a new bug class). Local fallback keeps
    // the handler working if the shared bundle hasn't loaded yet.
    function referencedIds( code ) {
        const u = sharedEditorUtils();
        if ( u && u.referencedIds ) {
return u.referencedIds( code );
}
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

    // The #ids the JS references that no block ANCHOR in THIS page's editor tree
    // provides. Surfaced to the brain as a FACT — NOT hard-blocked: the id may
    // still resolve on the frontend (an imported header/footer part the editor
    // tree omits, or an id authored inside block content — form fields, rich-text
    // spans). The brain has the site context to judge; blocking here false-rejects
    // those legit targets. Mirrors removalImpact (adapter surfaces facts, brain decides).
    function unresolvedSelectors( sel, code ) {
        const anchors = collectAnchors( sel );
        const ids = referencedIds( code );
        return ids.filter( function ( id, i ) {
            return ids.indexOf( id ) === i && ! anchors[ id ];
        } );
    }

    // The target block: an explicit clientId, else the page root container.
    function resolveClientId( sel, args ) {
        const cid = args && typeof args.clientId === 'string' && args.clientId !== '' ? args.clientId : null;
        return cid || rootClientId( sel );
    }

    // A block's current spectraCustomJS ('' when unset or the block is gone).
    // eslint-disable-next-line no-unused-vars
    function customJsOf( sel, clientId ) {
        const attrs = sel && sel.getBlockAttributes ? sel.getBlockAttributes( clientId ) : null;
        return attrs && typeof attrs.spectraCustomJS === 'string' ? attrs.spectraCustomJS : '';
    }

    function handleGetScripts( args ) {
        const sel = blockEditorSelect();
        if ( ! sel || ! sel.getBlockAttributes ) {
            return { success: false, error: 'editor_unavailable: core/block-editor store not present (is the block editor open?)' };
        }
        let clientId = resolveClientId( sel, args );
        if ( ! clientId ) {
            return { success: false, error: 'no_block: the page has no blocks to read JS from' };
        }
        // Mirror set-scripts' redirect: a non-JS-capable target's JS lives on the
        // page root (where set-scripts wrote it), so READ from there — else the
        // brain reads '' from the original block and re-issues, stacking duplicate JS.
        if ( sel.getBlockName && ! isJsCapable( sel.getBlockName( clientId ) ) ) {
            const root = rootClientId( sel );
            if ( root ) {
                clientId = root;
            }
        }
        // Block-gone is NOT the same as no-JS: if the clientId no longer resolves,
        // report it as a stale target (else the model reads code:'' as "no JS" and
        // may author a fresh script against a dead block).
        const attrs = sel.getBlockAttributes( clientId );
        if ( ! attrs ) {
            return { success: false, error: 'unknown_block: no block with clientId ' + clientId + ' in the live tree (it may have been deleted — re-read the outline / get-context first)' };
        }
        return {
            success: true,
            data: {
                post_id: currentPostId(),
                client_id: clientId,
                code: typeof attrs.spectraCustomJS === 'string' ? attrs.spectraCustomJS : '',
            },
        };
    }

    // Throws on code the block renderer would reject — typed and loud beats a
    // silent drop. `<script>` tags never belong in the store (it wraps the raw JS).
    function validateCode( code ) {
        if ( typeof code !== 'string' || code === '' ) {
            throw new Error( 'invalid_input: code is required (the raw JS source, no <script> tags)' );
        }
        if ( /<\/?script/i.test( code ) ) {
            throw new Error( 'invalid_input: code must be the raw JS source only, with no <script> tags (the store wraps it)' );
        }
    }

    // `code` REPLACES the block's spectraCustomJS; `append: true` adds to the
    // existing JS instead (read first with editor/get-scripts).
    function handleSetScripts( args ) {
        const sel = blockEditorSelect();
        const dis = blockEditorDispatch();
        if ( ! sel || ! sel.getBlockAttributes || ! dis || ! dis.updateBlockAttributes ) {
            return { success: false, error: 'editor_unavailable: core/block-editor store not present (is the block editor open?)' };
        }

        try {
 validateCode( args ? args.code : undefined );
} catch ( e ) {
            return { success: false, error: String( e && e.message ? e.message : e ) };
        }

        let clientId = resolveClientId( sel, args );
        if ( ! clientId ) {
            return { success: false, error: 'no_block: the page has no blocks to attach JS to' };
        }
        let attrs = sel.getBlockAttributes( clientId );
        if ( ! attrs ) {
            return { success: false, error: 'unknown_block: no block with clientId ' + clientId + ' in the live tree (read the outline / get-context first)' };
        }

        // The target must actually persist spectraCustomJS, or it paints this
        // session and silently vanishes on Save. Redirect to the root container —
        // the same fallback the converter uses for a script whose owner class
        // sits on a non-capable block — instead of writing something that's lost.
        let redirected = false;
        if ( sel.getBlockName && ! isJsCapable( sel.getBlockName( clientId ) ) ) {
            const root = rootClientId( sel );
            // The redirect target must ALSO be JS-capable — a page whose first
            // top-level block is core/group / core/cover would otherwise take the
            // write, report success, then drop the attribute on Save.
            if ( root && sel.getBlockName && ! isJsCapable( sel.getBlockName( root ) ) ) {
                return {
                    success: false,
                    error: 'block_not_js_capable: this block type cannot hold JavaScript, and the page root container cannot either — wrap the target in a Spectra container that holds JS, then retry.',
                };
            }
            const rootAttrs = root ? sel.getBlockAttributes( root ) : null;
            if ( ! rootAttrs ) {
                return {
                    success: false,
                    error: 'block_not_js_capable: this block type cannot hold JavaScript, and no root container was found to redirect to',
                };
            }
            redirected = true;
            clientId = root;
            attrs = rootAttrs;
        }

        // Block-safety guard — the SAME invariant apply-change enforces via
        // assertMutable (SCENARIO-003), applied here because this is the other path
        // that mutates a block's attributes. `spectraCustomJS` is a block attribute,
        // and Gutenberg's UPDATE_BLOCK_ATTRIBUTES reducer does NOT consult the lock
        // selectors (they gate the UI, not a programmatic dispatch) — so without this
        // check set_scripts could write JS into a template-locked block, or into a
        // synced-pattern (core/block) instance's content-locked inner block, which
        // edits content shared with every other page that uses that pattern. Checked
        // AFTER the js-capable redirect so it validates the block actually written to.
        // Degrades to ALLOW when the selector is absent (older Gutenberg), matching
        // assertMutable — never a false block.
        if ( typeof sel.canEditBlock === 'function' && sel.canEditBlock( clientId ) === false ) {
            return {
                success: false,
                error: 'locked_block: ' + clientId + ' is locked in the editor (template lock, content lock, or a synced-pattern instance), so its JavaScript cannot be changed here. Edit the pattern itself, or unlock the block.',
            };
        }

        // Unresolved #id selectors are a FACT for the brain, not a hard block: the
        // id may resolve on the frontend (imported header/footer part, block content)
        // even when no anchor in THIS page's editor tree matches. Store the JS and
        // surface them so the brain can re-target if they're genuinely dead.
        const unresolved = unresolvedSelectors( sel, args.code );

        const existing = typeof attrs.spectraCustomJS === 'string' ? attrs.spectraCustomJS : '';
        const next = ( args.append === true && existing !== '' ) ? existing + '\n' + args.code : args.code;
        dis.updateBlockAttributes( clientId, { spectraCustomJS: next } );

        return {
            success: true,
            data: {
                client_id: clientId,
                code: next,
                note: ( redirected
                    ? 'Session-scoped: the requested block can\'t hold JS (it would be dropped on Save), so it was attached to the page root container instead.'
                    : 'Session-scoped: the block\'s JS updates now; persists when the user saves the page.' ) +
                    ( unresolved.length
                        ? ' Heads up: #' + unresolved.join( ', #' ) + ' match no block anchor on this page — fine if they resolve in the imported header/footer or block content, otherwise set an anchor or re-target.'
                        : '' ),
                ...( unresolved.length ? { unresolved_selectors: unresolved } : {} ),
            },
        };
    }

    // Register once the bridge is ready (same retry pattern as get-context /
    // apply-change). The react-manager glob auto-enqueues this file.
    function initHandler() {
        if ( window.zipwpMcp && window.zipwpMcp.registerTool ) {
            window.zipwpMcp.registerTool(
                'editor/get-scripts',
                async function ( args ) {
 return handleGetScripts( args );
},
                { previewMode: 'client' }
            );
            window.zipwpMcp.registerTool(
                'editor/set-scripts',
                async function ( args ) {
 return handleSetScripts( args );
},
                { previewMode: 'client' }
            );
        } else {
            setTimeout( initHandler, 100 );
        }
    }

    initHandler();

    // Test-only surface (Node/CommonJS) — inert in the browser bundle.
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = {
            handleGetScripts,
            handleSetScripts,
        };
    }
}() );
