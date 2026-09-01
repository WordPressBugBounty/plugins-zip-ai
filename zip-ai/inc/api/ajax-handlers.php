<?php
/**
 * AJAX Handlers.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Classes to be used.
use ZipAI\MCP\Classes\Abilities\Zipai\System\PluginResolver;
use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Core\Plugin_Abilities_Toggler;
use ZipAI\MCP\Classes\Core\Utils;

/**
 * The AJAX_Handlers Class.
 * Handles all AJAX requests.
 */
class AJAX_Handlers {

	/**
	 * Constructor of this class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		// Hook into admin_init to catch OAuth callback and sync to zip_mcp_settings.
		add_action( 'admin_init', array( $this, 'verify_authorization' ), 5 );

			// Authentication handlers.
			add_action( 'wp_ajax_zipwp_clear_settings', array( $this, 'zipwp_clear_settings' ) );
			add_action( 'wp_ajax_zipwp_verify_auth_status', array( $this, 'zipwp_verify_auth_status' ) );

			// Media upload from base64.
			add_action( 'wp_ajax_zip_ai_upload_media', array( $this, 'zip_ai_upload_media' ) );

			// Dismiss the inline setup notice (per-user, persists across reloads).
			add_action( 'wp_ajax_zip_ai_dismiss_setup_gate', array( $this, 'zip_ai_dismiss_setup_gate' ) );
			// Activate the Spectra plugin / Spectra One theme for the setup gate.
			add_action( 'wp_ajax_zip_ai_activate_setup_item', array( $this, 'zip_ai_activate_setup_item' ) );
	}




	/**
	 * Clear authentication settings (logout).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function zipwp_clear_settings() {
		// Check nonce for security.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified, not stored.
		$nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? $_POST['nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'zip_ai_iframe' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// `manage_options` — this handler revokes the App Password and
		// wipes the connection. Should match every other auth/settings
		// surface; an editor must not be able to disconnect the site.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Revoke the stored WordPress Application Password (if any) so
		// reconnecting later mints a fresh one and the user's Profile
		// → Application Passwords screen reflects an accurate state.
		// Runs before the option write below so the app_password_* keys
		// referenced by `revoke_app_password()` are still in the option.
		Helper::revoke_app_password();

		// Clear authentication data from mcp settings.
		$mcp_settings = get_option( 'zip_mcp_settings', array() );
		if ( ! is_array( $mcp_settings ) ) {
			$mcp_settings = array();
		}
		unset( $mcp_settings['auth_token'] );
		unset( $mcp_settings['auth_token_server'] );
		// The ZipWP app token is connection-scoped too. Its only reader is the
		// re-exchange in Helper::get_decrypted_auth_token(), which also requires
		// `user_email` — cleared below — so leaving it was inert rather than
		// harmful. Unset for consistency: a disconnected site should hold no
		// credential from the connection it just dropped.
		unset( $mcp_settings['zip_token'] );
		unset( $mcp_settings['user_id'] );
		unset( $mcp_settings['user_email'] );
		unset( $mcp_settings['user_name'] );
		unset( $mcp_settings['site_id'] );
		unset( $mcp_settings['domain'] );
		unset( $mcp_settings['authenticated_at'] );
		// The team belongs to the connection, not to the site. Left in place it
		// outlives the disconnect, and a reconnect whose callback carries no
		// team_uuid leaves the stale one to be read back by
		// Helper::get_decrypted_auth_token() and sent on a later re-exchange —
		// re-pointing the credit server at a team the user may have left.
		unset( $mcp_settings['team_uuid'] );
		// App Password fields are also unset by `revoke_app_password()`
		// above, but we re-apply here defensively in case the revoke
		// path returned early due to a missing credential record.
		unset( $mcp_settings['app_password_authorization'] );
		unset( $mcp_settings['app_password_uuid'] );
		unset( $mcp_settings['app_password_user_id'] );
		$mcp_settings['enabled'] = false;
		update_option( 'zip_mcp_settings', $mcp_settings );

		wp_send_json_success(
			array(
				'message' => 'Settings cleared successfully',
			)
		);
	}

	/**
	 * Verify authentication status (for polling during auth popup)
	 *
	 * @return void
	 */
	public function zipwp_verify_auth_status() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'zip_ai_iframe' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}

		// Capability check — parity with the other AJAX handlers in this class.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Only treat the user as authorized when the stored token is actually decryptable.
		$is_authorized = Helper::is_authorized();

		// Return authorization status.
		wp_send_json_success(
			array(
				'is_authorized' => $is_authorized,
			)
		);
	}

	/**
	 * Dismiss the inline setup notice for the current user (persists across
	 * reloads). The header warning icon still shows while setup is incomplete.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function zip_ai_dismiss_setup_gate() {
		if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'zip_ai_iframe' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		update_user_meta( get_current_user_id(), 'zip_ai_setup_gate_dismissed', 1 );
		wp_send_json_success();
	}

	/**
	 * Activate the Spectra plugin or Spectra One theme for the setup gate.
	 *
	 * Done server-side via core APIs (activate_plugin / switch_theme) rather than
	 * fetching wp-admin activate URLs — those carry esc_html'd nonces ('&amp;')
	 * and referer checks that fail under a cross-context fetch.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function zip_ai_activate_setup_item() {
		if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'zip_ai_iframe' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

		// Allowlist — this endpoint only activates the setup-gate items, never an
		// arbitrary plugin/theme slug posted by the client.
		$allowed = array(
			'plugin' => array( 'spectra-blocks' ),
			'theme'  => array( 'spectra-one' ),
		);
		if ( ! isset( $allowed[ $type ] ) || ! in_array( $slug, $allowed[ $type ], true ) ) {
			wp_send_json_error( 'This item cannot be activated here.' );
		}

		if ( 'plugin' === $type ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error( 'Insufficient permissions' );
			}
			if ( ! function_exists( 'activate_plugin' ) || ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$file = PluginResolver::resolve_plugin_file( $slug );
			if ( null === $file ) {
				wp_send_json_error( 'Plugin is not installed.' );
			}

			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				Utils::debug_log( sprintf( 'Plugin activation failed for "%s"', $slug ), $result->get_error_message() );
				wp_send_json_error( "This plugin couldn't be activated. Please try activating it from wp-admin." );
			}
			wp_send_json_success( array( 'active' => is_plugin_active( $file ) ) );
		}

		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}
		if ( ! wp_get_theme( $slug )->exists() ) {
			wp_send_json_error( 'Theme is not installed.' );
		}

		switch_theme( $slug );
		wp_send_json_success( array( 'active' => get_stylesheet() === $slug ) );
	}

	/**
	 * Handle OAuth callback and save auth token to zip_mcp_settings.
	 * Uses unique nonce (zip_ai_auth_nonce) and callback param (zip-ai-auth)
	 * to avoid conflict with UAG plugin's OAuth flow.
	 *
	 * Security: Uses OAuth state parameter for CSRF protection instead of WordPress nonce.
	 * The state parameter is generated before redirect and verified on callback.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function verify_authorization() {
		// Check for our unique callback parameter - if not present, this is not our callback.
		if ( ! isset( $_GET['zip-ai-auth'] ) || 'true' !== $_GET['zip-ai-auth'] ) {
			return;
		}

		// If the current user does not have the required capability, then abandon ship.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// If none of the required data is received, abandon ship.
		if ( ! isset( $_GET['credit_token'] ) && ! isset( $_GET['token'] ) && ! isset( $_GET['email'] ) ) {
			return;
		}

		$state = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_text_field( $_GET['state'] ) : '';
		if ( empty( $state ) ) {
			return;
		}

		$stored_state = get_transient( 'zip_ai_oauth_state_' . get_current_user_id() );
		if ( empty( $stored_state ) || ! is_string( $stored_state ) || ! hash_equals( $stored_state, $state ) ) {
			return;
		}

		delete_transient( 'zip_ai_oauth_state_' . get_current_user_id() );

		// Get the existing options.
		$mcp_settings = get_option( 'zip_mcp_settings', array() );
		if ( ! is_array( $mcp_settings ) ) {
			$mcp_settings = array();
		}
		$email        = isset( $_GET['email'] ) && is_string( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$name         = isset( $_GET['name'] ) && is_string( $_GET['name'] ) ? sanitize_text_field( wp_unslash( $_GET['name'] ) ) : '';
		$zip_token    = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$credit_token = isset( $_GET['credit_token'] ) && is_string( $_GET['credit_token'] ) ? sanitize_text_field( wp_unslash( $_GET['credit_token'] ) ) : '';
		$team_uuid    = isset( $_GET['team_uuid'] ) && is_string( $_GET['team_uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['team_uuid'] ) ) : '';
		$auth_token   = '';

		if ( '' !== $zip_token && '' !== $email ) {
			$exchange_result = Helper::exchange_zipwp_token_for_local_auth_token( $zip_token, $email, $name, $team_uuid );

			if ( ! empty( $exchange_result['token'] ) ) {
				$auth_token = (string) $exchange_result['token'];
			}

			if ( ! empty( $exchange_result['email'] ) ) {
				$email = $exchange_result['email'];
			}

			if ( ! empty( $exchange_result['name'] ) ) {
				$name = $exchange_result['name'];
			}
		}

		// Only accept credit_token when the credit server positively validates it.
		// validate_credit_server_auth_token() returns true (valid), false (rejected),
		// or null (unreachable) — fail closed on anything but an explicit true so an
		// attacker who can block/spoof the validate endpoint can't inject a token.
		if ( '' === $auth_token && '' !== $credit_token && true === Helper::validate_credit_server_auth_token( $credit_token ) ) {
			$auth_token = $credit_token;
		}

		// Update the auth token if needed.
		if ( '' !== $auth_token ) {
			$mcp_settings['auth_token']        = Utils::encrypt( $auth_token );
			$mcp_settings['auth_token_server'] = untrailingslashit( ZIPAI_MCP_CREDIT_SERVER_API );

			// Remember the team so a later re-exchange
			// (Helper::get_decrypted_auth_token) sends the same one without
			// another round trip through ZipWP. Only when the callback carries
			// one, so a legacy redirect never clears it.
			//
			// Gated on a SUCCESSFUL connection, alongside auth_token_server,
			// because the team must follow the token it belongs to. Written
			// earlier it survived a FAILED exchange: a callback carrying team B
			// whose exchange failed left auth_token = A with team_uuid = B, and
			// a later re-exchange then sent B for A's token — the cross-tenant
			// mispointing this change exists to prevent.
			if ( '' !== $team_uuid ) {
				$mcp_settings['team_uuid'] = $team_uuid;
			}
		}

		// Update the ZIP AI token if needed.
		if ( '' !== $zip_token ) {
			$mcp_settings['zip_token'] = Utils::encrypt( $zip_token );
		}

		// Update the email if needed.
		if ( '' !== $email ) {
			$mcp_settings['user_email'] = $email;
		}

		// Update the user name if needed.
		if ( '' !== $name ) {
			$mcp_settings['user_name'] = $name;
		}

		// Mark as enabled.
		$mcp_settings['enabled']          = true;
		$mcp_settings['authenticated_at'] = current_time( 'mysql' );

		// Update the zip_mcp_settings option.
		update_option( 'zip_mcp_settings', $mcp_settings );

		// Mint a WordPress Application Password for the server → WP MCP
		// transport on the same connection click. Piggy-backs on the
		// existing OAuth flow so the admin sees one "Connect" step.
		// Failure here is non-fatal for the connection itself (Sanctum
		// auth still works); MCP tool execution will surface a
		// clear error if the App Password is missing. The admin can
		// retry via the disconnect → reconnect path.
		if ( '' !== $auth_token ) {
			$provision = Helper::ensure_app_password_provisioned();
			if ( empty( $provision['success'] ) ) {
				$code = isset( $provision['code'] ) ? $provision['code'] : 'unknown';
				error_log( sprintf( '[zip-ai] App Password provisioning failed on connect: %s', $code ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			// The site just connected to ERA — enable MCP for every already-active
			// first-party plugin the toggler maps (e.g. SureRank). Covers the case
			// where a mapped plugin was active BEFORE this connect, so its own
			// `activated_plugin` never ran the toggler. Deliberately outside the
			// provisioning result: the sweep has no dependency on App Passwords,
			// and a reconnect returns `already_provisioned`. Idempotent.
			Plugin_Abilities_Toggler::sweep_active_mapped_plugins();
		}

		// Run initial site scan after successful auth — gives ZIP AI day-zero context.
		// Fire non-blocking request to local REST endpoint (avoids WP cron unreliability).
		if ( '' !== $auth_token ) {
			\ZipAI\MCP\Classes\Core\Site_Scanner::fire_scan_request();
		}

		// Redirect back to the assistant screen after successful authentication.
		// This prevents users from landing on a generic admin URL with callback query params.
		wp_safe_redirect( admin_url( 'options-general.php?page=zip-ai-assistant' ) );
		exit;
	}

	/**
	 * Upload a base64 image to the WordPress Media Library.
	 *
	 * Expects POST params: nonce, base64_data, mime_type.
	 *
	 * @since 0.0.5
	 * @return void
	 */
	public function zip_ai_upload_media() {
		// Check nonce for security.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? $_POST['nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'zip_ai_iframe' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to upload files.' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw base64 decoded server-side
		$base64_data = isset( $_POST['base64_data'] ) && is_string( $_POST['base64_data'] ) ? $_POST['base64_data'] : '';
		$mime_type   = isset( $_POST['mime_type'] ) && is_string( $_POST['mime_type'] ) ? sanitize_text_field( $_POST['mime_type'] ) : '';

		if ( empty( $base64_data ) || empty( $mime_type ) ) {
			wp_send_json_error( array( 'message' => 'Missing base64 data or MIME type.' ), 400 );
		}

		$media_service = new \ZipAI\MCP\Classes\Services\Media_Service();
		$attachment_id = $media_service->upload_from_base64( $base64_data, $mime_type );

		if ( is_wp_error( $attachment_id ) ) {
			Utils::debug_log( 'Media upload failed', $attachment_id->get_error_message() );
			wp_send_json_error( array( 'message' => "That image couldn't be uploaded. Please try again." ), 500 );
		}

		wp_send_json_success(
			array(
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
			) 
		);
	}
}
