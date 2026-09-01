/*!
 * ZipWP MCP - Combined JavaScript
 * Version: 0.0.10
 * Build: 2026-08-28 08:41:01
 */

/**
 * ZIPWP_LAYOUT — single source of truth for the assistant's panel layout and
 * appearance state: the localStorage keys, the layout/theme values + defaults,
 * the geometry constants, the context-aware default resolution, and the
 * "reset to defaults" routine.
 *
 * Loaded before wp-bridge-host / popover-drag (and the React app bundle) and
 * exposed as `window.ZIPWP_LAYOUT`, so the vanilla host scripts and the React
 * components all read ONE definition. No backward-compat shims — key strings
 * are the current ones on purpose so live prefs keep working.
 */
( function() {
	'use strict';

	const CONFIG = {
		// localStorage keys.
		keys: {
			layout: 'zipwp-panel-layout',
			open: 'zipwp-panel-open',
			popoverPosition: 'zipwp-popover-position',
			popoverSize: 'zipwp-popover-size',
			sidebarWidth: 'zipwp-sidebar-width',
			fabPosition: 'zip-ai-fab-position',
			theme: 'zip-ai-theme',
		},
		// Panel layout values.
		layout: { sidebar: 'sidebar', popover: 'popover' },
		// Appearance values.
		theme: { light: 'light', dark: 'dark', default: 'light' },
		// Cross-tree broadcast fired by resetToDefaults().
		events: { reset: 'zip-ai-reset-layout' },
		// FAB launcher geometry.
		fab: { size: 56, margin: 8, dragThreshold: 4 },
		// Popover card geometry.
		popover: {
			defaultWidth: 420,
			defaultHeight: 620,
			minWidth: 400,
			minHeight: 400,
			maxWidthMargin: 40,
			maxHeightMargin: 60,
		},

		isValidLayout( value ) {
			return value === this.layout.sidebar || value === this.layout.popover;
		},

		// Block-editor screens dock the sidebar beside the canvas; every other
		// admin screen defaults to the floating popover. Uses the
		// PHP-authoritative is_block_editor() flag with a DOM fallback.
		isBlockEditorContext() {
			const cfg = ( typeof window !== 'undefined' && window.zipwpIframeConfig ) || {};
			if ( cfg.isBlockEditor ) {
				return true;
			}
			try {
				return document.body.classList.contains( 'block-editor-page' ) ||
					!! document.querySelector( '.block-editor-block-list__layout' );
			} catch ( e ) {
				return false;
			}
		},

		// Explicit stored choice wins everywhere; otherwise the context default.
		resolveLayout() {
			let stored = null;
			try {
				stored = localStorage.getItem( this.keys.layout );
			} catch ( e ) {}
			if ( this.isValidLayout( stored ) ) {
				return stored;
			}
			return this.isBlockEditorContext() ? this.layout.sidebar : this.layout.popover;
		},

		// Wipe every persisted layout + appearance preference. Broadcasts the
		// reset event so components in other React trees (FAB launcher, header
		// theme) snap back live without a reload. Callers re-resolve the layout
		// and re-apply classes themselves.
		resetToDefaults() {
			try {
				localStorage.removeItem( this.keys.layout );
				localStorage.removeItem( this.keys.popoverPosition );
				localStorage.removeItem( this.keys.popoverSize );
				localStorage.removeItem( this.keys.sidebarWidth );
				localStorage.removeItem( this.keys.fabPosition );
				localStorage.removeItem( this.keys.theme );
			} catch ( e ) {}
			try {
				window.dispatchEvent( new CustomEvent( this.events.reset ) );
			} catch ( e ) {}
		},
	};

	if ( typeof window !== 'undefined' ) {
		window.ZIPWP_LAYOUT = CONFIG;
	}
}() );


/**
 * Tool Hooks Registry
 *
 * Minimal tool hooks system for registering and executing JavaScript handlers
 * for MCP tools. This is used by the WordPress bridge host to execute js_hook tools.
 */

( function() {
    'use strict';

    class ToolHooksRegistry {
        constructor() {
            this.hooks = new Map();
            this.listeners = new Map();
        }

        /**
         * Register a JavaScript handler for a tool
         *
         * @param {string}   toolName - The tool name (e.g., 'myplugin/my-action')
         * @param {Function} handler  - The handler function
         * @param {Object}   options  - Additional options
         * @return {boolean} True on success
         */
        registerHandler( toolName, handler, options = {} ) {
            if ( ! toolName || typeof handler !== 'function' ) {
                return false;
            }

            const config = {
                handler,
                previewMode: options.previewMode || 'client',
                priority: options.priority || 10,
                ...options,
            };

            this.hooks.set( toolName, config );

            return true;
        }

        /**
         * Check if a tool has a registered handler
         *
         * @param {string} toolName - The tool name
         * @return {boolean} True if handler exists
         */
        hasHandler( toolName ) {
            return this.hooks.has( toolName );
        }

        /**
         * Execute a tool using its registered JavaScript handler
         *
         * @param {string} toolName - The tool name
         * @param {Object} args     - Tool arguments
         * @param {Object} context  - Execution context
         * @return {Promise<Object>} Execution result
         */
        async executeToolHook( toolName, args, context = {} ) {
            const config = this.hooks.get( toolName );

            if ( ! config ) {
                return {
                    success: false,
                    error: `No JavaScript handler registered for tool: ${ toolName }`,
                };
            }

            try {
                // Emit pre-execution event
                this.emitEvent( 'beforeToolExecution', { toolName, args, context } );

                // Execute the handler
                const result = await config.handler( args, context );

                // Ensure result has proper structure — pass through operation fields.
                // The outer `message` reflects actual status so callers don't read
                // "executed successfully" when the inner operation failed.
                const operationSucceeded = result?.success !== false;
                const defaultMessage = operationSucceeded
                    ? `Tool ${ toolName } executed successfully`
                    : ( result?.error || `Tool ${ toolName } failed` );
                const formattedResult = {
                    success: operationSucceeded,
                    data: result?.data || result,
                    message: result?.message || defaultMessage,
                    user_summary: result?.user_summary || null,
                    operation: result?.operation || null,
                    verification: result?.verification || result?.data?.verification || null,
                    error: result?.error || null,
                    toolName,
                    executionMode: 'js_hook',
                };

                // Emit post-execution event
                this.emitEvent( 'afterToolExecution', { toolName, args, context, result: formattedResult } );

                return formattedResult;
            } catch ( error ) {
                const errorResult = {
                    success: false,
                    error: error.message || 'Tool execution failed',
                    toolName,
                    executionMode: 'js_hook',
                };

                // Emit error event
                this.emitEvent( 'toolExecutionError', { toolName, args, context, error } );

                return errorResult;
            }
        }

        /**
         * Emit an event to all registered listeners
         *
         * @param {string} eventName - Event name
         * @param {any}    data      - Event data
         */
        emitEvent( eventName, data ) {
            const callbacks = this.listeners.get( eventName ) || [];
            callbacks.forEach( ( callback ) => {
                try {
                    callback( data );
                } catch {
                    // Event listener error - continue with other listeners
                }
            } );
        }
    }

    // Create singleton instance
    const toolHooksRegistry = new ToolHooksRegistry();

    // Make available globally
    window.zipwpMcp = window.zipwpMcp || {};
    window.zipwpMcp.toolHooks = toolHooksRegistry;
    window.zipwpMcp.registerTool = function( toolName, handler, options = {} ) {
        return toolHooksRegistry.registerHandler( toolName, handler, options );
    };
    window.zipwpMcpHooks = toolHooksRegistry;
}() );


/**
 * ZipWP MCP - Spectra Shared Utilities
 *
 * Shared utility functions used across Spectra tool handlers and context providers.
 *
 * @package
 */

