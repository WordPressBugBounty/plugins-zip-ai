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
			stageRail: 'zip-ai-stage-rail',
			stageTools: 'zip-ai-stage-tools',
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

		// Explicit stored choice wins everywhere; otherwise the docked sidebar.
		resolveLayout() {
			let stored = null;
			try {
				stored = localStorage.getItem( this.keys.layout );
			} catch ( e ) {}
			if ( this.isValidLayout( stored ) ) {
				return stored;
			}
			return this.layout.sidebar;
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
				localStorage.removeItem( this.keys.stageRail );
				localStorage.removeItem( this.keys.stageTools );
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
