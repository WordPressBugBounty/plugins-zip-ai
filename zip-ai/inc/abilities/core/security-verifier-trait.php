<?php
/**
 * Security Verifier Trait — a denylist. It blocks commands that violate
 * the WordPress.org Plugin Guidelines. It also blocks commands that
 * destroy block-editor content.
 *
 * The policy was moved out of RunWpCli into one focused file. The trait is
 * a pure function on a parsed args array. It has no side effects. It does
 * not depend on RunWpCli state. It calls `RunWpCli::parse_flags()` on
 * purpose. The security decision must read the slots that execute. It must
 * not run a second parse of its own (DSA-16, see `candidate_positionals()`).
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Core;

defined( 'ABSPATH' ) || exit;

use ZipAI\MCP\Classes\Security\Protected_Options_Filter;

/**
 * Trait holding `verify_command_security`.
 */
trait Security_Verifier_Trait {

	/**
	 * Block commands that violate WordPress.org Plugin Guidelines or would
	 * destroy block-editor content.
	 *
	 * @param string[] $args Parsed command tokens.
	 * @return true|\WP_Error
	 */
	private function verify_command_security( array $args ) {
		// The native dispatcher does not interpret shell operators. The
		// tokeniser treats them as ordinary tokens. So a chained command like
		// `option update foo bar && option update baz qux` would dispatch only
		// the first sub-command. It would drop the rest. Reject it upfront. So
		// the model learns to issue one command per call.
		$shell_operators = array( '&&', '||', ';' );
		foreach ( $args as $arg ) {
			if ( in_array( $arg, $shell_operators, true ) ) {
				return new \WP_Error(
					'security_blocked',
					sprintf(
						'Security policy: shell operator "%s" is not supported — run one command per call.',
						$arg
					)
				);
			}
		}

		// Every rule keys off positional slots. So a rule only holds when the
		// verifier's word split matches the split that EXECUTES. Run the rules
		// against every parse that could execute. Refuse the command if ANY
		// rule trips. See `candidate_positionals()` for why there is more than
		// one split.
		foreach ( $this->candidate_positionals( $args ) as $positional ) {
			$verdict = $this->verify_positional_rules( $args, $positional );
			if ( is_wp_error( $verdict ) ) {
				return $verdict;
			}
		}

		return true;
	}

	/**
	 * Every word split this command could execute under, deduped.
	 *
	 * Two parsers can run the tokens this ability accepts. They disagree:
	 *
	 *   • `parse_flags()` (`dispatch_native`) reads the NEXT token as the
	 *     value of about 60 known keys (`format`, `search`, `fields`, `role`,
	 *     …). It treats a single-dash `-x` as a positional.
	 *   • The WP-CLI runtime (`run_via_wpcli_api`) binds values only with `=`.
	 *     So every `-`-prefixed token is a standalone flag. The ability uses
	 *     this runtime when a wp-cli process invokes it.
	 *
	 * DSA-16 came from picking ONE parser. The verifier stripped flag tokens
	 * but not their values. So `--format json user add-cap 5 manage_options`
	 * read as base `json`. No rule fired. But the dispatcher ran `user
	 * add-cap` and granted the capability. Picking the other parser only moves
	 * the hole. `--search db query "DROP …"` hides `db query` from
	 * `parse_flags`. It then runs under the WP-CLI runtime.
	 *
	 * So the code picks neither. It evaluates both. A rule that trips under
	 * either split refuses the command. This is fail-closed on ambiguity. It
	 * does not over-reject. An unambiguous command makes one identical split.
	 * That is the common case, deduped here. The valid space-form flag
	 * (`plugin list --format json`) stays allowed. No rule trips under either
	 * reading of it.
	 *
	 * Scope: these are the two FLAG-BINDING readings of the tokens
	 * `parse_command_to_args()` made. They do not model the WP-CLI TOKENIZER.
	 * That tokenizer re-splits the raw string on the passthrough path. It
	 * differs at the quoting and escaping margins. It honours `\` inside double
	 * quotes and nowhere else. It does not treat a newline as a separator. That
	 * gap is pre-existing. It is not a known bypass. It is the reason this
	 * docblock says "flag binding" and not "every possible split".
	 *
	 * @param string[] $args Parsed command tokens.
	 * @return array<int,string[]> One or two lowercased positional lists.
	 */
	private function candidate_positionals( array $args ) {
		list( $executor_tokens ) = $this->parse_flags( $args );
		$executor                = array_map( 'strtolower', $executor_tokens );

		// The WP-CLI runtime split. Every `-`-prefixed token is a flag. It
		// binds no following value.
		$runtime = array();
		foreach ( $args as $arg ) {
			if ( ! str_starts_with( $arg, '-' ) ) {
				$runtime[] = strtolower( $arg );
			}
		}

		return $executor === $runtime ? array( $executor ) : array( $executor, $runtime );
	}