( function() {
    'use strict';

    /**
     * Text attributes to extract for lightweight serialization.
     */
    const TEXT_ATTRIBUTES = [
        'content', 'text', 'title', 'heading', 'description',
        'label', 'placeholder', 'caption', 'citation', 'value',
        'buttonText', 'linkText', 'question', 'answer',
    ];

    /**
     * Extract only text attributes from block attributes.
     * Truncates long text to save tokens.
     *
     * @param {Object} attributes - Block attributes
     * @return {Object} Object with only text attributes
     */
    function extractTextAttributes( attributes ) {
        if ( ! attributes ) {
return {};
}

        const texts = {};
        for ( const attr of TEXT_ATTRIBUTES ) {
            if ( typeof attributes[ attr ] === 'string' && attributes[ attr ].trim() ) {
                const text = attributes[ attr ].trim();
                texts[ attr ] = text.length > 200 ? text.substring( 0, 200 ) + '...' : text;
            }
        }
        return texts;
    }

    /**
     * Walk a block subtree DFS and return the first non-empty text attribute
     * encountered (the block itself first, then its descendants in document
     * order). Used to derive a user-recognisable label for a selected block
     * whose own attributes may be empty but whose children carry content —
     * e.g. a Spectra/Container that wraps the heading block of a hero.
     *
     * @param {Object} block
     * @return {string} Plain trimmed text, or '' when nothing found.
     */
    function findFirstText( block ) {
        if ( ! block ) {
return '';
}
        const attrs = block.attributes || {};
        for ( const key of TEXT_ATTRIBUTES ) {
            const v = attrs[ key ];
            if ( typeof v === 'string' && v.trim() ) {
                return v.replace( /<[^>]*>/g, '' ).trim();
            }
        }
        if ( Array.isArray( block.innerBlocks ) ) {
            for ( const child of block.innerBlocks ) {
                const nested = findFirstText( child );
                if ( nested ) {
return nested;
}
            }
        }
        return '';
    }

    /**
     * Serialize a block with only essential data (clientId, name, texts).
     * Lightweight version for AI operations - minimizes token usage.
     *
     * Also emits `primary_text`: a single user-recognisable label derived
     * from the block's own text attributes (or its subtree when the block
     * itself carries no text). Consumed by the React chat UI to make the
     * selected-block badge identifiable on pages with many same-type
     * containers. Capped at 120 chars.
     *
     * @param {Object} block - WordPress block object
     * @return {object|null} Lightweight serialized block or null
     */
    function serializeBlockLight( block ) {
        if ( ! block ) {
return null;
}

        const texts = extractTextAttributes( block.attributes );
        const result = {
            clientId: block.clientId,
            name: block.name,
            texts,
        };

        const primary = findFirstText( block );
        if ( primary ) {
            result.primary_text = primary.length > 120 ? primary.substring( 0, 117 ) + '…' : primary;
        }

        // The HTML tag the user gave this block — Spectra content stores it in
        // `tagName` (h1/h2/p/span), a container in `htmlTag` (section/div/header).
        // Surfaced so the selection chip can show the actual tag instead of the
        // raw block-type slug (falls back to the block name when absent).
        const attrs = block.attributes || {};
        const tag = typeof attrs.tagName === 'string' && attrs.tagName !== ''
            ? attrs.tagName
            : ( typeof attrs.htmlTag === 'string' && attrs.htmlTag !== '' ? attrs.htmlTag : '' );
        if ( tag ) {
            result.tag = tag;
        }

        if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
            result.innerBlocks = block.innerBlocks
                .map( ( innerBlock ) => serializeBlockLight( innerBlock ) )
                .filter( ( b ) => b !== null );
        }

        return result;
    }

    /**
     * Serialize a block for context/transmission.
     * Recursively includes innerBlocks and HTML representation.
     *
     * @param {Object} block - WordPress block object
     * @return {object|null} Serialized block or null if invalid
     */
    function serializeBlock( block ) {
        if ( ! block ) {
return null;
}

        // Use WordPress serializer to get HTML representation
        let blocksHtml = '';
        if ( window.wp?.blocks?.serialize ) {
            try {
                blocksHtml = wp.blocks.serialize( [ block ] );
            } catch ( e ) {
                console.warn( 'Failed to serialize block:', e ); // eslint-disable-line no-console -- intentional error surfacing
            }
        }

        return {
            clientId: block.clientId,
            name: block.name,
            blockName: block.name, // Also include as blockName for backend compatibility
            attributes: block.attributes,
            attrs: block.attributes, // Also include as attrs for backend compatibility
            innerBlocks: ( block.innerBlocks || [] ).map( ( innerBlock ) => serializeBlock( innerBlock ) ),
            blocks_html: blocksHtml, // Pre-serialized HTML for direct use
        };
    }

    /**
     * Screenshot utilities using Screen Capture API
     * Captures exactly what's rendered on screen with persistent stream (one-time permission)
     */

    /**
     * Get block DOM element by clientId.
     *
     * WordPress 6.3+ renders the editor canvas inside an internal iframe
     * (`iframe[name="editor-canvas"]`), so a direct `document.querySelector`
     * on the top document misses every block. Fall through to each
     * same-origin iframe's document before returning null.
     *
     * @param {string} clientId - Block client ID
     * @return {HTMLElement|null} Block element or null
     */
    function getBlockElement( clientId ) {
        if ( ! clientId ) {
return null;
}
        const selector = '[data-block="' + clientId + '"]';
        // Top document first (legacy / non-iframe editors).
        const direct = document.querySelector( selector );
        if ( direct ) {
return direct;
}
        // Then every iframe we can reach without a cross-origin error.
        const iframes = document.querySelectorAll( 'iframe' );
        for ( let i = 0; i < iframes.length; i++ ) {
            let doc = null;
            try {
 doc = iframes[ i ].contentDocument || ( iframes[ i ].contentWindow && iframes[ i ].contentWindow.document );
} catch ( err ) {
 doc = null;
}
            if ( ! doc ) {
continue;
}
            const nested = doc.querySelector( selector );
            if ( nested ) {
return nested;
}
        }
        return null;
    }

    /**
     * Inject the zip-ai-block-pulse keyframes + class into a document once.
     * Idempotent per-document. Must be called on the owner-document of the
     * element we'll animate — injecting into the top document does nothing
     * when the block lives inside Gutenberg's editor-canvas iframe.
     *
     * @param {Document} [doc] - Target document. Defaults to top document.
     */
    function ensurePulseStyles( doc ) {
        const target = doc || document;
        if ( ! target || ! target.head ) {
return;
}
        if ( target.getElementById( 'zip-ai-block-pulse-styles' ) ) {
return;
}
        const style = target.createElement( 'style' );
        style.id = 'zip-ai-block-pulse-styles';
        style.textContent =
            '@keyframes zip-ai-block-pulse {' +
                '0%, 100% { box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.9), 0 0 0 10px rgba(124, 58, 237, 0); }' +
                '50% { box-shadow: 0 0 0 3px rgba(124, 58, 237, 1), 0 0 0 14px rgba(124, 58, 237, 0.18); }' +
            '}' +
            '.zip-ai-block-pulse {' +
                'animation: zip-ai-block-pulse 0.9s ease-in-out 2;' +
                'border-radius: 4px;' +
                'outline: none !important;' +
            '}';
        target.head.appendChild( style );
    }

    /**
     * Scroll the block identified by clientId into view and play a brief
     * pulse highlight so the user can visually locate it. Used by the
     * selected-block badge in the chat UI.
     *
     * Silent no-op if the element isn't in the DOM (block may be in an
     * iframe we can't reach, or was just replaced — don't throw).
     *
     * @param {string}                                    clientId
     * @param {{ durationMs?: number, scroll?: boolean }} [options]
     * @return {boolean} true when an element was highlighted.
     */
    function highlightBlock( clientId, options ) {
        const opts = options || {};
        const el = getBlockElement( clientId );
        if ( ! el ) {
return false;
}
        // Inject the animation CSS into the element's owner document so it
        // works inside Gutenberg's editor-canvas iframe as well as on the
        // top document.
        ensurePulseStyles( el.ownerDocument );
        if ( opts.scroll !== false ) {
            try {
                el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
            } catch ( err ) {
                // Older browsers without smooth scroll — fall back to default.
                try {
 el.scrollIntoView();
} catch ( e ) { /* ignore */ }
            }
        }
        el.classList.remove( 'zip-ai-block-pulse' );
        // Force reflow so re-adding the class restarts the animation.
        // eslint-disable-next-line no-unused-expressions
        void el.offsetWidth;
        el.classList.add( 'zip-ai-block-pulse' );
        const duration = typeof opts.durationMs === 'number' ? opts.durationMs : 1800;
        window.setTimeout( () => {
            el.classList.remove( 'zip-ai-block-pulse' );
        }, duration );
        return true;
    }

    /**
     * Persistent screen capture stream (reused to avoid repeated permission prompts)
     */
    let screenCaptureStream = null;
    let screenCaptureVideo = null;
    let screenCapturePermissionDenied = false; // Remember if user cancelled/denied

    /**
     * Initialize or get existing screen capture stream
     * Permission is requested only once per session
     * If user cancels, won't ask again until page reload
     * @return {Promise<{stream: MediaStream, video: HTMLVideoElement}|null>}
     */
    async function getScreenCaptureStream() {
        // Don't ask again if user already denied/cancelled
        if ( screenCapturePermissionDenied ) {
            return null;
        }

        // Check if existing stream is still active
        if ( screenCaptureStream && screenCaptureVideo ) {
            const tracks = screenCaptureStream.getVideoTracks();
            if ( tracks.length > 0 && tracks[ 0 ].readyState === 'live' ) {
                return { stream: screenCaptureStream, video: screenCaptureVideo };
            }
            // Stream ended, clean up
            screenCaptureStream = null;
            screenCaptureVideo = null;
        }

        try {
            // Request new stream (prompts user once)
            screenCaptureStream = await navigator.mediaDevices.getDisplayMedia( {
                video: {
                    displaySurface: 'browser',
                    preferCurrentTab: true,
                },
                preferCurrentTab: true,
            } );

            // Create persistent video element
            screenCaptureVideo = document.createElement( 'video' );
            screenCaptureVideo.srcObject = screenCaptureStream;
            screenCaptureVideo.muted = true;
            await screenCaptureVideo.play();

            // Listen for stream end (user stops sharing)
            screenCaptureStream.getVideoTracks()[ 0 ].addEventListener( 'ended', () => {
                screenCaptureStream = null;
                screenCaptureVideo = null;
            } );

            return { stream: screenCaptureStream, video: screenCaptureVideo };
        } catch ( error ) {
            console.warn( 'Screen capture permission denied:', error.message ); // eslint-disable-line no-console -- intentional error surfacing
            // Remember that user denied/cancelled - don't ask again until page reload
            screenCapturePermissionDenied = true;
            return null;
        }
    }

    /**
     * Capture screenshot of an element using the persistent screen capture stream
     * @param {HTMLElement} element  - Element to capture
     * @param               clientId
     * @param {Object}      options  - Capture options
     * @return {Promise<{base64: string, width: number, height: number}|null>}
     */
    async function captureBlockScreenshot( clientId, options = {} ) {
        const { maxWidth = 1200, quality = 0.8 } = options;

        const element = getBlockElement( clientId );
        if ( ! element ) {
            console.warn( 'Block element not found for clientId:', clientId ); // eslint-disable-line no-console -- intentional error surfacing
            return null;
        }

        // Scroll element into view
        element.scrollIntoView( { behavior: 'instant', block: 'center' } );
        await new Promise( ( resolve ) => setTimeout( resolve, 150 ) );

        // Get or initialize screen capture stream
        const capture = await getScreenCaptureStream();
        if ( ! capture ) {
            return null;
        }

        const { video } = capture;

        try {
            // Get element position relative to viewport
            const rect = element.getBoundingClientRect();

            // Draw current video frame to canvas
            const fullCanvas = document.createElement( 'canvas' );
            fullCanvas.width = video.videoWidth;
            fullCanvas.height = video.videoHeight;
            const fullCtx = fullCanvas.getContext( '2d' );
            fullCtx.drawImage( video, 0, 0 );

            // Calculate scale factor (screen capture may be at different DPI)
            const scaleX = video.videoWidth / window.innerWidth;
            const scaleY = video.videoHeight / window.innerHeight;

            // Crop to element bounds
            const cropX = Math.max( 0, rect.left * scaleX );
            const cropY = Math.max( 0, rect.top * scaleY );
            const cropWidth = Math.min( rect.width * scaleX, fullCanvas.width - cropX );
            const cropHeight = Math.min( rect.height * scaleY, fullCanvas.height - cropY );

            // Create cropped canvas
            const croppedCanvas = document.createElement( 'canvas' );
            croppedCanvas.width = cropWidth;
            croppedCanvas.height = cropHeight;
            const croppedCtx = croppedCanvas.getContext( '2d' );
            croppedCtx.drawImage( fullCanvas, cropX, cropY, cropWidth, cropHeight, 0, 0, cropWidth, cropHeight );

            // Optimize if needed
            let finalCanvas = croppedCanvas;
            if ( croppedCanvas.width > maxWidth ) {
                finalCanvas = document.createElement( 'canvas' );
                const ratio = maxWidth / croppedCanvas.width;
                finalCanvas.width = maxWidth;
                finalCanvas.height = croppedCanvas.height * ratio;
                const ctx = finalCanvas.getContext( '2d' );
                ctx.drawImage( croppedCanvas, 0, 0, finalCanvas.width, finalCanvas.height );
            }

            return {
                base64: finalCanvas.toDataURL( 'image/jpeg', quality ),
                width: finalCanvas.width,
                height: finalCanvas.height,
            };
        } catch ( error ) {
            console.error( 'Screenshot capture failed:', error ); // eslint-disable-line no-console -- intentional error surfacing
            return null;
        }
    }

    // Export utilities to global scope for use by tool handlers and context providers
    window.zipwpMcpSpectraUtils = {
        serializeBlock,
        serializeBlockLight,
        extractTextAttributes,
        // Screenshot utilities
        getScreenCaptureStream,
        getBlockElement,
        highlightBlock,
        captureBlockScreenshot,
    };
}() );


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


