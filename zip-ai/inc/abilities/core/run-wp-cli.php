<?php
/**
 * Run WP-CLI Command Ability.
 *
 * Dispatch strategy:
 *   1. The code runs inside a WP-CLI process (`defined( 'WP_CLI' )`).
 *      It delegates to `\WP_CLI::runcommand()`. This gives full WP-CLI
 *      support in-process.
 *   2. The code runs in a web, REST or AJAX context.
 *      It dispatches the command to a native WordPress PHP function.
 *      The allowlist below selects the function. It uses no subprocess,
 *      no shell, no `proc_open` and no `eval`.
 *
 * The native dispatcher covers the WP-CLI commands an AI agent uses to
 * manage a site (plugin, theme, option, post, user, menu, cron, cache,
 * transient, rewrite, core, language). A command outside the allowlist
 * returns a clear error.
 *
 * ## Authentication and authorisation chain
 *
 * A caller must pass every layer below to reach `execute()`:
 *
 *   1. The MCP REST endpoint `/zip-ai/v1/mcp` gate
 *      (`REST_API::check_permission`). It accepts a valid Bearer token.
 *      It matches the token with `hash_equals` against the decrypted
 *      stored auth token. It also accepts a logged-in admin
 *      (`current_user_can( 'manage_options' )`).
 *   2. `setup_user_context()` resolves `X-WP-User-ID` into a WP user on
 *      the Bearer path. So `current_user_can()` inside the ability
 *      shows the real capabilities of the acting user.
 *   3. The Abilities API permission_callback
 *      (`Abstract_Ability::check_permission`) checks
 *      `current_user_can( manage_options )` again. It runs this before it
 *      routes to `handle_execute()`.
 *   4. This class's `execute()` adds more checks. It runs a second
 *      `current_user_can()` check. It runs an explicit `is_super_admin()`
 *      check on multisite. It runs `verify_command_security()` before
 *      dispatch.
 *
 * `Abstract_Ability::handle_execute` records every successful call. It
 * writes to the `zip_ai_audit_log` option via `Event_Logger`. The record
 * holds the user id, the tool id, the sanitised input, the sanitised
 * output and a timestamp.
 *
 * Compliance: this file contains no forbidden functions from the
 * WordPress.org Plugin Guidelines.
 *
 * @since 0.0.1
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Core;

defined( 'ABSPATH' ) || exit;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Utils;

/**
 * Ability: execute a WP-CLI-style command via native WP functions.
 */
class RunWpCli extends Abstract_Ability {

	use Security_Verifier_Trait;
	use Command_Parser_Trait;
	use Search_Replace_Engine_Trait;

	/** Max bytes of output to return. */
	const MAX_OUTPUT_BYTES = 2097152;

	/**
	 * Marks the tool as destructive for UI approval flows.
	 *
	 * @var bool
	 */
	protected $is_destructive = true;

	/**
	 * Configure the ability metadata.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/run-wp-cli';
		$this->label       = 'Run WP-CLI Command';
		$this->description = 'Run a WP-CLI-style command. Omit the leading "wp" — pass only the subcommand and flags. '
			. 'When the plugin is running inside a WP-CLI process the full WP-CLI command surface is available; in web/REST context only the allowlisted commands below are dispatched to native WordPress functions.'
			. "\n\n"
			. 'READ COMMANDS:'
			. "\n" . '  plugin list [--status=active|inactive|all] [--format=json]'
			. "\n" . '  plugin get <slug> [--format=json]'
			. "\n" . '  plugin is-active <slug>'
			. "\n" . '  theme list [--status=active|inactive] [--format=json]'
			. "\n" . '  theme get [<slug>] [--format=json]'
			. "\n" . '  option get <key>'
			. "\n" . '  option list [--search=pattern]'
			. "\n" . '  post list [--post_type=…] [--post_status=…] [--per_page=…] [--page=…] [--fields=ID,post_title,…] [--format=json|count]'
			. "\n" . '            — paginated: returns `data.pagination` {total,total_pages,page,per_page,has_more}. Use --format=count for the total only; page through with --page when has_more is true.'
			. "\n" . '  post get <ID> [--fields=…] [--format=json]'
			. "\n" . '  post meta get <ID> <meta_key>'
			. "\n" . '  user list [--role=…] [--per_page=…] [--page=…] [--fields=ID,user_login,user_email,…] [--format=json|count]'
			. "\n" . '  user get <id-or-login> [--format=json]'
			. "\n" . '  user meta get <id> <meta_key>'
			. "\n" . '  menu list [--format=json]'
			. "\n" . '  menu item list <menu> [--format=json]'
			. "\n" . '  sidebar list [--format=json]'
			. "\n" . '  widget list <sidebar-id> [--format=json]'
			. "\n" . '  core version'
			. "\n" . '  core check-update                                         — pending core updates (from WP\'s cached update data; no live wp.org call)'
			. "\n" . '  cli info                                                  — PHP / WordPress runtime facts for the request (no WP-CLI binary in web context)'
			. "\n" . '  language core list'
			. "\n" . '  cron event list [--format=json]'
			. "\n" . '  transient get <key>'
			. "\n" . '  transient list [--search=…] [--limit=…] [--network]   — list transient keys + expiry (no values)'
			. "\n" . '  transient type                                         — storage backend (database vs object-cache)'
			. "\n" . '  term list <taxonomy> [--per_page=…] [--page=…] [--hide_empty=true|false] [--format=json|count]'
			. "\n" . '  post meta list <ID>                                       — all meta keys for a post'
			. "\n" . '  user meta list <ID>                                       — all meta keys for a user'
			. "\n" . '  role list                                                 — registered user roles + cap counts'
			. "\n" . '  role list-caps <role>                                     — capability set for one role'
			. "\n" . '  db size [--tables] [--human-readable]                     — database size summary'
			. "\n" . '  rewrite list                                              — current permalink rewrite rules'
			. "\n" . '  cron schedule list                                        — registered cron intervals'
			. "\n" . '  env                                                       — WP / PHP / MySQL / extensions diagnostic'
			. "\n" . '  option list [--search=…] [--autoload=on|off] [--limit=…]  — list options, optionally filtered by autoload'
			. "\n" . '  post-type list [--public=true|false] [--show_in_rest=true|false] [--format=json]'
			. "\n" . '  taxonomy list [--public=true|false] [--object_type=<post_type>] [--format=json]'
			. "\n" . '  comment list [--status=approve|hold|spam|trash|all] [--post_id=<ID>] [--search=…] [--per_page=…] [--page=…] [--fields=…] [--format=json|count|ids]'
			. "\n" . '            — paginated like post list. Default --status=all covers approved + pending; spam/trash need an explicit --status.'
			. "\n" . '  comment get <ID> [--fields=…]'
			. "\n" . '  comment count [<post-ID>]                                 — totals by status (approved, moderated, spam, trash)'
			. "\n" . '  comment status <ID>'
			. "\n" . '  comment exists <ID>'
			. "\n" . '  comment meta get <ID> <meta_key>'
			. "\n" . '  comment meta list <ID>'
			. "\n\n"
			. 'WRITE COMMANDS (require user approval):'
			. "\n" . '  theme activate <slug>                                     — server-side switch_theme()'
			. "\n" . '  (plugin install/activate/deactivate/delete/update and theme install/delete/update'
			. "\n" . '   are NOT routed through `run-wp-cli` — use the dedicated'
			. "\n" . '   abilities zipai/install-plugin, zipai/activate-plugin,'
			. "\n" . '   zipai/deactivate-plugin, zipai/delete-plugin, zipai/update-plugin,'
			. "\n" . '   zipai/install-theme, zipai/delete-theme, zipai/update-theme.'
			. "\n" . '   Calling them via run-wp-cli returns an error.)'
			. "\n" . '  option update <key> <value> [--format=json]'
			. "\n" . '                                                        — set a WP option. Idempotent (creates when missing).'
			. "\n" . '                                                        Brick-risk keys (siteurl, home, admin_email, default_role,'
			. "\n" . '                                                        db_version, blog_charset, users_can_register) refuse upfront.'
			. "\n" . '  option delete <key>                                   — remove a WP option (idempotent on missing).'
			. "\n" . '  post create --post_type=page --post_status=publish --post_title="Title"'
			. "\n" . '  post update <ID> --post_title="New Title"     (post_content edits are blocked here — use editor__apply_change with the page open in the block editor)'
			. "\n" . '  post delete <ID> [<ID2> …] [--force]                       — accepts multiple IDs in one call.'
			. "\n" . '  comment create --comment_post_ID=<ID> --comment_content="…" [--comment_author=…] [--comment_author_email=…] [--comment_approved=0|1]'
			. "\n" . '  comment update <ID> --comment_content="…" [--comment_author=…]'
			. "\n" . '  comment delete <ID> [<ID2> …] [--force]                    — trash by default; --force = permanent. Accepts multiple IDs.'
			. "\n" . '  comment approve|unapprove|spam|unspam|trash|untrash <ID> [<ID2> …]   — moderation; accepts multiple IDs.'
			. "\n" . '  comment recount <post-ID> [<post-ID2> …]                   — recalculate cached comment_count on posts'
			. "\n" . '  menu create <name>'
			. "\n" . '  menu item add-post <menu-id> <post-id>'
			. "\n" . '  cache flush'
			. "\n" . '  rewrite flush'
			. "\n" . '  transient delete --expired               — drop only expired transient pairs (safe cleanup)'
			. "\n" . '  transient delete --all                   — drop every transient (destructive cache flush)'
			. "\n" . '  user add-role <id-or-login> <role>    — non-admin roles only (administrator/super-admin blocked)'
			. "\n" . '  user remove-role <id-or-login> <role> — same restriction'
			. "\n" . '  user set-role <id-or-login> <role>    — replaces all roles; same restriction'
			. "\n" . '  user add-cap <id-or-login> <cap>      — admin-class caps (manage_options, etc.) blocked'
			. "\n" . '  user remove-cap <id-or-login> <cap>   — same restriction'
			. "\n\n"
			. 'SITE-WIDE OPERATIONS (require user approval — destructive):'
			. "\n" . '  search-replace "<old>" "<new>" [--all-tables] [--skip-columns=col1,col2] [--dry-run]'
			. "\n" . '    Serialized-PHP-aware text replacement across post_content, postmeta, options, comments, etc. '
			. "\n" . '    `guid` and `user_pass` columns are ALWAYS skipped. Use --dry-run first to preview counts.'
			. "\n\n"
			. 'NOTES:'
			. "\n" . '  • In web/REST context, commands outside the allowlist return an explanatory error. Use the REST / Abilities API for uncovered operations.'
			. "\n" . '  • "post update --post_content=…" is intentionally blocked: it replaces the entire block-editor content with a plain string, destroying block markup. Use editor__apply_change with the page open in the block editor instead.'
			. "\n" . '  • Blocked for security: eval, eval-file, shell, package, server, db query, db import, db drop, option add/patch (use option update — it creates when missing), post/comment meta add/update/delete/patch, transient set/patch, transient delete <key> (specific-key — use --expired or --all instead), user create/update/delete, and user meta writes. Brick-risk option keys (siteurl, home, admin_email, default_role, db_version, blog_charset, users_can_register) refuse on option update/delete.';
		$this->capability  = 'manage_options';
	}

	/**
	 * Report the tool type to the MCP registry.
	 *
	 * @return string
	 */
	public function get_tool_type() {
		return Tool_Types::ACTION;
	}

	/**
	 * Return the tool's input schema.
	 *
	 * @return array<string,mixed>
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'command' => array(
					'type'        => 'string',
					'description' => 'WP-CLI subcommand and flags — without the leading "wp". '
						. 'Examples: "option get siteurl", "plugin list --status=active --format=json", '
						. '"post list --post_type=attachment --format=json --fields=ID,post_title,guid"',
				),
			),
			'required'   => array( 'command' ),
		);
	}

	// ─── Entrypoint ─────────────────────────────────────────────────────────────

	/**
	 * Execute the command.
	 *
	 * @param array<string,mixed> $input Validated input.
	 * @return array<string,mixed> Response data.
	 */
	public function execute( $input ) {
		// Defense-in-depth — four checks before dispatch. The Abilities API
		// permission_callback (`Abstract_Ability::check_permission`) already
		// gates this execute callback with `current_user_can($capability)`
		// before it is invoked; we re-run the check here so any future
		// direct caller is still subject to the same rule.
		if ( ! is_user_logged_in() ) {
			return Response::error( 'Authentication required.' );
		}
		if ( ! current_user_can( $this->capability ) ) {
			return Response::error( 'Permission denied. You must have manage_options capability.' );
		}
		if ( is_multisite() && ! is_super_admin() ) {
			return Response::error( 'Permission denied. You must be a network administrator on multisite installations to run WP-CLI commands.' );
		}

		$command = trim( Utils::to_str( $input['command'] ?? '' ) );
		if ( '' === $command ) {
			return Response::error( 'Empty command. Example: "option get siteurl"' );
		}

		// Cap command length defensively — anything larger is not a real CLI
		// invocation and risks tying up the parser.
		if ( strlen( $command ) > 4096 ) {
			return Response::error( 'Command is too long. Break it into multiple invocations.' );
		}

		// Strip accidental leading "wp ".
		$command = (string) preg_replace( '/^wp\s+/i', '', $command );

		$args = $this->parse_command_to_args( $command );
		if ( is_wp_error( $args ) ) {
			return Response::error( $args->get_error_message() );
		}

		$security_check = $this->verify_command_security( $args );
		if ( is_wp_error( $security_check ) ) {
			return Response::error( $security_check->get_error_message() );
		}

		// Fast path: the WP-CLI runtime is ready. Delegate to it. But do NOT
		// delegate `search-replace`.
		//
		// The protections for `search-replace` live inside
		// `handle_search_replace()`. They exclude the users, usermeta, sitemeta
		// and ms-global tables. They also filter protected-option rows. The
		// verifier has no equal for these. It cannot know which needle matches
		// which option before the walk. A raw string passed to WP-CLI would run
		// an unguarded replacement (DSA-15). So this one family always uses the
		// native engine.
		//
		// The family test reads EVERY candidate split. It does not read only the
		// `parse_flags` split. Under one parser `--search search-replace <old>
		// <new>` binds the family name as a flag value. WP-CLI reads the same
		// name as the command. That would route the exact command this exception
		// keeps native.
		//
		// This exception does NOT claim full cover on both paths.
		// `verify_command_security()` runs for both executors. So every SECURITY
		// rule in it applies to both. But the plugin and theme lifecycle
		// redirects ("use the dedicated `zipai/install-plugin` ability",
		// `:386-415`) are dispatcher cases. They are not verifier rules. The
		// verifier lets those commands through whenever `wp_is_file_mod_allowed()`
		// is true. On this path they would install or delete server-side. That
		// skips the consent flow the dedicated abilities provide.
		//
		// This branch is NOT dead code. `wp mcp serve` reaches it. It uses the
		// bundled `lib/mcp-adapter`. Its `McpAdapter` inits on `init` under
		// WP-CLI. It is a stdio JSON-RPC MCP server. It routes `tools/call` to
		// abilities. This runs in a process where `WP_CLI` is defined.
		//
		// That entry point stops one hop short today. This is only because
		// `mcp-adapter/execute-ability` refuses anything without
		// `meta['mcp']['public'] === true`. No `zipai/*` ability sets it. One
		// `mcp_adapter_default_server_config` filter would make it reachable. So
		// treat this path as guarded by configuration. Do not treat it as
		// unreachable.
		//
		// A related point is wider than this ability. Only
		// `Rest_Api::handle_mcp_request` (`inc/api/rest-api.php:116`) enters the
		// MCP-context flag of `Protected_Options_Filter`. So on ANY non-REST MCP
		// transport the filter backstop is inert for every protected key. This is
		// tracked separately.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			$families = array_column( $this->candidate_positionals( $args ), 0 );
			if ( ! in_array( 'search-replace', $families, true ) ) {
				return $this->run_via_wpcli_api( $command );
			}
		}

