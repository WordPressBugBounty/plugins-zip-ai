<?php
/**
 * Tool Response Helper
 *
 * IMPORTANT: All tool handlers MUST use this class for responses.
 * This ensures consistent response format for the server and AI.
 *
 * Standard Response Format:
 * - Success: ['success' => true, 'message' => '...', 'data' => [...]]
 * - Error:   ['success' => false, 'error' => '...']
 *
 * Usage:
 *   use ZipAI\MCP\Classes\Core\Response;
 *
 *   // Success response
 *   return Response::success( 'Page created successfully.' );
 *   return Response::success( 'Page created successfully.', ['id' => 123, 'url' => '...'] );
 *
 *   // Error response
 *   return Response::error( 'Page title is required.' );
 *
 *   // From WP_Error
 *   if ( is_wp_error( $result ) ) {
 *       return Response::from_wp_error( $result );
 *   }
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Response Class - Enforces consistent response format for all tools.
 */
class Response {

	/**
	 * Create a success response.
	 *
	 * @param mixed                $message Success message for the AI to communicate to user.
	 * @param array<string, mixed> $data    Optional additional data (e.g., created resource details).
	 * @return array<string, mixed> Standardized success response.
	 */
	public static function success( $message, $data = array() ) {
		$response = array(
			'success' => true,
			'message' => $message,
		);

		if ( ! empty( $data ) ) {
			$response['data'] = $data;
		}

		return $response;
	}

	/**
	 * Create an error response.
	 *
	 * @param mixed                $message Error message for the AI to communicate to user.
	 * @param string               $suggestion Optional suggestion for the AI on how to resolve the issue.
	 * @param array<string, mixed> $data    Optional structured detail (e.g. per-item results of a
	 *                        partially-failed batch) so the caller can see exactly
	 *                        what failed without parsing the message string.
	 * @return array<string, mixed> Standardized error response.
	 */
	public static function error( $message, $suggestion = '', $data = array() ) {
		$response = array(
			'success' => false,
			'error'   => $message,
		);

		if ( ! empty( $suggestion ) ) {
			$response['suggestion'] = $suggestion;
		}

		if ( ! empty( $data ) ) {
			$response['data'] = $data;
		}

		return $response;
	}

	/**
	 * Create an error response from WP_Error.
	 *
	 * @param mixed $wp_error WordPress error object (guarded — non-WP_Error input is tolerated).
	 * @return array<string, mixed> Standardized error response.
	 */
	public static function from_wp_error( $wp_error ) {
		if ( ! is_wp_error( $wp_error ) ) {
			return self::error( 'An unknown error occurred.' );
		}

		return self::error( $wp_error->get_error_message() );
	}
}
