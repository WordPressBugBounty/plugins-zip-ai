<?php
/**
 * Plugin Install — server-side execution.
 *
 * Runs the install in-process against WP core's `Plugin_Upgrader` under
 * the App Password user's identity (set by `REST_API::is_basic_authenticated()`
 * before this handler dispatches). Capability checks run as that user
 * via `current_user_can()` so unauthorised roles cannot install.
 *
 * App Password auth is the WP-blessed pattern for third-party services:
 * a server-side install under a user-bound credential is fully compliant
 * and returns real success/error in one round-trip.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

use Plugin_Upgrader;
use WP_Ajax_Upgrader_Skin;
use ZipAI\MCP\Classes\Abilities\Zipai\System\PluginResolver;
use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PluginInstall extends Abstract_Ability {

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
		$this->id          = 'zipai/install-plugin';
		$this->label       = 'Install Plugin';
		$this->description = 'Install a plugin from the WordPress.org repository by slug. '
			. 'Runs in-process via `Plugin_Upgrader` under the App-Password user\'s identity. '
			. 'The user must have the `install_plugins` capability (and `activate_plugins` when `status: "active"`). '
			. 'Returns synchronously with the actual result — no browser round-trip.';
		$this->capability  = 'install_plugins';

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
					'description' => 'Plugin slug on WordPress.org (e.g. "contact-form-7", "wordpress-seo"). Lowercase, hyphen-separated. NOT the full plugin path or display name.',
				),
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'inactive', 'active' ),
					'default'     => 'inactive',
					'description' => 'Optional target state after install. "active" requires `activate_plugins` capability too.',
				),
			),
		);
	}

	/**
	 * Installs a WordPress.org plugin by slug, optionally activating it.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		$raw_slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? $args['slug'] : '';
		if ( '' === trim( $raw_slug ) ) {
			return Response::error( 'Plugin slug is required (e.g. "contact-form-7").' );
		}
		$slug = sanitize_key( $raw_slug );
		// sanitize_key silently strips spaces/casing/punctuation — but an LLM
		// emitting "Bad Slug!" or "../evil" should fail loudly, not get
		// silently rewritten to "badslug" / "evil". Reject when sanitisation
		// changed the input or the result doesn't match the .org slug shape.
		if ( $slug !== $raw_slug || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
			return Response::error( 'Invalid plugin slug. Use the lowercase, hyphen-separated WordPress.org slug (e.g. "contact-form-7").' );
		}

		$want_active = isset( $args['status'] ) && 'active' === $args['status'];
		if ( $want_active && ! current_user_can( 'activate_plugins' ) ) {
			return Response::error( 'Connected user lacks `activate_plugins` capability — cannot install with status=active.' );
		}

		// Resolve installed state first — needs only plugin.php (not auto-loaded
		// in REST context).
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Already-installed fast path — mirror install-bundled-plugin.php's
		// resolver check. WP core's Plugin_Upgrader::install() aborts with
		// `folder_exists` on an installed plugin, so resolving first turns a
		// redundant install into an idempotent success (optionally activating)
		// and skips the filesystem + WP.org round-trips entirely.
		//
		// Match ONLY the folder component (resolve_installed_folder), not a
		// root-level single-file plugin whose basename happens to equal the
		// slug — install takes a wp.org folder slug, so a bare-filename hit
		// (e.g. a "redirects.php" snippet for slug "redirects") is a false
		// positive that would report/activate the wrong file.
		$existing_file = PluginResolver::resolve_installed_folder( $slug );
		if ( null !== $existing_file ) {
			return $this->finalize_install( $slug, $existing_file, $want_active, true );
		}

		// Not installed — the download + extract below writes files, so gate it
		// on DISALLOW_FILE_MODS here (not before the fast path: activating an
		// already-installed plugin modifies no files, so a locked-down host must
		// still get the idempotent success). Parity with ThemeInstall.
		if ( ! wp_is_file_mod_allowed( 'zipai_install_plugin' ) ) {
			return Response::error( 'Plugin installation is disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter).' );
		}
		if ( is_multisite() && ! is_super_admin() ) {
			return Response::error( 'On multisite, only network super-admins may install plugins.' );
		}

		// Load the rest of the upgrader machinery (not auto-loaded in REST
		// context) only now that we actually need to download + install.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

		// Initialise WP_Filesystem. On FTP-mode hosts without stored
		// credentials this fails — return a clear, actionable error
		// instead of a deep upgrader stack trace.
		if ( ! WP_Filesystem() ) {
			return Response::error(
				'Filesystem credentials required for plugin install. '
				. 'Configure FS_METHOD or store FTP credentials in wp-config.php.'
			);
		}

		// Fetch plugin info from WP.org to obtain the download_link.
		$api = plugins_api( 'plugin_information', array( 'slug' => $slug ) );
		if ( is_wp_error( $api ) ) {
			return Response::error( sprintf( 'Could not fetch plugin "%s" from WP.org: %s', $slug, $api->get_error_message() ) );
		}
		$download_link = is_object( $api ) && isset( $api->download_link ) && is_string( $api->download_link ) ? $api->download_link : '';
		if ( '' === $download_link ) {
			return Response::error( sprintf( 'Plugin "%s" has no download link on WP.org.', $slug ) );
		}

		// Host allowlist on the WP.org-supplied download URL. `plugins_api()`
		// hits `api.wordpress.org`, but the returned `download_link` is data
		// the network gave us — DNS hijack, TLS-MITM on a host with
		// `ZIPAI_MCP_DISABLE_SSL_VERIFY`, or a transient poisoning of the
		// `plugins_api_result` filter could otherwise smuggle an
		// attacker-controlled archive into `Plugin_Upgrader::install()`
		// under super-admin identity. Hard-pin the host so only
		// `downloads.wordpress.org` archives are honoured.
		$download_host = wp_parse_url( $download_link, PHP_URL_HOST );
		if ( 'downloads.wordpress.org' !== $download_host ) {
			return Response::error(
				sprintf(
					'Refusing to install plugin "%s": download host "%s" is not downloads.wordpress.org.',
					$slug,
					is_string( $download_host ) ? $download_host : ''
				)
			);
		}

		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $download_link );

		if ( is_wp_error( $result ) ) {
			// A `folder_exists` here means the resolver fast path did NOT see the
			// plugin (get_plugins() skips a directory whose main file lacks a
			// valid header), yet a same-named folder is on disk — a partial or
			// corrupted prior install. Retrying installs nothing, so say what is
			// actually wrong instead of the generic upgrader string (which reads
			// as a transient failure and drives a useless "retry"). We do NOT
			// auto-overwrite: clobbering an unrecognised folder risks destroying
			// user files or an unrelated plugin.
			if ( 'folder_exists' === $result->get_error_code() ) {
				// The blocking folder is the archive's top-level dir (WP core puts
				// its full path in the error data), which is not guaranteed to
				// equal the requested slug — a plugin whose zip top-folder differs
				// would otherwise point the user at a path that does not exist.
				// Report the real folder so the remediation actually unblocks.
				$conflict_dir = $result->get_error_data();
				$folder       = is_string( $conflict_dir ) && '' !== $conflict_dir ? basename( $conflict_dir ) : $slug;
				return Response::error(
					sprintf(
						'A "%1$s" folder already exists in wp-content/plugins but is not a recognisable plugin (likely a partial or corrupted earlier install). Delete wp-content/plugins/%1$s, then install again. Reinstalling will not overwrite it.',
						$folder
					)
				);
			}
			return Response::error( sprintf( 'Install failed: %s', $result->get_error_message() ) );
		}
		// `install()` returns null on certain failure paths (filesystem
		// init reset mid-flight, archive extraction issue). Surface as
		// an explicit failure rather than letting it look like success.
		if ( true !== $result ) {
			return Response::error( 'Plugin install did not complete successfully.' );
		}

		$plugin_file = $upgrader->plugin_info();
		if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
			return Response::error( 'Plugin installed but main file path could not be determined.' );
		}

		return $this->finalize_install( $slug, $plugin_file, $want_active, false );
	}

	/**
	 * Activates (when requested and not already active) an installed plugin and
	 * builds the success response. Shared by the fresh-install and
	 * already-installed paths so the activate + return logic lives in one place.
	 *
	 * @param string $slug              Sanitised plugin slug.
	 * @param string $plugin_file       Resolved main plugin file (folder/file.php).
	 * @param bool   $want_active       Whether status=active was requested.
	 * @param bool   $already_installed Whether the plugin was already on disk.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	private function finalize_install( $slug, $plugin_file, $want_active, $already_installed ) {
		// On a fresh install, do NOT trust a stale `active_plugins` entry —
		// is_plugin_active() only reads that option, and if the folder was
		// removed outside WP while active, core hasn't pruned it in this REST
		// request. Treating it as active would skip activate_plugin() and with it
		// validate_plugin_requirements() (the activation hooks are suppressed here
		// anyway via $silent=true), yet still report "installed and activated".
		// Force the activation to actually run.
		$is_active = $already_installed ? is_plugin_active( $plugin_file ) : false;
		$activated = false;
		if ( $want_active && ! $is_active ) {
			$activate_result = activate_plugin( $plugin_file, '', false, true );
			if ( is_wp_error( $activate_result ) ) {
				return Response::error( sprintf( 'Installed but activation failed: %s', $activate_result->get_error_message() ) );
			}
			$is_active = is_plugin_active( $plugin_file );
			// Verify the write actually took — an `active_plugins`/`activate_plugin`
			// filter can reject silently without a WP_Error. Parity with
			// ThemeInstall / InstallBundledPlugin.
			if ( ! $is_active ) {
				return Response::error( sprintf( 'Plugin "%s" installed but could not be activated.', $slug ) );
			}
			$activated = true;
		}

		// Clear the cache only when something actually changed — a fresh install
		// or an activation this call performed. A pure already-installed,
		// already-active no-op skips it (parity with ThemeInstall).
		if ( ! $already_installed || $activated ) {
			wp_clean_plugins_cache();
		}

		return array(
			'success' => true,
			'message' => sprintf(
				'Plugin "%s" %s%s.',
				$slug,
				$already_installed ? 'already installed' : 'installed',
				// Report intent + state, not state alone: never claim "and
				// activated" for a default-status call on an already-active plugin
				// (matches ThemeInstall).
				( $want_active && $is_active ) ? ' and activated' : ''
			),
			'data'    => array(
				'slug'        => $slug,
				'plugin_file' => $plugin_file,
				'active'      => $is_active,
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
