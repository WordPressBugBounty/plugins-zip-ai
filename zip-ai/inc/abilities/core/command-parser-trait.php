<?php
/**
 * Command Parser Trait — tokenise a WP-CLI command string and decode
 * WP-CLI JSON stdout.
 *
 * Extracted from RunWpCli. The tokeniser is a pure function on a string
 * with no WordPress dependencies; the JSON-decode helper depends only
 * on the shared Response class.
 *
 * `parse_flags` is intentionally NOT in this trait — it is consumed only
 * by `dispatch_native` and stays alongside the dispatcher.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Core;

defined( 'ABSPATH' ) || exit;

use ZipAI\MCP\Classes\Core\Response;

/**
 * Trait holding `parse_command_to_args` and `parse_output`.
 */
trait Command_Parser_Trait {

	/**
	 * Tokenise a WP-CLI command string into argv-style tokens.
	 *
	 * Handles single-quoted, double-quoted, and unquoted tokens. Returns
	 * WP_Error if the string contains unclosed quotes.
	 *
	 * @param string $command Command string without leading "wp".
	 * @return string[]|\WP_Error
	 */
	private function parse_command_to_args( string $command ) {
		$args      = array();
		$token     = '';
		$in_sq     = false;
		$in_dq     = false;
		$has_token = false;
		$len       = strlen( $command );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $command[ $i ];

			if ( $in_sq ) {
				if ( "'" === $ch ) {
					// POSIX '\'' concat trick — let a literal single quote through.
					if ( $i + 3 < $len
						&& '\\' === $command[ $i + 1 ]
						&& "'" === $command[ $i + 2 ]
						&& "'" === $command[ $i + 3 ]
					) {
						$token .= "'";
						$i     += 3;
						continue;
					}
					$in_sq = false;
				} else {
					$token .= $ch;
				}
				continue;
			}

			if ( $in_dq ) {
				if ( '"' === $ch ) {
					$in_dq = false;
				} elseif ( '\\' === $ch && $i + 1 < $len ) {
					$next = $command[ $i + 1 ];
					if ( '"' === $next || '\\' === $next ) {
						$token .= $next;
						++$i;
					} else {
						$token .= $ch;
					}
				} else {
					$token .= $ch;
				}
				continue;
			}

			if ( "'" === $ch ) {
				$in_sq     = true;
				$has_token = true;
				continue;
			}
			if ( '"' === $ch ) {
				$in_dq     = true;
				$has_token = true;
				continue;
			}
			if ( ' ' === $ch || "\t" === $ch ) {
				if ( $has_token || '' !== $token ) {
					$args[]    = $token;
					$token     = '';
					$has_token = false;
				}
				continue;
			}

			$token    .= $ch;
			$has_token = true;
		}

		if ( $in_sq || $in_dq ) {
			return new \WP_Error( 'unclosed_quote', 'Command contains an unclosed quote.' );
		}

		if ( $has_token || '' !== $token ) {
			$args[] = $token;
		}

		return $args;
	}

	/**
	 * Attempt to JSON-decode stdout from WP-CLI; fall back to the raw string.
	 *
	 * @param string $raw Raw stdout.
	 * @return array<string,mixed> Response data.
	 */
	private function parse_output( string $raw ): array {
		if ( '' === $raw ) {
			return Response::success( '' );
		}
		$decoded = json_decode( $raw, true );
		return JSON_ERROR_NONE === json_last_error() ? Response::success( $decoded ) : Response::success( $raw );
	}
}