/**
 * editor/get-styles + editor/set-styles — the GBS CSS store, symmetric with
 * editor/get-context (HTML) and editor/get-scripts (JS).
 *
 * READ (get-styles) has two scopes: 'page' (this page's payload + the SELECTED
 * block's ownership styleContext) and 'global' (the site-wide option). Pure reads.
 *
 * WRITE (set-styles) is GLOBAL-scope ONLY — the header/footer / site-wide CHROME
 * WP OPTION (read via GET /global-styles/user-css, written via the shared
 * /global-styles/sitewide merge route — IMMEDIATE + site-wide, not reversible by
 * discard). The former scope:'page' WRITE (an IMMEDIATE `/global-styles/save`
 * write to this post's `spectra_blocks_pro_gs_user_css` meta) was REMOVED: it
 * persisted a per-block style edit to the DB before the user Saved (no undo) and,
 * on a SHARED gs- class body, silently restyled every section carrying it. All
 * per-block styling is now a utility className via editor/apply-change (editor
 * state, committed on Save). scope:'page' here is rejected, defensively.
 *
 * The global write does READ → MERGE only the touched buckets → WRITE (never
 * full-replace, so importer chrome + user classes survive), then RENDERs the
 * merged payload via the SSOT GenCssRenderer (REST /global-styles/render) and
 * injects it into the canvas iframe for live paint.
 *
 * @package
 */
