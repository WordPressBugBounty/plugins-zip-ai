<?php
/**
 * Snippet Executor
 *
 * Loads and executes enabled code snippets from wp-content/zip-ai-snippets/.
 * Each snippet folder can contain: snippet.php, snippet.js, snippet.css.
 *
 * Execution is configurable per file type:
 * - Hook: which WordPress action to run on (init, wp_footer, admin_head, etc.)
 * - Priority: execution order (1-99)
 * - Scope: where to run (frontend, admin, everywhere, login)
 * - Conditions: conditional logic (page type, user role, post type, device)
 *
 * Guardrails:
 * - Path containment check before include/file_get_contents
 * - File integrity verification via SHA-256 hashes
 * - Auto-disable on fatal error
 * - Safe mode via URL param + secret key
 * - Error isolation per snippet
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Snippet_Executor {

	/**
	 * Cached manifest — loaded once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $manifest = null;

	/**
	 * Currently executing snippet slug — used by shutdown handler.
	 *
	 * @var string|null
	 */
	private static $executing_slug = null;

	/**
	 * Map of file paths to snippet slugs — for deferred callback fatal error detection.
	 *
	 * @var array<string,string>
	 */
	private static $file_to_slug = array();

	/**
	 * Hooks that fire before the `wp` action (early hooks).
	 * Page conditions cannot be evaluated on these hooks.
	 *
	 * @since 0.0.5
	 * @var array<int,string>
	 */
	private static $early_hooks = array( 'plugins_loaded', 'init', 'wp_loaded', 'admin_init' );

	/**
	 * Per-slug ring buffer cap. Trace entries beyond this drop the oldest.
	 *
	 * @since 0.0.5
	 */
	const TRACE_RING_SIZE = 50;

	/**
	 * Whether trace recording is active for this request. Set on init via
	 * `?zip_ai_snippet_trace=1` (admin only) or filter. Avoids the cost of
	 * trace evaluation on every request.
	 *
	 * @since 0.0.5
	 * @var bool|null
	 */
	private static $trace_active = null;

	/**
	 * Whether trace recording is on. Honored when:
	 *   - `?zip_ai_snippet_trace=1` is present and current user can `manage_options`
	 *   - Filter `zip_ai_snippets_trace_active` returns true
	 *
	 * @since 0.0.5
	 */
	private static function is_trace_active(): bool {
		if ( null !== self::$trace_active ) {
			return self::$trace_active;
		}
		$on = false;
		// Trace output is snippet introspection, so it tracks the snippet read
		// capability instead of a hard-coded `manage_options` — keeps the caps in
		// one place when a site filters them.
		if ( function_exists( 'current_user_can' ) && Snippet_Store::current_user_can_read() ) {
			$uid = get_current_user_id();
			if ( $uid > 0 && (bool) get_user_meta( $uid, 'zip_ai_snippets_trace_enabled', true ) ) {
				$on = true;
			}
		}
		self::$trace_active = (bool) apply_filters( 'zip_ai_snippets_trace_active', $on );
		return self::$trace_active;
	}

	/**
	 * Evaluate conditions for a snippet and (optionally) record a trace.
	 *
	 * When trace mode is active, this:
	 *   1. Runs evaluate_with_trace instead of evaluate.
	 *   2. Pushes the result into a per-slug transient ring buffer.
	 *   3. Emits an HTML comment on the next render hook so the page source
	 *      surfaces why a snippet did/didn't fire.
	 *
	 * Returns the boolean pass/fail so the callbacks treat it identically to
	 * the existing `Snippet_Conditions::evaluate()` call.
	 *
	 * @since 0.0.5
	 * @param string                         $slug       Snippet slug.
	 * @param array<int,array<string,mixed>> $conditions Condition rows to evaluate.
	 * @param bool                           $is_early   Whether the hook fires before the `wp` action.
	 * @param string                         $context    Trace context label.
	 * @return bool Whether the snippet's conditions pass.
	 */
	private static function check_conditions( $slug, $conditions, $is_early, $context = 'frontend' ) {
		if ( ! self::is_trace_active() ) {
			return Snippet_Conditions::evaluate( $conditions, $is_early );
		}

		$trace = Snippet_Conditions::evaluate_with_trace( $conditions, $is_early );
		$entry = array(
			'time'                => gmdate( 'c' ),
			'context'             => $context,
			'request_uri'         => isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'is_early'            => (bool) $is_early,
			'result'              => ! empty( $trace['result'] ),
			'evaluated_targeting' => Snippet_Store::describe_conditions( $conditions ),
			'trace'               => $trace,
		);
		self::record_trace_entry( $slug, $entry );
		self::emit_trace_comment( $slug, $entry );
		return ! empty( $trace['result'] );
	}

	/**
	 * Record a trace entry into the per-slug transient ring buffer.
	 *
	 * @param string              $slug  Snippet slug.
	 * @param array<string,mixed> $entry Evaluation trace entry to append.
	 * @return void
	 */
	private static function record_trace_entry( $slug, array $entry ) {
		$key  = 'zip_ai_snippets_trace_' . $slug;
		$ring = get_transient( $key );
		if ( ! is_array( $ring ) ) {
			$ring = array();
		}
		$ring[] = $entry;
		if ( count( $ring ) > self::TRACE_RING_SIZE ) {
			$ring = array_slice( $ring, -self::TRACE_RING_SIZE );
		}
		set_transient( $key, $ring, HOUR_IN_SECONDS );
	}

	/**
	 * Emit a single HTML comment summarising the evaluation for this slug.
	 * Hooks late on `wp_head`/`wp_footer` so it appears near the snippet
	 * output (or absence) and never lands inside `<title>` etc.
	 *
	 * @param string              $slug  Snippet slug.
	 * @param array<string,mixed> $entry Evaluation trace entry.
	 * @return void
	 */
	private static function emit_trace_comment( $slug, array $entry ) {
		$short_reason = ! empty( $entry['result'] ) ? 'FIRE' : 'SKIP';
		$summary      = sprintf(
			'<!-- zip-ai-trace: %s %s — %s -->',
			esc_html( $slug ),
			$short_reason,
			esc_html( is_string( $entry['evaluated_targeting'] ) ? $entry['evaluated_targeting'] : '' )
		);
		$emit         = static function () use ( $summary ) {
			echo "\n" . $summary . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};
		add_action( 'wp_footer', $emit, 9999 );
		add_action( 'admin_footer', $emit, 9999 );
		add_action( 'login_footer', $emit, 9999 );
	}

	/**
	 * Boot the executor: register the safe-mode notice, shutdown handler, and snippets.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::is_safe_mode() ) {
			return;
		}

		// Show admin notice when safe mode is active.
		add_action( 'admin_notices', array( __CLASS__, 'safe_mode_notice' ) );

		register_shutdown_function( array( __CLASS__, 'shutdown_handler' ) );

		self::register_snippets();
	}

	// ── Safe Mode ──────────────────────────────────────────────────────

	/**
	 * Check if safe mode is active. Runs during plugin load — NO user functions available.
	 */
	private static function is_safe_mode(): bool {
		// Persistent safe mode (transient) — no auth check needed, just a boolean flag.
		if ( get_transient( 'zip_ai_snippets_safe_mode' ) ) {
			return true;
		}

		// One-time key mode (legacy).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['zip_ai_safe_mode'] ) ) {
			$secret = self::get_safe_mode_key();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $_GET['zip_ai_safe_mode'] ) && hash_equals( $secret, $_GET['zip_ai_safe_mode'] ) ) {
				delete_option( 'zip_ai_snippets_safe_mode_key' );
				error_log( '[ZIP AI Snippets] Safe mode activated — all snippets disabled for this request. Key rotated.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging for snippet execution; no WP core logger equivalent.
				return true;
			}
		}

		return false;
	}

	/**
	 * Show admin notice when persistent safe mode is active.
	 *
	 * @since 0.0.5
	 */
	public static function safe_mode_notice(): void {
		$expires = get_transient( 'zip_ai_snippets_safe_mode' );
		if ( ! $expires ) {
			return;
		}

		$remaining = is_numeric( $expires ) ? max( 0, (int) $expires - time() ) : 0;
		$minutes   = $remaining > 0 ? ceil( $remaining / 60 ) : '?';

		echo '<div class="notice notice-warning"><p>';
		printf(
			'🛡️ <strong>ZIP AI Snippets Safe Mode</strong> — All snippets are disabled (%s min remaining). Manage in <a href="%s">ZIP AI Code Snippets</a>.',
			esc_html( (string) $minutes ),
			esc_url( admin_url( 'options-general.php?page=zip-ai-snippets' ) )
		);
		echo '</p></div>';
	}

	/**
	 * Check if persistent safe mode is currently active.
	 *
	 * @since 0.0.5
	 * @return bool
	 */
	public static function is_persistent_safe_mode() {
		return (bool) get_transient( 'zip_ai_snippets_safe_mode' );
	}

	/**
	 * Get (or lazily generate) the one-time safe-mode secret key.
	 *
	 * @return string
	 */
	private static function get_safe_mode_key() {
		$key = get_option( 'zip_ai_snippets_safe_mode_key', '' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 32, false );
			update_option( 'zip_ai_snippets_safe_mode_key', $key, false );
		}
		return is_string( $key ) ? $key : '';
	}

	// ── Fatal Error Auto-Disable ───────────────────────────────────────

	/**
	 * Auto-disable the offending snippet when a fatal error traces back to the
	 * snippets base directory.
	 *
	 * @return void
	 */
	public static function shutdown_handler() {
		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			return;
		}

		$base = Snippet_Store::base_dir();
		if ( strpos( $error['file'], $base ) !== 0 ) {
			return;
		}

		$slug = self::$executing_slug;
		if ( ! $slug && ! empty( self::$file_to_slug ) ) {
			foreach ( self::$file_to_slug as $filepath => $s ) {
				if ( strpos( $error['file'], dirname( $filepath ) ) === 0 ) {
					$slug = $s;
					break;
				}
			}
		}

		if ( ! $slug ) {
			return;
		}

		error_log( sprintf( '[ZIP AI Snippets] Fatal error in "%s" — auto-disabling. Error: %s in %s:%d', $slug, $error['message'], $error['file'], $error['line'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging for snippet execution; no WP core logger equivalent.

		$manifest = Snippet_Store::load();
		if ( isset( $manifest[ $slug ] ) && is_array( $manifest[ $slug ] ) ) {
			$manifest[ $slug ]['enabled']         = false;
			$manifest[ $slug ]['auto_disabled']   = true;
			$manifest[ $slug ]['disabled_reason'] = $error['message'];
			$manifest[ $slug ]['disabled_at']     = gmdate( 'Y-m-d H:i:s' );
			Snippet_Store::save( $manifest );
		}
	}

	// ── Core ───────────────────────────────────────────────────────────

	/**
	 * Load the manifest once per request and memoize it.
	 *
	 * @return array<string,mixed>
	 */
	private static function load_manifest() {
		if ( null !== self::$manifest ) {
			return self::$manifest;
		}

		self::$manifest = Snippet_Store::load();
		return self::$manifest;
	}

	/**
	 * Verify a snippet file against its stored SHA-256 hash.
	 *
	 * @param string              $filepath Absolute snippet file path.
	 * @param string              $slug     Snippet slug.
	 * @param string              $type     File type (php, js, css, html).
	 * @param array<string,mixed> $meta     Snippet metadata (carries file_hashes).
	 * @return bool
	 */
	private static function verify_integrity( $filepath, $slug, $type, $meta ) {
		$hashes   = isset( $meta['file_hashes'] ) && is_array( $meta['file_hashes'] ) ? $meta['file_hashes'] : array();
		$expected = isset( $hashes[ $type ] ) && is_string( $hashes[ $type ] ) ? $hashes[ $type ] : '';
		if ( ! $expected ) {
			error_log( sprintf( '[ZIP AI Snippet] No hash for "%s/snippet.%s" — skipping.', $slug, $type ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging for snippet execution; no WP core logger equivalent.
			return false;
		}
		$actual = hash_file( 'sha256', $filepath );
		if ( ! hash_equals( $expected, (string) $actual ) ) {
			error_log( sprintf( '[ZIP AI Snippet] Hash mismatch for "%s/snippet.%s" — skipping.', $slug, $type ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging for snippet execution; no WP core logger equivalent.
			return false;
		}
		return true;
	}

	// ── Scope Checking ────────────────────────────────────────────────

	/**
	 * Check if the current request matches the given scope.
	 *
	 * @since 0.0.5
	 * @param string $scope One of: frontend, admin, everywhere, login.
	 * @return bool
	 */
	private static function matches_scope( $scope ) {
		switch ( $scope ) {
			case 'frontend':
				return ! is_admin() && ! self::is_login_page();
			case 'admin':
				return is_admin();
			case 'login':
				return self::is_login_page();
			case 'everywhere':
			default:
				return true;
		}
	}

	/**
	 * Check if conditions array contains any late-evaluated types (page, post_type, post, device).
	 *
	 * @since 0.0.5
	 * @param array<int,array<string,mixed>> $conditions Conditions array.
	 * @return bool
	 */
	private static function has_late_conditions( $conditions ) {
		$late_types = array( 'page', 'post_type', 'post', 'device' );
		foreach ( $conditions as $cond ) {
			if ( isset( $cond['type'] ) && in_array( $cond['type'], $late_types, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if the current request is a login page.
	 *
	 * @since 0.0.5
	 * @return bool
	 */
	private static function is_login_page() {
		return in_array( $GLOBALS['pagenow'] ?? '', array( 'wp-login.php', 'wp-register.php' ), true );
	}

	// ── Registration ───────────────────────────────────────────────────

	/**
	 * Register each enabled snippet on its configured hooks.
	 *
	 * @since 0.0.5
	 */
	private static function register_snippets(): void {
		$manifest = self::load_manifest();

		foreach ( $manifest as $slug => $meta ) {
			if ( '_version' === $slug ) {
				continue;
			}

			// Normalize to get execution config + conditions + sandbox with defaults.
			/**
			 * Narrowed type for `$meta_raw`.
			 *
			 * @var array<string,mixed> $meta_raw
			 */
			$meta_raw = (array) $meta;
			$meta     = Snippet_Store::normalize_snippet( $meta_raw );

			$is_sandbox = ! empty( $meta['sandbox'] );
			$is_enabled = ! empty( $meta['enabled'] );

			// Skip if neither enabled nor in sandbox.
			if ( ! $is_enabled && ! $is_sandbox ) {
				continue;
			}

			if ( empty( $meta['files'] ) || ! is_array( $meta['files'] ) ) {
				continue;
			}

			$snippet_files = self::resolve_snippet_files( $slug, $meta );
			if ( empty( $snippet_files ) ) {
				continue;
			}

			/**
			 * Narrowed type for `$conditions`.
			 *
			 * @var array<int,array<string,mixed>> $conditions
			 */
			$conditions   = isset( $meta['conditions'] ) && is_array( $meta['conditions'] ) ? $meta['conditions'] : array();
			$sandbox_user = $is_sandbox ? Utils::to_int( $meta['sandbox_user'] ?? null, 0 ) : 0;
			$execution    = isset( $meta['execution'] ) && is_array( $meta['execution'] ) ? $meta['execution'] : array();

			foreach ( $snippet_files as $type => $filepath ) {
				$exec          = isset( $execution[ $type ] ) && is_array( $execution[ $type ] ) ? $execution[ $type ] : array();
				$hook          = isset( $exec['hook'] ) && is_string( $exec['hook'] ) ? $exec['hook'] : self::get_default_hook( $type );
				$priority      = Utils::to_int( $exec['priority'] ?? null, 10 );
				$scope         = isset( $exec['scope'] ) && is_string( $exec['scope'] ) ? $exec['scope'] : 'frontend';
				$accepted_args = 'php' === $type ? Utils::to_int( $exec['accepted_args'] ?? null, 1 ) : 1;

				$callback = self::make_callback( $type, $slug, $filepath, $scope, $conditions, $hook, $sandbox_user );
				if ( $callback ) {
					add_action( self::resolve_action_hook( $type, $hook ), $callback, $priority, $accepted_args );
				}
			}
		}
	}

	/**
	 * Get the default hook for a file type.
	 *
	 * @since 0.0.5
	 * @param string $type File type (php, js, css).
	 * @return string
	 */
	private static function get_default_hook( $type ) {
		$defaults = Snippet_Store::default_execution();
		$entry    = $defaults[ $type ] ?? array();
		return $entry['hook'] ?? 'init';
	}

	/**
	 * Resolve the WordPress action hook name for a file type + configured hook.
	 *
	 * JS and CSS need special handling — they use enqueue actions, not the hook directly.
	 *
	 * @since 0.0.5
	 * @param string $type File type (php, js, css).
	 * @param string $hook Configured hook name.
	 * @return string WordPress action name.
	 */
	private static function resolve_action_hook( $type, $hook ) {
		// PHP and HTML hooks map directly to WordPress actions.
		// HTML snippets emit verbatim markup — no enqueue indirection.
		if ( 'php' === $type || 'html' === $type ) {
			return $hook;
		}

		// Footer CSS cannot use wp_add_inline_style because styles are printed
		// during the enqueue/style-print phases. Run directly at wp_footer so
		// the callback's explicit <style> output lands where configured.
		if ( 'css' === $type && 'wp_footer' === $hook ) {
			return 'wp_footer';
		}

		// JS and CSS: map configured hooks to enqueue actions.
		$enqueue_map = array(
			'wp_head'      => 'wp_enqueue_scripts',
			'wp_footer'    => 'wp_enqueue_scripts',
			'admin_head'   => 'admin_enqueue_scripts',
			'admin_footer' => 'admin_enqueue_scripts',
			'login_head'   => 'login_enqueue_scripts',
		);

		return $enqueue_map[ $hook ] ?? 'wp_enqueue_scripts';
	}

	/**
	 * Resolve and validate snippet files for execution.
	 *
	 * @since 0.1.0
	 * @param string              $slug Snippet slug.
	 * @param array<string,mixed> $meta Snippet metadata.
	 * @return array<string,string> Array of [type => filepath] pairs that passed security checks.
	 */
	private static function resolve_snippet_files( $slug, $meta ) {
		$files = array();

		$file_types = isset( $meta['files'] ) && is_array( $meta['files'] ) ? $meta['files'] : array();
		foreach ( $file_types as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}
			$filepath = Snippet_Store::snippet_file( $slug, $type );
			if ( self::validate_snippet_file( $filepath, $slug, $type, $meta ) ) {
				$files[ $type ] = $filepath;
			}
		}

		return $files;
	}

	/**
	 * Validate a snippet file (path containment + existence + integrity).
	 *
	 * @since 0.0.5
	 * @param string              $filepath Absolute snippet file path.
	 * @param string              $slug     Snippet slug.
	 * @param string              $type     File type (php, js, css, html).
	 * @param array<string,mixed> $meta     Snippet metadata.
	 * @return bool
	 */
	private static function validate_snippet_file( $filepath, $slug, $type, $meta ) {
		if ( ! Snippet_Store::is_safe_path( $filepath ) ) {
			error_log( sprintf( '[ZIP AI Snippet] Path escape blocked for "%s".', $slug ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging for snippet execution; no WP core logger equivalent.
			return false;
		}
		if ( ! file_exists( $filepath ) ) {
			return false;
		}
		if ( ! self::verify_integrity( $filepath, $slug, $type, $meta ) ) {
			return false;
		}

		self::$file_to_slug[ $filepath ] = $slug;
		return true;
	}

	// ── Callback Factory ──────────────────────────────────────────────

	/**
	 * Create an execution callback for a snippet file.
	 *
	 * The callback checks scope and conditions at runtime (not registration time)
	 * because WordPress context functions like is_admin() and is_home() may not
	 * be available when hooks are registered.
	 *
	 * @since 0.0.5
	 * @param string                         $type       File type (php, js, css).
	 * @param string                         $slug       Snippet slug.
	 * @param string                         $filepath   Absolute file path.
	 * @param string                         $scope      Execution scope.
	 * @param array<int,array<string,mixed>> $conditions Conditional logic array.
	 * @param string                         $hook       Configured hook name.
	 * @param int                            $sandbox_user Sandbox user ID (0 = not sandboxed).
	 * @return \Closure|null
	 */
	private static function make_callback( $type, $slug, $filepath, $scope, $conditions, $hook, $sandbox_user = 0 ) {
		$is_early = in_array( $hook, self::$early_hooks, true );

		switch ( $type ) {
			case 'php':
				return self::make_php_callback( $slug, $filepath, $scope, $conditions, $is_early, $sandbox_user );
			case 'js':
				return self::make_js_callback( $slug, $filepath, $scope, $conditions, $is_early, $hook, $sandbox_user );
			case 'css':
				return self::make_css_callback( $slug, $filepath, $scope, $conditions, $is_early, $hook, $sandbox_user );
			case 'html':
				return self::make_html_callback( $slug, $filepath, $scope, $conditions, $is_early, $sandbox_user );
			default:
				return null;
		}
	}

	/**
	 * Check if the current user matches the sandbox user.
	 * Returns true if not in sandbox mode (sandbox_user = 0) or if user matches.
	 * Must be called AFTER WordPress loads the user (not on early hooks).
	 *
	 * @since 0.0.5
	 * @param int $sandbox_user User ID (0 = not sandboxed).
	 * @return bool
	 */
	private static function is_sandbox_allowed( $sandbox_user ) {
		if ( 0 === $sandbox_user ) {
			return true; // Not sandboxed — run for everyone.
		}
		return get_current_user_id() === $sandbox_user;
	}

	/**
	 * Build the PHP-snippet execution callback (deferred when early hooks lack
	 * user/conditional context).
	 *
	 * @since 0.0.5
	 * @param string                         $slug         Snippet slug.
	 * @param string                         $filepath     Absolute snippet file path.
	 * @param string                         $scope        Execution scope.
	 * @param array<int,array<string,mixed>> $conditions   Condition rows to evaluate.
	 * @param bool                           $is_early     Whether the hook fires before the `wp` action.
	 * @param int                            $sandbox_user Sandbox user ID (0 = not sandboxed).
	 * @return \Closure
	 */
	private static function make_php_callback( $slug, $filepath, $scope, $conditions, $is_early, $sandbox_user = 0 ) {
		// Sandbox or late conditions require deferral — user ID and conditional tags
		// aren't available on early hooks (init, wp_loaded, admin_init).
		$needs_defer = $is_early && ( $sandbox_user > 0 || self::has_late_conditions( $conditions ) );

		if ( $needs_defer ) {
			return function ( ...$hook_args ) use ( $slug, $filepath, $scope, $conditions, $sandbox_user ) {
				add_action(
					'template_redirect',
					function () use ( $slug, $filepath, $scope, $conditions, $sandbox_user ) {
						if ( ! self::matches_scope( $scope ) ) {
							return;
						}
						if ( ! self::is_sandbox_allowed( $sandbox_user ) ) {
							return;
						}
						if ( ! self::check_conditions( $slug, $conditions, false, 'php:deferred' ) ) {
							return;
						}

						self::include_php_snippet( $slug, $filepath );
					}
				);
				return $hook_args[0] ?? null;
			};
		}

		return function ( ...$hook_args ) use ( $slug, $filepath, $scope, $conditions, $is_early, $sandbox_user ) {
			if ( ! self::matches_scope( $scope ) ) {
				return $hook_args[0] ?? null;
			}
			if ( ! self::is_sandbox_allowed( $sandbox_user ) ) {
				return $hook_args[0] ?? null;
			}
			if ( ! self::check_conditions( $slug, $conditions, $is_early, 'php' ) ) {
				return $hook_args[0] ?? null;
			}

			return self::include_php_snippet( $slug, $filepath, $hook_args );
		};
	}

	/**
	 * Include PHP snippet code with optional hook/filter arguments.
	 *
	 * Snippet authors can read `$args`, `$zip_ai_hook_value`, or `$zip_ai_hook_args`.
	 * For filter hooks, returning a value from the snippet file wins; otherwise a
	 * mutated `$args` variable is returned. Action hooks ignore the return value.
	 *
	 * @since 0.0.5
	 * @param string                  $slug      Snippet slug.
	 * @param string                  $filepath  PHP snippet file path.
	 * @param array<int|string,mixed> $hook_args Arguments passed by the WordPress hook/filter.
	 * @return mixed
	 */
	private static function include_php_snippet( $slug, $filepath, array $hook_args = array() ) {
		$zip_ai_hook_args = array_values( $hook_args );
		if ( array_key_exists( 0, $zip_ai_hook_args ) ) {
			$args              =& $zip_ai_hook_args[0];
			$zip_ai_hook_value =& $zip_ai_hook_args[0];
		} else {
			$args              = null;
			$zip_ai_hook_value = null;
		}
		$result = null;

		self::$executing_slug = $slug;
		try {
			// SECURITY — accepted residual (audit zip-ai#314 #4): this runs
			// author-written PHP. RCE is by design for an admin-authored snippet.
			// The only gate is the *enable* step's brain/client approval card
			// (see ManageCodeSnippet::toggle_snippet) plus admin-only authoring —
			// there is no server-side human check here, and the Snippet_Lint
			// blocklist is defense-in-depth, not a security boundary. A snippet
			// only reaches this line if it was enabled, so the trust decision
			// lives at enable time, not execution time.
			$result = include $filepath;
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[ZIP AI Snippet] "%s" error: %s', $slug, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging for snippet execution; no WP core logger equivalent.
		} finally {
			self::$executing_slug = null;
		}

		if ( null !== $result && 1 !== $result ) {
			return $result;
		}

		return $args;
	}

	/**
	 * Build the CSS-snippet enqueue/inline-style callback.
	 *
	 * @since 0.0.5
	 * @param string                         $slug         Snippet slug.
	 * @param string                         $filepath     Absolute snippet file path.
	 * @param string                         $scope        Execution scope.
	 * @param array<int,array<string,mixed>> $conditions   Condition rows to evaluate.
	 * @param bool                           $is_early     Whether the hook fires before the `wp` action.
	 * @param string                         $hook         Configured hook name.
	 * @param int                            $sandbox_user Sandbox user ID (0 = not sandboxed).
	 * @return \Closure
	 */
	private static function make_css_callback( $slug, $filepath, $scope, $conditions, $is_early, $hook, $sandbox_user = 0 ) {
		return function () use ( $slug, $filepath, $scope, $conditions, $is_early, $hook, $sandbox_user ) {
			if ( ! self::matches_scope( $scope ) ) {
				return;
			}
			if ( ! self::is_sandbox_allowed( $sandbox_user ) ) {
				return;
			}
			if ( ! self::check_conditions( $slug, $conditions, $is_early, 'css' ) ) {
				return;
			}

			$handle  = 'zip-ai-snippet-' . $slug;
			$content = file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( 'wp_footer' === $hook ) {
				// wp_footer doesn't support wp_add_inline_style — output directly.
				echo '<style id="' . esc_attr( $handle ) . '">' . $content . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				wp_register_style( $handle, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
				wp_enqueue_style( $handle );
				wp_add_inline_style( $handle, (string) $content );
			}
		};
	}

	/**
	 * Build the JS-snippet enqueue/inline-script callback.
	 *
	 * @since 0.0.5
	 * @param string                         $slug         Snippet slug.
	 * @param string                         $filepath     Absolute snippet file path.
	 * @param string                         $scope        Execution scope.
	 * @param array<int,array<string,mixed>> $conditions   Condition rows to evaluate.
	 * @param bool                           $is_early     Whether the hook fires before the `wp` action.
	 * @param string                         $hook         Configured hook name.
	 * @param int                            $sandbox_user Sandbox user ID (0 = not sandboxed).
	 * @return \Closure
	 */
	private static function make_js_callback( $slug, $filepath, $scope, $conditions, $is_early, $hook, $sandbox_user = 0 ) {
		$in_footer = in_array( $hook, array( 'wp_footer', 'admin_footer' ), true );

		return function () use ( $slug, $filepath, $scope, $conditions, $is_early, $in_footer, $sandbox_user ) {
			if ( ! self::matches_scope( $scope ) ) {
				return;
			}
			if ( ! self::is_sandbox_allowed( $sandbox_user ) ) {
				return;
			}
			if ( ! self::check_conditions( $slug, $conditions, $is_early, 'js' ) ) {
				return;
			}

			$handle  = 'zip-ai-snippet-' . $slug;
			$content = file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_register_script( $handle, false, array(), false, $in_footer ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
			wp_enqueue_script( $handle );
			wp_add_inline_script( $handle, (string) $content );
		};
	}

	/**
	 * Build an HTML-snippet callback. Emits the file contents verbatim on the
	 * configured WordPress hook (wp_head, wp_footer, login_head, etc.) wrapped
	 * in a tagged HTML comment for traceability. No enqueue-pipeline wrapping —
	 * authors are responsible for valid markup. Use case: external script tag
	 * loaders (GA gtag.js, GTM, FB Pixel, Hotjar), JSON-LD, OG/meta blocks,
	 * `<noscript>` tracking pixels — anything the JS/CSS pipeline would
	 * double-wrap in `<script>`/`<style>` tags.
	 *
	 * @since 0.0.5
	 * @param string                         $slug         Snippet slug.
	 * @param string                         $filepath     Absolute snippet file path.
	 * @param string                         $scope        Execution scope.
	 * @param array<int,array<string,mixed>> $conditions   Condition rows to evaluate.
	 * @param bool                           $is_early     Whether the hook fires before the `wp` action.
	 * @param int                            $sandbox_user Sandbox user ID (0 = not sandboxed).
	 * @return \Closure
	 */
	private static function make_html_callback( $slug, $filepath, $scope, $conditions, $is_early, $sandbox_user = 0 ) {
		return function () use ( $slug, $filepath, $scope, $conditions, $is_early, $sandbox_user ) {
			if ( ! self::matches_scope( $scope ) ) {
				return;
			}
			if ( ! self::is_sandbox_allowed( $sandbox_user ) ) {
				return;
			}
			if ( ! self::check_conditions( $slug, $conditions, $is_early, 'html' ) ) {
				return;
			}

			$content = file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $content || '' === $content ) {
				return;
			}

			// Traceable wrapper so devtools "view source" surfaces the source
			// snippet without altering the rendered markup semantics.
			echo "\n<!-- zip-ai-snippet:" . esc_html( $slug ) . " -->\n";
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "\n<!-- /zip-ai-snippet:" . esc_html( $slug ) . " -->\n";
		};
	}
}
