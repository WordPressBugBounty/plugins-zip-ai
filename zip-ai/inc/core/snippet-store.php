<?php
/**
 * Snippet Store — single source of truth for ZIP AI snippet manifest I/O.
 *
 * All classes that read or write the snippet manifest MUST use this class.
 * Never read/write manifest.php directly.
 *
 * Storage format: manifest.php with `<?php exit;` guard on line 1, JSON on line 2+.
 * This prevents HTTP access on ALL servers (Apache, Nginx, LiteSpeed).
 *
 * @since 0.1.0
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists and retrieves user-defined MCP snippets on disk.
 *
 * @phpstan-type ConditionRow array{type: string, operator: string, values: list<string>, connector: string, meta: array<string, mixed>}
 */
class Snippet_Store {

	/**
	 * PHP exit guard prepended to manifest file.
	 *
	 * @var string
	 */
	private const GUARD = "<?php exit; // ZIP AI Snippets manifest. ?>\n";

	/**
	 * Opening tag + ABSPATH guard every stored PHP snippet file begins with.
	 * `wrap_php()` writes it, `unwrap_php()` matches it byte-for-byte.
	 *
	 * @var string
	 */
	private const PHP_WRAPPER_PREFIX = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n";

	/**
	 * Allowed file types for snippets.
	 *
	 * @since 0.0.5
	 * @var list<string>
	 */
	const ALLOWED_TYPES = array( 'php', 'js', 'css', 'html' );

	/**
	 * Valid PHP execution hooks.
	 *
	 * @since 0.0.5
	 * @var list<string>
	 */
	const VALID_PHP_HOOKS = array(
		'plugins_loaded',
		'init',
		'wp_loaded',
		'wp_enqueue_scripts',
		'wp_head',
		'wp_footer',
		'template_redirect',
		'admin_init',
		'admin_head',
		'admin_footer',
		'shutdown',
	);

	/**
	 * Valid JS execution hooks.
	 *
	 * @since 0.0.5
	 * @var list<string>
	 */
	const VALID_JS_HOOKS = array( 'wp_head', 'wp_footer', 'admin_head', 'admin_footer', 'login_head' );

	/**
	 * Valid CSS execution hooks.
	 *
	 * @since 0.0.5
	 * @var list<string>
	 */
	const VALID_CSS_HOOKS = array( 'wp_head', 'admin_head', 'login_head', 'wp_footer' );

	/**
	 * Valid HTML execution hooks. HTML snippets emit verbatim markup —
	 * they're for raw `<script>` loaders (GA, GTM, FB Pixel), JSON-LD,
	 * `<noscript>` blocks, OG tags — anything that JS/CSS can't express
	 * without being wrapped in another script/style tag by the executor.
	 *
	 * @since 0.0.5
	 * @var list<string>
	 */
	const VALID_HTML_HOOKS = array( 'wp_head', 'wp_footer', 'admin_head', 'admin_footer', 'login_head' );

	/**
	 * Valid execution scopes.
	 *
	 * @since 0.0.5
	 * @var list<string>
	 */
	const VALID_SCOPES = array( 'frontend', 'admin', 'everywhere', 'login' );

	/**
	 * Valid condition types — single source consumed by REST sanitizer,
	 * agent-ability sanitizer, and the Conditions evaluator.
	 *
	 * @since 0.0.5
	 * @var list<string>
	 */
	const VALID_CONDITION_TYPES = array(
		'page',
		'user',
		'user_role',
		'post_type',
		'post',
		'device',
		'url_pattern',
	);

	/**
	 * Valid condition operators — single source.
	 *
	 * @since 0.0.5
	 * @var list<string>
	 */
	const VALID_CONDITION_OPERATORS = array(
		'is',
		'is_not',
		'in',
		'not_in',
		'contains',
		'starts_with',
		'ends_with',
		'regex',
	);

	/**
	 * Default blocked PHP function names. Filterable via
	 * `zip_ai_snippets_blocked_functions` (additive only — defaults can never
	 * be removed).
	 *
	 * @since 0.0.5
	 * @return list<string>
	 */
	public static function default_blocked_functions() {
		return array(
			// Shell execution
			'exec',
			'shell_exec',
			'system',
			'passthru',
			'popen',
			'proc_open',
			'pcntl_exec',
			// Destructive WordPress
			'wp_delete_site',
			'wp_uninitialize_site',
			'drop_tables',
			// Dynamic code execution
			'eval',
			'assert',
			'create_function',
			// Filesystem permissions
			'chmod',
			'chown',
			'chgrp',
			'symlink',
			'link',
			// Environment manipulation
			'putenv',
			'dl',
			// PHP internals exposure
			'phpinfo',
			'show_source',
			'highlight_file',
			// Process manipulation
			'proc_nice',
			'proc_terminate',
			// Low-level sockets — use wp_remote_* instead
			'fsockopen',
			'stream_socket_client',
		);
	}

	/**
	 * Get effective blocked-functions list (defaults + user filter additions).
	 * Defaults are never removable.
	 *
	 * @since 0.0.5
	 * @return list<string>
	 */
	public static function blocked_functions() {
		$defaults = self::default_blocked_functions();
		$custom   = array_map( array( Utils::class, 'to_str' ), (array) apply_filters( 'zip_ai_snippets_blocked_functions', $defaults ) );
		return array_values( array_unique( array_merge( $defaults, $custom ) ) );
	}

