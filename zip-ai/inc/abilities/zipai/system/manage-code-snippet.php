<?php
/**
 * Manage Code Snippet Ability
 *
 * This ability can create, read, update, delete, enable, and disable code
 * snippets. Each snippet is a folder in wp-content/zip-ai-snippets/{slug}/.
 * The folder holds snippet.php, snippet.js, and snippet.css as needed.
 *
 * You can configure how each type runs:
 * - PHP is included on a configured WordPress action. This includes safe custom hooks.
 * - JS is enqueued through WordPress enqueue actions.
 * - CSS is enqueued through WordPress enqueue actions.
 *
 * Guardrails:
 * - validate_slug() prevents path traversal.
 * - A PHP blocklist rejects dangerous functions at create and update time.
 * - SHA-256 hashes check file integrity. A tampered file does not run.
 * - New snippets are DISABLED by default. The user must enable each one.
 * - An update saves the old code as a backup in snippet.{type}.bak.
 * - The code size limit is 100KB.
 * - An audit trail records the created_by and updated_by user IDs.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Snippet_Store;
use ZipAI\MCP\Classes\Core\Snippet_Versions;
use ZipAI\MCP\Classes\Core\Snippet_Lint;
use ZipAI\MCP\Classes\Core\Utils;
use ZipAI\MCP\Classes\Core\Snippet_Conditions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ManageCodeSnippet extends Abstract_Ability {

	/**
	 * Whether this ability performs a destructive write (gates approval).
	 *
	 * @var bool
	 */
	protected $is_destructive = true;

	/**
	 * Sub-actions that only READ from the snippet store — listed here so the
	 * writes-require-approval gate doesn't block dedup lookups
	 * (`list`/`get`), diagnostics (`simulate_match`/`trace`), target
	 * resolution (`resolve_target`), or version-history reads.
	 *
	 * @var array<int,string>
	 */
	protected $read_only_actions = array(
		'list',
		'get',
		'list_versions',
		'get_version',
		'diff_versions',
		'simulate_match',
		'resolve_target',
		'trace',
	);

	/**
	 * Actions that put PHP on disk or turn it on, and therefore need
	 * `Snippet_Store::manage_capability()` rather than the read capability the
	 * ability registers with.
	 *
	 * `delete` and `disable` are deliberately absent — removing code and
	 * switching it off are the safety valves, and the executor runs enabled
	 * snippets regardless of who can author them.
	 *
	 * @var array<int,string>
	 */
	private const WRITE_ACTIONS = array(
		'create',
		'update',
		'enable',
		'restore_version',
		'create_manual_version',
	);

	/**
	 * Get allowed file types. Filterable via 'zip_ai_snippets_types'.
	 *
	 * @since 0.0.5
	 * @return array<int,string>
	 */
	private function get_types() {
		$types = apply_filters( 'zip_ai_snippets_types', array( 'php', 'js', 'css', 'html' ) );
		return $this->to_str_list( $types );
	}

	/**
	 * Get max code size in bytes. Filterable via 'zip_ai_snippets_max_code_size'.
	 *
	 * @since 0.0.5
	 * @return int
	 */
	private function get_max_code_size() {
		$size = apply_filters( 'zip_ai_snippets_max_code_size', 102400 ); // 100KB default
		return max( 1024, is_scalar( $size ) ? (int) $size : 1024 ); // Minimum 1KB
	}

	/**
	 * Register the ability's id, label, description, and capability.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/run-snippet';
		$this->label       = 'Manage Code Snippet';
		$this->description = 'Site-wide PHP/JS/CSS/HTML snippets. '
			. 'MANDATORY FIRST STEP for ANY add/create/install/install-tag/install-snippet request: call `action:"list"` BEFORE anything else (no smart-form, no `resolve_target`, no `create`). If a matching snippet already exists, switch to `action:"update"` on that slug and skip the smart-form intake. NEVER create a duplicate. '
			. 'Conditions model: at most ONE row per type. Multi-value via `operator:"in"|"not_in"`/`starts_with`/`contains`/`ends_with`/`regex` (OR within row). Rows AND together. '
			. 'DO NOT use for page content — use editor__apply_change with the page open in the block editor, and a core/html block for raw HTML embedded in a page. '
			. 'Before authoring conditions for a named target ("shop page", "checkout", "blog"), call action=`resolve_target` to get the real post_id + post_type — never guess. '
			. 'After any conditions change, call action=`simulate_match` against the target post to verify. '
			. 'Every write returns `evaluated_targeting` (e.g. "Runs when post in [2800,2801] AND post_type=product") — read it to confirm intent. '
			. 'Full rules in "Snippet Generator" prompt section.';
		// Registers on the read capability so inspect / disable / delete keep
		// working where the file editor is denied; `execute()` enforces
		// Snippet_Store::manage_capability() for the write actions.
		$this->capability = Snippet_Store::read_capability();
	}

	/**
	 * Tool-type classification for this ability.
	 *
	 * @return string One of the Tool_Types constants.
	 */
	public function get_tool_type() {
		return Tool_Types::ACTION;
	}

	/**
	 * Input schema — per-action fields plus per-action required-field enforcement.
	 *
	 * @return array<string,mixed> JSON-schema definition for the ability input.
	 */
	public function get_input_schema() {
		// Per-file-type hook enums — each file type supports a different set.
		// Schema carries the contract so the prompt doesn't have to list them.
		$php_hooks  = Snippet_Store::VALID_PHP_HOOKS;
		$js_hooks   = Snippet_Store::VALID_JS_HOOKS;
		$css_hooks  = Snippet_Store::VALID_CSS_HOOKS;
		$html_hooks = Snippet_Store::VALID_HTML_HOOKS;
		$scope      = array( 'frontend', 'admin', 'everywhere', 'login' );

		// Common execution config shape — reused per file type with a different hook enum.
		/**
		 * Build a per-file-type execution-config schema entry.
		 *
		 * @param array<int,string> $hook_enum    Allowed hook names for this file type.
		 * @param bool              $allow_custom Whether custom hook strings are permitted.
		 * @return array<string,mixed>
		 */
		$exec_entry = static function ( array $hook_enum, $allow_custom = false ) use ( $scope ): array {
			$hook_schema = array(
				'type'        => 'string',
				'enum'        => $hook_enum,
				'description' => 'WordPress action hook to attach this file to.',
			);
			if ( $allow_custom ) {
				$hook_schema = array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z_][A-Za-z0-9_./:-]{0,127}$',
					'description' => 'WordPress action hook for PHP execution. Prefer known hooks: ' . implode( ', ', array_map( static fn ( $h ): string => is_scalar( $h ) ? (string) $h : '', $hook_enum ) ) . '. Safe custom plugin hooks are allowed, e.g. woocommerce_before_cart or acf/save_post.',
				);
			}

			$entry = array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'hook'     => $hook_schema,
					'priority' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 99,
						'description' => 'Hook priority (1-99). Default 10.',
					),
					'scope'    => array(
						'type'        => 'string',
						'enum'        => $scope,
						'description' => 'Where this file runs: frontend, admin, everywhere, or login pages.',
					),
				),
			);
			if ( $allow_custom ) {
				$entry['properties']['accepted_args'] = array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 10,
					'description' => 'PHP only. Number of hook/filter arguments WordPress should pass into the snippet. Defaults to 1. Snippet code may read $args, $zip_ai_hook_value, or $zip_ai_hook_args and may return a filter value.',
				);
			}
			return $entry;
		};

		return array(
			'type'                 => 'object',
			'required'             => array( 'action' ),
			// Top-level loose — callers may carry audit metadata we don't want to block.
			'additionalProperties' => true,
			'properties'           => array(
				'action'             => array(
					'type'        => 'string',
					'enum'        => array(
						'create',
						'list',
						'get',
						'update',
						'delete',
						'enable',
						'disable',
						'list_versions',
						'get_version',
						'diff_versions',
						'restore_version',
						'create_manual_version',
						'simulate_match',
						'resolve_target',
						'trace',
					),
					'description' => 'Snippet lifecycle action. Versioning: list_versions, get_version, diff_versions, restore_version (destructive), create_manual_version. Diagnostics: `simulate_match` (does this snippet fire against a given post/url/user?), `resolve_target` (turn "shop page"/slug into concrete post_id+post_type before authoring conditions), `trace` (read last evaluation traces for a slug). Always call `resolve_target` before authoring conditions for a named page, and `simulate_match` after any conditions change to verify before claiming success.',
				),
				'slug'               => array(
					'type'        => 'string',
					'description' => 'Snippet slug (folder name). Required for get/update/delete/enable/disable.',
				),
				'title'              => array(
					'type'        => 'string',
					'description' => 'Human-readable snippet title.',
				),
				'code'               => array(
					'type'        => 'string',
					'description' => 'Code content. For PHP: do NOT include opening tag.',
				),
				'type'               => array(
					'type'        => 'string',
					'enum'        => $this->get_types(),
					'description' => 'File type. php = server-side, runs on a WP action. js = pure JavaScript body (NO `<script>` wrappers, NO HTML comments — executor wraps it in an inline `<script>` automatically; including your own tags double-wraps and breaks). css = pure CSS body (NO `<style>` wrappers). html = verbatim head/footer markup emitted as-is on `wp_head`/`wp_footer`/`login_head` — use for external `<script src="...">` loaders (Google Analytics gtag, Tag Manager, FB Pixel, Hotjar), JSON-LD blocks, OG/meta tags, `<noscript>` pixels. If your payload literally contains `<script>` or `<link rel="stylesheet">`, the answer is `type:"html"`.',
				),
				'replace_files'      => array(
					'type'        => 'boolean',
					'description' => 'Update only. Set true when the new code replaces the whole snippet and old file types must be removed after the update. Use for conversions such as changing an old HTML WhatsApp button into a PHP Facebook button so stale files cannot keep rendering.',
				),
				'remove_types'       => array(
					'type'        => 'array',
					'description' => 'Update only. Explicit file types to remove from this snippet after a successful update. Use to clean up stale old outputs (for example remove_types:["html"] after replacing old HTML with PHP). Cannot remove the same type being updated.',
					'items'       => array(
						'type' => 'string',
						'enum' => $this->get_types(),
					),
				),
				'description'        => array( 'type' => 'string' ),
				'version_id'         => array(
					'type'        => 'string',
					'description' => 'Target version. Accepts either the raw id (e.g. "1778074991-7b9b29") OR the user-facing label (e.g. "v3"). Always prefer the raw id from a recent list_versions call — labels (vN) are derived from index position and may shift if older versions are GC\'d.',
				),
				'against_version_id' => array(
					'type'        => 'string',
					'description' => 'Comparison version for diff_versions. Accepts raw id or label. Defaults to current HEAD.',
				),
				'against'            => array(
					'type'        => 'object',
					'description' => '`simulate_match` only. Context to evaluate conditions against. Keys: post_id (preferred — server resolves post_type/page-type from it), url (REQUEST_URI to test), user_role, device ("mobile"|"desktop"). Server synthesizes runtime context from these and returns pass/fail + per-row trace.',
				),
				'query'              => array(
					'type'        => 'string',
					'description' => '`resolve_target` only. Natural-language label of a target page or archive (e.g. "shop page", "checkout", "blog", "product archive"). Server returns concrete matches (post ID + post_type, post-type archive, WooCommerce page mapping) and suggested condition rows. Always call this before authoring conditions for a named page.',
				),
				'reason'             => array(
					'type'        => 'string',
					'description' => 'Human-readable change message. Required for create_manual_version, and recommended on update when changing code.',
				),
				'execution'          => array(
					// Strict per-file-type. Wrong hook for a type (e.g. "init" on a JS file)
					// is rejected here rather than discovered at runtime.
					'type'                 => 'object',
					'description'          => 'Execution config per file type. Each file type has its own allowed hook set — schema enforces the valid combinations.',
					'additionalProperties' => false,
					'properties'           => array(
						'php'  => $exec_entry( $php_hooks, true ),
						'js'   => $exec_entry( $js_hooks ),
						'css'  => $exec_entry( $css_hooks ),
						'html' => $exec_entry( $html_hooks ),
					),
				),
				'conditions'         => array(
					'type'        => 'array',
					'description' => 'Targeting rows — FULL REPLACEMENT on update. Row shape: {type, operator, value?|values?, connector?}. `connector` is "and" (default) or "or" — "or" marks the start of a new OR chain. Consecutive AND rows form a chain; chains OR together. Standard precedence: AND binds tighter than OR. One row per type WITHIN a chain (same type across different chains is allowed). Multi-value via `operator:"in"`/`"not_in"` (or pattern operators for url_pattern). Empty array = runs everywhere (only after explicit user confirmation). Server returns `evaluated_targeting` like "Runs when (post in [2051] AND user_role=admin) OR device=mobile" — verify it matches intent.',
					'items'       => array( 'type' => 'object' ),
				),
			),
			// Per-action required-field enforcement. Each branch fires when
			// `action` matches the literal `const`, then asserts the fields
			// that action needs. Schema validators surface these failures
			// BEFORE the call hits the server, so the model learns the
			// contract from the schema rather than from a runtime
			// "version_id is required" error string.
			'allOf'                => array(
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'create' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'title', 'code', 'type' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'get' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug', 'reason' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'update' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'delete' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'enable' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'disable' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'list_versions' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'get_version' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug', 'version_id' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'diff_versions' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug', 'version_id' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'restore_version' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug', 'version_id' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'create_manual_version' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'simulate_match' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'resolve_target' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'query' ) ),
				),
				array(
					'if'   => array(
						'properties' => array( 'action' => array( 'const' => 'trace' ) ),
						'required'   => array( 'action' ),
					),
					'then' => array( 'required' => array( 'action', 'slug' ) ),
				),
			),
		);
	}

	/**
	 * Output schema — common response shape across all actions.
	 *
	 * @since 0.0.5
	 * @return array<string,mixed>
	 */
	public function get_output_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'success' ),
			'additionalProperties' => true,
			'properties'           => array(
				'success' => array( 'type' => 'boolean' ),
				'slug'    => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
				'data'    => array(
					'description' => 'Action-specific data: snippet record (create/get/update), list of snippets (list), or status flags (enable/disable/delete). create/update also return `edit_url` — the wp-admin link that opens this snippet in the ZIP AI editor.',
				),
			),
		);
	}

	/**
	 * Dispatch a sub-action to its handler.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	public function execute( $input ) {
		Snippet_Store::ensure_base_dir();

		$action = Utils::to_str( $input['action'] ?? '' );

		// The ability itself registers on the read capability so an agent can
		// still inspect and switch snippets OFF on a host that denies the file
		// editor. Actions that put PHP on disk or turn it on need the higher cap
		// — same split the REST routes enforce.
		if ( in_array( $action, self::WRITE_ACTIONS, true ) && ! Snippet_Store::current_user_can_manage() ) {
			return Response::error(
				'You do not have permission to create, change or enable snippets on this site. '
				. 'Snippet code runs on the site, so this requires the same capability as the plugin file editor '
				. '(blocked by DISALLOW_FILE_EDIT, DISALLOW_FILE_MODS, or a multisite sub-site role).'
			);
		}

		switch ( $action ) {
			case 'create':
				return $this->create_snippet( $input );
			case 'list':
				return $this->list_snippets();
			case 'get':
				return $this->get_snippet( $input );
			case 'update':
				return $this->update_snippet( $input );
			case 'delete':
				return $this->delete_snippet( $input );
			case 'enable':
				return $this->toggle_snippet( $input, true );
			case 'disable':
				return $this->toggle_snippet( $input, false );
			case 'list_versions':
				return $this->list_versions_action( $input );
			case 'get_version':
				return $this->get_version_action( $input );
			case 'diff_versions':
				return $this->diff_versions_action( $input );
			case 'restore_version':
				return $this->restore_version_action( $input );
			case 'create_manual_version':
				return $this->create_manual_version_action( $input );
			case 'simulate_match':
				return $this->simulate_match_action( $input );
			case 'resolve_target':
				return $this->resolve_target_action( $input );
			case 'trace':
				return $this->trace_action( $input );
			default:
				return Response::error( "Unknown action: {$action}" );
		}
	}

	/**
	 * Build version-author metadata from the current user.
	 *
	 * @return array{author_type: string, author_id: int|null, author_name: string} Version author context.
	 */
	private function agent_context() {
		$user_id = $this->current_user_id();
		$user    = $user_id ? get_user_by( 'id', $user_id ) : null;
		return array(
			'author_type' => 'agent',
			'author_id'   => $user_id ? $user_id : null,
			'author_name' => $user && $user->display_name ? 'ZIP AI (' . $user->display_name . ')' : 'ZIP AI',
		);
	}

	// ── Version actions (agent surface) ─────────────────────────────────

	/**
	 * List a snippet's version history.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function list_versions_action( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}
		$versions = Snippet_Versions::list_versions( $slug );
		return Response::success(
			count( $versions ) . ' version(s) found.',
			array(
				'slug'     => $slug,
				'versions' => $versions,
			)
		);
	}

	/**
	 * Load a single version's meta and file contents.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function get_version_action( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}
		$version_id = sanitize_text_field( Utils::to_str( $input['version_id'] ?? '' ) );
		if ( empty( $version_id ) ) {
			return Response::error( 'version_id is required. Pass either the raw id or the user-facing label (e.g. "v3"). Call list_versions first to map labels to ids.' );
		}
		$result = Snippet_Versions::get( $slug, $version_id );
		if ( is_wp_error( $result ) ) {
			return Response::error( $result->get_error_message() );
		}
		return Response::success( "Version '{$version_id}' loaded.", $this->to_arr( $result ) );
	}

	/**
	 * Compute a diff between two versions.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function diff_versions_action( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}
		$a = sanitize_text_field( Utils::to_str( $input['version_id'] ?? '' ) );
		if ( empty( $a ) ) {
			return Response::error( 'version_id is required.' );
		}
		$b = sanitize_text_field( Utils::to_str( $input['against_version_id'] ?? '' ) );
		$b = $b ? $b : null;
		return Response::success( 'Diff computed.', array( 'diff' => Snippet_Versions::diff( $slug, $a, $b ) ) );
	}

	/**
	 * Restore a snippet's HEAD to an older version.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function restore_version_action( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}
		$version_id = sanitize_text_field( Utils::to_str( $input['version_id'] ?? '' ) );
		if ( empty( $version_id ) ) {
			return Response::error( 'version_id is required. Pass either the raw id or the user-facing label (e.g. "v3"). Call list_versions first to map labels to ids.' );
		}
		$entry = Snippet_Versions::restore( $slug, $version_id, $this->agent_context() );
		if ( is_wp_error( $entry ) ) {
			return Response::error( $entry->get_error_message() );
		}
		if ( ! $entry ) {
			return Response::error( "Failed to restore version '{$version_id}'." );
		}
		// Stamp updated_by_type so future readers know an agent did this —
		// under the store lock so a concurrent writer's entry isn't clobbered
		// by this whole-manifest write.
		$user_id = $this->current_user_id();
		$entry   = array();
		Snippet_Store::with_lock(
			function ( array &$manifest ) use ( $slug, $user_id, &$entry ) {
				if ( ! isset( $manifest[ $slug ] ) ) {
					return;
				}
				$entry                    = $this->to_arr( $manifest[ $slug ] );
				$entry['updated_by_type'] = 'agent';
				$entry['updated']         = current_time( 'mysql' );
				$entry['updated_by']      = $user_id;
				$manifest[ $slug ]        = $entry;
			}
		);
		// Restore intentionally bypasses lint (escape hatch for legitimately
		// older code), but report warnings so the agent can self-correct.
		$lint_warnings = array();
		$files         = $this->to_str_list( $entry['files'] ?? array() );
		if ( in_array( 'php', $files, true ) ) {
			$file = Snippet_Store::snippet_file( $slug, 'php' );
			if ( file_exists( $file ) ) {
				$code           = Snippet_Store::unwrap_php( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$php_exec       = $this->to_arr( $this->to_arr( $entry['execution'] ?? array() )['php'] ?? array() );
				$execution_hook = Utils::to_str( $php_exec['hook'] ?? '' );
				$lint           = Snippet_Lint::check( $code, $execution_hook );
				if ( is_wp_error( $lint ) ) {
					$lint_warnings[] = array(
						'code'    => $lint->get_error_code(),
						'message' => $lint->get_error_message(),
					);
				}
			}
		}
		// HEAD changed — drop the executor trace ring buffer so stale entries
		// from the prior code path don't surface on the next `trace` call.
		delete_transient( 'zip_ai_snippets_trace_' . $slug );
		return Response::success(
			"Snippet '{$slug}' restored to version '{$version_id}'.",
			array(
				'version'       => $entry,
				'lint_warnings' => $lint_warnings,
			)
		);
	}

	/**
	 * Save a manual version snapshot of HEAD.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function create_manual_version_action( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}
		$reason = sanitize_text_field( Utils::to_str( $input['reason'] ?? '' ) );
		if ( '' === $reason ) {
			return Response::error( 'reason is required for create_manual_version.' );
		}
		$ctx           = $this->agent_context();
		$ctx['reason'] = $reason;
		$ctx['manual'] = true;
		$entry         = Snippet_Versions::create( $slug, $ctx );
		if ( ! $entry ) {
			return Response::error( 'Failed to create manual version.' );
		}
		return Response::success( "Manual snapshot saved for '{$slug}'.", array( 'version' => $entry ) );
	}

	// ── Diagnostic actions (agent surface) ──────────────────────────────

	/**
	 * Simulate whether a snippet would fire against a synthesized request
	 * context (post_id, url, user_role, device). Agent calls this after any
	 * conditions change to verify before claiming success; admin UI can wire
	 * it to a "Test on this page" button.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function simulate_match_action( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}
		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return Response::error( Snippet_Store::not_found_message( $slug ) );
		}
		$normalized = Snippet_Store::normalize_snippet( $this->to_arr( $manifest[ $slug ] ) );
		$conditions = $this->to_rows( $normalized['conditions'] );
		$against    = $this->to_arr( $input['against'] ?? null );

		$cleanup = $this->push_simulation_context( $against );
		try {
			$trace = Snippet_Conditions::evaluate_with_trace( $conditions, false );
		} finally {
			$cleanup();
		}

		return Response::success(
			sprintf(
				"Simulation for '%s' against %s: %s",
				$slug,
				$this->describe_against( $against ),
				! empty( $trace['result'] ) ? 'WOULD FIRE' : 'would not fire'
			),
			array(
				'slug'                => $slug,
				'would_match'         => ! empty( $trace['result'] ),
				'against'             => $against,
				'evaluated_targeting' => Snippet_Store::describe_conditions( $conditions ),
				'trace'               => $trace,
			)
		);
	}

	/**
	 * Set up a transient WP_Query context matching the requested target so
	 * Snippet_Conditions evaluators see the correct globals/queried object.
	 * Returns a cleanup callable that must be invoked in a finally block.
	 *
	 * @param array<string,mixed> $against Simulation context (post_id, url, user_role, device).
	 * @return \Closure Cleanup callback that restores the prior request context.
	 */
	private function push_simulation_context( array $against ) {
		global $wp_query;

		$saved_query = $wp_query;
		$saved_post  = ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) ? $GLOBALS['post'] : null;
		$saved_uri   = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- saved verbatim to restore after simulation; not consumed as input.
		$saved_user  = get_current_user_id();

		if ( ! empty( $against['url'] ) ) {
			$_SERVER['REQUEST_URI'] = esc_url_raw( Utils::to_str( $against['url'] ) );
		}

		if ( ! empty( $against['post_id'] ) ) {
			$post = get_post( (int) Utils::to_str( $against['post_id'] ) );
			if ( $post ) {
				$wp_query                    = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate simulate/restore of query context; restored in the returned closure.
				$wp_query->queried_object    = $post;
				$wp_query->queried_object_id = $post->ID;
				$wp_query->is_single         = ( 'page' !== $post->post_type );
				$wp_query->is_singular       = true;
				$wp_query->is_page           = ( 'page' === $post->post_type );
				$wp_query->post              = $post;
				$wp_query->posts             = array( $post );
				$wp_query->post_count        = 1;
				$GLOBALS['post']             = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate simulate/restore of query context; restored in the returned closure.
				setup_postdata( $post );
			}
		}

		// Track whether we registered a synthetic-role filter so cleanup can
		// remove it without poking globals.
		$role_filter = null;
		if ( ! empty( $against['user_role'] ) ) {
			$role  = sanitize_key( Utils::to_str( $against['user_role'] ) );
			$users = get_users(
				array(
					'role'   => $role,
					'number' => 1,
					'fields' => 'ID',
				)
			);
			if ( ! empty( $users ) ) {
				wp_set_current_user( (int) Utils::to_str( $users[0] ) );
			} else {
				// No real user has this role. Synthesize the role via filter so
				// the evaluator sees the requested role on `wp_get_current_user()`
				// — silently no-oping (the old behavior) made `simulate_match`
				// report incorrect pass/fail using whatever role the calling
				// agent's session user happened to have.
				$role_filter = static function ( $user ) use ( $role ) {
					if ( $user instanceof \WP_User ) {
						$user->roles = array( $role );
					}
					return $user;
				};
				add_filter( 'wp_get_current_user', $role_filter, 1000 );
				// Also ensure is_user_logged_in() returns true for the synthetic
				// session — the evaluator gates on it before reading roles.
				wp_set_current_user( $saved_user > 0 ? $saved_user : 1 );
			}
		}

		$mobile_filter = null;
		if ( ! empty( $against['device'] ) ) {
			$want_mobile   = ( 'mobile' === $against['device'] );
			$mobile_filter = static function () use ( $want_mobile ) {
				return $want_mobile;
			};
			add_filter( 'pre_wp_is_mobile', $mobile_filter, 1000 );
		}

		return function () use ( $saved_query, $saved_post, $saved_uri, $saved_user, $mobile_filter, $role_filter ) {
			global $wp_query;
			$wp_query = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the query context saved before simulation.
			if ( null === $saved_post ) {
				unset( $GLOBALS['post'] );
				wp_reset_postdata();
			} else {
				$GLOBALS['post'] = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the post context saved before simulation.
				setup_postdata( $saved_post );
			}
			if ( null === $saved_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $saved_uri;
			}
			wp_set_current_user( (int) $saved_user );
			if ( $mobile_filter ) {
				remove_filter( 'pre_wp_is_mobile', $mobile_filter, 1000 );
			}
			if ( $role_filter ) {
				remove_filter( 'wp_get_current_user', $role_filter, 1000 );
			}
		};
	}

	/**
	 * Render an against-context array as a compact human-readable string.
	 *
	 * @param array<string,mixed> $against Simulation context.
	 * @return string
	 */
	private function describe_against( array $against ) {
		if ( empty( $against ) ) {
			return 'current request';
		}
		$bits = array();
		foreach ( $against as $k => $v ) {
			$bits[] = $k . '=' . Utils::to_str( $v );
		}
		return implode( ',', $bits );
	}

	/**
	 * Resolve a natural-language target ("shop page", "checkout", "blog",
	 * "product archive") into concrete IDs + post_type + suggested condition
	 * rows. Agent calls this BEFORE authoring conditions on a named page so
	 * it never guesses (the celebration snippet failed exactly because the
	 * agent assumed "shop page" meant WooCommerce post_type=product).
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function resolve_target_action( $input ) {
		$query = trim( sanitize_text_field( Utils::to_str( $input['query'] ?? '' ) ) );
		if ( '' === $query ) {
			return Response::error( '`query` is required for resolve_target.' );
		}

		$matches = array();
		$slug    = sanitize_title( $query );
		$needle  = strtolower( $query );

		// 1) WooCommerce-specific aliases — `shop`, `cart`, `checkout`, `myaccount`/`my-account`.
		if ( function_exists( 'wc_get_page_id' ) ) {
			$wc_map = array(
				'shop'      => array( 'shop', 'shop page', 'shop pages', 'store', 'store page', 'products page' ),
				'cart'      => array( 'cart', 'cart page', 'shopping cart' ),
				'checkout'  => array( 'checkout', 'checkout page' ),
				'myaccount' => array( 'myaccount', 'my account', 'account', 'account page' ),
				'terms'     => array( 'terms', 'terms and conditions' ),
			);
			foreach ( $wc_map as $wc_key => $aliases ) {
				if ( in_array( $needle, $aliases, true ) ) {
					$wc_id = (int) wc_get_page_id( $wc_key );
					if ( $wc_id > 0 ) {
						$matches[] = $this->build_post_match( $wc_id, sprintf( 'WooCommerce %s page', $wc_key ) );
					}
				}
			}
		}

		// 2) Exact slug match across public post types.
		$by_slug = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => 'any',
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => 5,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		foreach ( $by_slug as $p ) {
			$matches[] = $this->build_post_match( (int) $p->ID, sprintf( 'Post matched by slug=%s', $p->post_name ) );
		}

		// 3) Title LIKE match.
		$by_title = get_posts(
			array(
				's'                => $query,
				'post_type'        => 'any',
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => 5,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		foreach ( $by_title as $p ) {
			$matches[] = $this->build_post_match( (int) $p->ID, sprintf( 'Post matched by title="%s"', $p->post_title ) );
		}

		// 4) Post-type archive — e.g. query "product archive" / "products".
		$archive_query = preg_replace( '/\s+(archive|archives|page)$/i', '', $needle ) ?? '';
		$archive_query = trim( $archive_query );
		if ( '' !== $archive_query ) {
			$post_types = get_post_types(
				array(
					'has_archive' => true,
					'public'      => true,
				),
				'objects'
			);
			foreach ( $post_types as $pt ) {
				$name_match = strtolower( $pt->name ) === $archive_query
					|| strtolower( $pt->name ) . 's' === $archive_query
					|| ( ! empty( $pt->labels->name ) && strtolower( Utils::to_str( $pt->labels->name ) ) === $archive_query );
				if ( $name_match ) {
					$matches[] = array(
						'kind'                 => 'post_type_archive',
						'post_type'            => $pt->name,
						'label'                => sprintf( '%s archive (post type %s)', Utils::to_str( $pt->labels->name ?? $pt->name ), $pt->name ),
						'suggested_conditions' => array(
							array(
								'type'     => 'page',
								'operator' => 'is',
								'value'    => 'archive',
							),
							array(
								'type'     => 'post_type',
								'operator' => 'is',
								'value'    => $pt->name,
							),
						),
						'suggested_join'       => 'all of the above (AND)',
					);
				}
			}
		}

		$matches = $this->dedupe_target_matches( $matches );

		return Response::success(
			sprintf(
				'%d match(es) for "%s".',
				count( $matches ),
				$query
			),
			array(
				'query'   => $query,
				'matches' => $matches,
				'hint'    => $this->resolve_hint( $matches ),
			)
		);
	}

	/**
	 * Build a resolve_target match row for a post.
	 *
	 * @param int    $post_id Post ID to describe.
	 * @param string $why     Human-readable reason this post matched.
	 * @return array<string,mixed>|null Match row, or null if the post no longer exists.
	 */
	private function build_post_match( $post_id, $why ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return null;
		}
		$permalink = get_permalink( $post );
		return array(
			'kind'                 => 'post',
			'id'                   => (int) $post->ID,
			'post_type'            => $post->post_type,
			'slug'                 => $post->post_name,
			'title'                => $post->post_title,
			'status'               => $post->post_status,
			'url'                  => $permalink ? $permalink : null,
			'why'                  => $why,
			'suggested_conditions' => array(
				array(
					'type'     => 'post',
					'operator' => 'is',
					'value'    => (string) $post->ID,
				),
			),
		);
	}

	/**
	 * Drop duplicate and null match rows, preserving order.
	 *
	 * @param array<int,array<string,mixed>|null> $matches Match rows to dedupe.
	 * @return array<int,array<string,mixed>> Unique match rows.
	 */
	private function dedupe_target_matches( array $matches ) {
		$seen = array();
		$out  = array();
		foreach ( $matches as $m ) {
			if ( ! $m ) {
				continue;
			}
			$key = Utils::to_str( $m['kind'] ) . ':' . Utils::to_str( $m['id'] ?? $m['post_type'] ?? '' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $m;
		}
		return $out;
	}

	/**
	 * Build a caller hint based on how many targets matched.
	 *
	 * @param array<int,array<string,mixed>> $matches Match rows.
	 * @return string
	 */
	private function resolve_hint( array $matches ) {
		if ( empty( $matches ) ) {
			return 'no matches — ask the user to clarify (page slug, URL, or post ID).';
		}
		if ( count( $matches ) === 1 ) {
			return 'single match — safe to use its `suggested_conditions` directly.';
		}
		return 'multiple matches — confirm with the user which one (do not guess).';
	}

	/**
	 * Read the executor's recent evaluation trace for a slug. Returns the
	 * last N entries from the ring buffer so the agent or admin can diagnose
	 * "snippet enabled but didn't fire" without trial-and-error.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function trace_action( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}
		$trace = get_transient( 'zip_ai_snippets_trace_' . $slug );
		if ( ! is_array( $trace ) ) {
			$trace = array();
		}
		return Response::success(
			sprintf( '%d trace entr(y/ies) for "%s".', count( $trace ), $slug ),
			array(
				'slug'  => $slug,
				'count' => count( $trace ),
				'trace' => $trace,
				'hint'  => empty( $trace )
					? 'no traces yet — enable trace mode by appending ?zip_ai_snippet_trace=1 (admin only) to a frontend URL, or call simulate_match for synthetic context.'
					: 'most recent entry is last in array; each entry records request context + per-row pass/fail.',
			)
		);
	}

	// ── Security ───────────────────────────────────────────────────────

	/**
	 * Compute file hash for integrity verification.
	 *
	 * @since 0.0.5
	 *
	 * @param string $filepath Absolute path to the file to hash.
	 * @return string|false SHA-256 hash, or false on failure.
	 */
	private function file_hash( $filepath ) {
		return hash_file( 'sha256', $filepath );
	}

	/**
	 * Get current WordPress user ID for audit trail.
	 *
	 * @since 0.0.5
	 * @return int
	 */
	private function current_user_id() {
		$uid = get_current_user_id();
		return $uid ? $uid : 0;
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Convert a title into a filesystem-safe slug.
	 *
	 * @param string $title Snippet title.
	 * @return string
	 */
	private function slugify( $title ) {
		return sanitize_file_name( sanitize_title( $title ) );
	}

	/**
	 * Coerce a mixed value into a list of strings.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	private function to_str_list( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( $value, 'is_string' ) );
	}

	/**
	 * Coerce a mixed value into an array (non-arrays -> empty array).
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,mixed>
	 */
	private function to_arr( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ (string) $key ] = $item;
		}
		return $out;
	}

	/**
	 * Coerce a mixed value into a list of associative rows (condition rows).
	 * Non-arrays and non-array members are dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,array<string,mixed>> List of condition rows.
	 */
	private function to_rows( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->to_arr( $row );
			}
		}
		return $out;
	}

	/**
	 * Thin pass-through to the central Snippet_Store sanitizer. Single
	 * source of truth for execution + condition sanitization.
	 *
	 * @param mixed $execution Raw execution config.
	 * @param mixed $persisted The snippet's persisted execution config — the merge baseline for partial updates.
	 * @return array<string,mixed> Sanitized execution config keyed by file type.
	 */
	private function sanitize_execution( $execution, $persisted = null ) {
		/**
		 * Sanitized execution config keyed by file type.
		 *
		 * @var array<string,mixed> $sanitized
		 */
		$sanitized = Snippet_Store::sanitize_execution( $this->to_arr( $execution ), is_array( $persisted ) ? $persisted : null );
		return $sanitized;
	}

	/**
	 * Thin pass-through to the central Snippet_Store condition sanitizer.
	 *
	 * @param mixed $conditions Raw condition rows.
	 * @return array{rows: array<int,array<string,mixed>>, rejected: array<int,string>} Sanitized rows + rejection reasons.
	 */
	private function sanitize_conditions( $conditions ) {
		/**
		 * Sanitized targeting condition rows + rejection reasons.
		 *
		 * @var array{rows: array<int,array<string,mixed>>, rejected: array<int,string>} $sanitized
		 */
		$sanitized = Snippet_Store::sanitize_conditions( is_array( $conditions ) ? array_values( $conditions ) : array() );
		return $sanitized;
	}

	/**
	 * Detect which snippet file types exist on disk for a slug.
	 *
	 * @param string $slug Validated snippet slug.
	 * @return array<int,string> File types present on disk.
	 */
	private function detect_files( $slug ) {
		$found = array();
		foreach ( $this->get_types() as $type ) {
			if ( file_exists( Snippet_Store::snippet_file( $slug, $type ) ) ) {
				$found[] = $type;
			}
		}
		return $found;
	}

	/**
	 * Write code to a snippet file with validation and hashing.
	 *
	 * @since 0.0.5
	 *
	 * @param string $slug           Validated snippet slug.
	 * @param string $type           File type: php, js, css, or html.
	 * @param string $code           Raw snippet code.
	 * @param string $title          Snippet title embedded in the PHP header.
	 * @param string $execution_hook Hook the PHP file runs on, for lint context.
	 * @return array{filepath: string, hash: string|false}|string Result array, or error string on failure.
	 */
	private function write_snippet_file( $slug, $type, $code, $title, $execution_hook = '' ) {
		$content = $this->prepare_snippet_content( $type, $code, $title, $execution_hook );
		if ( is_wp_error( $content ) ) {
			return $content->get_error_message();
		}

		return $this->write_prepared_file( $slug, $type, $content );
	}

	/**
	 * Validate a body and turn it into the bytes that go on disk. Separated from
	 * the write so the create path can reject before minting a storage dir.
	 *
	 * @since 0.0.5
	 *
	 * @param string $type           File type: php, js, css, or html.
	 * @param string $code           Raw snippet code.
	 * @param string $title          Snippet title embedded in the PHP header.
	 * @param string $execution_hook Hook the PHP file runs on, for lint context.
	 * @return string|\WP_Error Storage-ready contents, or the reason they were refused.
	 */
	private function prepare_snippet_content( $type, $code, $title, $execution_hook = '' ) {
		// Strip null bytes to prevent injection.
		$code = str_replace( "\0", '', $code );

		if ( strlen( $code ) > $this->get_max_code_size() ) {
			return new \WP_Error( 'too_large', 'Code exceeds maximum size of 100KB.' );
		}

		if ( 'php' !== $type ) {
			// JS/CSS bodies must not contain `<script>`/`<style>` wrappers or
			// HTML comments — the executor already wraps them via
			// wp_add_inline_script/style. HTML type is verbatim and bypasses
			// this check intentionally.
			$non_php_lint = Snippet_Lint::check_non_php( $code, $type );
			if ( is_wp_error( $non_php_lint ) ) {
				return $non_php_lint;
			}
			return $code;
		}

		// PHP blocklist check.
		$blocked = Snippet_Store::check_php_blocklist( $code );
		if ( $blocked ) {
			return new \WP_Error( 'blocked', "Blocked: '{$blocked}' is not allowed in PHP snippets for security reasons." );
		}

		// Inline-targeting lint — block routing checks baked into the snippet
		// body. Forces the agent to use the `conditions[]` argument instead of
		// `if (is_home()) return;` wrappers.
		$lint = Snippet_Lint::check( $code, $execution_hook );
		if ( is_wp_error( $lint ) ) {
			return $lint;
		}

		// Wraps in the ABSPATH guard and refuses bytes the parser rejects.
		return Snippet_Store::prepare_php_file( $code, $title );
	}

	/**
	 * Write already-validated contents to a snippet file.
	 *
	 * @since 0.0.5
	 *
	 * @param string $slug    Validated snippet slug.
	 * @param string $type    File type: php, js, css, or html.
	 * @param string $content Storage-ready contents.
	 * @return array{filepath: string, hash: string|false}|string Result array, or error string on failure.
	 */
	private function write_prepared_file( $slug, $type, $content ) {
		// Keeps the existing dir while back-filling the deny files a snippet
		// written by an older build never got. Idempotent, and on the create
		// path it resolves to the dir minted moments earlier.
		Snippet_Store::prepare_snippet_dir( $slug, false );

		$filepath = Snippet_Store::snippet_file( $slug, $type );
		if ( ! Snippet_Store::is_safe_path( $filepath ) ) {
			return 'Refused unsafe write path.';
		}

		// .bak files are no longer needed — Snippet_Versions snapshots HEAD on
		// every code change (auto for diffs, manual via create_manual_version).
		if ( ! Snippet_Store::put_file( $filepath, $content ) ) {
			return 'Failed to write the snippet file to disk. Check filesystem permissions and available disk space on wp-content.';
		}
		Snippet_Store::invalidate_opcache( $filepath );

		return array(
			'filepath' => $filepath,
			'hash'     => $this->file_hash( $filepath ),
		);
	}

	/**
	 * Persist ONE manifest entry under the store lock, returning a
	 * ready-to-return Response::error array when the write fails (disk full /
	 * permissions / damaged manifest) so callers surface the failure instead
	 * of reporting a phantom success. Returns null on success.
	 *
	 * @param string                   $slug            Snippet slug.
	 * @param array<string,mixed>|null $entry           Entry to upsert, or null to remove the slug.
	 * @param string                   $failure_message Optional override for the failure copy — used
	 *                               by the delete paths, where files are removed
	 *                               from disk BEFORE the manifest write, so the
	 *                               generic "nothing was persisted" wording would
	 *                               be the inverse of the truth.
	 * @return array<string,mixed>|null Response::error array on failure, null on success.
	 */
	private function save_manifest_entry( string $slug, $entry, string $failure_message = '' ) {
		// One entry, written under the store's advisory lock. Writing a whole
		// pre-loaded manifest here lost concurrent writers' changes (the same
		// lost-update race with_lock() documents for the REST bulk delete) —
		// the mutation must be re-applied against the LOCKED read.
		$saved = Snippet_Store::with_lock(
			static function ( array &$manifest ) use ( $slug, $entry ) {
				if ( null === $entry ) {
					unset( $manifest[ $slug ] );
					return;
				}
				$manifest[ $slug ] = $entry;
			}
		);
		if ( $saved ) {
			return null;
		}
		return Response::error(
			'' !== $failure_message
				? $failure_message
				: 'Failed to save the snippet to disk, the change was not persisted. Check filesystem permissions, disk space, and that the snippets manifest is not corrupted.'
		);
	}

	// ── CRUD ────────────────────────────────────────────────────────────

	/**
	 * Build the wp-admin URL that opens a snippet in the ZIP AI editor.
	 *
	 * The snippets admin screen is a hash-routed SPA under
	 * options-general.php?page=zip-ai-snippets; the edit view lives at the
	 * `#/zip-ai/edit/{slug}` route (see src/snippets-page/App.jsx). Returned to
	 * the caller so it can surface a "open/edit" link after a write.
	 *
	 * @param string $slug Validated snippet slug.
	 * @return string Absolute admin edit URL.
	 */
	private function snippet_edit_url( $slug ) {
		return admin_url( 'options-general.php?page=zip-ai-snippets' ) . '#/zip-ai/edit/' . rawurlencode( $slug );
	}

	/**
	 * Create a snippet, or add a file type to an existing one.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function create_snippet( $input ) {
		$title = sanitize_text_field( Utils::to_str( $input['title'] ?? '' ) );
		$code  = Utils::to_str( $input['code'] ?? '' );
		$type  = Utils::to_str( $input['type'] ?? 'php' );

		if ( empty( $code ) ) {
			return Response::error( 'Code is required.' );
		}
		if ( ! in_array( $type, $this->get_types(), true ) ) {
			return Response::error( 'Invalid type. Use: ' . implode( ', ', $this->get_types() ) );
		}

		$manifest = Snippet_Store::load();
		$raw_slug = ! empty( $input['slug'] ) ? Utils::to_str( $input['slug'] ) : $this->slugify( $title ? $title : 'snippet' );
		$slug     = Snippet_Store::validate_slug( $raw_slug );

		if ( ! $slug ) {
			return Response::error( 'Invalid slug. Use only letters, numbers, and hyphens.' );
		}

		// create NEVER adopts an existing snippet. Slugs derive from titles,
		// so collisions are ordinary — and the old "add a file to it" branch
		// wrote agent files into the user's snippet; on an ENABLED snippet
		// the new code executed on the next request with no enable step.
		if ( isset( $manifest[ $slug ] ) ) {
			return Response::error( "Snippet '{$slug}' already exists. create never modifies an existing snippet: use the update action, or pass a different slug." );
		}
		if ( empty( $title ) ) {
			return Response::error( 'Title is required for new snippets.' );
		}

		$execution         = $this->sanitize_execution( $input['execution'] ?? array() );
		$conditions_result = $this->sanitize_conditions( $input['conditions'] ?? array() );
		if ( ! empty( $conditions_result['rejected'] ) ) {
			return Response::error( 'Targeting conditions rejected, nothing was saved: ' . implode( '; ', $conditions_result['rejected'] ) );
		}

		$snippet_title = $title;

		// Validate + wrap BEFORE minting a dir: a rejected create would
		// otherwise leave `base/<slug>-0.0.9xxx/` behind, with a fresh suffix
		// per retry and no manifest entry pointing at it.
		$content = $this->prepare_snippet_content( $type, $code, $snippet_title, Utils::to_str( $this->to_arr( $execution[ $type ] ?? array() )['hook'] ?? '' ) );
		if ( is_wp_error( $content ) ) {
			return Response::error( $content->get_error_message() );
		}

		// New snippets get a randomized on-disk dir name so the filesystem
		// path is not guessable from the title alone. Also (re)writes the
		// HTTP-deny protection files.
		$dir_name = Snippet_Store::prepare_snippet_dir( $slug, true );

		$result = $this->write_prepared_file( $slug, $type, $content );

		if ( is_string( $result ) ) {
			return Response::error( $result );
		}

		$entry = array(
			'title'           => $title,
			'dir'             => $dir_name, // randomized on-disk basename
			'files'           => array( $type ),
			'file_hashes'     => array( $type => $result['hash'] ),
			'enabled'         => false,
			'description'     => sanitize_text_field( Utils::to_str( $input['description'] ?? '' ) ),
			'execution'       => $execution,
			'conditions'      => $conditions_result['rows'],
			'created'         => current_time( 'mysql' ),
			'created_by'      => $this->current_user_id(),
			'updated_by_type' => 'agent',
		);

		// The unlocked existence probe above is fast-path UX; the AUTHORITATIVE
		// "create never adopts" guard runs INSIDE the manifest lock, where a
		// concurrent create of the same slug cannot race past it (both racers
		// used to pass the unlocked check and the second clobbered the first's
		// entry). On collision the manifest is left untouched; the just-minted
		// randomized dir is orphaned deliberately, cleanup here would have to
		// resolve paths through the WINNING entry's mapping and risks deleting
		// the survivor's files.
		$collided = false;
		$saved    = Snippet_Store::with_lock(
			static function ( array &$manifest ) use ( $slug, $entry, &$collided ) {
				if ( isset( $manifest[ $slug ] ) ) {
					$collided = true;
					return;
				}
				$manifest[ $slug ] = $entry;
			}
		);
		if ( $collided ) {
			return Response::error( "Snippet '{$slug}' already exists. create never modifies an existing snippet: use the update action, or pass a different slug." );
		}
		if ( ! $saved ) {
			return Response::error( 'Failed to save the snippet to disk, the change was not persisted. Check filesystem permissions, disk space, and that the snippets manifest is not corrupted.' );
		}

		// Snapshot the new version (agent author). Reason falls back to a
		// generic message so the version row carries useful intent.
		$ctx           = $this->agent_context();
		$ctx['reason'] = sanitize_text_field(
			Utils::to_str( $input['reason'] ?? 'Initial version' )
		);
		$ctx['manual'] = false;
		Snippet_Versions::create( $slug, $ctx );

		// Snippet_Versions::create rewrites the manifest to update
		// current_version_id / versions_count / last_version_at — reload so the
		// response payload reflects current state instead of a pre-version copy.
		$manifest = Snippet_Store::load();
		$saved    = $this->to_arr( $manifest[ $slug ] ?? array() );

		$label = 'created (disabled, call enable to activate)';

		// applied_fields lets the caller verify each mutating field actually
		// landed in the manifest. Without this, agents have no way to detect
		// silent drops (which is how the conditions-field regression went
		// unnoticed before).
		$applied = array( 'slug', 'type', 'files', 'title', 'description', 'execution', 'conditions' );

		$saved_conditions = $this->to_arr( $saved['conditions'] ?? array() );
		$secret_warnings  = Snippet_Lint::detect_secrets( $code );
		return Response::success(
			"Snippet '{$slug}' {$label}.",
			array(
				'slug'                => $slug,
				'edit_url'            => $this->snippet_edit_url( $slug ),
				'type'                => $type,
				'files'               => $saved['files'] ?? array(),
				'enabled'             => $saved['enabled'] ?? false,
				'secret_warnings'     => $secret_warnings,
				'execution'           => $saved['execution'] ?? null,
				'conditions'          => $saved_conditions,
				'evaluated_targeting' => Snippet_Store::describe_conditions( array_values( $saved_conditions ) ),
				'applied_fields'      => array_values( array_unique( $applied ) ),
			)
		);
	}

	/**
	 * List all snippets with normalized metadata.
	 *
	 * @return array<string,mixed> Response payload.
	 */
	private function list_snippets() {
		$manifest = Snippet_Store::load();
		$snippets = array();

		foreach ( $manifest as $slug => $meta ) {
			if ( '_version' === $slug ) {
				continue;
			}
			$normalized = Snippet_Store::normalize_snippet( $this->to_arr( $meta ) );
			$snippets[] = array(
				'slug'                => $slug,
				'title'               => $normalized['title'],
				'files'               => $normalized['files'],
				'enabled'             => $normalized['enabled'],
				'description'         => $normalized['description'],
				'execution'           => $normalized['execution'],
				'conditions'          => $normalized['conditions'],
				'evaluated_targeting' => Snippet_Store::describe_conditions( array_values( $this->to_arr( $normalized['conditions'] ) ) ),
			);
		}

		return Response::success(
			count( $snippets ) . ' snippets found.',
			array(
				'count'    => count( $snippets ),
				'snippets' => $snippets,
			)
		);
	}

	/**
	 * Load a snippet's metadata and file contents.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function get_snippet( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}

		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return Response::error( Snippet_Store::not_found_message( $slug ) );
		}

		$meta  = $this->to_arr( $manifest[ $slug ] );
		$files = $this->to_str_list( $meta['files'] ?? array() );
		$code  = array();

		foreach ( $files as $type ) {
			$filepath = Snippet_Store::snippet_file( $slug, $type );
			if ( file_exists( $filepath ) ) {
				$raw           = (string) file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$code[ $type ] = 'php' === $type ? Snippet_Store::unwrap_php( $raw ) : $raw;
			}
		}

		$saved_conditions = $this->to_arr( $meta['conditions'] ?? array() );
		$meta_title       = Utils::to_str( $meta['title'] ?? '' );
		return Response::success(
			"Snippet: {$meta_title}",
			array_merge(
				$meta,
				array(
					'slug'                => $slug,
					'code'                => $code,
					'evaluated_targeting' => Snippet_Store::describe_conditions( array_values( $saved_conditions ) ),
				)
			)
		);
	}

	/**
	 * Filter a remove_types list down to known file types.
	 *
	 * @param mixed $types Raw remove_types value from the caller.
	 * @return array<int,string> Valid file types to remove.
	 */
	private function sanitize_remove_types( $types ) {
		if ( ! is_array( $types ) ) {
			return array();
		}
		$types = array_filter( $types, 'is_string' );
		return array_values( array_unique( array_intersect( $this->get_types(), $types ) ) );
	}

	/**
	 * Remove file types from a snippet on disk and in its manifest entry.
	 *
	 * @param string              $slug  Validated snippet slug.
	 * @param array<string,mixed> $meta  Snippet manifest entry, mutated in place.
	 * @param array<int,string>   $types File types to remove.
	 * @return array<int,string> File types actually removed.
	 */
	private function remove_snippet_file_types( $slug, &$meta, array $types ) {
		$removed     = array();
		$files       = array_values( array_intersect( $this->get_types(), $this->to_str_list( $meta['files'] ?? array() ) ) );
		$file_hashes = $this->to_arr( $meta['file_hashes'] ?? array() );

		foreach ( $types as $type ) {
			if ( ! in_array( $type, $files, true ) ) {
				continue;
			}

			$filepath = Snippet_Store::snippet_file( $slug, $type );
			if ( Snippet_Store::is_safe_path( $filepath ) && file_exists( $filepath ) ) {
				wp_delete_file( $filepath );
				Snippet_Store::invalidate_opcache( $filepath );
			}

			$files = array_values( array_diff( $files, array( $type ) ) );
			unset( $file_hashes[ $type ] );
			$removed[] = $type;
		}

		$meta['files']       = $files;
		$meta['file_hashes'] = $file_hashes;

		return $removed;
	}

	/**
	 * Update a snippet's code, metadata, execution, or conditions.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function update_snippet( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}

		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return Response::error( Snippet_Store::not_found_message( $slug ) );
		}

		$meta              = $this->to_arr( $manifest[ $slug ] );
		$conditions_before = is_array( $meta['conditions'] ?? null ) ? $meta['conditions'] : array();
		$applied           = array(); // Tracks which fields the caller's payload actually mutated.
		$snapshot_needed   = false;
		$updated_type      = null;
		$remove_types      = $this->sanitize_remove_types( $input['remove_types'] ?? array() );

		// Fail-closed ordering (create already does this): every REJECTABLE
		// input is validated before the first byte hits disk. Previously a
		// payload carrying both code and unrepresentable conditions had the
		// new code live on disk (and a baseline version minted) by the time
		// the conditions rejection reported "nothing was saved", leaving the
		// manifest's file_hashes stale against the file.
		$conditions_update = null;
		if ( isset( $input['conditions'] ) && is_array( $input['conditions'] ) ) {
			$conditions_update = $this->sanitize_conditions( $input['conditions'] );
			if ( ! empty( $conditions_update['rejected'] ) ) {
				// Persisting fewer/looser constraints than asked widens where
				// live code runs, refuse the whole write instead.
				return Response::error( 'Targeting conditions rejected, nothing was saved: ' . implode( '; ', $conditions_update['rejected'] ) );
			}
		}

		if ( isset( $input['code'] ) && ! isset( $input['type'] ) ) {
			$files = array_values( array_intersect( $this->get_types(), $this->to_str_list( $meta['files'] ?? array() ) ) );
			if ( empty( $files ) ) {
				$files = $this->detect_files( $slug );
			}
			if ( 1 === count( $files ) ) {
				$input['type'] = $files[0];
			} else {
				return Response::error(
					sprintf(
						'Type is required when updating code. Pass one of: %s.',
						implode( ', ', $this->get_types() )
					)
				);
			}
		}

		$has_code_update = isset( $input['code'] ) && isset( $input['type'] );
		$update_type     = $has_code_update ? Utils::to_str( $input['type'] ) : '';
		if ( $has_code_update ) {
			if ( ! in_array( $update_type, $this->get_types(), true ) ) {
				return Response::error( 'Invalid type.' );
			}
			if ( empty( $input['replace_files'] ) && in_array( $update_type, $remove_types, true ) ) {
				return Response::error( 'remove_types cannot include the same type being updated.' );
			}
		}

		// The replace_files/remove_types rejections are pre-write checks too:
		// each used to run AFTER write_snippet_file, so a refused payload left
		// the new code live with stale manifest hashes, the same defect the
		// conditions hoist above closes.
		if ( ! empty( $input['replace_files'] ) ) {
			if ( ! $has_code_update ) {
				return Response::error( 'replace_files requires code and type so the replacement file is explicit.' );
			}
			$remove_types = array_values( array_diff( array_intersect( $this->get_types(), $this->to_str_list( $meta['files'] ?? array() ) ), array( $update_type ) ) );
		}

		if ( $has_code_update && in_array( $update_type, $remove_types, true ) ) {
			return Response::error( 'remove_types cannot include the same type being updated.' );
		}

		if ( ! empty( $remove_types ) ) {
			// Projected file set AFTER this update: current files, plus the
			// (possibly new) updated type, minus the removals — computed
			// BEFORE any write so an empty result refuses while nothing has
			// changed yet.
			$projected = array_values( array_intersect( $this->get_types(), $this->to_str_list( $meta['files'] ?? array() ) ) );
			// `$update_type` is '' exactly when there is no code update (see its
			// assignment), and a real type is always a `get_types()` member, so
			// this is the same test as `$has_code_update` without re-deriving
			// the fact a second way.
			if ( '' !== $update_type && ! in_array( $update_type, $projected, true ) ) {
				$projected[] = $update_type;
			}
			if ( empty( array_diff( $projected, $remove_types ) ) ) {
				return Response::error( 'Refusing to remove every file from a snippet via update. Use delete action instead.' );
			}
		}

		if ( $has_code_update ) {
			$type = $update_type;

			// A snippet that predates versioning (imported / hand-written) has
			// no snapshot at all — the bytes about to be replaced would be
			// unrecoverable. Capture the pre-write baseline once, BEFORE the
			// first destructive write.
			if ( empty( $meta['current_version_id'] ) ) {
				$baseline           = $this->agent_context();
				$baseline['reason'] = 'Baseline before first agent edit';
				$baseline['manual'] = false;
				Snippet_Versions::create( $slug, $baseline );
				$manifest = Snippet_Store::load();
				$meta     = $this->to_arr( $manifest[ $slug ] ?? $meta );
			}

			$execution      = $this->sanitize_execution( $input['execution'] ?? array(), $this->to_arr( $meta['execution'] ?? array() ) );
			$execution_hook = Utils::to_str( $this->to_arr( $execution[ $type ] ?? array() )['hook'] ?? '' );
			$result         = $this->write_snippet_file( $slug, $type, Utils::to_str( $input['code'] ), Utils::to_str( $meta['title'] ?? '' ), $execution_hook );
			if ( is_string( $result ) ) {
				return Response::error( $result );
			}

			$files       = array_values( array_intersect( $this->get_types(), $this->to_str_list( $meta['files'] ?? array() ) ) );
			$file_hashes = $this->to_arr( $meta['file_hashes'] ?? array() );
			if ( ! in_array( $type, $files, true ) ) {
				// A new type on an existing snippet is ADDITIVE. Deleting the
				// old file here (the former single-file "conversion" branch)
				// destroyed the user's only file on inferred intent — file
				// removal happens ONLY through the explicit opt-ins
				// (`replace_files` / `remove_types`).
				$files[]       = $type;
				$meta['files'] = $files;
				$applied[]     = 'files';
			}
			$file_hashes[ $type ] = $result['hash'];
			$meta['file_hashes']  = $file_hashes;
			$applied[]            = 'code';
			$snapshot_needed      = true;
			$updated_type         = $type;
		}

		if ( ! empty( $remove_types ) ) {
			$removed = $this->remove_snippet_file_types( $slug, $meta, $remove_types );
			if ( ! empty( $removed ) ) {
				$applied[]       = 'files';
				$snapshot_needed = true;
			}
		}

		if ( isset( $input['title'] ) ) {
			$title = sanitize_text_field( Utils::to_str( $input['title'] ) );
			if ( ( $meta['title'] ?? '' ) !== $title ) {
				$snapshot_needed = true;
			}
			$meta['title'] = $title;
			$applied[]     = 'title';
		}
		if ( isset( $input['description'] ) ) {
			$description = sanitize_text_field( Utils::to_str( $input['description'] ) );
			if ( ( $meta['description'] ?? '' ) !== $description ) {
				$snapshot_needed = true;
			}
			$meta['description'] = $description;
			$applied[]           = 'description';
		}
		// Allow execution + conditions to be updated via the agent. Without
		// these branches the ability silently ignored targeting changes —
		// agent thought the call succeeded while the manifest stayed empty.
		if ( isset( $input['execution'] ) && is_array( $input['execution'] ) ) {
			// Merge over the PERSISTED execution: a partial payload ("move the
			// CSS to wp_head") must not reset the other file types' bindings.
			$execution = $this->sanitize_execution( $input['execution'], $this->to_arr( $meta['execution'] ?? array() ) );
			if ( ( $meta['execution'] ?? array() ) !== $execution ) {
				$snapshot_needed = true;
			}
			$meta['execution'] = $execution;
			$applied[]         = 'execution';
		}
		if ( null !== $conditions_update ) {
			// Sanitized and reject-checked up front, before any write.
			if ( ( $meta['conditions'] ?? array() ) !== $conditions_update['rows'] ) {
				$snapshot_needed = true;
			}
			$meta['conditions'] = $conditions_update['rows'];
			$applied[]          = 'conditions';
		}

		// Reject no-op updates so callers don't silently treat a missed
		// payload (e.g. a typo'd field name like `condition` instead of
		// `conditions`) as a successful mutation.
		if ( empty( $applied ) ) {
			return Response::error(
				'No mutating field provided. Pass at least one of: code (with type), title, description, execution, conditions, remove_types.'
			);
		}

		$meta['updated']         = current_time( 'mysql' );
		$meta['updated_by']      = $this->current_user_id();
		$meta['updated_by_type'] = 'agent';

		$save_error = $this->save_manifest_entry( $slug, $meta );
		if ( null !== $save_error ) {
			return $save_error;
		}

		if ( $snapshot_needed ) {
			$ctx           = $this->agent_context();
			$ctx['reason'] = sanitize_text_field( Utils::to_str( $input['reason'] ?? 'Updated snippet via ZIP AI: ' . implode( ', ', $applied ) ) );
			$ctx['manual'] = false;
			Snippet_Versions::create( $slug, $ctx );
			// Reload so $meta reflects manifest fields rewritten by Versions::create
			// (current_version_id, versions_count, last_version_at).
			$manifest = Snippet_Store::load();
			$meta     = $this->to_arr( $manifest[ $slug ] ?? $meta );
		}

		$saved_conditions = $this->to_arr( $meta['conditions'] ?? array() );
		$secret_warnings  = isset( $input['code'] ) ? Snippet_Lint::detect_secrets( Utils::to_str( $input['code'] ) ) : array();
		return Response::success(
			sprintf( "Snippet '%s' updated. Applied: %s.", $slug, implode( ', ', $applied ) ),
			array(
				'secret_warnings'            => $secret_warnings,
				'slug'                       => $slug,
				'edit_url'                   => $this->snippet_edit_url( $slug ),
				'applied_fields'             => $applied,
				'execution'                  => $meta['execution'] ?? null,
				'conditions'                 => $saved_conditions,
				'evaluated_targeting'        => Snippet_Store::describe_conditions( array_values( $saved_conditions ) ),
				'evaluated_targeting_before' => Snippet_Store::describe_conditions( array_values( $conditions_before ) ),
			)
		);
	}

	/**
	 * Delete a snippet, or a single file type from it.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed> Response payload.
	 */
	private function delete_snippet( $input ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}

		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return Response::error( Snippet_Store::not_found_message( $slug ) );
		}

		$entry = $this->to_arr( $manifest[ $slug ] );

		if ( ! empty( $input['dry_run'] ) ) {
			$dry_title = Utils::to_str( $entry['title'] ?? '' );
			return Response::success( "Dry run — would delete: {$dry_title}" );
		}

		// Delete specific file type if specified.
		if ( ! empty( $input['type'] ) ) {
			$type     = Utils::to_str( $input['type'] );
			$filepath = Snippet_Store::snippet_file( $slug, $type );

			if ( file_exists( $filepath ) ) {
				wp_delete_file( $filepath );
			}

			$files       = array_values( array_diff( $this->to_str_list( $entry['files'] ?? array() ), array( $type ) ) );
			$file_hashes = $this->to_arr( $entry['file_hashes'] ?? array() );
			unset( $file_hashes[ $type ] );
			$entry['file_hashes'] = $file_hashes;
			$entry['files']       = $files;
			$entry['updated']     = current_time( 'mysql' );

			if ( empty( $files ) ) {
				$this->remove_snippet_dir( $slug );
				$save_error = $this->save_manifest_entry( $slug, null, "Snippet '{$slug}' files were removed, but the snippet list could not be updated (manifest write failed). It may still appear until the delete is retried." );
				if ( null !== $save_error ) {
					return $save_error;
				}
				return Response::success( "Snippet '{$slug}' deleted (last file removed)." );
			}

				$save_error = $this->save_manifest_entry( $slug, $entry, "The {$type} file was removed, but the snippet list could not be updated (manifest write failed). Retry to reconcile it." );
			if ( null !== $save_error ) {
				return $save_error;
			}
				$ctx           = $this->agent_context();
				$ctx['reason'] = sanitize_text_field( Utils::to_str( $input['reason'] ?? "Removed {$type} file via ZIP AI" ) );
				$ctx['manual'] = false;
				Snippet_Versions::create( $slug, $ctx );
				return Response::success( "Removed {$type} file from snippet '{$slug}'." );
		}

		$this->remove_snippet_dir( $slug );
		$save_error = $this->save_manifest_entry( $slug, null, "Snippet '{$slug}' files were removed, but the snippet list could not be updated (manifest write failed). It may still appear until the delete is retried." );
		if ( null !== $save_error ) {
			return $save_error;
		}
		// Clear the executor's evaluation ring buffer for this slug so a
		// re-created snippet with the same slug doesn't surface stale traces.
		delete_transient( 'zip_ai_snippets_trace_' . $slug );
		// Drop the cached dir lookup so a re-created snippet with the same
		// slug doesn't accidentally inherit the deleted snippet's dir name
		// from the in-process cache.
		Snippet_Store::clear_dir_cache();

		return Response::success( "Snippet '{$slug}' deleted." );
	}

	/**
	 * Remove a snippet's directory tree from disk.
	 *
	 * @param string $slug Validated snippet slug.
	 * @return void
	 */
	private function remove_snippet_dir( $slug ) {
		$dir = Snippet_Store::snippet_dir( $slug );
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// Snippet dirs hold subdirs (e.g. `.versions/{id}/snippet.{ext}` written
		// by Snippet_Versions). The old top-level scan skipped those and tripped
		// `rmdir(): Directory not empty`. Walk the tree bottom-up so every file
		// and child directory is gone before we remove the parent. Don't follow
		// symlinks — unlink the link itself instead of recursing into its target.
		self::recursive_delete( $dir );
	}

	/**
	 * Recursively delete a directory and all of its contents.
	 * Skips symlink traversal so a stray link can never escape the snippet root.
	 *
	 * @param string $dir Directory path to delete.
	 * @return void
	 */
	private static function recursive_delete( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			if ( is_link( $dir ) || is_file( $dir ) ) {
				wp_delete_file( $dir );
			}
			return;
		}
		$entries = @scandir( $dir );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $dir . '/' . $entry;
				if ( is_link( $path ) || is_file( $path ) ) {
					wp_delete_file( $path );
					continue;
				}
				if ( is_dir( $path ) ) {
					self::recursive_delete( $path );
				}
			}
		}
		Snippet_Store::delete_dir( $dir );
	}

	/**
	 * Toggle a snippet's enabled state.
	 *
	 * SECURITY. This is an accepted residual risk. Enabling a `php` snippet runs
	 * author-written PHP on its hook. See the `include` in
	 * Snippet_Executor::include_php_snippet. This means it can run arbitrary code.
	 * There is no server-side human-approval gate here. This is intentional. The
	 * human-in-the-loop is the client's approval card, because writes require
	 * approval. This ability is `is_destructive`. Also `enable` is not in
	 * `read_only_actions`. So the client shows an approval the user must accept
	 * before this call. A write cannot classify itself as a read and skip the
	 * card. This depends on the trust model. The caller is the connected admin.
	 * This admin owns the App Password and has `manage_options`. This admin can
	 * already author and run PHP. The residual risk is a prompt-injected caller
	 * that bypasses its own approval. This risk is ACCEPTED for the current threat
	 * model. If the threat model gets stricter, gate php-enable on a human-only
	 * surface the caller cannot reach. That surface is the Snippets admin page
	 * with a cookie and nonce.
	 *
	 * @param array<string,mixed> $input  Action input.
	 * @param bool                $enable True to enable, false to disable.
	 * @return array<string,mixed> Response payload.
	 */
	private function toggle_snippet( $input, $enable ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $input['slug'] ?? '' ) );
		if ( ! $slug ) {
			return Response::error( 'Invalid slug.' );
		}

		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return Response::error( Snippet_Store::not_found_message( $slug ) );
		}

		$entry               = $this->to_arr( $manifest[ $slug ] );
		$entry['enabled']    = $enable;
		$entry['updated']    = current_time( 'mysql' );
		$entry['updated_by'] = $this->current_user_id();

		$save_error = $this->save_manifest_entry( $slug, $entry );
		if ( null !== $save_error ) {
			return $save_error;
		}

		$label = $enable ? 'enabled' : 'disabled';
		return Response::success( sprintf( "Snippet '%s' %s.", Utils::to_str( $entry['title'] ?? '' ), $label ) );
	}
}
