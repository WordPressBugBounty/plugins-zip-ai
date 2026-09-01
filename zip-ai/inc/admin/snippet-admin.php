<?php
/**
 * Snippet Admin - Mounts the React-based snippets management SPA.
 *
 * @since 0.1.0
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Admin;

use ZipAI\MCP\Classes\Core\Snippet_Store;
use ZipAI\MCP\Classes\Core\Snippet_Executor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Snippet_Admin {

	/**
	 * Hook suffix of the registered submenu page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Wire admin hooks (submenu registration + asset enqueue).
	 *
	 * @return void
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the snippets management submenu page.
	 *
	 * @return void
	 */
	public function register_submenu() {
		// Read capability: the page must stay reachable so an admin can see and
		// disable live snippets even where authoring is denied. Write actions
		// 403 from the REST layer with an explanatory message.
		if ( ! Snippet_Store::current_user_can_read() ) {
			return;
		}

		$this->hook_suffix = (string) add_submenu_page(
			'options-general.php',
			__( 'ZIP AI Code Snippets', 'zip-ai' ),
			__( 'ZIP AI Code Snippets', 'zip-ai' ),
			Snippet_Store::read_capability(),
			'zip-ai-snippets',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Read a @wordpress/scripts asset manifest (dependencies + version).
	 *
	 * The path is a generated build artifact that may be absent (not committed),
	 * so it is guarded with file_exists() and returns an empty array otherwise.
	 *
	 * @param string $path Absolute path to the generated *.asset.php file.
	 * @return array<int|string, mixed>
	 */
	private function read_asset_manifest( $path ) {
		return file_exists( $path ) ? (array) ( include $path ) : array();
	}

	/**
	 * Enqueue the snippets SPA bundle, styles, and localized config.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		// React SPA bundle.
		$asset = $this->read_asset_manifest( ZIPAI_MCP_DIR . 'assets/js/dist/snippets-page.asset.php' );

		$deps = array( 'react', 'react-dom' );
		if ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ) {
			$deps = array_map( static fn ( $d ): string => is_scalar( $d ) ? (string) $d : '', $asset['dependencies'] );
		}
		$version = isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : ZIPAI_MCP_VERSION;

		wp_enqueue_script(
			'zip-ai-snippets-page',
			ZIPAI_MCP_URL . 'assets/js/dist/snippets-page.js',
			$deps,
			$version,
			true
		);

		// Single source of truth: enqueue the shared Tailwind/ZIP AI stylesheet built
		// from src/styles/main.css. Tokens + utilities are scoped to both
		// #chat-assistant-root and #zip-ai-snippets-root via tailwind.config.js.
		wp_enqueue_style(
			'zip-ai-snippets-page',
			ZIPAI_MCP_URL . 'assets/css/dist/chat-assistant.css',
			array(),
			$version
		);

		// Mount point sits flush against the WP admin canvas. The SPA owns its
		// own layout/padding via Tailwind — the `.wrap` chrome adds margins
		// that fight the design, so we zero the margins WP injects on
		// `#wpbody-content`'s default flow.
		wp_add_inline_style(
			'zip-ai-snippets-page',
			'#wpbody-content { padding: 0; } #wpcontent { padding-left: 0; padding-top: 0; } #wpbody { padding-top: 0 !important; } #wpfooter { display: none !important; } #zip-ai-snippets-root-wrap { margin: 0; padding: 0; }'
		);

		// CodeMirror 6 editor.
		$editor_asset = $this->read_asset_manifest( ZIPAI_MCP_DIR . 'assets/js/dist/snippet-editor.asset.php' );

		$editor_deps = array();
		if ( isset( $editor_asset['dependencies'] ) && is_array( $editor_asset['dependencies'] ) ) {
			$editor_deps = array_map( static fn ( $d ): string => is_scalar( $d ) ? (string) $d : '', $editor_asset['dependencies'] );
		}
		$editor_version = isset( $editor_asset['version'] ) && is_string( $editor_asset['version'] ) ? $editor_asset['version'] : ZIPAI_MCP_VERSION;

		wp_enqueue_script(
			'zip-ai-snippet-editor',
			ZIPAI_MCP_URL . 'assets/js/dist/snippet-editor.js',
			$editor_deps,
			$editor_version,
			true
		);

		// Config for the React app.
		wp_localize_script(
			'zip-ai-snippets-page',
			'eraSnippetConfig',
			array(
				'restUrl'            => rest_url( 'zip-ai/v1/snippets' ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'hooks'              => array(
					'php'  => Snippet_Store::VALID_PHP_HOOKS,
					'js'   => Snippet_Store::VALID_JS_HOOKS,
					'css'  => Snippet_Store::VALID_CSS_HOOKS,
					'html' => Snippet_Store::VALID_HTML_HOOKS,
				),
				'scopes'             => Snippet_Store::VALID_SCOPES,
				'conditionTypes'     => array(
					array(
						'value' => 'page',
						'label' => __( 'Page Type', 'zip-ai' ),
					),
					array(
						'value' => 'user',
						'label' => __( 'User State', 'zip-ai' ),
					),
					array(
						'value' => 'user_role',
						'label' => __( 'User Role', 'zip-ai' ),
					),
					array(
						'value' => 'post_type',
						'label' => __( 'Post Type', 'zip-ai' ),
					),
					array(
						'value' => 'post',
						'label' => __( 'Specific Post / Page', 'zip-ai' ),
					),
					array(
						'value' => 'device',
						'label' => __( 'Device', 'zip-ai' ),
					),
					array(
						'value' => 'url_pattern',
						'label' => __( 'URL Pattern', 'zip-ai' ),
					),
				),
				'conditionValues'    => array(
					'page'        => array(
						array(
							'value' => 'home',
							'label' => __( 'Homepage', 'zip-ai' ),
						),
						array(
							'value' => 'single',
							'label' => __( 'Single Post', 'zip-ai' ),
						),
						array(
							'value' => 'page',
							'label' => __( 'Page', 'zip-ai' ),
						),
						array(
							'value' => 'archive',
							'label' => __( 'Archive', 'zip-ai' ),
						),
						array(
							'value' => '404',
							'label' => __( '404 Page', 'zip-ai' ),
						),
						array(
							'value' => 'search',
							'label' => __( 'Search Results', 'zip-ai' ),
						),
					),
					'user'        => array(
						array(
							'value' => 'logged_in',
							'label' => __( 'Logged In', 'zip-ai' ),
						),
					),
					'user_role'   => self::get_user_roles(),
					'post_type'   => self::get_post_types(),
					'device'      => array(
						array(
							'value' => 'mobile',
							'label' => __( 'Mobile', 'zip-ai' ),
						),
					),
					// post + url_pattern are open-ended (no fixed list); UI uses
					// autocomplete (post) or free-text input (url_pattern).
					'post'        => array(),
					'url_pattern' => array(),
				),
				'conditionOperators' => array(
					'page'        => array( 'is', 'is_not' ),
					'user'        => array( 'is', 'is_not' ),
					'user_role'   => array( 'is', 'is_not', 'in', 'not_in' ),
					'post_type'   => array( 'is', 'is_not', 'in', 'not_in' ),
					'post'        => array( 'is', 'is_not', 'in', 'not_in' ),
					'device'      => array( 'is', 'is_not' ),
					'url_pattern' => array( 'is', 'is_not', 'contains', 'starts_with', 'ends_with', 'regex' ),
				),
				'conditionMulti'     => array(
					// Types where the UI should render a chip multi-picker.
					'user_role' => true,
					'post_type' => true,
					'post'      => true,
				),
				'safeModeActive'     => Snippet_Executor::is_persistent_safe_mode(),
			)
		);
	}

	/**
	 * Render the React mount point.
	 *
	 * @return void
	 */
	public function render_page() {
		// Canvas-reset CSS is enqueued via wp_add_inline_style() in enqueue_assets().
		echo '<div id="zip-ai-snippets-root-wrap"><div id="zip-ai-snippets-root"></div></div>';
	}

	/**
	 * Get all user roles as value/label pairs.
	 *
	 * @since 0.0.5
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function get_user_roles() {
		$roles  = wp_roles()->get_names();
		$result = array();

		foreach ( $roles as $slug => $name ) {
			$result[] = array(
				'value' => $slug,
				'label' => translate_user_role( $name ),
			);
		}

		return $result;
	}

	/**
	 * Get all public post types as value/label pairs.
	 *
	 * @since 0.0.5
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function get_post_types() {
		$types  = get_post_types( array( 'public' => true ), 'objects' );
		$result = array();

		foreach ( $types as $slug => $type ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			$singular = $type->labels->singular_name ?? '';
			$result[] = array(
				'value' => $slug,
				'label' => is_string( $singular ) ? $singular : '',
			);
		}

		return $result;
	}
}
