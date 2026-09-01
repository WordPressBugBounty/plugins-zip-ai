<?php
/**
 * Tool Registry - Central registry for MCP tools with execution mode support
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Tool_Registry Class.
 * Provides API for third-party plugins to register MCP tools with different execution modes.
 */
class Tool_Registry {

	/**
	 * Registered tools with execution modes.
	 *
	 * @since 1.0.0
	 * @var array<string, array<string, mixed>>
	 */
	private $tools = array();

	/**
	 * Constructor of this class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		// Allow third-party plugins to register tools after abilities API is ready.
		add_action( 'wp_abilities_api_init', array( $this, 'trigger_tool_registration' ), 999 );

		// Enqueue tool metadata for JavaScript.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_tool_metadata' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tool_metadata' ), 20 );
	}

	/**
	 * Trigger the tool registration action for third-party plugins.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function trigger_tool_registration() {
		/**
		 * Fires when ZipWP MCP is ready to accept tool registrations.
		 *
		 * Third-party plugins should hook into this action to register their tools.
		 *
		 * @since 1.0.0
		 */
		do_action( 'zip_ai_register_tools', $this );
	}

	/**
	 * Register a new MCP tool.
	 *
	 * @param string               $tool_name Unique tool name (e.g., 'myplugin/my-action').
	 * @param array<string, mixed> $args Tool configuration arguments.
	 * @return bool True on success, false on failure.
	 *
	 * @since 1.0.0
	 */
	public function register_tool( $tool_name, $args ) {
		// Validate required parameters.
		if ( empty( $tool_name ) ) {
			return false;
		}

		// Default arguments.
		$defaults = array(
			'description'    => '',
			'execution_mode' => 'rest_api', // 'rest_api' or 'js_hook'
			'input_schema'   => array(),
			'examples'       => array(),
			'keywords'       => array(),
			'api_endpoint'   => null, // For rest_api mode
			'js_handler'     => null, // For js_hook mode (JavaScript function name)
			'capabilities'   => array( 'edit_posts' ), // WordPress capabilities required
			'callback'       => null, // PHP callback for rest_api mode
			'preview_mode'   => 'none', // 'none', 'client', 'server'
		);

		/**
		 * Narrowed type for `$args`.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = wp_parse_args( $args, $defaults );

		// Validate execution_mode.
		if ( ! in_array( $args['execution_mode'], array( 'rest_api', 'js_hook' ), true ) ) {
			return false;
		}

		// For rest_api mode, callback is required.
		if ( 'rest_api' === $args['execution_mode'] && empty( $args['callback'] ) && empty( $args['api_endpoint'] ) ) {
			return false;
		}

		// For js_hook mode, js_handler is required.
		if ( 'js_hook' === $args['execution_mode'] && empty( $args['js_handler'] ) ) {
			return false;
		}

		// Store the tool.
		$this->tools[ $tool_name ] = $args;

		// Register with WordPress Abilities API if available.
		if ( class_exists( 'WP_Abilities_Registry' ) ) {
			$this->register_with_abilities_api( $tool_name, $args );
		}

		return true;
	}

	/**
	 * Register tool with WordPress Abilities API.
	 *
	 * @param string               $tool_name Tool name.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	private function register_with_abilities_api( $tool_name, $args ) {
		$registry = \WP_Abilities_Registry::get_instance();

		if ( null === $registry ) {
			return;
		}

		// Create ability configuration.
		$ability_config = array(
			'description'  => $args['description'],
			'input_schema' => $args['input_schema'],
			'meta'         => array(
				'examples'       => $args['examples'],
				'keywords'       => $args['keywords'],
				'execution_mode' => $args['execution_mode'],
				'js_handler'     => $args['js_handler'],
				'preview_mode'   => $args['preview_mode'],
			),
		);

		// Add API endpoint for rest_api mode.
		if ( 'rest_api' === $args['execution_mode'] && ! empty( $args['api_endpoint'] ) ) {
			$ability_config['meta']['api_endpoint'] = $args['api_endpoint'];
		}

		// Add callback executor if provided.
		if ( ! empty( $args['callback'] ) ) {
			$ability_config['executor'] = $args['callback'];
		}

		// Register the ability.
		$registry->register( $tool_name, $ability_config );
	}

	/**
	 * Get all registered tools.
	 *
	 * @return array<string, array<string, mixed>> Array of registered tools.
	 *
	 * @since 1.0.0
	 */
	public function get_all_tools() {
		return $this->tools;
	}

	/**
	 * Get a specific tool by name.
	 *
	 * @param string $tool_name Tool name.
	 * @return array<string, mixed>|null Tool configuration or null if not found.
	 *
	 * @since 1.0.0
	 */
	public function get_tool( $tool_name ) {
		return isset( $this->tools[ $tool_name ] ) ? $this->tools[ $tool_name ] : null;
	}

	/**
	 * Get tools by execution mode.
	 *
	 * @param string $mode Execution mode ('rest_api' or 'js_hook').
	 * @return array<string, array<string, mixed>> Array of tools matching the execution mode.
	 *
	 * @since 1.0.0
	 */
	public function get_tools_by_mode( $mode ) {
		return array_filter(
			$this->tools,
			function ( $tool ) use ( $mode ) {
				return isset( $tool['execution_mode'] ) && $tool['execution_mode'] === $mode;
			}
		);
	}

	/**
	 * Enqueue tool metadata for JavaScript.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_tool_metadata() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Sync tools from WordPress Abilities API
		$this->sync_from_abilities_api();

		// Prepare tool metadata for JavaScript (only execution-related info).
		$tool_metadata = array();
		foreach ( $this->tools as $tool_name => $tool_config ) {
			$tool_metadata[ $tool_name ] = array(
				'execution_mode' => $tool_config['execution_mode'],
				'js_handler'     => $tool_config['js_handler'] ?? null,
				'preview_mode'   => $tool_config['preview_mode'] ?? 'none',
			);
		}

		// Add inline script with tool metadata.
		wp_add_inline_script(
			'zip-ai-tool-hooks',
			'window.zipwpMcpTools = ' . wp_json_encode( $tool_metadata ) . ';',
			'before'
		);
	}

	/**
	 * Sync tools from WordPress Abilities API.
	 * This ensures all abilities registered via wp_register_ability() are included.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function sync_from_abilities_api() {
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return;
		}

		$registry = \WP_Abilities_Registry::get_instance();

		if ( null === $registry ) {
			return;
		}

		$abilities = $registry->get_all_registered();

		foreach ( $abilities as $ability_name => $ability ) {
			$ability_name = (string) $ability_name;
			// Skip if already registered via register_tool()
			if ( isset( $this->tools[ $ability_name ] ) ) {
				continue;
			}

			// Get metadata from ability (WP_Ability has get_meta(), not get_meta_data())
			/**
			 * Narrowed type for `$meta`.
			 *
			 * @var array<string, mixed> $meta
			 */
			$meta = $ability->get_meta();

			// Determine execution mode
			$execution_mode = $meta['execution_mode'] ?? 'rest_api';

			// Add to tools array
			$this->tools[ $ability_name ] = array(
				'execution_mode' => $execution_mode,
				'js_handler'     => $meta['js_handler'] ?? null,
				'preview_mode'   => $meta['preview_mode'] ?? 'none',
				'description'    => $ability->get_description(),
			);
		}
	}

	/**
	 * Check if a tool uses JavaScript hook execution.
	 *
	 * @param string $tool_name Tool name.
	 * @return bool True if tool uses JS hooks, false otherwise.
	 *
	 * @since 1.0.0
	 */
	public function is_js_hook_tool( $tool_name ) {
		$tool = $this->get_tool( $tool_name );
		return $tool && 'js_hook' === ( $tool['execution_mode'] ?? '' );
	}
}
