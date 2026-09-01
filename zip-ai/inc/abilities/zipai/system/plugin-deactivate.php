<?php
/**
 * Plugin Deactivate — server-side execution.
 *
 * Sister to PluginActivate. Runs `deactivate_plugins()` in-process
 * under the App Password user's identity. Idempotent — already-inactive
 * plugins return success without re-firing deactivation hooks.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Abilities\Zipai\System\PluginResolver;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PluginDeactivate extends Abstract_Ability {

	/**
	 * Flags this ability as destructive (mutates site state).
	 *
	 * @var bool
	 */
	protected $is_destructive = true;

	/**
	 * Configures the ability's id, label, description and metadata.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/deactivate-plugin';
		$this->label       = 'Deactivate Plugin';
		$this->description = 'Deactivate an active plugin by slug or "folder/file" identifier. '
			. 'Runs `deactivate_plugins()` in-process under the App-Password user\'s identity. '
			. 'Requires `activate_plugins` capability.';
		$this->capability  = 'activate_plugins';

		$this->meta = array(
			'tool_type'          => Tool_Types::WRITE,
			'preflight_resource' => array(
				'kind'      => 'installed_plugin',
				'arg_field' => 'slug',
			),
		);
	}

	/**
	 * Returns the tool-type classification for this ability.
	 *
	 * @return string One of the Tool_Types constants.
	 */
	public function get_tool_type() {
		return Tool_Types::WRITE;
	}

	/**
	 * Returns the JSON Schema for this ability's input arguments.
	 *
	 * @return array<string,mixed> JSON Schema describing accepted arguments.
	 */
	public function get_input_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'slug' ),
			'additionalProperties' => false,
			'properties'           => array(
				'slug' => array(
					'type'        => 'string',
					'description' => 'Plugin slug ("contact-form-7") or WP REST plugin id "folder/file" (`.php` extension optional, stripped automatically).',
				),
			),
		);
	}

	/**
	 * Deactivates an active plugin by slug or "folder/file" identifier.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? sanitize_text_field( $args['slug'] ) : '';
		if ( '' === $slug ) {
			return Response::error( 'Plugin slug is required.' );
		}
		// Accept real installed-plugin folder names (underscores/dots), not only
		// wp.org hyphen-slugs — PluginResolver is the safe arbiter. See plugin-activate.php.
		if ( ! preg_match( '#^[a-z0-9][\w.-]*(?:/[a-z0-9._-]+(?:\.php)?)?$#i', $slug ) ) {
			return Response::error( 'Invalid plugin identifier.' );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = PluginResolver::resolve_plugin_file( $slug );
		if ( null === $plugin_file ) {
			return Response::error( sprintf( 'Plugin "%s" is not installed.', $slug ) );
		}

		// Self-preservation: this ability runs INSIDE ZIP AI — deactivating
		// it here kills the assistant's chat, tools, and this connection
		// mid-action. Fail closed; wp-admin → Plugins is the supported path.
		if ( PluginResolver::is_self( $plugin_file ) ) {
			return Response::error(
				sprintf( 'Refused: "%s" is the ZIP AI plugin, which runs this AI assistant — deactivating it from here would sever the chat, all tools, and this connection mid-action.', $slug ),
				'Leave ZIP AI active and continue the remaining work. If the user explicitly wants ZIP AI off, tell them to toggle it in wp-admin → Plugins.'
			);
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			return array(
				'success' => true,
				'message' => sprintf( 'Plugin "%s" is already inactive.', $slug ),
				'data'    => array(
					'slug'        => $slug,
					'plugin_file' => $plugin_file,
					'active'      => false,
				),
			);
		}

		// `deactivate_plugins()` returns void. WP core protects critical
		// plugins via the `pre_deactivate_plugin` action chain — we let
		// any plugin-side guard surface its own error path via WP_Error
		// in `pre_update_option_active_plugins` (filtered into our own
		// Protected_Options_Filter for the essential set).
		deactivate_plugins( array( $plugin_file ), true );

		// Cast forces a fresh call: analysis can't model that deactivate_plugins() mutated state.
		if ( is_plugin_active( (string) $plugin_file ) ) {
			return Response::error( sprintf( 'Plugin "%s" did not deactivate (still active after deactivate_plugins call).', $slug ) );
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Plugin "%s" deactivated.', $slug ),
			'data'    => array(
				'slug'        => $slug,
				'plugin_file' => $plugin_file,
				'active'      => false,
			),
		);
	}

	/**
	 * Returns the JSON Schema for this ability's response.
	 *
	 * @return array<string,mixed> JSON Schema describing the response shape.
	 */
	public function get_output_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'success' ),
			'additionalProperties' => true,
			'properties'           => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'data'    => array(
					'type'       => 'object',
					'properties' => array(
						'slug'        => array( 'type' => 'string' ),
						'plugin_file' => array( 'type' => 'string' ),
						'active'      => array( 'type' => 'boolean' ),
					),
				),
			),
		);
	}
}