( function () {
    // eslint-disable-next-line no-unused-vars
    const META_KEY = 'spectra_blocks_pro_gs_user_css'; // page post-meta AND global option key
    const NS = '/spectra-blocks/v1/global-styles';

    // Editor-store access (select/dispatch core/editor + session meta) comes from
    // the ONE shared source (editor/shared/editor-shared-utils.js): window in the
    // browser, require() under jest — so the editor handlers can't drift.
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
    function editorSelect() {
 const u = sharedEditorUtils(); return u && u.editorSelect ? u.editorSelect() : null;
}
    function blockEditorSelect() {
 const u = sharedEditorUtils(); return u && u.blockEditorSelect ? u.blockEditorSelect() : null;
}
    // How many blocks on the page carry a gs- token (the reverse `class -> blocks`
    // edge). >1 means editing this class body restyles other sections too. Shared SSOT.
    function classUsage( token ) {
        const u = sharedEditorUtils();
        const besel = blockEditorSelect();
        if ( ! u || ! u.classDependents || ! besel ) {
return 1;
}
        const n = u.classDependents( besel, token, [] ).length;
        return n > 0 ? n : 1;
    }
    function apiFetch( opts ) {
        if ( ! ( window.wp && window.wp.apiFetch ) ) {
            return Promise.reject( new Error( 'wp.apiFetch unavailable' ) );
        }
        return window.wp.apiFetch( opts );
    }

    // GBS page-store logic (merge / iframe-inject / the read→merge→save→render→inject
    // persist) lives in ONE place — editor/shared/editor-shared-utils.js — so this
    // styling tool and editor/apply-change can't drift. These are thin forwarders,
    // the same pattern as editorSelect above.
    function mergePayload( existing, incoming ) {
        const u = sharedEditorUtils();
        return u && u.mergePayload ? u.mergePayload( existing, incoming ) : Object.assign( {}, existing, incoming );
    }
    function canvasDoc() {
        const u = sharedEditorUtils();
        return u && u.canvasDoc ? u.canvasDoc() : document;
    }
    function injectCss( elementId, css ) {
        const u = sharedEditorUtils();
        if ( u && u.injectCss ) {
            u.injectCss( elementId, css );
        }
    }

    function bucketsOf( payload ) {
        return Object.keys( payload || {} ).filter( function ( k ) {
 return k !== 'v';
} );
    }

    // ── STYLE CONTEXT (ownership) ──────────────────────────────────────────────
    // The OWNERSHIP MODEL: a visual property is set by ONE of three layers, in
    // descending CSS specificity — a block ATTRIBUTE, a GBS CLASS body, or the
    // block DEFAULT (DEVELOPER-INSTRUCTIONS §5.1). To change a property you edit
    // its current OWNER (update the existing class, don't stack a new one; clear a
    // block attr that pins it). This resolver answers "who owns each property" so
    // the agent never hand-resolves specificity. It does NOT compute the exact
    // frontend winner (the editor canvas inverts utility-vs-gsClass specificity,
    // and utilities are JIT-compiled, not in the GBS payload) — `effective` is the
    // rendered truth and the agent's verify-iterate loop corrects any mis-guess.
    // The property map is small and explicit ON PURPOSE: it is this tool's job.
    // `prop` is the kebab CSS property — it doubles as the GBS-body key (bodies are
    // kebab, e.g. `font-size`) and the getComputedStyle key. Only `attrs` (the
    // block-attribute path(s) for that property) is non-derivable.
    // NOTE: ownership matches the EXACT property name — the shorthands `padding` /
    // `margin` resolve against a rule's `padding` / `margin` declaration, not a
    // longhand-only rule (`padding-top`). This system's utilities + gs- bodies write
    // the shorthand, so that is the intended scope; widen this list if a longhand-
    // only owner ever needs to be surfaced.
    const STYLE_PROPS = [
        { prop: 'color', attrs: [ 'style.color.text' ] },
        { prop: 'background-color', attrs: [ 'style.color.background', 'background.color' ] },
        { prop: 'padding', attrs: [ 'style.spacing.padding' ] },
        { prop: 'margin', attrs: [ 'style.spacing.margin' ] },
        { prop: 'font-size', attrs: [ 'style.typography.fontSize' ] },
        { prop: 'font-weight', attrs: [ 'style.typography.fontWeight' ] },
        { prop: 'text-align', attrs: [ 'align' ] },
        { prop: 'max-width', attrs: [ 'maxWidth' ] },
        // Border / shadow / motion — className-utility-owned (the visual block
        // attributes for these are BANNED, so there is no block-attribute owner
        // path: `attrs` is empty and ownership resolves purely from the live
        // cascade). Tracked so a `border-2 border-red-500`, `rounded-lg`,
        // `shadow-md`, `animate-*`, or a transform utility applied via apply_change
        // has an `effective`/`owner` the agent can VERIFY — without these the
        // resolver was blind to them and the agent could never confirm the edit
        // (the "the border is applied, the bounce could not be confirmed" hedge).
        // Longhands (not the `border` shorthand): utilities author border-width /
        // border-color separately, and getComputedStyle returns longhands.
        { prop: 'border-color', attrs: [] },
        { prop: 'border-width', attrs: [] },
        { prop: 'border-radius', attrs: [] },
        { prop: 'box-shadow', attrs: [] },
        { prop: 'animation', attrs: [] },
        { prop: 'transform', attrs: [] },
    ];
    function deepGet( obj, path ) {
        let cur = obj;
        const parts = path.split( '.' );
        for ( let i = 0; i < parts.length; i++ ) {
            if ( cur === null || typeof cur !== 'object' ) {
return undefined;
}
            cur = cur[ parts[ i ] ];
        }
        return cur;
    }
    function gsTokensOf( block ) {
        const cn = ( block && block.attributes && typeof block.attributes.className === 'string' )
            ? block.attributes.className : '';
        return cn.trim().split( /\s+/ ).filter( function ( t ) {
 return t && t.indexOf( 'gs-' ) === 0;
} );
    }
    function computedForBlock( clientId ) {
        try {
            const doc = canvasDoc();
            const el = doc.querySelector( '[data-block="' + clientId + '"]' );
            if ( ! el ) {
return null;
}
            return ( doc.defaultView || window ).getComputedStyle( el );
        } catch ( e ) {
 return null;
}
    }
    // ── CSSOM CASCADE RESOLUTION ───────────────────────────────────────────────
    // The ROOT of ownership: `effective` is read from the rendered cascade, so
    // `owner` MUST be resolved from that SAME cascade — not an authored guess. The
    // authored join (attrs + GBS payload) is blind to JIT utility classes (they are
    // compiled to real CSS rules, never in the GBS payload) and to source-order
    // ties between two utilities — the exact gap behind "colour set but not
    // applied" (owner came back `default` while the pixel was clearly painted). We
    // read the real matched rules from the canvas stylesheets instead.

    // The live element for a block (the [data-block] node in the canvas), or null
    // when unmounted (off-screen / virtualized) — then we degrade to the authored
    // join below, flagged as best-effort.
    function elForBlock( doc, clientId ) {
        try {
            return doc && clientId ? doc.querySelector( '[data-block="' + clientId + '"]' ) : null;
        } catch ( e ) {
 return null;
}
    }
    function computedForEl( el, doc ) {
        try {
            return el ? ( doc.defaultView || window ).getComputedStyle( el ) : null;
        } catch ( e ) {
 return null;
}
    }

    // Approximate CSS specificity [ids, classes/attrs/pseudo-classes, types] for a
    // single (comma-free) selector. Our selectors are single classes (`.gs-x`,
    // `.text-primary-600`) → [0,1,0]; the ties they create are broken by SOURCE
    // ORDER, which is the real decider and is tracked separately.
    function specificityOf( sel ) {
        const s = String( sel );
        const ids = ( s.match( /#[\w-]+/g ) || [] ).length;
        const classesAttrsPc =
            ( s.match( /\.[\w-]+/g ) || [] ).length +
            ( s.match( /\[[^\]]*\]/g ) || [] ).length +
            ( s.match( /:(?!:)[\w-]+/g ) || [] ).length;
        const stripped = s
            .replace( /::?[\w-]+(\([^)]*\))?/g, ' ' )
            .replace( /[.#][\w-]+/g, ' ' )
            .replace( /\[[^\]]*\]/g, ' ' );
        const types = ( stripped.match( /[a-zA-Z][\w-]*/g ) || [] ).length;
        return [ ids, classesAttrsPc, types ];
    }
    function cmpSpec( a, b ) {
        for ( let i = 0; i < 3; i++ ) {
            if ( a[ i ] !== b[ i ] ) {
 return a[ i ] - b[ i ];
}
        }
        return 0;
    }
    // Does a conditional group rule (@media / @supports) currently apply? Permissive
    // on the way in — a group we can't evaluate is INCLUDED (better to surface a
    // possibly-inactive source than hide the active one), matching the live paint
    // which the model verifies against `effective` anyway.
    function groupRuleApplies( doc, rule ) {
        try {
            const win = doc.defaultView || window;
            if ( rule.type === 4 /* MEDIA */ ) {
                const mt = rule.media && rule.media.mediaText;
                return ! mt || ! win.matchMedia ? true : win.matchMedia( mt ).matches;
            }
            if ( rule.type === 12 /* SUPPORTS */ ) {
                const ct = rule.conditionText;
                return ! ct || ! ( win.CSS && win.CSS.supports ) ? true : win.CSS.supports( ct );
            }
        } catch ( e ) { /* permissive */ }
        return true;
    }

    // Every stylesheet declaration of `prop` whose selector MATCHES `el`, sorted in
    // ascending cascade priority (winner LAST): !important tier, then specificity,
    // then source order. Reads real CSSOM rules from the canvas — the true source
    // of the painted value.
    function matchedDeclarations( el, doc, prop ) {
        const out = [];
        let order = 0;
        function walk( rules ) {
            for ( let i = 0; i < rules.length; i++ ) {
                const r = rules[ i ];
                if ( r.type === 1 /* STYLE_RULE */ && r.selectorText && r.style ) {
                    order++;
                    const value = r.style.getPropertyValue( prop );
                    if ( value === '' || value === null || value === undefined ) {
 continue;
}
                    const important = r.style.getPropertyPriority( prop ) === 'important';
                    let best = null;
                    String( r.selectorText ).split( ',' ).forEach( function ( part ) {
                        const p = part.trim();
                        if ( ! p ) {
 return;
}
                        let matches = false;
                        try {
 matches = el.matches( p );
} catch ( e ) {
 matches = false;
}
                        if ( ! matches ) {
 return;
}
                        const spec = specificityOf( p );
                        if ( ! best || cmpSpec( spec, best.specificity ) > 0 ) {
                            best = { part: p, specificity: spec };
                        }
                    } );
                    if ( best ) {
                        out.push( { selectorPart: best.part, value: String( value ).trim(), important, specificity: best.specificity, order } );
                    }
                } else if ( ( r.type === 4 || r.type === 12 ) && r.cssRules && groupRuleApplies( doc, r ) ) {
                    walk( r.cssRules );
                }
            }
        }
        const sheets = ( doc && doc.styleSheets ) || [];
        for ( let s = 0; s < sheets.length; s++ ) {
            let rules;
            try {
 rules = sheets[ s ].cssRules || sheets[ s ].rules;
} catch ( e ) {
 continue;
} // cross-origin sheet
            if ( rules ) {
 walk( rules );
}
        }
        out.sort( function ( a, b ) {
            return ( a.important ? 1 : 0 ) - ( b.important ? 1 : 0 ) ||
                cmpSpec( a.specificity, b.specificity ) ||
                a.order - b.order;
        } );
        return out;
    }

    // Map a single-selector matched rule to an EDITABLE source ON this block: a
    // `.gs-*` class (edit its body via set_styles) or a utility class (replace it
    // in className via apply_change). A compound selector (`.parent .child`) isn't
    // a class the model owns here → surfaced as `selector` so it edits the real
    // owner (or an ancestor) rather than stacking.
    function classifySelectorPart( part, el ) {
        const sel = String( part ).trim();
        if ( ! el || ! el.classList ) {
            return { type: 'selector', selector: sel };
        }
        // Class tokens named anywhere in the selector (a `[class]` attribute selector
        // or `:root` scoping carries no dot, so it is ignored here). The GBS JIT
        // boosts per-block rules to `[class].gs-x.gs-x` (attribute + DOUBLED class)
        // to outrank utilities — the same class repeated is ONE owner, editable via
        // set_styles. We must see through that, and through `:root .gs-x` scoping.
        const classTokens = ( sel.match( /\.[\w-]+/g ) || [] ).map( function ( c ) {
 return c.slice( 1 );
} );
        if ( classTokens.length === 0 ) {
            return { type: 'selector', selector: sel };
        }
        // A SELF-target: every class the selector names is on THIS element (so it is
        // repetition / attribute-boosting / a structural `:root` scope, not a real
        // ancestor). A descendant selector like `.wrap .child` names an ancestor
        // class that ISN'T on el → it stays a contextual `selector:` the model can't
        // edit here. Exactly one DISTINCT on-el class keeps the owner unambiguous.
        const distinct = [];
        let allOnEl = true;
        classTokens.forEach( function ( c ) {
            if ( ! el.classList.contains( c ) ) {
                allOnEl = false;
            }
            if ( distinct.indexOf( c ) === -1 ) {
                distinct.push( c );
            }
        } );
        if ( ! allOnEl || distinct.length !== 1 ) {
            return { type: 'selector', selector: sel };
        }
        const cls = distinct[ 0 ];
        return cls.indexOf( 'gs-' ) === 0
            ? { type: 'gbs', class: cls }
            : { type: 'utility', class: cls };
    }
    function ownerLabel( src ) {
        if ( ! src ) {
 return 'default';
}
        if ( src.type === 'block_attribute' ) {
 return 'block_attribute:' + src.path;
}
        if ( src.type === 'gbs' ) {
 return 'gbs:' + src.class;
}
        if ( src.type === 'utility' ) {
 return 'utility:' + src.class;
}
        if ( src.type === 'selector' ) {
 return 'selector:' + src.selector;
}
        return 'default';
    }

    // Cascade-ordered editable sources for ONE property on a MOUNTED block, winner
    // first. Merges the authored inline attribute (Spectra renders style.* inline,
    // a normal declaration above class selectors) with the real matched rules, then
    // sorts by the CSS cascade: !important tier > inline > selectors, each broken by
    // specificity then source order.
    function resolveCascadeSources( def, attrs, el, doc, usageByToken ) {
        const candidates = [];
        def.attrs.forEach( function ( path ) {
            const v = deepGet( attrs, path );
            if ( v !== undefined && v !== null && v !== '' ) {
                // level 2 = normal inline; specificity above any class selector.
                candidates.push( { src: { type: 'block_attribute', path, value: v }, level: 2, specificity: [ 1, 0, 0 ], order: Number.MAX_SAFE_INTEGER } );
            }
        } );
        matchedDeclarations( el, doc, def.prop ).forEach( function ( d ) {
            const cls = classifySelectorPart( d.selectorPart, el );
            let src;
            if ( cls.type === 'gbs' ) {
                src = { type: 'gbs', class: cls.class, value: d.value };
                if ( usageByToken && usageByToken[ cls.class ] !== undefined ) {
                    src.usage = usageByToken[ cls.class ];
                }
            } else if ( cls.type === 'utility' ) {
                src = { type: 'utility', class: cls.class, value: d.value };
            } else {
                src = { type: 'selector', selector: cls.selector, value: d.value };
            }
            candidates.push( { src, level: d.important ? 3 : 1, specificity: d.specificity, order: d.order } );
        } );
        candidates.sort( function ( a, b ) {
            return a.level - b.level || cmpSpec( a.specificity, b.specificity ) || a.order - b.order;
        } );
        return candidates.reverse().map( function ( c ) {
 return c.src;
} ); // winner first
    }

    // Per-property { effective, owner, availableSources[] } for ONE block. When the
    // block is MOUNTED (the real case: the selected block is on-screen), ownership
    // is resolved from the live CSS cascade (CSSOM) so `owner` can never disagree
    // with the painted `effective`. When unmounted (off-screen / no canvas — e.g.
    // jsdom), we degrade to the authored join (attrs + GBS payload), which is
    // best-effort. `canvas` ({ doc, el }) is an injectable seam for tests.
    function buildStyleContext( block, pagePayload, usageByToken, canvas ) {
        if ( ! block ) {
return null;
}
        const classes = ( pagePayload && pagePayload.classes && typeof pagePayload.classes === 'object' )
            ? pagePayload.classes : {};
        const gsTokens = gsTokensOf( block );
        const attrs = block.attributes || {};
        const doc = ( canvas && canvas.doc ) || canvasDoc();
        const el = ( canvas && canvas.el !== undefined ) ? canvas.el : elForBlock( doc, block.clientId );
        const cs = el ? computedForEl( el, doc ) : computedForBlock( block.clientId );
        const properties = {};
        if ( el ) {
            // ROOT PATH — resolve ownership from the live cascade (CSSOM), so a
            // colour painted by a JIT utility (or the winner of two competing
            // utilities) is named correctly instead of collapsing to `default`.
            STYLE_PROPS.forEach( function ( def ) {
                const sources = resolveCascadeSources( def, attrs, el, doc, usageByToken );
                properties[ def.prop ] = {
                    effective: cs ? cs.getPropertyValue( def.prop ) : null,
                    owner: sources.length ? ownerLabel( sources[ 0 ] ) : 'default',
                    availableSources: sources,
                };
            } );
        } else {
            // FALLBACK — block not mounted (no canvas node): the authored join
            // (attrs + GBS payload) is the best we can do and is flagged best-effort
            // by the absence of a rendered `effective`. It cannot see utilities.
            STYLE_PROPS.forEach( function ( def ) {
                const sources = [];
                // block_attribute tier (highest specificity)
                def.attrs.forEach( function ( path ) {
                    const v = deepGet( attrs, path );
                    if ( v !== undefined && v !== null && v !== '' ) {
                        sources.push( { type: 'block_attribute', path, value: v } );
                    }
                } );
                // gbs class tier — only classes ACTUALLY on this block, only if they declare it.
                // `usage` = how many blocks carry this class: >1 means editing its body
                // restyles those other sections too (the reverse-dependency signal).
                gsTokens.forEach( function ( token ) {
                    const body = classes[ token ] && classes[ token ].default;
                    if ( ! body || typeof body !== 'object' ) {
return;
}
                    const v = body[ def.prop ];
                    if ( v !== undefined && v !== null && v !== '' ) {
                        sources.push( { type: 'gbs', class: token, value: v, usage: usageByToken ? usageByToken[ token ] : undefined } );
                    }
                } );
                const owner = sources.length === 0
                    ? 'default'
                    : ( sources[ 0 ].type === 'block_attribute'
                        ? 'block_attribute:' + sources[ 0 ].path
                        : 'gbs:' + sources[ 0 ].class );
                properties[ def.prop ] = {
                    effective: null,
                    owner,
                    availableSources: sources,
                };
            } );
        }
        // Classes on THIS block that also style other sections — edit their body
        // and every listed block moves with it. The model surfaces this to the user
        // (or restyles this block with utilities instead — see the doctrine).
        const sharedClasses = usageByToken
            ? gsTokens.filter( function ( t ) {
 return ( usageByToken[ t ] || 1 ) > 1;
} )
                .map( function ( t ) {
 return { class: t, used_by: usageByToken[ t ] };
} )
            : [];
        return {
            client_id: block.clientId,
            gbs_classes: gsTokens,
            properties,
            shared_gbs_classes: sharedClasses,
        };
    }

    // ── get-styles ───────────────────────────────────────────────────────────
    async function handleGetStyles( args ) {
        const scope = args && args.scope === 'global' ? 'global' : 'page';
        if ( scope === 'page' ) {
            const sel = editorSelect();
            const postId = sel && sel.getCurrentPostId ? sel.getCurrentPostId() : 0;
            if ( ! postId ) {
                return { success: false, error: 'editor_unavailable: no current post id (is the block editor open?)' };
            }
            try {
                const pres = await apiFetch( { path: NS + '/save?scope=page&post_id=' + postId } );
                const pp = ( pres && pres.payload && typeof pres.payload === 'object' ) ? pres.payload : {};
                const out = { scope: 'page', post_id: postId, buckets: bucketsOf( pp ), payload: pp };
                // STYLE CONTEXT (ownership) for a target block — the agent reads
                // this BEFORE a styling edit so it updates the existing owner
                // instead of guessing/stacking. Best-effort: a missing block /
                // unmounted node simply omits styleContext (never blocks the read).
                const clientId = args && typeof args.client_id === 'string' ? args.client_id : null;
                if ( clientId && sel && sel.getBlock ) {
                    const blk = sel.getBlock( clientId );
                    // Reverse-dependency counts for the block's gs- tokens, so the
                    // model sees "shared by N" BEFORE it picks an owner to edit.
                    const usageByToken = {};
                    ( blk && blk.attributes && typeof blk.attributes.className === 'string'
                        ? blk.attributes.className.trim().split( /\s+/ ) : [] )
                        .filter( function ( t ) {
 return t.indexOf( 'gs-' ) === 0;
} )
                        .forEach( function ( t ) {
 usageByToken[ t ] = classUsage( t );
} );
                    const ctx = buildStyleContext( blk, pp, usageByToken );
                    if ( ctx ) {
out.styleContext = ctx;
}
                }
                return { success: true, data: out };
            } catch ( e ) {
                return { success: false, error: 'page_read_failed: ' + String( e && e.message ? e.message : e ) };
            }
        }
        try {
            const res = await apiFetch( { path: NS + '/user-css' } );
            const gp = ( res && res.payload && typeof res.payload === 'object' ) ? res.payload : {};
            return { success: true, data: { scope: 'global', buckets: bucketsOf( gp ), payload: gp } };
        } catch ( e ) {
            return { success: false, error: 'global_read_failed: ' + String( e && e.message ? e.message : e ) };
        }
    }

    // ── set-styles ───────────────────────────────────────────────────────────
    // GLOBAL (chrome) scope ONLY. The former scope:'page' write — an IMMEDIATE,
    // irreversible REST write to this post's `spectra_blocks_pro_gs_user_css` meta
    // — was REMOVED: it persisted a per-block style edit to the DB even if the user
    // never Saved (no undo), and editing a SHARED gs- class body silently restyled
    // every section carrying it. All per-block styling now goes through
    // editor/apply-change utility classNames (editor state, committed on Save). This
    // handler still rejects scope:'page' defensively so a stale/forked brain bundle
    // can never resurrect the silent DB write.
    async function handleSetStyles( args ) {
        const scope = args && args.scope === 'global' ? 'global' : null;
        const incoming = args && args.payload;
        if ( args && args.scope === 'page' ) {
            return { success: false, error: 'unsupported_scope: per-page style writes were removed (they wrote the DB before Save and restyled shared classes). Change per-block styling with a utility className via apply_change instead. set_styles is header/footer chrome (scope:"global") only.' };
        }
        if ( scope === null ) {
            return { success: false, error: 'invalid_input: scope must be "global" (per-block styling uses apply_change utilities)' };
        }
        if ( ! incoming || typeof incoming !== 'object' || Array.isArray( incoming ) ) {
            return { success: false, error: 'invalid_input: payload must be a schema-v1 object of style buckets' };
        }
        if ( ! bucketsOf( incoming ).length ) {
            return { success: false, error: 'invalid_input: payload has no style buckets (classes / wrapperStyles / rootStyles / …)' };
        }

        // scope === 'global' — read-modify-write the option (immediate, site-wide).
        let existingGlobal;
        try {
            // eslint-disable-next-line no-redeclare, no-var
            var g = await apiFetch( { path: NS + '/user-css' } );
            existingGlobal = ( g && g.payload && typeof g.payload === 'object' ) ? g.payload : {};
        } catch ( e ) {
            return { success: false, error: 'global_read_failed: ' + String( e && e.message ? e.message : e ) };
        }
        const mergedGlobal = mergePayload( existingGlobal, incoming );
        try {
            // /sitewide replaces non-class buckets wholesale → send the FULL merged
            // payload so the write equals the merged state (chrome/user classes kept).
            await apiFetch( { path: NS + '/sitewide', method: 'POST', data: { payload: mergedGlobal } } );
        } catch ( e ) {
            return { success: false, error: 'global_write_failed: ' + String( e && e.message ? e.message : e ) };
        }
        try {
            const rg = await apiFetch( { path: NS + '/render', method: 'POST', data: { payload: mergedGlobal, post_id: 0, scope: 'global' } } );
            // Append-last override so the live global paint wins source-order ties
            // (deletions converge on reload, consistent with the live-JIT model).
            injectCss( 'zipwp-gbs-live-global', rg && rg.css );
        } catch ( e ) {
            return { success: false, error: 'render_failed: ' + String( e && e.message ? e.message : e ) };
        }
        return {
            success: true,
            data: {
                scope: 'global',
                buckets: bucketsOf( incoming ),
                note: 'Site-wide + IMMEDIATE: applied to every page now (not reversible by discarding the editor).',
            },
        };
    }

    function initHandler() {
        if ( window.zipwpMcp && window.zipwpMcp.registerTool ) {
            window.zipwpMcp.registerTool( 'editor/get-styles', async function ( args ) {
 return handleGetStyles( args );
}, { previewMode: 'client' } );
            window.zipwpMcp.registerTool( 'editor/set-styles', async function ( args ) {
 return handleSetStyles( args );
}, { previewMode: 'client' } );
        } else {
            setTimeout( initHandler, 100 );
        }
    }
    initHandler();

    // Test-only surface (Node/CommonJS) — inert in the browser bundle.
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = {
            handleGetStyles,
            handleSetStyles,
            mergePayload,
            // Ownership resolver (per-property effective/owner/availableSources).
            // Exported PURE so a unit test locks the join, not a reimplementation.
            buildStyleContext,
        };
    }
}() );


