/*!
 * ZipWP MCP - Combined JavaScript
 * Version: 0.0.10
 * Build: 2026-08-28 08:41:01
 */

/**
 * Gutenberg Editor Plugin for ZipWP MCP iframe Assistant
 * Adds pinned toolbar button to trigger the iframe
 */

( function() {
    'use strict';

    const { registerPlugin } = wp.plugins;
    const { createElement: el, useEffect } = wp.element;
    const { __ } = wp.i18n;
    const { useDispatch, useSelect } = wp.data;

    // Import from correct package based on WP version
    const PluginSidebar = wp.editor?.PluginSidebar || wp.editPost?.PluginSidebar;

    // ZIP AI app icon — white sparkle on orange tile.
    // Matches src/components/shared/ZipBrand.jsx (ZIP_AI_ICON) / zip-ai-icon.svg.
    const ZIP_GLYPH = 'M10.6163 9.04474C14.6886 8.38907 21.5161 8.52189 22.6036 8.55646C22.6688 8.55139 22.7246 8.63189 22.6797 8.68634C22.4473 9.07806 21.6058 10.2989 19.3233 12.0047C19.2606 12.0435 19.2876 12.1771 19.3692 12.1707C20.1605 12.2111 22.8025 12.243 26.9971 11.7996C27.0778 11.7942 27.1306 11.857 27.1036 11.9266C26.8268 12.5935 25.3999 15.3714 20.4708 17.6902C20.3757 17.7318 20.4041 17.8824 20.502 17.8748L24.6436 17.8934C24.7262 17.9042 24.7826 18.0008 24.7217 18.0565C23.818 19.1117 18.3426 24.972 10.2803 23.118C7.10173 22.3795 4.9017 19.4422 4.88873 15.8934C4.87595 12.3444 7.01332 9.61306 10.6163 9.04474ZM12.6895 13.7557C12.612 13.5437 12.312 13.5437 12.2344 13.7557L12.003 14.3885C11.8213 14.8846 11.5337 15.3352 11.1602 15.7088C10.7865 16.0825 10.3352 16.3699 9.83892 16.5516L9.20611 16.784C8.99451 16.8617 8.99436 17.1615 9.20611 17.2391L9.83892 17.4705C10.3352 17.6522 10.7865 17.9396 11.1602 18.3133C11.5339 18.687 11.8213 19.1383 12.003 19.6346L12.2344 20.2664C12.312 20.4787 12.612 20.4787 12.6895 20.2664L12.921 19.6346C13.1026 19.1383 13.391 18.687 13.7647 18.3133C14.1383 17.9397 14.5889 17.6522 15.085 17.4705L15.7178 17.2391C15.9301 17.1615 15.93 16.8616 15.7178 16.784L15.085 16.5516C14.5889 16.3699 14.1383 16.0824 13.7647 15.7088C13.391 15.3351 13.1026 14.8847 12.921 14.3885L12.6895 13.7557ZM15.792 11.8924C15.7533 11.7827 15.6033 11.7827 15.5645 11.8924L15.4483 12.2195C15.3575 12.4763 15.2142 12.7098 15.0274 12.9031C14.8405 13.0965 14.6144 13.2456 14.3663 13.3397L14.0499 13.4588C13.9442 13.499 13.9442 13.6549 14.0499 13.6951L14.3663 13.8143C14.6144 13.9083 14.8405 14.0574 15.0274 14.2508C15.2142 14.4441 15.3575 14.6776 15.4483 14.9344L15.5645 15.2615C15.6033 15.3714 15.7533 15.3714 15.792 15.2615L15.9073 14.9344C15.9981 14.6776 16.1423 14.4442 16.3292 14.2508C16.516 14.0575 16.7412 13.9083 16.9893 13.8143L17.3057 13.6951C17.4119 13.655 17.4119 13.4989 17.3057 13.4588L16.9893 13.3397C16.7413 13.2456 16.516 13.0965 16.3292 12.9031C16.1423 12.7098 15.9981 12.4763 15.9073 12.2195L15.792 11.8924ZM26.9971 11.7996H26.9952L27.0118 11.7986C27.0069 11.7992 27.002 11.7991 26.9971 11.7996Z';
    const ChatBubbleIcon = () => el( 'svg', {
        width: 24,
        height: 24,
        viewBox: '0 0 32 32',
        fill: 'none',
        'aria-hidden': 'true',
        focusable: 'false',
    },
        el( 'rect', { width: 32, height: 32, rx: 8.88889, fill: '#FF580E' } ),
        el( 'path', { d: ZIP_GLYPH, fill: '#FFFFFF' } )
    );

    // Plugin component - uses PluginSidebar to get pinned button
    const ZipWPMCPPlugin = () => {
        // Call both dispatch hooks unconditionally so the hook order is stable
        // every render (rules-of-hooks), then prefer edit-post over editor.
        const editPostDispatch = useDispatch( 'core/edit-post' );
        const editorDispatch = useDispatch( 'core/editor' );
        const { closeGeneralSidebar } = editPostDispatch || editorDispatch || {};

        // Check if our sidebar is open
        const isOpen = useSelect( ( select ) => {
            const editorSelect = select( 'core/edit-post' ) || select( 'core/editor' );
            if ( editorSelect && editorSelect.getActiveGeneralSidebarName ) {
                return editorSelect.getActiveGeneralSidebarName() === 'zip-ai-iframe-assistant/zip-ai-iframe-panel';
            }
            return false;
        }, [] );

        // When sidebar opens, toggle the assistant panel and close the sidebar
        useEffect( () => {
            if ( isOpen ) {
                if ( window.zipwpMcpBridge ) {
                    window.zipwpMcpBridge.togglePanel();
                }

                // Close the sidebar immediately
                if ( closeGeneralSidebar ) {
                    closeGeneralSidebar();
                }
            }
        }, [ isOpen, closeGeneralSidebar ] );

        // Return a PluginSidebar with isPinnable to get the button in toolbar
        // The content is empty since we use iframe instead
        return el( PluginSidebar, {
            isPinnable: true,
            icon: ChatBubbleIcon(),
            name: 'zip-ai-iframe-panel',
            title: __( 'ZIP AI Assistant', 'zip-ai' ),
            className: 'zip-ai-iframe-sidebar',
        }, null ); // Empty content - we use iframe
    };

    // Register the plugin
    registerPlugin( 'zip-ai-iframe-assistant', {
        render: ZipWPMCPPlugin,
        icon: ChatBubbleIcon(),
    } );

    // (The per-block "Edit with AI" chat shortcut was removed — the "Ask ZIP AI"
    // quick-edit button is now the single block-toolbar ZIP AI entry point; it
    // lives in the sibling quickedit.js. Chat stays reachable via the pinned
    // ZIP AI Assistant sidebar button above.)
}() );


/**
 * ZIP AI Quick Edit — block-toolbar popover for fast, one-shot inline edits.
 *
 * SEPARATE from the chat agent loop: streams from /api/agent/inline-edit/*
 * (brain runs a single LLM call, no runTurn) and applies straight to the
 * block via setAttributes (native Gutenberg undo). Block-aware: each block
 * type exposes only the ops that fit it. UI mirrors the ZIP AI design spec.
 *
 * Self-contained IIFE — self-registers via addFilter('editor.BlockEdit', …)
 * and a load-time style inject; nothing calls into it. Loaded alongside
 * editor-plugin.js (prod: concatenated by Gruntfile's editor/**\/*.js glob;
 * dev: enqueued explicitly in react-manager.php). The per-block "Ask ZIP AI"
 * button is the single block-toolbar ZIP AI entry point; chat stays reachable
 * via the pinned ZIP AI Assistant sidebar button (editor-plugin.js).
 */

