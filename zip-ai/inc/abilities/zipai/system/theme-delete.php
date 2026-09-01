<?php
/**
 * Theme Delete — server-side execution.
 *
 * Removes a theme's files in-process via WP core's `delete_theme()` under the
 * App-Password user's identity. Mirrors `PluginDelete`. The theme MUST NOT be
 * the active theme (as stylesheet or as the parent template of an active
 * child) — that is refused here with a clear error before any filesystem work.
 *
 * Pre-App-Password versions returned a js_hook envelope and deferred to the
 * browser's `admin-ajax.php?action=delete-theme`. Server-side execution under
 * a user-bound credential returns the real success/error in one round-trip.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ThemeDelete extends Abstract_Ability {

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
		$this->id          = 'zipai/delete-theme';
		$this->label       = 'Delete Theme';
		$this->description = 'Permanently delete a theme\'s files. The theme must not be active — switch to a different theme first if needed. '
			. 'Runs in-process via `delete_theme()` under the App-Password user\'s identity. '
			. 'Requires the `delete_themes` capability. Returns synchronously with the actual result.';
		$this->capability  = 'delete_themes';

		$this->meta = array(
			'tool_type' => Tool_Types::DELETE,
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
					'pattern'     => '^[a-zA-Z0-9][a-zA-Z0-9_-]*$',
					'description' => 'Theme stylesheet slug ("twentytwentyfour"). The folder name under wp-content/themes/.',
				),
			),
		);
	}

	/**
	 * Deletes a non-active theme's files by slug.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		$raw_slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? $args['slug'] : '';
		$slug     = trim( $raw_slug );
		if ( '' === $slug || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $slug ) ) {
			return Response::error( 'Invalid theme slug. Use the theme folder name under wp-content/themes/.' );
		}

		if ( ! wp_is_file_mod_allowed( 'zipai_delete_theme' ) ) {
			return Response::error( 'Theme deletion is disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter).' );
		}
		if ( is_multisite() && ! is_super_admin() ) {
			return Response::error( 'On multisite, only network super-admins may delete themes.' );
		}

		if ( ! function_exists( 'delete_theme' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return Response::error( sprintf( 'Theme "%s" is not installed.', $slug ) );
		}

		// Never delete the active theme — neither the active stylesheet nor the
		// parent template of an active child theme. WP core refuses this too;
		// stopping it here gives the caller a clear reason instead of a generic
		// failure.
		$active = wp_get_theme();
		if (
			$theme->get_stylesheet() === $active->get_stylesheet()
			|| $theme->get_stylesheet() === $active->get_template()
		) {
			return Response::error( sprintf( 'Cannot delete the active theme "%s". Switch to another theme first.', $slug ) );
		}

		if ( ! WP_Filesystem() ) {
			return Response::error(
				'Filesystem credentials required for theme deletion. '
				. 'Configure FS_METHOD or store FTP credentials in wp-config.php.'
			);
		}

		$result = delete_theme( $theme->get_stylesheet() );

		if ( is_wp_error( $result ) ) {
			return Response::error( sprintf( 'Delete failed: %s', $result->get_error_message() ) );
		}
		// delete_theme() returns null when filesystem credentials are required
		// and false on an empty/invalid stylesheet — surface both as failures.
		if ( null === $result ) {
			return Response::error( 'Theme deletion requires filesystem credentials that are not available.' );
		}
		if ( true !== $result ) {
			return Response::error( 'Theme deletion did not complete successfully.' );
		}

		wp_clean_themes_cache();

		// Confirm the files are actually gone rather than trusting the return.
		if ( wp_get_theme( $slug )->exists() ) {
			return Response::error( sprintf( 'Theme "%s" still present after deletion.', $slug ) );
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Theme "%s" deleted.', $slug ),
			'data'    => array( 'slug' => $slug ),
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
						'slug' => array( 'type' => 'string' ),
					),
				),
			),
		);
	}
}
