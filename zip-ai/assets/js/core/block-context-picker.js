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
