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
