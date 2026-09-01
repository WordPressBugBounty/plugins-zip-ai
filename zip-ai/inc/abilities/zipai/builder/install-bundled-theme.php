<?php
/**
 * Install Bundled Theme — server-side ability used by the website builder
 * in place of `wp theme install <slug> --activate`, which is now refused by
 * RunWpCli's dispatcher and redirected to the browser-proxied
 * `zipai/install-theme` js_hook tool — unusable from a server-to-server call.
 *
 * Sister to InstallBundledPlugin; same Bearer-auth, capability, slug, and
 * DISALLOW_FILE_MODS gates. `theme activate` was never blocked (it's a
 * pure in-process WP API call), so callers who only need to activate an
 * already-installed theme can still use `wp theme activate` via RunWpCli.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\Builder;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Ability: install (if missing) and activate a wp.org theme slug.
 */
class InstallBundledTheme extends Abstract_Ability {

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
		$this->id          = 'zipai/install-bundled-theme';
		$this->label       = 'Install Bundled Theme (server-side)';
		$this->description = 'Install and optionally activate a wp.org theme slug. Internal builder-side replacement for `wp theme install <slug> --activate` — the wp-cli path is redirected to a browser-proxied js_hook tool which cannot run from a Laravel server-to-server call.';
		$this->capability  = 'install_themes';

		$this->meta['visibility'] = 'internal';
	}

	/**
	 * Returns the tool-type classification for this ability.
	 *
	 * @return string One of the Tool_Types constants.
	 */
	public function get_tool_type() {
		return Tool_Types::ACTION;
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
				'slug'     => array(
					'type'        => 'string',
					'description' => 'wp.org theme slug (lower-case alphanumerics and hyphens, e.g. "spectra-one").',
					'pattern'     => '^[a-z0-9][a-z0-9-]{0,62}$',
				),
				'activate' => array(
					'type'        => 'boolean',
					'description' => 'Activate after install. Default true.',
					'default'     => true,
				),
			),
		);
	}

	/**
	 * Installs (if missing) and optionally activates a wp.org theme slug.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		// Validate raw input first — see InstallBundledPlugin::execute for why.
		$raw_slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? $args['slug'] : '';
		$activate = isset( $args['activate'] ) ? (bool) $args['activate'] : true;

		if ( '' === $raw_slug || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,62}$/', $raw_slug ) ) {
			return Response::error( 'Invalid slug — expected wp.org theme slug (a–z, 0–9, hyphens).' );
		}
		$slug = sanitize_key( $raw_slug );

		if ( ! wp_is_file_mod_allowed( 'zipai_install_theme' ) ) {
			return Response::error( 'File modifications are disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter).' );
		}

		if ( ! current_user_can( 'install_themes' ) ) {
			return Response::error( 'You do not have install_themes capability.' );
		}
		if ( $activate && ! current_user_can( 'switch_themes' ) ) {
			return Response::error( 'You do not have switch_themes capability.' );
		}
		if ( is_multisite() && ! is_super_admin() ) {
			return Response::error( 'On multisite, only network super-admins may install themes.' );
		}

		// Load admin-only deps.
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		if ( ! class_exists( 'Theme_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}

		$existing = wp_get_theme( $slug );
		if ( ! $existing->exists() ) {
			$information = themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);
			if ( is_wp_error( $information ) ) {
				return Response::error( 'wp.org lookup failed: ' . $information->get_error_message() );
			}
			$package = is_object( $information ) && isset( $information->download_link ) && is_string( $information->download_link ) ? $information->download_link : '';
			if ( '' === $package ) {
				return Response::error( sprintf( 'wp.org returned no download link for "%s".', $slug ) );
			}

			// Hard-pin the download host, same as the system/ install
			// abilities: the upgrader runs whatever archive URL it is handed.
			$download_host = wp_parse_url( $package, PHP_URL_HOST );
			if ( 'downloads.wordpress.org' !== $download_host ) {
				return Response::error(
					sprintf( 'Refusing to install "%s": download host "%s" is not downloads.wordpress.org.', $slug, is_string( $download_host ) ? $download_host : '' )
				);
			}

			$upgrader = new \Theme_Upgrader( new \WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( $package );
			if ( is_wp_error( $result ) ) {
				return Response::error( 'install failed: ' . $result->get_error_message() );
			}
			if ( ! $result ) {
				return Response::error( 'install failed (unknown error).' );
			}
			$existing = wp_get_theme( $slug );
			if ( ! $existing->exists() ) {
				return Response::error( 'install succeeded but theme could not be resolved.' );
			}
		}

		$was_active = ( wp_get_theme()->get_stylesheet() === $existing->get_stylesheet() );
		if ( $activate && ! $was_active ) {
			switch_theme( $existing->get_stylesheet() );
		}

		return Response::success(
			array(
				'slug'       => $slug,
				'stylesheet' => $existing->get_stylesheet(),
				'active'     => $activate ? ( wp_get_theme()->get_stylesheet() === $existing->get_stylesheet() ) : $was_active,
				'message'    => $activate
					? sprintf( 'Theme "%s" installed and active.', $slug )
					: sprintf( 'Theme "%s" installed.', $slug ),
			)
		);
	}

	/**
	 * Returns the JSON Schema for this ability's response.
	 *
	 * @return array<string,mixed> JSON Schema describing the response shape.
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'object' ),
			),
		);
	}
}
