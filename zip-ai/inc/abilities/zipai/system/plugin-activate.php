<?php
/**
 * Plugin Activate — server-side execution.
 *
 * Runs `activate_plugin()` in-process under the App Password user's
 * identity (set by `REST_API::is_basic_authenticated()`). The legacy
 * js_hook delegation was needed when the service used a Sanctum bearer
 * not bound to a WP user; App Password auth is the WP-blessed pattern
 * for third-party services and binds capability checks natively.
 *
 * Idempotent — already-active plugins return success without re-firing
 * activation hooks.
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

class PluginActivate extends Abstract_Ability {

	/**
	 * Whether the ability performs a destructive/irreversible action.
	 *
	 * @var bool
	 */
	protected $is_destructive = true;

	/**
	 * Configure the ability id, label, description, capability, and meta.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/activate-plugin';
		$this->label       = 'Activate Plugin';
		$this->description = 'Activate an already-installed plugin by slug or "folder/file" identifier. '
			. 'Runs `activate_plugin()` in-process under the App-Password user\'s identity. '
			. 'Requires `activate_plugins` capability. '
			. 'Accepts a bare slug ("contact-form-7") or the WP REST plugin id "folder/file" with or without `.php` — extension is normalised.';
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
	 * Tool-type classification used for permission gating.
	 *
	 * @return string
	 */
	public function get_tool_type() {
		return Tool_Types::WRITE;
	}

	/**
	 * JSON Schema describing the ability's accepted input arguments.
	 *
	 * @return array<string,mixed>
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
	 * Activate an installed plugin by slug or "folder/file" identifier.
	 *
	 * @param array<string,mixed> $args Validated input arguments (expects `slug`).
	 * @return array<string,mixed> Success payload, or Response::error() on failure.
	 */
	public function execute( $args ) {
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? sanitize_text_field( $args['slug'] ) : '';
		if ( '' === $slug ) {
			return Response::error( 'Plugin slug is required.' );
		}
		// Folder-segment class accepts real installed-plugin folder names, not only
		// wp.org hyphen-slugs: premium/renamed plugins use underscores and dots
		// (e.g. "Ultimate_VC_Addons", "js_composer"). PluginResolver is the real,
		// safe arbiter (pure get_plugins() key/folder match — the raw slug never
		// reaches the filesystem), so this is a light shape gate only.
		if ( ! preg_match( '#^[a-z0-9][\w.-]*(?:/[a-z0-9._-]+(?:\.php)?)?$#i', $slug ) ) {
			return Response::error( 'Invalid plugin identifier. Use the slug ("contact-form-7") or the WP REST plugin id "folder/file".' );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = PluginResolver::resolve_plugin_file( $slug );
		if ( null === $plugin_file ) {
			return Response::error( sprintf( 'Plugin "%s" is not installed.', $slug ) );
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return array(
				'success' => true,
				'message' => sprintf( 'Plugin "%s" is already active.', $slug ),
				'data'    => array(
					'slug'        => $slug,
					'plugin_file' => $plugin_file,
					'active'      => true,
				),
			);
		}

		$result = activate_plugin( $plugin_file, '', false, true );
		if ( is_wp_error( $result ) ) {
			return Response::error( sprintf( 'Activation failed: %s', $result->get_error_message() ) );
		}

		// Belt-and-braces verify — `activate_plugin()` returns `null` on
		// success but a fatal activation error can leave the plugin
		// inactive without surfacing a WP_Error. Re-read the freshly-written
		// `active_plugins` option directly (single-site activation above) — a
		// side-effect-aware probe that reflects the post-activation state.
		$active_plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $active_plugins ) || ! in_array( $plugin_file, $active_plugins, true ) ) {
			return Response::error( sprintf( 'Plugin "%s" did not activate (no error returned, but plugin remains inactive).', $slug ) );
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Plugin "%s" activated.', $slug ),
			'data'    => array(
				'slug'        => $slug,
				'plugin_file' => $plugin_file,
				'active'      => true,
			),
		);
	}

	/**
	 * JSON Schema describing the ability's response shape.
	 *
	 * @return array<string,mixed>
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
