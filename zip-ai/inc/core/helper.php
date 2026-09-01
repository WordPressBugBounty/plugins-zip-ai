<?php
/**
 * ZIP AI - Helper.
 *
 * This file contains the helper functions of ZIP AI.
 * Helpers are functions that are used throughout the library.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Classes to be used, in alphabetical order.
use ZipAI\MCP\Classes\Core\Utils;

/**
 * The Helper Class.
 */
class Helper {

	/**
	 * Check if SSL verification should be enabled for remote requests.
	 *
	 * SSL verification is enabled by default for security. It can be disabled
	 * for local development environments using the ZIPAI_MCP_DISABLE_SSL_VERIFY
	 * constant or the 'zip_ai_sslverify' filter.
	 *
	 * @since 1.0.0
	 * @return bool True if SSL should be verified, false otherwise.
	 */
	public static function should_verify_ssl() {
		// Default to true (SSL verification enabled) for security.
		$verify_ssl = true;

		// Allow disabling via constant for local development.
		if ( defined( 'ZIPAI_MCP_DISABLE_SSL_VERIFY' ) && ZIPAI_MCP_DISABLE_SSL_VERIFY ) {
			$verify_ssl = false;
		}

		/**
		 * Filter whether SSL verification should be enabled for remote requests.
		 *
		 * @since 1.0.0
		 * @param bool $verify_ssl Whether to verify SSL. Default true.
		 */
		return apply_filters( 'zip_ai_sslverify', $verify_ssl );
	}

	/**
	 * Get an option from the database.
	 *
	 * @param string  $key              The option key.
	 * @param mixed   $default          The option default value if option is not available.
	 * @param boolean $network_override Whether to allow the network admin setting to be overridden on subsites.
	 * @since 1.0.0
	 * @return mixed  The option value.
	 */
	public static function get_admin_settings_option( $key, $default = false, $network_override = false ) {
		// Get the site-wide option if we're in the network admin.
		return $network_override && is_multisite() ? get_site_option( $key, $default ) : get_option( $key, $default );
	}

	/**
	 * Update an option from the database.
	 *
	 * @param string $key              The option key.
	 * @param mixed  $value            The value to update.
	 * @param bool   $network_override Whether to allow the network_override admin setting to be overridden on subsites.
	 * @since 1.0.0
	 * @return bool True if the option was updated, false otherwise.
	 */
	public static function update_admin_settings_option( $key, $value, $network_override = false ) {
		// Update the site-wide option if we're in the network admin, and return the updated status.
		return $network_override && is_multisite() ? update_site_option( $key, $value ) : update_option( $key, $value );
	}

	/**
	 * Check if ZIP AI is authorized.
	 *
	 * @since 1.0.0
	 * @return boolean True if ZIP AI is authorized, false otherwise.
	 */
	public static function is_authorized() {
		// Check zip_mcp_settings for auth_token.
		$auth_token = self::get_decrypted_auth_token();

		return ! empty( $auth_token ) && ! empty( trim( $auth_token ) );
	}

	/**
	 * Get the ZIP AI Settings.
	 *
	 * If used with a key, it will return that specific setting.
	 * If used without a key, it will return the entire settings array.
	 *
	 * @param string $key The setting key.
	 * @param mixed  $default The default value to return if the setting is not found.
	 * @since 1.0.0
	 * @return mixed|array The setting value, or the default.
	 */
	public static function get_setting( $key = '', $default = array() ) {

		// Get the ZIP AI settings.
		$existing_settings = self::get_admin_settings_option( 'zip_mcp_settings' );

		// If the ZIP AI settings are empty, return the fallback.
		if ( empty( $existing_settings ) || ! is_array( $existing_settings ) ) {
			return $default;
		}

		// If the key is empty, return the entire settings array - otherwise return the specific setting or the fallback.
		if ( empty( $key ) ) {
			return $existing_settings;
		} else {
			return isset( $existing_settings[ $key ] ) ? $existing_settings[ $key ] : $default;
		}
	}

