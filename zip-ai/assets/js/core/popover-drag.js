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
