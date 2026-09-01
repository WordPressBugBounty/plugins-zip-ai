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
