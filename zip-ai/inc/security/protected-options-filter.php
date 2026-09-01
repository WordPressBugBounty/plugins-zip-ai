<?php
/**
 * Protected-options WP filter. This is a generic backstop.
 *
 * Why this exists
 * ---------------
 * The server and the WP-CLI ability (RunWpCli::verify_command_security) both
 * have protected-resource gates. They cover the common paths. Those paths are
 * the CLI, REST /wp/v2/settings, and the server's tool registry. They do NOT
 * cover:
 *
 *   • Custom-plugin REST/AJAX endpoints. These call update_option('siteurl', …)
 *     through their own settings handlers.
 *   • Generic options.php form posts wrapped by a plugin namespace.
 *   • Future MCP abilities. These may wrap update_option() in a shape that the
 *     server extractor does not recognize.
 *
 * This file installs `pre_update_option_<key>` filters at WP's native
 * option-update layer. Every `update_option()` call fires this filter, whatever
 * the source. Inside an MCP context, the filter refuses mutations to protected
 * keys. It returns the previous value. This follows WP's own contract for the
 * `pre_update_option_<key>` filter. Returning a value other than the new value
 * cancels the update.
 *
 * `Rest_Api::handle_mcp_request` toggles the MCP context flag. It sets the flag
 * on entry and clears it on exit. A `register_shutdown_function` handles the
 * abnormal-exit case.
 *
 * Generalization angle
 * --------------------
 * This is the single point where WordPress core itself observes ALL option
 * mutations. To add a new protected key, add it to PROTECTED_KEYS. One entry
 * then covers every CLI, REST, AJAX and code path that updates that option. You
 * do not need to find every write surface.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and manages protected-options filters during MCP requests.
 *
 * Usage:
 *   Protected_Options_Filter::install();      // once, on plugin load
 *   Protected_Options_Filter::enter_mcp();    // at MCP request start
 *   Protected_Options_Filter::exit_mcp();     // at MCP request end
 */
class Protected_Options_Filter {

	/**
	 * The single source of truth for keys the MCP context cannot mutate.
	 * Mirrors `RunWpCli::$protected_options` (run-wp-cli.php) and the
	 * server-side protected-options registry.
	 *
	 * Keep this in lockstep with the server registry — adding a key here
	 * without updating the server is fine (defense-in-depth strengthens),
	 * but removing one without updating the server leaves a coverage gap.
	 *
	 * This const is the FILTER list only. Callers asking "may a generic
	 * `option update` / `search-replace` touch this key?" must use
	 * `write_protected_keys()` / `is_write_protected()` below, which is the
	 * superset — a new key belongs here only if no legitimate MCP flow writes
	 * it through a dedicated handler (see GENERIC_WRITE_PROTECTED_KEYS).
	 *
	 * @var string[]
	 */
	const PROTECTED_KEYS = array(
		'siteurl',          // brick: frontend/admin URL.
		'home',             // brick: site URL.
		// `template` + `stylesheet` are NOT in the protect-list. The
		// `wp theme activate <slug>` write path in RunWpCli is explicitly
		// allowed + approval-gated and calls `switch_theme()` which
		// updates both options — protecting them here silently no-ops
		// the switch and the handler reports false success. Theme
		// switch is the supported way to change them; arbitrary writes
		// outside that handler aren't a real surface (no MCP tool
		// exposes raw option writes for these keys).
		'admin_email',      // lock-out: password-recovery email.
		'db_version',       // brick: DB schema versioning.
		'blog_charset',     // mojibake risk.
		'users_can_register', // security boundary.
		'default_role',     // privilege escalation surface.
	);

	/**
	 * Extra keys refused for the GENERIC option-write primitives
	 * (`wp option update` / `option delete` / `search-replace`). These keys are
	 * deliberately NOT filter-installed. A legitimate MCP flow writes each of
	 * them through a dedicated handler that must keep working:
	 *
	 *   • `template` / `stylesheet` — `switch_theme()` writes these inside
	 *     `handle_theme_activate`.
	 *   • `active_plugins` — the browser-proxied activate and deactivate
	 *     abilities write this.
	 *   • `upload_path` / `upload_url_path` — these relocate the uploads dir.
	 *     That changes where every future media write lands.
	 *
	 * A `pre_update_option_*` filter on these keys would silently no-op the real
	 * handler. The handler would still report success. This is the failure mode
	 * the PROTECTED_KEYS note above records for `template` and `stylesheet`.
	 *
	 * @var string[]
	 */
	const GENERIC_WRITE_PROTECTED_KEYS = array(
		'template',
		'stylesheet',
		'active_plugins',
		'upload_path',
		'upload_url_path',
		// The Global Block Styles SSOT: every GBS class, its CSS, and the
		// design-token graph for the whole site live in this ONE row as JSON.
		// A generic byte-level write (option update / search-replace) that
		// leaves it unparseable is site-wide styling loss. The spectra-blocks
		// editor and the importer write it through their own REST handlers,
		// which stay unaffected (this list is not filter-installed).
		'spectra_blocks_pro_gs_user_css',
	);