/**
 * Block Context Picker
 *
 * Adds a "+" pin button on block hover in Gutenberg editor.
 * Selected blocks are sent as context to the chat assistant.
 * Supports both classic and iframed block editor (WP 6.3+).
 *
 * @since x.x.x
 */

( function() {
    'use strict';

    /** SVG for the "+" (add) icon */
    const ICON_ADD = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';

    /** SVG for the checkmark (pinned) icon */
    const ICON_CHECK = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

    function BlockContextPicker() {
        /** @type {Map<string, Object>} clientId → serialized block data */
        this.contextBlocks = new Map();

        /** @type {HTMLElement|null} Single floating pin button */
        this.pinButton = null;

        /** @type {string|null} clientId of the block currently being hovered */
        this.hoveredClientId = null;

        /** @type {boolean} Whether the picker is currently active */
        this.active = false;

        /** @type {Function|null} wp.data.subscribe unsubscribe handle */
        this._unsubscribeBlockDeletion = null;

        /** @type {Document} The document where blocks live (parent or iframe) */
        this._editorDocument = document;

        /** @type {number} Last known block count for quick deletion check */
        this._lastBlockCount = 0;

        /** @type {Function|null} Bound handler references for cleanup */
        const self = this;
        this._onMouseOver = function( e ) {
 self._handleMouseOver( e );
};
        this._onMouseOut = function( e ) {
 self._handleMouseOut( e );
};
        this._onPinClick = function( e ) {
 self._handlePinClick( e );
};
    }

    /**
     * Enable the picker — called when sidebar opens.
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype.enable = function() {
        if ( this.active ) {
return;
}

        if ( ! window.wp || ! window.wp.data || ! window.wp.data.select( 'core/block-editor' ) ) {
            return;
        }

        this.active = true;
        this._resolveEditorDocument();
        this._createPinButton();
        this._bindEditorEvents();
        this._watchBlockDeletion();
    };

    /**
     * Disable the picker — called when sidebar closes.
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype.disable = function() {
        if ( ! this.active ) {
return;
}

        this.active = false;
        this._removePinButton();
        this._unbindEditorEvents();
        this._unwatchBlockDeletion();
        this.hoveredClientId = null;
    };

    /**
     * Clear all pinned context blocks.
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype.clear = function() {
        this.contextBlocks.clear();
        this.hoveredClientId = null;
        this._removeAllHighlights();
        this._updateBadge();
    };

    /**
     * Get all context blocks as an array for the chat context payload.
     *
     * @since x.x.x
     * @return {Array<Object>}
     */
    BlockContextPicker.prototype.getContextBlocks = function() {
        return Array.from( this.contextBlocks.values() );
    };

    /**
     * Check if any blocks are pinned.
     *
     * @since x.x.x
     * @return {boolean}
     */
    BlockContextPicker.prototype.hasContextBlocks = function() {
        return this.contextBlocks.size > 0;
    };

    // ── Editor document resolution (iframe support) ─────────────────

    /**
     * Detect whether the block editor uses an iframe canvas (WP 6.3+).
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype._resolveEditorDocument = function() {
        const editorIframe = document.querySelector( 'iframe[name="editor-canvas"]' );
        if ( editorIframe && editorIframe.contentDocument ) {
            this._editorDocument = editorIframe.contentDocument;
        } else {
            this._editorDocument = document;
        }
    };

    // ── DOM — pin button ────────────────────────────────────────────

    /**
     * Create the floating pin button element.
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype._createPinButton = function() {
        if ( this.pinButton ) {
return;
}

        const doc = this._editorDocument;
        const btn = doc.createElement( 'button' );
        btn.className = 'zipwp-context-pin';
        btn.type = 'button';
        btn.setAttribute( 'aria-label', 'Pin block to chat context' );
        btn.innerHTML = ICON_ADD;

        btn.addEventListener( 'click', this._onPinClick );
        doc.body.appendChild( btn );
        this.pinButton = btn;

        // Inject styles into iframe editor if needed
        if ( doc !== document ) {
            this._injectIframeStyles( doc );
        }
    };

    /**
     * Inject context picker CSS into the editor iframe document.
     *
     * @since x.x.x
     * @param {Document} doc
     */
    BlockContextPicker.prototype._injectIframeStyles = function( doc ) {
        if ( doc.getElementById( 'zipwp-context-picker-styles' ) ) {
return;
}

        const style = doc.createElement( 'style' );
        style.id = 'zipwp-context-picker-styles';
        style.textContent =
            '.zipwp-context-pin{display:none;position:absolute;z-index:100000;align-items:center;justify-content:center;width:28px;height:28px;padding:0;border:none;border-radius:6px;background:#6366f1;color:#fff;cursor:pointer;box-shadow:0 2px 8px rgba(99,102,241,.35);transition:background-color .15s ease,transform .15s ease,box-shadow .15s ease}' +
            '.zipwp-context-pin:hover{background:#4f46e5;transform:scale(1.1);box-shadow:0 4px 12px rgba(99,102,241,.45)}' +
            '.zipwp-context-pin svg{width:16px;height:16px;display:block;pointer-events:none}' +
            '.zipwp-context-pin--active{background:#16a34a}' +
            '.zipwp-context-pin--active:hover{background:#dc2626}' +
            '.zipwp-context-selected{box-shadow:0 0 0 2px #6366f1 !important;border-radius:2px}';
        doc.head.appendChild( style );
    };

    /**
     * Remove the pin button from DOM.
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype._removePinButton = function() {
        if ( this.pinButton ) {
            this.pinButton.removeEventListener( 'click', this._onPinClick );
            this.pinButton.remove();
            this.pinButton = null;
        }
    };

    /**
     * Position the pin button next to the hovered block.
     *
     * @since x.x.x
     * @param {HTMLElement} blockEl
     */
    BlockContextPicker.prototype._positionPinButton = function( blockEl ) {
        if ( ! this.pinButton ) {
return;
}

        const doc = this._editorDocument;
        const rect = blockEl.getBoundingClientRect();
        const scrollTop = doc.defaultView.scrollY || doc.documentElement.scrollTop;
        const scrollLeft = doc.defaultView.scrollX || doc.documentElement.scrollLeft;

        this.pinButton.style.top = ( rect.top + scrollTop + 4 ) + 'px';
        this.pinButton.style.left = ( rect.right + scrollLeft - 36 ) + 'px';
        this.pinButton.style.display = 'flex';

        // Update icon for pinned state
        const clientId = blockEl.dataset.block;
        if ( clientId && this.contextBlocks.has( clientId ) ) {
            this.pinButton.classList.add( 'zipwp-context-pin--active' );
            this.pinButton.innerHTML = ICON_CHECK;
            this.pinButton.setAttribute( 'aria-label', 'Unpin block from chat context' );
        } else {
            this.pinButton.classList.remove( 'zipwp-context-pin--active' );
            this.pinButton.innerHTML = ICON_ADD;
            this.pinButton.setAttribute( 'aria-label', 'Pin block to chat context' );
        }
    };

    /**
     * Hide the pin button.
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype._hidePinButton = function() {
        if ( this.pinButton ) {
            this.pinButton.style.display = 'none';
        }
    };

    // ── Events — hover delegation ───────────────────────────────────

    /**
     * Bind mouseover/mouseout on the editor canvas via delegation.
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype._bindEditorEvents = function() {
        const doc = this._editorDocument;
        doc.addEventListener( 'mouseover', this._onMouseOver, true );
        doc.addEventListener( 'mouseout', this._onMouseOut, true );
    };

    /**
     * Unbind editor hover events.
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype._unbindEditorEvents = function() {
        const doc = this._editorDocument;
        doc.removeEventListener( 'mouseover', this._onMouseOver, true );
        doc.removeEventListener( 'mouseout', this._onMouseOut, true );
    };

    /**
     * Handle mouseover — find the closest block wrapper.
     *
     * @since x.x.x
     * @param {MouseEvent} e
     */
    BlockContextPicker.prototype._handleMouseOver = function( e ) {
        if ( ! this.active ) {
return;
}

        if ( this.pinButton && this.pinButton.contains( e.target ) ) {
return;
}

        const blockEl = e.target.closest( '[data-block]' );
        if ( ! blockEl ) {
return;
}

        const clientId = blockEl.dataset.block;
        if ( ! clientId || clientId === this.hoveredClientId ) {
return;
}

        this.hoveredClientId = clientId;
        this._positionPinButton( blockEl );
    };

    /**
     * Handle mouseout — hide pin when cursor leaves all blocks.
     *
     * @since x.x.x
     * @param {MouseEvent} e
     */
    BlockContextPicker.prototype._handleMouseOut = function( e ) {
        if ( ! this.active ) {
return;
}

        if ( this.pinButton && this.pinButton.contains( e.relatedTarget ) ) {
return;
}

        const relatedBlock = e.relatedTarget && e.relatedTarget.closest
            ? e.relatedTarget.closest( '[data-block]' )
            : null;
        if ( relatedBlock ) {
return;
}

        this.hoveredClientId = null;
        this._hidePinButton();
    };

    /**
     * Handle click on the pin button — toggle block in context.
     *
     * @since x.x.x
     * @param {MouseEvent} e
     */
    BlockContextPicker.prototype._handlePinClick = function( e ) {
        e.preventDefault();
        e.stopPropagation();

        const clientId = this.hoveredClientId;
        if ( ! clientId ) {
return;
}

        if ( this.contextBlocks.has( clientId ) ) {
            // Unpin
            this.contextBlocks.delete( clientId );
            this._removeHighlight( clientId );
            if ( this.pinButton ) {
                this.pinButton.classList.remove( 'zipwp-context-pin--active' );
                this.pinButton.innerHTML = ICON_ADD;
                this.pinButton.setAttribute( 'aria-label', 'Pin block to chat context' );
            }
        } else {
            // Pin
            const blockData = this._serializeBlockForContext( clientId );
            if ( blockData ) {
                this.contextBlocks.set( clientId, blockData );
                this._addHighlight( clientId );
                if ( this.pinButton ) {
                    this.pinButton.classList.add( 'zipwp-context-pin--active' );
                    this.pinButton.innerHTML = ICON_CHECK;
                    this.pinButton.setAttribute( 'aria-label', 'Unpin block from chat context' );
                }
            }
        }

        this._updateBadge();
        this._notifyContextChanged();
    };

    // ── Bridge notification ─────────────────────────────────────────

    /**
     * Notify the React app that context blocks changed.
     * Emits event on the React DirectBridge so components can react.
     *
     * @since x.x.x
     */
    BlockContextPicker.prototype._notifyContextChanged = function() {
        const appBridge = window.zipwpMcpAppBridge;
        if ( appBridge && appBridge.emit ) {
            appBridge.emit( 'context_blocks_changed', {
                context_blocks: this.getContextBlocks(),
                count: this.contextBlocks.size,
            } );
        }
    };

    // ── Block serialization ─────────────────────────────────────────

    /**
     * Serialize a block for chat context.
     *
     * @since x.x.x
     * @param {string} clientId
     * @return {Object|null}
     */
    BlockContextPicker.prototype._serializeBlockForContext = function( clientId ) {
        if ( ! window.wp || ! window.wp.data || ! window.wp.blocks ) {
return null;
}

        const blockEditorSelect = window.wp.data.select( 'core/block-editor' );
        const block = blockEditorSelect.getBlock( clientId );
        if ( ! block ) {
return null;
}

        let blocksHtml = '';
        try {
            blocksHtml = window.wp.blocks.serialize( [ block ] );
            const bridge = window.zipwpMcpBridge;
            if ( bridge && bridge.fixUnicodeEscapes ) {
                blocksHtml = bridge.fixUnicodeEscapes( blocksHtml );
            }
        } catch ( e ) {
            console.warn( 'BlockContextPicker: Failed to serialize block', e ); // eslint-disable-line no-console -- intentional error surfacing
        }

        const rootClientId = blockEditorSelect.getBlockRootClientId( clientId );
        const blockIndex = blockEditorSelect.getBlockIndex( clientId );

        return {
            clientId: block.clientId,
            name: block.name,
            blocks_html: blocksHtml,
            block_index: blockIndex,
            root_client_id: rootClientId || null,
        };
    };

    // ── Visual feedback — block highlights ──────────────────────────

    /**
     * @param clientId
     * @since x.x.x
     */
    BlockContextPicker.prototype._addHighlight = function( clientId ) {
        const blockEl = this._editorDocument.querySelector( '[data-block="' + clientId + '"]' );
        if ( blockEl ) {
blockEl.classList.add( 'zipwp-context-selected' );
}
    };

    /**
     * @param clientId
     * @since x.x.x
     */
    BlockContextPicker.prototype._removeHighlight = function( clientId ) {
        const blockEl = this._editorDocument.querySelector( '[data-block="' + clientId + '"]' );
        if ( blockEl ) {
blockEl.classList.remove( 'zipwp-context-selected' );
}
    };

    /**
     * @since x.x.x
     */
    BlockContextPicker.prototype._removeAllHighlights = function() {
        const els = this._editorDocument.querySelectorAll( '.zipwp-context-selected' );
        for ( let i = 0; i < els.length; i++ ) {
            els[ i ].classList.remove( 'zipwp-context-selected' );
        }
    };

    // ── Badge on FAB trigger button ─────────────────────────────────

    /**
     * @since x.x.x
     */
    BlockContextPicker.prototype._updateBadge = function() {
        const trigger = document.getElementById( 'zip-ai-floating-trigger' );
        if ( ! trigger ) {
return;
}

        let badge = trigger.querySelector( '.zipwp-context-badge' );
        const count = this.contextBlocks.size;

        if ( count === 0 ) {
            if ( badge ) {
badge.remove();
}
            return;
        }

        if ( ! badge ) {
            badge = document.createElement( 'span' );
            badge.className = 'zipwp-context-badge';
            trigger.appendChild( badge );
        }

        badge.textContent = count;
    };

    // ── Block deletion watcher ──────────────────────────────────────

    /**
     * @since x.x.x
     */
    BlockContextPicker.prototype._watchBlockDeletion = function() {
        if ( ! window.wp || ! window.wp.data ) {
return;
}

        const self = this;
        const subscribe = window.wp.data.subscribe;
        const blockEditorSelect = window.wp.data.select( 'core/block-editor' );

        this._lastBlockCount = blockEditorSelect.getGlobalBlockCount
            ? blockEditorSelect.getGlobalBlockCount()
            : blockEditorSelect.getBlocks().length;

        this._unsubscribeBlockDeletion = subscribe( function() {
            if ( self.contextBlocks.size === 0 ) {
return;
}

            const currentCount = blockEditorSelect.getGlobalBlockCount
                ? blockEditorSelect.getGlobalBlockCount()
                : blockEditorSelect.getBlocks().length;

            if ( currentCount >= self._lastBlockCount ) {
                self._lastBlockCount = currentCount;
                return;
            }

            self._lastBlockCount = currentCount;

            const currentBlockIds = self._getAllBlockClientIds();
            let changed = false;

            // Use Array.from to avoid iterator issues in older environments
            const pinnedIds = Array.from( self.contextBlocks.keys() );
            for ( let i = 0; i < pinnedIds.length; i++ ) {
                if ( ! currentBlockIds.has( pinnedIds[ i ] ) ) {
                    self.contextBlocks.delete( pinnedIds[ i ] );
                    self._removeHighlight( pinnedIds[ i ] );
                    changed = true;
                }
            }

            if ( changed ) {
                self._updateBadge();
                self._notifyContextChanged();
            }
        } );
    };

    /**
     * @since x.x.x
     */
    BlockContextPicker.prototype._unwatchBlockDeletion = function() {
        if ( this._unsubscribeBlockDeletion ) {
            this._unsubscribeBlockDeletion();
            this._unsubscribeBlockDeletion = null;
        }
    };

    /**
     * Get a Set of all block clientIds in the editor.
     *
     * @since x.x.x
     * @return {Set<string>}
     */
    BlockContextPicker.prototype._getAllBlockClientIds = function() {
        const ids = new Set();
        if ( ! window.wp || ! window.wp.data ) {
return ids;
}

        const blockEditorSelect = window.wp.data.select( 'core/block-editor' );
        if ( ! blockEditorSelect ) {
return ids;
}

        const collectIds = function( blocks ) {
            for ( let i = 0; i < blocks.length; i++ ) {
                ids.add( blocks[ i ].clientId );
                if ( blocks[ i ].innerBlocks && blocks[ i ].innerBlocks.length > 0 ) {
                    collectIds( blocks[ i ].innerBlocks );
                }
            }
        };

        collectIds( blockEditorSelect.getBlocks() );
        return ids;
    };

    // Expose singleton on window
    window.zipwpMcpContextPicker = new BlockContextPicker();
}() );