( function() {
    'use strict';

    const { createElement: el, useEffect, useState, useRef, useCallback, useMemo } = wp.element;
    const { __, sprintf } = wp.i18n;
    const { createHigherOrderComponent } = wp.compose;
    const { addFilter } = wp.hooks;
    const BlockControls = wp.blockEditor?.BlockControls || wp.editor?.BlockControls;
    const { ToolbarGroup, ToolbarButton, Dropdown } = wp.components;

    // ZIP AI app icon — white sparkle on orange tile.
    // Matches src/components/shared/ZipBrand.jsx (ZIP_AI_ICON) / zip-ai-icon.svg.
    // Local copy so this file has no runtime dependency on editor-plugin.js.
    const ZIP_GLYPH = 'M10.6163 9.04474C14.6886 8.38907 21.5161 8.52189 22.6036 8.55646C22.6688 8.55139 22.7246 8.63189 22.6797 8.68634C22.4473 9.07806 21.6058 10.2989 19.3233 12.0047C19.2606 12.0435 19.2876 12.1771 19.3692 12.1707C20.1605 12.2111 22.8025 12.243 26.9971 11.7996C27.0778 11.7942 27.1306 11.857 27.1036 11.9266C26.8268 12.5935 25.3999 15.3714 20.4708 17.6902C20.3757 17.7318 20.4041 17.8824 20.502 17.8748L24.6436 17.8934C24.7262 17.9042 24.7826 18.0008 24.7217 18.0565C23.818 19.1117 18.3426 24.972 10.2803 23.118C7.10173 22.3795 4.9017 19.4422 4.88873 15.8934C4.87595 12.3444 7.01332 9.61306 10.6163 9.04474ZM12.6895 13.7557C12.612 13.5437 12.312 13.5437 12.2344 13.7557L12.003 14.3885C11.8213 14.8846 11.5337 15.3352 11.1602 15.7088C10.7865 16.0825 10.3352 16.3699 9.83892 16.5516L9.20611 16.784C8.99451 16.8617 8.99436 17.1615 9.20611 17.2391L9.83892 17.4705C10.3352 17.6522 10.7865 17.9396 11.1602 18.3133C11.5339 18.687 11.8213 19.1383 12.003 19.6346L12.2344 20.2664C12.312 20.4787 12.612 20.4787 12.6895 20.2664L12.921 19.6346C13.1026 19.1383 13.391 18.687 13.7647 18.3133C14.1383 17.9397 14.5889 17.6522 15.085 17.4705L15.7178 17.2391C15.9301 17.1615 15.93 16.8616 15.7178 16.784L15.085 16.5516C14.5889 16.3699 14.1383 16.0824 13.7647 15.7088C13.391 15.3351 13.1026 14.8847 12.921 14.3885L12.6895 13.7557ZM15.792 11.8924C15.7533 11.7827 15.6033 11.7827 15.5645 11.8924L15.4483 12.2195C15.3575 12.4763 15.2142 12.7098 15.0274 12.9031C14.8405 13.0965 14.6144 13.2456 14.3663 13.3397L14.0499 13.4588C13.9442 13.499 13.9442 13.6549 14.0499 13.6951L14.3663 13.8143C14.6144 13.9083 14.8405 14.0574 15.0274 14.2508C15.2142 14.4441 15.3575 14.6776 15.4483 14.9344L15.5645 15.2615C15.6033 15.3714 15.7533 15.3714 15.792 15.2615L15.9073 14.9344C15.9981 14.6776 16.1423 14.4442 16.3292 14.2508C16.516 14.0575 16.7412 13.9083 16.9893 13.8143L17.3057 13.6951C17.4119 13.655 17.4119 13.4989 17.3057 13.4588L16.9893 13.3397C16.7413 13.2456 16.516 13.0965 16.3292 12.9031C16.1423 12.7098 15.9981 12.4763 15.9073 12.2195L15.792 11.8924ZM26.9971 11.7996H26.9952L27.0118 11.7986C27.0069 11.7992 27.002 11.7991 26.9971 11.7996Z';
    const ChatBubbleIcon = () => el( 'svg', {
        width: 24,
        height: 24,
        viewBox: '0 0 32 32',
        fill: 'none',
        'aria-hidden': 'true',
        focusable: 'false',
    },
        el( 'rect', { width: 32, height: 32, rx: 8.88889, fill: '#FF580E' } ),
        el( 'path', { d: ZIP_GLYPH, fill: '#FFFFFF' } )
    );

    const ic = ( children, vb ) => el( 'svg', {
        width: 16, height: 16, viewBox: vb || '0 0 24 24', fill: 'none',
        stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round',
        'aria-hidden': 'true',
    }, children );

    // ZIP AI brand mark — the quick-edit button is "Ask ZIP AI", not a generic sparkle.
    const EraMark = () => ChatBubbleIcon();

    // Animated loader — the "Snake border" variant from the ZIP AI Loaders spec: a
    // static ink mark with a comet segment gliding around its border (conic
    // gradient swept via the animated --eqe-a angle) over a faint base track.
    // Replaces the generic wp Spinner while a quick edit streams. Honors
    // prefers-reduced-motion (CSS).
    const EraLoader = () => el( 'span', { className: 'eqe-loader' },
        el( 'span', { className: 'eqe-snake-track', 'aria-hidden': 'true' } ),
        el( 'span', { className: 'eqe-snake-ring', 'aria-hidden': 'true' } ),
        el( 'svg', { className: 'eqe-snake-glyph', width: 14, height: 14, viewBox: '0 0 32 32', fill: 'none', 'aria-hidden': 'true' },
            el( 'path', { d: ZIP_GLYPH, fill: '#FFFFFF' } )
        )
    );

    // Ink spinner ring — replaces the generic blue wp Spinner so every loading
    // state stays in the ZIP AI ink palette. `on-dark` variant for dark (ink-fill)
    // buttons where a light ring reads better.
    const spinner = ( mod ) => el( 'span', { className: 'eqe-spin' + ( mod ? ' ' + mod : '' ), 'aria-hidden': 'true' } );

    // Lucide icon path builder (MIT). p(d) → keyed <path>.
    const p = ( d, k ) => el( 'path', { key: k || d.slice( 0, 6 ), d } );

    // Per-intent menu icon (Lucide) + label.
    const INTENT_META = {
        rewrite: { label: 'Rewrite', icon: () => ic( [ p( 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8', 'a' ), p( 'M3 3v5h5', 'b' ) ] ) }, // rotate-ccw
        improve: { label: 'Improve', icon: () => ic( [ p( 'm12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z', 'a' ), p( 'M5 3v4', 'b' ), p( 'M19 17v4', 'c' ), p( 'M3 5h4', 'd' ), p( 'M17 19h4', 'e' ) ] ) }, // sparkles
        shorten: { label: 'Shorten', icon: () => ic( [ p( 'M21 6H3', 'a' ), p( 'M15 12H3', 'b' ), p( 'M17 18H3', 'c' ) ] ) }, // align-left
        expand: { label: 'Expand', icon: () => ic( [ p( 'M3 6h18', 'a' ), p( 'M3 12h18', 'b' ), p( 'M3 18h18', 'c' ) ] ) }, // align-justify
        punchier: { label: 'Make punchier', icon: () => ic( p( 'M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z' ) ) }, // zap
        simplify: { label: 'Simplify', icon: () => ic( [ p( 'm21.64 3.64-1.28-1.28a1.2 1.2 0 0 0-1.72 0L2.36 18.64a1.2 1.2 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72', 'a' ), p( 'm14 7 3 3', 'b' ), p( 'M5 6v4', 'c' ), p( 'M7 8H3', 'd' ) ] ) }, // wand-sparkles
        professional: { label: 'Professional', icon: () => ic( [ p( 'M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16', 'a' ), el( 'rect', { key: 'r', width: 20, height: 14, x: 2, y: 6, rx: 2 } ) ] ) }, // briefcase
        friendly: { label: 'Friendly', icon: () => ic( [ el( 'circle', { key: 'c', cx: 12, cy: 12, r: 10 } ), p( 'M8 14s1.5 2 4 2 4-2 4-2', 'm' ), p( 'M9 9h.01', 'l' ), p( 'M15 9h.01', 'r' ) ] ) }, // smile
        grammar: { label: 'Grammar', icon: () => ic( p( 'M20 6 9 17l-5-5' ) ) }, // check
        humanize: { label: 'Humanize', icon: () => ic( [ p( 'M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2', 'a' ), el( 'circle', { key: 'c', cx: 12, cy: 7, r: 4 } ) ] ) }, // user
    };

    // Common edits shown up front; the rest fold behind "+ More tones & styles".
    // Both filter against caps.text.ops per block type.
    const PRIMARY_INTENTS = [ 'rewrite', 'improve', 'shorten', 'expand', 'grammar' ];

    const MORE_INTENTS = [ 'simplify', 'professional', 'friendly', 'humanize', 'punchier' ];
    const TEXT_FULL = [ 'rewrite', 'improve', 'shorten', 'expand', 'punchier', 'simplify', 'professional', 'friendly', 'grammar', 'humanize' ];

    // Last-resort overrides: ONLY blocks whose editable text is a plain string
    // attribute that ISN'T named `text` and has no `source` — so neither
    // auto-rule in textFieldsFor() can find it. Everything else is discovered:
    //   • source: html/rich-text/text attrs → core/*, uagb/*, Kadence, most builders
    //   • a plain string attr named `text`   → Spectra's convention (content,
    //     button, all the loop/pagination buttons, countdown-label, form-button…)
    // Map value = list of extra field names on that block.
    const TEXT_MAP = {
        'spectra/counter': [ 'prefix' ],
        'spectra-pro/mega-menu': [ 'label', 'description', 'title' ],
        'uagb/buttons-child': [ 'label' ],
    };
    // Image-block apply overrides: blocks that store the image in PLAIN attrs
    // (CSS background / custom save, no <img> source) so it can't be auto-found.
    // { url, id, alt } = the attribute names the apply writes into.
    const IMAGE_MAP = {
        'core/cover': { url: 'url', id: 'id', alt: 'alt' },
        'uagb/image': { url: 'url', id: 'id', alt: 'alt' },
    };
    const IMG_DESC_CACHE = {};
    // Describe how to set an image on a block: { url, id, alt } attribute names,
    // or null if it isn't an image block. Auto-detects any block that renders an
    // <img> (an attr with source:attribute, attribute:'src', selector incl. img)
    // — which safely EXCLUDES video/audio/embed (their src selector isn't img) —
    // then falls back to IMAGE_MAP for plain-attr blocks (cover, uagb/image).
    function imageDescriptorFor( name ) {
        if ( name in IMG_DESC_CACHE ) {
return IMG_DESC_CACHE[ name ];
}
        let desc = IMAGE_MAP[ name ] || null;
        const type = wp.blocks && wp.blocks.getBlockType ? wp.blocks.getBlockType( name ) : null;
        if ( ! desc && type && type.attributes ) {
            const attrs = type.attributes;
            const imgSel = ( d ) => d && d.source === 'attribute' && typeof d.selector === 'string' && /\bimg\b/.test( d.selector );
            let urlAttr = null,
altAttr = null;
            Object.keys( attrs ).forEach( ( k ) => {
                if ( imgSel( attrs[ k ] ) && attrs[ k ].attribute === 'src' && ! urlAttr ) {
urlAttr = k;
}
                if ( imgSel( attrs[ k ] ) && attrs[ k ].attribute === 'alt' && ! altAttr ) {
altAttr = k;
}
            } );
            if ( urlAttr ) {
                const numId = ( k ) => attrs[ k ] && attrs[ k ].type === 'number';
                const idAttr = numId( 'id' ) ? 'id' : numId( 'mediaId' ) ? 'mediaId'
                    : Object.keys( attrs ).find( ( k ) => numId( k ) && /id$/i.test( k ) ) || 'id';
                desc = { url: urlAttr, id: idAttr, alt: altAttr || 'alt' };
            }
        }
        if ( type ) {
IMG_DESC_CACHE[ name ] = desc;
} // cache once the type has registered
        return desc;
    }

    const RATIOS = [
        { id: '1024x1024', label: '1:1', w: 20, h: 20 },
        { id: '1536x1024', label: '16:9', w: 26, h: 16 },
        { id: '1024x1536', label: '4:5', w: 16, h: 20 },
    ];
    // Camera mark — the image-generation action reads as "photo", not a sparkle.
    const cameraIcon = () => ic( [
        p( 'M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z', 'a' ),
        el( 'circle', { key: 'c', cx: 12, cy: 13, r: 3 } ),
    ] );
    const searchIcon = () => ic( el( 'path', { d: 'M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.3-4.3' } ) );
    const checkIcon = () => ic( el( 'path', { d: 'M20 6L9 17l-5-5' } ) );

    // Style presets — gradient-thumbnail cards (per the ZIP AI image-tool spec).
    const STYLE_PRESETS = [
        { id: 'Photographic', label: 'Photographic', grad: 'linear-gradient(135deg,#8FAE91,#5F726B)', icon: () => ic( [
            el( 'circle', { key: 'c', cx: 12, cy: 12, r: 10 } ),
            p( 'M14.31 8 20.05 17.94', 'a' ), p( 'M9.69 8 21.17 8', 'b' ), p( 'M7.38 12 13.12 2.06', 'c' ),
            p( 'M9.69 16 3.95 6.06', 'd' ), p( 'M14.31 16 2.83 16', 'e' ), p( 'M16.62 12 10.88 21.94', 'f' ) ] ) },
        { id: 'Illustration', label: 'Illustration', grad: 'linear-gradient(135deg,#E7C98C,#CFA257)', icon: () => ic( [
            p( 'M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.3a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z', 'a' ),
            el( 'circle', { key: 'c1', cx: 13.5, cy: 6.5, r: 1, fill: 'currentColor', stroke: 'none' } ),
            el( 'circle', { key: 'c2', cx: 17.5, cy: 10.5, r: 1, fill: 'currentColor', stroke: 'none' } ),
            el( 'circle', { key: 'c3', cx: 6.5, cy: 12.5, r: 1, fill: 'currentColor', stroke: 'none' } ),
            el( 'circle', { key: 'c4', cx: 8.5, cy: 7.5, r: 1, fill: 'currentColor', stroke: 'none' } ) ] ) },
        { id: '3D render', label: '3D render', grad: 'linear-gradient(135deg,#B6A6E6,#8E72D2)', icon: () => ic( [
            p( 'M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z', 'a' ),
            p( 'm3.3 7 8.7 5 8.7-5', 'b' ), p( 'M12 22V12', 'c' ) ] ) },
        { id: 'Minimal', label: 'Minimal', grad: 'linear-gradient(135deg,#E6E7EA,#C2C5CA)', icon: () => ic( el( 'circle', { cx: 12, cy: 12, r: 9 } ) ) },
        { id: 'Vibrant', label: 'Vibrant', grad: 'linear-gradient(135deg,#F09B91,#DD5F77)', icon: () => ic( [
            el( 'circle', { key: 'c', cx: 12, cy: 12, r: 4 } ),
            p( 'M12 2v2', 'a' ), p( 'M12 20v2', 'b' ), p( 'm4.93 4.93 1.41 1.41', 'c' ), p( 'm17.66 17.66 1.41 1.41', 'd' ),
            p( 'M2 12h2', 'e' ), p( 'M20 12h2', 'f' ), p( 'm6.34 17.66-1.41 1.41', 'g' ), p( 'm19.07 4.93-1.41 1.41', 'h' ) ] ) },
    ];

    // Editable text fields for a block type, discovered generically. Three rules,
    // widest-first, so almost no block needs a hardcoded entry:
    //   1. attributes with source: html | rich-text | text → RichText prose
    //      (core/*, legacy uagb/* like info-box, Kadence, most third-party).
    //   2. a plain string attr literally named `text` → Spectra's editable-content
    //      convention (content, button, loop/pagination buttons, form-button…).
    //   3. TEXT_MAP → the few odd-named plain attrs rules 1–2 can't see.
    // Returns [{ field }], deduped. Memoized by block name.
    const TEXT_FIELDS_CACHE = {}; // by block name — schemas are static at runtime
    function textFieldsFor( name ) {
        if ( TEXT_FIELDS_CACHE[ name ] ) {
return TEXT_FIELDS_CACHE[ name ];
}
        const type = wp.blocks && wp.blocks.getBlockType ? wp.blocks.getBlockType( name ) : null;
        const seen = {};
        const fields = [];
        const add = ( f ) => {
 if ( f && ! seen[ f ] ) {
 seen[ f ] = true; fields.push( f );
}
};
        if ( type && type.attributes ) {
            const attrs = type.attributes;
            Object.keys( attrs ).forEach( ( key ) => {
                const def = attrs[ key ];
                if ( ! def ) {
return;
}
                const src = def.source;
                // (1) sourced RichText — core/*, uagb/*, Kadence, most builders.
                if ( src === 'html' || src === 'rich-text' || src === 'text' ) {
add( key );
} else if ( key === 'text' && ! src && def.type === 'string' ) {
                // (2) a plain string attr literally named `text` — Spectra's
                //     editable-content convention (no source). isProse() guards it.
add( key );
}
            } );
        }
        ( TEXT_MAP[ name ] || [] ).forEach( add ); // (3) odd-named plain-attr extras
        const out = fields.map( ( f ) => ( { field: f } ) );
        if ( type ) {
TEXT_FIELDS_CACHE[ name ] = out;
} // don't cache before the type registers
        return out;
    }
    // Guard against editing non-prose source:html attrs (icon SVG, raw markup):
    // keep only values that have visible word characters after stripping tags.
    function isProse( v ) {
        if ( typeof v !== 'string' ) {
return false;
}
        const plain = v.replace( /<[^>]*>/g, '' ).trim();
        return plain !== '' && /[\p{L}\p{N}]/u.test( plain );
    }

    // Visual state on a block's OWN editor node (Gutenberg renders
    // `[data-block="<clientId>"]`; no wrapper around BlockEdit — that breaks
    // blocks). 'pending' — shimmer · 'active' — glow ring + scrolled into view ·
    // 'done' — brief success flash · null — clear. Best-effort.
    // The Gutenberg canvas may render inside a same-origin iframe
    // (name="editor-canvas"); blocks live in ITS document, so a main-document
    // query misses them — which is why scroll-into-view never reached the block.
    // Look in the canvas iframe first, fall back to the main document (classic).
    function findBlockEl( clientId ) {
        const sel = '[data-block="' + clientId + '"]';
        const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
        const doc = frame && frame.contentDocument;
        return ( doc && doc.querySelector( sel ) ) || document.querySelector( sel );
    }
    function setBlockState( clientId, state ) {
        try {
            const n = findBlockEl( clientId );
            if ( ! n ) {
return;
}
            n.classList.toggle( 'eqe-skeleton-on', state === 'pending' );
            n.classList.toggle( 'eqe-block-active', state === 'active' );
            if ( state === 'active' ) {
n.scrollIntoView( { behavior: 'smooth', block: 'center' } );
}
            if ( state === 'done' ) {
 n.classList.add( 'eqe-block-done' ); window.setTimeout( function () {
 n.classList.remove( 'eqe-block-done' );
}, 900 );
}
            if ( state === null ) {
n.classList.remove( 'eqe-skeleton-on', 'eqe-block-active', 'eqe-block-done' );
}
        } catch ( e ) {}
    }

    // A "section" = any block with editable text descendants (detected via the
    // store, no hardcoded container list — so custom containers work too). We
    // show "Ask ZIP AI" on it and fan the chosen op out to each descendant text
    // block. A big section is split into batches (see partitionTargets) and
    // edited over sequential calls, so even a text-heavy container works.
    // MAX_SECTION_TARGETS caps the WHOLE section (runaway guard — a whole-page
    // selection stays per-block); MAX_BATCH_TARGETS + SECTION_COST_CEILING bound
    // each single call.
    const MAX_SECTION_TARGETS = 80; // total fan-out cap across all batches
    const MAX_BATCH_TARGETS = 40; // count cap per single call
    // Per-batch est-token ceiling. MUST equal the brain's EST_CEILING
    // (workers/brain/src/http/routes/inlineEdit.ts) — same value + same formula
    // means a batch the client builds is exactly one the server accepts (no drift).
    const SECTION_COST_CEILING = 24000;
    const BATCH_GAP_MS = 300; // pause between sequential batches to ease provider rate limits
    // Bin-pack targets (in document order) into batches that each fit ONE call:
    // ≤ SECTION_COST_CEILING est tokens AND ≤ MAX_BATCH_TARGETS blocks. The first
    // target is admitted unconditionally, so callers must pre-drop any single
    // target that alone exceeds the ceiling (run() filters them) — then every
    // target is guaranteed to fit its batch.
    // Estimate mirrors the brain's estOutputTokens (chars + block count).
    function partitionTargets( targets ) {
        const batches = [];
        let cur = [],
curChars = 0;
        targets.forEach( ( t ) => {
            const chars = curChars + t.text.length;
            const count = cur.length + 1;
            const est = Math.ceil( chars / 2 ) + count * 64 + 4096;
            if ( cur.length && ( est > SECTION_COST_CEILING || count > MAX_BATCH_TARGETS ) ) {
                batches.push( cur ); cur = []; curChars = 0;
            }
            cur.push( t ); curChars += t.text.length;
        } );
        if ( cur.length ) {
batches.push( cur );
}
        return batches;
    }
    // Collect editable text descendants of a container (fresh from the store):
    // [{ id, name, field, text }] for every non-empty text block under clientId.
    // Every editable text target in a block's scope: the block's OWN text fields
    // PLUS those of every descendant — so a leaf (1 field), a multi-field block
    // (uagb/info-box → prefix + title + desc), and a container (all descendants)
    // are one uniform list. `key` = clientId + field: a block can contribute
    // several fields, but the wire keys edits by id, so each field rides its own
    // composite id (the brain treats it as opaque and echoes it back).
    function collectTargets( clientId ) {
        const be = wp.data.select( 'core/block-editor' );
        if ( ! be || ! clientId ) {
return [];
}
        const out = [];
        const visit = ( id ) => {
            const name = be.getBlockName( id );
            const attrs = be.getBlockAttributes( id ) || {};
            textFieldsFor( name ).forEach( ( f ) => {
                const raw = attrs[ f.field ];
                // WP 6.5+ rich-text source attrs are RichTextData objects, not
                // strings — coerce here so the whole pipeline stays string-typed
                // (wire payload, unchanged-diff, revert).
                const v = ( raw && typeof raw === 'object' && typeof raw.toHTMLString === 'function' )
                    ? raw.toHTMLString() : raw;
                if ( typeof v === 'string' && isProse( v ) ) {
                    out.push( { id, key: id + '::' + f.field, name, field: f.field, text: v } );
                }
            } );
        };
        visit( clientId );
        if ( typeof be.getClientIdsOfDescendants === 'function' ) {
            be.getClientIdsOfDescendants( [ clientId ] ).forEach( visit );
        }
        return out;
    }

    // The current page's section headings, gathered with the SAME buildPageOutline
    // the vibe-editing turn uses (core/wp-bridge-host.js) so an inline edit stays
    // on-topic with the rest of the page — the brain folds the headings into the
    // prompt as reference-only context. buildPageOutline is pure (reads blocks,
    // mutates no bridge state); getEditorContext() is NOT — it mints a snapshot id
    // + bumps the selection revision, so it must never be called from here.
    // Returns null when the bridge is absent or the page has no headings; the edit
    // then runs without page context (the brain treats it as optional — no failure).
    function collectPageOutline() {
        try {
            const bridge = window.zipwpMcpBridge;
            if ( ! bridge || typeof bridge.buildPageOutline !== 'function' ) {
                return null;
            }
            const be = wp.data.select( 'core/block-editor' );
            const blocks = be && typeof be.getBlocks === 'function' ? be.getBlocks() : null;
            if ( ! Array.isArray( blocks ) || ! blocks.length ) {
                return null;
            }
            // The brain reads ONLY heading_excerpt, so ship just that — dropping the
            // seven structural fields buildPageOutline also emits (client_id,
            // block_name, flags…) that the inline-edit prompt would ignore — and
            // only rows that actually carry a heading.
            const rows = ( bridge.buildPageOutline( blocks ) || [] )
                .map( function ( r ) {
                    return r && typeof r.heading_excerpt === 'string' && r.heading_excerpt
                        ? { heading_excerpt: r.heading_excerpt }
                        : null;
                } )
                .filter( Boolean );
            return rows.length ? rows : null;
        } catch ( e ) {
            return null;
        }
    }

    function apiBase() {
        const cfg = window.ZIPAI_CONFIG || {};
        return { url: ( cfg.apiUrl || '/api' ).replace( /\/$/, '' ), token: cfg.token || '' };
    }

    // Stream an inline edit (one structured call, SSE) over /inline-edit/stream.
    // Sends blocks:[{id,block_type,text}] — a single block is a list of one. The
    // brain emits `edit {id,text}` events then `complete`; onEdit(id,text) fires
    // per event. Throws on error.
    //
    // Goes DIRECT to the brain (verifies the same Sanctum token and bills via
    // Laravel). The Laravel relay fallback was removed — brainUrl is required.
    async function streamEdit( payload, onEdit, signal ) {
        const { token } = apiBase();
        const base = ( window.ZIPAI_CONFIG || {} ).brainUrl;
        if ( ! base ) {
throw new Error( 'Inline edit unavailable. The import server URL is not configured.' );
}
        const endpoint = base.replace( /\/$/, '' ) + '/inline-edit/stream';
        const res = await fetch( endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', Authorization: 'Bearer ' + token },
            body: JSON.stringify( payload ),
            signal,
        } );
        if ( ! res.ok || ! res.body ) {
            // Read the curated `user_message` off the body before throwing. This
            // used to discard the body outright, so the one reject the user can
            // actually act on — a section too big to rewrite in one pass, whose
            // message says to edit its blocks individually — surfaced as the bare
            // "Inline edit failed (400)" fallback. `errText` prefers userMessage.
            let body = {};
            try {
 body = await res.json();
} catch ( e ) { /* non-JSON (proxy/HTML error page) — fall through to the status line */ }
            const err = new Error( 'Inline edit failed (' + res.status + ')' );
            if ( typeof body.user_message === 'string' && body.user_message ) {
                err.userMessage = body.user_message;
            }
            throw err;
        }
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let errored = null;
        for ( ;; ) {
            const { done, value } = await reader.read();
            if ( done ) {
break;
}
            buffer += decoder.decode( value, { stream: true } );
            let idx;
            while ( ( idx = buffer.indexOf( '\n\n' ) ) !== -1 ) {
                const blk = buffer.slice( 0, idx );
                buffer = buffer.slice( idx + 2 );
                let event = null;
                let data = null;
                blk.split( '\n' ).forEach( ( line ) => {
                    if ( line.indexOf( 'event:' ) === 0 ) {
event = line.slice( 6 ).trim();
} else if ( line.indexOf( 'data:' ) === 0 ) {
data = line.slice( 5 ).trim();
}
                } );
                if ( ! event || data === null ) {
continue;
}
                let parsed = {};
                try {
 parsed = JSON.parse( data );
} catch ( e ) {
 parsed = {};
}
                if ( event === 'edit' && typeof parsed.id === 'string' && typeof parsed.text === 'string' ) {
onEdit( parsed.id, parsed.text );
} else if ( event === 'error' ) {
errored = parsed.error || 'Inline edit failed';
}
            }
        }
        if ( errored ) {
throw new Error( errored );
}
    }

    // Never surface a raw caught-error `.message` to the user (it can carry
    // network/stack text). Prefer a curated `userMessage` the fetch helper
    // attached (the brain's `user_message` SSOT), else a safe fallback. Local
    // twin of src/services/errorCopy.js#userFacingError — the editor bundle is a
    // classic enqueued script and can't import the React app's module.
    function errText( e, fallback ) {
        return ( e && typeof e.userMessage === 'string' && e.userMessage ) ? e.userMessage : fallback;
    }

    // Generate an image (base64 only — preview before committing to media).
    // DIRECT to the brain; the Laravel relay was removed (brainUrl required).
    async function generateImage( prompt, size ) {
        const { token } = apiBase();
        const base = ( window.ZIPAI_CONFIG || {} ).brainUrl;
        if ( ! base ) {
            const err = new Error( 'Image generation unavailable: brainUrl not configured.' );
            err.userMessage = 'Image generation isn’t set up on this site yet.';
            throw err;
        }
        const endpoint = base.replace( /\/$/, '' ) + '/inline-edit/image';
        const res = await fetch( endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer ' + token },
            body: JSON.stringify( { prompt, size } ),
        } );
        const data = await res.json();
        // Surface the brain's curated copy (the AI-image plan-limit / "busy"
        // notice) via `user_message` (SSOT), with `message` as the back-compat
        // alias — `error` is a machine code like "plan_limit_exceeded".
        if ( ! res.ok || ! data.success || ! data.base64 ) {
            const err = new Error( data.error || 'image_generation_failed' );
            err.userMessage = data.user_message || data.message || 'Image generation failed';
            throw err;
        }
        return { dataUrl: 'data:' + ( data.mime_type || 'image/png' ) + ';base64,' + data.base64 };
    }

    // Stock-image search via the SaaS proxy (Pexels/Unsplash aggregator).
    async function searchStock( query ) {
        const { url, token } = apiBase();
        const res = await fetch( url + '/unsplash/search?query=' + encodeURIComponent( query ) + '&per_page=12', {
            headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
        } );
        const data = await res.json();
        return ( data && data.data && data.data.results ) ? data.data.results : [];
    }

    // Upload an image (blob) into the WP media library; returns { url, id }.
    async function uploadBlobToMedia( blob, filename ) {
        const form = new FormData();
        form.append( 'file', blob, filename || 'zip-ai-image.png' );
        const media = await wp.apiFetch( { path: '/wp/v2/media', method: 'POST', body: form } );
        return { url: media.source_url, id: media.id };
    }
    async function uploadFromDataUrl( dataUrl ) {
        const res = await fetch( dataUrl );
        return uploadBlobToMedia( await res.blob(), 'zip-ai-image.png' );
    }
    async function uploadFromUrl( srcUrl ) {
        const res = await fetch( srcUrl );
        if ( ! res.ok ) {
throw new Error( 'Could not fetch image' );
}
        return uploadBlobToMedia( await res.blob(), 'zip-ai-stock.jpg' );
    }

    // ── Text popover ──
    function TextPanel( props ) {
        const { caps, onClose, onRun } = props;
        const [ prompt, setPrompt ] = useState( '' );
        const [ moreOpen, setMoreOpen ] = useState( false );

        // The popover only TRIGGERS a run, then closes. Streaming + the toolbar
        // animation are owned by the block wrapper (EraBlockEdit) so they survive
        // the popover closing.
        const trigger = ( intent, instruction ) => {
 onRun( intent, instruction ); onClose();
};
        const submitPrompt = () => {
 if ( prompt.trim() ) {
trigger( 'custom', prompt.trim() );
}
};

        if ( ! caps.text ) {
return el( 'div', { className: 'eqe-menu' } );
}

        const ops = caps.text.ops;
        const primary = PRIMARY_INTENTS.filter( ( id ) => ops.indexOf( id ) !== -1 );
        const more = MORE_INTENTS.filter( ( id ) => ops.indexOf( id ) !== -1 );

        // Primary actions — staged horizontal toolbar-style buttons (unchanged).
        const actBtn = ( id ) => el( 'button', { key: id, className: 'eqe-act', onClick: () => trigger( id ) },
            el( 'span', { className: 'eqe-ai' }, INTENT_META[ id ].icon() ),
            INTENT_META[ id ].label,
        );
        // "More tones & styles" reveal — same horizontal icon+label structure as
        // the primary row, just under a MORE label + a Show less link.
        const linkRow = ( open ) => el( 'button', {
            key: open ? 'more' : 'less', className: 'eqe-morelink', onClick: () => setMoreOpen( open ), 'aria-expanded': open,
        },
            ic( open ? [ p( 'M12 5v14', 'a' ), p( 'M5 12h14', 'b' ) ] : [ p( 'M18 6 6 18', 'a' ), p( 'm6 6 12 12', 'b' ) ] ),
            open ? __( 'More tones & styles', 'zip-ai' ) : __( 'Show less', 'zip-ai' ),
        );

        let moreSection = null;
        if ( more.length ) {
            moreSection = moreOpen
                ? el( 'div', { className: 'eqe-moreopen' },
                    el( 'div', { className: 'eqe-morelabel' }, __( 'More', 'zip-ai' ) ),
                    el( 'div', { className: 'eqe-actions-row' }, more.map( actBtn ) ),
                    linkRow( false ) )
                : linkRow( true );
        }

        return el( 'div', { className: 'eqe-menu' },
            el( 'div', { className: 'eqe-promptRow' },
                el( 'div', { className: 'eqe-promptField' },
                    el( 'span', { className: 'eqe-spark' }, EraMark() ),
                    el( 'input', {
                        className: 'eqe-promptInput', type: 'text',
                        placeholder: __( 'Tell ZIP AI what to change…', 'zip-ai' ),
                        value: prompt, onChange: ( e ) => setPrompt( e.target.value ),
                        onKeyDown: ( e ) => {
 if ( e.key === 'Enter' ) {
 e.preventDefault(); submitPrompt();
}
},
                    } ),
                    el( 'button', { className: 'eqe-send' + ( prompt.trim() ? ' ready' : '' ), onClick: submitPrompt, 'aria-label': __( 'Send', 'zip-ai' ) },
                        ic( [ p( 'm5 12 7-7 7 7', 'a' ), p( 'M12 19V5', 'b' ) ] ) ), // arrow-up
                ) ),
            el( 'div', { className: 'eqe-divider' } ),
            el( 'div', { className: 'eqe-actions-row' }, primary.map( actBtn ) ),
            moreSection,
        );
    }

    // ── Image popover (Generate / Search) ──
    function ImagePanel( props ) {
        const { setAttributes, onClose } = props;
        // Per-block image attribute names (descriptor from imageDescriptorFor).
        const d = ( props.caps && props.caps.image ) || { url: 'url', id: 'id', alt: 'alt' };
        const applyImage = ( url, id, alt ) => {
            const a = {}; a[ d.url ] = url; a[ d.id ] = id; if ( d.alt ) {
a[ d.alt ] = alt;
}
            setAttributes( a ); onClose();
        };
        const [ mode, setMode ] = useState( 'generate' ); // generate | search
        const [ imgPrompt, setImgPrompt ] = useState( '' );
        const [ ratio, setRatio ] = useState( '1536x1024' );
        const [ styleChip, setStyleChip ] = useState( null );
        const [ busy, setBusy ] = useState( false );
        const [ error, setError ] = useState( '' );
        const [ genPreview, setGenPreview ] = useState( null ); // dataUrl
        const [ query, setQuery ] = useState( '' );
        const [ results, setResults ] = useState( [] );
        const [ searching, setSearching ] = useState( false );

        const doGenerate = useCallback( () => {
            if ( ! imgPrompt.trim() ) {
return;
}
            const full = styleChip ? ( imgPrompt.trim() + ', ' + styleChip + ' style' ) : imgPrompt.trim();
            setBusy( true ); setError( '' ); setGenPreview( null );
            generateImage( full, ratio )
                .then( ( { dataUrl } ) => {
 setGenPreview( dataUrl ); setBusy( false );
} )
                .catch( ( e ) => {
 setError( errText( e, 'Couldn’t generate the image. Please try again.' ) ); setBusy( false );
} );
        }, [ imgPrompt, styleChip, ratio ] );

        const useGenerated = useCallback( () => {
            if ( ! genPreview ) {
return;
}
            setBusy( true );
            uploadFromDataUrl( genPreview )
                .then( ( { url, id } ) => {
 applyImage( url, id, imgPrompt.trim() );
} )
                .catch( ( e ) => {
 setError( errText( e, 'Couldn’t add the image. Please try again.' ) ); setBusy( false );
} );
        }, [ genPreview, imgPrompt, setAttributes, onClose ] );

        const doSearch = useCallback( () => {
            if ( ! query.trim() ) {
return;
}
            setSearching( true ); setError( '' ); setResults( [] );
            searchStock( query.trim() )
                .then( ( r ) => {
 setResults( r ); setSearching( false );
} )
                .catch( ( e ) => {
 setError( errText( e, 'Couldn’t search stock photos. Please try again.' ) ); setSearching( false );
} );
        }, [ query ] );

        const pickStock = useCallback( ( item ) => {
            const src = item.urls && ( item.urls.regular || item.urls.small || item.urls.thumb );
            if ( ! src ) {
return;
}
            setBusy( true );
            uploadFromUrl( src )
                .then( ( { url, id } ) => {
 applyImage( url, id, item.alt_description || query );
} )
                .catch( ( e ) => {
 setError( errText( e, 'Couldn’t add the image. Please try again.' ) ); setBusy( false );
} );
        }, [ query, setAttributes, onClose ] );

        const seg = el( 'div', { className: 'eqe-seg' },
            el( 'button', { className: 'eqe-segBtn' + ( mode === 'generate' ? ' on' : '' ), onClick: () => setMode( 'generate' ) }, cameraIcon(), __( 'Generate', 'zip-ai' ) ),
            el( 'button', { className: 'eqe-segBtn' + ( mode === 'search' ? ' on' : '' ), onClick: () => setMode( 'search' ) }, searchIcon(), __( 'Search', 'zip-ai' ) ),
        );

        let body;
        if ( mode === 'generate' ) {
            body = el( 'div', { className: 'eqe-imgBody' },
                el( 'textarea', {
                    className: 'eqe-ta', rows: 3, placeholder: __( 'Describe the image you want…', 'zip-ai' ),
                    value: imgPrompt, onChange: ( e ) => setImgPrompt( e.target.value ),
                } ),
                el( 'div', { className: 'eqe-fieldLabel' }, __( 'Style', 'zip-ai' ) ),
                el( 'div', { className: 'eqe-swatchRow' }, STYLE_PRESETS.map( ( s ) =>
                    el( 'button', {
                        key: s.id, className: 'eqe-swatch' + ( styleChip === s.id ? ' on' : '' ),
                        'aria-pressed': styleChip === s.id, onClick: () => setStyleChip( styleChip === s.id ? null : s.id ),
                    },
                        el( 'span', { className: 'eqe-swatchImg', style: { backgroundImage: s.grad } }, s.icon() ),
                        el( 'span', { className: 'eqe-swatchLbl' }, s.label ) ) ) ),
                el( 'div', { className: 'eqe-fieldLabel' }, __( 'Aspect ratio', 'zip-ai' ) ),
                el( 'div', { className: 'eqe-ratioRow' }, RATIOS.map( ( r ) =>
                    el( 'button', { key: r.id, className: 'eqe-ratio' + ( ratio === r.id ? ' on' : '' ), onClick: () => setRatio( r.id ) },
                        el( 'span', { className: 'eqe-box', style: { width: r.w, height: r.h } } ),
                        el( 'span', { className: 'eqe-rl' }, r.label ) ) ) ),
                el( 'button', { className: 'eqe-primary', disabled: busy || ! imgPrompt.trim(), onClick: doGenerate },
                    ( ! genPreview && busy ) ? spinner( 'on-dark' ) : cameraIcon(),
                    ( ! genPreview && busy ) ? __( 'Generating…', 'zip-ai' ) : genPreview ? __( 'Regenerate', 'zip-ai' ) : __( 'Generate image', 'zip-ai' ) ),
                genPreview ? el( 'div', { className: 'eqe-genResult' + ( busy ? ' busy' : '' ) },
                    el( 'img', { src: genPreview, alt: '', style: { width: '100%', display: 'block' } } ),
                    el( 'button', { className: 'eqe-useOverlay', disabled: busy, onClick: useGenerated },
                        busy ? spinner( 'on-dark' ) : checkIcon(),
                        busy ? __( 'Replacing…', 'zip-ai' ) : __( 'Replace', 'zip-ai' ) ) ) : null,
                error ? el( 'p', { className: 'eqe-errline' }, error ) : null,
            );
        } else {
            body = el( 'div', { className: 'eqe-imgBody' },
                el( 'div', { className: 'eqe-searchField' + ( searching ? ' busy' : '' ) },
                    // The search icon itself becomes the loader while a query runs —
                    // no separate spinner row, so the field stays the focal point.
                    searching ? spinner() : searchIcon(),
                    el( 'input', {
                        type: 'text', placeholder: __( 'Search free stock photos…', 'zip-ai' ),
                        value: query, onChange: ( e ) => setQuery( e.target.value ),
                        onKeyDown: ( e ) => {
 if ( e.key === 'Enter' ) {
 e.preventDefault(); doSearch();
}
},
                    } ) ),
                searching ? el( 'div', { className: 'eqe-grid eqe-skel' },
                    [ 0, 1, 2, 3, 4, 5 ].map( ( i ) => el( 'span', { key: i, className: 'eqe-skelcell' } ) ) ) : null,
                results.length ? el( 'div', { className: 'eqe-grid' }, results.map( ( item, i ) =>
                    el( 'button', { key: item.id || i, className: 'eqe-thumb', onClick: () => pickStock( item ), disabled: busy },
                        el( 'img', { src: ( item.urls && ( item.urls.thumb || item.urls.small ) ) || '', alt: item.alt_description || '', loading: 'lazy' } ) ) ) ) : null,
                results.length ? el( 'p', { className: 'eqe-credit' }, __( 'Photos via Pexels / Unsplash', 'zip-ai' ) ) : null,
                error ? el( 'p', { className: 'eqe-errline' }, error ) : null,
            );
        }
        return el( 'div', { className: 'eqe-imgPanel' }, seg, body );
    }

    function QuickEditPanel( props ) {
        if ( props.caps.image ) {
return el( ImagePanel, props );
}
        return el( TextPanel, props );
    }

    // Per-editable-block wrapper. OWNS the streaming text run so it outlives the
    // popover: the popover only triggers run(); the stream, the typewriter
    // reveal, the skeleton (until the first token) and the toolbar loading state
    // all live here. Errors surface as a Gutenberg snackbar notice — no popover.
    function EraBlockEdit( opts ) {
        const BlockEdit = opts.BlockEdit,
props = opts.props,
caps = opts.caps;
        const { name, attributes, setAttributes } = props;
        const [ running, setRunning ] = useState( false );
        const [ progress, setProgress ] = useState( null ); // section edit: { cur, total }
        const abortRef = useRef( null );
        const timerRef = useRef( null );

        const markTransient = useCallback( () => {
            try {
 wp.data.dispatch( 'core/block-editor' ).__unstableMarkNextChangeAsNotPersistent();
} catch ( e ) {}
        }, [] );
        const notifyError = useCallback( ( msg ) => {
            try {
 wp.data.dispatch( 'core/notices' ).createErrorNotice( msg, { type: 'snackbar', isDismissible: true } );
} catch ( e ) {}
        }, [] );

        // Abort + clear on unmount.
        useEffect( () => () => {
            if ( abortRef.current ) {
abortRef.current.abort();
}
            if ( timerRef.current ) {
clearInterval( timerRef.current );
}
        }, [] );

        const cancel = useCallback( () => {
 if ( abortRef.current ) {
abortRef.current.abort();
}
}, [] );

        // Edit a block or a whole section (a single block is a list of one). A
        // large section is bin-packed into batches that each fit one call, fetched
        // SEQUENTIALLY (one connection at a time), and revealed as each arrives: a
        // choreographed SPOTLIGHT pass types each changed block in turn (shimmer →
        // glow + scroll → typewriter → done). Each block has a unique id and lives
        // in exactly one batch, so the per-id buffer never conflicts. One persistent
        // write per block; cancel reverts everything, a mid-way failure keeps the
        // batches already applied. Toolbar shows "Editing N of M" across the section.
        const run = useCallback( ( intent, instruction ) => {
            if ( abortRef.current ) {
return;
}
            // Every editable text target in scope (own fields + descendants, any
            // block type). A target = one (block, field): a block with several
            // text fields contributes several targets, each keyed by `key`
            // (clientId::field) since the wire keys edits by id.
            // Drop any single field whose own est-cost already exceeds the
            // per-call ceiling — it can't fit a batch and the brain would reject
            // it, failing the whole pass; skipping it lets the rest still edit.
            const all = collectTargets( props.clientId );
            const targets = all.filter(
                ( t ) => Math.ceil( t.text.length / 2 ) + 64 + 4096 <= SECTION_COST_CEILING );
            if ( ! targets.length ) {
                // Don't fail silently: if there WAS text but every field is over
                // the ceiling, tell the user instead of a no-op.
                if ( all.length ) {
notifyError( __( 'This text is too long for ZIP AI to edit in one pass.', 'zip-ai' ) );
}
                return;
            }
            const byKey = {}; targets.forEach( ( t ) => {
 byKey[ t.key ] = t;
} );
            const batches = partitionTargets( targets ); // each ≤ cost ceiling & ≤ count cap
            // Gather the page outline ONCE (same for every batch of this run) so a
            // multi-batch section doesn't re-walk the block tree per batch.
            const pageOutline = collectPageOutline();

            const ac = new AbortController(); abortRef.current = ac;
            setRunning( true );
            const dispatch = wp.data.dispatch( 'core/block-editor' );
            const setText = ( id, field, value ) => {
 const a = {}; a[ field ] = value; dispatch.updateBlockAttributes( id, a );
};
            const latest = {}; // key -> final text, buffered per batch
            targets.forEach( ( t ) => setBlockState( t.id, 'pending' ) ); // all waiting → shimmer
            setProgress( { cur: 0, total: targets.length } );

            // Reveal one target: spotlight + scroll its block, typewriter at a
            // steady cadence into its field, final persistent write, done flash.
            const reveal = ( t ) => new Promise( ( resolve ) => {
                const full = latest[ t.key ];
                setBlockState( t.id, 'active' );
                // A value containing HTML tags CANNOT be char-sliced: an
                // intermediate slice lands mid-tag (e.g. `…></span><span>`), which
                // is invalid markup — and if the reveal is interrupted there, the
                // block freezes in that broken state and loses content. Write rich
                // values in one shot; only plain text gets the typewriter.
                if ( /<[a-z!/][^>]*>/i.test( full ) ) {
                    setText( t.id, t.field, full );
                    setBlockState( t.id, 'done' );
                    resolve();
                    return;
                }
                let shown = 0;
                timerRef.current = setInterval( () => {
                    if ( ac.signal.aborted ) {
 clearInterval( timerRef.current ); timerRef.current = null; resolve(); return;
}
                    shown = Math.min( full.length, shown + Math.max( 2, Math.ceil( ( full.length - shown ) / 8 ) ) );
                    markTransient(); setText( t.id, t.field, full.slice( 0, shown ) );
                    if ( shown >= full.length ) {
                        clearInterval( timerRef.current ); timerRef.current = null;
                        setText( t.id, t.field, full ); // persist final
                        setBlockState( t.id, 'done' );
                        resolve();
                    }
                }, 30 );
            } );
            const revertAll = () => {
                targets.forEach( ( t ) => setBlockState( t.id, null ) );
                Object.keys( latest ).forEach( ( key ) => {
 const t = byKey[ key ]; if ( t ) {
 markTransient(); setText( t.id, t.field, t.text );
}
} );
            };

            ( async () => {
                let revealed = 0; // targets the brain actually changed
                let processed = 0; // all targets handled — drives the N-of-M counter
                try {
                    for ( let b = 0; b < batches.length; b += 1 ) {
                        if ( ac.signal.aborted ) {
break;
}
                        const batch = batches[ b ];
                        const payload = { intent, blocks: batch.map( ( t ) => ( { id: t.key, block_type: t.name, text: t.text } ) ) };
                        if ( intent === 'custom' ) {
payload.instruction = instruction;
}
                        if ( pageOutline ) {
payload.page_outline = pageOutline;
}
                        // Fetch this batch (fills the buffer), then reveal its targets.
                        await streamEdit( payload, ( key, text ) => {
 if ( byKey[ key ] ) {
latest[ key ] = text;
}
}, ac.signal ); // eslint-disable-line no-await-in-loop
                        for ( let i = 0; i < batch.length; i += 1 ) {
                            if ( ac.signal.aborted ) {
break;
}
                            const t = batch[ i ];
                            // Count every target (changed or not) so the counter
                            // reaches M even when some blocks come back unchanged.
                            processed += 1; setProgress( { cur: processed, total: targets.length } );
                            if ( latest[ t.key ] !== null && latest[ t.key ] !== undefined && latest[ t.key ] !== t.text ) {
                                revealed += 1;
                                await reveal( t ); // eslint-disable-line no-await-in-loop
                            } else {
                                setBlockState( t.id, null ); // unchanged → drop shimmer
                            }
                        }
                        // Brief gap before the next batch so a multi-batch section
                        // doesn't machine-gun the provider into a rate limit.
                        if ( b < batches.length - 1 && ! ac.signal.aborted ) {
                            await new Promise( ( r ) => setTimeout( r, BATCH_GAP_MS ) ); // eslint-disable-line no-await-in-loop
                        }
                    }
                    abortRef.current = null; setRunning( false ); setProgress( null );
                    if ( ac.signal.aborted ) {
 revertAll(); return;
}
                    if ( revealed === 0 ) {
notifyError( __( 'No changes were needed.', 'zip-ai' ) );
}
                } catch ( e ) {
                    abortRef.current = null; setRunning( false ); setProgress( null );
                    if ( ac.signal.aborted ) {
 revertAll(); return;
}
                    // Partial failure: keep batches already applied; just clear the
                    // shimmer on targets we never reached.
                    targets.forEach( ( t ) => {
 if ( latest[ t.key ] === null || latest[ t.key ] === undefined ) {
setBlockState( t.id, null );
}
} );
                    notifyError( errText( e, __( 'ZIP AI couldn’t finish this section. Please try again.', 'zip-ai' ) ) );
                }
            } )();
        }, [ props.clientId, markTransient, notifyError ] );

        // caps null = no ZIP AI affordance for this block (yet) — render the
        // block untouched. Hooks above still run so the type stays mounted.
        const toolbar = caps && el( BlockControls, { group: 'other' },
            el( ToolbarGroup, null,
                running
                    ? el( ToolbarButton, {
                        label: __( 'Stop', 'zip-ai' ), showTooltip: true,
                        onClick: cancel, className: 'eqe-toggle eqe-running',
                    },
                        // Loader + explicit "editing" copy + animated dots so it
                        // reads unmistakably as work-in-progress, not a static icon.
                        el( 'span', { className: 'eqe-workwrap' },
                            EraLoader(),
                            el( 'span', { className: 'eqe-worktext' },
                                ( progress && progress.total > 1 )
                                    ? sprintf( /* translators: %1$d is the current item number, %2$d is the total number of items. */ __( 'Editing %1$d of %2$d', 'zip-ai' ), progress.cur || 1, progress.total )
                                    : __( 'ZIP AI is editing', 'zip-ai' ) ),
                            el( 'span', { className: 'eqe-dots', 'aria-hidden': 'true' },
                                el( 'i', null ), el( 'i', null ), el( 'i', null ) ) ) )
                    : el( Dropdown, {
                        // bottom-end opens the panel leftward, into the canvas and
                        // away from the docked ZIP AI sidebar — a right-edge block's
                        // bottom-start panel slid under it. flip still picks the
                        // vertical side with room (toolbar floats high on some
                        // blocks, low on others); shift clamps the panel back into
                        // view near an edge. The panel keeps a stable compact height
                        // (eqe-imgBody min-height + grid cap below) so flip doesn't
                        // ping-pong between tabs, and content scrolls rather than
                        // clipping when space is tight (resize+overflow).
                        popoverProps: { placement: 'bottom-end', flip: true, shift: true },
                        className: 'eqe-dropdown', contentClassName: 'eqe-popover',
                        renderToggle: ( { isOpen, onToggle } ) => el( ToolbarButton, {
                            icon: EraMark(), text: __( 'Ask ZIP AI', 'zip-ai' ), label: __( 'Ask ZIP AI', 'zip-ai' ),
                            showTooltip: true, onClick: onToggle, 'aria-expanded': isOpen, className: 'eqe-toggle',
                        } ),
                        renderContent: ( { onClose } ) => el( QuickEditPanel, {
                            name, attributes, setAttributes, caps, onClose, onRun: run,
                        } ),
                    } )
            )
        );

        // No wrapper around BlockEdit — Gutenberg blocks break when their edit
        // output is nested in an extra element. Run state lives in this
        // component (survives the popover closing); the block streams in place.
        return el( wp.element.Fragment, null,
            el( BlockEdit, props ),
            toolbar,
        );
    }

    const withEraQuickEdit = createHigherOrderComponent( ( BlockEdit ) => {
        return ( props ) => {
            // One uniform decision: collect every editable text target in this
            // block's scope (own fields + descendants, any block type). Show
            // "Ask ZIP AI" if there's text within the cap, or it's an image block.
            // REACTIVE gate, cheap steady-state: subscribe to the deep block
            // tree ONLY while this block has no quick-edit affordance yet
            // (fresh empty block), so the button appears the moment text lands
            // — typed or streamed — without a reload. Once targets exist the
            // mapSelect returns a constant and the subscription goes quiet:
            // during a streaming quick edit (~30 descendant attr updates/s)
            // ancestor wrappers do NOT re-walk their subtree per tick.
            // Structural changes still refresh via descIds (render-computed);
            // the block's own fields via props.attributes.
            const hasTargetsRef = useRef( false );
            const emptyTree = wp.data.useSelect(
                ( select ) => hasTargetsRef.current ? null : select( 'core/block-editor' ).getBlocks( props.clientId ),
                [ props.clientId ]
            );
            const descIds = ( () => {
                const be = wp.data.select( 'core/block-editor' );
                return be && typeof be.getClientIdsOfDescendants === 'function'
                    ? be.getClientIdsOfDescendants( [ props.clientId ] ).join( '|' ) : '';
            } )();
            const targets = useMemo( () => collectTargets( props.clientId ), [ props.clientId, descIds, emptyTree, props.attributes ] );
            hasTargetsRef.current = targets.length > 0;
            const hasText = targets.length > 0 && targets.length <= MAX_SECTION_TARGETS;
            // Image descriptor (or null). Skip a media block currently in video
            // mode (e.g. core/media-text with mediaType:'video').
            const imageDesc = props.attributes && props.attributes.mediaType === 'video'
                ? null : imageDescriptorFor( props.name );
            // The image panel is shown ONLY for a leaf image block (core/image,
            // even one with a caption). A section CONTAINER that also carries an
            // image (core/cover, core/media-text) has editable text in its
            // descendants — there the section text-rewrite is the point, so the
            // image panel must not shadow it.
            const hasDescendantText = targets.some( ( t ) => t.id !== props.clientId );
            const imagePrimary = !! imageDesc && ! hasDescendantText;
            // ALWAYS render EraBlockEdit (caps:null → plain BlockEdit, no
            // toolbar). The component type at this slot must be stable: now
            // that the gate is reactive it flips on the first typed char, and
            // swapping bare BlockEdit ↔ wrapper on a flip remounts the block's
            // edit tree mid-typing (caret/IME loss). Conditional lives inside.
            return el( EraBlockEdit, {
                BlockEdit, props,
                caps: ( hasText || imagePrimary ) ? {
                    text: ( hasText && ! imagePrimary ) ? { ops: TEXT_FULL } : null,
                    image: imagePrimary ? imageDesc : false,
                } : null,
            } );
        };
    }, 'withEraQuickEdit' );

    addFilter( 'editor.BlockEdit', 'zip-ai/quick-edit', withEraQuickEdit );

    // One-time scoped styles for the quick-edit popover (ZIP AI design tokens).
    ( function injectQuickEditStyles() {
        if ( document.getElementById( 'eqe-styles' ) ) {
return;
}
        const s = document.createElement( 'style' );
        s.id = 'eqe-styles';
        s.textContent =
            // Accent maps to the ZIP AI ink system (no blue): primary/active = ink-1,
            // soft ring = translucent ink, soft fill = sunken.
            '.eqe-popover{--ai:#1A1D21;--ai-50:#F6F7F8;--ink0:#0A0A0C;--ink1:#1A1D21;--ink3:#6B7178;--ink4:#9AA0A6;--ink5:#BCC0C5;--line:#ECECEE;--line2:#E2E2E5;--line3:#D3D4D7;--sunken:#F6F7F8}' +
            // overflow:auto (not hidden) so when flip/resize constrains the popover
            // near a viewport edge the panel scrolls instead of clipping its top.
            '.eqe-popover .components-popover__content{border-radius:14px!important;border:1px solid var(--line2)!important;box-shadow:0 18px 44px -16px rgba(16,18,20,.26),0 4px 12px -6px rgba(16,18,20,.12)!important;overflow:auto}' +
            // Toolbar-width panel: prompt on top, one wrapping action row.
            '.eqe-menu{width:344px;max-width:92vw;padding:0 0 7px}' +
            '.eqe-promptRow{padding:11px 11px 9px}' +
            '.eqe-promptField{display:flex;align-items:center;gap:9px;border:1.5px solid var(--line2);border-radius:11px;padding:0 6px 0 12px;height:44px;transition:border-color .14s,box-shadow .14s}' +
            '.eqe-promptField:focus-within{border-color:var(--ai);box-shadow:0 0 0 3px var(--ai-50)}' +
            // Kill wp-admin borders/rings on the bare inputs (focused or not) so
            // only the wrapper shows a single border — no nested box.
            '.eqe-popover .eqe-promptField input,.eqe-popover .eqe-searchField input{border:none!important;box-shadow:none!important;outline:none!important;background:transparent!important}' +
            '.eqe-spark{color:var(--ai);display:flex;flex-shrink:0}.eqe-spark svg{width:18px;height:18px}' +
            '.eqe-promptInput{flex:1;border:none;outline:none;background:transparent;font-size:13.5px;color:var(--ink1);min-width:0}' +
            // wp-admin slaps a blue focus ring on every input/textarea — kill it
            // inside the popover so only OUR ink ring (on the wrappers) shows.
            '.eqe-popover input:focus,.eqe-popover .eqe-promptInput:focus,.eqe-popover .eqe-searchField input:focus{outline:none!important;box-shadow:none!important;border:none!important;background:transparent!important}' +
            '.eqe-popover .eqe-ta:focus{border-color:var(--ai)!important;box-shadow:0 0 0 3px var(--ai-soft)!important;outline:none!important}' +
            '.eqe-send{width:32px;height:32px;border-radius:9px;border:none;cursor:pointer;display:grid;place-items:center;flex-shrink:0;background:var(--sunken);color:var(--ink5);transition:all .14s}' +
            '.eqe-send svg{width:17px;height:17px}' +
            '.eqe-send.ready{background:var(--ai);color:#fff}' +
            '.eqe-send.ready:hover{transform:translateY(-1px)}' +
            '.eqe-divider{height:1px;background:var(--line);margin:0 11px}' +
            // Primary actions — staged horizontal toolbar-style buttons.
            '.eqe-actions-row{display:flex;flex-wrap:wrap;gap:3px;padding:8px}' +
            '.eqe-act{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border:none;background:none;border-radius:8px;cursor:pointer;color:var(--ink1);font-size:12.5px;font-weight:500;transition:background .12s}' +
            '.eqe-act:hover{background:var(--sunken)}' +
            '.eqe-act .eqe-ai{width:15px;height:15px;flex-shrink:0;color:var(--ink3);display:flex}.eqe-act:hover .eqe-ai{color:var(--ai)}.eqe-act .eqe-ai svg{width:15px;height:15px}' +
            // "More tones & styles" reveal — same horizontal action row as primary.
            '.eqe-moreopen{border-top:1px solid var(--line);margin-top:2px}' +
            // "+ More tones & styles" / "Show less" accent link row (ZIP AI ink).
            '.eqe-morelabel{font-size:9.5px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;color:var(--ink4);padding:7px 9px 3px}' +
            '.eqe-morelink{display:flex;align-items:center;gap:8px;width:100%;padding:6px 7px;border:none;background:none;border-radius:9px;cursor:pointer;text-align:left;color:var(--ai);font-size:12.5px;font-weight:600;transition:background .12s}' +
            '.eqe-morelink:hover{background:var(--sunken)}' +
            '.eqe-morelink svg{width:15px;height:15px;flex-shrink:0;margin:0 6px}' +
            // Ink spinner ring — shared by search field + Generate button.
            '.eqe-spin{width:16px;height:16px;border-radius:50%;border:2px solid var(--line2);border-top-color:var(--ai);box-sizing:border-box;flex-shrink:0;display:inline-block;animation:eqe-rot .7s linear infinite}' +
            '.eqe-spin.on-dark{border-color:rgba(255,255,255,.35);border-top-color:#fff}' +
            '@keyframes eqe-rot{to{transform:rotate(360deg)}}' +
            // "ZIP AI is editing" toolbar state — loader + copy + bouncing dots.
            '.eqe-workwrap{display:inline-flex;align-items:center;gap:8px}' +
            '.eqe-worktext{font-size:12.5px;font-weight:600;color:var(--ai);white-space:nowrap}' +
            '.eqe-dots{display:inline-flex;align-items:flex-end;gap:3px;padding-bottom:2px}' +
            '.eqe-dots i{width:3px;height:3px;border-radius:50%;background:var(--ai);animation:eqe-dot 1.2s ease-in-out infinite}' +
            '.eqe-dots i:nth-child(2){animation-delay:.18s}.eqe-dots i:nth-child(3){animation-delay:.36s}' +
            '@keyframes eqe-dot{0%,70%,100%{opacity:.3;transform:translateY(0)}35%{opacity:1;transform:translateY(-3px)}}' +
            // ZIP AI-mark loader: same ink knockout tile as the Ask button (the
            // mark must not change color while editing) + a comet segment
            // running its border.
            '.eqe-loader{position:relative;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:#FF580E}' +
            '.eqe-loader .eqe-snake-glyph{display:block}' +
            // track + ring use the border-mask trick (content-box xor) so only the
            // 1.6px frame paints; the ring sweeps via the animated --eqe-a angle.
            '.eqe-snake-track,.eqe-snake-ring{position:absolute;inset:0;border-radius:inherit;padding:1.6px;pointer-events:none;-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude}' +
            '.eqe-snake-track{background:rgba(255,255,255,.08)}' +
            '.eqe-snake-ring{background:conic-gradient(from var(--eqe-a,0deg),#FFE7D5 0deg,#FF9A5C 14deg,#FF580E 30deg,rgba(255,88,14,.35) 70deg,rgba(255,88,14,0) 110deg,transparent 360deg);filter:drop-shadow(0 0 3px rgba(255,120,60,.7));animation:eqe-snake 1.5s cubic-bezier(.45,.05,.55,.95) infinite}' +
            '@property --eqe-a{syntax:"<angle>";inherits:false;initial-value:0deg}' +
            '@keyframes eqe-snake{to{--eqe-a:360deg}}' +
            '@media (prefers-reduced-motion:reduce){.eqe-snake-ring,.eqe-spin,.eqe-skelcell,.eqe-dots i{animation:none}}' +
            '.eqe-primary{flex:1;min-width:120px;padding:11px;border:none;border-radius:11px;background:var(--ai);color:#fff;font-size:13.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;box-shadow:inset 0 1px 0 rgba(255,255,255,.18);transition:filter .12s}' +
            '.eqe-primary svg{width:16px;height:16px}' +
            '.eqe-primary:hover:not(:disabled){filter:brightness(1.06)}' +
            // Block skeleton — a shimmer overlay on the editing block(s) until
            // the first streamed token. Targets the block\'s own [data-block]
            // node (no wrapper around BlockEdit). Global, not popover-scoped.
            '[data-block].eqe-skeleton-on{position:relative}' +
            '[data-block].eqe-skeleton-on::after{content:"";position:absolute;inset:0;z-index:6;border-radius:6px;pointer-events:none;background:#F2F3F5;background-image:linear-gradient(90deg,#F2F3F5 0,#E6E8EB 80px,#F2F3F5 160px);background-size:300px 100%;background-repeat:no-repeat;animation:eqe-shimmer 1.1s linear infinite}' +
            '@keyframes eqe-shimmer{0%{background-position:-160px 0}100%{background-position:300px 0}}' +
            '@media (prefers-reduced-motion:reduce){[data-block].eqe-skeleton-on::after{animation:none}}' +
            // Sequential spotlight — the block being written gets a pulsing accent
            // ring; on hand-off it flashes a green "done" ring that fades. Rings
            // paint on ::before (skeleton owns ::after) so the two never clash.
            '[data-block].eqe-block-active{position:relative}' +
            '[data-block].eqe-block-active::before{content:"";position:absolute;inset:-4px;z-index:7;border-radius:9px;pointer-events:none;box-shadow:0 0 0 2px #FF580E,0 0 0 7px rgba(255,88,14,.16);animation:eqe-activepulse 1.4s ease-in-out infinite}' +
            '@keyframes eqe-activepulse{0%,100%{opacity:.6}50%{opacity:1}}' +
            '[data-block].eqe-block-done::before{content:"";position:absolute;inset:-4px;z-index:7;border-radius:9px;pointer-events:none;box-shadow:0 0 0 2px #2BA24C,0 0 0 7px rgba(43,162,76,.14);animation:eqe-doneflash .9s ease-out forwards}' +
            '@keyframes eqe-doneflash{0%{opacity:1}100%{opacity:0}}' +
            '@media (prefers-reduced-motion:reduce){[data-block].eqe-block-active::before,[data-block].eqe-block-done::before{animation:none}}' +
            '.eqe-imgBody>.eqe-primary{width:100%;margin-top:16px}' +
            '.eqe-primary:disabled{opacity:.6;cursor:default}' +
            '.eqe-genResult::after{content:"";position:absolute;inset:0;background:rgba(0,0,0,.14);opacity:0;transition:opacity .15s;pointer-events:none}' +
            '.eqe-genResult:hover::after,.eqe-genResult.busy::after{opacity:1}' +
            '.eqe-useOverlay{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2;display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border:none;border-radius:999px;background:#fff;color:var(--ink0);font-size:13.5px;font-weight:600;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.22);opacity:0;transition:opacity .15s}' +
            '.eqe-genResult:hover .eqe-useOverlay,.eqe-genResult.busy .eqe-useOverlay{opacity:1}' +
            '.eqe-useOverlay svg{width:16px;height:16px}' +
            '.eqe-useOverlay:disabled{cursor:default}' +
            '.eqe-error,.eqe-errline{color:#b32d2e;font-size:13px}.eqe-errline{margin:10px 2px 0}' +
            // Image panel stays on the ZIP AI ink system (no blue): --ai/--ai-50
            // inherit ink-1/sunken from .eqe-popover; only the two image-only
            // tokens are mapped to ink — --ai-ink (selected label) + --ink2.
            '.eqe-imgPanel{--ai-ink:#0A0A0C;--ai-soft:#F6F7F8;--ink2:#3D4248}' +
            '.eqe-seg{display:flex;gap:3px;padding:12px 12px 0}' +
            '.eqe-segBtn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 0;border:none;background:var(--sunken);color:var(--ink3);font-size:12.5px;font-weight:600;cursor:pointer;border-radius:9px;transition:all .14s}' +
            '.eqe-segBtn svg{width:15px;height:15px}' +
            '.eqe-segBtn.on{background:var(--ink1);color:#fff}' +
            // Floor the body to the Generate-tab height so an empty Search tab
            // doesn't collapse — keeps both tabs the same size so flip picks one
            // side and stays there instead of jumping when you switch tabs.
            '.eqe-imgBody{padding:12px;min-width:300px;max-width:340px;min-height:336px;box-sizing:border-box}' +
            '.eqe-ta{width:100%;border:1.5px solid var(--line2);border-radius:11px;padding:10px 12px;font-size:13.5px;line-height:1.5;color:var(--ink1);resize:none;outline:none;min-height:68px;background:#fff;transition:border-color .14s,box-shadow .14s}' +
            '.eqe-ta:focus{border-color:var(--ai);box-shadow:0 0 0 3px var(--ai-soft)}' +
            '.eqe-fieldLabel{font-size:10.5px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;color:var(--ink4);margin:14px 2px 7px}' +
            // Style presets — gradient-thumbnail cards (3-up).
            '.eqe-swatchRow{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}' +
            '.eqe-swatch{display:flex;flex-direction:column;gap:5px;padding:5px;border:1px solid var(--line2);background:#fff;border-radius:10px;cursor:pointer;transition:all .12s}' +
            '.eqe-swatch:hover{border-color:var(--line3)}' +
            '.eqe-swatch.on{border-color:var(--ai);background:var(--ai-50);box-shadow:0 0 0 1px var(--ai)}' +
            '.eqe-swatchImg{height:34px;border-radius:6px;display:grid;place-items:center;color:#fff}' +
            '.eqe-swatchImg svg{width:18px;height:18px}' +
            '.eqe-swatchLbl{font-size:11px;font-weight:500;color:var(--ink2);text-align:center}' +
            '.eqe-swatch.on .eqe-swatchLbl{color:var(--ai-ink)}' +
            '.eqe-ratioRow{display:flex;gap:6px}' +
            '.eqe-ratio{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;padding:9px 0 8px;border:1px solid var(--line2);border-radius:9px;background:#fff;cursor:pointer}' +
            '.eqe-ratio.on{border-color:var(--ai);background:var(--ai-50)}' +
            '.eqe-box{border:1.6px solid var(--ink3);border-radius:2px}.eqe-ratio.on .eqe-box{border-color:var(--ai)}' +
            '.eqe-rl{font-size:11px;font-weight:500;color:var(--ink3)}.eqe-ratio.on .eqe-rl{color:var(--ai)}' +
            '.eqe-genResult{position:relative;margin-top:12px;border-radius:11px;overflow:hidden}' +
            '.eqe-searchField{display:flex;align-items:center;gap:9px;height:42px;padding:0 12px;border:1.5px solid var(--line2);border-radius:11px;color:var(--ink4)}' +
            '.eqe-searchField:focus-within{border-color:var(--ai);box-shadow:0 0 0 3px var(--ai-soft)}' +
            '.eqe-searchField input{flex:1;border:none;outline:none;background:transparent;font-size:13.5px;color:var(--ink1);min-width:0}' +
            // Cap the result grid so the panel stays a fixed compact size: the
            // grid scrolls internally instead of stretching the popover to fill
            // the viewport (which made it resize/jump as the page scrolled).
            '.eqe-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:12px;max-height:300px;overflow-y:auto;overflow-x:hidden}' +
            // Skeleton thumbnails while a search runs — ink shimmer, no blue spinner.
            '.eqe-skelcell{aspect-ratio:1;border-radius:9px;background:linear-gradient(90deg,var(--sunken) 25%,var(--line) 37%,var(--sunken) 63%);background-size:400% 100%;animation:eqe-shim 1.3s ease-in-out infinite}' +
            '@keyframes eqe-shim{0%{background-position:100% 0}100%{background-position:-100% 0}}' +
            '.eqe-thumb{position:relative;aspect-ratio:1;border-radius:9px;overflow:hidden;cursor:pointer;border:1px solid rgba(0,0,0,.06);padding:0;background:none}' +
            '.eqe-thumb img{width:100%;height:100%;object-fit:cover;display:block}' +
            '.eqe-thumb:hover{box-shadow:inset 0 0 0 2.5px var(--ai)}' +
            '.eqe-credit{font-size:11px;color:var(--ink4);margin-top:11px}';
        document.head.appendChild( s );
    }() );
}() );