	/**
	 * Every option key the generic write primitives must refuse.
	 *
	 * PROTECTED_KEYS + GENERIC_WRITE_PROTECTED_KEYS + this site's prefixed
	 * `user_roles` map (`{$wpdb->prefix}user_roles`) — the role→capability
	 * table `WP_Roles::for_site()` reads. Writing it grants any role
	 * administrator capabilities, which is the exact outcome
	 * `wp user add-cap <id> manage_options` is blocked to prevent. The prefix
	 * is runtime state, so this cannot be a const.
	 *
	 * @return string[] Lowercase option names.
	 */
	public static function write_protected_keys() {
		global $wpdb;
		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		return array_merge(
			self::PROTECTED_KEYS,
			self::GENERIC_WRITE_PROTECTED_KEYS,
			array( strtolower( $wpdb->prefix . 'user_roles' ) )
		);
	}

	/**
	 * Whether a generic option write/delete must be refused for this key.
	 *
	 * Compared case-insensitively: `wp_options.option_name` collates
	 * case-insensitively on a default install, so `SITEURL` addresses the
	 * same row as `siteurl` and a case-sensitive check is a bypass.
	 *
	 * @param string $key Option name.
	 * @return bool
	 */
	public static function is_write_protected( string $key ) {
		return in_array( strtolower( $key ), self::write_protected_keys(), true );
	}

	/**
	 * In-memory flag indicating whether we're servicing an MCP request.
	 * Toggled by `enter_mcp()` / `exit_mcp()`. Defensive default: false
	 * (out-of-MCP traffic is allowed to mutate options normally; the
	 * filter only fires its refusal during MCP-bound calls).
	 *
	 * @var bool
	 */
	private static $in_mcp_context = false;

	/**
	 * Whether `install()` has already wired the filters. Idempotent guard
	 * for plugin reload / multiple bootstrap paths.
	 *
	 * @var bool
	 */
	private static $installed = false;

	/**
	 * Wire `pre_update_option_<key>` filters for every protected key.
	 * Idempotent — calling twice does nothing on the second call.
	 *
	 * @return void
	 */
	public static function install() {
		if ( self::$installed ) {
			return;
		}
		self::$installed = true;

		foreach ( self::PROTECTED_KEYS as $key ) {
			\add_filter(
				"pre_update_option_{$key}",
				array( __CLASS__, 'maybe_block_update' ),
				10,
				3
			);
		}

		// Safety net: if a fatal error or early exit prevents `exit_mcp()`
		// from running, the shutdown hook clears the flag so the next
		// non-MCP request isn't accidentally treated as MCP-bound.
		\register_shutdown_function( array( __CLASS__, 'exit_mcp' ) );
	}

	/**
	 * Mark the start of an MCP request. The filter callback below uses
	 * this flag to decide whether to refuse the option update.
	 *
	 * @return void
	 */
	public static function enter_mcp() {
		self::$in_mcp_context = true;
	}

	/**
	 * Mark the end of an MCP request. Idempotent.
	 *
	 * @return void
	 */
	public static function exit_mcp() {
		self::$in_mcp_context = false;
	}

	/**
	 * Filter callback wired into `pre_update_option_<key>` for every
	 * key in PROTECTED_KEYS.
	 *
	 * Contract (from WP core):
	 *   - $value     — the new value about to be written.
	 *   - $old_value — the existing value in the DB.
	 *   - $option    — the option name (e.g. 'siteurl').
	 *
	 * Returning $old_value cancels the update. WP treats a new value equal to
	 * the old value as a no-op. It does not write. This method logs the attempt
	 * for observability.
	 *
	 * @param mixed  $value     New value attempted.
	 * @param mixed  $old_value Existing value.
	 * @param string $option    Option name.
	 * @return mixed
	 */
	public static function maybe_block_update( $value, $old_value, $option ) {
		if ( ! self::$in_mcp_context ) {
			return $value;
		}

		// No-op writes (same value) are not worth refusing or logging —
		// some plugins re-save options on activation as a side-effect.
		if ( \maybe_serialize( $value ) === \maybe_serialize( $old_value ) ) {
			return $value;
		}

		\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'[zip-ai] protected_resource_blocked: refused MCP-context update of `%s`. ' .
				'Mutating site-critical options via the AI agent is disabled (would brick the site). ' .
				'If you truly need this change, do it manually in wp-admin → Settings.',
				$option
			)
		);

		return $old_value;
	}
}