/**
 * Popover Drag Manager
 *
 * Handles drag-to-reposition for the popover panel mode.
 * Position persists in localStorage so it stays where the user left it.
 *
 * Used by WPBridgeHost — not standalone.
 */

( function() {
    'use strict';

    // Keys + geometry come from the ZIPWP_LAYOUT SSOT (loaded first); the
    // fallbacks keep this resilient if that global is ever absent.
    const L = ( typeof window !== 'undefined' && window.ZIPWP_LAYOUT ) || {};
    const LKEYS = L.keys || {};
    const LPOP = L.popover || {};
    const STORAGE_KEY = LKEYS.popoverPosition || 'zipwp-popover-position';
    const SIZE_STORAGE_KEY = LKEYS.popoverSize || 'zipwp-popover-size';
    const CONTAINER_ID = 'zip-ai-assistant-container';
    const DRAG_HANDLE_SELECTOR = '[data-popover-drag]';
    const RESIZE_HANDLE_SELECTOR = '[data-popover-resize]';
    const INTERACTIVE_SELECTOR = 'button, input, textarea, a, [role="menuitem"], [data-radix-collection-item]';
    const DRAG_THRESHOLD = ( L.fab && L.fab.dragThreshold ) || 4; // px — ignore tiny accidental drags
    const MIN_WIDTH = LPOP.minWidth || 360;
    const MIN_HEIGHT = LPOP.minHeight || 400;
    const MAX_WIDTH_MARGIN = LPOP.maxWidthMargin || 40; // min margin from viewport edge
    const MAX_HEIGHT_MARGIN = LPOP.maxHeightMargin || 60;

    /**
     * Sets up drag listeners on the container.
     * Called once from WPBridgeHost.setup().
     * @param getBridgeLayout
     */
    function setupPopoverDrag( getBridgeLayout ) {
        const container = document.getElementById( CONTAINER_ID );
        if ( ! container ) {
return;
}

        injectResizeHandles( container );

        let isDragging = false;
        let didMove = false;
        let startX, startY, startLeft, startTop;
        let isResizing = false;
        let resizeEdges = null;
        let startWidth, startHeight;

        function onMouseMove( e ) {
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;

            // Ignore until threshold is met
            if ( ! didMove && Math.abs( dx ) + Math.abs( dy ) < DRAG_THRESHOLD ) {
return;
}
            didMove = true;

            let newLeft = startLeft + dx;
            let newTop = startTop + dy;

            // Clamp to viewport
            const cw = container.offsetWidth;
            const ch = container.offsetHeight;
            newLeft = Math.max( 0, Math.min( newLeft, window.innerWidth - cw ) );
            newTop = Math.max( 0, Math.min( newTop, window.innerHeight - ch ) );

            container.style.left = newLeft + 'px';
            container.style.top = newTop + 'px';
            container.style.right = 'auto';
            container.style.bottom = 'auto';
        }

        function onMouseUp() {
            if ( ! isDragging ) {
return;
}
            isDragging = false;
            document.body.style.userSelect = '';
            container.style.transition = '';
            document.removeEventListener( 'mousemove', onMouseMove );
            document.removeEventListener( 'mouseup', onMouseUp );
            // Persist position only if the user actually moved
            if ( didMove ) {
                savePosition();
            }
        }

        container.addEventListener( 'mousedown', function( e ) {
            if ( getBridgeLayout() !== 'popover' ) {
return;
}

            // Resize takes priority over drag
            const resizeHandle = e.target.closest( RESIZE_HANDLE_SELECTOR );
            if ( resizeHandle ) {
                isResizing = true;
                resizeEdges = resizeHandle.getAttribute( 'data-popover-resize' );
                const rect = container.getBoundingClientRect();
                startX = e.clientX;
                startY = e.clientY;
                startLeft = rect.left;
                startTop = rect.top;
                startWidth = rect.width;
                startHeight = rect.height;

                document.body.style.userSelect = 'none';
                container.style.transition = 'none';
                e.preventDefault();
                e.stopPropagation();

                document.addEventListener( 'mousemove', onResizeMove );
                document.addEventListener( 'mouseup', onResizeEnd );
                return;
            }

            // Only drag from header area
            const dragHandle = e.target.closest( DRAG_HANDLE_SELECTOR );
            if ( ! dragHandle ) {
return;
}
            // Don't drag when clicking interactive elements
            if ( e.target.closest( INTERACTIVE_SELECTOR ) ) {
return;
}

            isDragging = true;
            didMove = false;
            const dragRect = container.getBoundingClientRect();
            startX = e.clientX;
            startY = e.clientY;
            startLeft = dragRect.left;
            startTop = dragRect.top;

            document.body.style.userSelect = 'none';
            container.style.transition = 'none';
            e.preventDefault();

            document.addEventListener( 'mousemove', onMouseMove );
            document.addEventListener( 'mouseup', onMouseUp );
        } );

        function onResizeMove( e ) {
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            const maxW = window.innerWidth - MAX_WIDTH_MARGIN;
            const maxH = window.innerHeight - MAX_HEIGHT_MARGIN;
            let newWidth = startWidth;
            let newHeight = startHeight;
            let newLeft = startLeft;
            let newTop = startTop;

            if ( resizeEdges.indexOf( 'e' ) !== -1 ) {
                newWidth = clamp( startWidth + dx, MIN_WIDTH, maxW );
            }
            if ( resizeEdges.indexOf( 'w' ) !== -1 ) {
                newWidth = clamp( startWidth - dx, MIN_WIDTH, maxW );
                newLeft = startLeft + ( startWidth - newWidth );
            }
            if ( resizeEdges.indexOf( 's' ) !== -1 ) {
                newHeight = clamp( startHeight + dy, MIN_HEIGHT, maxH );
            }
            if ( resizeEdges.indexOf( 'n' ) !== -1 ) {
                newHeight = clamp( startHeight - dy, MIN_HEIGHT, maxH );
                newTop = startTop + ( startHeight - newHeight );
            }

            container.style.setProperty( '--zipwp-popover-w', newWidth + 'px' );
            container.style.setProperty( '--zipwp-popover-h', newHeight + 'px' );
            container.style.setProperty( '--zipwp-popover-max-h', 'none' );
            if ( resizeEdges.indexOf( 'w' ) !== -1 || resizeEdges.indexOf( 'n' ) !== -1 || container.style.left ) {
                container.style.left = newLeft + 'px';
                container.style.top = newTop + 'px';
                container.style.right = 'auto';
                container.style.bottom = 'auto';
            }
        }

        function onResizeEnd() {
            if ( ! isResizing ) {
return;
}
            isResizing = false;
            resizeEdges = null;
            document.body.style.userSelect = '';
            container.style.transition = '';
            document.removeEventListener( 'mousemove', onResizeMove );
            document.removeEventListener( 'mouseup', onResizeEnd );
            saveSize();
            if ( container.style.left ) {
savePosition();
}
        }
    }

    function clamp( v, lo, hi ) {
        return Math.max( lo, Math.min( hi, v ) );
    }

    /**
     * Inject resize handle DOM elements — 4 edges + 4 corners.
     * Only visible in popover mode (CSS-gated by body class).
     * @param container
     */
    function injectResizeHandles( container ) {
        if ( container.querySelector( RESIZE_HANDLE_SELECTOR ) ) {
return;
}
        const edges = [ 'n', 's', 'e', 'w', 'nw', 'ne', 'sw', 'se' ];
        edges.forEach( function( edge ) {
            const el = document.createElement( 'div' );
            el.setAttribute( 'data-popover-resize', edge );
            el.className = 'zipwp-popover-resize zipwp-popover-resize--' + edge;
            container.appendChild( el );
        } );
    }

    function saveSize() {
        const container = document.getElementById( CONTAINER_ID );
        if ( ! container ) {
return;
}
        try {
            localStorage.setItem( SIZE_STORAGE_KEY, JSON.stringify( {
                width: container.offsetWidth,
                height: container.offsetHeight,
            } ) );
        } catch ( e ) {}
    }

    function restoreSize() {
        const container = document.getElementById( CONTAINER_ID );
        if ( ! container ) {
return;
}
        try {
            const raw = localStorage.getItem( SIZE_STORAGE_KEY );
            if ( ! raw ) {
return;
}
            const size = JSON.parse( raw );
            if ( ! size || typeof size.width !== 'number' || typeof size.height !== 'number' ) {
return;
}
            const maxW = window.innerWidth - MAX_WIDTH_MARGIN;
            const maxH = window.innerHeight - MAX_HEIGHT_MARGIN;
            const w = clamp( size.width, MIN_WIDTH, maxW );
            const h = clamp( size.height, MIN_HEIGHT, maxH );
            container.style.setProperty( '--zipwp-popover-w', w + 'px' );
            container.style.setProperty( '--zipwp-popover-h', h + 'px' );
            container.style.setProperty( '--zipwp-popover-max-h', 'none' );
        } catch ( e ) {}
    }

    /**
     * Save current container position to localStorage.
     */
    function savePosition() {
        const container = document.getElementById( CONTAINER_ID );
        if ( ! container || ! container.style.left ) {
return;
}
        try {
            localStorage.setItem( STORAGE_KEY, JSON.stringify( {
                left: parseInt( container.style.left, 10 ),
                top: parseInt( container.style.top, 10 ),
                // Viewport + panel size at save time. Restore uses these to
                // re-anchor to the closer edge (so a right/bottom-docked panel
                // keeps its margin when the window is a different size next
                // load) AND to size the edge/clamp math off the panel's real
                // dimensions — at load time offsetWidth is briefly the wide
                // sidebar width before the popover width var applies.
                vw: window.innerWidth,
                vh: window.innerHeight,
                w: container.offsetWidth,
                h: container.offsetHeight,
            } ) );
        } catch ( e ) {}
    }

    /**
     * Restore saved position from localStorage, clamped to current viewport.
     */
    function restorePosition() {
        const container = document.getElementById( CONTAINER_ID );
        if ( ! container ) {
return;
}
        // Restore size first so clamping uses the restored dimensions
        restoreSize();
        try {
            const raw = localStorage.getItem( STORAGE_KEY );
            if ( ! raw ) {
return;
}
            const pos = JSON.parse( raw );
            if ( ! pos || typeof pos.left !== 'number' || typeof pos.top !== 'number' ) {
return;
}

            let left = pos.left;
            let top = pos.top;
            // Prefer the size saved with the position (avoids the load-time
            // wide-sidebar offsetWidth that would skew the edge decision/clamp).
            // Legacy records have no w/h — fall back to the post-restoreSize()
            // offsetWidth so a resized-then-dragged panel still clamps to its
            // real width, then to the default popover size.
            const cw = pos.w || container.offsetWidth || ( LPOP.defaultWidth || 420 );
            const ch = pos.h || container.offsetHeight || ( LPOP.defaultHeight || 620 );

            // Re-anchor to the closer edge if the viewport changed size since
            // save: a right-docked panel shifts with the right edge (keeps its
            // right margin); a left-docked one stays put. Same for top/bottom.
            if ( typeof pos.vw === 'number' && pos.vw > 0 ) {
                if ( pos.left + cw / 2 > pos.vw / 2 ) {
                    left += window.innerWidth - pos.vw;
                }
            }
            if ( typeof pos.vh === 'number' && pos.vh > 0 ) {
                if ( pos.top + ch / 2 > pos.vh / 2 ) {
                    top += window.innerHeight - pos.vh;
                }
            }

            // Clamp to viewport
            if ( left + cw > window.innerWidth ) {
left = window.innerWidth - cw;
}
            if ( top + ch > window.innerHeight ) {
top = window.innerHeight - ch;
}
            if ( left < 0 ) {
left = 0;
}
            if ( top < 0 ) {
top = 0;
}

            container.style.left = left + 'px';
            container.style.top = top + 'px';
            container.style.right = 'auto';
            container.style.bottom = 'auto';
        } catch ( e ) {}
    }

    /**
     * Clear saved position and inline styles from the container.
     */
    function clearPosition() {
        const container = document.getElementById( CONTAINER_ID );
        if ( ! container ) {
return;
}
        container.style.left = '';
        container.style.top = '';
        container.style.right = '';
        container.style.bottom = '';
        try {
 localStorage.removeItem( STORAGE_KEY );
} catch ( e ) {}
        // Note: size intentionally NOT cleared here — user's custom popover
        // size persists across mode switches. Only resetLayout() wipes size.
    }

    /**
     * Reset popover to default size and position.
     * Removes CSS vars + inline position styles + clears storage.
     */
    function resetLayout() {
        const container = document.getElementById( CONTAINER_ID );
        if ( ! container ) {
return;
}
        container.style.left = '';
        container.style.top = '';
        container.style.right = '';
        container.style.bottom = '';
        container.style.removeProperty( '--zipwp-popover-w' );
        container.style.removeProperty( '--zipwp-popover-h' );
        container.style.removeProperty( '--zipwp-popover-max-h' );
        try {
            localStorage.removeItem( STORAGE_KEY );
            localStorage.removeItem( SIZE_STORAGE_KEY );
        } catch ( e ) {}
    }

    // Expose for WPBridgeHost
    window.zipwpPopoverDrag = {
        setup: setupPopoverDrag,
        restorePosition,
        clearPosition,
        resetLayout,
    };
}() );


