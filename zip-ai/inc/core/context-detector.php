<?php
/**
 * WordPress Context Detector.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Context_Detector Class.
 * Detects WordPress admin context for MCP integration.
 */
class Context_Detector {



	/**
	 * Constructor of this class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		// Add hooks to detect WordPress context.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_context_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_context_scripts' ) );
	}

	/**
	 * Enqueue scripts for context detection
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_context_scripts() {
		// Check if user has permission to use the assistant.
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$context_data = array(
			'adminUrl'   => admin_url(),
			'siteUrl'    => home_url(),
			'currentUrl' => $this->get_current_url(),
			'isAdmin'    => is_admin(),
			'isFrontend' => ! is_admin(),
		);

		if ( is_admin() ) {
			// Admin context.
			$context_data['currentScreen'] = $this->get_current_screen_info();
			$context_data['currentPostId'] = $this->get_current_post_id();
		} else {
			// Frontend context.
			$context_data['currentPostId'] = $this->get_frontend_post_id();
			$context_data['postData']      = $this->get_frontend_post_data();
			$context_data['contextType']   = $this->get_frontend_context_type();
		}

		wp_localize_script( 'zip-ai-tool-hooks', 'zipwpMcpContext', $context_data );
	}

	/**
	 * Get current WordPress screen information
	 *
	 * @since 1.0.0
	 * @return array<string,string|null> Screen information
	 */
	public function get_current_screen_info() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return array();
		}

		return array(
			'id'          => $screen->id,
			'base'        => $screen->base,
			'post_type'   => $screen->post_type,
			'action'      => $screen->action,
			'parent_base' => $screen->parent_base ?? null,
		);
	}

	/**
	 * Get current post ID if editing a post
	 *
	 * @since 1.0.0
	 * @return int|null Current post ID or null
	 */
	public function get_current_post_id() {
		global $post, $pagenow;

		// Check if we're on post edit screen.
		if ( in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
			// Get post ID from URL parameter.
			if ( isset( $_GET['post'] ) && is_string( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return absint( sanitize_text_field( wp_unslash( $_GET['post'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			// Get from global $post object.
			if ( $post instanceof \WP_Post ) {
				return (int) $post->ID;
			}
		}

		// Check for block editor (Gutenberg).
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && $screen->is_block_editor() && $post instanceof \WP_Post ) {
				return (int) $post->ID;
			}
		}

		return null;
	}

	/**
	 * Get current URL (admin or frontend)
	 *
	 * @since 1.0.0
	 * @return string Current URL
	 */
	public function get_current_url() {
		$protocol = isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https://' : 'http://';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HTTP_HOST is used only for URL construction.
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is used only for URL construction.
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

		return $protocol . $host . $uri;
	}

	/**
	 * Get current post ID on frontend
	 *
	 * @since 1.0.0
	 * @return int|null Frontend post ID or null
	 */
	public function get_frontend_post_id() {
		global $post;

		// Check if we're on a single post or page.
		if ( is_single() || is_page() ) {
			return get_queried_object_id();
		}

		// Fallback to global post object.
		if ( $post instanceof \WP_Post ) {
			return (int) $post->ID;
		}

		return null;
	}

	/**
	 * Get frontend post data
	 *
	 * @since 1.0.0
	 * @return array<string,string|int|false|null>|null Frontend post data or null
	 */
	public function get_frontend_post_data() {
		$post_id = $this->get_frontend_post_id();

		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'slug'      => $post->post_name,
			'url'       => get_permalink( $post->ID ),
			'edit_link' => get_edit_post_link( $post->ID ),
		);
	}

	/**
	 * Get frontend context type
	 *
	 * @since 1.0.0
	 * @return string Frontend context type
	 */
	public function get_frontend_context_type() {
		if ( is_single() ) {
			return 'frontend_post';
		} elseif ( is_page() ) {
			return 'frontend_page';
		} elseif ( is_home() || is_front_page() ) {
			return 'frontend_home';
		} elseif ( is_archive() ) {
			return 'frontend_archive';
		} elseif ( is_search() ) {
			return 'frontend_search';
		}

		return 'frontend';
	}
}
