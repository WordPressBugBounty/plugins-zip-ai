<?php
/**
 * Brain Client. This is the ONE server-to-server seam from this plugin to the
 * ZIP AI server. The abilities that an external AI client reaches over MCP use
 * it. On those calls there is no browser to carry the Sanctum token.
 *
 * A remote POST has four failure classes. This class collapses them into one
 * result shape. So callers branch on `code` instead of re-deriving them:
 *   1. transport      — DNS / TLS / timeout (`WP_Error` from wp_remote_post)
 *   2. JSON error     — non-2xx with the server's `{error_code, message}` body
 *   3. non-JSON error — non-2xx with a host error page (Cloudflare, nginx 502)
 *   4. broken 2xx     — 200 with a body that isn't JSON
 *
 * Some callers are deliberately NOT migrated here. These are Helper's token
 * exchange and wp-credentials bind, and Site_Scanner's scan push. They keep
 * their own `wp_remote_post` blocks. New callers use this class.
 *
 * @since 0.0.8
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Services;

use ZipAI\MCP\Classes\Core\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Server-to-server POST client.
 */
class Brain_Client {

	/**
	 * Default request timeout. The server's import path converts and then commits
	 * page-by-page over MCP, which legitimately runs tens of seconds on a page
	 * with many images to sideload.
	 */
	const DEFAULT_TIMEOUT = 120;

	/**
	 * POST a JSON body to a server path with the site's Sanctum token.
	 *
	 * @param string              $path    Server path, leading slash (e.g. '/import').
	 * @param array<string,mixed> $body    JSON body.
	 * @param int                 $timeout Seconds.
	 * @return array{ok: bool, status: int, code: string, message: string, data: array<string,mixed>}
	 */
	public static function post( string $path, array $body, int $timeout = self::DEFAULT_TIMEOUT ) {
		$token = Helper::get_decrypted_auth_token();
		if ( '' === $token ) {
			return self::result( false, 0, 'not_connected', 'This site is not connected to a ZIP AI account.' );
		}

		$encoded = wp_json_encode( $body );
		if ( false === $encoded ) {
			return self::result( false, 0, 'encode_failed', 'Request body could not be encoded.' );
		}

		$response = wp_remote_post(
			untrailingslashit( ZIPAI_BRAIN_URL ) . $path,
			array(
				'timeout'   => $timeout,
				'sslverify' => Helper::should_verify_ssl(),
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'      => $encoded,
			)
		);

		// 1. Transport — the request never reached the server, so nothing ran.
		if ( is_wp_error( $response ) ) {
			return self::result(
				false,
				0,
				'brain_unreachable',
				'Could not reach the ZIP AI service: ' . $response->get_error_message()
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );
		// JSON object keys are strings by definition, but `json_decode` is typed
		// loosely — normalise once here so callers read a known-shaped array
		// instead of narrowing the key type at every access.
		$data = array();
		if ( is_array( $parsed ) ) {
			foreach ( $parsed as $key => $value ) {
				$data[ (string) $key ] = $value;
			}
		}

		if ( $status >= 200 && $status < 300 ) {
			// 4. A 2xx we cannot read is a failure, not a success — returning
			// ok:true here would have the caller report an import that may not
			// have happened.
			if ( ! is_array( $parsed ) ) {
				return self::result( false, $status, 'bad_response', 'The ZIP AI service returned an unreadable response.' );
			}
			return self::result( true, $status, '', '', $data );
		}

		// 2. Typed server error. `message` and `error` carry the same text (the
		// server aliases them); prefer `message`.
		$code = isset( $data['error_code'] ) && is_string( $data['error_code'] ) ? $data['error_code'] : '';
		foreach ( array( 'message', 'error' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== $data[ $key ] ) {
				return self::result( false, $status, '' !== $code ? $code : 'http_' . $status, $data[ $key ], $data );
			}
		}

		// 3. Non-JSON error body — a host error page, not the server speaking.
		// The body is never surfaced: it is HTML, often multi-KB, and would be
		// pasted straight into an AI client's context.
		return self::result(
			false,
			$status,
			'' !== $code ? $code : 'http_' . $status,
			sprintf( 'The ZIP AI service returned HTTP %d.', $status ),
			$data
		);
	}

	/**
	 * Build the uniform result array.
	 *
	 * @param bool                $ok      Whether the call succeeded.
	 * @param int                 $status  HTTP status (0 when no response).
	 * @param string              $code    Stable error code ('' on success).
	 * @param string              $message Human/agent-readable text ('' on success).
	 * @param array<string,mixed> $data    Decoded body.
	 * @return array{ok: bool, status: int, code: string, message: string, data: array<string,mixed>}
	 */
	private static function result( bool $ok, int $status, string $code, string $message, array $data = array() ) {
		return array(
			'ok'      => $ok,
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
			'data'    => $data,
		);
	}
}
