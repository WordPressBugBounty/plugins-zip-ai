<?php
/**
 * React Manager - Renders chat assistant directly in WordPress (no iframe)
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\React;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ZipAI\MCP\Classes\Abilities\Zipai\System\PluginResolver;
use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Core\Product_Context;
use ZipAI\MCP\Classes\Traits\Enqueue;

/**
 * The React_Manager Class.
 * Handles rendering of the React chat assistant directly in WordPress.
 */
class React_Manager {

	use Enqueue;

	/**
	 * Constructor of this class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		// ZIP AI assistant is admin-only — it is NOT enqueued or rendered on the
		// public frontend, so no floating trigger appears on the live site.
		$this->enqueue_scripts_admin();
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_footer', array( $this, 'render_container' ) );

		// Collapse WP sidebar and tag body on the dedicated full-page screen.
		if ( is_admin() ) {
			$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'zip-ai-assistant' === $page ) {
				add_filter( 'admin_body_class', array( $this, 'add_fullpage_body_classes' ) );
				// Remove all admin notices on the fullpage assistant screen.
				add_action( 'in_admin_header', array( $this, 'remove_admin_notices' ) );
			}
		}
	}

	/**
	 * Add body classes for the dedicated full-page assistant screen.
	 * `folded`                – collapses WP admin sidebar to icon-only mode.
	 * `zip-ai-fullpage-page` – lets CSS target this page precisely.
	 *
	 * @since 1.0.0
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public function add_fullpage_body_classes( $classes ) {
		return $classes . ' folded zip-ai-fullpage-page';
	}

	/**
	 * Remove all admin notices on the fullpage assistant screen.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function remove_admin_notices() {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	}

	/**
	 * Register dedicated full-page assistant screen in WP admin.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_options_page(
			__( 'ZIP AI Assistant', 'zip-ai' ),
			__( 'ZIP AI Assistant', 'zip-ai' ),
			'manage_options',
			'zip-ai-assistant',
			array( $this, 'render_fullpage_screen' )
		);
	}

	/**
	 * Check if we should use source (non-minified) scripts.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function use_source_scripts() {
		return ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ||
				( defined( 'ZIPAI_MCP_DEBUG' ) && ZIPAI_MCP_DEBUG );
	}

	/**
	 * Admin enqueue callback (registered by trait).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		$this->enqueue_all_assets();
	}

	/**
	 * Enqueue all scripts and styles for the React chat assistant.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function enqueue_all_assets() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$use_source = $this->use_source_scripts();
		$auth_url   = $this->get_auth_url();

		// ── Bridge scripts (tool hooks, context, bridge host) ──
		if ( $use_source ) {
			$this->enqueue_source_scripts();
		} else {
			$this->enqueue_minified_scripts();
		}

		// ── React app bundle (read .asset.php for React dependencies) ──
		$asset_file = $this->build_path . 'js/dist/chat-assistant.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => ZIPAI_MCP_VERSION,
		);
		$asset      = is_array( $asset ) ? $asset : array();

		$asset_deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? array_values( array_filter( $asset['dependencies'], 'is_string' ) ) : array();
		$asset_version = isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : ZIPAI_MCP_VERSION;
		$react_deps    = array_merge( $asset_deps, array( $this->enqueue_prefix . '-bridge-host' ) );

		$this->script_operations(
			'chat-assistant',
			$this->build_url . 'js/dist/chat-assistant.js',
			$react_deps,
			array(),
			$asset_version
		);

		// ── React app styles ──
		// Version with the same build hash as the JS bundle (not the static
		// plugin version) so a rebuild that doesn't bump ZIPAI_MCP_VERSION still
		// busts the CSS cache. Otherwise `?ver=<plugin version>` stays identical
		// across builds and browsers/CDNs serve a stale stylesheet against fresh
		// JS — newly added utility classes go missing until the version bumps.
		$this->style_operations(
			'chat-assistant',
			$this->build_url . 'css/dist/chat-assistant.css',
			array(),
			$asset_version
		);

		// ── Gutenberg editor plugin (native post/page editor only) ──
		// Uses the same narrowed gate as `isBlockEditor` so the editor RPC
		// handlers/sidebar plugin never load on custom block-editor screens
		// (e.g. SureCart's page editor, the Site Editor).
		if ( $this->is_block_editor_screen() ) {
			$editor_file = $use_source ? 'js/editor/editor-plugin.js' : 'js/dist/zip-ai-editor.min.js';
			// In production the quickedit.js sibling is concatenated into this
			// bundle, so the handle must declare quickedit's deps too
			// (wp-compose/wp-block-editor/wp-hooks/wp-api-fetch) — not rely on
			// wp-editor's transitive graph. Matches the dev enqueue below.
			$this->script_operations(
				'editor-plugin',
				$this->build_url . $editor_file,
				array( 'wp-plugins', 'wp-element', 'wp-i18n', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-compose', 'wp-block-editor', 'wp-hooks', 'wp-api-fetch' )
			);

			// ZIP AI Quick Edit block-toolbar popover. In source/dev mode it is a
			// separate sibling script; the production build concatenates it into
			// zip-ai-editor.min.js via Gruntfile's editor/**\/*.js glob, so it is
			// only enqueued explicitly here for $use_source.
			if ( $use_source ) {
				$this->script_operations(
					'editor-quickedit',
					$this->build_url . 'js/editor/quickedit.js',
					array( 'wp-element', 'wp-i18n', 'wp-components', 'wp-compose', 'wp-block-editor', 'wp-hooks', 'wp-data', 'wp-api-fetch' )
				);
			}
		}

		// ── Localize bridge config ──
		$this->localize_script(
			'tool-hooks',
			'zipwpIframeConfig',
			array(
				'nonce'            => wp_create_nonce( 'zip_ai_iframe' ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'displayMode'      => $this->is_fullpage_screen() ? 'fullpage' : 'sidebar',
				'adminHomeUrl'     => admin_url(),
				'userId'           => get_current_user_id(),
				'authUrl'          => $auth_url,
				'websiteContext'   => array(
					'site_url'     => get_site_url(),
					'admin_url'    => admin_url(),
					'site_title'   => get_bloginfo( 'name' ),
					'site_tagline' => get_bloginfo( 'description' ),
					'language'     => get_bloginfo( 'language' ),
					'timezone'     => wp_timezone_string(),
					'date_format'  => get_option( 'date_format' ),
					'time_format'  => get_option( 'time_format' ),
					'is_multisite' => is_multisite(),
				),
				'pageContext'      => $this->get_current_page_context(),
				'activeProduct'    => Product_Context::detect(),
				'isBlockEditor'    => $this->is_block_editor_screen(),
				'isPostEditScreen' => $this->is_post_edit_screen(),
				'themeContext'     => array(
					'color_palette' => $this->get_theme_color_palette(),
				),
				'installedPlugins' => $this->get_installed_plugins_versions(),
				'setupGate'        => $this->get_setup_gate(),
			)
		);

		// ── Localize React app config ──
		// `token` — Sanctum credit token, sent as `Authorization: Bearer <token>`
		// for server API auth.
		//
		// The WordPress Application Password header is INTENTIONALLY NOT
		// included here. It used to be emitted as `wpAuthorizationHeader`
		// (pre-built `Basic <b64>`) and forwarded by React to the server as
		// `X-Wp-Authorization` on every chat call — which placed the raw
		// Basic credential into the inline JS where any other script on the
		// admin page could read `window.ZIPAI_CONFIG.*`. The credential is now
		// delivered server-to-server and read from the issuing Sanctum token's
		// encrypted meta at turn time.

		// Brand context (business type/tone/description) so the chat's colour
		// picker can generate ON-BRAND palettes. Stored by the design system as
		// the `zip_ai_brand_context` option; may be a JSON string or an array.
		$brand_context = get_option( 'zip_ai_brand_context', array() );
		if ( is_string( $brand_context ) ) {
			$brand_context = json_decode( $brand_context, true );
		}
		if ( ! is_array( $brand_context ) ) {
			$brand_context = array();
		}

		// Free/guest temp-site expiry (ISO-8601 UTC). The zipwp-client mu-plugin
		// stores it in the `zipwp_site_data` option and we suppress ZipWP's own
		// admin-bar countdown when ZIP AI owns the surface, so the assistant
		// surfaces the timer itself. Absent on permanent/paid sites, and
		// suppressed once the site is reserved (no longer expiring).
		$zipwp_site_data = get_option( 'zipwp_site_data' );
		$expire_at       = ( is_array( $zipwp_site_data ) && empty( $zipwp_site_data['reserve'] ) && ! empty( $zipwp_site_data['expire_at'] ) )
			? $zipwp_site_data['expire_at']
			: null;

		$this->localize_script(
			'chat-assistant',
			'ZIPAI_CONFIG',
			array(
				// Turns on the bridge/apply-change console mirror (wp-bridge-host.js,
				// apply-change/handler.js). Those log lines were already written and
				// gated on this flag, but nothing ever set it — so the whole tracing
				// surface has been dead. Follows WP_DEBUG; override with the filter
				// for a UAT run on a site that isn't in debug mode.
				'debug'               => (bool) apply_filters( 'zipai_frontend_debug', defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'apiUrl'              => rtrim( ZIPAI_MCP_CREDIT_SERVER_API, '/' ),
				// Server base URL for direct calls (e.g. inline-edit). Set via the
				// ZIPAI_BRAIN_URL constant (defined in loader.php); override in
				// wp-config.php.
				'brainUrl'            => rtrim( ZIPAI_BRAIN_URL, '/' ),
				'token'               => Helper::get_decrypted_auth_token(),
				'isAuthenticated'     => Helper::is_authorized(),
				// Per-layout import impact, from the SAME source the MCP
				// `zipai/import-html` ability discloses to AI clients — so the
				// panel and an agent can never describe the same import
				// differently. Sent at load: no per-selection request, one copy.
				'importImpact'        => \ZipAI\MCP\Classes\Core\Import_Impact::all(),
				// The Connection screen's REST routes are `manage_options`-only, so
				// the menu entry is hidden for anyone who would only get a
				// permission error after clicking it.
				'canManageConnection' => current_user_can( 'manage_options' ),
				'displayMode'         => $this->is_fullpage_screen() ? 'fullpage' : 'sidebar',
				'fullPageUrl'         => admin_url( 'options-general.php?page=zip-ai-assistant' ),
				'adminHomeUrl'        => admin_url(),
				'userId'              => get_current_user_id(),
				'domain'              => wp_parse_url( home_url(), PHP_URL_HOST ),
				'site_url'            => home_url(),
				'user'                => array(
					'id'    => get_current_user_id(),
					'email' => Helper::get_setting( 'user_email', '' ),
					'name'  => Helper::get_setting( 'user_name', '' ),
				),
				'isFreshSite'         => (bool) get_option( 'fresh_site', false ),
				'expireAt'            => $expire_at,
				// Brand context for on-brand palette generation (colour picker).
				'brandContext'        => $brand_context,
				'nonce'               => wp_create_nonce( 'zip_ai_iframe' ),
				// wp_rest nonce — required by browser-side code that calls WP
				// core REST endpoints using the user's session cookie (e.g.
				// wp-bridge-host.js). Without this, calls from admin pages
				// that don't auto-enqueue `wp-api-request` (plugins.php,
				// themes.php, etc.) fail with `rest_cookie_invalid_nonce`.
				'restNonce'           => wp_create_nonce( 'wp_rest' ),
				'restUrl'             => esc_url_raw( rest_url() ),
				// 'updates' nonce + admin-ajax URL — required by the setup-gate
				// install flow (SetupGateCard.jsx), which calls WordPress core's
				// `install-plugin` / `install-theme` admin-ajax actions directly
				// using the user's session. Core enqueues this nonce as
				// `_wpUpdatesSettings.ajax_nonce` only on plugin/theme admin
				// screens, so we localize it here for every admin page where the
				// ZipWP chat loads. (Theme/plugin lifecycle MCP tools now run
				// server-side and do not use this.)
				'updatesNonce'        => wp_create_nonce( 'updates' ),
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'authUrl'             => $auth_url,
			)
		);
	}

	/**
	 * Enqueue individual source scripts for development/debugging.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function enqueue_source_scripts() {
		// Layout/appearance SSOT (window.ZIPWP_LAYOUT) — a dependency of
		// popover-drag + bridge-host below, so it loads before every consumer
		// (and before the React bundle, which depends on bridge-host).
		$this->script_operations(
			'layout-config',
			$this->build_url . 'js/core/layout-config.js',
			array()
		);

		$this->script_operations(
			'tool-hooks',
			$this->build_url . 'js/core/tool-hooks-registry.js',
			array()
		);

		// tool-context-provider-registry.js was removed in the page-delivery refactor.
		// Only enqueue if it still exists on disk; bridge-host's dependency on it
		// is stripped below when missing.
		$context_registry_path = $this->build_path . 'js/core/tool-context-provider-registry.js';
		$has_context_registry  = file_exists( $context_registry_path );
		if ( $has_context_registry ) {
			$this->script_operations(
				'tool-context-registry',
				$this->build_url . 'js/core/tool-context-provider-registry.js',
				array()
			);
		}

		$this->script_operations(
			'block-context-picker',
			$this->build_url . 'js/core/block-context-picker.js',
			array( $this->enqueue_prefix . '-tool-hooks' )
		);

		$bridge_deps = array(
			$this->enqueue_prefix . '-tool-hooks',
			$this->enqueue_prefix . '-block-context-picker',
			$this->enqueue_prefix . '-layout-config',
		);
		if ( $has_context_registry ) {
			$bridge_deps[] = $this->enqueue_prefix . '-tool-context-registry';
		}
		$this->script_operations(
			'popover-drag',
			$this->build_url . 'js/core/popover-drag.js',
			array( $this->enqueue_prefix . '-layout-config' )
		);

		$bridge_deps[] = $this->enqueue_prefix . '-popover-drag';

		// Pure js_rpc dispatch-dedup decision (B-1 / P5) — a bridge dependency so
		// the unit-tested helper (core/rpc-dedup.js) is loaded before executeTools
		// runs. No deps of its own.
		$this->script_operations(
			'rpc-dedup',
			$this->build_url . 'js/core/rpc-dedup.js',
			array()
		);
		$bridge_deps[] = $this->enqueue_prefix . '-rpc-dedup';

		// Tool utility modules (utils.js) define globals the bridge + React app
		// depend on — notably window.zipwpMcpSpectraUtils, which EditorContext
		// uses to serialize the selected block. Without it the selection carries
		// no text and the quick-edit toolbar never renders. The grunt production
		// bundle concatenates these ahead of the bridge (Gruntfile `main`); source
		// mode must load them ahead of bridge-host the same way. Globbed (not a
		// hardcoded filename) so a new tools/<ns>/utils.js auto-loads.
		$tool_utils = glob( $this->build_path . 'js/tools/*/utils.js' );
		$tool_utils = $tool_utils ? $tool_utils : array();
		foreach ( $tool_utils as $utils_path ) {
			$tool_slug = basename( dirname( $utils_path ) );
			$this->script_operations(
				"tool-{$tool_slug}-utils",
				$this->build_url . "js/tools/{$tool_slug}/utils.js",
				array( $this->enqueue_prefix . '-tool-hooks' )
			);
			$bridge_deps[] = $this->enqueue_prefix . "-tool-{$tool_slug}-utils";
		}

