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