	/**
	 * Detect a PHP shell-execution backtick operator in `$code`.
	 *
	 * A naïve `preg_match('/`/', ...)` false-positives on backticks that live
	 * inside embedded HTML / JS / CSS blocks of a PHP file (JS template
	 * literals such as `` `hello ${name}` ``), which are never evaluated by
	 * PHP and pose no risk. PHP's own tokenizer is the only reliable way to
	 * distinguish the shell-exec operator from those benign occurrences.
	 *
	 * @since 0.0.5
	 * @param string $code Snippet body as authored by the user (no opening tag).
	 * @return bool True iff a real PHP shell-exec backtick token exists.
	 */
	public static function has_php_shell_exec_backticks( $code ) {
		if ( strpos( $code, '`' ) === false ) {
			return false;
		}

		$prefixed = ( strncmp( ltrim( $code ), '<?', 2 ) === 0 ) ? $code : "<?php\n" . $code;
		$tokens   = @token_get_all( $prefixed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- tokenizer may warn on malformed user PHP.

		foreach ( $tokens as $token ) {
			// token_get_all returns either [id, text, line] arrays for named
			// tokens or raw single-character strings for punctuation. The
			// shell-exec operator surfaces as the bare '`' character; backticks
			// inside T_INLINE_HTML / T_CONSTANT_ENCAPSED_STRING /
			// T_ENCAPSED_AND_WHITESPACE / comments are absorbed into the
			// surrounding token's text and never appear at this layer.
			if ( is_string( $token ) && '`' === $token ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scan PHP code for blocked dangerous functions / patterns.
	 *
	 * Single source — replaces the previously-duplicated private
	 * `check_php_blocklist()` methods in `ManageCodeSnippet` and the
	 * snippet REST controller. Returns the offending function or pattern
	 * name (suitable for use in an error message) or null when clean.
	 *
	 * @since 0.0.5
	 * @param string $code Snippet body as authored by the user.
	 * @return string|null Blocked name, or null when clean.
	 */
	public static function check_php_blocklist( $code ) {
		foreach ( self::blocked_functions() as $func ) {
			if ( preg_match( '/\b' . preg_quote( $func, '/' ) . '\s*\(/i', $code ) ) {
				return $func;
			}
			if ( preg_match( '/[\'"]' . preg_quote( $func, '/' ) . '[\'"]/i', $code ) ) {
				return "{$func} (as string reference)";
			}
		}

		if ( self::has_php_shell_exec_backticks( $code ) ) {
			return 'shell execution (backticks)';
		}

		if ( preg_match( '/preg_replace\s*\(\s*[\'"].*\/[a-z]*e[a-z]*[\'"]/i', $code ) ) {
			return 'preg_replace with /e modifier';
		}

		return null;
	}

	/**
	 * Per-file-type valid hook list lookup.
	 *
	 * @since 0.0.5
	 * @param string $type File type (php, js, css, html).
	 * @return list<string>
	 */
	public static function valid_hooks_for( $type ) {
		switch ( $type ) {
			case 'php':
				return self::VALID_PHP_HOOKS;
			case 'js':
				return self::VALID_JS_HOOKS;
			case 'css':
				return self::VALID_CSS_HOOKS;
			case 'html':
				return self::VALID_HTML_HOOKS;
			default:
				return array();
		}
	}

	/**
	 * Whether a hook name is safe to persist and execute.
	 *
	 * PHP snippets may run directly on third-party plugin hooks. JS/CSS keep a
	 * curated list because they are mapped onto WordPress enqueue actions.
	 *
	 * @since 0.0.5
	 * @param string $type File type.
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public static function is_valid_execution_hook( $type, $hook ) {
		$hook = (string) $hook;
		if ( in_array( $hook, self::valid_hooks_for( $type ), true ) ) {
			return true;
		}
		if ( 'php' !== $type ) {
			return false;
		}
		return self::is_safe_custom_php_hook( $hook );
	}

	/**
	 * Validate custom PHP hook names.
	 *
	 * WordPress hook names are arbitrary strings, but snippets should only
	 * persist names that are readable, bounded, and free of whitespace/control
	 * characters. This allows common third-party hooks like
	 * `woocommerce_before_cart`, `elementor/frontend/after_render`, and
	 * `acf/save_post`.
	 *
	 * @since 0.0.5
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public static function is_safe_custom_php_hook( $hook ) {
		$hook = trim( (string) $hook );
		if ( '' === $hook || strlen( $hook ) > 128 ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z_][A-Za-z0-9_\\.\\/:\\-]*$/', $hook );
	}

	/**
	 * Sanitize one execution hook while preserving valid custom PHP hooks.
	 *
	 * @since 0.0.5
	 * @param string $type    File type.
	 * @param mixed  $hook    Raw hook value.
	 * @param string $default Default hook.
	 * @return string
	 */
	public static function sanitize_execution_hook( $type, $hook, $default ) {
		$hook = trim( sanitize_text_field( Utils::to_str( $hook ) ) );
		return self::is_valid_execution_hook( $type, $hook ) ? $hook : $default;
	}

	/**
	 * Sanitize execution config. Single source consumed by REST + Ability.
	 * Always returns a fully-populated array (php/js/css/html keys), with
	 * invalid input falling back to the persisted state when given, else to
	 * defaults.
	 *
	 * @since 0.0.5
	 * @param mixed $execution Raw execution config (unknowable external input).
	 * @param mixed $persisted The snippet's persisted execution config — the merge baseline for partial updates.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize_execution( $execution, $persisted = null ) {
		$defaults = self::default_execution();

		// The merge baseline is the snippet's PERSISTED execution (validated
		// per key), falling back to defaults only where nothing is persisted.
		// Filling gaps from defaults instead of persisted state made every
		// partial update a reset: "move the CSS to wp_head" silently rebound
		// the PHP file's hook/priority/scope back to init/10/everywhere too.
		$baseline = $defaults;
		if ( is_array( $persisted ) ) {
			foreach ( $defaults as $type => $type_defaults ) {
				$prior = $persisted[ $type ] ?? null;
				if ( ! is_array( $prior ) ) {
					continue;
				}
				/** @var array<string, mixed> $prior */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHP array keys are always array-key at runtime; values stay mixed and are validated per key below.
				$baseline[ $type ] = self::sanitize_execution_entry( $type, $prior, $type_defaults );
			}
		}

		if ( ! is_array( $execution ) ) {
			return $baseline;
		}

		$result = array();
		foreach ( $baseline as $type => $type_baseline ) {
			$input = $execution[ $type ] ?? array();
			if ( ! is_array( $input ) ) {
				$result[ $type ] = $type_baseline;
				continue;
			}
			/** @var array<string, mixed> $input */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- same runtime guarantee as $prior above.
			$result[ $type ] = self::sanitize_execution_entry( $type, $input, $type_baseline );
		}
		return $result;
	}

	/**
	 * Sanitize one per-type execution entry against a fallback entry.
	 *
	 * Absent or invalid keys fall back to the given entry (persisted state
	 * during a partial update, defaults otherwise) — never unconditionally to
	 * the global defaults.
	 *
	 * @since 0.0.5
	 * @param string               $type     File type (php/js/css/html).
	 * @param array<string, mixed> $input    Raw per-type input.
	 * @param array<string, mixed> $fallback Fully-populated fallback entry.
	 * @return array<string, mixed>
	 */
	private static function sanitize_execution_entry( $type, array $input, array $fallback ) {
		$entry = array(
			'hook'     => self::sanitize_execution_hook( $type, $input['hook'] ?? $fallback['hook'], Utils::to_str( $fallback['hook'] ?? '' ) ),
			'priority' => max( 1, min( 99, Utils::to_int( $input['priority'] ?? $fallback['priority'] ) ) ),
			'scope'    => in_array( $input['scope'] ?? '', self::VALID_SCOPES, true )
				? $input['scope']
				: $fallback['scope'],
		);
		if ( 'php' === $type ) {
			$entry['accepted_args'] = max( 0, min( 10, Utils::to_int( $input['accepted_args'] ?? ( $fallback['accepted_args'] ?? 1 ) ) ) );
		}
		return $entry;
	}

	/**
	 * Sanitize a conditions array under the one-row-per-type model.
	 *
	 * Data model:
	 *   - Each row is one constraint: { type, operator, value?, values?, meta? }
	 *   - At most ONE row per `type` (post, post_type, page, user, user_role,
	 *     device, url_pattern). Same-type rows submitted by clients are merged.
	 *   - All rows AND together at evaluation time.
	 *   - Multi-value operators (`in`/`not_in`/`starts_with`/`contains`/
	 *     `ends_with`/`regex`) OR across their values inside the row.
	 *
	 * Merge rules for duplicate-type rows in the input:
	 *   - All positive operators (`is`/`in`) with same type → single row with
	 *     operator `in` and unique merged values.
	 *   - All negative operators (`is_not`/`not_in`) with same type → single
	 *     row with operator `not_in` and unique merged values.
	 *   - All same single-operator on string-matchers (`starts_with`/`contains`/
	 *     `ends_with`/`regex`) → single row with that operator and unique
	 *     merged values (evaluator ORs across values).
	 *   - Mixed direction (positive + negative) or mixed string-matchers for
	 *     the same type in ONE AND chain → recorded in `rejected`; there is no
	 *     faithful single-row representation, and silently keeping the first
	 *     row WIDENED execution (the asked-for exclusion vanished).
	 *
	 * Nothing is dropped silently: every discarded or unrepresentable row adds
	 * a human-readable reason to `rejected`. Callers MUST refuse the write
	 * when `rejected` is non-empty — persisting fewer/looser constraints than
	 * the author asked for widens where live code runs.
	 *
	 * @since 0.0.5
	 * @param mixed $conditions Raw conditions (unknowable external input).
	 * @return array{rows: list<array<string, mixed>>, rejected: list<string>}
	 */
	public static function sanitize_conditions( $conditions ) {
		$rejected = array();
		if ( ! is_array( $conditions ) ) {
			return array(
				'rows'     => array(),
				'rejected' => $rejected,
			);
		}

		$normalized = array();
		foreach ( array_values( $conditions ) as $i => $cond ) {
			if ( ! is_array( $cond ) ) {
				$rejected[] = "row {$i}: not an object";
				continue;
			}
			$type = sanitize_key( Utils::to_str( $cond['type'] ?? '' ) );
			if ( ! in_array( $type, self::VALID_CONDITION_TYPES, true ) ) {
				$rejected[] = "row {$i}: unknown condition type \"{$type}\" (valid: " . implode( ', ', self::VALID_CONDITION_TYPES ) . ')';
				continue;
			}

			// An absent operator defaults to `is`; a PRESENT but unknown one is
			// rejected. Coercing it to `is` inverted a mistyped negation
			// ("isnot" ran the snippet exactly where it was excluded from).
			$op_raw = $cond['operator'] ?? 'is';
			if ( ! in_array( $op_raw, self::VALID_CONDITION_OPERATORS, true ) ) {
				$rejected[] = "row {$i}: unknown operator \"" . sanitize_text_field( Utils::to_str( $op_raw ) ) . '" (valid: ' . implode( ', ', self::VALID_CONDITION_OPERATORS ) . ')';
				continue;
			}
			$operator = strval( $op_raw );

			$values = array();
			if ( isset( $cond['values'] ) && is_array( $cond['values'] ) ) {
				foreach ( $cond['values'] as $v ) {
					$v = sanitize_text_field( Utils::to_str( $v ) );
					if ( '' !== $v ) {
						$values[] = $v;
					}
				}
			}
			if ( isset( $cond['value'] ) && '' !== $cond['value'] ) {
				$v = sanitize_text_field( Utils::to_str( $cond['value'] ) );
				if ( '' !== $v ) {
					$values[] = $v;
				}
			}
			if ( empty( $values ) ) {
				$rejected[] = "row {$i}: no usable values";
				continue;
			}

			// Login-state normalization. `logged_in` / `logged_out` are login
			// STATES, not roles — but the model routinely emits them under
			// `type:"user_role"` (e.g. `user_role is_not logged_in` for a
			// "logged-out only" snippet). The role evaluator then reads that as
			// "doesn't have a role NAMED logged_in" → true for EVERY user → the
			// gate is a silent no-op (a logged-out-only snippet shows for
			// logged-in users too). Route any single login-state token to the
			// `user` type, which the evaluator resolves via is_user_logged_in(),
			// and fold `logged_out` into `is_not logged_in` (the only value the
			// `user` type understands). Operators in/not_in collapse to is/is_not.
			if ( 1 === count( $values ) && in_array( $values[0], array( 'logged_in', 'logged_out' ), true ) ) {
				$negated = in_array( $operator, array( 'is_not', 'not_in' ), true );
				if ( 'logged_out' === $values[0] ) {
					$negated = ! $negated; // logged_out === NOT logged_in
				}
				$type      = 'user';
				$values[0] = 'logged_in';
				$operator  = $negated ? 'is_not' : 'is';
			}

			// Multi-value login-state in a ROLE LIST (e.g. `user_role not_in
			// [logged_in, editor]`). The login token is dead under the role
			// evaluator (no role is named that), so the login intent is silently
			// lost. Strip login-state tokens from the role list; for a NEGATIVE
			// operator (is_not/not_in — which AND-composes) emit a separate
			// `user` row carrying the login intent. For a POSITIVE operator the
			// value-level OR can't be split into AND rows, so we only strip the
			// already-dead token (preserving runtime behaviour, cleaning the shape).
			$extra_login_row = null;
			if ( 'user_role' === $type && count( $values ) > 1 ) {
				$login_tokens = array_values( array_intersect( $values, array( 'logged_in', 'logged_out' ) ) );
				if ( ! empty( $login_tokens ) ) {
					$values = array_values( array_diff( $values, array( 'logged_in', 'logged_out' ) ) );
					// Compose the login row only for a single, unambiguous token
					// under a negative operator; a contradictory pair just strips.
					if ( 1 === count( $login_tokens ) && in_array( $operator, array( 'is_not', 'not_in' ), true ) ) {
						// Negative operator; logged_out === NOT logged_in flips it back to positive.
						$negated         = 'logged_out' !== $login_tokens[0];
						$extra_login_row = array(
							'type'     => 'user',
							'operator' => $negated ? 'is_not' : 'is',
							'values'   => array( 'logged_in' ),
						);
					}
					// Stripping emptied the role list — promote the login row into
					// this row, or reject the row when no unambiguous login
					// intent is left (dropping it silently would widen execution).
					if ( empty( $values ) ) {
						if ( null !== $extra_login_row ) {
							$type            = 'user';
							$operator        = $extra_login_row['operator'];
							$values          = array( 'logged_in' );
							$extra_login_row = null;
						} else {
							$rejected[] = "row {$i}: user_role row contains only login-state tokens with no single unambiguous intent — use type \"user\" with value \"logged_in\" instead";
							continue;
						}
					}
				}
			}

			// `meta` is reserved for future per-row flags (e.g. case_sensitive
			// on url_pattern). Currently no flag is consumed by the evaluator,
			// so we strip the entire meta block at sanitize time — accepting it
			// would mislead authors into thinking it works. Re-add to the
			// allowlist here when an evaluator codepath consumes the flag.
			$meta = array();

			$connector = isset( $cond['connector'] ) ? strtolower( Utils::to_str( $cond['connector'] ) ) : 'and';
			if ( 'or' !== $connector ) {
				$connector = 'and';
			}

			$normalized[] = array(
				'type'      => $type,
				'operator'  => $operator,
				'values'    => $values,
				'connector' => $connector,
				'meta'      => $meta,
			);

			// A login row split out of a multi-value role list ANDs with the
			// role row in the same chain (different type, so it survives merge).
			if ( null !== $extra_login_row ) {
				$normalized[] = array(
					'type'      => $extra_login_row['type'],
					'operator'  => $extra_login_row['operator'],
					'values'    => $extra_login_row['values'],
					'connector' => 'and',
					'meta'      => array(),
				);
			}
		}

		return array(
			'rows'     => self::merge_by_type( $normalized, $rejected ),
			'rejected' => $rejected,
		);
	}

	/**
	 * Merge same-type rows into one per type — but PER CHAIN, not globally.
	 * Chains are separated by `connector:"or"` on a row. Cross-chain same-
	 * type rows are legitimate (e.g. `post=2051 AND role=admin` OR
	 * `post=2052 AND role=editor`), so each chain is merged independently.
	 *
	 * @param ConditionRow[] $rows     Normalized rows.
	 * @param string[]       $rejected Rejection reasons, appended to by reference.
	 * @return list<array<string, mixed>> Flat list preserving chain order; connector field marks
	 *               chain boundaries.
	 */
	private static function merge_by_type( array $rows, array &$rejected = array() ) {
		// Split into chains by `or` connector.
		$chains  = array();
		$current = array();
		foreach ( $rows as $row ) {
			if ( 'or' === $row['connector'] && ! empty( $current ) ) {
				$chains[] = $current;
				$current  = array();
			}
			$current[] = $row;
		}
		if ( ! empty( $current ) ) {
			$chains[] = $current;
		}

		$out = array();
		foreach ( $chains as $chain_index => $chain ) {
			$merged_chain = self::merge_chain( $chain, $rejected );
			foreach ( $merged_chain as $i => $row ) {
				// First row of the FIRST chain → connector "and" (implicit).
				// First row of subsequent chains → connector "or".
				// Subsequent rows in any chain → connector "and".
				$row['connector'] = ( 0 === $i && $chain_index > 0 ) ? 'or' : 'and';
				$out[]            = self::finalize_row( $row );
			}
		}
		return $out;
	}

	/**
	 * Merge same-type rows within a single AND-chain. All positive → `in`;
	 * all negative → `not_in`; same string-matcher → that matcher with
	 * multi-value; mixed direction → recorded in $rejected (no faithful
	 * single-row form; callers refuse the write).
	 *
	 * @param ConditionRow[] $chain    One AND-chain of normalized condition rows.
	 * @param string[]       $rejected Rejection reasons, appended to by reference.
	 * @return list<ConditionRow>
	 */
	private static function merge_chain( array $chain, array &$rejected = array() ) {
		$positive_set = array( 'is', 'in' );
		$negative_set = array( 'is_not', 'not_in' );
		$pattern_set  = array( 'starts_with', 'contains', 'ends_with', 'regex' );

		$by_type = array();
		$order   = array();
		foreach ( $chain as $row ) {
			if ( ! isset( $by_type[ $row['type'] ] ) ) {
				$by_type[ $row['type'] ] = array();
				$order[]                 = $row['type'];
			}
			$by_type[ $row['type'] ][] = $row;
		}

		$out = array();
		foreach ( $order as $type ) {
			$bucket = $by_type[ $type ];
			if ( count( $bucket ) === 1 ) {
				$out[] = $bucket[0];
				continue;
			}
			$first             = $bucket[0];
			$ops               = array_values(
				array_unique(
					array_map(
						static function ( $r ) {
							return $r['operator'];
						},
						$bucket 
					) 
				) 
			);
			$all_positive      = ! array_diff( $ops, $positive_set );
			$all_negative      = ! array_diff( $ops, $negative_set );
			$single_pattern_op = 1 === count( $ops ) && in_array( $ops[0], $pattern_set, true );

			if ( $all_positive ) {
				$out[] = array(
					'type'      => $type,
					'operator'  => 'in',
					'values'    => self::merge_values( $bucket ),
					'connector' => $first['connector'],
					'meta'      => $first['meta'],
				);
				continue;
			}
			if ( $all_negative ) {
				$out[] = array(
					'type'      => $type,
					'operator'  => 'not_in',
					'values'    => self::merge_values( $bucket ),
					'connector' => $first['connector'],
					'meta'      => $first['meta'],
				);
				continue;
			}
			if ( $single_pattern_op ) {
				$out[] = array(
					'type'      => $type,
					'operator'  => $ops[0],
					'values'    => self::merge_values( $bucket ),
					'connector' => $first['connector'],
					'meta'      => $first['meta'],
				);
				continue;
			}
			// Mixed direction (or mixed string-matchers) for one type in one
			// AND chain has no faithful single-row representation. Keeping
			// only the first row silently WIDENED execution — the exclusion
			// the author asked for vanished. Record it; callers refuse the
			// write when anything lands in `rejected`.
			$rejected[] = 'conditions mix operators (' . implode( ', ', $ops ) . ") for type \"{$type}\" in one AND chain — split them into separate OR chains (connector: \"or\") or use one row per type";
			$out[]      = $first;
		}
		return $out;
	}

	/**
	 * Collect unique values across a bucket of same-type rows, first-seen order.
	 *
	 * @param ConditionRow[] $bucket Same-type normalized condition rows.
	 * @return list<string> Unique values.
	 */
	private static function merge_values( array $bucket ) {
		$seen = array();
		$out  = array();
		foreach ( $bucket as $row ) {
			foreach ( $row['values'] as $v ) {
				if ( ! isset( $seen[ $v ] ) ) {
					$seen[ $v ] = true;
					$out[]      = $v;
				}
			}
		}
		return $out;
	}

	/**
	 * Convert the internal normalized row shape into the persistence shape:
	 * single-value rows use `value`, multi-value rows use `values`. Drops
	 * empty meta. Promotes `is`→`in` / `is_not`→`not_in` when multi-value.
	 *
	 * @param array<string, mixed> $row Internal normalized condition row.
	 * @return array<string, mixed>
	 */
	private static function finalize_row( array $row ) {
		$values = isset( $row['values'] ) && is_array( $row['values'] ) ? $row['values'] : array();
		$op     = $row['operator'] ?? 'is';

		$persisted = array(
			'type'     => $row['type'],
			'operator' => $op,
		);

		if ( count( $values ) > 1 ) {
			// Multi-value: promote single-value operators to their list form.
			if ( 'is' === $op ) {
				$persisted['operator'] = 'in';
			} elseif ( 'is_not' === $op ) {
				$persisted['operator'] = 'not_in';
			}
			$persisted['values'] = array_values( $values );
		} else {
			// Single-value: demote list operators back to their scalar form so
			// the persisted shape is canonical. Without this, `describe_row`
			// renders `post in [2051]` instead of `post=2051` after a merge
			// collapses to one value.
			if ( 'in' === $op ) {
				$persisted['operator'] = 'is';
			} elseif ( 'not_in' === $op ) {
				$persisted['operator'] = 'is_not';
			}
			$persisted['value'] = Utils::to_str( $values[0] ?? '' );
		}

		// Connector is significant: "or" marks the start of a new OR chain.
		// "and" is the default; we still persist it explicitly so JSON shape
		// is uniform across rows (avoid silent client-side drift).
		$connector              = isset( $row['connector'] ) ? strtolower( Utils::to_str( $row['connector'] ) ) : 'and';
		$persisted['connector'] = ( 'or' === $connector ) ? 'or' : 'and';

		if ( ! empty( $row['meta'] ) && is_array( $row['meta'] ) ) {
			$persisted['meta'] = $row['meta'];
		}
		return $persisted;
	}

	/**
	 * Human-readable sentence describing when a snippet will run.
	 *
	 * Output examples:
	 *   "Runs everywhere"
	 *   "Runs when post_type=product"
	 *   "Runs when post in [2800,2801] AND post_type=product"
	 *
	 * Under the one-row-per-type model the description is always a flat AND
	 * of row strings — no parenthesization, no group rendering.
	 *
	 * @since 0.0.5
	 * @param mixed $conditions Persisted condition rows (unknowable external input).
	 * @return string
	 */
	public static function describe_conditions( $conditions ) {
		if ( empty( $conditions ) || ! is_array( $conditions ) ) {
			return 'Runs everywhere';
		}

		// Split into chains: each chain is rows joined by AND, chains OR together.
		$chains  = array();
		$current = array();
		foreach ( $conditions as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( 'or' === ( $row['connector'] ?? 'and' ) && ! empty( $current ) ) {
				$chains[] = $current;
				$current  = array();
			}
			$current[] = $row;
		}
		if ( ! empty( $current ) ) {
			$chains[] = $current;
		}
		if ( empty( $chains ) ) {
			return 'Runs everywhere';
		}

		$rendered = array();
		foreach ( $chains as $chain ) {
			$row_strings = array();
			foreach ( $chain as $row ) {
				$row_strings[] = self::describe_row( $row );
			}
			$expr = implode( ' AND ', $row_strings );
			// Parenthesize multi-row chains when there is more than one chain
			// so AND/OR precedence reads correctly.
			if ( count( $row_strings ) > 1 && count( $chains ) > 1 ) {
				$expr = '(' . $expr . ')';
			}
			$rendered[] = $expr;
		}
		return 'Runs when ' . implode( ' OR ', $rendered );
	}

	/**
	 * Render a single condition row as `type[op]value` short form. Operator
	 * is omitted for the common `is` case to keep the sentence readable.
	 *
	 * @since 0.0.5
	 * @param array<array-key, mixed> $row Single condition row.
	 * @return string
	 */
	private static function describe_row( $row ) {
		$type = Utils::to_str( $row['type'] ?? '?' );
		$op   = Utils::to_str( $row['operator'] ?? 'is' );
		if ( isset( $row['values'] ) && is_array( $row['values'] ) && ! empty( $row['values'] ) ) {
			$val = implode(
				',',
				array_map(
					static function ( $v ) {
						return Utils::to_str( $v );
					},
					$row['values']
				)
			);
		} else {
			$val = Utils::to_str( $row['value'] ?? '' );
		}

		switch ( $op ) {
			case 'is':
				return $type . '=' . $val;
			case 'is_not':
				return $type . '!=' . $val;
			case 'in':
				return $type . ' in [' . $val . ']';
			case 'not_in':
				return $type . ' not in [' . $val . ']';
			case 'contains':
				return $type . ' contains [' . $val . ']';
			case 'starts_with':
				return $type . ' starts_with [' . $val . ']';
			case 'ends_with':
				return $type . ' ends_with [' . $val . ']';
			case 'regex':
				return $type . ' matches [' . $val . ']';
			default:
				return $type . ' ' . $op . ' ' . $val;
		}
	}

	/**
	 * Return all valid snippet slugs from the manifest. Used to enrich
	 * "not found" errors so the agent can self-correct on a typo or stale
	 * slug reference.
	 *
	 * @since 0.0.5
	 * @return list<string>
	 */
	public static function known_slugs() {
		$manifest = self::load();
		return array_values(
			array_filter(
				array_keys( $manifest ),
				static function ( $k ) {
					return '_version' !== $k; }
			) 
		);
	}

	/**
	 * Format a "not found" diagnostic with up to N known slugs for the agent
	 * to fuzzy-match against.
	 *
	 * @since 0.0.5
	 * @param string $requested Slug the caller asked for.
	 * @return string
	 */
	public static function not_found_message( $requested ) {
		$known = self::known_slugs();
		if ( empty( $known ) ) {
			return sprintf( 'Snippet "%s" not found. No snippets exist yet.', (string) $requested );
		}
		$preview = array_slice( $known, 0, 20 );
		$suffix  = count( $known ) > 20 ? sprintf( ' (and %d more)', count( $known ) - 20 ) : '';
		return sprintf(
			'Snippet "%s" not found. Known slugs: %s%s. Call action=list to inspect all snippets.',
			(string) $requested,
			implode( ', ', $preview ),
			$suffix
		);
	}

	/**
	 * Default execution settings per file type.
	 *
	 * @since 0.0.5
	 * @return array{php: array{hook: string, priority: int, scope: string, accepted_args: int}, js: array{hook: string, priority: int, scope: string}, css: array{hook: string, priority: int, scope: string}, html: array{hook: string, priority: int, scope: string}}
	 */
	public static function default_execution() {
		return array(
			'php'  => array(
				'hook'          => 'init',
				'priority'      => 10,
				'scope'         => 'everywhere',
				'accepted_args' => 1,
			),
			'js'   => array(
				'hook'     => 'wp_footer',
				'priority' => 10,
				'scope'    => 'frontend',
			),
			'css'  => array(
				'hook'     => 'wp_head',
				'priority' => 10,
				'scope'    => 'frontend',
			),
			'html' => array(
				'hook'     => 'wp_head',
				'priority' => 10,
				'scope'    => 'frontend',
			),
		);
	}

	/**
	 * Normalize a snippet's metadata by filling missing fields with defaults.
	 *
	 * Ensures backward compatibility — old manifests without execution/conditions
	 * fields get sensible defaults so downstream code never needs null-checks.
	 *
	 * @since 0.0.5
	 * @param array<string, mixed> $meta Raw snippet metadata from manifest.
	 * @return array<string, mixed> Normalized metadata with all fields present.
	 */
	public static function normalize_snippet( $meta ) {
		$defaults = array(
			'title'              => '',
			'description'        => '',
			'files'              => array(),
			'file_hashes'        => array(),
			'enabled'            => false,
			'auto_disabled'      => false,
			'disabled_reason'    => null,
			'disabled_at'        => null,
			'execution'          => self::default_execution(),
			'conditions'         => array(),
			'sandbox'            => false,
			'sandbox_user'       => null,
			'created'            => null,
			'created_by'         => null,
			'updated'            => null,
			'updated_by'         => null,
			'updated_by_type'    => null,  // user|agent|system — author type of last edit
			'current_version_id' => null,  // pointer into .versions/index.json
			'versions_count'     => 0,     // denormalized for list-page badge
			'last_version_at'    => null,  // ISO 8601 timestamp of latest version
		);

		$merged = array_merge( $defaults, $meta );

		// Ensure execution has entries for all file types with defaults.
		$default_exec = self::default_execution();
		$execution    = is_array( $merged['execution'] ) ? $merged['execution'] : array();
		foreach ( $default_exec as $type => $type_defaults ) {
			$entry              = $execution[ $type ] ?? null;
			$execution[ $type ] = is_array( $entry ) ? array_merge( $type_defaults, $entry ) : $type_defaults;
		}

		// Validate execution hooks and scopes.
		foreach ( array_keys( $default_exec ) as $type ) {
			$entry         = is_array( $execution[ $type ] ) ? $execution[ $type ] : array();
			$entry['hook'] = self::sanitize_execution_hook(
				$type,
				$entry['hook'] ?? '',
				$default_exec[ $type ]['hook']
			);
			if ( ! in_array( $entry['scope'] ?? '', self::VALID_SCOPES, true ) ) {
				$entry['scope'] = $default_exec[ $type ]['scope'];
			}
			$entry['priority'] = max( 1, min( 99, Utils::to_int( $entry['priority'] ?? 0 ) ) );
			if ( 'php' === $type ) {
				$entry['accepted_args'] = max( 0, min( 10, Utils::to_int( $entry['accepted_args'] ?? 1 ) ) );
			}
			$execution[ $type ] = $entry;
		}
		$merged['execution'] = $execution;

		// Ensure conditions is an array.
		if ( ! is_array( $merged['conditions'] ) ) {
			$merged['conditions'] = array();
		}

		return $merged;
	}

	/**
	 * Get the base snippets directory.
	 *
	 * Filterable via 'zip_ai_snippets_base_dir'. Always validated to be under WP_CONTENT_DIR.
	 *
	 * @since 0.1.0
	 * @return string Absolute directory path.
	 */
	public static function base_dir() {
		$dir = apply_filters( 'zip_ai_snippets_base_dir', WP_CONTENT_DIR . '/zip-ai-snippets' );
		$dir = is_string( $dir ) ? $dir : WP_CONTENT_DIR . '/zip-ai-snippets';

		$real = realpath( $dir );
		$real = $real ? $real : $dir;
		$base = realpath( WP_CONTENT_DIR );
		$base = $base ? $base : WP_CONTENT_DIR;
		if ( strpos( $real, $base ) !== 0 ) {
			return WP_CONTENT_DIR . '/zip-ai-snippets';
		}

		return $dir;
	}

	/**
	 * Capability required to put PHP on disk or turn it on.
	 *
	 * Snippet bodies are executable PHP, so authoring them is the plugin/theme
	 * file editor's boundary, not `manage_options`. WP maps `edit_plugins`
	 * through `DISALLOW_FILE_EDIT`, `wp_is_file_mod_allowed()` and — on
	 * multisite — the super-admin check, so this one cap covers all three.
	 * Filterable so a site that manages snippets with a custom role can swap it
	 * out.
	 *
	 * Applies to: create, update, import, restore-version, enable, sandbox, and
	 * turning safe mode OFF.
	 *
	 * @since 0.0.5
	 * @return string
	 */
	public static function manage_capability() {
		$cap = apply_filters( 'zip_ai_snippets_capability', 'edit_plugins' );
		return is_string( $cap ) && '' !== $cap ? $cap : 'edit_plugins';
	}

	/**
	 * Capability required to inspect snippets or switch them OFF.
	 *
	 * Deliberately lower than `manage_capability()`. The executor has no cap
	 * check — an already-enabled snippet keeps running on every request — so
	 * gating the read and disable paths behind the file-editor cap would leave a
	 * `DISALLOW_FILE_EDIT` host (WP Engine, Kinsta, Flywheel, Pantheon) or a
	 * multisite sub-site admin with live snippets and no supported way to see or
	 * stop them. Inspecting and disabling is the safety valve; authoring is the
	 * privilege.
	 *
	 * Applies to: list, read, export, version list/get/diff, delete, disable,
	 * and turning safe mode ON.
	 *
	 * @since 0.0.5
	 * @return string
	 */
	public static function read_capability() {
		$cap = apply_filters( 'zip_ai_snippets_read_capability', 'manage_options' );
		return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Whether the current user may author or enable snippets.
	 *
	 * @since 0.0.5
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::manage_capability() );
	}

