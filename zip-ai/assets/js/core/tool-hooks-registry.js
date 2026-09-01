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
