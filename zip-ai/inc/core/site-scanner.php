<?php
/**
 * Site Scanner — thin data collector for ZIP AI memory enrichment.
 *
 * Collects raw WordPress data and sends it directly to the server.
 * All extraction logic, analysis, and fact storage lives server-side.
 * The plugin is a dumb data pipe — easy to maintain, no intelligence to update.
 *
 * Triggers:
 * 1. After first successful auth (day-zero scan)
 * 2. When a post/page/CPT is published or updated (debounced)
 * 3. On plugin activation/deactivation or theme switch
 *
 * @package zip-ai
 * @since 1.0.0
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Scanner {

	/** Debounce interval — don't scan more than once per 5 minutes. */
	private const DEBOUNCE_SECONDS = 300;

	/**
	 * Register event-driven hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status_change' ), 10, 3 );
		add_action( 'activated_plugin', array( __CLASS__, 'trigger_debounced_scan' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'trigger_debounced_scan' ) );
		add_action( 'switch_theme', array( __CLASS__, 'trigger_debounced_scan' ) );
	}

	/**
	 * No-op — cron no longer used. Kept for backward compat if old events exist.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( 'zip_ai_site_scan' );
	}

	/**
	 * Trigger scan when a post transitions to published.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object being transitioned.
	 * @return void
	 */
	public static function on_post_status_change( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status ) {
			return;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		self::trigger_debounced_scan();
	}

	/**
	 * Trigger a scan with debouncing via non-blocking REST call.
	 * No WP cron dependency — fires immediately.
	 */
	public static function trigger_debounced_scan(): void {
		$auth_token = Helper::get_decrypted_auth_token();

		if ( empty( $auth_token ) ) {
			return;
		}

		$last_scan = get_transient( 'zip_ai_last_scan_time' );

		if ( $last_scan && ( time() - Utils::to_int( $last_scan ) ) < self::DEBOUNCE_SECONDS ) {
			return;
		}

		self::fire_scan_request();
	}

	/**
	 * Fire non-blocking REST request to trigger site scan.
	 *
	 * Authenticates with the plugin's own Application Password: the route takes
	 * Basic, never a Bearer, so the server token sent here before could only 401 —
	 * and rode the wire with TLS verification off to do it.
	 */
	public static function fire_scan_request(): void {
		$authorization = Helper::get_decrypted_app_password_authorization();

		if ( '' === $authorization ) {
			return;
		}

		// Claim the debounce window here — after the credential check, before the
		// non-blocking send. `run_scan()` re-claims it once the loopback lands,
		// which is too late to stop a bulk publish firing one scan per post; and
		// claiming any earlier would burn the window every 5 minutes forever on
		// installs where no App Password is provisioned.
		set_transient( 'zip_ai_last_scan_time', time(), self::DEBOUNCE_SECONDS );

		wp_remote_post(
			rest_url( 'zip-ai/v1/site-scan' ),
			array(
				'headers'   => array(
					'Authorization' => $authorization,
				),
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => Helper::should_verify_ssl(),
			)
		);
	}

	/**
	 * Run the scan and send raw data straight to the server, which owns fact
	 * extraction + memory storage. The server authenticates the plugin's Sanctum
	 * token itself (dual-mode /site-scan) and resolves the account.
	 */
	public static function run_scan(): void {
		$auth_token = Helper::get_decrypted_auth_token();

		if ( empty( $auth_token ) ) {
			return;
		}

		set_transient( 'zip_ai_last_scan_time', time(), self::DEBOUNCE_SECONDS );

		$scan_data = self::collect();

		if ( empty( $scan_data ) ) {
			return;
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		wp_remote_post(
			untrailingslashit( ZIPAI_BRAIN_URL ) . '/site-scan',
			array(
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $auth_token,
				),
				'body'      => (string) wp_json_encode(
					array(
						'domain'    => $domain,
						'scan_data' => $scan_data,
					)
				),
				'timeout'   => 15,
				'sslverify' => Helper::should_verify_ssl(),
			)
		);
	}

	/**
	 * Collect raw WordPress data. No formatting, no intelligence — just data.
	 *
	 * @return array<string, mixed> Raw site data.
	 */
	public static function collect() {
		$data = array();

		// ── Site basics ──────────────────────────────────────
		$data['site_title']   = get_bloginfo( 'name' );
		$data['site_tagline'] = get_bloginfo( 'description' );
		$data['language']     = get_bloginfo( 'language' );

		// ── Theme ────────────────────────────────────────────
		$theme         = wp_get_theme();
		$data['theme'] = $theme->get( 'Name' );

		$data['color_palette'] = self::get_color_palette();

		// ── Spectra Style Guide (GBS) ─────────────────────────
		// Map of slug → shade → hex, so downstream memory stores the site's
		// real palette hex values. Empty when Spectra is not active.
		$data['spectra_style_guide'] = self::get_spectra_style_guide();

		// ── Active plugins (names only) ──────────────────────
		$data['active_plugins'] = self::get_active_plugin_names();

		// ── Pages (latest 15 with raw content) ───────────────
		$data['pages'] = self::get_pages_raw();

		// ── Posts (latest 10 with raw content) ───────────────
		$data['posts'] = self::get_posts_raw();

		// ── Navigation menus ─────────────────────────────────
		$data['menus'] = self::get_menus_raw();

		// ── Custom post types ────────────────────────────────
		$data['custom_post_types'] = self::get_custom_post_types();

		// ── Sidebar widgets ──────────────────────────────────
		$data['sidebars'] = self::get_sidebars_raw();

		// ── E-commerce ───────────────────────────────────────
		$ecommerce = self::get_ecommerce_raw();
		if ( $ecommerce ) {
			$data['ecommerce'] = $ecommerce;
		}

		// ── Membership ───────────────────────────────────────
		$membership = self::get_membership_data();
		if ( $membership ) {
			$data['membership'] = $membership;
		}

		// ── LMS ──────────────────────────────────────────────
		$lms = self::get_lms_data();
		if ( $lms ) {
			$data['lms'] = $lms;
		}

		// ── Events ───────────────────────────────────────────
		$events = self::get_events_data();
		if ( $events ) {
			$data['events'] = $events;
		}

		// ── Forms ────────────────────────────────────────────
		$forms = self::get_forms_data();
		if ( $forms ) {
			$data['forms'] = $forms;
		}

		// ── SEO plugin metadata ──────────────────────────────
		$seo = self::get_seo_raw();
		if ( $seo ) {
			$data['seo'] = $seo;
		}

		return $data;
	}

	// ══════════════════════════════════════════════════════════
	// Raw data collectors — minimal processing, just fetch data
	// ══════════════════════════════════════════════════════════

	/**
	 * Collect the display names of all active plugins.
	 *
	 * @return array<int,string> Active plugin names.
	 */
	private static function get_active_plugin_names() {
		$active = get_option( 'active_plugins', array() );
		$names  = array();

		if ( ! is_array( $active ) ) {
			return $names;
		}

		foreach ( $active as $plugin_file ) {
			if ( ! is_string( $plugin_file ) ) {
				continue;
			}
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			if ( ! empty( $plugin_data['Name'] ) ) {
				$names[] = $plugin_data['Name'];
			}
		}

		return $names;
	}

	/**
	 * Fetch the latest published pages with raw content.
	 *
	 * @return array{count:int,items:array<int,array{title:string,slug:string,excerpt:string,word_count:int,raw_html:string}>} Page count and page items.
	 */
	private static function get_pages_raw() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 15,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $pages as $page ) {
			$raw_content = $page->post_content;
			$text        = wp_strip_all_tags( $raw_content );

			$items[] = array(
				'title'      => $page->post_title,
				'slug'       => $page->post_name,
				'excerpt'    => mb_substr( $text, 0, 300 ),
				'word_count' => str_word_count( $text ),
				'raw_html'   => mb_substr( $raw_content, 0, 2000 ), // The server extracts headings from this.
			);
		}

		$total = wp_count_posts( 'page' );

		return array(
			'count' => isset( $total->publish ) ? Utils::to_int( $total->publish ) : 0,
			'items' => $items,
		);
	}

	/**
	 * Fetch the latest published posts with top categories.
	 *
	 * @return array{count:int,categories:array<int,string>,items:array<int,array{title:string,excerpt:string}>} Post count, categories, and post items.
	 */
	private static function get_posts_raw() {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$text = wp_strip_all_tags( $post->post_content );

			$items[] = array(
				'title'   => $post->post_title,
				'excerpt' => mb_substr( $text, 0, 300 ),
			);
		}

		$count = wp_count_posts( 'post' );

		$categories = get_categories(
			array(
				'hide_empty' => true,
				'number'     => 15,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		$cat_names = array();
		foreach ( $categories as $cat ) {
			$cat_names[] = $cat->name . ' (' . $cat->count . ')';
		}

		return array(
			'count'      => isset( $count->publish ) ? Utils::to_int( $count->publish ) : 0,
			'categories' => $cat_names,
			'items'      => $items,
		);
	}

	/**
	 * Collect navigation menus with their items.
	 *
	 * @return array<int,array{name:string,items:array<int,array{title:string,url:string}>}> Menus with their items.
	 */
	private static function get_menus_raw() {
		$menus   = wp_get_nav_menus();
		$results = array();

		foreach ( $menus as $menu ) {
			$items      = wp_get_nav_menu_items( $menu->term_id );
			$menu_items = array();

			if ( $items ) {
				foreach ( array_slice( $items, 0, 20 ) as $item ) {
					if ( ! is_object( $item ) ) {
						continue;
					}
					$vars         = get_object_vars( $item );
					$menu_items[] = array(
						'title' => isset( $vars['title'] ) && is_string( $vars['title'] ) ? $vars['title'] : '',
						'url'   => isset( $vars['url'] ) && is_string( $vars['url'] ) ? $vars['url'] : '',
					);
				}
			}

			if ( ! empty( $menu_items ) ) {
				$results[] = array(
					'name'  => $menu->name,
					'items' => $menu_items,
				);
			}
		}

		return $results;
	}

	/**
	 * Collect footer/bottom sidebar widget counts.
	 *
	 * @return array<string,int>|null Map of sidebar id to widget count, or null when none.
	 */
	private static function get_sidebars_raw() {
		$sidebars       = wp_get_sidebars_widgets();
		$footer_widgets = array();

		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( empty( $widgets ) || ! is_array( $widgets ) ) {
				continue;
			}

			if ( str_contains( $sidebar_id, 'footer' ) || str_contains( $sidebar_id, 'bottom' ) ) {
				$footer_widgets[ (string) $sidebar_id ] = count( $widgets );
			}
		}

		return ! empty( $footer_widgets ) ? $footer_widgets : null;
	}

	/**
	 * Collect public custom post types that have published entries.
	 *
	 * @return array<int,array{name:string,label:string,count:int}> Custom post type details.
	 */
	private static function get_custom_post_types() {
		$cpts    = get_post_types(
			array(
				'_builtin' => false,
				'public'   => true,
			),
			'objects' 
		);
		$results = array();
		$exclude = array( 'spectra-popup', 'elementor_library', 'wp_template', 'wp_template_part', 'wp_block' );

		foreach ( $cpts as $cpt ) {
			if ( in_array( $cpt->name, $exclude, true ) ) {
				continue;
			}

			$count     = wp_count_posts( $cpt->name );
			$published = isset( $count->publish ) ? Utils::to_int( $count->publish ) : 0;

			if ( $published > 0 ) {
				$results[] = array(
					'name'  => $cpt->name,
					'label' => $cpt->label,
					'count' => $published,
				);
			}
		}

		return $results;
	}

	/**
	 * Read the site's color palette from Astra settings or theme.json.
	 *
	 * @return string Comma-separated palette colors, or empty string when none.
	 */
	private static function get_color_palette() {
		$palette = array();

		$astra_settings = get_option( 'astra-settings', array() );
		if ( is_array( $astra_settings ) ) {
			$gcp = $astra_settings['global-color-palette'] ?? null;
			if ( is_array( $gcp ) && ! empty( $gcp['palette'] ) && is_array( $gcp['palette'] ) ) {
				$palette = array_filter( array_map( static fn ( $v ): string => is_scalar( $v ) ? (string) $v : '', $gcp['palette'] ) );
			}
		}

		if ( ! empty( $palette ) ) {
			return implode( ', ', array_slice( $palette, 0, 6 ) );
		}

		if ( function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			if ( is_array( $settings ) ) {
				foreach ( array_slice( $settings, 0, 6 ) as $color ) {
					if ( ! is_array( $color ) ) {
						continue;
					}
					$name      = $color['name'] ?? '';
					$value     = $color['color'] ?? '';
					$palette[] = ( is_string( $name ) ? $name : '' ) . ': ' . ( is_string( $value ) ? $value : '' );
				}
			}
		}

		return ! empty( $palette ) ? implode( ', ', $palette ) : '';
	}

	/**
	 * Read the Spectra GBS Style Guide palette.
	 *
	 * Returns `{ slug: { shade: "#hex", ... }, ... }` for every palette slug
	 * defined in the site's Style Guide (primary / secondary / base / neutral
	 * / chromatic1..N). Shades come from `ClassRegistry::get_all_classes()`
	 * filtered to `bg-{slug}-{shade}`; each entry's CSS body is parsed for
	 * the `var(--spectra-...)` reference which is then resolved against the
	 * style-guide-tokens stylesheet to yield the actual hex.
	 *
	 * Empty array when Spectra is not active.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function get_spectra_style_guide() {
		if ( ! class_exists( '\Spectra\GlobalStyles\ClassRegistry' ) ) {
			return array();
		}

		// 1. Collect the site's resolved CSS custom properties from the
		// Style-Guide TokenRegistry (single source of truth for slug→hex).
		$vars = array();
		if ( class_exists( '\\Spectra\\StyleGuide\\Engine' ) ) {
			$engine   = \Spectra\StyleGuide\Engine::get_instance();
			$registry = is_object( $engine ) && method_exists( $engine, 'get_token_registry' ) ? $engine->get_token_registry() : null;
			if ( is_object( $registry ) && method_exists( $registry, 'get_css_string' ) ) {
				$token_css = $registry->get_css_string();
				if ( is_string( $token_css ) && preg_match_all( '/--spectra-([a-z0-9-]+)\s*:\s*([^;]+);/i', $token_css, $m ) ) {
					foreach ( $m[1] as $idx => $var_name ) {
						$vars[ $var_name ] = trim( $m[2][ $idx ] );
					}
				}
			}
		}

		// 2. Walk the ClassRegistry and pick out `bg-{slug}-{shade}` rules.
		// Each rule's CSS body looks like `background: var(--spectra-SLUG-SHADE)`.
		// Resolve the var name against the table built above and emit the hex.
		$palette = array();
		$all     = \Spectra\GlobalStyles\ClassRegistry::get_all_classes();
		if ( ! is_array( $all ) ) {
			return array();
		}

		foreach ( $all as $class_name => $entry ) {
			if ( ! is_string( $class_name ) || ! is_array( $entry ) ) {
				continue;
			}
			if ( ! preg_match( '/^bg-([a-z][a-z0-9]*)-(50|100|200|300|400|500|600|700|800|900|950)$/', $class_name, $m ) ) {
				continue;
			}
			$slug  = $m[1];
			$shade = $m[2];

			$css_val     = $entry['css'] ?? '';
			$declaration = is_string( $css_val ) ? $css_val : '';
			if ( ! preg_match( '/var\(\s*--spectra-([a-z0-9-]+)\s*\)/i', $declaration, $vm ) ) {
				continue;
			}
			$var_ref = $vm[1];
			$hex     = $vars[ $var_ref ] ?? null;
			if ( null !== $hex && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $hex ) ) {
				$palette[ $slug ][ $shade ] = $hex;
			}
		}

		ksort( $palette );
		foreach ( $palette as $slug => $shades ) {
			ksort( $palette[ $slug ] );
		}

		return $palette;
	}

	// ══════════════════════════════════════════════════════════
	// E-commerce — raw product data
	// ══════════════════════════════════════════════════════════

	/**
	 * Collect raw e-commerce data for the active store plugin.
	 *
	 * @return array<string,mixed>|null WooCommerce or SureCart data, or null when no store plugin is active.
	 */
	private static function get_ecommerce_raw() {
		if ( class_exists( 'WooCommerce' ) ) {
			$product_count = wp_count_posts( 'product' );

			$categories = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'number'     => 10,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);

			$cat_names = array();
			if ( ! is_wp_error( $categories ) ) {
				foreach ( $categories as $cat ) {
					$cat_names[] = $cat->name;
				}
			}

			// Latest 15 products with names, prices, and short descriptions.
			$products = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 15,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$product_items = array();
			foreach ( $products as $product_post ) {
				$wc = wc_get_product( $product_post->ID );
				if ( ! $wc ) {
					continue;
				}

				$product_items[] = array(
					'name'              => $product_post->post_title,
					'price'             => (float) $wc->get_price(),
					'short_description' => wp_strip_all_tags( mb_substr( $wc->get_short_description(), 0, 150 ) ),
				);
			}

			// Bestsellers (top 5 by sales).
			$bestsellers     = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 5,
					'meta_key'       => 'total_sales',
					'orderby'        => 'meta_value_num',
					'order'          => 'DESC',
				)
			);
			$bestseller_data = array();
			foreach ( $bestsellers as $bs ) {
				$wc    = wc_get_product( $bs->ID );
				$sales = $wc ? (int) $wc->get_total_sales() : 0;
				if ( $sales > 0 ) {
					$bestseller_data[] = array(
						'name'  => $bs->post_title,
						'sales' => $sales,
					);
				}
			}

			return array(
				'platform'           => 'WooCommerce',
				'product_count'      => isset( $product_count->publish ) ? Utils::to_int( $product_count->publish ) : 0,
				'product_categories' => $cat_names,
				'products'           => $product_items,
				'bestsellers'        => $bestseller_data,
				'currency'           => get_woocommerce_currency(),
				'has_reviews'        => 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ),
			);
		}

		if ( defined( 'SURECART_PLUGIN_FILE' ) || class_exists( 'SureCart' ) ) {
			return array( 'platform' => 'SureCart' );
		}

		return null;
	}

	// ══════════════════════════════════════════════════════════
	// SEO — raw meta data from Yoast / RankMath
	// ══════════════════════════════════════════════════════════

	/**
	 * Collect SEO metadata from Yoast or RankMath.
	 *
	 * @return array{plugin:string,pages:array<int,array<string,string>>}|null SEO plugin name and per-page meta, or null when no SEO plugin is active.
	 */
	private static function get_seo_raw() {
		$seo_plugin = null;

		if ( defined( 'WPSEO_VERSION' ) ) {
			$seo_plugin = 'Yoast SEO';
		} elseif ( class_exists( 'RankMath' ) ) {
			$seo_plugin = 'RankMath';
		}

		if ( ! $seo_plugin ) {
			return null;
		}

		$pages = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$meta = array();
		foreach ( $pages as $page ) {
			$entry = array( 'title' => $page->post_title );

			$fk = '';
			$md = '';
			if ( 'Yoast SEO' === $seo_plugin ) {
				$fk = get_post_meta( $page->ID, '_yoast_wpseo_focuskw', true );
				$md = get_post_meta( $page->ID, '_yoast_wpseo_metadesc', true );
			} elseif ( 'RankMath' === $seo_plugin ) {
				$fk = get_post_meta( $page->ID, 'rank_math_focus_keyword', true );
				$md = get_post_meta( $page->ID, 'rank_math_description', true );
			}

			$entry['focus_keyword']    = is_string( $fk ) ? $fk : '';
			$entry['meta_description'] = is_string( $md ) ? $md : '';

			// Only include if there's actual SEO data.
			if ( ! empty( $entry['focus_keyword'] ) || ! empty( $entry['meta_description'] ) ) {
				$meta[] = $entry;
			}
		}

		return array(
			'plugin' => $seo_plugin,
			'pages'  => $meta,
		);
	}

	// ══════════════════════════════════════════════════════════
	// Platform detectors — just check if active, no formatting
	// ══════════════════════════════════════════════════════════

	/**
	 * Detect the active membership plugin.
	 *
	 * @return array{plugin:string}|null Membership plugin name, or null when none is active.
	 */
	private static function get_membership_data() {
		if ( defined( 'MEPR_PLUGIN_NAME' ) ) {
			return array( 'plugin' => 'MemberPress' );
		}
		if ( class_exists( 'Restrict_Content_Pro' ) ) {
			return array( 'plugin' => 'Restrict Content Pro' );
		}
		if ( class_exists( 'WC_Memberships' ) ) {
			return array( 'plugin' => 'WooCommerce Memberships' );
		}

		return null;
	}

	/**
	 * Collect LMS course/lesson counts for the active LMS plugin.
	 *
	 * @return array{plugin:string,course_count:int,lesson_count:int}|null LMS plugin name and counts, or null when no LMS is active.
	 */
	private static function get_lms_data() {
		if ( defined( 'LEARNDASH_VERSION' ) ) {
			$courses = wp_count_posts( 'sfwd-courses' );
			$lessons = wp_count_posts( 'sfwd-lessons' );

			return array(
				'plugin'       => 'LearnDash',
				'course_count' => isset( $courses->publish ) ? Utils::to_int( $courses->publish ) : 0,
				'lesson_count' => isset( $lessons->publish ) ? Utils::to_int( $lessons->publish ) : 0,
			);
		}

		if ( defined( 'TUTOR_VERSION' ) ) {
			$courses = wp_count_posts( 'courses' );
			$lessons = wp_count_posts( 'lesson' );

			return array(
				'plugin'       => 'Tutor LMS',
				'course_count' => isset( $courses->publish ) ? Utils::to_int( $courses->publish ) : 0,
				'lesson_count' => isset( $lessons->publish ) ? Utils::to_int( $lessons->publish ) : 0,
			);
		}

		return null;
	}

	/**
	 * Collect upcoming event count for The Events Calendar.
	 *
	 * @return array{plugin:string,upcoming_count:int}|null Events plugin name and upcoming count, or null when not active.
	 */
	private static function get_events_data() {
		if ( defined( 'TRIBE_EVENTS_FILE' ) ) {
			$upcoming = get_posts(
				array(
					'post_type'      => 'tribe_events',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off site-scan diagnostic query, not a hot path.
						array(
							'key'     => '_EventStartDate',
							'value'   => current_time( 'mysql' ),
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
					),
					'fields'         => 'ids',
				)
			);

			return array(
				'plugin'         => 'The Events Calendar',
				'upcoming_count' => count( $upcoming ),
			);
		}

		return null;
	}

	/**
	 * Detect the active forms plugin and its form count.
	 *
	 * @return array{plugin:string,count?:int}|null Forms plugin name and form count, or null when none is active.
	 */
	private static function get_forms_data() {
		if ( defined( 'JESUSFN_SUREFORMS_VER' ) || defined( 'JESUSFN_SUREFORMS_PLUGIN_FILE' ) || class_exists( 'JESUSFN_SureForms' ) ) {
			$forms = wp_count_posts( 'sureforms_form' );
			return array(
				'plugin' => 'SureForms',
				'count'  => isset( $forms->publish ) ? Utils::to_int( $forms->publish ) : 0,
			);
		}
		if ( defined( 'WPFORMS_VERSION' ) ) {
			$forms = wp_count_posts( 'wpforms' );
			return array(
				'plugin' => 'WPForms',
				'count'  => isset( $forms->publish ) ? Utils::to_int( $forms->publish ) : 0,
			);
		}
		if ( defined( 'WPCF7_VERSION' ) ) {
			$forms = wp_count_posts( 'wpcf7_contact_form' );
			return array(
				'plugin' => 'Contact Form 7',
				'count'  => isset( $forms->publish ) ? Utils::to_int( $forms->publish ) : 0,
			);
		}
		if ( class_exists( 'GFAPI' ) ) {
			return array( 'plugin' => 'Gravity Forms' );
		}

		return null;
	}
}
