<?php
/**
 * Install Bundled Plugin — a server-side ability. The website builder uses it
 * in place of `wp plugin install <slug> --activate` and
 * `wp plugin activate <slug>`. RunWpCli's dispatcher now refuses both of
 * those. It redirects them to the browser-proxied `zipai/install-plugin` and
 * `zipai/activate-plugin` js_hook tools. Those tools cannot run from a
 * server-to-server call. There is no browser session for them.
 *
 * This ability runs `Plugin_Upgrader` server-side. This is the same path the
 * admin "Install Now" button uses. A server-side install is acceptable here
 * for these reasons:
 *   1. The caller is the trusted builder backend (Bearer-auth).
 *   2. The acting WP user must have `install_plugins` and `activate_plugins`.
 *      `setup_user_context()` resolves this user.
 *   3. The slug is limited to lower-case alphanumerics and hyphens.
 *      These are the WP.org plugin-directory naming rules.
 *   4. The code honours `DISALLOW_FILE_MODS`.
 *
 * The ability is hidden from the LLM catalog (`meta.visibility = internal`).
 * So chat-driven installs still flow through the browser-proxied path.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\Builder;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Core\Plugin_Abilities_Toggler;

defined( 'ABSPATH' ) || exit;

/**
 * Ability: install (if missing) and activate a wp.org plugin slug.
 */
