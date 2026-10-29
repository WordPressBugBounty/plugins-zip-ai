<?php
/**
 * Snippet Admin - Snippets submenu + config for the ZIP AI app's snippets route.
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
	 * Wire admin hooks (submenu registration + config).
	 *
	 * Priority 11: both run after React_Manager registers the ZIP AI menu and
	 * the chat-assistant bundle this config rides on.
	 *
	 * @return void
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_submenu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 11 );
	}

	/**
	 * Register the snippets submenu: a hash route of the ZIP AI screen.
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

		// ponytail: never called, the hash never reaches the server; WP needs a callback to link the slug as admin.php?page=.
		add_submenu_page(
			'zip-ai-assistant',
			__( 'ZIP AI Code Snippets', 'zip-ai' ),
			__( 'Code Snippets', 'zip-ai' ),
			Snippet_Store::read_capability(),
			'zip-ai-assistant#/snippets',
			'__return_null'
		);
	}

	/**
	 * Localize the snippets config onto the ZIP AI screen's app bundle.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_zip-ai-assistant' !== $hook_suffix ) {
			return;
		}

		// Config for the React app.
		wp_localize_script(
			'zip-ai-chat-assistant',
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