		$this->script_operations(
			'bridge-host',
			$this->build_url . 'js/core/wp-bridge-host.js',
			$bridge_deps
		);

		// Vibe Editing v2 — browser-native editor tools (Pattern A: server-side
		// tool declaration + this JS handler, no PHP ability). Each handler
		// self-registers with the bridge via window.zipwpMcp.registerTool, so
		// the only dependency is bridge-host. Globbed across the whole editor/
		// namespace, so every tool added there auto-loads — no per-tool PHP
		// edit. In production the grunt bundle (tools/**/handler.js) already
		// includes these; this source-mode branch is the SCRIPT_DEBUG path.
		// Shared editor utilities — loaded BEFORE the handlers that consume them.
		// blockFingerprint (conflict-fence hash) + currentPostId live here as the
		// single source of truth for both get-context and apply-change, so the two
		// can never drift. (In production the grunt bundle concatenates
		// tools/**\/*-utils.js ahead of handler.js, so order holds there too.)
		$this->script_operations(
			'editor-shared-utils',
			$this->build_url . 'js/tools/editor/shared/editor-shared-utils.js',
			array( $this->enqueue_prefix . '-bridge-host' )
		);
		$editor_handlers = glob( $this->build_path . 'js/tools/editor/*/handler.js' );
		$editor_handlers = $editor_handlers ? $editor_handlers : array();
		foreach ( $editor_handlers as $handler_path ) {
			$tool_slug = basename( dirname( $handler_path ) );
			$this->script_operations(
				"editor-{$tool_slug}-handler",
				$this->build_url . "js/tools/editor/{$tool_slug}/handler.js",
				array(
					$this->enqueue_prefix . '-bridge-host',
					$this->enqueue_prefix . '-editor-shared-utils',
				)
			);
		}
	}

	/**
	 * Enqueue combined minified script for production.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function enqueue_minified_scripts() {
		$this->script_operations(
			'tool-hooks',
			$this->build_url . 'js/dist/zip-ai.min.js',
			array()
		);

		// Tool context registry is bundled in the same file — virtual handle.
		$this->register_script( 'tool-context-registry', '', array( $this->enqueue_prefix . '-tool-hooks' ) );
		$this->enqueue_script( 'tool-context-registry' );

		// Bridge host is also bundled — virtual handle for dependency chain.
		$this->register_script( 'bridge-host', '', array( $this->enqueue_prefix . '-tool-hooks' ) );
		$this->enqueue_script( 'bridge-host' );
	}

	/**
	 * Get current page/post context from PHP.
	 *
	 * @since 1.0.0
	 * @return array<string, int|string|null>
	 */
	private function get_current_page_context() {
		global $post;

		$context = array(
			'post_id'     => null,
			'post_type'   => null,
			'post_title'  => null,
			'post_status' => null,
		);

		if ( $post instanceof \WP_Post ) {
			$context['post_id']     = $post->ID;
			$context['post_type']   = $post->post_type;
			$context['post_title']  = $post->post_title;
			$context['post_status'] = $post->post_status;
			return $context;
		}

		if ( is_admin() ) {
			$post_id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $post_id ) {
				$admin_post = get_post( $post_id );
				if ( $admin_post instanceof \WP_Post ) {
					$context['post_id']     = $admin_post->ID;
					$context['post_type']   = $admin_post->post_type;
					$context['post_title']  = $admin_post->post_title;
					$context['post_status'] = $admin_post->post_status;
				}
			}
		}

		return $context;
	}

	/**
	 * Authoritative server-side check for whether the current screen is the
	 * NATIVE WordPress post/page editor (post.php / post-new.php, screen base
	 * 'post'). The JS bridge falls back to this when `wp.data` /
	 * `core/block-editor` are not yet initialized at iframe boot — without
	 * this flag the server may resolve `is_block_editor=false` on the user's
	 * first turn and surface dashboard-only tools (e.g. spawning a new page
	 * when one is already open).
	 *
	 * The `base === 'post'` guard is deliberate: custom admin screens that
	 * embed `@wordpress/block-editor` — e.g. SureCart's page editor, or the
	 * Site Editor — also report `is_block_editor() === true`, but Editor Mode
	 * tools only operate on a real WP post, so those screens must NOT count.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_block_editor_screen() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return false;
		}
		return $screen->is_block_editor();
	}

	/**
	 * Whether the current screen is the classic post edit action (post.php?action=edit).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_post_edit_screen() {
		global $pagenow;
		if ( ! is_admin() ) {
			return false;
		}
		if ( 'post.php' !== $pagenow ) {
			return false;
		}
		$action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'edit' === $action;
	}

	/**
	 * Get authentication URL with CSRF protection for ZipWP OAuth callback.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function get_auth_url() {
		$transient_key = 'zip_ai_oauth_state_' . get_current_user_id();

		// Reuse existing state if still valid — prevents overwriting during auth popup flow.
		$state = get_transient( $transient_key );
		if ( empty( $state ) ) {
			$state = wp_generate_password( 32, false );
			set_transient( $transient_key, $state, 10 * MINUTE_IN_SECONDS );
		}

		$redirect_url = add_query_arg(
			array(
				'nonce'       => wp_create_nonce( 'zip_ai_auth_nonce' ),
				'state'       => $state,
				'zip-ai-auth' => 'true',
			),
			admin_url( 'options-general.php?page=zip-ai-assistant' )
		);

		$auth_middleware = ZIPAI_MCP_MIDDLEWARE;

		$auth_url = add_query_arg(
			array(
				'type'         => 'token',
				'redirect_url' => rawurlencode( $redirect_url ),
				'state'        => $state,
				'source'       => 'zip-ai',
			),
			$auth_middleware
		);

		return $auth_url;
	}

	/**
	 * Get theme color palette formatted as CSS variables.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	private function get_theme_color_palette() {
		if ( ! function_exists( 'astra_get_palette_colors' ) ) {
			return array();
		}

		$palette_data = astra_get_palette_colors();
		if ( ! is_array( $palette_data ) ) {
			return array();
		}

		$current_palette = is_string( $palette_data['currentPalette'] ?? null ) ? $palette_data['currentPalette'] : '';
		$palettes        = is_array( $palette_data['palettes'] ?? null ) ? $palette_data['palettes'] : array();

		if ( '' === $current_palette || empty( $palettes[ $current_palette ] ) ) {
			return array();
		}

		$colors = $palettes[ $current_palette ];
		if ( ! is_array( $colors ) ) {
			return array();
		}

		$formatted_palette = array();

		foreach ( $colors as $index => $color ) {
			if ( ! is_scalar( $color ) ) {
				continue;
			}
			$formatted_palette[ '--ast-global-color-' . $index ] = (string) $color;
		}

		return $formatted_palette;
	}

	/**
	 * Get installed plugins with their versions.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	private function get_installed_plugins_versions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins     = get_plugins();
		$plugin_versions = array();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$slug = dirname( $plugin_file );
			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}
			$plugin_versions[ $slug ] = is_string( $plugin_data['Version'] ?? null ) ? $plugin_data['Version'] : '0.0.0';
		}

		return $plugin_versions;
	}

	/**
	 * Build the Spectra setup-gate descriptor for the React notice.
	 *
	 * ZIP AI runs on the Spectra Blocks plugin. When it is missing or inactive
	 * the React app shows a setup notice. Returns null when it is active.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>|null
	 */
	private function get_setup_gate() {
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin = $this->get_plugin_gate_item();

		if ( $plugin['active'] ) {
			return null;
		}

		return array(
			'items'           => array( $plugin ),
			// Inline card was dismissed (X) — keep the gate so the header icon
			// still shows, but the React app won't auto-render the inline card.
			'inlineDismissed' => (bool) get_user_meta( get_current_user_id(), 'zip_ai_setup_gate_dismissed', true ),
		);
	}

	/**
	 * Spectra plugin gate item (installed / active state).
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	private function get_plugin_gate_item() {
		$slug = 'spectra-blocks';
		$file = PluginResolver::resolve_plugin_file( $slug );

		return array(
			'type'      => 'plugin',
			'slug'      => $slug,
			'label'     => 'Spectra Blocks',
			'installed' => null !== $file,
			'active'    => null !== $file && is_plugin_active( $file ),
		);
	}

	/**
	 * Render the assistant container HTML.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_container() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// On dedicated full-page screen we render a different container.
		if ( is_admin() && $this->is_fullpage_screen() ) {
			return;
		}

		?>
		<div id="zip-ai-assistant-container" class="zip-ai-iframe-container" inert>
			<!-- React app mounts here (trigger + resize handle rendered by React via portals) -->
			<div id="chat-assistant-root"></div>
		</div>
		<?php
	}

	/**
	 * Render dedicated full-page assistant admin screen.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_fullpage_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Note: no .wrap class — we bypass WP's default margin/padding for a true full-bleed layout.
		?>
		<div class="zip-ai-fullpage-screen">
			<div id="zip-ai-fullpage-container" class="zip-ai-fullpage-container">
				<div id="chat-assistant-root"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if current admin page is dedicated full-page assistant.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_fullpage_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'settings_page_zip-ai-assistant' === $screen->id ) {
			return true;
		}

		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'zip-ai-assistant' === $page;
	}
}