/**
 * ZipWP MCP — pure js_rpc dispatch-dedup decision (B-1 / P5).
 *
 * The bridge's executeTools loop owns the seen-map I/O (sessionStorage-backed,
 * FIFO-bounded). THIS module is the pure DECISION over a prior seen entry, so the
 * idempotency logic — the silent-until-it-bites duplicate-content path — is
 * unit-tested instead of living only inline in the browser IIFE.
 *
 * Decisions, given the prior _rpcSeen entry for a js_rpc call_id (or undefined):
 *   - a prior entry (IN-FLIGHT or COMPLETED) → repost_cached: NEVER re-dispatch
 *     (re-running the handler double-applies the mutation); re-POST the stored
 *     reply so a still-waiting brain BRPOP resolves.
 *   - no prior entry → run: the caller records IN_FLIGHT_MARKER BEFORE the handler
 *     mutates the tree, so a crash BETWEEN the mutation and the completion-record
 *     still leaves a marker. A replay then reposts an uncertain (ok:false) reply
 *     and the brain verifies with get_context before any retry — never a blind
 *     re-dispatch.
 *
 * Dual-mode: window.zipwpRpcDedup in the browser; CommonJS for jest.
 *
 * @package
 */
( function () {
    'use strict';

    // The reply a replay receives while the first run is mid-apply, or after a
    // crash before the completion-record. ok:false → the brain treats it as an
    // unconfirmed apply (verify-before-retry), never a blind re-apply.
    const IN_FLIGHT_MARKER = { ok: false, data: undefined, error: 'apply_pending_no_confirmation' };

    // A COMPLETED session-scoped write (apply_change / set_scripts — both mutate
    // block attributes in the OPEN editor session) cached on a PRIOR page load: the
    // page reloaded since, and Gutenberg DISCARDS unsaved session edits on reload.
    // The cached ok:true would then mask a LOST edit (the brain folds success while
    // the page reverted). Repost this uncertain reply so the brain re-verifies with
    // get_context instead — same "don't assume, verify" contract as IN_FLIGHT.
    const RELOAD_REVERIFY_MARKER = { ok: false, data: undefined, error: 'apply_unconfirmed_after_reload' };

    // `currentPageLoadId` is the token minted once per page load. A cached entry
    // tagged `mutating` (a session-scoped write) whose `pageLoadId` differs means a
    // reload happened between the apply and this replay → the edit is gone.
    function decideJsRpcDispatch( seenEntry, currentPageLoadId ) {
        if ( seenEntry ) {
            if ( seenEntry.mutating === true &&
                currentPageLoadId !== undefined && currentPageLoadId !== null &&
                seenEntry.pageLoadId !== undefined &&
                seenEntry.pageLoadId !== currentPageLoadId ) {
                return { action: 'repost_cached', reply: RELOAD_REVERIFY_MARKER };
            }
            return { action: 'repost_cached', reply: seenEntry };
        }
        return { action: 'run', inFlightMarker: IN_FLIGHT_MARKER };
    }

    if ( typeof window !== 'undefined' ) {
        window.zipwpRpcDedup = window.zipwpRpcDedup || {};
        window.zipwpRpcDedup.decideJsRpcDispatch = decideJsRpcDispatch;
        window.zipwpRpcDedup.IN_FLIGHT_MARKER = IN_FLIGHT_MARKER;
        window.zipwpRpcDedup.RELOAD_REVERIFY_MARKER = RELOAD_REVERIFY_MARKER;
    }
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = {
            decideJsRpcDispatch,
            IN_FLIGHT_MARKER,
            RELOAD_REVERIFY_MARKER,
        };
    }
}() );


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
