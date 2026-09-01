<?php
/**
 * Connection REST API — what the Connection screen needs to hand an AI client
 * everything required to reach this site.
 *
 * Cookie/nonce authenticated and `manage_options` only: these routes mint and
 * revoke Application Passwords, and switch the external MCP endpoint on and off.
 * They are for a logged-in administrator in wp-admin, never for an AI client
 * (which authenticates with the credential these routes produce).
 *
 * Credential storage is entirely WordPress core's (`WP_Application_Passwords`):
 * core hashes the password, records `last_used`, and revokes. Nothing about a
 * credential is duplicated here — the plaintext is returned exactly once, at
 * creation, and never stored.
 *
 * @since 0.0.8
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Api;

use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Connection screen's REST routes.
 */
class Connection_REST_API {

	/**
	 * Prefix on every credential this screen creates, so the list can show the
	 * ones a user made here without claiming credentials other plugins own.
	 */
	const APP_ID_PREFIX = 'zip-ai-connection-';

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$permission = array( $this, 'check_permission' );

		register_rest_route(
			'zip-ai/v1',
			'/connection',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_connection' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'zip-ai/v1',
			'/connection/enabled',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_enabled' ),
				'permission_callback' => $permission,
				'args'                => array(
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'zip-ai/v1',
			'/connection/credentials',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_credential' ),
				'permission_callback' => $permission,
				'args'                => array(
					'name' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'zip-ai/v1',
			'/connection/credentials/(?P<uuid>[A-Za-z0-9\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_credential' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Only a logged-in administrator may see or change connection settings.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Everything the Connection screen renders: endpoint, state, the current
	 * user's credentials, and the exposed tool list.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_connection() {
		return rest_ensure_response(
			Response::success(
				'',
				array(
					'enabled'     => External_Mcp::is_enabled(),
					'server_url'  => External_Mcp::server_url(),
					'server_name' => External_Mcp::SERVER_ID,
					'username'    => wp_get_current_user()->user_login,
					'site_host'   => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					// Advertised tools carry full schemas at connect time; the
					// reachable set is what the adapter's execute-ability
					// dispatcher can run by name on top of those.
					'tools'       => \ZipAI\MCP\Classes\Core\External_Tool_Policy::ADVERTISED,
					'reachable'   => \ZipAI\MCP\Classes\Core\External_Tool_Policy::allowed(),
					'credentials' => $this->list_credentials(),
				)
			)
		);
	}

	/**
	 * Switch the external endpoint on or off.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function set_enabled( $request ) {
		$enabled = (bool) $request->get_param( 'enabled' );
		External_Mcp::set_enabled( $enabled );

		// The adapter registers its routes on `rest_api_init`, which has already
		// run for THIS request — so the endpoint appears once the next request
		// boots. Say so rather than let the screen show a URL that 404s.
		return rest_ensure_response(
			Response::success(
				$enabled
					? __( 'AI abilities enabled. The connection endpoint is live.', 'zip-ai' )
					: __( 'AI abilities disabled. Connected clients can no longer reach this site.', 'zip-ai' ),
				array( 'enabled' => $enabled )
			)
		);
	}

	/**
	 * Mint an Application Password for one AI client. The plaintext is in this
	 * response and nowhere else — core stores only a hash.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_credential( $request ) {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return rest_ensure_response(
				Response::error( __( 'Application Passwords are not available on this site.', 'zip-ai' ) )
			);
		}
		if ( ! wp_is_application_passwords_available() ) {
			// Core requires HTTPS (or an explicit override) — a truthful reason
			// beats a generic failure the admin cannot act on.
			return rest_ensure_response(
				Response::error(
					__( 'WordPress has Application Passwords turned off for this site: they require HTTPS.', 'zip-ai' ),
					__( 'Serve the site over HTTPS, or define WP_ENVIRONMENT_TYPE as local for development.', 'zip-ai' )
				)
			);
		}

		$raw_name = $request->get_param( 'name' );
		$name     = is_string( $raw_name ) ? trim( $raw_name ) : '';
		$name     = '' !== $name ? $name : __( 'AI client', 'zip-ai' );
		$user_id  = get_current_user_id();

		$result = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => $name,
				'app_id' => self::APP_ID_PREFIX . wp_generate_uuid4(),
			)
		);
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( Response::from_wp_error( $result ) );
		}

		list( $plaintext, $item ) = $result;
		if ( '' === $plaintext || empty( $item['uuid'] ) ) {
			return rest_ensure_response(
				Response::error( __( 'WordPress returned an unexpected credential shape.', 'zip-ai' ) )
			);
		}

		$username = wp_get_current_user()->user_login;

		return rest_ensure_response(
			Response::success(
				__( 'Copy this password now. It is not shown again.', 'zip-ai' ),
				array(
					'uuid'        => (string) $item['uuid'],
					'name'        => $name,
					'password'    => $plaintext,
					'username'    => $username,
					// Pre-built so the screen never has to base64 in the browser,
					// and every client tab shows a byte-identical header.
					'basic_auth'  => 'Basic ' . base64_encode( $username . ':' . $plaintext ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credential, not obfuscation.
					'created'     => $item['created'],
					'credentials' => $this->list_credentials(),
				)
			)
		);
	}

	/**
	 * Revoke one credential. Scoped to the current user's own passwords — an
	 * administrator revoking another user's credential is a Users-screen action,
	 * not a connection setting.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_credential( $request ) {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return rest_ensure_response(
				Response::error( __( 'Application Passwords are not available on this site.', 'zip-ai' ) )
			);
		}

		$raw_uuid = $request->get_param( 'uuid' );
		$uuid     = is_string( $raw_uuid ) ? $raw_uuid : '';
		$user_id  = get_current_user_id();

		$managed_uuid = $this->managed_uuid();
		if ( '' !== $managed_uuid && $uuid === $managed_uuid ) {
			return rest_ensure_response(
				Response::error(
					__( 'That credential is used by ZIP AI to write to this site and cannot be revoked here.', 'zip-ai' ),
					__( 'Turn off AI abilities above to stop access, or disconnect the account to remove it.', 'zip-ai' )
				)
			);
		}

		$existing = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
		if ( null === $existing ) {
			return rest_ensure_response(
				Response::error( __( 'That credential no longer exists.', 'zip-ai' ) )
			);
		}

		// Only credentials minted on this screen. A WP-mobile-app password or
		// another plugin's credential looks like harmless cleanup in a list of
		// names — revoking those is a Profile-screen action, not ours.
		if ( 0 !== strpos( $existing['app_id'], self::APP_ID_PREFIX ) ) {
			return rest_ensure_response(
				Response::error(
					__( 'That credential was not created by ZIP AI, so it cannot be revoked here.', 'zip-ai' ),
					__( 'Manage it from your WordPress profile under Application Passwords.', 'zip-ai' )
				)
			);
		}

		$deleted = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( is_wp_error( $deleted ) ) {
			return rest_ensure_response( Response::from_wp_error( $deleted ) );
		}

		return rest_ensure_response(
			Response::success(
				__( 'Credential revoked. Any client using it can no longer reach this site.', 'zip-ai' ),
				array( 'credentials' => $this->list_credentials() )
			)
		);
	}

	/**
	 * The uuid of the credential ZIP AI itself uses — the one bound to this site's
	 * account so the import service can write back into WordPress.
	 *
	 * Deleting it breaks imports until it is re-issued, and it is indistinguishable
	 * from leftover junk in a list of names, so the UI must not offer to revoke it.
	 *
	 * @return string Empty when nothing is bound.
	 */
	private function managed_uuid() {
		$stored = Helper::get_setting( 'app_password_uuid', '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		return (string) Utils::decrypt( $stored );
	}

	/**
	 * The current user's Application Passwords, newest first. Never includes a
	 * password — core keeps only a hash, which is the point.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function list_credentials() {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return array();
		}

		// Core's record shape is fixed and typed; only `last_used` is nullable
		// (null until the credential is first used by a client).
		$managed   = $this->managed_uuid();
		$passwords = \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() );
		$rows      = array();
		foreach ( $passwords as $password ) {
			$rows[] = array(
				// Owned by the plugin, not the user — the UI hides Revoke for it.
				'managed'   => '' !== $managed && $password['uuid'] === $managed,
				'uuid'      => $password['uuid'],
				'name'      => $password['name'],
				'created'   => $password['created'],
				'last_used' => null !== $password['last_used'] ? $password['last_used'] : 0,
				// Distinguishes credentials made on this screen from ones the
				// user created in their WordPress profile or another plugin did.
				'ours'      => 0 === strpos( $password['app_id'], self::APP_ID_PREFIX ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);

		return $rows;
	}
}