		// Web / REST context — map to native WordPress PHP functions.
		return $this->dispatch_native( $args );
	}

	// ─── Security ───────────────────────────────────────────────────────────────
	// `verify_command_security()` lives in Security_Verifier_Trait
	// (security-verifier-trait.php).

	// ─── WP-CLI runtime passthrough ─────────────────────────────────────────────

	/**
	 * Delegate to `\WP_CLI::runcommand()` when the request is already running
	 * inside a WP-CLI process.
	 *
	 * @param string $command Subcommand string (no leading "wp").
	 * @return array<string,mixed>
	 */
	private function run_via_wpcli_api( string $command ): array {
		try {
			/**
			 * Narrowed type for `$result`.
			 *
			 * @var object{return_code:int,stdout:string,stderr:string} $result
			 */
			$result = \WP_CLI::runcommand(
				$command,
				array(
					'launch'     => false,
					'return'     => 'all',
					'exit_error' => false,
				)
			);
		} catch ( \Throwable $e ) {
			// Internal fault in WP-CLI itself (not a command's own non-zero exit —
			// that's handled below with the real stderr the agent needs). The raw
			// exception text can leak class names / paths, so keep it server-side
			// (WP_DEBUG) and return a static message.
			Utils::debug_log( 'WP-CLI runtime fault', $e->getMessage() );
			return Response::error( 'WP-CLI could not run the command. Please try again.' );
		}

		if ( 0 !== $result->return_code ) {
			$error_raw = '' !== trim( $result->stderr ) ? $result->stderr : $result->stdout;
			$error     = trim( $error_raw );
			if ( '' === $error ) {
				$error = sprintf( 'Command failed (exit %d)', $result->return_code );
			}
			return Response::error( $error );
		}

		$stdout       = trim( $result->stdout );
		$is_truncated = false;

		if ( strlen( $stdout ) >= self::MAX_OUTPUT_BYTES ) {
			$stdout       = substr( $stdout, 0, self::MAX_OUTPUT_BYTES );
			$is_truncated = true;
		}

		/**
		 * Parsed command output.
		 *
		 * @var array<string,mixed> $parsed
		 */
		$parsed = $this->parse_output( $stdout );
		if ( $is_truncated ) {
			$parsed['truncated']         = true;
			$parsed['truncated_message'] = 'Output truncated. Use --format=json with pagination flags to page through results.';
		}
		return $parsed;
	}

	// ─── Native dispatcher ──────────────────────────────────────────────────────

	/**
	 * Dispatch an allowlisted WP-CLI command to a native WordPress handler.
	 *
	 * @param string[] $args Parsed command tokens (no leading "wp").
	 * @return array<string,mixed>
	 */
	private function dispatch_native( array $args ): array {
		list( $positional, $flags ) = $this->parse_flags( $args );

		$base = strtolower( $positional[0] ?? '' );
		$sub  = strtolower( $positional[1] ?? '' );

		// Single-token commands whose first positional is the command itself
		// (rather than a "<base> <sub>" pair). Branch before the switch so the
		// switch's "$base $sub" key doesn't need to encode the search/replace
		// arguments.
		if ( 'search-replace' === $base ) {
			return $this->handle_search_replace( array_slice( $positional, 1 ), $flags );
		}

		// Routes where the subcommand is a single word after the base.
		$route = trim( "{$base} {$sub}" );
		$rest  = array_slice( $positional, 2 );

		switch ( $route ) {
			// Plugin.
			case 'plugin list':
				return $this->handle_plugin_list( $flags );
			case 'plugin get':
				return $this->handle_plugin_get( $rest, $flags );
			case 'plugin is-active':
			case 'plugin is_active':
				return $this->handle_plugin_is_active( $rest );

			// Plugin lifecycle (install/activate/deactivate/delete) is handled by
			// dedicated browser-proxied abilities. Refuse here with an explicit
			// pointer so the LLM picks the right tool instead of running
			// `wp plugin activate ...` and falling through to a stub.
			case 'plugin install':
				return Response::error(
					'`wp plugin install` is not available here. Use the dedicated `zipai/install-plugin` ability — it installs through the user\'s browser session under the user\'s real `install_plugins` capability.'
				);
			case 'plugin activate':
				return Response::error(
					'`wp plugin activate` is not available here. Use the dedicated `zipai/activate-plugin` ability — it activates through the user\'s browser session under the user\'s real `activate_plugins` capability.'
				);
			case 'plugin deactivate':
				return Response::error(
					'`wp plugin deactivate` is not available here. Use the dedicated `zipai/deactivate-plugin` ability — it deactivates through the user\'s browser session under the user\'s real `activate_plugins` capability.'
				);
			case 'plugin delete':
				return Response::error(
					'`wp plugin delete` is not available here. Use the dedicated `zipai/delete-plugin` ability — it deletes through the user\'s browser session under the user\'s real `delete_plugins` capability.'
				);
			case 'plugin update':
				return Response::error(
					'`wp plugin update` is not available here. Use the dedicated `zipai/update-plugin` ability — it updates server-side under the user\'s real `update_plugins` capability and verifies each version bump.'
				);

			// Theme.
			case 'theme list':
				return $this->handle_theme_list( $flags );
			case 'theme get':
			case 'theme status':
				return $this->handle_theme_get( $rest, $flags );
			case 'theme activate':
				return $this->handle_theme_activate( $rest );
			case 'theme install':
				return Response::error(
					'`wp theme install` is not available here. Use the dedicated `zipai/install-theme` ability — it installs through the user\'s browser session under the user\'s real `install_themes` capability.'
				);
			case 'theme delete':
				return Response::error(
					'`wp theme delete` is not available here. Use the dedicated `zipai/delete-theme` ability — it deletes through the user\'s browser session under the user\'s real `delete_themes` capability.'
				);
			case 'theme update':
				return Response::error(
					'`wp theme update` is not available here. Use the dedicated `zipai/update-theme` ability — it updates server-side under the user\'s real `update_themes` capability and verifies each version bump.'
				);

			// Option. Reads + targeted writes (update / delete) are allowed.
			// `add` / `patch` stay blocked in verify_command_security — `update`
			// is idempotent and covers the legitimate write surface. Protected
			// keys (Protected_Options_Filter::write_protected_keys()) refuse upfront.
			case 'option get':
				return $this->handle_option_get( $rest, $flags );
			case 'option list':
				return $this->handle_option_list( $flags );
			case 'option update':
				return $this->handle_option_update( $rest, $flags );
			case 'option delete':
				return $this->handle_option_delete( $rest );

			// Transient — `set`/`patch` and arbitrary-key `delete` are blocked in
			// verify_command_security; the four cases below are the safe ones.
			case 'transient get':
				return $this->handle_transient_get( $rest );
			case 'transient list':
				return $this->handle_transient_list( $flags );
			case 'transient type':
				return $this->handle_transient_type();
			case 'transient delete':
				return $this->handle_transient_delete( $flags );

			// Post.
			case 'post list':
				return $this->handle_post_list( $flags );
			case 'post get':
				return $this->handle_post_get( $rest, $flags );
			case 'post create':
				return $this->handle_post_create( $flags );
			case 'post update':
				return $this->handle_post_update( $rest, $flags );
			case 'post delete':
				return $this->handle_post_delete( $rest, $flags );

			// Post meta — 3 tokens: `post meta <verb> ...`.
			case 'post meta':
				return $this->route_meta( 'post', $rest, $flags );

			// Comment.
			case 'comment list':
				return $this->handle_comment_list( $flags );
			case 'comment get':
				return $this->handle_comment_get( $rest, $flags );
			case 'comment count':
				return $this->handle_comment_count( $rest );
			case 'comment exists':
				return $this->handle_comment_exists( $rest );
			case 'comment status':
				return $this->handle_comment_status( $rest );
			case 'comment create':
				return $this->handle_comment_create( $flags );
			case 'comment update':
				return $this->handle_comment_update( $rest, $flags );
			case 'comment delete':
				return $this->handle_comment_delete( $rest, $flags );
			case 'comment approve':
			case 'comment unapprove':
			case 'comment spam':
			case 'comment unspam':
			case 'comment trash':
			case 'comment untrash':
				return $this->handle_comment_set_status( $sub, $rest );
			case 'comment recount':
				return $this->handle_comment_recount( $rest );

			// Comment meta — reads only (writes blocked in verify_command_security).
			case 'comment meta':
				return $this->route_meta( 'comment', $rest, $flags );

			// User.
			case 'user list':
				return $this->handle_user_list( $flags );
			case 'user get':
				return $this->handle_user_get( $rest, $flags );
			case 'user meta':
				return $this->route_meta( 'user', $rest, $flags );
			case 'user add-role':
				return $this->handle_user_role_change( 'add', $rest );
			case 'user remove-role':
				return $this->handle_user_role_change( 'remove', $rest );
			case 'user set-role':
				return $this->handle_user_role_change( 'set', $rest );
			case 'user add-cap':
				return $this->handle_user_cap_change( 'add', $rest );
			case 'user remove-cap':
				return $this->handle_user_cap_change( 'remove', $rest );

			// Term.
			case 'term list':
				return $this->handle_term_list( $rest, $flags );

			// Post type / taxonomy schema discovery.
			case 'post-type list':
			case 'post_type list':
				return $this->handle_post_type_list( $flags );
			case 'taxonomy list':
				return $this->handle_taxonomy_list( $flags );

			// Menu.
			case 'menu list':
				return $this->handle_menu_list( $flags );
			case 'menu create':
				return $this->handle_menu_create( $rest );
			case 'menu item':
				return $this->route_menu_item( $rest, $flags );

			// Sidebar / widget.
			case 'sidebar list':
				return $this->handle_sidebar_list();
			case 'widget list':
				return $this->handle_widget_list( $rest );

			// Core / language / misc.
			case 'core version':
				return Response::success( array( 'version' => get_bloginfo( 'version' ) ) );
			case 'core check-update':
			case 'core check_update':
				return $this->handle_core_check_update();

			// Core UPDATES are deliberately unsupported — no route, no ability.
			// An explicit refusal beats the generic catch-all so the LLM relays
			// the manual path instead of hunting for a fallback tool.
			case 'core update':
			case 'core upgrade':
			case 'core download':
				return Response::error(
					'WordPress core updates are not supported by the agent — there is no core-update ability and `wp core update` has no web-context route. Ask the user to apply the update in wp-admin → Dashboard → Updates.'
				);

			// CLI runtime probe — reports the PHP/WP process serving the
			// request (there is no WP-CLI binary in web/REST context).
			case 'cli info':
				return $this->handle_cli_info();

			case 'language core':
				if ( 'list' === ( $positional[2] ?? '' ) ) {
					return Response::success( get_available_languages() );
				}
				return Response::error( sprintf( 'Unsupported subcommand: "language core %s".', $positional[2] ?? '' ) );
			case 'cache flush':
				wp_cache_flush();
				return Response::success( array( 'success' => true ) );
			case 'rewrite flush':
				// Soft flush: drop the cached rewrite_rules option so WordPress
				// regenerates rules on the next request. Equivalent to passing
				// false to flush_rewrite_rules() but without writing .htaccess
				// or hitting the VIP restricted-functions sniff.
				delete_option( 'rewrite_rules' );
				return Response::success( array( 'success' => true ) );
			case 'cron event':
				if ( 'list' === ( $positional[2] ?? '' ) ) {
					return $this->handle_cron_event_list();
				}
				return Response::error( sprintf( 'Unsupported subcommand: "cron event %s".', $positional[2] ?? '' ) );
			case 'cron schedule':
				if ( 'list' === ( $positional[2] ?? '' ) ) {
					return $this->handle_cron_schedule_list();
				}
				return Response::error( sprintf( 'Unsupported subcommand: "cron schedule %s".', $positional[2] ?? '' ) );
			case 'rewrite list':
				return $this->handle_rewrite_list();

			// Diagnostics: role discovery, db size, env info.
			case 'role list':
				return $this->handle_role_list( $flags );
			case 'role list-caps':
				return $this->handle_role_list_caps( $rest );
			case 'db size':
				return $this->handle_db_size( $flags );
			case 'env':
				return $this->handle_env();
		}

		return Response::error(
			sprintf(
				'Command "%s" is not available in web/REST context on the WordPress.org build. Use the REST or Abilities API for uncovered operations.',
				trim( "{$base} {$sub}" )
			)
		);
	}

	// ─── Plugin handlers ────────────────────────────────────────────────────────

	/**
	 * Handle "plugin list".
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_plugin_list( array $flags ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// `get_plugin_updates()` reads the cached `update_plugins` site
		// transient (populated by core's scheduled `wp_update_plugins()`) —
		// no forced wp.org call, so it's safe and fast in web/REST context.
		// Without this the `update` / `update_version` columns the caller asks
		// for would always come back empty and a Site Health snapshot would
		// under-report available updates.
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$plugin_updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();

		$plugins = get_plugins();
		$status  = strtolower( Utils::to_str( $flags['status'] ?? 'all', 'all' ) );

		$out = array();
		foreach ( $plugins as $file => $data ) {
			$active = is_plugin_active( $file );
			if ( 'active' === $status && ! $active ) {
				continue;
			}
			if ( 'inactive' === $status && $active ) {
				continue;
			}
			$has_update     = isset( $plugin_updates[ $file ] );
			$update_version = '';
			if ( $has_update ) {
				$entry = $plugin_updates[ $file ];
				if ( isset( $entry->update ) && is_object( $entry->update ) && isset( $entry->update->new_version ) ) {
					$update_version = Utils::to_str( $entry->update->new_version );
				}
			}
			$out[] = array(
				'name'           => sanitize_title( Utils::to_str( $data['Name'] ?? basename( dirname( $file ) ) ) ),
				'file'           => $file,
				'title'          => $data['Name'] ?? '',
				'status'         => $active ? 'active' : 'inactive',
				'version'        => $data['Version'] ?? '',
				// Mirror WP-CLI's `wp plugin list` columns: 'available' | 'none'.
				'update'         => $has_update ? 'available' : 'none',
				'update_version' => $update_version,
				'description'    => wp_trim_words( wp_strip_all_tags( Utils::to_str( $data['Description'] ?? '' ) ), 30 ),
				'author'         => wp_strip_all_tags( Utils::to_str( $data['Author'] ?? '' ) ),
			);
		}
		return Response::success( $out );
	}

	/**
	 * Handle "plugin get <slug>".
	 *
	 * @param string[]            $positional Remaining positional args.
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_plugin_get( array $positional, array $flags ) {
		unset( $flags );
		$slug = $positional[0] ?? '';
		if ( '' === $slug ) {
			return Response::error( 'Usage: plugin get <slug>' );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = $this->resolve_plugin_file( $slug );
		if ( null === $file ) {
			return Response::error( sprintf( 'Plugin "%s" not found.', $slug ) );
		}
		$plugins = get_plugins();
		$data    = $plugins[ $file ] ?? array();
		return Response::success(
			array(
				'file'         => $file,
				'name'         => sanitize_title( Utils::to_str( $data['Name'] ?? $slug ) ),
				'title'        => $data['Name'] ?? '',
				'status'       => is_plugin_active( $file ) ? 'active' : 'inactive',
				'version'      => $data['Version'] ?? '',
				'description'  => wp_strip_all_tags( Utils::to_str( $data['Description'] ?? '' ) ),
				'author'       => wp_strip_all_tags( Utils::to_str( $data['Author'] ?? '' ) ),
				'requires_wp'  => $data['RequiresWP'] ?? '',
				'requires_php' => $data['RequiresPHP'] ?? '',
			)
		);
	}

	/**
	 * Handle "plugin is-active".
	 *
	 * @param string[] $positional Remaining positional args.
	 * @return array<string,mixed>
	 */
	private function handle_plugin_is_active( array $positional ): array {
		$slug = $positional[0] ?? '';
		if ( '' === $slug ) {
			return Response::error( 'Usage: plugin is-active <slug>' );
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = $this->resolve_plugin_file( $slug );
		if ( null === $file ) {
			return Response::success( array( 'active' => false ) );
		}
		return Response::success( array( 'active' => is_plugin_active( $file ) ) );
	}

	/**
	 * Resolve a plugin slug or folder name to its main plugin file.
	 *
	 * @param string $slug Slug or "folder/file.php".
	 * @return string|null Plugin file relative to plugins/, or null if not found.
	 */
	private function resolve_plugin_file( string $slug ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( isset( $plugins[ $slug ] ) ) {
			return $slug;
		}
		foreach ( array_keys( $plugins ) as $file ) {
			if ( 0 === strpos( $file, $slug . '/' ) || 0 === strcasecmp( dirname( $file ), $slug ) ) {
				return $file;
			}
		}
		return null;
	}

	// ─── Theme handlers ─────────────────────────────────────────────────────────

	/**
	 * Handle "theme list".
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_theme_list( array $flags ): array {
		$status  = strtolower( Utils::to_str( $flags['status'] ?? 'all', 'all' ) );
		$current = get_option( 'stylesheet' );

		// Cached theme-update data (`update_themes` transient) — same
		// rationale as plugin updates: read-only, no forced wp.org call,
		// keeps the `update` / `update_version` columns populated.
		if ( ! function_exists( 'get_theme_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$theme_updates = function_exists( 'get_theme_updates' ) ? get_theme_updates() : array();

		$out = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$is_active = ( $stylesheet === $current );
			if ( 'active' === $status && ! $is_active ) {
				continue;
			}
			if ( 'inactive' === $status && $is_active ) {
				continue;
			}
			// `get_theme_updates()` returns WP_Theme objects with an `update`
			// array property (keyed by stylesheet) when an update is offered.
			$has_update     = isset( $theme_updates[ $stylesheet ] );
			$update_version = '';
			if ( $has_update ) {
				$entry = $theme_updates[ $stylesheet ];
				// get_theme_updates() sets ->update to the update-data array; the
				// WP_Theme stub types that dynamic property as bool, so capture it
				// before checking.
				/**
				 * Narrowed type for `$update_data`.
				 *
				 * @var array<string,mixed>|bool $update_data
				 */
				$update_data = $entry->update;
				if ( is_array( $update_data ) && isset( $update_data['new_version'] ) ) {
					$update_version = Utils::to_str( $update_data['new_version'] );
				}
			}
			$out[] = array(
				'name'           => $stylesheet,
				'title'          => $theme->get( 'Name' ),
				'status'         => $is_active ? 'active' : 'inactive',
				'version'        => $theme->get( 'Version' ),
				'update'         => $has_update ? 'available' : 'none',
				'update_version' => $update_version,
				'author'         => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			);
		}
		return Response::success( $out );
	}

	/**
	 * Handle "theme get".
	 *
	 * @param string[]            $positional Remaining positional args.
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_theme_get( array $positional, array $flags ) {
		unset( $flags );
		$slug  = $positional[0] ?? '';
		$theme = '' === $slug ? wp_get_theme() : wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return Response::error( sprintf( 'Theme "%s" not found.', $slug ) );
		}
		return Response::success(
			array(
				'name'        => $theme->get_stylesheet(),
				'title'       => $theme->get( 'Name' ),
				'status'      => ( $theme->get_stylesheet() === get_option( 'stylesheet' ) ) ? 'active' : 'inactive',
				'version'     => $theme->get( 'Version' ),
				'author'      => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'description' => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
				'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : '',
				'template'    => $theme->get_template(),
			)
		);
	}

	/**
	 * Handle "theme activate".
	 *
	 * @param string[] $positional Remaining positional args.
	 * @return array<string,mixed>
	 */
	private function handle_theme_activate( array $positional ): array {
		$slug = $positional[0] ?? '';
		if ( '' === $slug ) {
			return Response::error( 'Usage: theme activate <slug>' );
		}
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return Response::error( sprintf( 'Theme "%s" not found.', $slug ) );
		}
		switch_theme( $slug );
		return Response::success(
			array(
				'activated' => $slug,
			)
		);
	}

	// ─── Option handlers ────────────────────────────────────────────────────────

	/**
	 * Handle "option get".
	 *
	 * @param string[]            $positional Remaining positional args.
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_option_get( array $positional, array $flags ) {
		unset( $flags );
		if ( empty( $positional ) ) {
			return Response::error( 'Usage: option get <key> [<key2> …]' );
		}
		$out = array();
		foreach ( $positional as $key ) {
			$out[ $key ] = get_option( $key );
		}
		return Response::success( 1 === count( $out ) ? reset( $out ) : $out );
	}

	/**
	 * Handle "option list".
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_option_list( array $flags ): array {
		global $wpdb;
		/**
		 * Narrowed type for `$wpdb`.
		 *
		 * @var \wpdb $wpdb
		 */
		$search = Utils::to_str( $flags['search'] ?? '' );
		$limit  = Utils::to_int( $flags['limit'] ?? 200, 200 );
		$limit  = max( 1, min( $limit, 1000 ) );

		// --autoload=on|off|yes|no — useful for performance audits ("what's
		// loaded on every request?"). Maps to the `yes`/`no` values stored in
		// the wp_options.autoload column.
		$autoload_filter = '';
		if ( isset( $flags['autoload'] ) ) {
			$raw = strtolower( Utils::to_str( $flags['autoload'] ) );
			if ( in_array( $raw, array( 'on', 'yes', 'true', '1' ), true ) ) {
				$autoload_filter = 'yes';
			} elseif ( in_array( $raw, array( 'off', 'no', 'false', '0' ), true ) ) {
				$autoload_filter = 'no';
			}
		}

		$sql      = 'SELECT option_name, autoload FROM %i';
		$bindings = array( $wpdb->options );
		if ( '' !== $search && '' !== $autoload_filter ) {
			$sql       .= ' WHERE option_name LIKE %s AND autoload = %s';
			$bindings[] = '%' . $wpdb->esc_like( $search ) . '%';
			$bindings[] = $autoload_filter;
		} elseif ( '' !== $search ) {
			$sql       .= ' WHERE option_name LIKE %s';
			$bindings[] = '%' . $wpdb->esc_like( $search ) . '%';
		} elseif ( '' !== $autoload_filter ) {
			$sql       .= ' WHERE autoload = %s';
			$bindings[] = $autoload_filter;
		}
		$sql       .= ' ORDER BY option_name ASC LIMIT %d';
		$bindings[] = $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query is built from literal fragments + %i/%d placeholders and run through $wpdb->prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $bindings ), ARRAY_A );

		return Response::success( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Handle "option update <key> <value>".
	 *
	 * Mirrors WP-CLI's `wp option update <key> <value> [--format=json]`:
	 *   - Idempotent: it creates the option when the option is missing.
	 *   - `--format=json`: it parses `<value>` as JSON. So callers can pass
	 *     arrays or objects. They do not serialize by hand.
	 *
	 * The code refuses keys in
	 * `Protected_Options_Filter::write_protected_keys()` upfront with a clear
	 * error. These are brick-risk options. They also include keys this generic
	 * primitive must not reach (`active_plugins`, `template`, `stylesheet`,
	 * `upload_path`, `upload_url_path`, `{prefix}user_roles`).
	 * `Protected_Options_Filter` also intercepts the `update_option()` call.
	 * This is a second backstop for the filter-installed subset. The upfront
	 * check means the code never claims success on a write that the filter
	 * turned into a no-op. That failure mode broke theme-activate before the
	 * protect-list fix.
	 *
	 * @param string[]            $positional Remaining positional args ([key, value]).
	 * @param array<string,mixed> $flags      Parsed flags (`--format=json` recognised).
	 * @return array<string,mixed>
	 */
	private function handle_option_update( array $positional, array $flags ): array {
		$key = isset( $positional[0] ) ? (string) $positional[0] : '';
		if ( '' === $key ) {
			return Response::error( 'Usage: option update <key> <value> [--format=json]' );
		}

		if ( \ZipAI\MCP\Classes\Security\Protected_Options_Filter::is_write_protected( $key ) ) {
			return Response::error(
				sprintf(
					'Security policy: option "%s" is protected and cannot be modified via the AI agent. Edit manually in wp-admin → Settings, or use the dedicated ability for it (theme activate / plugin activate).',
					$key
				)
			);
		}

		// Value: positional[1] is the raw string. `--format=json` reinterprets
		// it as a JSON literal (lets callers store arrays/objects).
		if ( ! array_key_exists( 1, $positional ) ) {
			return Response::error( 'Usage: option update <key> <value> [--format=json]' );
		}
		$value = $positional[1];
		if ( isset( $flags['format'] ) && 'json' === strtolower( Utils::to_str( $flags['format'] ) ) ) {
			$decoded = json_decode( $value, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				return Response::error( sprintf( 'Invalid JSON value: %s.', json_last_error_msg() ) );
			}
			$value = $decoded;
		}

		$ok = update_option( $key, $value );

		// `update_option()` returns false for BOTH "value already at this
		// state" and "filter blocked the write" — indistinguishable from
		// the return value alone. Read back and compare to catch the
		// latter case: if the post-write value still differs from what we
		// tried to write, a `pre_update_option_<key>` filter (security
		// plugin / theme / other code) refused it.
		$post = get_option( $key );
		if ( ! $ok && \maybe_serialize( $post ) !== \maybe_serialize( $value ) ) {
			return Response::error(
				sprintf(
					'Option "%s" did not update — a `pre_update_option_%s` filter (security plugin, theme, or other code) is blocking the write.',
					$key,
					$key
				) 
			);
		}

		return Response::success(
			array(
				'key'     => $key,
				'updated' => (bool) $ok,
				'message' => $ok
					? sprintf( 'Option "%s" updated.', $key )
					: sprintf( 'Option "%s" already at this value; no write.', $key ),
			) 
		);
	}

	/**
	 * Handle "option delete <key>".
	 *
	 * Idempotent on missing options (returns success with `deleted: false`).
	 * Brick-risk keys refuse upfront — see handle_option_update for rationale.
	 *
	 * @param string[] $positional Remaining positional args ([key]).
	 * @return array<string,mixed>
	 */
	private function handle_option_delete( array $positional ): array {
		$key = isset( $positional[0] ) ? (string) $positional[0] : '';
		if ( '' === $key ) {
			return Response::error( 'Usage: option delete <key>' );
		}

		if ( \ZipAI\MCP\Classes\Security\Protected_Options_Filter::is_write_protected( $key ) ) {
			return Response::error(
				sprintf(
					'Security policy: option "%s" is protected and cannot be deleted via the AI agent.',
					$key
				)
			);
		}

		// `false` from `get_option` with no default = option doesn't exist.
		// Idempotent: treat absent-option as a successful no-op.
		if ( false === get_option( $key, false ) ) {
			return Response::success(
				array(
					'key'     => $key,
					'deleted' => false,
					'message' => sprintf( 'Option "%s" did not exist; nothing to delete.', $key ),
				) 
			);
		}

		$ok = delete_option( $key );
		if ( ! $ok ) {
			return Response::error( sprintf( 'Failed to delete option "%s".', $key ) );
		}

		return Response::success(
			array(
				'key'     => $key,
				'deleted' => true,
				'message' => sprintf( 'Option "%s" deleted.', $key ),
			) 
		);
	}

	// ─── Transient handlers ─────────────────────────────────────────────────────

	/**
	 * Handle "transient get".
	 *
	 * @param string[] $positional Remaining positional args.
	 * @return array<string,mixed>
	 */
	private function handle_transient_get( array $positional ): array {
		$key = $positional[0] ?? '';
		if ( '' === $key ) {
			return Response::error( 'Usage: transient get <key>' );
		}
		return Response::success( array( 'value' => get_transient( $key ) ) );
	}

	/**
	 * Handle "transient list [--search=…] [--limit=…] [--network]".
	 *
	 * Lists transient keys directly from `wp_options` (or `wp_sitemeta` when
	 * `--network` is passed on multisite). Returns only the key name, expiration
	 * timestamp, and seconds-until-expiry — NOT the value, because transient
	 * payloads can be large serialised structures that blow the response cap.
	 * Use `transient get <key>` to fetch a specific value.
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_transient_list( array $flags ): array {
		global $wpdb;
		/**
		 * Narrowed type for `$wpdb`.
		 *
		 * @var \wpdb $wpdb
		 */
		$is_network = ! empty( $flags['network'] );
		if ( $is_network && ! is_multisite() ) {
			return Response::error( '--network requires a multisite install.' );
		}

		$search = isset( $flags['search'] ) ? Utils::to_str( $flags['search'] ) : '';
		$limit  = isset( $flags['limit'] ) ? max( 1, min( 1000, Utils::to_int( $flags['limit'] ) ) ) : 200;

		$prefix         = $is_network ? '_site_transient_' : '_transient_';
		$timeout_prefix = $is_network ? '_site_transient_timeout_' : '_transient_timeout_';

		if ( $is_network ) {
			$table       = (string) $wpdb->sitemeta;
			$name_column = 'meta_key';
		} else {
			$table       = (string) $wpdb->options;
			$name_column = 'option_name';
		}

		$sql  = 'SELECT %i AS k FROM %i WHERE %i LIKE %s AND %i NOT LIKE %s';
		$args = array(
			$name_column,
			$table,
			$name_column,
			$wpdb->esc_like( $prefix ) . '%',
			$name_column,
			$wpdb->esc_like( $timeout_prefix ) . '%',
		);
		if ( $is_network ) {
			$sql   .= ' AND site_id = %d';
			$args[] = get_current_network_id();
		}
		if ( '' !== $search ) {
			$sql   .= ' AND %i LIKE %s';
			$args[] = $name_column;
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$sql   .= ' ORDER BY %i ASC LIMIT %d';
		$args[] = $name_column;
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query is built from literal fragments + %i/%s/%d placeholders and run through $wpdb->prepare().
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		$now    = time();
		$result = array();
		foreach ( (array) $rows as $option_name ) {
			$key            = substr( Utils::to_str( $option_name ), strlen( $prefix ) );
			$timeout_option = $timeout_prefix . $key;
			$expiration     = $is_network
				? get_site_option( $timeout_option )
				: get_option( $timeout_option );
			$expiration     = Utils::to_int( $expiration );
			$result[]       = array(
				'key'        => $key,
				'expiration' => $expiration,
				'expires_in' => $expiration > 0 ? max( 0, $expiration - $now ) : null,
				'expired'    => $expiration > 0 && $expiration < $now,
			);
		}

		return Response::success(
			array(
				'scope' => $is_network ? 'site' : 'blog',
				'count' => count( $result ),
				'items' => $result,
			)
		);
	}

	/**
	 * Handle "transient type" — return the storage backend.
	 *
	 * @return array<string,mixed>
	 */
	private function handle_transient_type(): array {
		return Response::success(
			array(
				'storage'                => wp_using_ext_object_cache() ? 'object-cache' : 'database',
				'using_ext_object_cache' => wp_using_ext_object_cache(),
				'multisite'              => is_multisite(),
				// Hint for the LLM about where `transient list` is currently
				// reading from — when object-cache is on, the option-table
				// listing won't see transients that live only in the cache.
				'list_visibility'        => wp_using_ext_object_cache()
					? 'transient list reads the option/sitemeta table only; transients held entirely in the persistent object cache are NOT enumerated here.'
					: 'transient list enumerates every site/network transient from the option/sitemeta table.',
			)
		);
	}

	/**
	 * Handle "transient delete --expired | --all". Specific-key deletion
	 * (`transient delete <key>`) is refused upstream by
	 * `verify_command_security` — the LLM shouldn't be picking individual
	 * transient keys to nuke.
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_transient_delete( array $flags ): array {
		$expired_only = ! empty( $flags['expired'] );
		$all          = ! empty( $flags['all'] );

		// Defence in depth — the security verifier already gates these two cases.
		if ( ! $expired_only && ! $all ) {
			return Response::error( 'Usage: transient delete --expired | --all' );
		}
		if ( $expired_only && $all ) {
			return Response::error( 'Pass either --expired or --all, not both.' );
		}

		if ( $expired_only ) {
			$deleted = $this->delete_expired_transients( false );
			// On multisite also clean network transients for the current network.
			if ( is_multisite() ) {
				$deleted += $this->delete_expired_transients( true );
			}
			return Response::success(
				array(
					'mode'             => 'expired',
					'transients_freed' => $deleted,
					'message'          => sprintf( 'Deleted %d expired transient entr%s.', $deleted, 1 === $deleted ? 'y' : 'ies' ),
				)
			);
		}

		// --all: nuke every transient. Mirrors `wp transient delete --all`.
		$deleted = $this->delete_all_transients( false );
		if ( is_multisite() ) {
			$deleted += $this->delete_all_transients( true );
		}
		if ( wp_using_ext_object_cache() ) {
			// Transients in the persistent object cache live outside the
			// options/sitemeta tables; flush the cache so they're gone too.
			wp_cache_flush();
		}
		return Response::success(
			array(
				'mode'                 => 'all',
				'transients_freed'     => $deleted,
				'object_cache_flushed' => wp_using_ext_object_cache(),
				'message'              => sprintf( 'Deleted %d transient entr%s.', $deleted, 1 === $deleted ? 'y' : 'ies' ),
			)
		);
	}

	/**
	 * Delete expired transient + timeout option pairs.
	 *
	 * @param bool $network When true, operate on `_site_transient_*` entries
	 *                      in $wpdb->sitemeta. Otherwise blog-level options.
	 * @return int Number of `_transient_*` (non-timeout) options removed.
	 */
	private function delete_expired_transients( bool $network ): int {
		global $wpdb;
		/**
		 * Narrowed type for `$wpdb`.
		 *
		 * @var \wpdb $wpdb
		 */
		$now = time();
		if ( $network ) {
			$table            = (string) $wpdb->sitemeta;
			$name_column      = 'meta_key';
			$value_column     = 'meta_value';
			$timeout_prefix   = '_site_transient_timeout_';
			$transient_prefix = '_site_transient_';
		} else {
			$table            = (string) $wpdb->options;
			$name_column      = 'option_name';
			$value_column     = 'option_value';
			$timeout_prefix   = '_transient_timeout_';
			$transient_prefix = '_transient_';
		}

		// Find expired timeout entries.
		$sql  = 'SELECT %i FROM %i WHERE %i LIKE %s AND %i < %d';
		$args = array( $name_column, $table, $name_column, $wpdb->esc_like( $timeout_prefix ) . '%', $value_column, $now );
		if ( $network ) {
			$sql   .= ' AND site_id = %d';
			$args[] = get_current_network_id();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query is built from literal fragments + %i/%s/%d placeholders and run through $wpdb->prepare().
		$expired_timeout_names = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		if ( empty( $expired_timeout_names ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $expired_timeout_names as $timeout_option ) {
			$key  = substr( Utils::to_str( $timeout_option ), strlen( $timeout_prefix ) );
			$pair = $transient_prefix . $key;
			if ( $network ) {
				if ( delete_site_transient( $key ) ) {
					++$deleted;
				} else {
					// Belt-and-braces — drop dangling rows directly.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->delete(
						$table,
						array(
							$name_column => $pair,
							'site_id'    => get_current_network_id(),
						) 
					);
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->delete(
						$table,
						array(
							$name_column => $timeout_option,
							'site_id'    => get_current_network_id(),
						) 
					);
				}
			} else {
				if ( delete_transient( $key ) ) {
					++$deleted;
				} else {
					delete_option( $pair );
					delete_option( Utils::to_str( $timeout_option ) );
				}
			}
		}
		return $deleted;
	}

	/**
	 * Delete every transient (both `_transient_*` and `_transient_timeout_*`).
	 *
	 * Walks the discovered key list and calls core's `delete_transient()` /
	 * `delete_site_transient()` for each one. Slower than a raw DELETE but
	 * keeps the in-memory option/transient cache coherent — non-autoloaded
	 * transient rows aren't in `alloptions`, so a bulk SQL DELETE alone
	 * would leave stale values in the per-option cache.
	 *
	 * @param bool $network See delete_expired_transients().
	 * @return int Number of transient entries removed.
	 */
	private function delete_all_transients( bool $network ): int {
		global $wpdb;
		/**
		 * Narrowed type for `$wpdb`.
		 *
		 * @var \wpdb $wpdb
		 */
		if ( $network ) {
			$table          = (string) $wpdb->sitemeta;
			$name_column    = 'meta_key';
			$prefix         = '_site_transient_';
			$timeout_prefix = '_site_transient_timeout_';
		} else {
			$table          = (string) $wpdb->options;
			$name_column    = 'option_name';
			$prefix         = '_transient_';
			$timeout_prefix = '_transient_timeout_';
		}

		// Discover every transient name (skip the timeout pairs — delete_*_transient
		// removes them along with the main entry).
		$sql  = 'SELECT %i FROM %i WHERE %i LIKE %s AND %i NOT LIKE %s';
		$args = array( $name_column, $table, $name_column, $wpdb->esc_like( $prefix ) . '%', $name_column, $wpdb->esc_like( $timeout_prefix ) . '%' );
		if ( $network ) {
			$sql   .= ' AND site_id = %d';
			$args[] = get_current_network_id();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query is built from literal fragments + %i/%s/%d placeholders and run through $wpdb->prepare().
		$names = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		if ( empty( $names ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $names as $option_name ) {
			$key = substr( Utils::to_str( $option_name ), strlen( $prefix ) );
			if ( '' === $key ) {
				continue;
			}
			$ok = $network ? delete_site_transient( $key ) : delete_transient( $key );
			if ( $ok ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	// ─── Pagination helpers ─────────────────────────────────────────────────────

	/**
	 * Resolve the page window (page size + zero-based offset) for a list
	 * command from its flags.
	 *
	 * Accepts both the WP-CLI input style (`--posts_per_page` / `--number`,
	 * `--offset`) and the WP REST style (`--per_page`, `--page`). When an
	 * explicit `--offset` is absent it is derived from `--page` (1-based).
	 *
	 * @param array<string,mixed> $flags        Parsed command flags.
	 * @param string              $size_flag    Primary page-size flag for this
	 *                                          command (`posts_per_page` for
	 *                                          posts, `number` for users/terms).
	 * @param int                 $default_size Fallback page size when none is given.
	 * @return array{0:int,1:int} [per_page, offset] — both clamped to safe bounds.
	 */
	private function resolve_page_window( array $flags, string $size_flag, int $default_size ): array {
		$raw = $flags[ $size_flag ] ?? $flags['per_page'] ?? null;

		// `-1` (or any negative) is the WP-CLI idiom for "all rows". Return the
		// sentinel -1 so handlers can request an unbounded query instead of
		// silently clamping the universal "give me everything" request to a
		// single row.
		if ( null !== $raw && Utils::to_int( $raw ) < 0 ) {
			return array( -1, 0 );
		}

		$per_page = Utils::to_int( $raw, $default_size );
		$per_page = max( 1, min( $per_page, 1000 ) );

		if ( isset( $flags['offset'] ) ) {
			$offset = max( 0, Utils::to_int( $flags['offset'] ) );
		} elseif ( isset( $flags['page'] ) ) {
			$offset = ( max( 1, Utils::to_int( $flags['page'] ) ) - 1 ) * $per_page;
		} else {
			$offset = 0;
		}

		return array( $per_page, $offset );
	}

	/**
	 * Wrap a page of rows with WP REST-standard pagination metadata.
	 *
	 * Mirrors the WP REST API contract (the `X-WP-Total` / `X-WP-TotalPages`
	 * headers) in the response body so the agent can page deterministically
	 * with `--page` instead of guessing completeness from row count — a
	 * silently-capped page is otherwise indistinguishable from a full result.
	 *
	 * @param array<int|string,mixed> $items    Rows for the current page.
	 * @param int                     $total    Total matching rows across every page.
	 * @param int                     $per_page Page size actually applied.
	 * @param int                     $offset   Zero-based offset of the current page.
	 * @return array<string,mixed> Standardized success response with `data.pagination`.
	 */
	private function paginated_response( array $items, int $total, int $per_page, int $offset ): array {
		$per_page = max( 1, $per_page );

		return Response::success(
			$items,
			array(
				'pagination' => array(
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
					'page'        => (int) floor( $offset / $per_page ) + 1,
					'per_page'    => $per_page,
					'has_more'    => ( $offset + count( $items ) ) < $total,
				),
			)
		);
	}

	/**
	 * Whether the command requested a bare count (`--format=count`), mirroring
	 * WP-CLI's `--format=count` which prints only the total number of rows.
	 *
	 * @param array<string,mixed> $flags Parsed command flags.
	 * @return bool
	 */
	private function wants_count( array $flags ): bool {
		return 'count' === strtolower( Utils::to_str( $flags['format'] ?? '' ) );
	}

	/**
	 * Whether the command requested only IDs (`--format=ids`), mirroring
	 * WP-CLI's `--format=ids` which prints just the row identifiers. The
	 * native dispatcher returns them as a flat JSON array so the agent can
	 * feed them straight into a follow-up bulk operation.
	 *
	 * @param array<string,mixed> $flags Parsed command flags.
	 * @return bool
	 */
	private function wants_ids( array $flags ): bool {
		return 'ids' === strtolower( Utils::to_str( $flags['format'] ?? '' ) );
	}

	/**
	 * Normalize a `--post_status` flag to a value WP_Query understands.
	 *
	 * - `all` → every registered status (incl. trash/draft), matching the
	 *   agent's intent of "every post regardless of status". WP_Query has no
	 *   native `all`, so left raw it silently matches nothing.
	 * - Comma lists (`publish,draft`) → array, mirroring WP-CLI.
	 * - Anything else (`publish`, `any`, …) → passed through untouched.
	 *
	 * @param mixed $status Raw flag value.
	 * @return string|array<int|string,mixed>
	 */
	private function normalize_post_status( $status ) {
		if ( is_array( $status ) ) {
			return $status;
		}
		$status = Utils::to_str( $status );
		if ( 'all' === strtolower( $status ) ) {
			return array_values( get_post_stati() );
		}
		if ( false !== strpos( $status, ',' ) ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $status ) ), static fn ( string $s ): bool => '' !== $s ) );
		}
		return $status;
	}

	// ─── Post handlers ──────────────────────────────────────────────────────────

	/**
	 * Handle "post list".
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_post_list( array $flags ): array {
		$post_type = Utils::to_str( $flags['post_type'] ?? 'post', 'post' );
		// Attachments are ALWAYS stored with post_status='inherit', never
		// 'publish'. Defaulting to 'publish' (correct for posts/pages)
		// silently returns zero media for `post list --post_type=attachment`,
		// which made the agent report "no media files" on sites that have
		// plenty. Mirror WP-CLI: when no --post_status is given, attachments
		// default to 'inherit'; everything else keeps 'publish'. An explicit
		// `--post_status=any` still overrides either way.
		$default_status            = 'attachment' === $post_type ? 'inherit' : 'publish';
		list( $per_page, $offset ) = $this->resolve_page_window( $flags, 'posts_per_page', 20 );
		$all_rows                  = ( -1 === $per_page );
		$query_args                = array(
			'post_type'      => $post_type,
			'post_status'    => $this->normalize_post_status( $flags['post_status'] ?? $default_status ),
			'posts_per_page' => $all_rows ? -1 : $per_page,
			'offset'         => $all_rows ? 0 : $offset,
			'orderby'        => $flags['orderby'] ?? 'date',
			'order'          => strtoupper( Utils::to_str( $flags['order'] ?? 'DESC', 'DESC' ) ),
		);
		if ( ! empty( $flags['s'] ) ) {
			$query_args['s'] = Utils::to_str( $flags['s'] );
		}

		// `--format=count`: mirror WP-CLI and return only the total, computed
		// from found_posts so it is accurate regardless of page size.
		if ( $this->wants_count( $flags ) ) {
			$count_query = new \WP_Query(
				array_merge(
					$query_args,
					array(
						'fields'         => 'ids',
						'posts_per_page' => 1,
						'offset'         => 0,
					) 
				)
			);
			return Response::success( (int) $count_query->found_posts );
		}

		// `--format=ids`: flat list of post IDs for chaining into bulk ops.
		if ( $this->wants_ids( $flags ) ) {
			$id_query = new \WP_Query( array_merge( $query_args, array( 'fields' => 'ids' ) ) );
			$ids      = array_map( static fn ( $p ) => is_scalar( $p ) ? (int) $p : 0, $id_query->posts );
			return $this->paginated_response(
				$ids,
				(int) $id_query->found_posts,
				$all_rows ? max( 1, (int) $id_query->found_posts ) : $per_page,
				$all_rows ? 0 : $offset
			);
		}

		$fields = $this->resolve_fields_flag( $flags, array( 'ID', 'post_title', 'post_status', 'post_date', 'post_type' ) );

		$query = new \WP_Query( $query_args );
		$out   = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$out[] = $this->post_to_array( $post, $fields );
		}
		$total = (int) $query->found_posts;
		return $this->paginated_response( $out, $total, $all_rows ? max( 1, $total ) : $per_page, $all_rows ? 0 : $offset );
	}

	/**
	 * Handle "post get".
	 *
	 * @param string[]            $positional Remaining positional args.
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_post_get( array $positional, array $flags ) {
		$post_id = isset( $positional[0] ) ? (int) $positional[0] : 0;
		if ( $post_id <= 0 ) {
			return Response::error( 'Usage: post get <ID>' );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return Response::error( sprintf( 'Post %d not found.', $post_id ) );
		}
		$fields = $this->resolve_fields_flag( $flags, null );
		return Response::success( $this->post_to_array( $post, $fields ) );
	}

	/**
	 * Handle "post create".
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_post_create( array $flags ) {
		$data = $this->flags_to_post_data( $flags );
		if ( isset( $data['post_type'] ) && $this->is_managed_post_type( $data['post_type'] ) ) {
			return $this->managed_post_type_error( $data['post_type'] );
		}
		// wp_insert_post() runs wp_unslash() on the data — without wp_slash()
		// a literal backslash in a title/excerpt ("C:\new deals") is eaten.
		// The comment handlers on the identical contract already slash.
		$result = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $result ) ) {
			return Response::error( $result->get_error_message() );
		}
		return Response::success( array( 'ID' => (int) $result ) );
	}

	/**
	 * Handle "post update".
	 *
	 * @param string[]            $positional Remaining positional args.
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_post_update( array $positional, array $flags ) {
		$post_id = isset( $positional[0] ) ? (int) $positional[0] : 0;
		if ( $post_id <= 0 ) {
			return Response::error( 'Usage: post update <ID> --field=value ...' );
		}
		// Site-infrastructure posts are off-limits for the GENERIC post
		// primitive in BOTH directions: retyping a managed post detaches it
		// (a wp_template_part turned "post" drops the site chrome), and any
		// sibling-field write on one degrades it (drafting a wp_global_styles
		// post pulls the design tokens off the front end) — the exact outcome
		// the --post_content guard already exists to prevent, one field over.
		$target = get_post( $post_id );
		if ( $target && $this->is_managed_post_type( (string) $target->post_type ) ) {
			return $this->managed_post_type_error( (string) $target->post_type );
		}
		$data = $this->flags_to_post_data( $flags );
		if ( isset( $data['post_type'] ) && $this->is_managed_post_type( $data['post_type'] ) ) {
			return $this->managed_post_type_error( $data['post_type'] );
		}
		$data['ID'] = $post_id;
		// wp_update_post() runs wp_unslash() on the data — see handle_post_create.
		$result = wp_update_post( wp_slash( $data ), true );
		if ( is_wp_error( $result ) ) {
			return Response::error( $result->get_error_message() );
		}
		return Response::success( array( 'ID' => (int) $result ) );
	}

	/**
	 * Post types the generic post primitives must not touch — WordPress site
	 * infrastructure owned by dedicated tools (template editing, navigation,
	 * global styles, the font library).
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private function is_managed_post_type( string $post_type ): bool {
		return in_array(
			$post_type,
			array( 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face' ),
			true
		);
	}

	/**
	 * The refusal for a managed post type, pointing at the owning tools.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<string,mixed>
	 */
	private function managed_post_type_error( string $post_type ): array {
		return Response::error(
			sprintf(
				'Refused: `%s` posts are WordPress site infrastructure (templates, navigation, global styles, fonts). Generic post commands cannot create, retype, restate or delete them. Use the dedicated theme/navigation/style tools instead.',
				$post_type
			)
		);
	}

	/**
	 * Handle "post delete <ID> [<ID2> …] [--force]".
	 *
	 * Mirrors WP-CLI's native multi-id syntax. `wp post delete 12 34 56`
	 * processes all three ids. The earlier single-id code dropped everything
	 * after positional[0]. It reported success on the partial outcome. The LLM
	 * could not know it deleted too few posts.
	 *
	 * Partial-success semantics:
	 *   - At least one delete works → `success: true`. The result holds
	 *     `deleted` (the list of ids that landed) and `failed` (a reason per
	 *     id). The caller phrases the response on the real counts.
	 *   - No delete works (every id is missing or a hook refused it) →
	 *     `Response::error`. So the caller does not claim success on a no-op
	 *     turn.
	 *
	 * @param string[]            $positional Remaining positional args (one or more post IDs).
	 * @param array<string,mixed> $flags      Parsed flags (`--force` recognised).
	 * @return array<string,mixed>
	 */
	private function handle_post_delete( array $positional, array $flags ): array {
		if ( empty( $positional ) ) {
			return Response::error( 'Usage: post delete <ID> [<ID2> …] [--force]' );
		}
		$force = ! empty( $flags['force'] );

		$deleted = array();
		$failed  = array();

		foreach ( $positional as $arg ) {
			$post_id = (int) $arg;
			if ( $post_id <= 0 ) {
				$failed[] = array(
					'id'     => (string) $arg,
					'reason' => 'not a positive integer',
				);
				continue;
			}
			// Same infrastructure guard as post update — deleting a
			// wp_template_part / wp_navigation / wp_global_styles post through
			// the generic primitive detaches site chrome or design tokens.
			$target = get_post( $post_id );
			if ( $target && $this->is_managed_post_type( (string) $target->post_type ) ) {
				$failed[] = array(
					'id'     => $post_id,
					'reason' => "post type `{$target->post_type}` is site infrastructure, use the dedicated theme/navigation/style tools",
				);
				continue;
			}
			$result = wp_delete_post( $post_id, $force );
			if ( $result ) {
				$deleted[] = $post_id;
			} else {
				$failed[] = array(
					'id'     => $post_id,
					'reason' => 'wp_delete_post returned false (post not found, already trashed when --force omitted, or hook refused)',
				);
			}
		}

		// All-fail → error, so the caller doesn't claim success on zero deletes.
		// Mirrors the same truth-telling rule core__bulk_run_wp_cli enforces:
		// if NOTHING landed, don't mark dependent todos done.
		if ( empty( $deleted ) ) {
			$ids     = array_map( static fn( $f ) => (string) $f['id'], $failed );
			$details = array_map( static fn( $f ) => $f['id'] . ' (' . $f['reason'] . ')', $failed );
			return Response::error(
				sprintf(
					'No posts deleted. Attempted IDs: %s. Failures: %s.',
					implode( ', ', $ids ),
					implode( '; ', $details )
				) 
			);
		}

		return Response::success(
			array(
				'deleted' => $deleted,
				'failed'  => $failed,
				'forced'  => $force,
			)
		);
	}

	// ─── Comment handlers ───────────────────────────────────────────────────────

	/**
	 * Handle "comment list".
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_comment_list( array $flags ): array {
		list( $per_page, $offset ) = $this->resolve_page_window( $flags, 'number', 20 );
		$all_rows                  = ( -1 === $per_page );
		$query_args                = array(
			// WP_Comment_Query's 'all' covers approved + pending; spam/trash
			// rows only appear when requested explicitly via --status.
			'status'  => Utils::to_str( $flags['status'] ?? 'all', 'all' ),
			'number'  => $all_rows ? 0 : $per_page,
			'offset'  => $all_rows ? 0 : $offset,
			'orderby' => $flags['orderby'] ?? 'comment_date_gmt',
			'order'   => strtoupper( Utils::to_str( $flags['order'] ?? 'DESC', 'DESC' ) ),
		);
		// WP-CLI uses --comment_post_ID; accept --post_id as the friendlier alias.
		$post_id = Utils::to_int( $flags['comment_post_ID'] ?? $flags['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$query_args['post_id'] = $post_id;
		}
		if ( ! empty( $flags['search'] ) ) {
			$query_args['search'] = Utils::to_str( $flags['search'] );
		}
		if ( ! empty( $flags['type'] ) ) {
			$query_args['type'] = Utils::to_str( $flags['type'] );
		}

		$count_args = array_merge(
			$query_args,
			array(
				'count'  => true,
				'number' => 0,
				'offset' => 0,
			)
		);

		// `--format=count`: total matching comments, no rows fetched.
		if ( $this->wants_count( $flags ) ) {
			return Response::success( (int) ( new \WP_Comment_Query() )->query( $count_args ) );
		}

		$total = (int) ( new \WP_Comment_Query() )->query( $count_args );

		// `--format=ids`: flat list of comment IDs for chaining into bulk ops.
		if ( $this->wants_ids( $flags ) ) {
			$ids = (array) ( new \WP_Comment_Query() )->query( array_merge( $query_args, array( 'fields' => 'ids' ) ) );
			return $this->paginated_response(
				array_map( static fn ( $id ) => is_scalar( $id ) ? (int) $id : 0, $ids ),
				$total,
				$all_rows ? max( 1, $total ) : $per_page,
				$all_rows ? 0 : $offset
			);
		}

		$fields   = $this->resolve_fields_flag(
			$flags,
			array( 'comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email', 'comment_date', 'status' )
		);
		$comments = (array) ( new \WP_Comment_Query() )->query( $query_args );
		$out      = array();
		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}
			$out[] = $this->comment_to_array( $comment, $fields );
		}
		return $this->paginated_response( $out, $total, $all_rows ? max( 1, $total ) : $per_page, $all_rows ? 0 : $offset );
	}

	/**
	 * Handle "comment get".
	 *
	 * @param string[]            $positional Remaining positional args.
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_comment_get( array $positional, array $flags ) {
		$comment_id = isset( $positional[0] ) ? (int) $positional[0] : 0;
		if ( $comment_id <= 0 ) {
			return Response::error( 'Usage: comment get <ID>' );
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return Response::error( sprintf( 'Comment %d not found.', $comment_id ) );
		}
		$fields = $this->resolve_fields_flag( $flags, null );
		return Response::success( $this->comment_to_array( $comment, $fields ) );
	}

	/**
	 * Handle "comment count [<post-ID>]".
	 *
	 * @param string[] $positional Remaining positional args.
	 * @return array<string,mixed>
	 */
	private function handle_comment_count( array $positional ): array {
		$post_id = isset( $positional[0] ) ? (int) $positional[0] : 0;
		$counts  = wp_count_comments( $post_id );
		return Response::success( (array) $counts );
	}

	/**
	 * Handle "comment exists <ID>".
	 *
	 * @param string[] $positional Remaining positional args.
	 * @return array<string,mixed>
	 */
	private function handle_comment_exists( array $positional ): array {
		$comment_id = isset( $positional[0] ) ? (int) $positional[0] : 0;
		if ( $comment_id <= 0 ) {
			return Response::error( 'Usage: comment exists <ID>' );
		}
		return Response::success( array( 'exists' => (bool) get_comment( $comment_id ) ) );
	}

	/**
	 * Handle "comment status <ID>".
	 *
	 * @param string[] $positional Remaining positional args.
	 * @return array<string,mixed>
	 */
	private function handle_comment_status( array $positional ): array {
		$comment_id = isset( $positional[0] ) ? (int) $positional[0] : 0;
		if ( $comment_id <= 0 ) {
			return Response::error( 'Usage: comment status <ID>' );
		}
		$status = wp_get_comment_status( $comment_id );
		if ( false === $status ) {
			return Response::error( sprintf( 'Comment %d not found.', $comment_id ) );
		}
		return Response::success( array( 'status' => $status ) );
	}

	/**
	 * Handle "comment create".
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_comment_create( array $flags ) {
		$data = $this->flags_to_comment_data( $flags );

		$post_id = (int) ( $data['comment_post_ID'] ?? 0 );
		if ( $post_id <= 0 ) {
			return Response::error( 'Usage: comment create --comment_post_ID=<post-ID> --comment_content="…" [--comment_author=…] [--comment_author_email=…] [--comment_approved=0|1]' );
		}
		if ( ! get_post( $post_id ) ) {
			return Response::error( sprintf( 'Post %d not found — cannot attach a comment to it.', $post_id ) );
		}

		// Mirror WP-CLI: comments created by the tool are approved unless told otherwise.
		if ( ! array_key_exists( 'comment_approved', $data ) ) {
			$data['comment_approved'] = 1;
		}

		$comment_id = wp_insert_comment( wp_slash( $data ) );
		if ( ! $comment_id ) {
			return Response::error( 'wp_insert_comment failed (hook refused or invalid data).' );
		}
		return Response::success( array( 'comment_ID' => (int) $comment_id ) );
	}

	/**
	 * Handle "comment update".
	 *
	 * @param string[]            $positional Remaining positional args.
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_comment_update( array $positional, array $flags ) {
		$comment_id = isset( $positional[0] ) ? (int) $positional[0] : 0;
		if ( $comment_id <= 0 ) {
			return Response::error( 'Usage: comment update <ID> --field=value ...' );
		}
		if ( ! get_comment( $comment_id ) ) {
			return Response::error( sprintf( 'Comment %d not found.', $comment_id ) );
		}
		$data = $this->flags_to_comment_data( $flags );
		if ( empty( $data ) ) {
			return Response::error( 'No updatable fields supplied. Pass flags like --comment_content="…".' );
		}
		$data['comment_ID'] = $comment_id;

		$result = wp_update_comment( wp_slash( $data ), true );
		if ( is_wp_error( $result ) ) {
			return Response::error( $result->get_error_message() );
		}
		return Response::success(
			array(
				'comment_ID' => $comment_id,
				'updated'    => (bool) $result,
			)
		);
	}

	/**
	 * Handle "comment delete <ID> [<ID2> …] [--force]".
	 *
	 * Same partial-success semantics as `post delete`: report what landed
	 * and what failed; all-fail → error so the caller doesn't claim success
	 * on a no-op turn. Without --force the comment is trashed (WP default);
	 * with --force it is permanently deleted.
	 *
	 * @param string[]            $positional Remaining positional args (one or more comment IDs).
	 * @param array<string,mixed> $flags      Parsed flags (`--force` recognised).
	 * @return array<string,mixed>
	 */
	private function handle_comment_delete( array $positional, array $flags ): array {
		if ( empty( $positional ) ) {
			return Response::error( 'Usage: comment delete <ID> [<ID2> …] [--force]' );
		}
		$force = ! empty( $flags['force'] );

		$deleted = array();
		$failed  = array();

		foreach ( $positional as $arg ) {
			$comment_id = (int) $arg;
			if ( $comment_id <= 0 ) {
				$failed[] = array(
					'id'     => (string) $arg,
					'reason' => 'not a positive integer',
				);
				continue;
			}
			$result = wp_delete_comment( $comment_id, $force );
			if ( $result ) {
				$deleted[] = $comment_id;
			} else {
				$failed[] = array(
					'id'     => $comment_id,
					'reason' => 'wp_delete_comment returned false (comment not found, already trashed when --force omitted, or hook refused)',
				);
			}
		}

		if ( empty( $deleted ) ) {
			$ids     = array_map( static fn( $f ) => (string) $f['id'], $failed );
			$details = array_map( static fn( $f ) => $f['id'] . ' (' . $f['reason'] . ')', $failed );
			return Response::error(
				sprintf(
					'No comments deleted. Attempted IDs: %s. Failures: %s.',
					implode( ', ', $ids ),
					implode( '; ', $details )
				)
			);
		}

		return Response::success(
			array(
				'deleted' => $deleted,
				'failed'  => $failed,
				'forced'  => $force,
			)
		);
	}

	/**
	 * Handle "comment approve|unapprove|spam|unspam|trash|untrash <ID> [<ID2> …]".
	 *
	 * Same partial-success semantics as `comment delete`.
	 *
	 * @param string   $verb       Moderation verb.
	 * @param string[] $positional One or more comment IDs.
	 * @return array<string,mixed>
	 */
	private function handle_comment_set_status( string $verb, array $positional ): array {
		if ( empty( $positional ) ) {
			return Response::error( sprintf( 'Usage: comment %s <ID> [<ID2> …]', $verb ) );
		}

		$changed = array();
		$failed  = array();

		foreach ( $positional as $arg ) {
			$comment_id = (int) $arg;
			if ( $comment_id <= 0 ) {
				$failed[] = array(
					'id'     => (string) $arg,
					'reason' => 'not a positive integer',
				);
				continue;
			}
			if ( ! get_comment( $comment_id ) ) {
				$failed[] = array(
					'id'     => $comment_id,
					'reason' => 'comment not found',
				);
				continue;
			}

			switch ( $verb ) {
				case 'approve':
					$result = wp_set_comment_status( $comment_id, 'approve', true );
					break;
				case 'unapprove':
					$result = wp_set_comment_status( $comment_id, 'hold', true );
					break;
				case 'spam':
					$result = wp_spam_comment( $comment_id );
					break;
				case 'unspam':
					$result = wp_unspam_comment( $comment_id );
					break;
				case 'trash':
					$result = wp_trash_comment( $comment_id );
					break;
				case 'untrash':
					$result = wp_untrash_comment( $comment_id );
					break;
				default:
					return Response::error( sprintf( 'Unsupported comment moderation verb: "%s".', $verb ) );
			}

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'id'     => $comment_id,
					'reason' => $result->get_error_message(),
				);
			} elseif ( $result ) {
				$changed[] = $comment_id;
			} else {
				$failed[] = array(
					'id'     => $comment_id,
					'reason' => 'status change refused (already in target status, or hook refused)',
				);
			}
		}

		if ( empty( $changed ) ) {
			$details = array_map( static fn( $f ) => $f['id'] . ' (' . $f['reason'] . ')', $failed );
			return Response::error(
				sprintf(
					'No comments %sd. Failures: %s.',
					$verb,
					implode( '; ', $details )
				)
			);
		}

		return Response::success(
			array(
				'action'  => $verb,
				'changed' => $changed,
				'failed'  => $failed,
			)
		);
	}

	/**
	 * Handle "comment recount <post-ID> [<post-ID2> …]".
	 *
	 * Recalculates the cached comment_count on each post.
	 *
	 * @param string[] $positional One or more post IDs.
	 * @return array<string,mixed>
	 */
	private function handle_comment_recount( array $positional ): array {
		if ( empty( $positional ) ) {
			return Response::error( 'Usage: comment recount <post-ID> [<post-ID2> …]' );
		}

		$out = array();
		foreach ( $positional as $arg ) {
			$post_id = (int) $arg;
			$post    = $post_id > 0 ? get_post( $post_id ) : null;
			if ( ! $post ) {
				$out[] = array(
					'post_ID' => (string) $arg,
					'error'   => 'post not found',
				);
				continue;
			}
			wp_update_comment_count_now( $post_id );
			$out[] = array(
				'post_ID'       => $post_id,
				'comment_count' => (int) get_post( $post_id )->comment_count,
			);
		}
		return Response::success( $out );
	}

	/**
	 * Serialise a WP_Comment to a JSON-friendly array.
	 *
	 * Includes a derived `status` (approved/unapproved/spam/trash) so the
	 * agent never has to decode raw comment_approved values ('0'/'1'/'spam').
	 *
	 * @param \WP_Comment   $comment Comment.
	 * @param string[]|null $fields  Field subset; null for all.
	 * @return array<string,mixed>
	 */
	private function comment_to_array( \WP_Comment $comment, ?array $fields ): array {
		$all = array(
			'comment_ID'           => (int) $comment->comment_ID,
			'comment_post_ID'      => (int) $comment->comment_post_ID,
			'comment_author'       => $comment->comment_author,
			'comment_author_email' => $comment->comment_author_email,
			'comment_author_url'   => $comment->comment_author_url,
			'comment_date'         => $comment->comment_date,
			'comment_content'      => $comment->comment_content,
			'comment_type'         => $comment->comment_type,
			'comment_parent'       => (int) $comment->comment_parent,
			'comment_approved'     => $comment->comment_approved,
			'user_id'              => (int) $comment->user_id,
			'status'               => wp_get_comment_status( $comment ),
		);
		if ( null === $fields ) {
			return $all;
		}
		$out = array();
		foreach ( $fields as $f ) {
			$out[ $f ] = $all[ $f ] ?? null;
		}
		return $out;
	}

	/**
	 * Translate WP-CLI-style --flags into a wp_insert_comment/wp_update_comment array.
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array{comment_post_ID?:int,comment_content?:string,comment_author?:string,comment_author_email?:string,comment_author_url?:string,comment_approved?:string,comment_parent?:int,comment_type?:string,comment_date?:string,user_id?:int}
	 */
	private function flags_to_comment_data( array $flags ): array {
		$out = array();
		if ( array_key_exists( 'comment_post_ID', $flags ) ) {
			$out['comment_post_ID'] = Utils::to_int( $flags['comment_post_ID'] );
		}
		if ( array_key_exists( 'comment_content', $flags ) ) {
			$out['comment_content'] = Utils::to_str( $flags['comment_content'] );
		}
		if ( array_key_exists( 'comment_author', $flags ) ) {
			$out['comment_author'] = Utils::to_str( $flags['comment_author'] );
		}
		if ( array_key_exists( 'comment_author_email', $flags ) ) {
			$out['comment_author_email'] = Utils::to_str( $flags['comment_author_email'] );
		}
		if ( array_key_exists( 'comment_author_url', $flags ) ) {
			$out['comment_author_url'] = Utils::to_str( $flags['comment_author_url'] );
		}
		if ( array_key_exists( 'comment_approved', $flags ) ) {
			$out['comment_approved'] = Utils::to_str( $flags['comment_approved'] );
		}
		if ( array_key_exists( 'comment_parent', $flags ) ) {
			$out['comment_parent'] = Utils::to_int( $flags['comment_parent'] );
		}
		if ( array_key_exists( 'comment_type', $flags ) ) {
			$out['comment_type'] = Utils::to_str( $flags['comment_type'] );
		}
		if ( array_key_exists( 'comment_date', $flags ) ) {
			$out['comment_date'] = Utils::to_str( $flags['comment_date'] );
		}
		if ( array_key_exists( 'user_id', $flags ) ) {
			$out['user_id'] = Utils::to_int( $flags['user_id'] );
		}
		return $out;
	}

	// ─── Meta (post + user + comment) ───────────────────────────────────────────

	/**
	 * Route post-meta / user-meta / comment-meta subcommands.
	 *
	 * @param string              $object     "post", "user" or "comment".
	 * @param string[]            $positional Remaining positional args after "<object> meta".
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function route_meta( string $object, array $positional, array $flags ): array {
		unset( $flags );
		$verb = strtolower( $positional[0] ?? '' );
		$id   = isset( $positional[1] ) ? (int) $positional[1] : 0;

		// Writes are blocked in verify_command_security(); only reads reach here.
		$get_fns = array(
			'user'    => 'get_user_meta',
			'comment' => 'get_comment_meta',
		);
		$get_fn  = $get_fns[ $object ] ?? 'get_post_meta';

		if ( 'get' === $verb ) {
			$key = $positional[2] ?? '';
			if ( $id <= 0 || '' === $key ) {
				return Response::error( sprintf( 'Usage: %s meta get <id> <key>', $object ) );
			}
			return Response::success( array( 'value' => $get_fn( $id, $key, true ) ) );
		}

		if ( 'list' === $verb ) {
			if ( $id <= 0 ) {
				return Response::error( sprintf( 'Usage: %s meta list <id>', $object ) );
			}
			// Return all meta as { key: value } — single-element arrays are
			// unwrapped to the scalar so the LLM sees a clean key/value map.
			$all = $get_fn( $id );
			if ( ! is_array( $all ) ) {
				return Response::success( array() );
			}
			$out = array();
			foreach ( $all as $k => $v ) {
				if ( is_array( $v ) && 1 === count( $v ) ) {
					$first     = reset( $v );
					$out[ $k ] = is_string( $first ) ? maybe_unserialize( $first ) : $first;
				} else {
					$out[ $k ] = $v;
				}
			}
			return Response::success( $out );
		}

		// Anything else (add/update/set/delete/patch) reaches this only if the
		// security check failed to fire — defensive fallback with a clear
		// "not supported" rather than the misleading "writes are blocked".
		if ( in_array( $verb, array( 'add', 'update', 'set', 'delete', 'patch' ), true ) ) {
			return Response::error( sprintf( 'Security policy: %s meta writes are blocked. Use a dedicated ability for the specific meta key.', $object ) );
		}
		return Response::error( sprintf( 'Unsupported %s meta verb: "%s". Supported: get, list.', $object, $verb ) );
	}

	// ─── User handlers ──────────────────────────────────────────────────────────

	/**
	 * Handle "user list".
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_user_list( array $flags ): array {
		list( $per_page, $offset ) = $this->resolve_page_window( $flags, 'number', 50 );
		$all_rows                  = ( -1 === $per_page );
		$query_args                = array(
			'number'      => $all_rows ? -1 : $per_page,
			'offset'      => $all_rows ? 0 : $offset,
			'count_total' => true,
		);
		if ( ! empty( $flags['role'] ) ) {
			$query_args['role'] = Utils::to_str( $flags['role'] );
		}
		if ( ! empty( $flags['search'] ) ) {
			$query_args['search'] = '*' . Utils::to_str( $flags['search'] ) . '*';
		}

		// `--format=count`: total matching users, no rows fetched.
		if ( $this->wants_count( $flags ) ) {
			$count_query = new \WP_User_Query(
				array_merge(
					$query_args,
					array(
						'number' => 1,
						'offset' => 0,
						'fields' => 'ID',
					) 
				)
			);
			return Response::success( (int) $count_query->get_total() );
		}

		// `--format=ids`: flat list of user IDs.
		if ( $this->wants_ids( $flags ) ) {
			$id_query = new \WP_User_Query( array_merge( $query_args, array( 'fields' => 'ID' ) ) );
			$ids      = array_map( static fn ( $u ) => is_scalar( $u ) ? (int) $u : 0, $id_query->get_results() );
			return $this->paginated_response(
				$ids,
				(int) $id_query->get_total(),
				$all_rows ? max( 1, (int) $id_query->get_total() ) : $per_page,
				$all_rows ? 0 : $offset
			);
		}

		$query  = new \WP_User_Query( $query_args );
		$fields = $this->resolve_fields_flag( $flags, array( 'ID', 'user_login', 'user_email', 'display_name', 'roles' ) );

		$out = array();
		foreach ( $query->get_results() as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$out[] = $this->user_to_array( $user, $fields );
		}
		$total = (int) $query->get_total();
		return $this->paginated_response( $out, $total, $all_rows ? max( 1, $total ) : $per_page, $all_rows ? 0 : $offset );
	}

	/**
	 * Handle "user get".
	 *
	 * @param string[]            $positional Remaining positional args.
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_user_get( array $positional, array $flags ) {
		$ident = $positional[0] ?? '';
		if ( '' === $ident ) {
			return Response::error( 'Usage: user get <id-or-login>' );
		}
		$user = is_numeric( $ident ) ? get_user_by( 'id', (int) $ident ) : get_user_by( 'login', $ident );
		if ( ! $user ) {
			return Response::error( sprintf( 'User "%s" not found.', $ident ) );
		}
		$fields = $this->resolve_fields_flag( $flags, null );
		return Response::success( $this->user_to_array( $user, $fields ) );
	}

	/**
	 * Handle `user add-role|remove-role|set-role <id-or-login> <role>`.
	 *
	 * Admin/super-admin roles are refused in `verify_command_security()`;
	 * by the time we reach here the role is editor/author/contributor/
	 * subscriber/custom-non-admin.
	 *
	 * @param string   $action     'add' | 'remove' | 'set'.
	 * @param string[] $positional Remaining tokens — [id-or-login, role].
	 * @return array<string,mixed>
	 */
	private function handle_user_role_change( string $action, array $positional ): array {
		$ident = $positional[0] ?? '';
		$role  = strtolower( $positional[1] ?? '' );
		if ( '' === $ident || '' === $role ) {
			return Response::error( sprintf( 'Usage: user %s-role <id-or-login> <role>', $action ) );
		}
		// Defense in depth — the security verifier already blocks
		// administrator/super-admin, but a future caller (or a verifier
		// regression) shouldn't be able to elevate a user here.
		if ( in_array( $role, array( 'administrator', 'super-admin' ), true ) ) {
			return Response::error( sprintf( 'Security policy: role "%s" cannot be changed via this endpoint.', $role ) );
		}
		$user = is_numeric( $ident ) ? get_user_by( 'id', (int) $ident ) : get_user_by( 'login', $ident );
		if ( ! $user ) {
			return Response::error( sprintf( 'User "%s" not found.', $ident ) );
		}
		// Validate the role exists.
		if ( ! get_role( $role ) ) {
			return Response::error( sprintf( 'Role "%s" is not registered. Available roles: %s.', $role, implode( ', ', array_keys( wp_roles()->roles ) ) ) );
		}

		switch ( $action ) {
			case 'add':
				$user->add_role( $role );
				break;
			case 'remove':
				$user->remove_role( $role );
				break;
			case 'set':
				$user->set_role( $role );
				break;
			default:
				return Response::error( sprintf( 'Unsupported role action: "%s".', $action ) );
		}

		// Refresh user object for the response.
		$refreshed = get_user_by( 'id', $user->ID );
		return Response::success(
			array(
				'ID'     => (int) $user->ID,
				'login'  => $user->user_login,
				'roles'  => $refreshed instanceof \WP_User ? array_values( $refreshed->roles ) : array(),
				'action' => $action,
				'role'   => $role,
			)
		);
	}

	/**
	 * Handle `user add-cap|remove-cap <id-or-login> <capability>`.
	 *
	 * Admin-class capabilities are refused in `verify_command_security()`.
	 *
	 * @param string   $action     'add' | 'remove'.
	 * @param string[] $positional [id-or-login, capability].
	 * @return array<string,mixed>
	 */
	private function handle_user_cap_change( string $action, array $positional ): array {
		$ident = $positional[0] ?? '';
		$cap   = strtolower( $positional[1] ?? '' );
		if ( '' === $ident || '' === $cap ) {
			return Response::error( sprintf( 'Usage: user %s-cap <id-or-login> <capability>', $action ) );
		}
		$user = is_numeric( $ident ) ? get_user_by( 'id', (int) $ident ) : get_user_by( 'login', $ident );
		if ( ! $user ) {
			return Response::error( sprintf( 'User "%s" not found.', $ident ) );
		}

		if ( 'add' === $action ) {
			$user->add_cap( $cap );
		} elseif ( 'remove' === $action ) {
			$user->remove_cap( $cap );
		} else {
			return Response::error( sprintf( 'Unsupported cap action: "%s".', $action ) );
		}

		return Response::success(
			array(
				'ID'         => (int) $user->ID,
				'login'      => $user->user_login,
				'capability' => $cap,
				'action'     => $action,
				'has_now'    => user_can( $user->ID, $cap ),
			)
		);
	}

	// ─── Menu handlers ──────────────────────────────────────────────────────────

	/**
	 * Handle "menu list".
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_menu_list( array $flags ): array {
		unset( $flags );
		$menus = wp_get_nav_menus();
		$out   = array();
		foreach ( $menus as $m ) {
			$out[] = array(
				'term_id' => (int) $m->term_id,
				'name'    => $m->name,
				'slug'    => $m->slug,
				'count'   => (int) $m->count,
			);
		}
		return Response::success( $out );
	}

	/**
	 * Handle "menu create".
	 *
	 * @param string[] $positional Remaining positional args.
	 * @return array<string,mixed>
	 */
	private function handle_menu_create( array $positional ): array {
		$name = $positional[0] ?? '';
		if ( '' === $name ) {
			return Response::error( 'Usage: menu create <name>' );
		}
		$id = wp_create_nav_menu( $name );
		if ( is_wp_error( $id ) ) {
			return Response::error( $id->get_error_message() );
		}
		return Response::success( array( 'term_id' => (int) $id ) );
	}

	/**
	 * Route "menu item ..." subcommands.
	 *
	 * @param string[]            $positional Remaining positional args after "menu item".
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function route_menu_item( array $positional, array $flags ): array {
		$verb = strtolower( $positional[0] ?? '' );

		switch ( $verb ) {
			case 'list':
				$menu = $positional[1] ?? '';
				if ( '' === $menu ) {
					return Response::error( 'Usage: menu item list <menu>' );
				}
				$items = wp_get_nav_menu_items( $menu );
				if ( false === $items ) {
					return Response::error( sprintf( 'Menu "%s" not found.', $menu ) );
				}
				$out = array();
				foreach ( $items as $item ) {
					if ( ! is_object( $item ) ) {
						continue;
					}
					/**
					 * Narrowed type for `$item`.
					 *
					 * @var object{db_id:int,type:string,object:string,object_id:int,title:string,url:string,menu_item_parent:int} $item
					 */
					$out[] = array(
						'db_id'     => Utils::to_int( $item->db_id ),
						'type'      => $item->type,
						'object'    => $item->object,
						'object_id' => Utils::to_int( $item->object_id ),
						'title'     => $item->title,
						'url'       => $item->url,
						'parent'    => Utils::to_int( $item->menu_item_parent ),
					);
				}
				return Response::success( $out );

			case 'add-post':
			case 'add_post':
				$menu_id = isset( $positional[1] ) ? (int) $positional[1] : 0;
				$post_id = isset( $positional[2] ) ? (int) $positional[2] : 0;
				if ( $menu_id <= 0 || $post_id <= 0 ) {
					return Response::error( 'Usage: menu item add-post <menu-id> <post-id>' );
				}
				$post = get_post( $post_id );
				if ( ! $post ) {
					return Response::error( sprintf( 'Post %d not found.', $post_id ) );
				}
				$item_id = wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => $post->post_title,
						'menu-item-object'    => $post->post_type,
						'menu-item-object-id' => $post_id,
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
						'menu-item-parent-id' => isset( $flags['parent-id'] ) ? Utils::to_int( $flags['parent-id'] ) : 0,
					)
				);
				if ( is_wp_error( $item_id ) ) {
					return Response::error( $item_id->get_error_message() );
				}
				return Response::success( array( 'menu_item_id' => (int) $item_id ) );
		}

		return Response::error( sprintf( 'Unknown "menu item" subcommand: "%s"', $verb ) );
	}

	// ─── Sidebar / widget / cron handlers ───────────────────────────────────────

	/**
	 * Handle "sidebar list".
	 *
	 * @return array<string,mixed>
	 */
	private function handle_sidebar_list(): array {
		global $wp_registered_sidebars;
		$out = array();
		foreach ( (array) $wp_registered_sidebars as $id => $sidebar ) {
			if ( ! is_array( $sidebar ) ) {
				continue;
			}
			$out[] = array(
				'id'          => $id,
				'name'        => $sidebar['name'] ?? '',
				'description' => $sidebar['description'] ?? '',
			);
		}
		return Response::success( $out );
	}

	/**
	 * Handle "widget list <sidebar-id>".
	 *
	 * @param string[] $positional Remaining positional args.
	 * @return array<string,mixed>
	 */
	private function handle_widget_list( array $positional ): array {
		$sidebar_id = $positional[0] ?? '';
		if ( '' === $sidebar_id ) {
			return Response::error( 'Usage: widget list <sidebar-id>' );
		}
		// `wp_get_sidebars_widgets()` is internal/private in WP core and is
		// blocked by WPCS. Read the `sidebars_widgets` option directly — it
		// carries the same sidebar→widgets map.
		$map = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$widgets = isset( $map[ $sidebar_id ] ) && is_array( $map[ $sidebar_id ] ) ? $map[ $sidebar_id ] : array();
		return Response::success( array_values( $widgets ) );
	}

	/**
	 * Handle "cron event list".
	 *
	 * @return array<string,mixed>
	 */
	private function handle_cron_event_list(): array {
		$crons = _get_cron_array();
		$out   = array();
		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $dings ) {
				foreach ( (array) $dings as $sig => $data ) {
					if ( ! is_array( $data ) ) {
						continue;
					}
					$out[] = array(
						'hook'       => $hook,
						'next_run'   => (int) $timestamp,
						'schedule'   => $data['schedule'] ?? false,
						'interval'   => $data['interval'] ?? null,
						'sig'        => $sig,
						'args_count' => is_array( $data['args'] ?? null ) ? count( $data['args'] ) : 0,
					);
				}
			}
		}
		return Response::success( $out );
	}

	// ─── Term handlers ──────────────────────────────────────────────────────────

	/**
	 * Handle "term list <taxonomy>".
	 *
	 * @param string[]            $positional Remaining positional args (taxonomy at [0]).
	 * @param array<string,mixed> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_term_list( array $positional, array $flags ): array {
		$taxonomy = Utils::to_str( $positional[0] ?? $flags['taxonomy'] ?? '' );
		if ( '' === $taxonomy ) {
			return Response::error( 'Usage: term list <taxonomy> [--number=…] [--hide_empty=true|false]' );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return Response::error( sprintf( 'Taxonomy "%s" is not registered.', $taxonomy ) );
		}

		$hide_empty = isset( $flags['hide_empty'] )
			? filter_var( $flags['hide_empty'], FILTER_VALIDATE_BOOLEAN )
			: false;

		$filter_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => $hide_empty,
		);
		if ( isset( $flags['parent'] ) ) {
			$filter_args['parent'] = Utils::to_int( $flags['parent'] );
		}

		// Total matching terms across every page — used for `--format=count`
		// and the pagination envelope.
		$count = wp_count_terms( $filter_args );
		$total = is_wp_error( $count ) ? 0 : (int) $count;
		if ( $this->wants_count( $flags ) ) {
			return Response::success( $total );
		}

		// Terms return ALL matches unless a page size is given (mirrors
		// `wp term list`); `--per_page=-1` is the explicit "all" idiom. When a
		// positive size is given, page deterministically. per_page === 0 means
		// "no limit" for get_terms.
		$size_raw = $flags['number'] ?? $flags['per_page'] ?? null;
		if ( null === $size_raw || Utils::to_int( $size_raw ) < 0 ) {
			$per_page = 0;
		} else {
			$per_page = max( 1, min( Utils::to_int( $size_raw ), 1000 ) );
		}
		if ( isset( $flags['offset'] ) ) {
			$offset = max( 0, Utils::to_int( $flags['offset'] ) );
		} elseif ( $per_page && isset( $flags['page'] ) ) {
			$offset = ( max( 1, Utils::to_int( $flags['page'] ) ) - 1 ) * $per_page;
		} else {
			$offset = 0;
		}

		$query_args = $filter_args;
		if ( $per_page ) {
			$query_args['number'] = $per_page;
			$query_args['offset'] = $offset;
		}

		// `--format=ids`: flat list of term IDs.
		if ( $this->wants_ids( $flags ) ) {
			$ids = get_terms( array_merge( $query_args, array( 'fields' => 'ids' ) ) );
			if ( is_wp_error( $ids ) ) {
				return Response::error( $ids->get_error_message() );
			}
			$ids = array_map( 'intval', $ids );
			return $this->paginated_response( $ids, $total, $per_page ? $per_page : max( 1, count( $ids ) ), $offset );
		}

		$terms = get_terms( $query_args );
		if ( is_wp_error( $terms ) ) {
			return Response::error( $terms->get_error_message() );
		}

		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'term_id'     => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'count'       => (int) $term->count,
				'taxonomy'    => $term->taxonomy,
				'description' => $term->description,
				'parent'      => (int) $term->parent,
			);
		}
		return $this->paginated_response( $out, $total, $per_page ? $per_page : max( 1, count( $out ) ), $offset );
	}

	// ─── Schema discovery handlers (post-type / taxonomy list) ──────────────────

	/**
	 * Handle `post-type list [--public=true|false] [--hierarchical=true|false]
	 *                       [--show_in_menu=…] [--show_in_rest=…] [--format=…]`.
	 *
	 * Mirrors WP-CLI's `wp post-type list` — returns one row per registered
	 * post type with the fields the LLM most commonly needs to decide
	 * downstream operations (which post type to query, whether it's REST-
	 * exposed, what its rest_base is for /wp/v2/<base>).
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_post_type_list( array $flags ): array {
		$query        = array();
		$bool_filters = array( 'public', 'hierarchical', 'show_in_menu', 'show_in_rest', 'show_ui', 'has_archive' );
		foreach ( $bool_filters as $key ) {
			if ( isset( $flags[ $key ] ) ) {
				$query[ $key ] = filter_var( $flags[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		$types = get_post_types( $query, 'objects' );

		$out = array();
		foreach ( $types as $type ) {
			/**
			 * Narrowed type for `$cap_type`.
			 *
			 * @var string|array<int,string> $cap_type
			 */
			$cap_type = $type->capability_type;
			$out[]    = array(
				'name'            => $type->name,
				'label'           => $type->label,
				'description'     => $type->description,
				'public'          => (bool) $type->public,
				'hierarchical'    => (bool) $type->hierarchical,
				'show_in_menu'    => is_bool( $type->show_in_menu ) ? $type->show_in_menu : (bool) $type->show_in_menu,
				'show_in_rest'    => (bool) $type->show_in_rest,
				'rest_base'       => $type->rest_base ? $type->rest_base : $type->name,
				'has_archive'     => is_bool( $type->has_archive ) ? $type->has_archive : (bool) $type->has_archive,
				'capability_type' => is_array( $cap_type ) ? implode( ',', $cap_type ) : (string) $cap_type,
				'_builtin'        => (bool) $type->_builtin,
			);
		}
		return Response::success( $out );
	}

	/**
	 * Handle `taxonomy list [--public=true|false] [--hierarchical=…]
	 *                      [--object_type=<post_type>] [--format=…]`.
	 *
	 * Mirrors WP-CLI's `wp taxonomy list`.
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_taxonomy_list( array $flags ): array {
		$query        = array();
		$bool_filters = array( 'public', 'hierarchical', 'show_in_menu', 'show_in_rest', 'show_ui' );
		foreach ( $bool_filters as $key ) {
			if ( isset( $flags[ $key ] ) ) {
				$query[ $key ] = filter_var( $flags[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		$object_type_filter = isset( $flags['object_type'] ) ? Utils::to_str( $flags['object_type'] ) : '';

		$taxonomies = get_taxonomies( $query, 'objects' );

		$out = array();
		foreach ( $taxonomies as $tax ) {
			// --object_type filters down to taxonomies attached to that post type.
			if ( '' !== $object_type_filter && ! in_array( $object_type_filter, $tax->object_type, true ) ) {
				continue;
			}
			$out[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'description'  => $tax->description,
				'public'       => (bool) $tax->public,
				'hierarchical' => (bool) $tax->hierarchical,
				'object_type'  => array_values( $tax->object_type ),
				'show_in_rest' => (bool) $tax->show_in_rest,
				'rest_base'    => $tax->rest_base ? $tax->rest_base : $tax->name,
				'_builtin'     => (bool) $tax->_builtin,
			);
		}
		return Response::success( $out );
	}

	// ─── Diagnostics: role / db / rewrite / cron / env ──────────────────────────

	/**
	 * Handle `role list [--fields=role,name,capabilities] [--format=json]`.
	 *
	 * Mirrors `wp role list`. Returns one row per registered role with a
	 * count of granted capabilities and a `builtin` flag for the WP core
	 * roles. Use `role list-caps <role>` for the full cap set.
	 *
	 * @param array<string,mixed> $flags Parsed flags (unused except format passthrough).
	 * @return array<string,mixed>
	 */
	private function handle_role_list( array $flags ): array {
		unset( $flags );
		$roles_obj = wp_roles();
		$builtin   = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
		$out       = array();
		foreach ( $roles_obj->roles as $slug => $info ) {
			$caps_array = isset( $info['capabilities'] ) && is_array( $info['capabilities'] ) ? $info['capabilities'] : array();
			// Only count caps explicitly granted (true). WordPress stores
			// removed caps as `false`, which `array_filter` strips out.
			$granted = array_filter( $caps_array );
			$out[]   = array(
				'role'         => $slug,
				'name'         => isset( $info['name'] ) ? translate_user_role( Utils::to_str( $info['name'] ) ) : $slug,
				'capabilities' => count( $granted ),
				'builtin'      => in_array( $slug, $builtin, true ),
			);
		}
		return Response::success( $out );
	}

	/**
	 * Handle `role list-caps <role>`.
	 *
	 * Returns the capability set explicitly granted to a role. Capabilities
	 * stored as `false` (explicit removals) are omitted from the list to
	 * match WP-CLI's `wp role list-caps` behaviour.
	 *
	 * @param string[] $positional Remaining tokens after `role list-caps`.
	 * @return array<string,mixed>
	 */
	private function handle_role_list_caps( array $positional ): array {
		$slug = strtolower( $positional[0] ?? '' );
		if ( '' === $slug ) {
			return Response::error( 'Usage: role list-caps <role>' );
		}
		$role = get_role( $slug );
		if ( ! $role instanceof \WP_Role ) {
			return Response::error( sprintf( 'Role "%s" is not registered. Use "role list" to see available roles.', $slug ) );
		}
		$granted = array_keys( array_filter( $role->capabilities ) );
		sort( $granted );
		return Response::success(
			array(
				'role'         => $slug,
				'capabilities' => $granted,
				'count'        => count( $granted ),
			)
		);
	}

	/**
	 * Handle `db size [--tables] [--human-readable]`.
	 *
	 * Read-only summary built from SHOW TABLE STATUS. Honors the wpdb
	 * prefix so multisite blogs only count their own tables when invoked
	 * inside a subsite context. Returns total size + optional per-table
	 * breakdown when --tables is set.
	 *
	 * No arbitrary SQL is exposed; the verifier's `db query/import/...`
	 * block remains in effect. This is a bounded read.
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_db_size( array $flags ): array {
		global $wpdb;
		/**
		 * Narrowed type for `$wpdb`.
		 *
		 * @var \wpdb $wpdb
		 */
		$show_tables = ! empty( $flags['tables'] );
		$human       = ! empty( $flags['human-readable'] ) || ! empty( $flags['human_readable'] );
		$prefix_like = $wpdb->esc_like( $wpdb->prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $prefix_like ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return Response::error( 'Failed to read table status from MySQL.' );
		}

		$total      = 0;
		$tables_out = array();
		foreach ( $rows as $row ) {
			$data_len  = isset( $row['Data_length'] ) ? Utils::to_int( $row['Data_length'] ) : 0;
			$index_len = isset( $row['Index_length'] ) ? Utils::to_int( $row['Index_length'] ) : 0;
			$size      = $data_len + $index_len;
			$total    += $size;
			if ( $show_tables ) {
				$entry = array(
					'name'  => $row['Name'] ?? '',
					'rows'  => isset( $row['Rows'] ) ? Utils::to_int( $row['Rows'] ) : null,
					'bytes' => $size,
				);
				if ( $human ) {
					$entry['size'] = size_format( $size, 2 );
				}
				$tables_out[] = $entry;
			}
		}

		if ( $show_tables ) {
			// Sort descending by bytes — largest tables first, which is
			// what someone debugging size issues actually wants to see.
			usort(
				$tables_out,
				static function ( $a, $b ) {
					return $b['bytes'] - $a['bytes'];
				} 
			);
		}

		$payload = array(
			'total_bytes' => $total,
		);
		if ( $human ) {
			$payload['total_size'] = size_format( $total, 2 );
		}
		if ( $show_tables ) {
			$payload['tables'] = $tables_out;
		}
		return Response::success( $payload );
	}

	/**
	 * Handle `rewrite list`.
	 *
	 * Returns the cached rewrite_rules option as an array of
	 * { match, query } entries. Useful for debugging "why is /foo/ returning
	 * 404?" or "what URL pattern resolves to which post/term?".
	 *
	 * @return array<string,mixed>
	 */
	private function handle_rewrite_list(): array {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return Response::success(
				array(
					'count' => 0,
					'rules' => array(),
					'note'  => 'No rewrite rules cached. Try `rewrite flush` to regenerate them.',
				)
			);
		}
		$out = array();
		foreach ( $rules as $match => $query ) {
			$out[] = array(
				'match' => (string) $match,
				'query' => Utils::to_str( $query ),
			);
		}
		return Response::success(
			array(
				'count' => count( $out ),
				'rules' => $out,
			)
		);
	}

	/**
	 * Handle `cron schedule list`.
	 *
	 * Returns every registered cron interval (`hourly`, `daily`, `twicedaily`,
	 * `weekly`, plus any custom schedules registered via the
	 * `cron_schedules` filter). Used when scheduling a new cron event needs
	 * to pick a valid recurrence.
	 *
	 * @return array<string,mixed>
	 */
	private function handle_cron_schedule_list(): array {
		$schedules = wp_get_schedules();
		$out       = array();
		foreach ( $schedules as $slug => $info ) {
			$out[] = array(
				'name'     => (string) $slug,
				'display'  => (string) $info['display'],
				'interval' => (int) $info['interval'],
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['interval'] - $b['interval'];
			} 
		);
		return Response::success( $out );
	}

	/**
	 * Handle `env`.
	 *
	 * Synthesised environment / diagnostic report covering the questions
	 * users ask most often during troubleshooting: which PHP / MySQL /
	 * WordPress are running, where is the install, multisite status,
	 * debug flags, file-mod gates, memory limits, active locale.
	 *
	 * Read-only. Does NOT expose secrets (DB password, auth keys, etc.).
	 *
	 * @return array<string,mixed>
	 */
	private function handle_env(): array {
		global $wpdb;
		/**
		 * Narrowed type for `$wpdb`.
		 *
		 * @var \wpdb $wpdb
		 */
		$mysql_version = '';
		if ( isset( $wpdb->dbh ) ) {
			// $wpdb exposes a public helper since WP 5.1.
			$mysql_version = (string) $wpdb->db_version();
		}

		return Response::success(
			array(
				'wp'    => array(
					'version'            => get_bloginfo( 'version' ),
					'site_url'           => get_option( 'siteurl' ),
					'home_url'           => get_option( 'home' ),
					'abspath'            => defined( 'ABSPATH' ) ? ABSPATH : '',
					'language'           => get_locale(),
					'multisite'          => is_multisite(),
					'debug'              => defined( 'WP_DEBUG' ) && WP_DEBUG,
					'debug_log'          => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
					'script_debug'       => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
					'disallow_file_mods' => ! wp_is_file_mod_allowed( 'zipai_env_report' ),
					'disallow_file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
					'fs_method'          => defined( 'FS_METHOD' ) ? Utils::to_str( constant( 'FS_METHOD' ) ) : '',
					'memory_limit'       => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
					'max_memory'         => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '',
					'table_prefix'       => $wpdb->prefix,
				),
				'php'   => array(
					'version'             => PHP_VERSION,
					'sapi'                => PHP_SAPI,
					'memory_limit'        => (string) ini_get( 'memory_limit' ),
					'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
					'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
					'post_max_size'       => (string) ini_get( 'post_max_size' ),
					'extensions'          => array(
						'curl'     => extension_loaded( 'curl' ),
						'gd'       => extension_loaded( 'gd' ),
						'imagick'  => extension_loaded( 'imagick' ),
						'mbstring' => extension_loaded( 'mbstring' ),
						'mysqli'   => extension_loaded( 'mysqli' ),
						'openssl'  => extension_loaded( 'openssl' ),
						'sodium'   => extension_loaded( 'sodium' ),
						'zip'      => extension_loaded( 'zip' ),
					),
				),
				'mysql' => array(
					'version' => $mysql_version,
				),
				'theme' => array(
					'stylesheet' => Utils::to_str( get_option( 'stylesheet' ) ),
					'template'   => Utils::to_str( get_option( 'template' ) ),
				),
			)
		);
	}

	/**
	 * Handle `core check-update [--format=json]`.
	 *
	 * Reports core updates WordPress already knows about, read from the
	 * cached `update_core` site transient that core's own scheduled
	 * `wp_version_check()` populates. It does NOT force a live wp.org
	 * request: a web/REST request must stay fast and cannot block on an
	 * outbound call, and WordPress's own Site Health screen reads the
	 * same cached data. Returns an empty `updates` list when the install
	 * is up to date (or when the cache hasn't been primed yet).
	 *
	 * Mirrors the useful half of `wp core check-update`.
	 *
	 * @return array<string,mixed>
	 */
	private function handle_core_check_update(): array {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$current = get_bloginfo( 'version' );
		$updates = function_exists( 'get_core_updates' ) ? get_core_updates() : array();
		if ( ! is_array( $updates ) ) {
			$updates = array();
		}

		$available = array();
		foreach ( $updates as $update ) {
			if ( ! is_object( $update ) ) {
				continue;
			}
			// `response === 'upgrade'` is the only state that offers a newer
			// core build; 'latest' / 'development' rows are not actionable.
			if ( ! isset( $update->response ) || 'upgrade' !== $update->response ) {
				continue;
			}
			$offered = isset( $update->current ) ? Utils::to_str( $update->current ) : '';
			if ( '' !== $offered && version_compare( $offered, $current, '<=' ) ) {
				continue;
			}
			$available[] = array(
				'version'       => $offered,
				'locale'        => isset( $update->locale ) ? Utils::to_str( $update->locale, 'en_US' ) : 'en_US',
				'package'       => ( isset( $update->packages ) && is_object( $update->packages ) && isset( $update->packages->full ) ) ? Utils::to_str( $update->packages->full ) : '',
				'php_version'   => isset( $update->php_version ) ? Utils::to_str( $update->php_version ) : '',
				'mysql_version' => isset( $update->mysql_version ) ? Utils::to_str( $update->mysql_version ) : '',
			);
		}

		return Response::success(
			array(
				'current_version'  => $current,
				'update_available' => ! empty( $available ),
				'updates'          => $available,
				'note'             => empty( $updates )
					? 'Core update cache is empty — WordPress has not run its scheduled version check yet. "update_available: false" here means "no known update", not a guaranteed up-to-date result.'
					: '',
			)
		);
	}

	/**
	 * Handle `cli info`.
	 *
	 * There is no WP-CLI binary in web/REST context, so report the PHP and
	 * WordPress runtime the request is actually executing under — the half
	 * of `wp cli info` that is meaningful here. Keeps callers that probe
	 * `cli info` for runtime facts (e.g. a Site Health snapshot) working
	 * instead of erroring out on the default "not available" branch.
	 *
	 * @return array<string,mixed>
	 */
	private function handle_cli_info(): array {
		return Response::success(
			array(
				'php_binary'  => defined( 'PHP_BINARY' ) ? PHP_BINARY : '',
				'php_version' => PHP_VERSION,
				'php_sapi'    => PHP_SAPI,
				'wp_version'  => get_bloginfo( 'version' ),
				'wp_cli'      => false,
				'context'     => 'web-rest',
				'note'        => 'Running in web/REST context — no WP-CLI runtime. Reported values reflect the PHP/WordPress process serving this request.',
			)
		);
	}

	// ─── Search-replace handler ─────────────────────────────────────────────────
	// `handle_search_replace()` and its serialized-PHP walker live in
	// Search_Replace_Engine_Trait (search-replace-engine-trait.php).

	// ─── Shared helpers ─────────────────────────────────────────────────────────

	/**
	 * Split parsed args into positional tokens and --flag map.
	 *
	 * Supports `--key=value`, `--key value`, and boolean `--key`.
	 *
	 * @param string[] $args Parsed command tokens.
	 * @return array{0:string[],1:array<string,bool|string>}
	 */
	private function parse_flags( array $args ): array {
		$positional  = array();
		$flags       = array();
		$pending_key = null;
		$value_flags = array(
			'status',
			'format',
			'fields',
			'post_type',
			'post_status',
			'posts_per_page',
			'per_page',
			'page',
			'offset',
			'orderby',
			'order',
			's',
			'search',
			'number',
			'role',
			'hide_empty',
			'expiration',
			'autoload',
			'reassign',
			'parent-id',
			'post_title',
			'post_content',
			'post_name',
			'post_excerpt',
			'post_author',
			'menu_order',
			'slug',
			'parent',
			'description',
			'name',
			'user_login',
			'user_email',
			'user_pass',
			'first_name',
			'last_name',
			'display_name',
			'limit',
			'skip-columns',
			'include-columns',
			'taxonomy',
			// comment list filters + create/update fields.
			'post_id',
			'type',
			'comment_post_ID',
			'comment_content',
			'comment_author',
			'comment_author_email',
			'comment_author_url',
			'comment_approved',
			'comment_parent',
			'comment_type',
			'comment_date',
			'user_id',
			// post-type / taxonomy schema-discovery filters.
			'public',
			'hierarchical',
			'show_in_menu',
			'show_in_rest',
			'show_ui',
			'has_archive',
			'object_type',
		);

		foreach ( $args as $arg ) {
			if ( null !== $pending_key ) {
				$flags[ $pending_key ] = $arg;
				$pending_key           = null;
				continue;
			}
			if ( str_starts_with( $arg, '--' ) ) {
				$body = substr( $arg, 2 );
				if ( str_contains( $body, '=' ) ) {
					list( $k, $v ) = explode( '=', $body, 2 );
					$flags[ $k ]   = $v;
				} elseif ( in_array( $body, $value_flags, true ) ) {
					// Value follows in the next token.
					$pending_key = $body;
				} else {
					$flags[ $body ] = true;
				}
				continue;
			}
			$positional[] = $arg;
		}

		if ( null !== $pending_key ) {
			// Trailing `--key` with no value — treat as boolean true.
			$flags[ $pending_key ] = true;
		}

		return array( $positional, $flags );
	}

	/**
	 * Normalise the --fields flag.
	 *
	 * @param array<string,mixed> $flags   Parsed flags.
	 * @param string[]|null       $default Default field set, or null for "all".
	 * @return string[]|null
	 */
	private function resolve_fields_flag( array $flags, ?array $default ): ?array {
		if ( empty( $flags['fields'] ) ) {
			return $default;
		}
		$raw = is_array( $flags['fields'] ) ? $flags['fields'] : explode( ',', Utils::to_str( $flags['fields'] ) );
		return array_values( array_filter( array_map( static fn ( $v ) => trim( is_scalar( $v ) ? (string) $v : '' ), $raw ), static fn ( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Serialise a WP_Post to a JSON-friendly array.
	 *
	 * @param \WP_Post      $post   Post.
	 * @param string[]|null $fields Field subset; null for all.
	 * @return array<string,mixed>
	 */
	private function post_to_array( \WP_Post $post, ?array $fields ): array {
		$all = array(
			'ID'             => (int) $post->ID,
			'post_title'     => $post->post_title,
			'post_status'    => $post->post_status,
			'post_type'      => $post->post_type,
			'post_date'      => $post->post_date,
			'post_modified'  => $post->post_modified,
			'post_author'    => (int) $post->post_author,
			'post_parent'    => (int) $post->post_parent,
			'post_name'      => $post->post_name,
			'post_excerpt'   => $post->post_excerpt,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'guid'           => $post->guid,
		);
		if ( null === $fields ) {
			return $all;
		}
		$out = array();
		foreach ( $fields as $f ) {
			$out[ $f ] = $all[ $f ] ?? null;
		}
		return $out;
	}

	/**
	 * Serialise a WP_User to a JSON-friendly array.
	 *
	 * @param \WP_User      $user   User.
	 * @param string[]|null $fields Field subset; null for all safe fields.
	 * @return array<string,mixed>
	 */
	private function user_to_array( \WP_User $user, ?array $fields ): array {
		$all = array(
			'ID'              => (int) $user->ID,
			'user_login'      => $user->user_login,
			'user_email'      => $user->user_email,
			'user_nicename'   => $user->user_nicename,
			'display_name'    => $user->display_name,
			'user_registered' => $user->user_registered,
			'roles'           => $user->roles,
		);
		if ( null === $fields ) {
			return $all;
		}
		$out = array();
		foreach ( $fields as $f ) {
			$out[ $f ] = $all[ $f ] ?? null;
		}
		return $out;
	}

	/**
	 * Translate WP-CLI-style --flags into a wp_insert_post/wp_update_post array.
	 * Skips --post_content (blocked by `verify_command_security`).
	 *
	 * @param array<string,mixed> $flags Parsed flags.
	 * @return array{post_title?:string,post_status?:string,post_type?:string,post_name?:string,post_excerpt?:string,post_author?:int,post_parent?:int,menu_order?:int,comment_status?:string,ping_status?:string,post_date?:string,post_password?:string}
	 */
	private function flags_to_post_data( array $flags ): array {
		$out = array();
		if ( array_key_exists( 'post_title', $flags ) ) {
			$out['post_title'] = Utils::to_str( $flags['post_title'] );
		}
		if ( array_key_exists( 'post_status', $flags ) ) {
			$out['post_status'] = Utils::to_str( $flags['post_status'] );
		}
		if ( array_key_exists( 'post_type', $flags ) ) {
			$out['post_type'] = Utils::to_str( $flags['post_type'] );
		}
		if ( array_key_exists( 'post_name', $flags ) ) {
			$out['post_name'] = Utils::to_str( $flags['post_name'] );
		}
		if ( array_key_exists( 'post_excerpt', $flags ) ) {
			$out['post_excerpt'] = Utils::to_str( $flags['post_excerpt'] );
		}
		if ( array_key_exists( 'post_author', $flags ) ) {
			$out['post_author'] = Utils::to_int( $flags['post_author'] );
		}
		if ( array_key_exists( 'post_parent', $flags ) ) {
			$out['post_parent'] = Utils::to_int( $flags['post_parent'] );
		}
		if ( array_key_exists( 'menu_order', $flags ) ) {
			$out['menu_order'] = Utils::to_int( $flags['menu_order'] );
		}
		if ( array_key_exists( 'comment_status', $flags ) ) {
			$out['comment_status'] = Utils::to_str( $flags['comment_status'] );
		}
		if ( array_key_exists( 'ping_status', $flags ) ) {
			$out['ping_status'] = Utils::to_str( $flags['ping_status'] );
		}
		if ( array_key_exists( 'post_date', $flags ) ) {
			$out['post_date'] = Utils::to_str( $flags['post_date'] );
		}
		if ( array_key_exists( 'post_password', $flags ) ) {
			$out['post_password'] = Utils::to_str( $flags['post_password'] );
		}
		return $out;
	}

	// ─── Command parsing ────────────────────────────────────────────────────────
	// `parse_command_to_args()` and `parse_output()` live in
	// Command_Parser_Trait (command-parser-trait.php).
}
