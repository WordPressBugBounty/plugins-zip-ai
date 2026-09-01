/**
 * WordPress Bridge Host
 *
 * Same-window host bridge for the mounted React assistant.
 * Exposes window.zipwpMcpBridge for the React DirectBridge to call.
 */

( function() {
    'use strict';

    // Block attribute keys that hold human-readable text (used by buildPageOutline
    // to separate copy from config/style attrs).
    const TEXT_KEYS = [ 'content', 'text', 'title', 'label', 'heading', 'question' ];

    // B1 capability handshake — this bundle's browser-RPC protocol version,
    // stamped into every editor context snapshot (getEditorContext).
    //
    //   1 = executes `js_rpc` envelopes for the editor/* handlers and POSTs replies
    //       to /agent/rpc-reply with {call_id, session_id, ok, data?, error?}.
    //   2 = apply-change parses a resolved `section_markup` on the REVAMP path
    //       (replaceInnerBlocks), not just on an insert — so a form-bearing section
    //       placed as a revamp materializes a real form instead of an unconfigured
    //       placeholder.
    //
    // The brain refuses to route a turn to its AgentBrowserLoop unless it sees
    // rpc >= 1, so ROUTING is unchanged by a bump. Bump when the dispatch/reply
    // contract changes incompatibly OR when the brain must be able to tell whether
    // this bundle can do something (the version is the ONE capability signal —
    // do not add a parallel flag). The brain reads this as
    // REVAMP_MATERIALIZATION_RPC_VERSION and degrades CLOSED below it: it keeps
    // warning the model that a revamped form would ship broken, because on an
    // un-updated site it still would.
    const RPC_PROTOCOL_VERSION = 2;

    // B-1 idempotency — a js_rpc apply mutates the live block tree BEFORE it
    // POSTs its reply, so a SECOND dispatch of the SAME tool_use call_id (an SSE
    // replay, a brain turn-replay after a mid-turn restart, OR hydrateSession's
    // replay_events after a page reload) would apply the mutation twice and
    // silently duplicate content. We record every js_rpc call_id we have already
    // executed, with the reply we produced; an exact replay skips the handler
    // entirely and just re-POSTs the cached reply (the brain may still be
    // BRPOP-waiting on it). Bounded FIFO so it can't grow unbounded.
    //
    // M3 — backed by sessionStorage (per-tab, survives a reload) because the
    // duplicate-apply hazard's worst case is exactly a mid-turn reload: the user
    // saves, reloads, hydrateSession replays the turn's tool_call_result events
    // through the SAME handlers, and a memory-only map is gone. Degrades to the
    // in-memory map when sessionStorage is unavailable (quota / privacy mode) —
    // never throws into the dispatch path.
    const RPC_DEDUP_MAX = 64;
    const RPC_DEDUP_STORAGE_KEY = 'zipwpRpcSeen.v1';
    const _rpcSeen = loadRpcSeen(); // call_id -> { ok, data, error, mutating?, pageLoadId? }
    // Unique per PAGE LOAD (a reload mints a fresh module + a fresh id, while the
    // sessionStorage-backed _rpcSeen survives). A cached session-scoped write whose
    // pageLoadId differs from this = the page reloaded since the apply → the
    // in-memory edit was discarded (see rpc-dedup.js RELOAD_REVERIFY_MARKER).
    const PAGE_LOAD_ID = 'pl-' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2, 8 );
    // Session-scoped writes (discarded on reload) — apply_change + set_scripts both
    // mutate block attributes in the open editor session; set_styles writes REST
    // (survives reload) so it is NOT here.
    const SESSION_SCOPED_TOOLS = { 'editor/apply-change': true, 'editor/set-scripts': true };
    function loadRpcSeen() {
        try {
            const raw = window.sessionStorage && window.sessionStorage.getItem( RPC_DEDUP_STORAGE_KEY );
            if ( raw ) {
                const entries = JSON.parse( raw );
                if ( Array.isArray( entries ) ) {
return new Map( entries );
}
            }
        } catch ( e ) { /* corrupt/unavailable storage → start fresh */ }
        return new Map();
    }
    function saveRpcSeen() {
        try {
            if ( ! window.sessionStorage ) {
return;
}
            window.sessionStorage.setItem(
                RPC_DEDUP_STORAGE_KEY,
                JSON.stringify( Array.from( _rpcSeen.entries() ) )
            );
        } catch ( e ) { /* quota/unavailable → memory-only behaviour, same as before */ }
    }
    function rpcSeenGet( callId ) {
        return callId ? _rpcSeen.get( callId ) : undefined;
    }
    function rpcSeenRemember( callId, ok, data, error, meta ) {
        if ( ! callId ) {
return;
}
        if ( _rpcSeen.has( callId ) ) {
_rpcSeen.delete( callId );
}
        const entry = { ok, data, error };
        // Tag session-scoped completed writes with the page-load id so a later
        // replay after a reload reposts an uncertain reply instead of a vanished
        // success (F2). Reads / non-session writes stay untagged (repost cached).
        if ( meta && meta.mutating === true ) {
            entry.mutating = true;
            entry.pageLoadId = meta.pageLoadId;
        }
        _rpcSeen.set( callId, entry );
        while ( _rpcSeen.size > RPC_DEDUP_MAX ) {
            _rpcSeen.delete( _rpcSeen.keys().next().value );
        }
        saveRpcSeen();
    }

    // Map WP editor getSettings() colors/fontSizes (`[{name, slug, color|size}]`)
    // into the brain's wire shape `{ colors: [{slug, hex}], font_sizes: [{slug, size}] }`.
    // Drops malformed entries (blank slug/value). `size` is coerced to a string
    // (theme.json may give numbers). Returns null when no valid tokens exist, so
    // the caller omits `theme_tokens` entirely rather than send an empty object.
    function buildThemeTokens( settings ) {
        const tokens = {};

        // WP exposes the palette in TWO shapes and a BLOCK THEME (theme.json) uses
        // ONLY the second: the legacy flat `settings.colors` (classic themes), and
        // `settings.__experimentalFeatures.color.palette.{theme,custom,default}`
        // (block themes). Reading only the flat array meant a theme.json site sent
        // NO palette, so the brain styled new sections with the generic default
        // accent (cyan) instead of the site's real brand. Prefer the flat array;
        // fall back to the experimental palette (theme + custom — the site's own
        // colours, not WP's built-in defaults).
        const exp = settings.__experimentalFeatures || {};
        const expPalette = ( exp.color && exp.color.palette ) || {};
        const srcColors = ( Array.isArray( settings.colors ) && settings.colors.length )
            ? settings.colors
            : [].concat(
                Array.isArray( expPalette.theme ) ? expPalette.theme : [],
                Array.isArray( expPalette.custom ) ? expPalette.custom : [],
            );
        const colors = [];
        // Null-proto so a palette slug that collides with an Object.prototype key
        // (constructor, __proto__, hasOwnProperty) can't resolve to an inherited
        // truthy value and silently drop that colour from theme_tokens.
        const seenColor = Object.create( null );
        for ( let i = 0; i < srcColors.length; i++ ) {
            const c = srcColors[ i ] || {};
            const cslug = typeof c.slug === 'string' ? c.slug : '';
            const hex = typeof c.color === 'string' ? c.color : '';
            if ( cslug && hex && ! seenColor[ cslug ] ) {
                seenColor[ cslug ] = true;
                colors.push( { slug: cslug, hex } );
            }
        }
        if ( colors.length ) {
tokens.colors = colors;
}

        const expTypo = exp.typography || {};
        const srcSizes = ( Array.isArray( settings.fontSizes ) && settings.fontSizes.length )
            ? settings.fontSizes
            : ( Array.isArray( expTypo.fontSizes ) ? expTypo.fontSizes : [] );
        const fontSizes = [];
        // Null-proto: same prototype-key guard as seenColor above.
        const seenSize = Object.create( null );
        for ( let j = 0; j < srcSizes.length; j++ ) {
            const f = srcSizes[ j ] || {};
            const fslug = typeof f.slug === 'string' ? f.slug : '';
            const size = f.size;
            const hasSize = typeof size === 'string' ? size !== '' : typeof size === 'number';
            if ( fslug && hasSize && ! seenSize[ fslug ] ) {
                seenSize[ fslug ] = true;
                fontSizes.push( { slug: fslug, size: String( size ) } );
            }
        }
        if ( fontSizes.length ) {
tokens.font_sizes = fontSizes;
}

        return ( tokens.colors || tokens.font_sizes ) ? tokens : null;
    }

    // The dominant repeated DIRECT-child block type — the block name occurring
    // 3+ times among `children`, with the highest count — or null when none
    // qualifies (fewer than 3 children, or no single type reaches 3). One
    // detection shared by the page outline (repeated_children flag) and the
    // selected-block repeater signal (flag + count + clone target), so the two
    // can never drift. Iframe path mirrors this in src/utils/selectedBlockDto.js.
    function dominantRepeatedChildType( children ) {
        if ( ! Array.isArray( children ) || children.length < 3 ) {
return null;
}
        const counts = {};
        for ( let i = 0; i < children.length; i++ ) {
            const name = children[ i ] && children[ i ].name;
            if ( typeof name === 'string' ) {
counts[ name ] = ( counts[ name ] || 0 ) + 1;
}
        }
        let best = null;
        const names = Object.keys( counts );
        for ( let k = 0; k < names.length; k++ ) {
            const n = counts[ names[ k ] ];
            if ( n >= 3 && ( best === null || n > best.count ) ) {
best = { name: names[ k ], count: n };
}
        }
        return best;
    }

    // A grid nested ONE level down: when the DIRECT children aren't a repeater
    // but EXACTLY ONE direct child is a container whose children ARE a repeater,
    // that inner container is the grid (the Spectra `section > content >
    // [card×N]` layout). "Exactly one" keeps it unambiguous. Iframe path mirrors
    // this in src/utils/selectedBlockDto.js::nestedGrid — keep both in lockstep.
    function nestedGrid( children ) {
        let found = null;
        for ( let i = 0; i < children.length; i++ ) {
            const c = children[ i ];
            const inner = c && Array.isArray( c.innerBlocks ) ? c.innerBlocks : null;
            if ( ! inner ) {
continue;
}
            const d = dominantRepeatedChildType( inner );
            if ( d === null ) {
continue;
}
            if ( found !== null ) {
return null;
} // 2+ candidate grids — ambiguous, bail
            found = { dominant: d, children: inner };
        }
        return found;
    }

    class WPBridgeHost {
        constructor() {
            this.config = window.zipwpIframeConfig || {};
            this._snapshotCounter = 0;
            this._selectionRevision = 0;
            this._lastSelectedId = null;
            this.init();
        }

        init() {
            if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', () => this.setup() );
            } else {
                this.setup();
            }
        }

        setup() {
            this.setupAdminBarToggle();
            this.setupResizeHandle();
            this.loadSavedSidebarWidth();
            this.setupInlineEditShortcut();
            this.restorePanelLayout();
            if ( window.zipwpPopoverDrag ) {
                const bridge = this;
                window.zipwpPopoverDrag.setup( function() {
 return bridge._panelLayout || 'sidebar';
} );
            }
            this.checkAutoOpen();
            this.restorePanelState();
        }

        // ── Panel Toggle ──────────────────────────────────────────────

        setupAdminBarToggle() {
            const self = this;
            document.addEventListener( 'click', function( e ) {
                const trigger = e.target.closest( '#zip-ai-floating-trigger' );
                if ( trigger ) {
                    e.preventDefault();
                    self.togglePanel();
                }
            } );

            // Escape closes the panel when focus is inside it.
            document.addEventListener( 'keydown', function( e ) {
                if ( e.key !== 'Escape' ) {
return;
}
                const container = document.getElementById( 'zip-ai-assistant-container' );
                if ( ! container || ! container.classList.contains( 'zip-ai-iframe-visible' ) ) {
return;
}
                // Escape belongs to the TOPMOST layer. The React app mounts
                // directly into this container (same document, despite the
                // `iframe` class name), and this listener is on `document`
                // while the app's own Esc handlers are on `window` — document
                // fires FIRST on the bubble path, so without this guard the
                // panel closed out from under an open overlay: Escape in the
                // fullscreen pick-a-look preview dismissed the preview AND the
                // whole chat, instead of dropping back to the conversation.
                //
                // Keyed on the ARIA contract, not on a component or class name,
                // so every dialog in the app — today's preview stage, session
                // list, attachment preview, and anything added later — owns its
                // own Escape for free.
                if ( container.querySelector( '[role="dialog"][aria-modal="true"]' ) ) {
return;
}
                // Only close if focus is inside the panel (or on body — e.g. after clicking inside)
                // eslint-disable-next-line @wordpress/no-global-active-element
                if ( container.contains( document.activeElement ) || document.activeElement === document.body ) {
                    e.preventDefault();
                    self.togglePanel();
                }
            } );

            // Global keyboard shortcut:
            // - Primary: Cmd+E / Ctrl+E (more reliable across OS/browser combos)
            // - Legacy: Cmd/Ctrl+Shift+Space (kept for backward compatibility)
            // Registered on the parent page so it works even when the panel is closed.
            document.addEventListener( 'keydown', function( e ) {
                const modKey = e.metaKey || e.ctrlKey;

                // Avoid intercepting typing inside form fields or contenteditable regions.
                const target = e.target;
                const tagName = target && target.tagName;
                const isTextInput =
                    tagName === 'INPUT' ||
                    tagName === 'TEXTAREA' ||
                    ( target && target.isContentEditable );

                if ( isTextInput || ! modKey ) {
                    return;
                }

                // Primary shortcut: Cmd/Ctrl + E
                if ( String( e.key ).toLowerCase() === 'e' ) {
                    e.preventDefault();
                    self.togglePanel();
                    return;
                }

                // Legacy shortcut: Cmd/Ctrl + Shift + Space
                if ( ! e.shiftKey ) {
                    return;
                }

                const isSpaceKey =
                    e.code === 'Space' ||
                    e.key === ' ' ||
                    e.key === 'Spacebar';

                if ( ! isSpaceKey ) {
                    return;
                }

                e.preventDefault();
                self.togglePanel();
            } );
        }

        togglePanel() {
            const container = document.getElementById( 'zip-ai-assistant-container' );
            if ( ! container ) {
                // Full-page mode has no sidebar container. "Close" navigates back to admin home.
                if ( this.config.displayMode === 'fullpage' ) {
                    window.location.href = this.config.adminHomeUrl || '/wp-admin/';
                }
                return;
            }

            const isVisible = container.classList.contains( 'zip-ai-iframe-visible' );

            if ( isVisible ) {
                container.classList.remove( 'zip-ai-iframe-visible' );
                document.body.classList.remove( 'zip-ai-assistant-open' );
                this.toggleFullscreen( false );
                // Make hidden container fully non-interactive (prevents click interception).
                container.setAttribute( 'inert', '' );

                // Disable block context picker when sidebar closes
                if ( window.zipwpMcpContextPicker ) {
                    window.zipwpMcpContextPicker.disable();
                }

                try {
 localStorage.setItem( 'zipwp-panel-open', '0' );
} catch ( e ) {}
            } else {
                container.removeAttribute( 'inert' );
                container.classList.add( 'zip-ai-iframe-visible' );
                document.body.classList.add( 'zip-ai-assistant-open' );
                this.emitFullscreenChanged( container.classList.contains( 'zip-ai-iframe-fullscreen' ) );

                // Enable block context picker when sidebar opens
                if ( window.zipwpMcpContextPicker ) {
                    window.zipwpMcpContextPicker.enable();
                }

                try {
 localStorage.setItem( 'zipwp-panel-open', '1' );
} catch ( e ) {}
            }
        }

        checkAutoOpen() {
            const urlParams = new URLSearchParams( window.location.search );

            // Legacy param: ?zipwp_open_assistant
            if ( urlParams.has( 'zipwp_open_assistant' ) ) {
                this._autoOpened = true;
                urlParams.delete( 'zipwp_open_assistant' );
                const newUrl = window.location.pathname +
                    ( urlParams.toString() ? '?' + urlParams.toString() : '' ) +
                    window.location.hash;
                window.history.replaceState( {}, '', newUrl );

                // eslint-disable-next-line no-var
                var self = this;
                setTimeout( function() {
 self.togglePanel();
}, 300 );
                return;
            }

            // New param: ?auto_open=true&prompt=...
            if ( urlParams.get( 'auto_open' ) === 'true' ) {
                const prompt = urlParams.get( 'prompt' ) || '';

                // Clean URL params
                urlParams.delete( 'auto_open' );
                urlParams.delete( 'prompt' );
                urlParams.delete( 'mode' );
                const cleanUrl = window.location.pathname +
                    ( urlParams.toString() ? '?' + urlParams.toString() : '' ) +
                    window.location.hash;
                window.history.replaceState( {}, '', cleanUrl );

                // Stash prompt for React to pick up after mount. sessionStorage
                // (not a window global) so it survives the logged-out
                // login -> reloadWithAutoOpen() full-page reload before an
                // authed InputBox can consume it.
                if ( prompt ) {
                    try {
                        sessionStorage.setItem( 'zipaiAutoPrompt', prompt );
                    } catch ( e ) {}
                    // React's InputBox may have already mounted and read the
                    // stash before this ran (warm reload: the cached bundle
                    // boots faster than DOMContentLoaded -> checkAutoOpen), so
                    // a plain setItem would strand the prompt until a remount.
                    // Signal useAutoPrompt to consume the just-written stash.
                    try {
                        window.dispatchEvent( new CustomEvent( 'zipai:auto-prompt' ) );
                    } catch ( e ) {}
                }

                this._autoOpened = true;

                // Open sidebar panel if not already on fullpage (fullpage is always open)
                if ( this.config.displayMode !== 'fullpage' ) {
                    // eslint-disable-next-line no-redeclare, no-var
                    var self = this;
                    setTimeout( function() {
 self.togglePanel();
}, 300 );
                }
            }
        }

        restorePanelState() {
            // Skip in full-page mode — panel is always visible there.
            if ( this.config.displayMode === 'fullpage' ) {
return;
}
            // Skip if checkAutoOpen already scheduled a deferred toggle — avoid double-toggle race.
            if ( this._autoOpened ) {
return;
}

            const container = document.getElementById( 'zip-ai-assistant-container' );
            if ( ! container ) {
return;
}

            const alreadyOpen = container.classList.contains( 'zip-ai-iframe-visible' );
            let wasOpen = false;
            try {
 wasOpen = localStorage.getItem( 'zipwp-panel-open' ) === '1';
} catch ( e ) {}

            if ( wasOpen && ! alreadyOpen ) {
                this.togglePanel();
            }
        }

        // ── Panel Layout (sidebar vs popover) ─────────────────────────
        // 'sidebar' = pushes WP content left (default)
        // 'popover' = floating card, no viewport shrink

        restorePanelLayout() {
            // Resolution (explicit choice wins; else context default) lives in the
            // ZIPWP_LAYOUT SSOT.
            const L = window.ZIPWP_LAYOUT;
            this._panelLayout = L ? L.resolveLayout() : 'sidebar';
            this._applyPanelLayoutClasses( this._panelLayout );
        }

        setPanelLayout( layout ) {
            const L = window.ZIPWP_LAYOUT;
            // Validate via the SSOT when present; fall back to the known layouts so
            // a missing config never lets an arbitrary string get persisted.
            const valid = L ? L.isValidLayout( layout ) : ( layout === 'sidebar' || layout === 'popover' );
            if ( ! valid ) {
return;
}
            this._panelLayout = layout;
            try {
 localStorage.setItem( L ? L.keys.layout : 'zipwp-panel-layout', layout );
} catch ( e ) {}
            // _applyPanelLayoutClasses already calls drag.clearPosition()
            // on the non-popover branch — no additional cleanup needed.
            this._applyPanelLayoutClasses( layout );
        }

        _applyPanelLayoutClasses( layout ) {
            const drag = window.zipwpPopoverDrag;
            document.body.classList.remove( 'zip-ai-overlay-mode', 'zip-ai-popover-mode' );
            if ( layout === 'popover' ) {
                document.body.classList.add( 'zip-ai-popover-mode' );
                if ( drag ) {
drag.restorePosition();
}
            } else if ( drag ) {
drag.clearPosition();
}
        }

        // Reset EVERYTHING (layout choice, popover size/position, FAB position,
        // theme) to defaults via the SSOT — which clears storage + broadcasts
        // the reset event that the FAB launcher and header theme listen for —
        // then re-resolve + re-apply the context-aware default here.
        resetPopoverLayout() {
            const drag = window.zipwpPopoverDrag;
            if ( drag && drag.resetLayout ) {
drag.resetLayout();
}
            if ( window.ZIPWP_LAYOUT ) {
                window.ZIPWP_LAYOUT.resetToDefaults();
            }
            // Sidebar width lives on its own CSS var + inline width (set by
            // resizeSidebar). resetToDefaults() clears the stored value; clear
            // the live styles too so the sidebar snaps back to the default
            // without a reload.
            document.documentElement.style.removeProperty( '--zipwp-sidebar-width' );
            const sidebarContainer = document.getElementById( 'zip-ai-assistant-container' );
            if ( sidebarContainer ) {
                sidebarContainer.style.width = '';
            }
            this.restorePanelLayout();
        }

        reloadWithAutoOpen() {
            // In full-page mode, just reload — the assistant is already the whole page.
            if ( this.config.displayMode === 'fullpage' ) {
                window.location.reload();
                return;
            }
            const url = new URL( window.location.href );
            url.searchParams.set( 'zipwp_open_assistant', '1' );
            window.location.href = url.toString();
        }

        // ── Context ───────────────────────────────────────────────────

        getContext() {
            return {
                wp_user_id: this.config.userId || null,
                editor_context: this.getEditorContext(),
                page_context: this.getPageContext(),
                website_context: {
                    current_url: window.location.href,
                    ...( this.config.websiteContext || {} ),
                },
                theme_context: this.config.themeContext || {},
                installed_plugins: this.config.installedPlugins || {},
                admin_screen: this.getAdminScreenContext(),
            };
        }

        getEditorContext() {
            const snapshotId = ++this._snapshotCounter;

            // PHP-authoritative flag from `get_current_screen()->is_block_editor()`,
            // already narrowed server-side to the native post/page editor
            // (screen base 'post'; see React_Manager::is_block_editor_screen).
            // Used as a fallback when `wp.data` / `core/block-editor` haven't
            // initialized yet at iframe boot — so the brain doesn't see
            // is_block_editor=false on the first turn and route to dashboard mode.
            const phpIsBlockEditor = !! ( this.config && this.config.isBlockEditor );

            // Native post/page editor URL gate. The DOM/store heuristic below
            // (block-list layout, .editor-styles-wrapper, #editor) matches ANY
            // screen rendering @wordpress/block-editor — including SureCart's
            // page editor and the Site Editor — so without this gate it would
            // re-flag those as the block editor even after PHP is narrowed.
            // The host bridge runs in the parent admin window, so location is
            // the real wp-admin URL: only post.php / post-new.php may qualify.
            const editorPath = ( window.location && window.location.pathname ) || '';
            // eslint-disable-next-line @wordpress/no-unused-vars-before-return
            const isNativePostEditorUrl = /\/(post|post-new)\.php$/.test( editorPath );

            const editorContext = {
                is_block_editor: phpIsBlockEditor,
                // B1 capability handshake — this bundle's browser-RPC protocol
                // version. Declares that THIS host bridge executes js_rpc
                // editor/* dispatches and POSTs replies to /agent/rpc-reply.
                // The brain routes to its AgentBrowserLoop ONLY when it sees
                // rpc >= 1; a bundle without the stamp (v1) degrades to the
                // dashboard flow instead of timing out on every editor tool.
                // Bump ONLY when the dispatch/reply contract changes shape.
                rpc: RPC_PROTOCOL_VERSION,
                selected_block: null,
            };

            if ( ! window.wp || ! window.wp.data ) {
return editorContext;
}

            const select = window.wp.data.select;
            const blockEditorSelect = select( 'core/block-editor' );
            if ( ! blockEditorSelect ) {
return editorContext;
}

            const currentSelectedId = blockEditorSelect.getSelectedBlockClientId();
            if ( currentSelectedId !== this._lastSelectedId ) {
                this._lastSelectedId = currentSelectedId;
                this._selectionRevision = ( this._selectionRevision || 0 ) + 1;
            }

            const blocks = blockEditorSelect.getBlocks();
            const hasEditorDOM = document.body.classList.contains( 'block-editor-page' ) ||
                document.querySelector( '.block-editor-block-list__layout' ) ||
                document.querySelector( '.editor-styles-wrapper' ) ||
                document.querySelector( '#editor' );

            // Fallback: core/editor.getEditingMode() returns 'visual' in block editor context
            // Guards against DOM selector drift across WP versions
            const coreEditorSelectEarly = select( 'core/editor' );
            const hasEditorMode = coreEditorSelectEarly
                ? ( coreEditorSelectEarly.getEditingMode?.() === 'visual' || coreEditorSelectEarly.getCurrentPostId?.() > 0 )
                : false;

            editorContext.is_block_editor = phpIsBlockEditor || ( isNativePostEditorUrl && Array.isArray( blocks ) && ( hasEditorDOM || hasEditorMode ) );

            // Include post_id from the core/editor store so the brain knows which page is open
            const coreEditorSelect = coreEditorSelectEarly;
            if ( coreEditorSelect ) {
                const postId = coreEditorSelect.getCurrentPostId();
                const postTitle = coreEditorSelect.getEditedPostAttribute( 'title' );
                const postType = coreEditorSelect.getCurrentPostType();
                // Public permalink of the open page — the measured-DNA render
                // target for editor__generate_section (Issue #1083: a revamp/add
                // matches the page's real spacing/layout, not only colour). The
                // brain host-checks it against the token-bound site before any
                // render. SAME source as buildSectionDnaContext (the picker path,
                // src/services/dnaContext.js) so both share the one DNA cache.
                const pageUrl = coreEditorSelect.getEditedPostAttribute( 'link' );
                if ( postId ) {
editorContext.post_id = postId;
}
                if ( postTitle ) {
editorContext.post_title = postTitle;
}
                if ( postType ) {
editorContext.post_type = postType;
}
                if ( pageUrl ) {
editorContext.page_url = pageUrl;
}
            }

            // Build the compact page outline (top-level sections) for AI navigation.
            // Block detail is pulled on demand by the brain via editor__get_context
            // (fresh clientIds + classNames), never dumped here — so no live block index.
            if ( editorContext.is_block_editor && blocks.length > 0 ) {
                editorContext.page_outline = this.buildPageOutline( blocks );
            }

            editorContext.snapshot_id = snapshotId;
            editorContext.selection_revision = this._selectionRevision || 0;

            const selectedBlock = blockEditorSelect.getSelectedBlock();
            const hasLiveSelection = !! ( selectedBlock && selectedBlock.clientId && selectedBlock.name );
            // Explicit scope intent — replaces the brain's page-wide TEXT regex
            // (computeSelectionBinding). A live block selection means the user is
            // acting on THAT element, so confine the edit to it (page_wide:false);
            // no selection means a page-wide edit is fine (page_wide:true).
            // Deselecting is the explicit affordance to widen scope. Driven by
            // REAL editor state, never the user's words — so a coincidental "all
            // sections" phrase can't silently disable the selection scope lock.
            // Keep in lockstep with the iframe path (src/hooks/useMessageSubmit.js).
            editorContext.page_wide = ! hasLiveSelection;
            if ( hasLiveSelection ) {
                // Build the brain's selected_block DTO DIRECTLY in snake_case — the
                // exact shape the brain's zod reads ({ client_id, block_name }). NO
                // camelCase intermediate (serializeBlockLight emitted clientId/name +
                // texts, all of which the brain strips) so there is no two-spelling
                // drift surface to fall out of sync — the original "client_id: Required"
                // turn-drop bug. Mirrors src/utils/selectedBlockDto.js, the iframe path's
                // builder; keep the two in lockstep. The guard above omits the selection
                // entirely when ids are absent, never a partial.
                editorContext.selected_block = {
                    client_id: selectedBlock.clientId,
                    block_name: selectedBlock.name,
                };
                // Repeater signal — so the brain sees "the user selected a card
                // grid" and routes "add one more" to a CHILD clone (duplicateBlocks
                // the last child) instead of authoring a brand-new section. Mirrors
                // the page-outline heuristic (selectedBlockRepeatInfo). Iframe path
                // computes the same in src/utils/selectedBlockDto.js — keep both in
                // lockstep. getSelectedBlock() carries innerBlocks live this turn.
                const repeatInfo = this.selectedBlockRepeatInfo( selectedBlock );
                editorContext.selected_block.repeated_children = repeatInfo.repeated_children;
                editorContext.selected_block.repeated_child_count = repeatInfo.repeated_child_count;
                if ( repeatInfo.last_child_client_id ) {
                    editorContext.selected_block.last_child_client_id = repeatInfo.last_child_client_id;
                }
                // Selection's ancestor chain (root-first, getBlockParents) so the
                // brain can resolve a nested selection to the OUTLINE section that
                // CONTAINS it — the section the user means by "this section" (the
                // deepest clicked block is not itself in the outline). Mirrors the
                // iframe path (src/hooks/useMessageSubmit.js); keep both in lockstep.
                // getBlockParents is guarded (typeof) the same way apply-change's
                // scope lock guards it: an OLDER Gutenberg without it must NOT throw
                // out of getEditorContext (that would drop the WHOLE editor context
                // for the turn, not just this hint).
                const selectionAncestors =
                    typeof blockEditorSelect.getBlockParents === 'function'
                        ? blockEditorSelect.getBlockParents( selectedBlock.clientId ) || []
                        : [];
                if ( selectionAncestors.length > 0 ) {
                    editorContext.selected_block.section_ancestor_client_ids = selectionAncestors.filter(
                        ( id ) => typeof id === 'string' && id !== ''
                    );
                }
                // NOTE: selected_block carries only the small wire DTO the brain's
                // schema declares — client_id, block_name, text, the repeater hint
                // (repeated_children / repeated_child_count / last_child_client_id),
                // and section_ancestor_client_ids. The brain STRIPS anything else
                // (wireToDomain). Two attr bundles were computed
                // here every turn and silently dropped: a parent_* bundle
                // (parent_client_id / parent_block_name / parent_attributes_schema /
                // parent_config_attrs) and selected_block.config_attrs (pickConfigAttrs)
                // — pure wasted per-turn compute + a boundary smear (plugin-side attr-
                // schema interpretation that never reached the model). Exact attribute
                // names are available on demand via editor__get_context. Removed (L-8).
            }

            // Live theme.json design tokens (editor getSettings()) — the
            // deterministic, always-present palette/type SSOT the brain styles
            // against. Emitted directly in the BRAIN WIRE SHAPE
            // (`theme_tokens: { colors: [{slug, hex}], font_sizes: [{slug, size}] }`)
            // so Laravel relays it VERBATIM with no reshaping. The WP getSettings()
            // shape is `[{name, slug, color|size}]`; we map + drop malformed entries
            // here at the source.
            const editorSettings = blockEditorSelect.getSettings();
            if ( editorSettings ) {
                const themeTokens = buildThemeTokens( editorSettings );
                if ( themeTokens ) {
editorContext.theme_tokens = themeTokens;
}
            }

            // Read-the-neighbour matcher: a representative existing section's
            // ACTUALLY-RENDERED signature (heading font/colour + brand accent).
            // theme_tokens/getSettings expose fixed slots, but on a multi-brand
            // page the page's real brand may live in a different slot (--accent,
            // not --primary) or an untokenised font — so the brain matches a new
            // section to what a real neighbour RENDERS. Canvas-read, best-effort.
            if ( editorContext.is_block_editor ) {
                const sectionSignature = this.buildSectionSignature();
                if ( sectionSignature ) {
                    editorContext.section_signature = sectionSignature;
                }
            }

            // Inject and clear results from the previous turn's js_hook executions
            if ( window.__zipwpLastToolResults && window.__zipwpLastToolResults.length > 0 ) {
                editorContext.last_tool_results = window.__zipwpLastToolResults;
                window.__zipwpLastToolResults = null;
            }

            return editorContext;
        }

        // Read-the-neighbour matcher. Sample EVERY top-level section in the live
        // canvas and return the page's DOMINANT rendered signature — the most
        // common heading font-family + colour, and the most common brand accent
        // (button backgrounds, else link/icon colours). Reading all sections + a
        // mode makes it robust to an outlier band that a single sample could land
        // on. The brain resolves the accent colour to whichever site token renders
        // it (so a page whose brand is --accent matches, not the stale --primary).
        // Best-effort: any failure → null, so the editor-context build never
        // breaks. Canvas render ≠ frontend, but accent/font resolve correctly in
        // the canvas — enough to match by.
        buildSectionSignature() {
            try {
                const shared = window.zipwpEditorShared;
                const doc = shared && shared.canvasDoc ? shared.canvasDoc() : document;
                const view = doc.defaultView || window;
                const all = Array.prototype.slice.call(
                    doc.querySelectorAll( 'section, .wp-block-spectra-container' )
                ).filter( ( s ) => s.offsetHeight > 160 );
                const top = all.filter( ( s ) => ! all.some( ( o ) => o !== s && o.contains( s ) ) );
                if ( ! top.length ) {
                    return null;
                }
                // Read EVERY top-level section and take the most common value per
                // field (the MODE) — a single sample can land on an outlier band;
                // the mode is the page's DOMINANT brand signature. Buttons are the
                // clearest brand pop, so button backgrounds decide the accent and
                // link/icon colours are only a fallback when no section has one.
                const opaque = ( c ) => c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent';
                const rgbOf = ( c ) => {
                    const m = /rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/.exec( c || '' );
                    return m ? [ Number( m[ 1 ] ), Number( m[ 2 ] ), Number( m[ 3 ] ) ] : null;
                };
                // A colour worth counting as a brand ACCENT: opaque AND chromatic.
                // The old opaque()-only gate accepted near-neutrals (a white/black/
                // grey button, or the near-black/near-white a plain link contributes)
                // and reported them as the page accent. Require real saturation in a
                // mid lightness band so #000/#fff/greys never become the "brand pop".
                const isBrandAccent = ( c ) => {
                    if ( ! opaque( c ) ) {
                        return false;
                    }
                    const p = rgbOf( c );
                    if ( ! p ) {
                        return false;
                    }
                    const max = Math.max( p[ 0 ], p[ 1 ], p[ 2 ] );
                    const min = Math.min( p[ 0 ], p[ 1 ], p[ 2 ] );
                    const sat = max === 0 ? 0 : ( max - min ) / max;
                    const light = max / 255;
                    return sat >= 0.15 && light >= 0.12 && light <= 0.95;
                };
                const fonts = {};
                const headingColors = {};
                const btnAccents = {};
                const linkAccents = {};
                const bump = ( map, key ) => {
                    if ( key ) {
                        map[ key ] = ( map[ key ] || 0 ) + 1;
                    }
                };
                for ( let i = 0; i < top.length; i++ ) {
                    const sec = top[ i ];
                    const h = sec.querySelector( 'h1, h2, h3' );
                    if ( h ) {
                        const hcs = view.getComputedStyle( h );
                        bump( fonts, hcs.fontFamily );
                        bump( headingColors, hcs.color );
                    }
                    const btn = sec.querySelector( '.wp-block-button__link, a.wp-block-button__link, button' );
                    const btnBg = btn ? view.getComputedStyle( btn ).backgroundColor : '';
                    if ( isBrandAccent( btnBg ) ) {
                        bump( btnAccents, btnBg );
                    } else {
                        // Fallback: an accent-BEARING element, never a bare text link.
                        // The brain's global CSS sets `a { color: var(--primary) }`, so
                        // a plain <a> echoes --primary — the exact false signal that
                        // stamps a new section gold on a green-brand page. Prefer a
                        // button-styled link's TEXT colour (captures an outline/ghost
                        // CTA whose transparent bg skipped the branch above), then an
                        // icon; the chroma filter drops any neutral that slips through.
                        const pop = sec.querySelector( 'a.wp-block-button__link, i[class*="fa-"], svg' );
                        const popColor = pop ? view.getComputedStyle( pop ).color : '';
                        if ( isBrandAccent( popColor ) ) {
                            bump( linkAccents, popColor );
                        }
                    }
                }
                const mode = ( map ) => {
                    let best = null;
                    let bestN = 0;
                    const keys = Object.keys( map );
                    for ( let i = 0; i < keys.length; i++ ) {
                        if ( map[ keys[ i ] ] > bestN ) {
                            bestN = map[ keys[ i ] ];
                            best = keys[ i ];
                        }
                    }
                    return best;
                };
                const sig = {};
                const hf = mode( fonts );
                if ( hf ) {
                    sig.heading_font = hf;
                }
                const hc = mode( headingColors );
                if ( hc ) {
                    sig.heading_color = hc;
                }
                const accent = mode( btnAccents ) || mode( linkAccents );
                if ( accent ) {
                    sig.accent_color = accent;
                }
                return Object.keys( sig ).length ? sig : null;
            } catch ( e ) {
                return null;
            }
        }

        buildPageOutline( blocks ) {
            function countDeep( innerBlocks ) {
                if ( ! innerBlocks || ! innerBlocks.length ) {
return 0;
}
                let count = 0;
                const queue = innerBlocks.slice();
                while ( queue.length > 0 ) {
                    const b = queue.shift();
                    count++;
                    if ( b.innerBlocks && b.innerBlocks.length > 0 ) {
                        for ( let i = 0; i < b.innerBlocks.length; i++ ) {
queue.push( b.innerBlocks[ i ] );
}
                    }
                }
                return count;
            }

            function clipLabel( s ) {
                return s.length > 80 ? s.substring( 0, 77 ) + '...' : s;
            }
            // A block's OWN representative text — LIVE TEXT_KEYS attributes ONLY.
            // We deliberately do NOT fall back to block.originalContent: that is
            // the parse-time serialized HTML, which goes STALE vs the live
            // attributes after an in-editor edit. A stale label can steer the
            // model to match the WRONG section → wrong clientId (select-X →
            // operate-Y). A block with no live text attr returns null, and
            // extractHeading then DESCENDS to a child that has one — so a section
            // CONTAINER (no own text) is always labelled by its real heading.
            function ownText( block ) {
                const attrs = block && block.attributes;
                if ( attrs ) {
                    for ( let i = 0; i < TEXT_KEYS.length; i++ ) {
                        const val = attrs[ TEXT_KEYS[ i ] ];
                        if ( typeof val === 'string' && val.trim() ) {
                            const stripped = val.replace( /<[^>]*>/g, '' ).trim();
                            if ( stripped ) {
return stripped;
}
                        }
                    }
                }
                return null;
            }
            // A heading-ish block: a heading block type, or a content block whose
            // tag is h1-h6. Preferred as a section's label over body copy.
            function isHeadingBlock( block ) {
                if ( ! block || ! block.name ) {
return false;
}
                if ( block.name.indexOf( 'heading' ) !== -1 ) {
return true;
}
                const tag = block.attributes && ( block.attributes.tagName || block.attributes.htmlTag );
                return typeof tag === 'string' && /^h[1-6]$/i.test( tag );
            }
            // A section's label. A section CONTAINER carries no text of its own —
            // its identifying heading lives in a NESTED child — so when the block
            // itself has no text we DESCEND (document order) and surface the first
            // nested heading (h1-h6 / heading block), falling back to the first
            // nested text. Without this every Spectra/FSE section reads as an
            // anonymous `spectra/container` (heading: null) and the agent cannot
            // tell the hero from the CTA — forcing a get_context drill per section
            // (or a fabricated answer). With it, the outline is a real section map.
            function extractHeading( block ) {
                const own = ownText( block );
                if ( own ) {
return clipLabel( own );
}
                let firstText = null;
                function walk( b ) {
                    if ( ! b ) {
return null;
}
                    const t = ownText( b );
                    if ( t ) {
                        if ( isHeadingBlock( b ) ) {
return t;
} // best: a real heading
                        if ( firstText === null ) {
firstText = t;
} // fallback: first text in order
                    }
                    if ( b.innerBlocks ) {
                        for ( let i = 0; i < b.innerBlocks.length; i++ ) {
                            const r = walk( b.innerBlocks[ i ] );
                            if ( r ) {
return r;
}
                        }
                    }
                    return null;
                }
                const inner = block.innerBlocks || [];
                for ( let j = 0; j < inner.length; j++ ) {
                    const found = walk( inner[ j ] );
                    if ( found ) {
return clipLabel( found );
}
                }
                return firstText !== null ? clipLabel( firstText ) : null;
            }

            function hasButtons( block ) {
                // DFS scan for button blocks
                const stack = [ block ];
                while ( stack.length > 0 ) {
                    const b = stack.pop();
                    if ( b.name && ( b.name.indexOf( 'button' ) !== -1 || b.name.indexOf( 'cta' ) !== -1 ) ) {
return true;
}
                    if ( b.innerBlocks ) {
                        for ( let i = 0; i < b.innerBlocks.length; i++ ) {
stack.push( b.innerBlocks[ i ] );
}
                    }
                }
                return false;
            }

            function hasFormFields( block ) {
                const stack = [ block ];
                while ( stack.length > 0 ) {
                    const b = stack.pop();
                    if ( b.name && ( b.name.indexOf( 'form' ) !== -1 || b.name.indexOf( 'input' ) !== -1 || b.name.indexOf( 'field' ) !== -1 ) ) {
return true;
}
                    if ( b.innerBlocks ) {
                        for ( let i = 0; i < b.innerBlocks.length; i++ ) {
stack.push( b.innerBlocks[ i ] );
}
                    }
                }
                return false;
            }

            // --- Section surfacing ------------------------------------------
            // Spectra/FSE pages nest every visual section under one (or a chain
            // of) WRAPPER container(s) whose own class is empty or purely
            // layout/alignment. getBlocks() therefore hands us the wrapper, not
            // the sections. Descend through such a single wrapper until we reach
            // the level where the sections actually live — multiple siblings, a
            // single SEMANTICALLY-classed section, or a non-container. This makes
            // the outline a real section map the agent can target directly.
            function blockHasText( b ) {
                const a = ( b && b.attributes ) || {};
                const t = typeof a.content === 'string' ? a.content : ( typeof a.text === 'string' ? a.text : '' );
                return t.replace( /<[^>]*>/g, '' ).trim().length > 0;
            }
            function isContainerBlock( b ) {
                return !! ( b && b.name && ( b.name.indexOf( 'container' ) !== -1 || b.name === 'core/group' || b.name === 'core/columns' ) );
            }
            // A class string is "wrapper-generic" when every token is a layout /
            // alignment / auto class (alignfull, is-layout-*, wp-block-*, has-*)
            // — nothing SEMANTIC like zip-ai-hero. Such a container is a pass-through
            // wrapper we descend past; a semantic class marks a real section.
            //
            // The converter's own intent markers (spectra-no-padding /
            // spectra-no-width / spectra-no-block-gap) are PLUMBING, not
            // semantics — they are stamped unconditionally on every container
            // the converter emits. Counting them as semantic made a lone root
            // wrapper read as "a section": measured 2026-08-22, a single-root
            // page with 114 child sections rendered a ONE-row outline
            // (`alignfull spectra-no-padding spectra-no-block-gap`), so the
            // agent had no targets, its reads were capped at the top of the
            // page, and a "change email" turn burned 20 steps finding nothing.
            function isGenericWrapperClass( cls ) {
                if ( ! cls ) {
return true;
}
                const tokens = cls.split( /\s+/ );
                for ( let k = 0; k < tokens.length; k++ ) {
                    const t = tokens[ k ];
                    if ( t && ! /^align(full|wide|left|right|center|none)$|^is-layout-|^wp-block-|^has-|^spectra-no-/.test( t ) ) {
return false;
}
                }
                return true;
            }
            function sectionLevel( rootBlocks ) {
                let level = rootBlocks || [];
                for ( let hop = 0; hop < 6; hop++ ) {
                    // meaningful siblings = drop trailing freeform / empty spacers
                    // so a lone wrapper isn't masked by decorative neighbours.
                    const meaningful = [];
                    for ( let j = 0; j < level.length; j++ ) {
                        const b = level[ j ];
                        if ( ! b || ! b.name || b.name.indexOf( 'freeform' ) !== -1 ) {
continue;
}
                        if ( ( b.innerBlocks && b.innerBlocks.length > 0 ) || blockHasText( b ) ) {
meaningful.push( b );
}
                    }
                    if ( meaningful.length !== 1 ) {
break;
} // 0 or 2+ siblings → this IS the section level
                    const only = meaningful[ 0 ];
                    const cls = ( only.attributes && typeof only.attributes.className === 'string' ? only.attributes.className : '' ).trim();
                    const canDescend = isContainerBlock( only ) && isGenericWrapperClass( cls ) && only.innerBlocks && only.innerBlocks.length > 0;
                    if ( ! canDescend ) {
break;
} // semantic-classed container = a section; non-container = stop
                    level = only.innerBlocks;
                }
                return level.length > 0 ? level : ( rootBlocks || [] );
            }

            // Emits the BRAIN WIRE SHAPE for each outline row directly (snake_case
            // `client_id`, optional fields OMITTED rather than null). Laravel relays
            // page_outline VERBATIM — it does no renaming, hand-picking, or default
            // injection. The brain's EditorSchema (wireSchemas.ts) is the sole
            // validator: `heading_excerpt` is z.string().min(1).optional() so it must
            // be ABSENT (not null) when there's no heading.
            return sectionLevel( blocks ).map( function( block ) {
                // Repeated card-like children (3+ of one block type) — shared
                // detection with the selected-block repeater signal.
                const repeated = dominantRepeatedChildType( block.innerBlocks || [] ) !== null;

                const row = {
                    client_id: block.clientId,
                    block_name: block.name,
                    // Section's current class string — so the brain can restyle a
                    // section (className REPLACES wholesale, so it needs the
                    // existing classes to keep) DIRECTLY from the outline, without
                    // a get_context round-trip.
                    class_name: ( block.attributes && typeof block.attributes.className === 'string' )
                        ? block.attributes.className : '',
                    inner_block_count: countDeep( block.innerBlocks ),
                    has_buttons: hasButtons( block ),
                    has_form_fields: hasFormFields( block ),
                    repeated_children: repeated,
                };

                // Optional hint — present only when the section actually carries a
                // heading. Omitted (never null) to satisfy the brain's optional() schema.
                const heading = extractHeading( block );
                if ( heading ) {
row.heading_excerpt = heading;
}

                return row;
            } );
        }

        // Repeater shape of the SELECTED block, in the brain wire shape, via the
        // shared dominantRepeatedChildType detection. The clone target is the
        // last child OF THE DOMINANT type (never a trailing CTA / spacer of a
        // different type), and the count is that type's occurrence count (the
        // number of cards), not the deep descendant total. Kept in lockstep with
        // the iframe builder src/utils/selectedBlockDto.js::repeatInfoFromInnerBlocks.
        selectedBlockRepeatInfo( block ) {
            const children = ( block && block.innerBlocks ) || [];
            let dominant = dominantRepeatedChildType( children );
            let gridChildren = children;
            if ( dominant === null ) {
                const nested = nestedGrid( children );
                if ( nested !== null ) {
                    dominant = nested.dominant;
                    gridChildren = nested.children;
                }
            }
            if ( dominant === null ) {
return { repeated_children: false, repeated_child_count: 0 };
}

            const info = { repeated_children: true, repeated_child_count: dominant.count };
            for ( let i = gridChildren.length - 1; i >= 0; i-- ) {
                const c = gridChildren[ i ];
                if ( c && c.name === dominant.name && typeof c.clientId === 'string' && c.clientId !== '' ) {
                    info.last_child_client_id = c.clientId;
                    break;
                }
            }
            return info;
        }

        getPageContext() {
            const phpPageContext = this.config.pageContext || {};
            return {
                post_id: phpPageContext.post_id || null,
                post_type: phpPageContext.post_type || null,
                post_title: phpPageContext.post_title || null,
                post_status: phpPageContext.post_status || null,
            };
        }

        getAdminScreenContext() {
            const phpContext = window.zipwpMcpContext || {};
            const currentScreen = phpContext.currentScreen || {};
            return {
                id: currentScreen.id || null,
                base: currentScreen.base || null,
                post_type: currentScreen.post_type || null,
                action: currentScreen.action || null,
                parent_base: currentScreen.parent_base || null,
                is_admin: phpContext.isAdmin || false,
            };
        }

        // ── Block Serialization ───────────────────────────────────────

        serializeBlock( block ) {
            if ( ! block ) {
return null;
}

            let blocksHtml = '';
            if ( window.wp && window.wp.blocks && window.wp.blocks.serialize ) {
                try {
                    blocksHtml = window.wp.blocks.serialize( [ block ] );
                    blocksHtml = this.fixUnicodeEscapes( blocksHtml );
                } catch ( e ) {
                    console.warn( 'Failed to serialize block:', e );
                }
            }

            const self = this;
            return {
                clientId: block.clientId,
                name: block.name,
                blockName: block.name,
                attributes: block.attributes,
                attrs: block.attributes,
                innerBlocks: ( block.innerBlocks || [] ).map( function( innerBlock ) {
 return self.serializeBlock( innerBlock );
} ),
                blocks_html: blocksHtml,
            };
        }

        fixUnicodeEscapes( html ) {
            if ( ! html || typeof html !== 'string' ) {
return html;
}
            return html
                .replace( /\\\\u002d/gi, '-' )
                .replace( /\\u002d/gi, '-' )
                .replace( /u002d/gi, '-' );
        }

        // ── Block Editor Operations ───────────────────────────────────

        captureBlockScreenshot( clientId, options ) {
            if ( ! clientId ) {
return Promise.reject( new Error( 'Block clientId is required' ) );
}

            const utils = window.zipwpMcpSpectraUtils;
            if ( ! utils || ! utils.captureBlockScreenshot ) {
                return Promise.reject( new Error( 'Screenshot utility not available' ) );
            }

            return utils.captureBlockScreenshot( clientId, options || {} ).then( function( screenshot ) {
                if ( ! screenshot ) {
throw new Error( 'Failed to capture screenshot' );
}
                return {
                    success: true,
                    base64: screenshot.base64,
                    width: screenshot.width,
                    height: screenshot.height,
                };
            } );
        }

        /**
         * Capture a screenshot of the editor content area.
         *
         * Uses html2canvas to render the editor styles wrapper (or fallback targets)
         * and returns a base64 JPEG data URL (quality 0.7, max width 1280px).
         *
         * @since x.x.x
         * @return {Promise<Object>} { success, dataUrl, width, height } or { success: false, error }
         */
        async captureScreenshot() {
            try {
                // Find the best element to capture
                const target = document.querySelector( '.editor-styles-wrapper' ) ||
                    document.querySelector( '.block-editor-block-list__layout' ) ||
                    document.querySelector( '#wpwrap' );

                if ( ! target ) {
                    return { success: false, error: 'No editor content found' };
                }

                // html2canvas is available via the WP plugin
                let html2canvas = window.html2canvas;
                if ( ! html2canvas ) {
                    // Try dynamic import
                    try {
                        const mod = await import( 'html2canvas' );
                        html2canvas = mod.default || mod;
                    } catch ( e ) {
                        return { success: false, error: 'html2canvas not available' };
                    }
                }

                const canvas = await html2canvas( target, {
                    useCORS: true,
                    allowTaint: true,
                    scale: 1,
                    logging: false,
                    windowWidth: target.scrollWidth,
                    windowHeight: target.scrollHeight,
                } );

                // Resize if too large
                const maxWidth = 1280;
                let finalCanvas = canvas;
                if ( canvas.width > maxWidth ) {
                    finalCanvas = document.createElement( 'canvas' );
                    const ratio = maxWidth / canvas.width;
                    finalCanvas.width = maxWidth;
                    finalCanvas.height = Math.round( canvas.height * ratio );
                    const ctx = finalCanvas.getContext( '2d' );
                    ctx.drawImage( canvas, 0, 0, finalCanvas.width, finalCanvas.height );
                }

                return {
                    success: true,
                    dataUrl: finalCanvas.toDataURL( 'image/jpeg', 0.7 ),
                    width: finalCanvas.width,
                    height: finalCanvas.height,
                };
            } catch ( e ) {
                return { success: false, error: e.message || 'Screenshot capture failed' };
            }
        }

        // ── Tool Execution ────────────────────────────────────────────

        // `sessionId` (M1) — the chat session that dispatched these calls; the
        // React SSE layer passes it so js_rpc replies can carry it to Laravel,
        // which verifies it against the brain's owner key for the call_id.
        async executeTools( executionResults, sessionId ) {
            if ( window.ZIPAI_CONFIG && window.ZIPAI_CONFIG.debug ) {
                console.log( '[ZIP AI:wp-bridge-host] executeTools called count=%d tools=%s',
                    executionResults.length,
                    executionResults.map( ( r ) => r.js_handler || r.tool_name ).join( ', ' ) );
            }

            for ( let i = 0; i < executionResults.length; i++ ) {
                    const result = executionResults[ i ];
                    if ( result.execution_mode === 'js_hook' || result.execution_mode === 'hybrid' || result.execution_mode === 'js_rpc' ) {
                        try {
                            // eslint-disable-next-line no-var
                            var toolName = result.js_handler || result.tool_name;
                            const toolArguments = result.arguments || {};
                            // Anthropic-assigned tool_use_id, stamped by the
                            // SSE handler before this dispatch. Carried onto
                            // window.__zipwpLastToolResults so the brain's
                            // jsHookReconciler can attribute the next-turn
                            // result deterministically to the originating
                            // todo via todo.dispatched_call_ids.
                            // eslint-disable-next-line no-var
                            var toolCallId = result.call_id || null;

                            // B-1 / P5 idempotency: decide BEFORE running the handler.
                            // The pure decision (core/rpc-dedup.js, unit-tested) reads
                            // the prior _rpcSeen entry: a prior entry (in-flight OR
                            // completed) → NEVER re-dispatch (that double-applies) —
                            // re-POST it so a waiting brain BRPOP resolves; a first run
                            // → record the IN-FLIGHT marker NOW (before the handler
                            // mutates the tree) so a crash mid-apply still blocks a
                            // replay (the replay reposts an uncertain ok:false reply →
                            // the brain verifies before retrying). Degrades to the
                            // pre-P5 seen-check if the helper module isn't loaded (no
                            // crash-window marker) — never a duplicate of the logic.
                            if ( result.execution_mode === 'js_rpc' ) {
                                const seenReply = rpcSeenGet( toolCallId );
                                const dedupApi = ( typeof window !== 'undefined' ) ? window.zipwpRpcDedup : null;
                                const dedup = ( dedupApi && dedupApi.decideJsRpcDispatch )
                                    ? dedupApi.decideJsRpcDispatch( seenReply, PAGE_LOAD_ID )
                                    : { action: seenReply ? 'repost_cached' : 'run', reply: seenReply, inFlightMarker: null };
                                if ( dedup.action === 'repost_cached' ) {
                                    console.warn( '[ZIP AI:wp-bridge-host] js_rpc duplicate/in-flight call_id=%s — skipping re-dispatch, re-POSTing reply', toolCallId );
                                    await this.postRpcReply( toolCallId, dedup.reply.ok, dedup.reply.data, dedup.reply.error, sessionId );
                                    continue;
                                }
                                if ( dedup.inFlightMarker ) {
                                    const inflight = dedup.inFlightMarker;
                                    rpcSeenRemember( toolCallId, inflight.ok, inflight.data, inflight.error );
                                }
                            }

                            const hasHandler = window.zipwpMcp && window.zipwpMcp.toolHooks &&
                                window.zipwpMcp.toolHooks.hasHandler( toolName );

                            if ( window.ZIPAI_CONFIG && window.ZIPAI_CONFIG.debug ) {
                                console.log( '[ZIP AI:wp-bridge-host] executing tool=%s hasHandler=%s zipwpMcp=%s',
                                    toolName, hasHandler, !! ( window.zipwpMcp ) );
                            }

                            // [RTRACE] BRIDGE-IN — the js_rpc envelope arrives at the browser bridge
                            // and the registered handler is about to be invoked with the wire args.
                            if ( result.execution_mode === 'js_rpc' ) {
                                try {
                                    // attr_keys is the load-bearing signal: a key the
                                    // target block doesn't define (e.g. tagName on a
                                    // spectra/container, which uses htmlTag) is silently
                                    // dropped by the registry → the model never sees the
                                    // edit "complete" and re-tries. Surface it at the JS
                                    // hop so the loop is visible from the browser too.
                                    const _ops = ( toolArguments && Array.isArray( toolArguments.operations ) ) ? toolArguments.operations : [];
                                    const _attrKeys = _ops.reduce( function ( acc, o ) {
                                        if ( o && o.attributes && typeof o.attributes === 'object' ) {
                                            acc.push.apply( acc, Object.keys( o.attributes ) );
                                        }
                                        return acc;
                                    }, [] );
                                    ( window.__zipwpTrace = window.__zipwpTrace || [] ).push( {
                                        hop: 'bridge:receive+invoke', ts: Date.now(),
                                        tool_name: toolName, call_id: toolCallId,
                                        execution_mode: result.execution_mode, has_handler: !! hasHandler,
                                        version: toolArguments && toolArguments.version,
                                        post_id: toolArguments && toolArguments.post_id,
                                        scope: toolArguments && toolArguments.scope,
                                        op_count: _ops.length,
                                        functions: _ops.map( function ( o ) {
 return o && o.function;
} ),
                                        attr_keys: _attrKeys.length ? _attrKeys : null,
                                    } );
                                    if ( window.ZIPAI_CONFIG && window.ZIPAI_CONFIG.debug ) {
 console.log( '[RTRACE] bridge:receive+invoke tool=%s call_id=%s ops=%d attr_keys=%s', toolName, toolCallId, _ops.length, JSON.stringify( _attrKeys ) );
}
                                } catch ( e ) { /* trace never breaks dispatch */ }
                            }

                            if ( ! hasHandler ) {
                                throw new Error( 'No handler available for tool: ' + toolName );
                            }

                            // L-16: the full-page getContext() re-walk was merged
                            // into every envelope as `_wordpress_context` — but NO
                            // editor handler reads it (the brain strips it at the
                            // wire). Dropped the per-envelope re-walk; pass the wire
                            // args through directly.
                            // eslint-disable-next-line no-var
                            var toolArgs = Object.assign( {}, toolArguments );

                            const hookResult = await window.zipwpMcp.toolHooks.executeToolHook(
                                toolName,
                                toolArgs,
                                { jsExecutionResult: executionResults }
                            );

                            // Capture result for next-turn context injection
                            if ( window.ZIPAI_CONFIG && window.ZIPAI_CONFIG.debug ) {
 console.log( '[ZIP AI:wp-bridge-host] handler finished tool=%s hookResult=%s', toolName, JSON.stringify( hookResult ) );
}

                            // Vibe Editing v2 — synchronous RPC: POST the reply so the brain's
                            // AgentBrowserLoop (blocked on BRPOP for this call_id) resolves the
                            // call IN THE SAME TURN, then skip the next-turn buffer below.
                            if ( result.execution_mode === 'js_rpc' ) {
                                const rpcOk = !! ( hookResult && hookResult.success !== false );
                                const rpcData = hookResult ? hookResult.data : undefined;
                                const rpcErr = hookResult ? ( hookResult.error || null ) : 'js_rpc handler returned no result';
                                // [RTRACE] BRIDGE-OUT — what wp.data actually produced,
                                // before the reply goes back to the brain. The reply
                                // summary (applied / failed / unknown_attrs) is what the
                                // model reacts to next step; unknown_attrs here is the
                                // browser-side proof of a silent registry drop.
                                try {
                                    const _ap = ( rpcData && Array.isArray( rpcData.applied ) ) ? rpcData.applied : [];
                                    const _ua = _ap.reduce( function ( acc, a ) {
                                        if ( a && Array.isArray( a.unknown_attrs ) ) {
acc.push.apply( acc, a.unknown_attrs );
}
                                        return acc;
                                    }, [] );
                                    ( window.__zipwpTrace = window.__zipwpTrace || [] ).push( {
                                        hop: 'bridge:reply', ts: Date.now(),
                                        tool_name: toolName, call_id: toolCallId, ok: rpcOk,
                                        applied: _ap.length,
                                        failed: ( rpcData && Array.isArray( rpcData.failed ) ) ? rpcData.failed.length : null,
                                        unknown_attrs: _ua.length ? _ua : null,
                                        blocks: ( rpcData && Array.isArray( rpcData.blocks ) ) ? rpcData.blocks.length : null,
                                        error: rpcErr,
                                    } );
                                    if ( window.ZIPAI_CONFIG && window.ZIPAI_CONFIG.debug ) {
 console.log( '[RTRACE] bridge:reply tool=%s call_id=%s ok=%s applied=%d unknown_attrs=%s', toolName, toolCallId, rpcOk, _ap.length, JSON.stringify( _ua ) );
}
                                } catch ( e ) { /* trace never breaks dispatch */ }
                                // UPGRADE the in-flight marker to the REAL reply, and
                                // before posting so a replay during the POST round-trip
                                // still de-dups against the final reply (B-1 / P5).
                                rpcSeenRemember( toolCallId, rpcOk, rpcData, rpcErr, {
                                    mutating: rpcOk === true && SESSION_SCOPED_TOOLS[ toolName ] === true,
                                    pageLoadId: PAGE_LOAD_ID,
                                } );
                                await this.postRpcReply( toolCallId, rpcOk, rpcData, rpcErr, sessionId );
                                continue;
                            }

                            if ( ! window.__zipwpLastToolResults ) {
                                window.__zipwpLastToolResults = [];
                            }
                            if ( hookResult && typeof hookResult === 'object' ) {
                                window.__zipwpLastToolResults.push( {
                                    tool: toolName,
                                    // Primary correlation: Anthropic tool_use_id assigned by the LLM,
                                    // plumbed end-to-end. The brain's jsHookReconciler matches this
                                    // against todo.dispatched_call_ids — deterministic, identity-based.
                                    call_id: toolCallId,
                                    // Fallback correlation: original tool arguments. Used when
                                    // call_id is missing (legacy bundle or non-LLM-initiated paths)
                                    // — the reconciler subset-matches against todo.tool_call.arguments.
                                    args: toolArgs || null,
                                    success: hookResult.success !== false,
                                    message: hookResult.message || null,
                                    user_summary: hookResult.user_summary || null,
                                    operation: hookResult.operation || null,
                                    verification: hookResult.verification || hookResult.data?.verification || null,
                                    error: hookResult.error || null,
                                } );
                            } else {
                                console.warn( '[ZIP AI:wp-bridge-host] js_hook returned null/undefined for tool=%s — result NOT captured in last_tool_results', toolName );
                                window.__zipwpLastToolResults.push( {
                                    tool: toolName,
                                    call_id: toolCallId,
                                    args: toolArgs || null,
                                    success: false,
                                    error: 'js_hook handler returned no result',
                                } );
                            }
                        } catch ( error ) {
                            console.error( '[ZIP AI:wp-bridge-host] handler threw for tool=%s error=%s', toolName, error?.message, error );
                            if ( result.execution_mode === 'js_rpc' ) {
                                // A thrown handler may still have partially mutated
                                // the tree, so de-dup this call_id too (B-1).
                                const thrownErr = error?.message || 'js_rpc handler threw';
                                // A session-scoped tool that THREW may still have partially
                                // mutated the tree — tag it mutating so a mid-turn reload
                                // replays RELOAD_REVERIFY_MARKER (re-verify against the
                                // discarded state), not the stale thrown error verbatim.
                                rpcSeenRemember( toolCallId, false, undefined, thrownErr, {
                                    mutating: SESSION_SCOPED_TOOLS[ toolName ] === true,
                                } );
                                await this.postRpcReply( toolCallId, false, undefined, thrownErr, sessionId );
                                continue;
                            }
                            if ( ! window.__zipwpLastToolResults ) {
window.__zipwpLastToolResults = [];
}
                            window.__zipwpLastToolResults.push( {
                                tool: toolName,
                                call_id: toolCallId,
                                args: toolArgs || null,
                                success: false,
                                error: error?.message || 'js_hook threw an exception',
                            } );
                        }
                    }
                }
        }

        // Vibe Editing v2 — POST a synchronous editor-RPC reply to the SaaS so
        // the brain's AgentBrowserLoop (blocked on BRPOP for this call_id)
        // resolves the tool in the SAME turn. Auth + base URL come from
        // window.ZIPAI_CONFIG (the same source the React app's api client uses).
        //
        // `sessionId` (M1) — included as session_id so Laravel can verify the
        // reply against the brain-claimed owner of this call_id (the owner key's
        // VALUE is the dispatching session). Without it any authenticated tenant
        // holding a call_id could inject a reply into a foreign turn.
        //
        // H1 — bounded retry. The reply is the ONLY confirmation the brain gets
        // and there is no later resend, so a transient failure (network blip,
        // 5xx, or a 409 from a not-yet-claimed owner key) must not drop it
        // permanently: every dropped reply costs the brain a full BRPOP window
        // plus a verify round-trip. Retries are safe by construction — Laravel
        // RPUSHes at most one consumable copy per POST and the brain releases
        // the owner key after consuming, so a duplicate late POST is 409-rejected,
        // never double-folded; re-execution is impossible (the handler already
        // ran; _rpcSeen replays the cached reply).
        async postRpcReply( callId, ok, data, error, sessionId ) {
            // The editor turn's executeTools loop AWAITS this POST before moving
            // to the next call, so a hung request would stall the whole turn.
            // Bound it with an AbortController timeout — the brain's BRPOP times
            // out independently on its side, so dropping a slow reply is safe.
            //
            // B-1 INVARIANT: total time here (attempts × timeout + backoff) MUST
            // stay BELOW the brain's BRAIN_EDITOR_RPC_TIMEOUT_MS (default 20s).
            // The plugin applies the change BEFORE replying, so if the brain
            // gives up first it reads a false "nothing applied" and may nudge a
            // retry → duplicate content. Per-attempt timeout 5s × 3 + backoff
            // ≈ 15.9s worst case, ~4s inside the 20s window (F6: widened margin).
            const cfg = window.ZIPAI_CONFIG || {};
            const attempts = 3;
            const backoffMs = 300;
            // F6 — cap the per-attempt timeout so the TOTAL reply budget stays safely
            // under the brain's BRPOP window even if an operator sets a large
            // rpcReplyTimeoutMs. The old 6s default left only ~1s of headroom, and the
            // offset between the brain STARTING its wait and this reply arriving
            // (handler-exec + SSE + the reply route's ownership check) ate it — so a
            // reply that actually succeeded landed after the brain gave up and was
            // read as a FALSE timeout (the edit had already applied). 5s keeps the
            // total ≈ 15.9s, ~4s inside the window.
            const perAttemptMax = 5000;
            const configured = Number( cfg.rpcReplyTimeoutMs ) > 0 ? Number( cfg.rpcReplyTimeoutMs ) : perAttemptMax;
            const timeoutMs = Math.min( configured, perAttemptMax );
            const apiUrl = ( cfg.apiUrl || '/api' ).replace( /\/+$/, '' );
            // Route the reply DIRECT to the brain when configured (same switch
            // as the React api client's brainPath); else fall back to Laravel.
            const rpcReplyUrl = ( cfg.brainUrl ? cfg.brainUrl.replace( /\/+$/, '' ) : apiUrl ) + '/agent/rpc-reply';
            const body = { call_id: callId, ok: !! ok };
            if ( sessionId ) {
 body.session_id = String( sessionId );
}
            if ( data !== undefined && data !== null ) {
 body.data = data;
}
            if ( error ) {
 body.error = String( error );
}
            const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
            if ( cfg.token ) {
 headers.Authorization = 'Bearer ' + cfg.token;
}
            const payload = JSON.stringify( body );

            // [RTRACE] BRIDGE-OUT — the reply POSTs DIRECT to the brain (brainUrl),
            // whose AgentBrowserLoop is blocked on BRPOP for this call_id. Clearing
            // brainUrl reverts to the Laravel path (plugin-wide direct-to-brain toggle).
            try {
                ( window.__zipwpTrace = window.__zipwpTrace || [] ).push( {
                    hop: 'bridge:postRpcReply', ts: Date.now(),
                    call_id: callId, ok: !! ok,
                    endpoint: rpcReplyUrl,
                    applied: ( data && data.applied ) ? data.applied.length : null,
                    failed: ( data && data.failed ) ? data.failed.length : null,
                    refused: ( data && data.refused ) ? data.refused : null,
                    error: error ? String( error ) : null,
                } );
                if ( window.ZIPAI_CONFIG && window.ZIPAI_CONFIG.debug ) {
 console.log( '[RTRACE] bridge:postRpcReply call_id=%s ok=%s', callId, !! ok );
}
            } catch ( e ) { /* trace never breaks reply */ }

            // eslint-disable-next-line no-var
            for ( var attempt = 1; attempt <= attempts; attempt++ ) {
                // eslint-disable-next-line no-var
                var controller = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;
                const timer = controller ? setTimeout( function () {
 controller.abort();
}, timeoutMs ) : null;
                try {
                    const opts = { method: 'POST', headers, body: payload };
                    if ( controller ) {
opts.signal = controller.signal;
}
                    const res = await fetch( rpcReplyUrl, opts );
                    if ( res.ok ) {
return;
}
                    // 4xx other than 409 won't improve on retry (bad payload /
                    // auth); 409 can be the claim race or an already-consumed
                    // reply — retry covers the former, the latter stays 409 and
                    // we stop after the budget.
                    const retryable = res.status === 409 || res.status >= 500;
                    console.error( '[ZIP AI:wp-bridge-host] rpc-reply POST failed status=%d call_id=%s attempt=%d/%d',
                        res.status, callId, attempt, attempts );
                    if ( ! retryable ) {
return;
}
                } catch ( e ) {
                    const reason = ( e && e.name === 'AbortError' ) ? ( 'timeout after ' + timeoutMs + 'ms' ) : ( e && e.message );
                    console.error( '[ZIP AI:wp-bridge-host] rpc-reply POST threw call_id=%s attempt=%d/%d err=%s',
                        callId, attempt, attempts, reason );
                } finally {
                    if ( timer ) {
clearTimeout( timer );
}
                }
                if ( attempt < attempts ) {
                    await new Promise( function ( r ) {
 setTimeout( r, backoffMs * attempt );
} );
                }
            }
        }

        // ── Canvas Loader Protocol ────────────────────────────────────

        applyCanvasLoader( toolName, target ) {
            if ( ! target ) {
return;
}

            switch ( target.type ) {
                case 'block':
                    this._applyBlockLoader( target.id );
                    break;
                case 'selector':
                    this._applySelectorLoader( target.selector );
                    break;
                case 'global':
                    this._applyGlobalLoader( toolName );
                    break;
                case 'fullpage':
                    this._applyFullPageLoader();
                    break;
            }
        }

        // eslint-disable-next-line no-unused-vars
        removeCanvasLoader( toolName, target, success ) {
            if ( ! target ) {
return;
}

            switch ( target.type ) {
                case 'block':
                    this._removeBlockLoader( target.id );
                    break;
                case 'selector':
                    this._removeSelectorLoader( target.selector );
                    break;
                case 'global':
                    this._removeGlobalLoader();
                    break;
                case 'fullpage':
                    this._removeFullPageLoader();
                    break;
            }
        }

        _applyBlockLoader( clientId ) {
            if ( ! clientId ) {
return;
}
            const blockNode = document.querySelector( '[data-block="' + clientId + '"]' );
            if ( blockNode ) {
blockNode.classList.add( 'is-ai-processing' );
}

            if ( window.wp && window.wp.data ) {
                try {
                    window.wp.data.dispatch( 'core/block-editor' )
                        .updateBlockAttributes( clientId, { lock: { move: true, remove: true } } );
                } catch ( e ) { /* block may not support lock */ }
            }
        }

        _removeBlockLoader( clientId ) {
            if ( ! clientId ) {
return;
}
            const blockNode = document.querySelector( '[data-block="' + clientId + '"]' );
            if ( blockNode ) {
blockNode.classList.remove( 'is-ai-processing' );
}

            if ( window.wp && window.wp.data ) {
                try {
                    window.wp.data.dispatch( 'core/block-editor' )
                        .updateBlockAttributes( clientId, { lock: undefined } );
                } catch ( e ) { /* silent */ }
            }
        }

        _applySelectorLoader( selector ) {
            try {
                const el = document.querySelector( selector );
                if ( el ) {
el.classList.add( 'is-ai-processing' );
}
            } catch ( e ) { /* invalid selector */ }
        }

        _removeSelectorLoader( selector ) {
            try {
                const el = document.querySelector( selector );
                if ( el ) {
el.classList.remove( 'is-ai-processing' );
}
            } catch ( e ) { /* silent */ }
        }

        _applyGlobalLoader() {
            if ( document.getElementById( 'ai-global-loader' ) ) {
return;
}
            const toast = document.createElement( 'div' );
            toast.id = 'ai-global-loader';
            toast.className = 'ai-global-processing-toast';
            toast.textContent = 'AI is updating design settings\u2026';
            document.body.appendChild( toast );
        }

        _removeGlobalLoader() {
            const toast = document.getElementById( 'ai-global-loader' );
            if ( toast ) {
toast.remove();
}
        }

        _applyFullPageLoader() {
            if ( document.getElementById( 'ai-fullpage-overlay' ) ) {
return;
}
            const overlay = document.createElement( 'div' );
            overlay.id = 'ai-fullpage-overlay';
            overlay.className = 'ai-fullpage-processing-overlay';
            const text = document.createElement( 'div' );
            text.className = 'ai-fullpage-text';
            text.textContent = 'AI is building your page\u2026';
            overlay.appendChild( text );
            document.body.appendChild( overlay );
        }

        _removeFullPageLoader() {
            const overlay = document.getElementById( 'ai-fullpage-overlay' );
            if ( overlay ) {
overlay.remove();
}
        }

        // ── Color Palette Preview ─────────────────────────────────────

        previewPalette( colors ) {
            if ( ! colors || ! Array.isArray( colors ) ) {
return;
}

            let styleEl = document.getElementById( 'zipwp-palette-preview' );
            if ( ! styleEl ) {
                styleEl = document.createElement( 'style' );
                styleEl.id = 'zipwp-palette-preview';
                document.head.appendChild( styleEl );
            }

            const vars = colors.map( function( c, i ) {
                const slug = ( typeof c === 'object' && c.slug ) || ( 'ast-global-color-' + i );
                const color = typeof c === 'string' ? c : ( c.color || c.hex || '' );
                return '--' + slug + ':' + color;
            } ).join( ';' );

            styleEl.textContent = ':root{' + vars + '}';
        }

        // ── Auth ──────────────────────────────────────────────────────

        checkAuthStatus() {
            const self = this;
            const formData = new URLSearchParams();
            formData.append( 'action', 'zipwp_verify_auth_status' );
            formData.append( 'nonce', self.config.nonce );
            return fetch( self.config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            } )
                .then( function( response ) {
 return response.json();
} )
                .then( function( result ) {
                    return !! ( result && result.success && result.data && result.data.is_authorized );
                } )
                .catch( function() {
 return false;
} );
        }

        openAuthPopup() {
            const self = this;
            return new Promise( function( resolve, reject ) {
                const url = self.config.authUrl;
                if ( ! url || ! self.config.ajaxUrl || ! self.config.nonce ) {
                    reject( new Error( 'Assistant auth configuration is missing. Reload the page and try again.' ) );
                    return;
                }

                const width = 600;
                const height = 700;
                const left = ( screen.width - width ) / 2;
                const top = ( screen.height - height ) / 2;

                const popupWindow = window.open(
                    url,
                    'ZipWP Login',
                    'width=' + width + ',height=' + height + ',top=' + top + ',left=' + left + ',popup=yes,toolbar=no,location=no,menubar=no'
                );

                if ( ! popupWindow || popupWindow.closed ) {
                    reject( new Error( 'Popup was blocked. Please allow popups for this site and try again.' ) );
                    return;
                }

                let iterations = 0;
                const maxIterations = 300;

                // eslint-disable-next-line no-var
                var authPollingInterval = setInterval( async function() {
                    if ( popupWindow.closed || iterations >= maxIterations ) {
                        clearInterval( authPollingInterval );
                        if ( ! popupWindow.closed ) {
popupWindow.close();
}
                        // The popup can close (ZipWP auto-closes on success, or the
                        // user closes it) between two 500ms polls — before a tick
                        // catches is_authorized. Do one final check so a completed
                        // login still reloads into the authed app instead of
                        // stranding the user on the auth screen.
                        if ( popupWindow.closed && await self.checkAuthStatus() ) {
                            self.reloadWithAutoOpen();
                            return;
                        }
                        if ( iterations >= maxIterations ) {
                            reject( new Error( 'Authentication timeout' ) );
                        }
                        return;
                    }

                    if ( await self.checkAuthStatus() ) {
                        if ( ! popupWindow.closed ) {
popupWindow.close();
}
                        clearInterval( authPollingInterval );
                        self.reloadWithAutoOpen();
                    }

                    iterations++;
                }, 500 );
            } );
        }

        // ── Inline Edit Shortcut (Cmd+J / Ctrl+J) ────────────────────

        /**
         * Register Cmd+J / Ctrl+J keyboard shortcut to open sidebar and focus chat input.
         *
         * @since x.x.x
         */
        setupInlineEditShortcut() {
            const self = this;
            const handler = function( e ) {
                const isMac = navigator.platform.toUpperCase().indexOf( 'MAC' ) >= 0;
                const modKey = isMac ? e.metaKey : e.ctrlKey;

                if ( modKey && e.key === 'j' ) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Open sidebar if not visible
                    const container = document.getElementById( 'zip-ai-assistant-container' );
                    if ( container && ! container.classList.contains( 'zip-ai-iframe-visible' ) ) {
                        self.togglePanel();
                    }

                    // Emit event for React to focus input and lock context
                    if ( window.zipwpMcpAppBridge ) {
                        window.zipwpMcpAppBridge.emit( 'inline_edit_shortcut', {} );
                    }
                }
            };

            // Listen on main document
            document.addEventListener( 'keydown', handler, true );

            // Also listen inside the block editor iframe (WP 6.3+)
            const attachToEditorIframe = function() {
                const iframes = document.querySelectorAll( 'iframe[name="editor-canvas"]' );
                iframes.forEach( function( iframe ) {
                    try {
                        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                        if ( iframeDoc && ! iframeDoc._zipwpShortcutAttached ) {
                            iframeDoc.addEventListener( 'keydown', handler, true );
                            iframeDoc._zipwpShortcutAttached = true;
                        }
                    } catch ( e ) { /* cross-origin iframe, skip */ }
                } );
            };

            // Retry attaching since editor iframe loads asynchronously
            setTimeout( attachToEditorIframe, 1000 );
            setTimeout( attachToEditorIframe, 3000 );
        }

        // ── Fullscreen ────────────────────────────────────────────────

        emitFullscreenChanged( isFullscreen ) {
            if ( window.zipwpMcpAppBridge && typeof window.zipwpMcpAppBridge.emit === 'function' ) {
                window.zipwpMcpAppBridge.emit( 'fullscreen_changed', { fullscreen: !! isFullscreen } );
            }
        }

        toggleFullscreen( isFullscreen ) {
            const container = document.getElementById( 'zip-ai-assistant-container' );
            if ( ! container ) {
return;
}

            if ( isFullscreen ) {
                container.classList.add( 'zip-ai-iframe-fullscreen' );
                document.body.classList.add( 'zip-ai-assistant-fullscreen' );
                // Legacy alias for older selectors/scripts.
                document.body.classList.add( 'zip-ai-iframe-fullscreen' );
            } else {
                container.classList.remove( 'zip-ai-iframe-fullscreen' );
                document.body.classList.remove( 'zip-ai-assistant-fullscreen' );
                document.body.classList.remove( 'zip-ai-iframe-fullscreen' );
            }

            this.emitFullscreenChanged( isFullscreen );
        }

        // ── Resize Handle ─────────────────────────────────────────────

        setupResizeHandle() {
            const resizeHandle = document.getElementById( 'zip-ai-resize-handle' );
            if ( ! resizeHandle ) {
return;
}

            let isDragging = false;
            let startX = 0;
            let startWidth = 0;
            const self = this;

            const onMouseDown = function( e ) {
                e.preventDefault();
                isDragging = true;
                startX = e.clientX;
                const container = document.getElementById( 'zip-ai-assistant-container' );
                startWidth = container ? container.offsetWidth : 550;

                resizeHandle.classList.add( 'dragging' );
                document.body.classList.add( 'zip-ai-resizing' );

                document.addEventListener( 'mousemove', onMouseMove );
                document.addEventListener( 'mouseup', onMouseUp );
            };

            // eslint-disable-next-line no-var
            var onMouseMove = function( e ) {
                if ( ! isDragging ) {
return;
}
                const delta = startX - e.clientX;
                const newWidth = startWidth + delta;
                self.resizeSidebar( newWidth );
            };

            // eslint-disable-next-line no-var
            var onMouseUp = function() {
                if ( ! isDragging ) {
return;
}

                isDragging = false;
                resizeHandle.classList.remove( 'dragging' );
                document.body.classList.remove( 'zip-ai-resizing' );

                document.removeEventListener( 'mousemove', onMouseMove );
                document.removeEventListener( 'mouseup', onMouseUp );

                const container = document.getElementById( 'zip-ai-assistant-container' );
                if ( container ) {
                    self.saveSidebarWidth( container.offsetWidth );
                }
            };

            resizeHandle.addEventListener( 'mousedown', onMouseDown );

            resizeHandle.addEventListener( 'touchstart', function( e ) {
                const touch = e.touches[ 0 ];
                onMouseDown( { clientX: touch.clientX, preventDefault() {
 e.preventDefault();
} } );
            } );

            document.addEventListener( 'touchmove', function( e ) {
                if ( ! isDragging ) {
return;
}
                const touch = e.touches[ 0 ];
                onMouseMove( { clientX: touch.clientX } );
            } );

            document.addEventListener( 'touchend', onMouseUp );
        }

        resizeSidebar( width ) {
            const minWidth = ( window.ZIPWP_LAYOUT && window.ZIPWP_LAYOUT.popover && window.ZIPWP_LAYOUT.popover.minWidth ) || 360;
            const maxWidth = Math.round( window.innerWidth * 0.8 );
            const clampedWidth = Math.min( maxWidth, Math.max( minWidth, width ) );

            document.documentElement.style.setProperty( '--zipwp-sidebar-width', clampedWidth + 'px' );

            const container = document.getElementById( 'zip-ai-assistant-container' );
            if ( container ) {
                container.style.width = clampedWidth + 'px';
            }
        }

        saveSidebarWidth( width ) {
            const minWidth = ( window.ZIPWP_LAYOUT && window.ZIPWP_LAYOUT.popover && window.ZIPWP_LAYOUT.popover.minWidth ) || 360;
            const maxWidth = Math.round( window.innerWidth * 0.8 );
            const clampedWidth = Math.min( maxWidth, Math.max( minWidth, width ) );

            localStorage.setItem( window.ZIPWP_LAYOUT.keys.sidebarWidth, clampedWidth.toString() );
            this.resizeSidebar( clampedWidth );
        }

        loadSavedSidebarWidth() {
            const savedWidth = localStorage.getItem( window.ZIPWP_LAYOUT.keys.sidebarWidth );
            if ( savedWidth ) {
                this.resizeSidebar( parseInt( savedWidth, 10 ) );
            }
        }

        // ── Generic Message Handler ───────────────────────────────────

        // eslint-disable-next-line no-unused-vars
        handleMessage( type, data ) {
            // Extensibility point for future message types
        }
    }

    // Initialize and expose globally
    const bridgeHost = new WPBridgeHost();
    window.zipwpMcpBridge = bridgeHost;
}() );
