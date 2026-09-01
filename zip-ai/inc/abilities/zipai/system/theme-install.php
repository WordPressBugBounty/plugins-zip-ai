<?php
/**
 * Theme Install — server-side execution.
 *
 * Runs the install in-process via WP core's `Theme_Upgrader` under the
 * App-Password user's identity (set by `REST_API::is_basic_authenticated()`
 * before this handler dispatches). Capability checks run as that user via
 * `current_user_can()` so unauthorised roles cannot install.
 *
 * Pre-App-Password versions of this ability returned a js_hook envelope and
 * deferred to the browser's `admin-ajax.php?action=install-theme` action.
 * App Password auth IS the WP-blessed pattern for third-party services —
 * server-side install with a user-bound credential returns the real
 * success/error in one round-trip and removes the "dispatched →
 * maybe-done-next-turn" UX gap. (The REST themes controller has no POST
 * route, but `Theme_Upgrader` is the in-process API the admin-ajax handler
 * itself wraps, so no browser round-trip is needed.) Mirrors `PluginInstall`.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

use Theme_Upgrader;
use WP_Ajax_Upgrader_Skin;
use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ThemeInstall extends Abstract_Ability {

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
		$this->id          = 'zipai/install-theme';
		$this->label       = 'Install Theme';
		$this->description = 'Install a theme from the WordPress.org repository by slug. '
			. 'Runs in-process via `Theme_Upgrader` under the App-Password user\'s identity. '
			. 'The user must have the `install_themes` capability (and `switch_themes` when `status: "active"`). '
			. 'Returns synchronously with the actual result — no browser round-trip.';
		$this->capability  = 'install_themes';

		$this->meta = array(
			'tool_type' => Tool_Types::WRITE,
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
				'slug'   => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9][a-z0-9-]*$',
					'description' => 'Theme slug on WordPress.org (e.g. "twentytwentyfour", "generatepress"). Lowercase, hyphen-separated. NOT the display name.',
				),
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'inactive', 'active' ),
					'default'     => 'inactive',
					'description' => 'Optional target state after install. "active" switches the site to this theme and requires `switch_themes` capability.',
				),
			),
		);
	}

	/**
	 * Installs a WordPress.org theme by slug, optionally activating it.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		$raw_slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? $args['slug'] : '';
		if ( '' === trim( $raw_slug ) ) {
			return Response::error( 'Theme slug is required (e.g. "twentytwentyfour").' );
		}
		$slug = sanitize_key( $raw_slug );
		// sanitize_key silently strips spaces/casing/punctuation — an LLM emitting
		// "Bad Theme!" should fail loudly, not get rewritten. Reject when
		// sanitisation changed the input or the result isn't a valid .org slug.
		if ( $slug !== $raw_slug || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
			return Response::error( 'Invalid theme slug. Use the lowercase, hyphen-separated WordPress.org slug (e.g. "twentytwentyfour").' );
		}

		$want_active = isset( $args['status'] ) && 'active' === $args['status'];
		if ( $want_active && ! current_user_can( 'switch_themes' ) ) {
			return Response::error( 'Connected user lacks `switch_themes` capability — cannot install with status=active.' );
		}

		// Idempotent: an already-installed theme skips the download and just
		// (optionally) switches — mirrors InstallBundledTheme. wp_get_theme()
		// lives in wp-includes (always loaded), so the idempotent and
		// activate-only paths need no admin includes, filesystem credentials, or
		// the install-only gates below — switch_theme() modifies no files.
		$existing = wp_get_theme( $slug );
		if ( ! $existing->exists() ) {
			// Install-only gates — they apply to the file-writing download, not
			// to activating a theme that is already present. Honor
			// DISALLOW_FILE_MODS, and on multisite restrict installing to
			// network super-admins.
			if ( ! wp_is_file_mod_allowed( 'zipai_install_theme' ) ) {
				return Response::error( 'Theme installation is disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter).' );
			}
			if ( is_multisite() && ! is_super_admin() ) {
				return Response::error( 'On multisite, only network super-admins may install themes.' );
			}

			// WP-Admin upgrader machinery isn't auto-loaded in REST context.
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

			// Initialise WP_Filesystem. On FTP-mode hosts without stored
			// credentials this fails — return a clear, actionable error
			// instead of a deep upgrader stack trace.
			if ( ! WP_Filesystem() ) {
				return Response::error(
					'Filesystem credentials required for theme install. '
					. 'Configure FS_METHOD or store FTP credentials in wp-config.php.'
				);
			}

			$api = themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				) 
			);
			if ( is_wp_error( $api ) ) {
				return Response::error( sprintf( 'Could not fetch theme "%s" from WP.org: %s', $slug, $api->get_error_message() ) );
			}
			$download_link = is_object( $api ) && isset( $api->download_link ) && is_string( $api->download_link ) ? $api->download_link : '';
			if ( '' === $download_link ) {
				return Response::error( sprintf( 'Theme "%s" has no download link on WP.org.', $slug ) );
			}

			// Host-pin the WP.org-supplied download URL (same threat model as
			// PluginInstall): the link is network-supplied data, so only honour
			// downloads.wordpress.org archives.
			$download_host = wp_parse_url( $download_link, PHP_URL_HOST );
			if ( 'downloads.wordpress.org' !== $download_host ) {
				return Response::error(
					sprintf(
						'Refusing to install theme "%s": download host "%s" is not downloads.wordpress.org.',
						$slug,
						is_string( $download_host ) ? $download_host : ''
					)
				);
			}

			$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( $download_link );

			if ( is_wp_error( $result ) ) {
				return Response::error( sprintf( 'Install failed: %s', $result->get_error_message() ) );
			}
			// install() returns null/false on certain failure paths — surface as
			// an explicit failure rather than letting it look like success.
			if ( ! $result ) {
				return Response::error( 'Theme install did not complete successfully.' );
			}

			wp_clean_themes_cache();
			$existing = wp_get_theme( $slug );
			if ( ! $existing->exists() ) {
				return Response::error( 'Theme installed but could not be resolved afterwards.' );
			}
		}

		$is_active = ( wp_get_theme()->get_stylesheet() === $existing->get_stylesheet() );
		if ( $want_active && ! $is_active ) {
			switch_theme( $existing->get_stylesheet() );
			// switch_theme() is void — confirm the switch actually took, so a
			// filter that blocked it can't be reported as success.
			$is_active = ( wp_get_theme()->get_stylesheet() === $existing->get_stylesheet() );
			if ( ! $is_active ) {
				return Response::error( 'Theme installed but activation did not take effect.' );
			}
		}

		return array(
			'success' => true,
			'message' => sprintf(
				'Theme "%s" installed%s.',
				$slug,
				$want_active && $is_active ? ' and activated' : ''
			),
			'data'    => array(
				'slug'       => $slug,
				'stylesheet' => $existing->get_stylesheet(),
				'active'     => $is_active,
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
						'slug'       => array( 'type' => 'string' ),
						'stylesheet' => array( 'type' => 'string' ),
						'active'     => array( 'type' => 'boolean' ),
					),
				),
			),
		);
	}
}
