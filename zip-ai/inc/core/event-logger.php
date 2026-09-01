<?php
/**
 * Event Logger - Central audit trail for MCP actions
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Event_Logger
 */
class Event_Logger {

	/**
	 * Log an event to the audit trail.
	 *
	 * @param string              $tool_id     The name/id of the tool executed.
	 * @param array<string,mixed> $input       The input arguments.
	 * @param array<string,mixed> $output      The execution result.
	 * @param array<string,mixed> $performance Performance metrics (time, memory).
	 * @return void
	 */
	public static function log( $tool_id, $input, $output, $performance = array() ) {
		// In a real scenario, this might log to a custom DB table.
		// For the prototype, we use an option-based circular buffer.
		$logs = get_option( 'zip_ai_audit_log', array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$new_entry = array(
			'timestamp'   => current_time( 'mysql' ),
			'user_id'     => get_current_user_id(),
			'tool_id'     => $tool_id,
			'input'       => self::sanitize_data( $input ),
			'output'      => self::sanitize_data( $output ),
			'performance' => $performance,
			'success'     => ! empty( $output['success'] ),
		);

		array_unshift( $logs, $new_entry );

		// Keep only the last 100 entries.
		$logs = array_slice( $logs, 0, 100 );

		update_option( 'zip_ai_audit_log', $logs, false );
	}

	/**
	 * Sanitize sensitive data before logging.
	 *
	 * @param mixed $data Data to sanitize.
	 * @return mixed Sanitized data.
	 */
	private static function sanitize_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sensitive_keys = array( 'password', 'key', 'token', 'secret' );

		foreach ( $data as $key => &$value ) {
			if ( in_array( strtolower( $key ), $sensitive_keys, true ) ) {
				$value = '********';
			} elseif ( is_array( $value ) ) {
				$value = self::sanitize_data( $value );
			}
		}

		return $data;
	}
}