	/**
	 * Whether the current user may inspect or disable snippets.
	 *
	 * @since 0.0.5
	 * @return bool
	 */
	public static function current_user_can_read() {
		return current_user_can( self::read_capability() );
	}

	/**
	 * Get the manifest file path.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function manifest_path() {
		return self::base_dir() . '/manifest.php';
	}

	/**
	 * Load the manifest as an array.
	 *
	 * @since 0.1.0
	 * @return array<string, mixed> Manifest data, or empty array if missing/corrupt.
	 */
	public static function load() {
		// Read paths degrade to an empty list on a damaged manifest so the
		// site keeps rendering; WRITE paths must not — with_lock() refuses to
		// write over a file that exists but does not parse (see
		// manifest_is_unreadable()), otherwise the next save would persist a
		// near-empty registry and deregister every other snippet.
		$path = self::manifest_path();
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			return array();
		}

		$newline = strpos( $raw, "\n" );
		if ( false === $newline ) {
			return array();
		}

		$json = substr( $raw, $newline + 1 );
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$manifest = array();
		foreach ( $data as $key => $value ) {
			$manifest[ (string) $key ] = $value;
		}
		return $manifest;
	}

	/**
	 * Whether the manifest file EXISTS but cannot be parsed back into a
	 * registry (unreadable, truncated write, manual damage).
	 *
	 * Distinguishes "no manifest yet" (a legitimate empty registry — returns
	 * false) from "there was a registry and it is damaged" (returns true).
	 * Write paths must check this and refuse: rebuilding over a damaged file
	 * silently deregisters every snippet the damaged file still names.
	 *
	 * @since 0.1.0
	 * @return bool
	 */
	public static function manifest_is_unreadable() {
		$path = self::manifest_path();
		if ( ! file_exists( $path ) ) {
			return false;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			return true;
		}

		$newline = strpos( $raw, "\n" );
		$json    = false === $newline ? '' : substr( $raw, $newline + 1 );

		return ! is_array( json_decode( $json, true ) );
	}

	/**
	 * Resolve the initialized WP_Filesystem instance — the plugin's blessed FS
	 * layer (same pattern as media-service / the plugin installers). Returns
	 * null on hosts where it can't initialize without stored credentials
	 * (FTP/SSH mode) so callers degrade rather than fatal.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function fs() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Write a file through WP_Filesystem.
	 *
	 * @param string $path    Absolute file path.
	 * @param string $content File contents.
	 * @return bool True on success; false if WP_Filesystem is unavailable or the write failed.
	 */
	public static function put_file( $path, $content ) {
		$fs = self::fs();
		return $fs && $fs->put_contents( $path, $content, FS_CHMOD_FILE );
	}

	/**
	 * Wrap an author's PHP body in the stored file format: one opening tag, an
	 * ABSPATH guard, and a title comment.
	 *
	 * SECURITY: the executor `include`s these files, so a direct HTTP hit on
	 * the file would run the body too. The guard makes a directly-fetched
	 * snippet exit instead — the last line of defense behind the randomized
	 * directory name and the per-dir deny files, and the only one that holds on
	 * a server where `.htaccess`/`web.config` are both inert.
	 *
	 * Idempotent: the input is unwrapped first, so re-saving an already-stored
	 * body never stacks guards.
	 *
	 * The header line is always written (empty title included) so `unwrap_php()`
	 * can strip exactly one line without guessing.
	 *
	 * @since 0.0.5
	 * @param string $code  Author's PHP body (with or without an opening tag).
	 * @param string $title Snippet title for the header comment.
	 * @return string Contents to write to disk.
	 */
	public static function wrap_php( $code, $title = '' ) {
		$body   = self::strip_open_tag( rtrim( self::unwrap_php( $code ), "\n" ) );
		$header = rtrim( '// Snippet: ' . self::comment_safe_title( $title ) );
		return self::PHP_WRAPPER_PREFIX . $header . "\n" . $body . "\n";
	}

	/**
	 * Drop an author-supplied opening tag so the wrapper cannot nest a second
	 * one — `<?php` inside an already-open PHP file is a parse error, and the
	 * body's own gates never see it because they only ever inspect the body.
	 *
	 * `<?=` becomes `echo ` so the shorthand keeps its output semantics instead
	 * of leaving a bare expression. Anything else that merely looks like a tag
	 * (`<?xml`, `<?foo`) is left alone — `prepare_php_file()` refuses to write
	 * an unparseable result rather than guess at it.
	 *
	 * @since 0.0.5
	 * @param string $body Author's PHP body.
	 * @return string Body with no leading opening tag.
	 */
	private static function strip_open_tag( $body ) {
		if ( 1 === preg_match( '/\A<\?=[ \t]*/', $body ) ) {
			return (string) preg_replace( '/\A<\?=[ \t]*/', 'echo ', $body, 1 );
		}
		return (string) preg_replace( '/\A<\?(?:php)?(?:[ \t]+|\R|\z)/i', '', $body, 1 );
	}

	/**
	 * Wrap a PHP body for storage and refuse to hand back anything the parser
	 * rejects.
	 *
	 * SECURITY / correctness belt: every write gate (`check_php_blocklist()`,
	 * `Snippet_Lint::check()`, `detect_syntax_error()`) inspects the BODY, and
	 * `hash_file()` runs after the write, so a wrapper bug that produced an
	 * unparseable file would pass all of them, read back clean through
	 * `unwrap_php()`, and then be swallowed at runtime by the executor's
	 * `catch ( \Throwable )` — a snippet that silently never runs. Checking the
	 * bytes we are about to write is the only place that catches it.
	 *
	 * @since 0.0.5
	 * @param string $code  Author's PHP body.
	 * @param string $title Snippet title for the header comment.
	 * @return string|\WP_Error Contents to write, or an error if they would not parse.
	 */
	public static function prepare_php_file( $code, $title = '' ) {
		$stored = self::wrap_php( $code, $title );
		$error  = Snippet_Lint::detect_syntax_error( $stored );

		if ( null !== $error ) {
			return new \WP_Error(
				'wrapped_syntax_error',
				sprintf(
					/* translators: %s: parser error message. */
					__( 'Refused to store an unparseable snippet file: %s', 'zip-ai' ),
					$error
				)
			);
		}

		return $stored;
	}

	/**
	 * Flatten a title so it cannot escape the `// Snippet: …` header line.
	 *
	 * SECURITY: the header is interpolated into the stored PHP file, and the
	 * import path takes its title straight from the payload — a title carrying
	 * a newline would put the remainder on its own line as executable code that
	 * `validate_code()` (blocklist, lint, syntax check) never inspected, since
	 * those run on the body only. `?>` would likewise end the PHP block early.
	 * Sanitising here covers all four writers rather than each call site.
	 *
	 * @since 0.0.5
	 * @param string $title Raw title, possibly attacker-supplied.
	 * @return string Single-line, comment-safe title.
	 */
	private static function comment_safe_title( $title ) {
		return trim( str_replace( array( "\0", "\r", "\n", '?>' ), ' ', (string) $title ) );
	}

	/**
	 * Strip the stored prefix (opening tag, ABSPATH guard, title comment) back
	 * to the author's body. Used on every read that lints, diffs, exports or
	 * shows code — the wrapper is storage plumbing, not the user's snippet.
	 *
	 * Content that does not begin with the exact wrapper prefix is returned
	 * untouched: an author body that happens to open with `// Snippet: …` keeps
	 * that line, and a pre-wrapper file is never partially eaten.
	 *
	 * @since 0.0.5
	 * @param string $content Stored file contents.
	 * @return string Author's body.
	 */
	public static function unwrap_php( $content ) {
		$content = (string) $content;

		// Structural match on the prefix, line-ending agnostic: a file edited
		// over SFTP can come back CRLF, and a byte compare would then treat our
		// own wrapper as author content.
		if ( 1 !== preg_match( '/\A<\?php\Rif \( ! defined\( \'ABSPATH\' \) \) \{ exit; \}\R/', $content, $matches ) ) {
			return $content;
		}

		$body = substr( $content, strlen( $matches[0] ) );

		// Our header is the line immediately after the guard, when present.
		return (string) preg_replace( '#\A// Snippet:.*\R#', '', $body, 1 );
	}

	/**
	 * Recursively delete a directory through WP_Filesystem.
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool True on success; false if WP_Filesystem is unavailable or the delete failed.
	 */
	public static function delete_dir( $dir ) {
		$fs = self::fs();
		return $fs && $fs->delete( $dir, true, 'd' );
	}

	/**
	 * Save manifest data to disk.
	 *
	 * @since 0.1.0
	 * @param array<string, mixed> $data Manifest data.
	 * @return bool True on success.
	 */
	public static function save( array $data ) {
		$content = self::GUARD . wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		return self::put_file( self::manifest_path(), $content );
	}

	/**
	 * Run a callback with an exclusive lock held across the manifest's full
	 * read-modify-write window. Solves the lost-update race where parallel
	 * REST requests (bulk delete, concurrent MCP tool calls) each `load()`
	 * the same baseline, each `unset()` their slug, each `save()` — second
	 * writer wins, the first deletion silently comes back, the UI surfaces
	 * the resurrected entry as a "leftover".
	 *
	 * Usage:
	 *   Snippet_Store::with_lock( function ( &$manifest ) use ( $slug ) {
	 *       unset( $manifest[ $slug ] );
	 *   } );
	 *
	 * Best-effort: when `flock()` is unavailable on the host (some hosted
	 * filesystems don't support advisory locking), falls back to a plain
	 * load/save sequence so callers don't break — same behavior as before
	 * this helper existed.
	 *
	 * @param callable $callback Receives the manifest array by reference and mutates it in place.
	 * @return bool True on save success.
	 */
	public static function with_lock( callable $callback ) {
		$path = self::manifest_path();

		if ( ! file_exists( $path ) ) {
			// First-write case — there is nothing to read-lock. Hand the
			// callback an empty array, then save() (which already takes
			// LOCK_EX for the write).
			$manifest = array();
			$callback( $manifest );
			return self::save( $manifest );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$fp = @fopen( $path, 'r+' );
		if ( false === $fp ) {
			// Cannot open for read+write — fall back to a plain load/save so
			// callers degrade rather than fail. The corruption guard still
			// applies: never rebuild a registry over a file we could not read.
			if ( self::manifest_is_unreadable() ) {
				return false;
			}
			$manifest = self::load();
			$callback( $manifest );
			return self::save( $manifest );
		}

		$locked = @flock( $fp, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_flock, WordPress.PHP.NoSilencedErrors.Discouraged -- advisory locking for the atomic manifest read-modify-write; WP_Filesystem has no equivalent.
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			$raw     = stream_get_contents( $fp );
			$newline = is_string( $raw ) ? strpos( $raw, "\n" ) : false;
			$json    = false === $newline ? '' : substr( $raw, $newline + 1 );
			$data    = json_decode( $json, true );

			// The file EXISTS but does not parse (truncated write, manual
			// damage). Proceeding with an empty array would let the next
			// save() persist a one-entry registry and silently deregister
			// every other snippet — refuse the write instead; the registry
			// needs to be repaired or the file removed deliberately.
			if ( ! is_array( $data ) ) {
				return false;
			}
			$manifest = $data;

			$callback( $manifest );

			$content = self::GUARD . wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

			rewind( $fp );
			ftruncate( $fp, 0 ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_ftruncate -- atomic manifest rewrite under flock; WP_Filesystem has no equivalent.
			$written = fwrite( $fp, $content ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- atomic manifest rewrite under flock; WP_Filesystem has no equivalent.
			fflush( $fp );

			return false !== $written;
		} finally {
			if ( $locked ) {
				@flock( $fp, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_flock, WordPress.PHP.NoSilencedErrors.Discouraged -- releases the manifest advisory lock; WP_Filesystem has no equivalent.
			}
			fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Validate and sanitize a snippet slug.
	 *
	 * Prevents path traversal, reserved names, and special characters.
	 *
	 * @since 0.1.0
	 * @param string $slug Raw slug input.
	 * @return string|false Sanitized slug, or false if invalid.
	 */
	public static function validate_slug( $slug ) {
		$slug = sanitize_file_name( $slug );

		if ( empty( $slug ) || '_version' === $slug || '.' === $slug || '..' === $slug ) {
			return false;
		}

		if ( preg_match( '/[\/\\\\]|\.\./', $slug ) ) {
			return false;
		}

		$base   = realpath( self::base_dir() );
		$base   = $base ? $base : self::base_dir();
		$target = $base . '/' . $slug;
		if ( strpos( $target, $base . '/' ) !== 0 ) {
			return false;
		}

		return $slug;
	}

	/**
	 * Check if a file path is safely within the snippets base directory.
	 *
	 * @since 0.1.0
	 * @param string $filepath Absolute file path to check.
	 * @return bool
	 */
	public static function is_safe_path( $filepath ) {
		$real_base = realpath( self::base_dir() );
		if ( ! $real_base ) {
			return false;
		}

		// For new files that don't exist yet, check the parent directory.
		$check = file_exists( $filepath ) ? $filepath : dirname( $filepath );
		$real  = realpath( $check );
		if ( ! $real ) {
			return false;
		}

		return strpos( $real, $real_base . DIRECTORY_SEPARATOR ) === 0
			|| $real === $real_base;
	}

	/**
	 * Per-process cache of slug → on-disk dir-name lookups. Avoids reloading
	 * the manifest for every snippet_dir/snippet_file call within a request.
	 *
	 * @var array<string,string>
	 */
	private static $dir_cache = array();

	/**
	 * Mint a randomized on-disk directory name for a new snippet.
	 *
	 * Format: `{slug}-{8 hex chars}`. Pure-random suffix means an attacker
	 * can't guess the path from the title alone — defense against direct-URL
	 * fetching on web servers where `.htaccess` is inert (Nginx, Caddy, IIS).
	 * The human-readable slug is preserved as a prefix for filesystem
	 * inspection.
	 *
	 * @since 0.0.5
	 * @param string $slug Validated slug.
	 * @return string Storage dir basename.
	 */
	public static function mint_storage_dir( $slug ) {
		$suffix = function_exists( 'wp_generate_password' )
			? strtolower( wp_generate_password( 8, false, false ) )
			: bin2hex( random_bytes( 4 ) );
		// Strip any non [a-z0-9] just in case (wp_generate_password without
		// special chars already gives alphanumeric — belt-and-braces).
		$suffix = preg_replace( '/[^a-z0-9]/', '', $suffix ) ?? '';
		if ( strlen( $suffix ) < 8 ) {
			$suffix = str_pad( $suffix, 8, '0' );
		}
		return $slug . '-' . substr( $suffix, 0, 8 );
	}

	/**
	 * Resolve a slug to its on-disk directory basename via the manifest.
	 * New snippets carry an explicit `dir` field (randomized prefix +
	 * suffix). Legacy snippets without `dir` fall back to the slug, so
	 * existing folders keep working without migration.
	 *
	 * @since 0.0.5
	 * @param string $slug Validated snippet slug.
	 * @return string
	 */
	public static function resolve_dir_name( $slug ) {
		if ( isset( self::$dir_cache[ $slug ] ) ) {
			return self::$dir_cache[ $slug ];
		}
		$manifest                 = self::load();
		$entry                    = $manifest[ $slug ] ?? null;
		$dir                      = is_array( $entry ) && isset( $entry['dir'] ) && is_string( $entry['dir'] ) && '' !== $entry['dir']
			? $entry['dir']
			: $slug;
		self::$dir_cache[ $slug ] = $dir;
		return $dir;
	}

	/**
	 * Reset the dir cache. Call after a write that may have changed the
	 * `dir` field — primarily create / restore-from-version paths.
	 *
	 * @since 0.0.5
	 * @return void
	 */
	public static function clear_dir_cache() {
		self::$dir_cache = array();
	}

	/**
	 * Stash a slug → dir-name binding into the cache. Used by the create
	 * flow to expose a freshly-minted random dir to `snippet_dir()` before
	 * the manifest is persisted (otherwise the fallback would point at
	 * `base_dir()/{slug}` instead of `base_dir()/{slug}-{hex}`).
	 *
	 * @since 0.0.5
	 * @param string $slug Validated snippet slug.
	 * @param string $dir  On-disk directory basename.
	 * @return void
	 */
	public static function set_dir_cache( $slug, $dir ) {
		self::$dir_cache[ $slug ] = $dir;
	}

	/**
	 * Get the full path to a snippet's directory.
	 *
	 * @since 0.1.0
	 * @param string $slug Validated snippet slug.
	 * @return string
	 */
	public static function snippet_dir( $slug ) {
		return self::base_dir() . '/' . self::resolve_dir_name( $slug );
	}

	/**
	 * Get the full path to a snippet file.
	 *
	 * @since 0.1.0
	 * @param string $slug Validated snippet slug.
	 * @param string $type File type (php, js, css).
	 * @return string
	 */
	public static function snippet_file( $slug, $type ) {
		return self::snippet_dir( $slug ) . '/snippet.' . $type;
	}

	/**
	 * Apache/LiteSpeed `.htaccess` body that denies all direct HTTP access
	 * to the snippets dir tree. Idempotent — safe to write at any time.
	 *
	 * @since 0.0.5
	 * @return string
	 */
	public static function htaccess_content() {
		return "# ZIP AI snippets — block all direct HTTP access.\n"
			. "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n  Order Deny,Allow\n  Deny from all\n</IfModule>\n";
	}

	/**
	 * Recommended Nginx location block for hosts where `.htaccess` is inert.
	 * Written as a documentation file inside the base dir so admins can
	 * paste the snippet into their server config.
	 *
	 * @since 0.0.5
	 * @return string
	 */
	public static function nginx_config_content() {
		$rel = '/wp-content/' . basename( self::base_dir() ) . '/';
		return "# ZIP AI snippets — paste this into your Nginx server block.\n"
			. "# Blocks direct HTTP access to snippet source on hosts where\n"
			. "# .htaccess is inert. The plugin's PHP executor still includes\n"
			. "# these files server-side; this only blocks the public URL path.\n"
			. "\n"
			. "location ~* ^{$rel} {\n"
			. "    deny all;\n"
			. "    return 403;\n"
			. "}\n";
	}

	/**
	 * IIS `web.config` body that denies direct HTTP access. `.htaccess` is
	 * inert on IIS, so the same deny has to be expressed in its own dialect.
	 *
	 * @since 0.0.5
	 * @return string
	 */
	public static function web_config_content() {
		// Request Filtering, not URL Authorization: `<security><authorization>`
		// needs the URL Authorization role feature installed, and IIS answers
		// 500.19 for the whole directory without it — a deny that comes from a
		// config error, and evaporates the moment someone fixes the 500.
		// `requestFiltering` ships enabled by default and is delegated to
		// web.config, so denying the extension is both valid and durable.
		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<!-- ZIP AI snippets — block direct HTTP access to snippet source on IIS. -->\n"
			. "<configuration>\n"
			. "  <system.webServer>\n"
			. "    <security>\n"
			. "      <requestFiltering>\n"
			. "        <fileExtensions>\n"
			. "          <add fileExtension=\".php\" allowed=\"false\" />\n"
			. "          <add fileExtension=\".js\" allowed=\"false\" />\n"
			. "          <add fileExtension=\".css\" allowed=\"false\" />\n"
			. "          <add fileExtension=\".html\" allowed=\"false\" />\n"
			. "        </fileExtensions>\n"
			. "      </requestFiltering>\n"
			. "    </security>\n"
			. "  </system.webServer>\n"
			. "</configuration>\n";
	}

	/**
	 * Idempotent .htaccess + web.config + index.php protection at a directory
	 * path. Used for both the base dir and each per-snippet subdir.
	 *
	 * @since 0.0.5
	 * @param string $dir Absolute path.
	 * @return void
	 */
	public static function write_dir_protection( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			self::put_file( $htaccess, self::htaccess_content() );
		}
		$web_config = $dir . '/web.config';
		if ( ! file_exists( $web_config ) ) {
			self::put_file( $web_config, self::web_config_content() );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			self::put_file( $index, '<?php // Silence is golden.' );
		}
	}

	/**
	 * Create the base snippets dir with HTTP-deny protection files and an
	 * empty manifest. Idempotent — safe to call on every write path.
	 *
	 * @since 0.0.5
	 * @return void
	 */
	public static function ensure_base_dir() {
		$base = self::base_dir();
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		self::write_dir_protection( $base );

		// Doc-only nginx config snippet — admins on Nginx can paste it into
		// their server block, where `.htaccess` is inert.
		$nginx_path = $base . '/nginx.conf.example';
		if ( ! file_exists( $nginx_path ) ) {
			self::put_file( $nginx_path, self::nginx_config_content() );
		}

		if ( ! file_exists( self::manifest_path() ) ) {
			self::save( array() );
		}
	}

	/**
	 * Ensure a snippet's storage dir exists and is HTTP-denied, minting a
	 * randomized on-disk basename for new snippets.
	 *
	 * SECURITY: every write path (REST create, REST import, agent ability)
	 * must route through here. A caller that mkdir's `snippet_dir()` itself
	 * gets `resolve_dir_name()`'s slug fallback — a path guessable from the
	 * (possibly attacker-supplied) slug, and directly fetchable on servers
	 * where `.htaccess` is inert.
	 *
	 * @since 0.0.5
	 * @param string $slug   Validated snippet slug.
	 * @param bool   $is_new True when creating; false keeps the existing dir.
	 * @return string On-disk directory basename — persist as the manifest `dir`.
	 */
	public static function prepare_snippet_dir( $slug, $is_new ) {
		self::ensure_base_dir();

		if ( $is_new ) {
			// Cache the minted name BEFORE resolving the path — otherwise
			// resolve_dir_name() falls back to the slug (no manifest entry yet).
			$dir_name = self::mint_storage_dir( $slug );
			self::set_dir_cache( $slug, $dir_name );
		} else {
			$dir_name = self::resolve_dir_name( $slug );
		}

		$dir = self::base_dir() . '/' . $dir_name;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::write_dir_protection( $dir );

		return $dir_name;
	}

	/**
	 * Invalidate PHP opcache for a snippet file after rewrite.
	 *
	 * Critical on hosts with `opcache.validate_timestamps=0` — without this
	 * call, `include` keeps executing the previously-compiled bytecode even
	 * after the file content changes, so frontend never picks up snippet
	 * edits until opcache is flushed manually.
	 *
	 * @since 0.0.5
	 * @param string $filepath Absolute path to the file just written.
	 * @return void
	 */
	public static function invalidate_opcache( $filepath ) {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}
		// Only PHP files get cached; skip JS/CSS to avoid noise.
		if ( substr( $filepath, -4 ) !== '.php' ) {
			return;
		}
		// `force = true` so we drop the entry even if its timestamp matches.
		@opcache_invalidate( $filepath, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Functions.RestrictedFunctions.opcache_opcache_invalidate -- drop stale bytecode so include() picks up the rewritten snippet; no WP equivalent.
	}
}