class InstallBundledPlugin extends Abstract_Ability {

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
		$this->id          = 'zipai/install-bundled-plugin';
		$this->label       = 'Install Bundled Plugin (server-side)';
		$this->description = 'Install and optionally activate a wp.org plugin slug. Internal builder-side replacement for `wp plugin install <slug> --activate` / `wp plugin activate <slug>` — those wp-cli paths route to browser-proxied js_hook tools which cannot run from a Laravel server-to-server call.';
		// Caller must hold BOTH caps. `install_plugins` is checked again
		// inside `Plugin_Upgrader::install()` by core; we re-check here so
		// any future direct caller still gets the same gate.
		$this->capability = 'install_plugins';

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
					'description' => 'wp.org plugin slug (lower-case alphanumerics and hyphens, e.g. "contact-form-7"). NOT a `folder/file.php` shape.',
					'pattern'     => '^[a-z0-9][a-z0-9-]{0,62}$',
				),
				'activate' => array(
					'type'        => 'boolean',
					'description' => 'Activate after install. Default true. If the plugin is already installed but inactive, activation still runs.',
					'default'     => true,
				),
			),
		);
	}

	/**
	 * Installs (if missing) and optionally activates a wp.org plugin slug.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		// Validate the RAW input against the slug regex first — `sanitize_key`
		// silently strips slashes / dots / spaces which would otherwise let
		// shapes like "foo/bar.php" pass and then fail later at wp.org lookup
		// with a misleading "Plugin not found" message.
		$raw_slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? $args['slug'] : '';
		$activate = isset( $args['activate'] ) ? (bool) $args['activate'] : true;

		if ( '' === $raw_slug || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,62}$/', $raw_slug ) ) {
			return self::coded_error( 'Invalid slug — expected wp.org plugin slug (a–z, 0–9, hyphens).', 'invalid_slug' );
		}
		$slug = sanitize_key( $raw_slug );

		// File-mods gate first — if file mods are off, capability state is moot.
		if ( ! wp_is_file_mod_allowed( 'zipai_install_bundled_plugin' ) ) {
			return self::coded_error( 'File modifications are disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter).', 'file_mods_disabled' );
		}

		// Capability double-check — defense-in-depth on top of the Abilities
		// API `permission_callback`.
		if ( ! current_user_can( 'install_plugins' ) ) {
			return self::coded_error( 'You do not have install_plugins capability.', 'insufficient_capability' );
		}
		if ( $activate && ! current_user_can( 'activate_plugins' ) ) {
			return self::coded_error( 'You do not have activate_plugins capability.', 'insufficient_capability' );
		}

		// Network-admin gate — installing into a multisite from a sub-site is
		// surprising; restrict to network admins.
		if ( is_multisite() && ! is_super_admin() ) {
			return self::coded_error( 'On multisite, only network super-admins may install plugins.', 'multisite_super_admin_required' );
		}

		// Bring in admin-only loaders.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
		// Plugin_Upgrader may call request_filesystem_credentials() through
		// WP_Upgrader internals. Load file.php explicitly in non-admin
		// contexts to avoid fatal "undefined function" during server-side
		// installs invoked server-to-server.
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Fast path: already installed → skip install, jump to activate.
		$existing_file = Helper::find_plugin_file_for_slug( $slug );

		if ( null === $existing_file ) {
			// Not installed — fetch package URL + run install.
			$information = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);
			if ( is_wp_error( $information ) ) {
				return self::coded_error( 'wp.org lookup failed: ' . $information->get_error_message(), 'wporg_lookup_failed' );
			}
			$package = is_object( $information ) && isset( $information->download_link ) && is_string( $information->download_link ) ? $information->download_link : '';
			if ( '' === $package ) {
				return self::coded_error( sprintf( 'wp.org returned no download link for "%s".', $slug ), 'wporg_missing_download_link' );
			}

			// Hard-pin the download host, same as the system/ install
			// abilities: the API response rides plain-parseable JSON, and the
			// upgrader runs whatever archive URL it is handed.
			$download_host = wp_parse_url( $package, PHP_URL_HOST );
			if ( 'downloads.wordpress.org' !== $download_host ) {
				return self::coded_error(
					sprintf( 'Refusing to install "%s": download host "%s" is not downloads.wordpress.org.', $slug, is_string( $download_host ) ? $download_host : '' ),
					'untrusted_download_host'
				);
			}

			$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( $package );
			if ( is_wp_error( $result ) ) {
				return self::coded_error( 'install failed: ' . $result->get_error_message(), 'install_failed' );
			}
			if ( ! $result ) {
				return self::coded_error( 'install failed (unknown error).', 'install_failed' );
			}
			$existing_file = $upgrader->plugin_info();
			if ( ! $existing_file ) {
				$existing_file = Helper::find_plugin_file_for_slug( $slug );
			}
			if ( ! $existing_file ) {
				return self::coded_error( 'install succeeded but plugin file could not be resolved.', 'plugin_file_unresolved' );
			}
		}

		$was_active = is_plugin_active( $existing_file ) || is_plugin_active_for_network( $existing_file );
		if ( $activate && ! $was_active ) {
			$activation = activate_plugin( $existing_file );
			if ( is_wp_error( $activation ) ) {
				return self::coded_error( 'activation failed: ' . $activation->get_error_message(), 'activation_failed' );
			}
			// `activate_plugin()` returns null on success OR when an
			// `update_option(active_plugins)` filter silently rejected the
			// write. Verify post-state so silent-failure surfaces as a real
			// error instead of a false success. Read the option stores directly
			// (equivalent to is_plugin_active/is_plugin_active_for_network) so the
			// re-check reflects the post-activation runtime state.
			$active_plugins  = (array) get_option( 'active_plugins', array() );
			$network_plugins = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
			$still_inactive  = ! in_array( $existing_file, $active_plugins, true ) && ! isset( $network_plugins[ $existing_file ] );
			if ( $still_inactive ) {
				return self::coded_error(
					sprintf( 'activate_plugin() returned no error for "%s" but plugin is still inactive — likely an `update_option(active_plugins)` filter rejected the write.', $slug ),
					'activation_rejected'
				);
			}
		}

		$toggle_result = Plugin_Abilities_Toggler::enable_for_slug( $slug );

		// Read the version off the plugin header rather than from get_plugins(),
		// whose cache can predate an install this call just ran. Callers gate
		// features on a minimum version, so a stale reading would gate on the
		// wrong number. Additive field — the rest of the payload is unchanged.
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $existing_file, false, false );
		$version     = $plugin_data['Version'];

		return Response::success(
			array(
				'slug'    => $slug,
				'file'    => $existing_file,
				'version' => $version,
				'active'  => $activate ? ( is_plugin_active( $existing_file ) || is_plugin_active_for_network( $existing_file ) ) : $was_active,
				'toggles' => $toggle_result,
				'message' => $activate
					? sprintf( 'Plugin "%s" installed and active.', $slug )
					: sprintf( 'Plugin "%s" installed.', $slug ),
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

	/**
	 * Error payload with machine-readable code for callers.
	 *
	 * @param string $message Human-readable error.
	 * @param string $code    Stable error code.
	 * @return array<string,mixed>
	 */
	private static function coded_error( string $message, string $code ): array {
		return array(
			'success'    => false,
			'error'      => $message,
			'error_code' => $code,
		);
	}
}
