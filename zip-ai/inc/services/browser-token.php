<?php
/**
 * Browser Token. The short-lived, narrow credential wp-admin JavaScript uses
 * to reach the ZIP AI server, in place of the site token.
 *
 * The site token (Sanctum, no expiry) stays in PHP. Any script on a wp-admin
 * page can read what the page is given, so the page only ever gets a browser
 * token minted from the site token server-to-server
 * (`POST /api/auth/browser-token`): it expires after an hour, carries only
 * the `agent:browser` ability, cannot manage tokens, rebind the WordPress
 * credential or change account settings, and dies with its site token.
 *
 * One cached browser token per site token, encrypted at rest, reused until it
 * is close to expiry. The cache key is derived from the site token, so a
 * reconnect (new site token) never reuses a browser token of the old one.
 *
 * @since 1.0.0
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Services;

use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Core\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Mints and caches the browser token.
 */
class Browser_Token {

	/**
	 * A cached token with less life left than this is replaced, so the page
	 * never starts out holding one about to lapse.
	 */
	const RENEW_MARGIN = 10 * MINUTE_IN_SECONDS;

	/**
	 * The current browser token, minting one when none is cached or the cached
	 * one is close to expiry.
	 *
	 * @param bool $force Mint a fresh one even when a cached one is still good.
	 * @return array{token: string, expires_at: int} Empty token when the site is not connected or the server refused.
	 */
	public static function get( $force = false ) {
		$empty      = array(
			'token'      => '',
			'expires_at' => 0,
		);
		$site_token = Helper::get_decrypted_auth_token();
		if ( '' === $site_token ) {
			return $empty;
		}

		$cache_key = 'zip_ai_browser_token_' . substr( hash( 'sha256', $site_token ), 0, 16 );

		if ( ! $force ) {
			// A mint just failed: don't hold every admin page load on another
			// remote call. The page's 401 renewal (forced) still tries.
			if ( get_transient( $cache_key . '_failed' ) ) {
				return $empty;
			}
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['token'], $cached['expires_at'] ) && is_string( $cached['token'] ) && is_int( $cached['expires_at'] ) ) {
				$token = Utils::decrypt( $cached['token'] );
				if ( '' !== $token && $cached['expires_at'] - time() > self::RENEW_MARGIN ) {
					return array(
						'token'      => $token,
						'expires_at' => $cached['expires_at'],
					);
				}
			}
		}

		$minted = self::mint( $site_token );
		if ( '' === $minted['token'] ) {
			set_transient( $cache_key . '_failed', 1, MINUTE_IN_SECONDS );
			return $empty;
		}
		delete_transient( $cache_key . '_failed' );

		set_transient(
			$cache_key,
			array(
				'token'      => Utils::encrypt( $minted['token'] ),
				'expires_at' => $minted['expires_at'],
			),
			max( 1, $minted['expires_at'] - time() - self::RENEW_MARGIN )
		);

		return $minted;
	}

	/**
	 * Trade the site token for a fresh browser token.
	 *
	 * @param string $site_token Decrypted site token.
	 * @return array{token: string, expires_at: int}
	 */
	private static function mint( $site_token ) {
		$empty = array(
			'token'      => '',
			'expires_at' => 0,
		);

		$response = wp_remote_post(
			ZIPAI_MCP_CREDIT_SERVER_API . 'auth/browser-token',
			array(
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $site_token,
				),
				'body'      => '{}',
				'timeout'   => 5,
				'sslverify' => Helper::should_verify_ssl(),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[zip-ai] auth/browser-token failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $empty;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $data ) || empty( $data['token'] ) || ! is_string( $data['token'] ) ) {
			error_log( sprintf( '[zip-ai] auth/browser-token returned HTTP %d', $status ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $empty;
		}

		$expires_at = isset( $data['expires_at'] ) && is_string( $data['expires_at'] ) ? strtotime( $data['expires_at'] ) : false;

		return array(
			'token'      => $data['token'],
			// A server that omits the expiry still issues an hour; assume less.
			'expires_at' => false !== $expires_at ? $expires_at : time() + 30 * MINUTE_IN_SECONDS,
		);
	}
}