	/**
	 * Get the decrypted auth token from zip_mcp_settings.
	 *
	 * @since 1.0.0
	 * @return string The decrypted auth token.
	 */
	public static function get_decrypted_auth_token() {
		/**
		 * Narrowed type for `$resolved_token`.
		 *
		 * @var string $resolved_token
		 */
		static $resolved_token = '';
		static $is_resolved    = false;

		if ( $is_resolved ) {
			return $resolved_token;
		}

		$is_resolved  = true;
		$mcp_settings = get_option( 'zip_mcp_settings', array() );

		if ( ! is_array( $mcp_settings ) ) {
			$resolved_token = '';
			return $resolved_token;
		}

		$auth_token    = ! empty( $mcp_settings['auth_token'] ) && is_string( $mcp_settings['auth_token'] ) ? Utils::decrypt( $mcp_settings['auth_token'] ) : '';
		$zip_token     = ! empty( $mcp_settings['zip_token'] ) && is_string( $mcp_settings['zip_token'] ) ? Utils::decrypt( $mcp_settings['zip_token'] ) : '';
		$team_uuid     = ! empty( $mcp_settings['team_uuid'] ) && is_string( $mcp_settings['team_uuid'] ) ? sanitize_text_field( $mcp_settings['team_uuid'] ) : '';
		$email         = ! empty( $mcp_settings['user_email'] ) && is_string( $mcp_settings['user_email'] ) ? sanitize_email( $mcp_settings['user_email'] ) : '';
		$name          = ! empty( $mcp_settings['user_name'] ) && is_string( $mcp_settings['user_name'] ) ? sanitize_text_field( $mcp_settings['user_name'] ) : '';
		$current_api   = self::get_credit_server_identifier();
		$stored_api    = ! empty( $mcp_settings['auth_token_server'] ) && is_string( $mcp_settings['auth_token_server'] ) ? untrailingslashit( $mcp_settings['auth_token_server'] ) : '';
		$is_valid_auth = null;

		if ( '' !== $auth_token && $stored_api === $current_api ) {
			$resolved_token = $auth_token;
			return $resolved_token;
		}

		if ( '' !== $auth_token && '' === $stored_api ) {
			$is_valid_auth = self::validate_credit_server_auth_token( $auth_token );

			if ( true === $is_valid_auth ) {
				$mcp_settings['auth_token_server'] = $current_api;
				update_option( 'zip_mcp_settings', $mcp_settings );
				$resolved_token = $auth_token;
				return $resolved_token;
			}
		}

		if ( '' !== $zip_token && '' !== $email ) {
			$exchange_result = self::exchange_zipwp_token_for_local_auth_token( $zip_token, $email, $name, $team_uuid );

			if ( ! empty( $exchange_result['token'] ) ) {
				$mcp_settings['auth_token']        = Utils::encrypt( $exchange_result['token'] );
				$mcp_settings['auth_token_server'] = $current_api;

				if ( ! empty( $exchange_result['email'] ) ) {
					$mcp_settings['user_email'] = sanitize_email( $exchange_result['email'] );
				}

				if ( ! empty( $exchange_result['name'] ) ) {
					$mcp_settings['user_name'] = sanitize_text_field( $exchange_result['name'] );
				}

				update_option( 'zip_mcp_settings', $mcp_settings );
				$resolved_token = $exchange_result['token'];
				return $resolved_token;
			}
		}

		if ( '' !== $auth_token && null === $is_valid_auth && '' === $stored_api ) {
			$resolved_token = $auth_token;
			return $resolved_token;
		}

		$resolved_token = '';
		return $resolved_token;
	}

	/**
	 * Get the decrypted Application Password Authorization header value.
	 *
	 * Returns the pre-built `Basic <b64(user:password)>` string ready to be
	 * sent as the `Authorization` header (or forwarded to MCP via the
	 * `X-Wp-Authorization` custom header). Empty when no App Password is
	 * provisioned for the current connection — caller should treat that as
	 * "MCP tools unavailable" and surface to the admin.
	 *
	 * @since 1.0.0
	 * @return string The full `Basic …` Authorization header value, or empty string.
	 */
	public static function get_decrypted_app_password_authorization() {
		$mcp_settings = get_option( 'zip_mcp_settings', array() );

		if ( is_array( $mcp_settings ) && ! empty( $mcp_settings['app_password_authorization'] ) && is_string( $mcp_settings['app_password_authorization'] ) ) {
			return (string) Utils::decrypt( $mcp_settings['app_password_authorization'] );
		}

		return '';
	}

