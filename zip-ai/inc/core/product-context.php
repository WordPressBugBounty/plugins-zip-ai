<?php
/**
 * Product context detector.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps the current WP admin screen to a product slug (Sure-family, Astra,
 * Elementor, WooCommerce). Drives the chat assistant's context-aware welcome
 * suggestions: the React side does a dictionary lookup on this slug, with the
 * suggestion lists in `src/constants/emptyThreadSuggestions.js`.
 *
 * Detection is server-side because `get_current_screen()` is the authoritative
 * source for screen identity. Only products that register WP Abilities are
 * mapped — UAE registers none, so it is intentionally omitted.
 */
class Product_Context {

	/**
	 * Exact `get_current_screen()->id` => product slug. Used for short, generic
	 * ids (core WP screens + single-word slugs) that would over-match if checked
	 * as substrings — a third-party submenu page carries the parent menu slug in
	 * its id/parent_base (e.g. a page under Users has `parent_base = users`), so
	 * substring matching `users` there would mistag it. Exact id match avoids it.
	 *
	 * Core screens map to what the assistant can actually do (plugin/theme/user/
	 * comment abilities + the WP-CLI runner, image generation, page builder).
	 *
	 * @var array<string,string>
	 */
	private const ID_MAP = array(
		'plugins'              => 'wp-plugins',
		'plugin-install'       => 'wp-plugins',
		'themes'               => 'wp-themes',
		'theme-install'        => 'wp-themes',
		'edit-comments'        => 'wp-comments',
		'upload'               => 'wp-media',
		'users'                => 'wp-users',
		'user-new'             => 'wp-users',
		'edit-product'         => 'woocommerce',
		'toplevel_page_portal' => 'suredash',
		// Posts/Pages list screens intentionally not mapped — the chat falls
		// through to the default suggestions there.
	);

	/**
	 * Distinctive brand token => product slug. Matched as a substring of the
	 * screen haystack ("id parent_base post_type") so product submenus inherit
	 * the parent product. Safe to substring-match because these are unique
	 * vendor slugs, not common English words. First hit wins.
	 *
	 * @var array<string,string>
	 */
	private const TOKEN_MAP = array(
		'zip-ai-snippets' => 'snippets',
		'woocommerce'     => 'woocommerce',
		'sureforms'       => 'sureforms',
		'suremail'        => 'suremail',
		'surerank'        => 'surerank',
		'surecookie'      => 'surecookie',
		'surecontact'     => 'surecontact',
		'surecart'        => 'surecart',
		'sc-dashboard'    => 'surecart',
		'cartflows'       => 'cartflows',
		'spectra'         => 'spectra',
		'latepoint'       => 'latepoint',
		'sigmize'         => 'sigmize',
		'elementor'       => 'elementor',
		'astra'           => 'astra',
	);

	/**
	 * Detect the active product for the current admin screen.
	 *
	 * @since 1.0.0
	 * @return string|null Product slug, or null off any known product screen.
	 */
	public static function detect() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return null;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return null;
		}

		// Core WordPress Settings screens (Settings > General/Writing/Reading/
		// Discussion/Media/Permalinks) all have a screen id prefixed `options-`.
		// Match the id prefix ONLY — not parent_base — so plugin pages that
		// merely sit under the Settings menu (id `settings_page_*`, including our
		// own assistant/snippets pages) are not mistagged as core settings.
		if ( 0 === strpos( (string) $screen->id, 'options-' ) ) {
			return 'wp-settings';
		}

		// Exact id match for generic/core screens (no parent_base over-match).
		if ( isset( self::ID_MAP[ $screen->id ] ) ) {
			return self::ID_MAP[ $screen->id ];
		}

		// Distinctive brand tokens — substring across the screen haystack.
		$haystack = strtolower(
			$screen->id . ' ' . ( $screen->parent_base ?? '' ) . ' ' . $screen->post_type
		);

		foreach ( self::TOKEN_MAP as $token => $product ) {
			if ( false !== strpos( $haystack, $token ) ) {
				return $product;
			}
		}

		return null;
	}
}
