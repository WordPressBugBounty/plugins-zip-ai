<?php
/**
 * Browser token REST API — renewal of the page's browser token, and the
 * account writes a browser token is not allowed to make itself.
 *
 * Cookie/nonce authenticated and `manage_options` only (the same audience the
 * chat is rendered for). The site token never leaves PHP:
 *   - POST /zip-ai/v1/browser-token mints a fresh browser token for the page.
 *   - The account routes relay a write to the ZIP AI server with the site
 *     token, because the server refuses those writes from a browser token.
 *     Status and JSON body come back unchanged, so the chat's request gateway
 *     reads the server's errors exactly as before.
 *
 * @since 1.0.0
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Api;

use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Services\Browser_Token;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the browser-token and account-relay routes.
 */
class Browser_Token_REST_API {

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
			'/browser-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'renew' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'zip-ai/v1',
			'/account/llm-settings',
			array(
				'methods'             => 'PATCH, DELETE',
				'callback'            => array( $this, 'relay_llm_settings' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'zip-ai/v1',
			'/account/connected-sites/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'relay_disconnect' ),
				'permission_callback' => $permission,
				'args'                => array(
					'identifier_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Only an administrator, who is shown the chat, may hold a browser token.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Mint a fresh browser token for the page.
	 *
	 * @return \WP_REST_Response
	 */
	public function renew() {
		$browser = Browser_Token::get( true );
		if ( '' === $browser['token'] ) {
			return new \WP_REST_Response(
				array(
					'error_code'   => 'browser_token_unavailable',
					'user_message' => __( 'Could not reach ZIP AI. Reload the page and try again.', 'zip-ai' ),
				),
				503
			);
		}

		return new \WP_REST_Response(
			array(
				'token'      => $browser['token'],
				'expires_at' => $browser['expires_at'],
			),
			200
		);
	}

	/**
	 * Relay a BYO LLM settings update (PATCH) or removal (DELETE).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response
	 */
	public function relay_llm_settings( $request ) {
		$body = array();
		if ( 'PATCH' === $request->get_method() ) {
			$params = $request->get_json_params();
			foreach ( array( 'api_key', 'model', 'page_builder_model', 'image_model' ) as $key ) {
				if ( isset( $params[ $key ] ) && is_string( $params[ $key ] ) ) {
					$body[ $key ] = sanitize_text_field( $params[ $key ] );
				}
			}
			if ( isset( $params['enabled'] ) ) {
				$body['enabled'] = (bool) $params['enabled'];
			}
		}

		return $this->relay( $request->get_method(), 'agent/llm-settings', $body );
	}

	/**
	 * Relay a connected-site disconnect.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response
	 */
	public function relay_disconnect( $request ) {
		$identifier_id = $request->get_param( 'identifier_id' );

		return $this->relay(
			'POST',
			'agent/connected-sites/disconnect',
			array( 'identifier_id' => is_numeric( $identifier_id ) ? (int) $identifier_id : 0 )
		);
	}

	/**
	 * Send one request to the ZIP AI server with the site token and pass its
	 * status and JSON body back.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Server path under the API root.
	 * @param array<string,mixed> $body   JSON body.
	 * @return \WP_REST_Response
	 */
	private function relay( $method, $path, $body ) {
		$site_token = Helper::get_decrypted_auth_token();
		if ( '' === $site_token ) {
			return new \WP_REST_Response(
				array(
					'error_code'   => 'not_connected',
					'user_message' => __( 'This site is not connected to ZIP AI.', 'zip-ai' ),
				),
				401
			);
		}

		$response = wp_remote_request(
			ZIPAI_MCP_CREDIT_SERVER_API . $path,
			array(
				'method'    => $method,
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $site_token,
				),
				'body'      => (string) wp_json_encode( (object) $body ),
				'timeout'   => 20,
				'sslverify' => Helper::should_verify_ssl(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response(
				array(
					'error_code'   => 'server_unreachable',
					'user_message' => __( 'Could not reach ZIP AI. Try again.', 'zip-ai' ),
				),
				502
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return new \WP_REST_Response(
			is_array( $data ) ? $data : array(),
			(int) wp_remote_retrieve_response_code( $response )
		);
	}
}
