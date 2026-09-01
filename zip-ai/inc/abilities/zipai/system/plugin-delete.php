<?php
/**
 * Plugin Delete — server-side execution.
 *
 * Atomic plugin removal — if the plugin is active, this deactivates it
 * first then deletes the files. Single tool call, single todo. Runs
 * in-process under the App Password user's identity via WP core's
 * `delete_plugins()`.
 *
 * The response includes `data.deactivated_first` so the LLM can mention
 * to the user whether a deactivate step was needed.
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

class PluginDelete extends Abstract_Ability {

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
		$this->id          = 'zipai/delete-plugin';
		$this->label       = 'Delete Plugin';
		$this->description = 'Permanently remove a plugin by slug or "folder/file" identifier. Atomic — deactivates first when active. '
			. 'Runs in-process under the App-Password user\'s identity. '
			. 'Requires `delete_plugins` capability. '
			. 'Returns `data.deactivated_first` so you can mention whether a deactivate step was needed.';
		$this->capability  = 'delete_plugins';

		$this->meta = array(
			'tool_type'          => Tool_Types::DELETE,
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
		return Tool_Types::DELETE;
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
	 * Deletes a plugin by slug, deactivating it first when active.
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

		if ( ! wp_is_file_mod_allowed( 'zipai_delete_plugin' ) ) {
			return Response::error( 'Plugin deletion is disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter).' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = PluginResolver::resolve_plugin_file( $slug );
		if ( null === $plugin_file ) {
			return Response::error( sprintf( 'Plugin "%s" is not installed.', $slug ) );
		}

		// Self-preservation: this ability runs INSIDE ZIP AI — deleting it
		// here removes the assistant itself (chat, tools, this connection)
		// mid-action. Fail closed; wp-admin → Plugins is the supported path.
		if ( PluginResolver::is_self( $plugin_file ) ) {
			return Response::error(
				sprintf( 'Refused: "%s" is the ZIP AI plugin, which runs this AI assistant — deleting it from here would remove the chat, all tools, and this connection mid-action.', $slug ),
				'Leave ZIP AI installed and continue the remaining work. If the user explicitly wants ZIP AI removed, tell them to delete it in wp-admin → Plugins.'
			);
		}

		// Atomic-from-the-LLM's-POV: deactivate first when active so the
		// caller doesn't have to chain two tool calls. WP core's
		// `delete_plugins()` refuses with `could_not_remove_plugin` if
		// the plugin is still active.
		$deactivated_first = false;
		if ( is_plugin_active( $plugin_file ) ) {
			deactivate_plugins( array( $plugin_file ), true );
			$deactivated_first = true;
		}

		// `delete_plugins()` requires `WP_Filesystem` to be available.
		// On FTP-mode hosts without stored creds it returns `null` — we
		// surface that as a clear error rather than letting it look
		// like success.
		if ( ! WP_Filesystem() ) {
			return Response::error(
				'Filesystem credentials required for plugin delete. '
				. 'Configure FS_METHOD or store FTP credentials in wp-config.php.'
			);
		}

		$result = delete_plugins( array( $plugin_file ) );
		if ( is_wp_error( $result ) ) {
			return Response::error( sprintf( 'Delete failed: %s', $result->get_error_message() ) );
		}
		if ( null === $result ) {
			return Response::error( 'Filesystem unavailable — plugin not deleted.' );
		}

		wp_clean_plugins_cache();

		return array(
			'success' => true,
			'message' => sprintf(
				'Plugin "%s" deleted%s.',
				$slug,
				$deactivated_first ? ' (deactivated first)' : ''
			),
			'data'    => array(
				'slug'              => $slug,
				'plugin_file'       => $plugin_file,
				'deactivated_first' => $deactivated_first,
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
						'slug'              => array( 'type' => 'string' ),
						'plugin_file'       => array( 'type' => 'string' ),
						'deactivated_first' => array( 'type' => 'boolean' ),
					),
				),
			),
		);
	}
}
