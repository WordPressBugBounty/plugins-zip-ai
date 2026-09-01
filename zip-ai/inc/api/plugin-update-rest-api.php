<?php
/**
 * Plugin Update REST API — lets a ZIP AI screen offer a one-click "Update"
 * when something it needs is installed but too old to work with.
 *
 * Cookie/nonce authenticated and gated on `update_plugins`, the same capability
 * WordPress's own Plugins screen requires. This route can only UPDATE a plugin
 * that is already installed — there is no install path here, so it cannot be
 * used to pull new code onto the site.
 *
 * @since 0.0.9
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Api;

use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Core\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the one-click plugin update route.
 */
class Plugin_Update_REST_API {

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'zip-ai/v1',
			'/plugin/update',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_plugin' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'slug' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Only a user who could run this update from the Plugins screen may run it
	 * from here.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'update_plugins' );
	}

	/**
	 * Update one already-installed plugin to its latest published version.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function update_plugin( $request ) {
		$raw_slug = $request->get_param( 'slug' );
		$raw_slug = is_string( $raw_slug ) ? $raw_slug : '';

		// Same wp.org slug shape the install ability enforces. Validate the RAW
		// value before sanitising: sanitize_key() would quietly strip a slash or
		// dot and turn "foo/bar.php" into something that looks valid.
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,62}$/', $raw_slug ) ) {
			return rest_ensure_response(
				Response::error( 'That plugin name doesn’t look right, so nothing was changed.' )
			);
		}
		$slug = sanitize_key( $raw_slug );

		if ( ! wp_is_file_mod_allowed( 'zipai_update_plugin' ) ) {
			return rest_ensure_response(
				Response::error( 'This site is set up so plugins can’t be changed from the dashboard. Ask whoever manages your hosting to update it for you.' )
			);
		}

		if ( is_multisite() && ! is_super_admin() ) {
			return rest_ensure_response(
				Response::error( 'Only a network administrator can update plugins on this site.' )
			);
		}

		$this->load_upgrader_dependencies();

		$plugin_file = Helper::find_plugin_file_for_slug( $slug );
		if ( null === $plugin_file ) {
			return rest_ensure_response(
				Response::error( 'That plugin isn’t installed on this site, so there’s nothing to update.' )
			);
		}

		// Capture the active state BEFORE upgrading. Outside cron,
		// Plugin_Upgrader::upgrade() hooks `deactivate_plugin_before_upgrade`,
		// which silently deactivates an active plugin for the file swap and does
		// not turn it back on. We restore it below — but only if it was on to
		// begin with, or we would switch on a plugin the user deliberately
		// disabled. Network activation is tracked SEPARATELY: the deactivation
		// core runs also strips the network entry, so restoring a
		// network-activated plugin with a plain (single-site) activate would
		// leave a 30-site network with SureForms active on one site only.
		$was_network_active = is_plugin_active_for_network( $plugin_file );
		$was_active         = is_plugin_active( $plugin_file );

		// Refresh the update data so the upgrader does not read a stale "no
		// update available" and report success while changing nothing.
		// `wp_update_plugins()` alone re-checks wp.org and updates the transient
		// in place; we deliberately do NOT call `wp_clean_plugins_cache( true )`
		// first. That wipe deletes the site-wide `update_plugins` transient for
		// EVERY plugin, and `wp_update_plugins()` bails without writing anything
		// back if the wp.org request errors — so a host firewall or a wp.org
		// blip would leave the whole site showing zero pending updates for up to
		// 12 hours, sending the user to a Plugins screen this very click emptied.
		// Core's own wp_ajax_update_plugin() refreshes without the wipe for the
		// same reason.
		wp_update_plugins();

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				Response::error( 'The update couldn’t be completed. You can update this plugin from your Plugins screen instead.' )
			);
		}

		// upgrade() returns false when there was nothing to do, and null when the
		// filesystem was unavailable. Neither is an exception, and neither left
		// the site updated — so neither should be reported as success.
		if ( true !== $result ) {
			return rest_ensure_response(
				Response::error( 'No update was available to install. Check your Plugins screen for a pending update.' )
			);
		}

		// Re-read the header so the caller learns the version it actually got,
		// rather than the one we assumed was published. `false` clears only the
		// get_plugins() list cache, NOT the site-wide update transient — the
		// upgrade already cleared this plugin's own pending-update entry, and
		// wiping the transient wholesale would drop every OTHER plugin's pending
		// flag too (the same wp.org-outage trap as the pre-upgrade path).
		wp_clean_plugins_cache( false );
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
		$version     = $plugin_data['Version'];
		$name        = '' !== $plugin_data['Name'] ? $plugin_data['Name'] : $slug;

		// Restore the pre-update state. Only when it WAS active — a plugin the
		// user had switched off must stay off, or an update silently turns it on.
		// `$was_network_active` carries the network scope through so a
		// network-activated plugin is restored network-wide, not just here.
		// `$silent = true` matches core (wp-admin/update.php): the deactivation
		// was silent, so firing the activation hook against the pre-swap code
		// that is still in memory is the asymmetry to avoid.
		if ( ( $was_active || $was_network_active ) && ! is_plugin_active( $plugin_file ) && ! is_plugin_active_for_network( $plugin_file ) ) {
			$reactivated = activate_plugin( $plugin_file, '', $was_network_active, true );
			if ( is_wp_error( $reactivated ) ) {
				// Files updated but the plugin is now OFF — worse than not
				// updating, and the caller must not treat this as success.
				return rest_ensure_response(
					Response::error( sprintf( '%s was updated but could not be switched back on. Please activate it from your Plugins screen.', $name ) )
				);
			}
		}

		return rest_ensure_response(
			Response::success(
				sprintf( '%s is now up to date.', $name ),
				array(
					'slug'    => $slug,
					'version' => $version,
				)
			)
		);
	}

	/**
	 * Pull in the admin-only upgrader pieces. These live in wp-admin and are not
	 * loaded during a REST request.
	 *
	 * @return void
	 */
	private function load_upgrader_dependencies() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
	}
}