	/**
	 * The denylist itself, evaluated against one candidate word split.
	 *
	 * @param string[] $args       Parsed command tokens (flag scans read these).
	 * @param string[] $positional Lowercased positional slots for this candidate.
	 * @return true|\WP_Error
	 */
	private function verify_positional_rules( array $args, array $positional ) {
		$base_command = $positional[0] ?? '';
		$sub_command  = $positional[1] ?? '';

		// 1. Arbitrary code execution & shell access are forbidden.
		$forbidden_commands = array( 'eval', 'eval-file', 'shell', 'package', 'server' );
		if ( in_array( $base_command, $forbidden_commands, true ) ) {
			return new \WP_Error( 'security_blocked', sprintf( 'Security policy: WP-CLI "%s" command is blocked.', $base_command ) );
		}

		// 2. Direct SQL is blocked (SQLi prevention).
		if ( 'db' === $base_command && in_array( $sub_command, array( 'query', 'import', 'drop', 'reset' ), true ) ) {
			return new \WP_Error( 'security_blocked', sprintf( 'Security policy: WP-CLI "db %s" is blocked.', $sub_command ) );
		}

		// 2a. `option add` and `option patch` remain blocked.
		// `add` is rarely useful — `update` is idempotent (creates the option
		// if missing). `patch` performs array-key surgery on serialized
		// options, which is a sharp edge that's almost never the right path
		// for an AI agent. `update` + `delete` cover the legitimate write
		// surface; the keys neither may touch are rule 2a-bis below.
		if ( 'option' === $base_command && in_array( $sub_command, array( 'add', 'patch' ), true ) ) {
			return new \WP_Error(
				'security_blocked',
				sprintf( 'Security policy: WP-CLI "option %s" is blocked. Use "option update" instead (it creates the option when missing).', $sub_command )
			);
		}

		// 2a-bis. Protected option keys (DSA-17). This sits in the VERIFIER, not
		// only in `handle_option_update` / `handle_option_delete`, because
		// `execute()` has two executors and the handlers are reachable from just
		// one of them: when the ability runs inside a wp-cli process,
		// `run_via_wpcli_api()` hands the raw string to WP-CLI and never touches
		// `dispatch_native`. A guard here covers both, and inherits the
		// two-candidate parse above for free.
		//
		// The handler checks stay as defense-in-depth — same
		// `is_write_protected()` call, so there is still one key list — and they
		// are what produce the precise error instead of a silent filter no-op.
		if ( 'option' === $base_command && in_array( $sub_command, array( 'update', 'delete' ), true ) ) {
			$option_key = $positional[2] ?? '';
			if ( '' !== $option_key && Protected_Options_Filter::is_write_protected( $option_key ) ) {
				return new \WP_Error(
					'security_blocked',
					sprintf(
						'Security policy: option "%s" is protected and cannot be modified via the AI agent. Edit manually in wp-admin → Settings, or use the dedicated ability for it (theme activate / plugin activate).',
						$option_key
					)
				);
			}
		}

		// 2b. User creation/deletion and account mutation are blocked.
		// WordPress account management has reauth, email-confirmation,
		// password, and security-plugin hooks that generic automation must
		// not bypass. Read-only user discovery remains available.
		if ( 'user' === $base_command && in_array( $sub_command, array( 'create', 'update', 'delete' ), true ) ) {
			return new \WP_Error(
				'security_blocked',
				sprintf( 'Security policy: WP-CLI "user %s" is blocked. Manage users from WordPress admin or a dedicated, reviewed flow.', $sub_command )
			);
		}

		if ( 'user' === $base_command && 'meta' === $sub_command ) {
			$meta_verb = $positional[2] ?? '';
			if ( in_array( $meta_verb, array( 'add', 'update', 'set', 'delete' ), true ) ) {
				return new \WP_Error(
					'security_blocked',
					sprintf( 'Security policy: WP-CLI "user meta %s" is blocked. User metadata writes can affect authentication and capabilities.', $meta_verb )
				);
			}
		}

		// 2b-bis. Block administrator-promotion / lockout via role and cap
		// commands. Non-admin role changes (e.g. add-role <id> editor) are
		// allowed through to dispatch_native; only grants/removals of admin-
		// class roles and capabilities are refused here.
		if ( 'user' === $base_command
			&& in_array( $sub_command, array( 'add-role', 'remove-role', 'set-role' ), true ) ) {
			$role_arg        = strtolower( $positional[3] ?? '' );
			$high_risk_roles = array( 'administrator', 'super-admin' );
			if ( '' !== $role_arg && in_array( $role_arg, $high_risk_roles, true ) ) {
				return new \WP_Error(
					'security_blocked',
					sprintf(
						'Security policy: `wp user %s <id> %s` is blocked. Promoting or demoting administrators must be done from wp-admin → Users.',
						$sub_command,
						$role_arg
					)
				);
			}
		}

		if ( 'user' === $base_command
			&& in_array( $sub_command, array( 'add-cap', 'remove-cap' ), true ) ) {
			$cap_arg = strtolower( $positional[3] ?? '' );
			// Admin-class capabilities — granting any of these elevates a
			// user to admin in practice; removing manage_options from the
			// only admin can lock out site access.
			$admin_caps = array(
				'manage_options',
				'install_plugins',
				'activate_plugins',
				'delete_plugins',
				'edit_plugins',
				'install_themes',
				'switch_themes',
				'edit_themes',
				'delete_themes',
				'unfiltered_html',
				'create_users',
				'delete_users',
				'edit_users',
				'promote_users',
				'manage_network',
				'manage_sites',
			);
			if ( '' !== $cap_arg && in_array( $cap_arg, $admin_caps, true ) ) {
				return new \WP_Error(
					'security_blocked',
					sprintf(
						'Security policy: `wp user %s <id> %s` is blocked. "%s" is an admin-class capability — manage it from wp-admin → Users.',
						$sub_command,
						$cap_arg,
						$cap_arg
					)
				);
			}
		}

		// 2c. `wp post|comment meta add/update/set/delete/patch` is blocked.
		// Caller-supplied object id + caller-supplied meta key is a generic
		// write primitive equivalent to `option update` — it can target
		// `_edit_lock`, `_thumbnail_id`, theme/plugin private meta, or any
		// custom field. Specific edits route through dedicated abilities.
		if ( in_array( $base_command, array( 'post', 'comment' ), true ) && 'meta' === $sub_command ) {
			$meta_verb = $positional[2] ?? '';
			if ( in_array( $meta_verb, array( 'add', 'update', 'set', 'delete', 'patch' ), true ) ) {
				return new \WP_Error(
					'security_blocked',
					sprintf( 'Security policy: WP-CLI "%s meta %s" is blocked. Use a dedicated ability for the specific meta key you need to modify.', $base_command, $meta_verb )
				);
			}
		}

		// 2d. `wp transient set` and `transient patch` are always blocked —
		// caller-supplied key + value is a generic write primitive (same
		// shape as `option update`) that can clobber internal WP transients
		// like `update_plugins`, `update_themes`, session caches, etc.
		if ( 'transient' === $base_command && in_array( $sub_command, array( 'set', 'patch' ), true ) ) {
			return new \WP_Error(
				'security_blocked',
				sprintf( 'Security policy: WP-CLI "transient %s" is blocked.', $sub_command )
			);
		}
		// `wp transient delete` is conditional:
		// - `wp transient delete --expired`  ALLOW (safe bulk-cleanup)
		// - `wp transient delete --all`      ALLOW (destructive cache flush, approval-gated)
		// - `wp transient delete <key>`      BLOCK (specific-key deletion can nuke
		// `update_plugins` and break the WP update system)
		if ( 'transient' === $base_command && 'delete' === $sub_command ) {
			$has_expired_flag = false;
			$has_all_flag     = false;
			$has_positional   = false;
			foreach ( $args as $arg ) {
				$arg_lower = strtolower( $arg );
				if ( '--expired' === $arg_lower || str_starts_with( $arg_lower, '--expired=' ) ) {
					$has_expired_flag = true;
					continue;
				}
				if ( '--all' === $arg_lower || str_starts_with( $arg_lower, '--all=' ) ) {
					$has_all_flag = true;
					continue;
				}
				if ( ! str_starts_with( $arg, '-' )
					&& 'transient' !== strtolower( $arg )
					&& 'delete' !== strtolower( $arg ) ) {
					$has_positional = true;
				}
			}
			if ( $has_positional ) {
				return new \WP_Error(
					'security_blocked',
					'Security policy: WP-CLI "transient delete <key>" is blocked. Use "transient delete --expired" (safe bulk cleanup) or "transient delete --all" (full cache flush, approval required).'
				);
			}
			if ( ! $has_expired_flag && ! $has_all_flag ) {
				return new \WP_Error(
					'security_blocked',
					'Security policy: WP-CLI "transient delete" requires either --expired or --all. Specific-key deletion is blocked.'
				);
			}
		}

		// 3. Honor DISALLOW_FILE_MODS for install/update/delete on plugin/theme/core.
		$modifying_commands = array( 'plugin', 'theme', 'core' );
		$modifying_subs     = array( 'install', 'update', 'delete' );
		if ( in_array( $base_command, $modifying_commands, true ) && in_array( $sub_command, $modifying_subs, true ) ) {
			if ( ! wp_is_file_mod_allowed( 'zipai_run_wp_cli_file_mods' ) ) {
				return new \WP_Error( 'security_blocked', 'Security policy: file modifications are disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter).' );
			}
		}

		// 4. Block code-execution and path-traversal flags.
		$forbidden_flags = array( '--require', '--exec', '--ssh', '--config', '--path', '--prompt' );
		foreach ( $args as $arg ) {
			$arg_lower = strtolower( $arg );
			foreach ( $forbidden_flags as $flag ) {
				if ( $arg_lower === $flag || str_starts_with( $arg_lower, $flag . '=' ) ) {
					return new \WP_Error( 'security_blocked', sprintf( 'Security policy: WP-CLI flag "%s" is blocked.', $flag ) );
				}
			}
		}

		// 5. Block post_content replacement — it destroys block markup.
		if ( 'post' === $base_command && in_array( $sub_command, array( 'update', 'create' ), true ) ) {
			foreach ( $args as $arg ) {
				if ( str_starts_with( strtolower( $arg ), '--post_content' ) ) {
					return new \WP_Error(
						'security_blocked',
						sprintf( 'Security policy: "wp post %s --post_content=" is blocked. It replaces the entire post_content with a plain string, destroying all block markup. ', $sub_command )
						. 'For block edits, use editor__apply_change with the page open in the block editor. '
						. 'There is no server-side path for editing page content without the editor — ask the user to open the page in the block editor.'
					);
				}
			}
		}

		return true;
	}
}
