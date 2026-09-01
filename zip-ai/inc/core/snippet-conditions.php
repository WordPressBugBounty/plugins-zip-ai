<?php
/**
 * Snippet Conditions — evaluates conditional logic for snippet execution.
 *
 * Data model (one-row-per-type):
 *   - Each row is one constraint: { type, operator, value?, values?, meta? }
 *   - At most one row per `type` — enforced by Snippet_Store::sanitize_conditions.
 *   - All rows AND together (every constraint must match).
 *   - Multi-value operators (`in`/`not_in`/`starts_with`/`contains`/`ends_with`/`regex`)
 *     OR across the values within a single row.
 *
 * Late condition types (page/post_type/post/device) require the main query to
 * be set up. For early hooks the executor defers to `template_redirect` if any
 * row references a late type.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Snippet_Conditions {

	/**
	 * Condition types that require the main query (after `wp` action).
	 *
	 * @var array<int,string>
	 */
	private static $late_condition_types = array( 'page', 'post_type', 'post', 'device' );

	// ── Public ────────────────────────────────────────────────────────────

	/**
	 * Evaluate an array of conditions.
	 *
	 * Connector semantics: each row has an optional `connector` ("and" | "or",
	 * default "and"; ignored on the first row). Consecutive AND rows form a
	 * chain; chains OR together (standard SQL precedence — AND binds tighter
	 * than OR). Empty array means "runs everywhere".
	 *
	 * Examples:
	 *   [post=2051, OR device=mobile]                    → post=2051 OR device=mobile
	 *   [post=2051, AND user_role=admin, OR device=mobile]
	 *     → (post=2051 AND user_role=admin) OR device=mobile
	 *
	 * @param array<int,array<string,mixed>> $conditions Sanitized condition rows.
	 * @param bool                           $is_early   True when the hook fires before `wp`.
	 * @return bool
	 */
	public static function evaluate( array $conditions, $is_early = false ) {
		$chains = self::build_chains( $conditions );
		if ( empty( $chains ) ) {
			return true;
		}

		foreach ( $chains as $chain ) {
			$chain_passed = true;
			foreach ( $chain as $cond ) {
				$type = $cond['type'] ?? '';
				if ( $is_early && in_array( $type, self::$late_condition_types, true ) ) {
					continue; // late types skipped on early hooks (executor defers)
				}
				if ( ! self::evaluate_single( $cond ) ) {
					$chain_passed = false;
					break; // AND short-circuit within chain
				}
			}
			if ( $chain_passed ) {
				return true; // OR short-circuit across chains
			}
		}

		return false;
	}

	/**
	 * Split a flat conditions list into AND-chains separated by OR-connectors.
	 * First row's connector is ignored. Returns array of chains; each chain is
	 * a non-empty list of row arrays.
	 *
	 * @param array<int,array<string,mixed>> $conditions Sanitized condition rows.
	 * @return array<int,array<int,array<string,mixed>>> AND-chains; each chain is a non-empty list of rows.
	 */
	public static function build_chains( array $conditions ) {
		$chains  = array();
		$current = array();
		foreach ( $conditions as $cond ) {
			if ( empty( $cond['type'] ?? '' ) ) {
				continue;
			}
			$connector = isset( $cond['connector'] ) && is_string( $cond['connector'] ) ? strtolower( $cond['connector'] ) : 'and';
			if ( 'or' === $connector && ! empty( $current ) ) {
				$chains[] = $current;
				$current  = array();
			}
			$current[] = $cond;
		}
		if ( ! empty( $current ) ) {
			$chains[] = $current;
		}
		return $chains;
	}

	/**
	 * Evaluate conditions with a per-row trace. Used by `simulate_match`,
	 * executor trace mode, and admin diagnostic UI.
	 *
	 * Output shape:
	 *   {
	 *     result: bool,
	 *     rows: [
	 *       { row, status: "pass"|"fail"|"skipped", reason }
	 *     ]
	 *   }
	 *
	 * @param array<int,array<string,mixed>> $conditions Sanitized condition rows.
	 * @param bool                           $is_early   True when the hook fires before `wp`.
	 * @return array<string,mixed> Trace result with a `result` bool and per-chain `chains`.
	 */
	public static function evaluate_with_trace( array $conditions, $is_early = false ) {
		$chains = self::build_chains( $conditions );
		if ( empty( $chains ) ) {
			return array(
				'result' => true,
				'chains' => array(),
				'reason' => 'empty conditions → runs everywhere',
			);
		}

		$chain_traces = array();
		$final        = false;

		foreach ( $chains as $chain ) {
			$chain_passed = true;
			$row_traces   = array();
			foreach ( $chain as $cond ) {
				$type = is_string( $cond['type'] ?? null ) ? $cond['type'] : '';
				if ( $is_early && in_array( $type, self::$late_condition_types, true ) ) {
					$row_traces[] = array(
						'row'    => $cond,
						'status' => 'skipped',
						'reason' => sprintf( 'late type "%s" on early hook — deferred', $type ),
					);
					continue;
				}
				$result       = self::evaluate_single( $cond );
				$row_traces[] = array(
					'row'    => $cond,
					'status' => $result ? 'pass' : 'fail',
					'reason' => self::describe_row_outcome( $cond, $result ),
				);
				if ( ! $result ) {
					$chain_passed = false;
				}
			}
			$chain_traces[] = array(
				'passed' => $chain_passed,
				'rows'   => $row_traces,
			);
			if ( $chain_passed ) {
				$final = true;
			}
		}

		return array(
			'result' => $final,
			'chains' => $chain_traces,
		);
	}

	/**
	 * Per-row natural-language outcome string. Reports the runtime value the
	 * evaluator compared against so authors can debug a failing row at a
	 * glance.
	 *
	 * @param array<string,mixed> $cond   Condition row being described.
	 * @param bool                $result Whether the row matched.
	 * @return string Human-readable outcome for the row.
	 */
	private static function describe_row_outcome( array $cond, $result ) {
		$type = $cond['type'] ?? '';
		$verb = $result ? 'matched' : 'did not match';

		switch ( $type ) {
			case 'post_type':
				$current = self::current_post_type();
				return sprintf( '%s — current post_type=%s', $verb, $current ?? '(none)' );
			case 'post':
				$current = self::current_post_id();
				return sprintf( '%s — current post_id=%s', $verb, $current ? $current : '(none)' );
			case 'page':
				$tokens = array();
				if ( function_exists( 'is_front_page' ) && ( is_front_page() || is_home() ) ) {
					$tokens[] = 'home';
				}
				if ( is_single() ) {
					$tokens[] = 'single'; }
				if ( is_page() ) {
					$tokens[] = 'page'; }
				if ( is_archive() ) {
					$tokens[] = 'archive'; }
				if ( is_search() ) {
					$tokens[] = 'search'; }
				if ( is_404() ) {
					$tokens[] = '404'; }
				$page_tokens = implode( ',', $tokens );
				return sprintf( '%s — current page=[%s]', $verb, $page_tokens ? $page_tokens : '(none)' );
			case 'user':
				return sprintf( '%s — logged_in=%s', $verb, is_user_logged_in() ? 'true' : 'false' );
			case 'user_role':
				if ( ! is_user_logged_in() ) {
					return sprintf( '%s — anonymous user', $verb );
				}
				$user = wp_get_current_user();
				return sprintf( '%s — current roles=[%s]', $verb, implode( ',', (array) $user->roles ) );
			case 'device':
				return sprintf( '%s — wp_is_mobile=%s', $verb, wp_is_mobile() ? 'true' : 'false' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_is_mobile_wp_is_mobile -- evaluates a device display-condition server-side; wp_is_mobile is the WP API with no alternative.
			case 'url_pattern':
				$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				return sprintf( '%s — current uri=%s', $verb, $uri );
			default:
				return $verb;
		}
	}

	// ── Dispatch ──────────────────────────────────────────────────────────

	/**
	 * Dispatch a single condition row to its per-type evaluator.
	 *
	 * @param array<string,mixed> $cond Condition row.
	 * @return bool Whether the condition matches the current request.
	 */
	private static function evaluate_single( array $cond ) {
		$type     = is_string( $cond['type'] ?? null ) ? $cond['type'] : '';
		$operator = is_string( $cond['operator'] ?? null ) ? $cond['operator'] : 'is';
		$values   = self::values_from_row( $cond );
		$meta     = is_array( $cond['meta'] ?? null ) ? $cond['meta'] : array();

		switch ( $type ) {
			case 'page':
				return self::evaluate_page( $operator, $values );
			case 'user':
				return self::evaluate_user( $operator, $values );
			case 'user_role':
				return self::evaluate_user_role( $operator, $values );
			case 'post_type':
				return self::evaluate_post_type( $operator, $values );
			case 'post':
				return self::evaluate_post( $operator, $values );
			case 'device':
				return self::evaluate_device( $operator, $values );
			case 'url_pattern':
				/**
				 * Narrowed type for `$meta`.
				 *
				 * @var array<string,mixed> $meta
				 */
				return self::evaluate_url_pattern( $operator, $values, $meta );
			default:
				// Fail closed on unknown types so typos block execution
				// instead of producing over-permissive behavior.
				if ( function_exists( 'error_log' ) ) {
					error_log( sprintf( '[ZIP AI Snippet] Unknown condition type "%s" — failing closed.', $type ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging for snippet execution; no WP core logger equivalent.
				}
				return false;
		}
	}

	/**
	 * Extract the non-empty comparison values from a condition row.
	 *
	 * @param array<string,mixed> $cond Condition row.
	 * @return array<int,string> Comparison values.
	 */
	private static function values_from_row( array $cond ) {
		if ( isset( $cond['values'] ) && is_array( $cond['values'] ) && ! empty( $cond['values'] ) ) {
			return array_values(
				array_map(
					'strval',
					array_filter(
						$cond['values'],
						static function ( $v ) {
							return null !== $v && '' !== $v && is_scalar( $v );
						}
					)
				)
			);
		}
		$single = $cond['value'] ?? '';
		if ( '' === $single || ! is_scalar( $single ) ) {
			return array();
		}
		return array( (string) $single );
	}

	// ── Operator helper ───────────────────────────────────────────────────

	/**
	 * Match a haystack against an operator + needle list. Multi-value
	 * operators (starts_with, contains, ends_with, regex) OR across needles:
	 * the row matches if ANY needle matches the haystack.
	 *
	 * @param string|int|null   $haystack Value being tested.
	 * @param string            $operator Comparison operator.
	 * @param array<int,string> $needles  List of comparison values.
	 * @return bool Whether the haystack matches under the operator.
	 */
	private static function match_value( $haystack, $operator, array $needles ) {
		if ( empty( $needles ) ) {
			return false;
		}
		$haystack = (string) $haystack;

		switch ( $operator ) {
			case 'is':
				return $haystack === (string) $needles[0];
			case 'is_not':
				return $haystack !== (string) $needles[0];
			case 'in':
				return in_array( $haystack, array_map( 'strval', $needles ), true );
			case 'not_in':
				return ! in_array( $haystack, array_map( 'strval', $needles ), true );
			case 'contains':
				foreach ( $needles as $n ) {
					if ( '' !== $n && false !== strpos( $haystack, (string) $n ) ) {
						return true;
					}
				}
				return false;
			case 'starts_with':
				foreach ( $needles as $n ) {
					if ( '' !== $n && 0 === strpos( $haystack, (string) $n ) ) {
						return true;
					}
				}
				return false;
			case 'ends_with':
				foreach ( $needles as $n ) {
					$nlen = strlen( (string) $n );
					if ( $nlen > 0 && substr( $haystack, -$nlen ) === (string) $n ) {
						return true;
					}
				}
				return false;
			case 'regex':
				foreach ( $needles as $n ) {
					if ( self::safe_regex_match( (string) $n, $haystack ) ) {
						return true;
					}
				}
				return false;
			default:
				return $haystack === (string) $needles[0];
		}
	}

	/**
	 * Run a user-supplied regex safely.
	 *
	 * @param string $pattern Regex pattern, optionally with delimiters.
	 * @param string $haystack Subject string to test.
	 * @return bool Whether the pattern matches the subject.
	 */
	private static function safe_regex_match( $pattern, $haystack ) {
		if ( strlen( $pattern ) > 256 || '' === $pattern ) {
			return false;
		}
		if ( ! preg_match( '/^([\/#~%@]).+\1[a-zA-Z]*$/s', $pattern ) ) {
			$pattern = '/' . str_replace( '/', '\/', $pattern ) . '/';
		}
		$ok = @preg_match( $pattern, $haystack ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- suppresses the warning from a malformed user-supplied regex pattern; failure returns false and is handled by the 1 === $ok return check.
		return 1 === $ok;
	}

	// ── Per-type evaluators ───────────────────────────────────────────────

	/**
	 * Evaluate a `page` condition against the current request context.
	 *
	 * @param string            $operator Comparison operator.
	 * @param array<int,string> $values   Page tokens or object IDs.
	 * @return bool Whether the condition matches.
	 */
	private static function evaluate_page( $operator, array $values ) {
		$pos = self::any_positive(
			$values,
			static function ( string $value ) {
				switch ( $value ) {
					case 'home':
						return is_front_page() || is_home();
					case 'single':
						return is_single();
					case 'page':
						return is_page();
					case 'archive':
						return is_archive();
					case '404':
						return is_404();
					case 'search':
						return is_search();
					default:
						if ( is_numeric( $value ) ) {
							return is_page( (int) $value ) || is_single( (int) $value );
						}
						return is_page( $value ) || is_single( $value );
				}
			} 
		);
		return self::apply_negation( $pos, $operator );
	}

	/**
	 * Evaluate a `user` (logged-in state) condition.
	 *
	 * @param string            $operator Comparison operator.
	 * @param array<int,string> $values   Expected user-state tokens.
	 * @return bool Whether the condition matches.
	 */
	private static function evaluate_user( $operator, array $values ) {
		$pos = self::any_positive(
			$values,
			static function ( $value ) {
				return 'logged_in' === $value && is_user_logged_in();
			} 
		);
		return self::apply_negation( $pos, $operator );
	}

	/**
	 * Evaluate a `user_role` condition against the current user's roles.
	 *
	 * @param string            $operator Comparison operator.
	 * @param array<int,string> $values   Role slugs to match.
	 * @return bool Whether the condition matches.
	 */
	private static function evaluate_user_role( $operator, array $values ) {
		if ( ! is_user_logged_in() ) {
			return self::apply_negation( false, $operator );
		}
		$user = wp_get_current_user();
		$pos  = self::any_positive(
			$values,
			static function ( $value ) use ( $user ) {
				return in_array( $value, (array) $user->roles, true );
			} 
		);
		return self::apply_negation( $pos, $operator );
	}

	/**
	 * Evaluate a `post_type` condition against the current post type.
	 *
	 * @param string            $operator Comparison operator.
	 * @param array<int,string> $values   Post-type slugs to match.
	 * @return bool Whether the condition matches.
	 */
	private static function evaluate_post_type( $operator, array $values ) {
		$current = self::current_post_type();
		if ( null === $current ) {
			return self::apply_negation( false, $operator );
		}
		return self::match_value( $current, self::expand_operator( $operator ), $values );
	}

	/**
	 * Evaluate a `post` condition against the current post ID.
	 *
	 * @param string            $operator Comparison operator.
	 * @param array<int,string> $values   Post IDs to match.
	 * @return bool Whether the condition matches.
	 */
	private static function evaluate_post( $operator, array $values ) {
		$post_id = self::current_post_id();
		if ( ! $post_id ) {
			return self::apply_negation( false, $operator );
		}
		return self::match_value( (string) $post_id, self::expand_operator( $operator ), $values );
	}

	/**
	 * Evaluate a `device` condition (mobile detection).
	 *
	 * @param string            $operator Comparison operator.
	 * @param array<int,string> $values   Device tokens to match.
	 * @return bool Whether the condition matches.
	 */
	private static function evaluate_device( $operator, array $values ) {
		$pos = self::any_positive(
			$values,
			static function ( $value ) {
				return 'mobile' === $value && wp_is_mobile(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_is_mobile_wp_is_mobile -- evaluates a device display-condition server-side; wp_is_mobile is the WP API with no alternative.
			} 
		);
		return self::apply_negation( $pos, $operator );
	}

	/**
	 * Evaluate a `url_pattern` condition against the request URI.
	 *
	 * @param string              $operator Comparison operator.
	 * @param array<int,string>   $values   URL patterns to match.
	 * @param array<string,mixed> $meta     Optional row metadata.
	 * @return bool Whether the condition matches.
	 */
	private static function evaluate_url_pattern( $operator, array $values, array $meta ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( empty( $values ) ) {
			return false;
		}
		$op = $operator ? $operator : 'starts_with';
		return self::match_value( $uri, $op, $values );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * True when any value passes the given check.
	 *
	 * @param array<int,string> $values Values to test.
	 * @param callable          $check  Predicate applied to each value.
	 * @return bool Whether any value passed.
	 */
	private static function any_positive( array $values, callable $check ) {
		foreach ( $values as $v ) {
			if ( $check( $v ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Invert the result for negating operators (`is_not`, `not_in`).
	 *
	 * @param bool   $result   Positive match result.
	 * @param string $operator Comparison operator.
	 * @return bool Result after applying negation.
	 */
	private static function apply_negation( $result, $operator ) {
		return ( 'is_not' === $operator || 'not_in' === $operator ) ? ! $result : (bool) $result;
	}

	/**
	 * Map `is`/`is_not` to their multi-value `in`/`not_in` equivalents.
	 *
	 * @param string $operator Comparison operator.
	 * @return string Expanded operator.
	 */
	private static function expand_operator( $operator ) {
		if ( 'is' === $operator ) {
			return 'in';
		}
		if ( 'is_not' === $operator ) {
			return 'not_in';
		}
		return $operator;
	}

	/**
	 * Resolve the current request's post type.
	 *
	 * @return string|null Post type, or null when none.
	 */
	private static function current_post_type() {
		$current = get_post_type();
		if ( $current ) {
			return $current;
		}
		$queried = get_queried_object();
		if ( $queried instanceof \WP_Post_Type ) {
			return $queried->name;
		}
		$qv = get_query_var( 'post_type' );
		if ( is_array( $qv ) && ! empty( $qv ) && is_string( $qv[0] ) ) {
			return $qv[0];
		}
		if ( is_string( $qv ) && '' !== $qv ) {
			return $qv;
		}
		return null;
	}

	/**
	 * Resolve the current request's post ID.
	 *
	 * @return int Post ID, or 0 when none.
	 */
	private static function current_post_id() {
		$id = get_the_ID();
		if ( $id ) {
			return (int) $id;
		}
		$queried = get_queried_object();
		if ( $queried && isset( $queried->ID ) && is_numeric( $queried->ID ) ) {
			return (int) $queried->ID;
		}
		return 0;
	}
}