	/**
	 * Mint a WordPress Application Password for the current admin user. Store
	 * the pre-built Authorization header value (`Basic <b64>`) and the App
	 * Password UUID into `zip_mcp_settings`. The stored value is encrypted.
	 *
	 * This method is idempotent. A stored UUID can still resolve to a live App
	 * Password on the user record. Then this method is a no-op and trusts the
	 * existing token. A stale UUID triggers a fresh mint. A UUID goes stale when
	 * the user deletes it in Profile then Application Passwords.
	 *
	 * The OAuth callback in `AJAX_Handlers::verify_authorization` calls this. It
	 * runs right after the Sanctum `auth_token` is persisted. This reuses the
	 * existing connection click. So the admin UX stays at one "Connect" step.
	 * The App Password is provisioned silently in the same flow.
	 *
	 * App Password plaintext is one-time-visible. WordPress hashes it and never
	 * exposes the plaintext again. This method captures it in a single call. It
	 * pre-builds the Authorization header value once (`'Basic '. base64(...)`).
	 * It encrypts the full value and stores it. The plaintext is never persisted
	 * bare. It lives only inside the `Basic …` string. That is the form needed
	 * on the wire.
	 *
	 * @since 1.0.0
	 * @return array{success: bool, code?: string, message?: string, bound?: bool} status envelope;
	 *         `bound` reports whether the server accepted the credential push.
	 */
	public static function ensure_app_password_provisioned() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array(
				'success' => false,
				'code'    => 'no_current_user',
				'message' => __( 'No current user context. Cannot provision Application Password.', 'zip-ai' ),
			);
		}

		// Capability gate. Match the OAuth callback's own check so the App
		// Password is only ever minted under an admin identity. The resulting
		// token inherits the user's caps; we don't want a non-admin
		// connection to silently mint a low-privilege token that then 401s
		// every tool call.
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return array(
				'success' => false,
				'code'    => 'insufficient_capability',
				'message' => __( 'Connecting user lacks manage_options. Application Password not provisioned.', 'zip-ai' ),
			);
		}

		if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			return array(
				'success' => false,
				'code'    => 'app_passwords_disabled',
				'message' => __( 'Application Passwords are disabled on this site. Enable them or contact your administrator.', 'zip-ai' ),
			);
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return array(
				'success' => false,
				'code'    => 'app_passwords_disabled_for_user',
				'message' => __( 'Application Passwords are disabled for the connecting user.', 'zip-ai' ),
			);
		}

		// Idempotency: if we already minted one and it still exists on the
		// user record, leave it alone. Re-minting on every reconnect would
		// litter the user's Profile → Application Passwords screen.
		$existing_uuid_encrypted = self::get_setting( 'app_password_uuid', '' );
		$existing_uuid           = is_string( $existing_uuid_encrypted ) && '' !== $existing_uuid_encrypted
			? (string) Utils::decrypt( $existing_uuid_encrypted )
			: '';
		if ( '' !== $existing_uuid ) {
			$existing_record = \WP_Application_Passwords::get_user_application_password( $user_id, $existing_uuid );
			if ( null !== $existing_record ) {
				// Even though we already have the App Password locally, a
				// reconnect typically issues a NEW Sanctum token on the
				// server side — and our credential is bound to that token's
				// meta. Re-push the stored header so the new token also
				// has it bound; the server endpoint is idempotent.
				$stored_header = self::get_decrypted_app_password_authorization();
				$pushed        = false;
				if ( '' !== $stored_header ) {
					$pushed = (bool) self::push_app_password_to_saas( $stored_header );
				}
				return array(
					'success' => true,
					'code'    => 'already_provisioned',
					// Whether the server accepted the bind — soft-fail for the
					// connection flow, but reprovision needs the hard answer.
					'bound'   => $pushed,
				);
			}
			// Stale UUID — fall through and mint fresh. The next
			// `update_setting` call overwrites the stored ciphertext.
		}

		$result = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => 'ZipWP MCP Connection',
				'app_id' => 'zip-ai-' . wp_generate_uuid4(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'code'    => 'create_failed',
				'message' => $result->get_error_message(),
			);
		}

		// `create_new_application_password` returns [ $plaintext_password, $item ].
		// $item carries the persisted record metadata including `uuid`.
		list( $plaintext, $item ) = $result;
		if ( '' === $plaintext || empty( $item['uuid'] ) ) {
			return array(
				'success' => false,
				'code'    => 'create_unexpected_shape',
				'message' => __( 'WP_Application_Passwords returned an unexpected response shape.', 'zip-ai' ),
			);
		}

		// Pre-build the full `Basic <b64(user_login:plaintext)>` header value
		// — that's the form we actually need on the wire. Storing the
		// pre-built string means plaintext never round-trips through the
		// codebase at any point after this call.
		$authorization_header = 'Basic ' . base64_encode( $user->user_login . ':' . $plaintext );

		self::update_setting( 'app_password_uuid', (string) $item['uuid'] );
		self::update_setting( 'app_password_authorization', $authorization_header );
		self::update_setting( 'app_password_user_id', (string) $user_id );

		// Server-to-server delivery — push the pre-built `Basic <b64>` value
		// to the server so it can pick it up from its DB at turn time.
		// The credential never has to ride along on a client request header,
		// which means it never lands in the inline JS where any other page
		// script could read it. Soft-fail: if the push errors we still report
		// `success: provisioned` locally so the admin sees the connection as
		// established — the next chat turn will surface a clear MCP-auth error
		// and the admin can disconnect/reconnect to trigger another bind attempt.
		$pushed = (bool) self::push_app_password_to_saas( $authorization_header );

		return array(
			'success' => true,
			'code'    => 'provisioned',
			'bound'   => $pushed,
		);
	}

	/**
	 * Bind the pre-built `Basic <b64>` Authorization header to the active
	 * Sanctum token on the server via `POST /api/wp-credentials/bind`. Called
	 * right after a fresh App Password is minted (or proactively from the
	 * OAuth callback when an `already_provisioned` credential exists and
	 * needs to be re-bound after a server-side wipe).
	 *
	 * Idempotent on both sides — the server overwrites whatever value was
	 * previously stored under the same token's meta column.
	 *
	 * @since 1.0.0
	 * @param string $authorization_header Pre-built `Basic <b64(user:apppwd)>` value.
	 * @return bool True on HTTP 200, false on any error path.
	 */
	public static function push_app_password_to_saas( $authorization_header ) {
		if ( '' === $authorization_header ) {
			return false;
		}

		$auth_token = self::get_decrypted_auth_token();
		if ( '' === $auth_token ) {
			// No Sanctum token yet — the bind has to happen post-OAuth.
			return false;
		}

		$response = wp_remote_post(
			ZIPAI_MCP_CREDIT_SERVER_API . 'wp-credentials/bind',
			array(
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $auth_token,
				),
				'body'      => (string) wp_json_encode(
					array(
						'authorization_header' => $authorization_header,
						'site_url'             => home_url(),
					)
				),
				'timeout'   => 15,
				'sslverify' => self::should_verify_ssl(),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[zip-ai] wp-credentials/bind failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			error_log( sprintf( '[zip-ai] wp-credentials/bind returned HTTP %d', (int) $status_code ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return true;
	}

	/**
	 * Inverse of `push_app_password_to_saas()` — tell the server to drop the
	 * bound credential. Called from `revoke_app_password()` so the server
	 * state tracks the WP-side revoke.
	 *
	 * @since 1.0.0
	 * @return bool True on HTTP 200, false on any error path.
	 */
	public static function unpush_app_password_from_saas() {
		$auth_token = self::get_decrypted_auth_token();
		if ( '' === $auth_token ) {
			return false;
		}

		$response = wp_remote_post(
			ZIPAI_MCP_CREDIT_SERVER_API . 'wp-credentials/unbind',
			array(
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $auth_token,
				),
				'body'      => (string) wp_json_encode( array() ),
				'timeout'   => 15,
				'sslverify' => self::should_verify_ssl(),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[zip-ai] wp-credentials/unbind failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		return 200 === (int) $status_code;
	}

	/**
	 * Re-mint the site's WordPress Application Password and re-bind it to the
	 * server. This replaces whatever is on file.
	 *
	 * This is the recovery path for the one failure this binding has. The stored
	 * credential can stop working. Someone revoked the password in Profile then
	 * Application Passwords. Or a restore or migration left a header whose
	 * password no longer exists. The server then gets 401 on every write into
	 * the site. The import cannot proceed. Only WordPress-side code holds the
	 * admin identity needed to issue a new one.
	 *
	 * This method revokes first, deliberately. `ensure_app_password_provisioned()`
	 * is idempotent. Without the revoke, it would re-push the SAME dead header
	 * when the stored uuid still resolves.
	 *
	 * @since 0.0.8
	 * @return bool True when a fresh credential is minted AND accepted by the server.
	 */
	public static function reprovision_app_password() {
		// Revoking is destructive and UNCONDITIONAL. It deletes the password,
		// unbinds it server-side and clears the local rows. So refuse before
		// touching anything unless this context can mint the replacement.
		// `ensure_app_password_provisioned()` requires `manage_options` on the
		// current user. It also requires Application Passwords to be available.
		// Reaching those checks after the revoke would leave the site
		// disconnected from every door (wizard, chat, MCP). There would be no
		// way back except a manual reconnect. The MCP import door calls this
		// under a caller who only needs `publish_pages`.
		if ( ! current_user_can( 'manage_options' )
			|| ! function_exists( 'wp_is_application_passwords_available' )
			|| ! wp_is_application_passwords_available() ) {
			return false;
		}

		// Best-effort: a uuid that no longer exists simply returns false here, and
		// the point is only to clear the local record so a fresh mint happens.
		self::revoke_app_password();

		// `bound` is the server's acceptance of the push `ensure…` already made —
		// re-pushing the same header here would just bind the same value twice.
		$provisioned = self::ensure_app_password_provisioned();

		return ! empty( $provisioned['success'] ) && ! empty( $provisioned['bound'] );
	}

	/**
	 * Revoke the stored Application Password (if any) and clear its
	 * settings rows. Called from the disconnect AJAX handler.
	 *
	 * @since 1.0.0
	 * @return bool True if a password was revoked, false if nothing was stored.
	 */
	public static function revoke_app_password() {
		$mcp_settings = get_option( 'zip_mcp_settings', array() );
		if ( ! is_array( $mcp_settings ) ) {
			return false;
		}

		$uuid    = ! empty( $mcp_settings['app_password_uuid'] ) && is_string( $mcp_settings['app_password_uuid'] )
			? (string) Utils::decrypt( $mcp_settings['app_password_uuid'] )
			: '';
		$user_id = ! empty( $mcp_settings['app_password_user_id'] ) && is_string( $mcp_settings['app_password_user_id'] )
			? (int) Utils::decrypt( $mcp_settings['app_password_user_id'] )
			: 0;

		$revoked = false;
		if ( '' !== $uuid && $user_id > 0 && class_exists( '\WP_Application_Passwords' ) ) {
			$revoked = (bool) \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		}

		// Drop the server-side mirror BEFORE we wipe local meta so the bind
		// endpoint still has access to the active Sanctum token (cleared
		// separately by `zipwp_clear_settings()`). Soft-fail — the local
		// revoke is authoritative; the server will 401 next turn if the
		// unbind didn't land and the admin can reconnect.
		self::unpush_app_password_from_saas();

		unset(
			$mcp_settings['app_password_authorization'],
			$mcp_settings['app_password_uuid'],
			$mcp_settings['app_password_user_id']
		);
		update_option( 'zip_mcp_settings', $mcp_settings );

		return $revoked;
	}

	/**
	 * Validate the auth token against the configured credit server.
	 *
	 * @param string $auth_token The auth token to validate.
	 * @since 1.0.0
	 * @return bool|null True when valid, false when rejected, null when validation could not be completed.
	 */
	public static function validate_credit_server_auth_token( $auth_token ) {
		if ( empty( $auth_token ) ) {
			return false;
		}

		$response = wp_remote_post(
			ZIPAI_MCP_CREDIT_SERVER_API . 'auth/validate',
			array(
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $auth_token,
				),
				'body'      => (string) wp_json_encode( array() ),
				'timeout'   => 15,
				'sslverify' => self::should_verify_ssl(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		if ( 200 === $status_code ) {
			return is_array( $response_data ) && ! empty( $response_data['valid'] );
		}

		if ( 401 === $status_code || 403 === $status_code ) {
			return false;
		}

		return null;
	}

	/**
	 * Exchange a ZipWP app token for a local credit-server auth token.
	 *
	 * @param string $zip_token The ZipWP app token.
	 * @param string $email The user email.
	 * @param string $name The user name.
	 * @param string $team_uuid The ZipWP team this site is being authenticated with.
	 * @since 1.0.0
	 * @return array{token?: string, email?: string, name?: string} The exchange result containing token details, or an empty array on failure.
	 */
	public static function exchange_zipwp_token_for_local_auth_token( $zip_token, $email, $name = '', $team_uuid = '' ) {
		if ( empty( $zip_token ) || empty( $email ) ) {
			return array();
		}

		$request_body = array(
			'token'   => $zip_token,
			'email'   => $email,
			'site_id' => self::get_site_id(),
		);

		if ( '' !== $name ) {
			$request_body['name'] = $name;
		}

		// Scopes credit pooling to ONE team on the credit server: a user who
		// belongs to several ZipWP teams must not be able to spend another
		// team's credits from this site. Optional on the wire — an older credit
		// server ignores it, and a newer one falls back to inferring the team
		// when it is absent, so sending nothing is never worse than today.
		if ( '' !== $team_uuid ) {
			$request_body['team_uuid'] = $team_uuid;
		}

		if ( get_current_user_id() > 0 ) {
			$request_body['user_id'] = get_current_user_id();
		}

		$response = wp_remote_post(
			ZIPAI_MCP_CREDIT_SERVER_API . 'token/exchange',
			array(
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => (string) wp_json_encode( $request_body ),
				'timeout'   => 15,
				'sslverify' => self::should_verify_ssl(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		if ( ! is_array( $response_data ) || ! in_array( $status_code, array( 200, 201 ), true ) || empty( $response_data['success'] ) || empty( $response_data['token'] ) ) {
			return array();
		}

		$token_value = is_string( $response_data['token'] ) ? $response_data['token'] : '';
		$email_value = ! empty( $response_data['email'] ) && is_string( $response_data['email'] ) ? $response_data['email'] : '';
		$name_value  = ! empty( $response_data['name'] ) && is_string( $response_data['name'] ) ? $response_data['name'] : '';

		return array(
			'token' => sanitize_text_field( $token_value ),
			'email' => '' !== $email_value ? sanitize_email( $email_value ) : $email,
			'name'  => '' !== $name_value ? sanitize_text_field( $name_value ) : $name,
		);
	}

	/**
	 * Get the configured credit server identifier for token compatibility checks.
	 *
	 * @since 1.0.0
	 * @return string The normalized credit server API URL.
	 */
	private static function get_credit_server_identifier() {
		return untrailingslashit( ZIPAI_MCP_CREDIT_SERVER_API );
	}

	/**
	 * Generate a shared secret for HMAC authentication.
	 *
	 * @since 1.0.0
	 * @return string The generated shared secret.
	 */
	public static function generate_shared_secret() {
		return bin2hex( random_bytes( 32 ) );
	}
	/**
	 * Get or generate the shared secret for HMAC authentication.
	 *
	 * @since 1.0.0
	 * @return string The shared secret.
	 */
	public static function get_shared_secret() {
		// Get the encrypted shared secret from settings.
		$encrypted_shared_secret = self::get_setting( 'shared_secret', '' );

		if ( empty( $encrypted_shared_secret ) ) {
			// Generate new shared secret.
			$shared_secret = self::generate_shared_secret();
			self::update_setting( 'shared_secret', $shared_secret );
			return $shared_secret;
		}

		// Decrypt and return the existing shared secret.
		return is_string( $encrypted_shared_secret ) ? Utils::decrypt( $encrypted_shared_secret ) : '';
	}

	/**
	 * Update a specific setting in the ZIP AI settings.
	 *
	 * @param string $key The setting key.
	 * @param mixed  $value The setting value.
	 * @since 1.0.0
	 * @return bool True if the setting was updated, false otherwise.
	 */
	public static function update_setting( $key, $value ) {
		$existing_settings = self::get_admin_settings_option( 'zip_mcp_settings', array() );

		if ( ! is_array( $existing_settings ) ) {
			$existing_settings = array();
		}

		$existing_settings[ $key ] = is_string( $value ) ? Utils::encrypt( $value ) : '';

		return self::update_admin_settings_option( 'zip_mcp_settings', $existing_settings );
	}

	/**
	 * Get the site ID for HMAC authentication.
	 *
	 * @since 1.0.0
	 * @return string The site ID.
	 */
	public static function get_site_id() {
		return get_site_url();
	}

	/**
	 * Register the shared secret with the server during plugin activation.
	 *
	 * @since 1.0.0
	 * @return array<int|string, mixed> The registration response.
	 */
	public static function register_shared_secret_with_laravel() {
		$shared_secret = self::get_shared_secret();
		$site_id       = self::get_site_id();

		// Register endpoint - this should point to the server.
		$register_endpoint = ZIPAI_MCP_CREDIT_SERVER_API . 'auth/register-secret';

		$registration_data = array(
			'site_id'     => $site_id,
			'secret'      => $shared_secret,
			'site_name'   => get_bloginfo( 'name' ),
			'admin_email' => get_option( 'admin_email' ),
		);

		$response = wp_remote_post(
			$register_endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => (string) wp_json_encode( $registration_data ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'error' => $response->get_error_message(),
				'code'  => 'registration_failed',
			);
		}

		$response_body = wp_remote_retrieve_body( $response );
		$status_code   = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return array(
				'error' => __( 'Failed to register with Laravel server.', 'zip-ai' ),
				'code'  => 'registration_failed',
			);
		}

		$response_data = json_decode( $response_body, true );

		// Store the registration status.
		self::update_setting( 'hmac_registered', 'true' );

		return is_array( $response_data ) ? $response_data : array();
	}

	/**
	 * Check if HMAC is registered with the server.
	 *
	 * @since 1.0.0
	 * @return bool True if registered, false otherwise.
	 */
	public static function is_hmac_registered() {
		return 'true' === self::get_setting( 'hmac_registered', 'false' );
	}

	/**
	 * Prepare a block array for WordPress serialize_blocks().
	 *
	 * LLM-generated blocks only have blockName/attrs/innerBlocks.
	 * WordPress serialize_block() requires innerHTML and innerContent
	 * to know how to render the block tree. This adds the missing keys.
	 *
	 * - Blocks with innerBlocks: innerContent = [null, null, ...] (one per child)
	 * - Blocks without innerBlocks: innerContent = [] (self-closing)
	 * - Already-prepared blocks (from parse_blocks): left untouched
	 *
	 * @since 1.0.0
	 * @param array<int|string, mixed> $blocks Array of block objects.
	 * @return array<int|string, mixed> Blocks ready for serialize_blocks().
	 */
	public static function prepare_blocks_for_serialization( $blocks ) {
		return array_map(
			function ( $block ) {
				if ( ! is_array( $block ) ) {
						return $block;
				}

				// Already prepared (e.g. from parse_blocks) — skip
				if ( isset( $block['innerContent'] ) ) {
					// Still recurse into innerBlocks in case they need preparation
					if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
						$block['innerBlocks'] = Helper::prepare_blocks_for_serialization( $block['innerBlocks'] );
					}
					return $block;
				}

				// Ensure attrs is an array
				if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
					$block['attrs'] = array();
				}

				// Recursively prepare innerBlocks first
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$block['innerBlocks'] = Helper::prepare_blocks_for_serialization( $block['innerBlocks'] );
					// One null per inner block — tells serialize_block() where to place each child
					$block['innerContent'] = array_fill( 0, count( $block['innerBlocks'] ), null );
				} else {
					$block['innerBlocks']  = array();
					$block['innerContent'] = array();
				}

				// innerHTML is empty for Spectra blocks (content is in attrs + innerBlocks)
				if ( ! isset( $block['innerHTML'] ) ) {
					$block['innerHTML'] = '';
				}

				return $block;
			},
			$blocks
		);
	}

	/**
	 * Resolve a wp.org plugin slug to its installed `folder/file.php`.
	 *
	 * Matches on the folder segment, so it resolves folder-shaped plugins
	 * (`sureforms/sureforms.php` ← `sureforms`) — the shape every wp.org plugin
	 * this is used to gate takes. Single-file plugins (`hello.php`) never match,
	 * which is correct for the callers here (they only ever pass folder slugs).
	 * Returns null when the slug isn't installed.
	 *
	 * @param string $slug wp.org slug (lower-case alphanumerics and hyphens).
	 * @return string|null
	 */
	public static function find_plugin_file_for_slug( string $slug ): ?string {
		// ONE resolver: PluginResolver carries the main-file preference
		// (`slug/slug.php` beats whichever file get_plugins() lists first in a
		// two-header folder). This wrapper only narrows the contract back to
		// folder-shaped results — its callers pass wp.org folder slugs and
		// must not match single-file plugins.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = \ZipAI\MCP\Classes\Abilities\Zipai\System\PluginResolver::resolve_plugin_file( $slug );
		return null !== $file && false !== strpos( $file, '/' ) ? $file : null;
	}
}
