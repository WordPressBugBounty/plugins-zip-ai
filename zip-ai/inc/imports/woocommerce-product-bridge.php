<?php
/**
 * Store product bridge — a built site's product cards wear the site's own classes, on
 * WooCommerce or SureCart.
 *
 * The converter binds a design-library product card to the store: WooCommerce
 * renders the product (its picture, name, price and description) and the card
 * keeps the classes every part wore, the library class plus the `gs-*` class
 * the GBS store holds its rule under. Two parts need help to wear them:
 *
 *   - the cart control is the store's own: WooCommerce's Product Button block in a
 *     list, its `[add_to_cart]` shortcode on a single product, SureCart's Buy Button
 *     (`surecart/product-buy-button`). Each prints the classes it is given on a
 *     wrapper (a `div`, a `p`). The converter marks them
 *     `zip-ai-cart`; the bridge MOVES the card's classes from the wrapper onto the
 *     button or link inside, the element the page's rules style.
 *   - a product list (`woocommerce/product-template` or `surecart/product-template`,
 *     marked `zip-ai-products`) holds each card in an `li` (SureCart's, in a `form`). A few rules hand each `li`'s box to the card it
 *     holds, so the list lays the cards out as the library grid does, and an
 *     empty store-notice banner takes no room.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Imports;

use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Dresses WooCommerce's product blocks in the classes the converter recorded.
 *
 * @since 0.0.15
 */
class WoocommerceProductBridge {
	/**
	 * The converter's mark on a Product Button whose classes belong on the button.
	 *
	 * @var string
	 */
	public const CART_MARKER = 'zip-ai-cart';

	/**
	 * The converter's mark on a product list (`woocommerce/product-template`).
	 *
	 * @var string
	 */
	public const LIST_MARKER = 'zip-ai-products';

	/**
	 * The handle the list's rules ride.
	 *
	 * @var string
	 */
	public const STYLE_HANDLE = 'zip-ai-products';

	/**
	 * Each `li` hands its box to the card it holds; the card's name links its product's
	 * page over the whole card, in the name's own ink, its cart control above that link;
	 * an empty notice banner (the region WooCommerce puts above a list) takes no room.
	 *
	 * @var string
	 */
	private const LIST_CSS = '.zip-ai-products{list-style:none;margin:0;padding:0}'
		// (0,3,1): above WooCommerce's own list rule, `.is-product-collection-layout-list .wc-block-product:not(:last-child)`.
		. '.zip-ai-products.wc-block-product-template>li.wc-block-product{display:flex;margin:0;padding:0;min-width:0}'
		. '.zip-ai-products.wc-block-product-template>li.wc-block-product>*{flex:1 1 auto;min-width:0;position:relative}'
		. '.zip-ai-products .wp-block-post-title>a{color:inherit;text-decoration:none}'
		. '.zip-ai-products .wp-block-post-title>a::after{content:"";position:absolute;inset:0}'
		. '.zip-ai-products .wc-block-components-product-button{position:relative;z-index:1}'
		// SureCart's list: each `li` holds its card in a `form`, beside the link SureCart stretches over the card.
		. '.zip-ai-products.wp-block-surecart-product-template>li.sc-product-item{display:flex;margin:0;padding:0;min-width:0}'
		. '.zip-ai-products.wp-block-surecart-product-template>li.sc-product-item>form{display:flex;flex:1 1 auto;min-width:0;position:relative}'
		// the card above SureCart's card link, letting a click through to it except on the card's own controls
		. '.zip-ai-products.wp-block-surecart-product-template>li.sc-product-item>form>:not(.sc-product-item-link){flex:1 1 auto;min-width:0;position:relative;z-index:2;pointer-events:none}'
		. '.zip-ai-products.wp-block-surecart-product-template>li.sc-product-item>form>:not(.sc-product-item-link) :is(a,button,input,select,textarea,label){pointer-events:auto}'
		. '.wc-block-components-notices:has(>.wc-block-components-notice-banner .wc-block-components-notice-banner__content>span:empty){display:none}';

	/**
	 * Register the render filters.
	 */
	public function __construct() {
		add_filter( 'render_block_woocommerce/product-button', array( $this, 'dress_button' ), 10, 2 );
		add_filter( 'do_shortcode_tag', array( $this, 'dress_cart_shortcode' ), 10, 3 );
		add_filter( 'render_block_woocommerce/product-template', array( $this, 'dress_list' ), 10, 2 );
		add_filter( 'render_block_surecart/product-buy-button', array( $this, 'dress_button' ), 10, 2 );
		add_filter( 'render_block_surecart/product-template', array( $this, 'dress_list' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_for_page' ) );
	}

	/**
	 * A page whose content holds a marked list brings the list's rules in the head: enqueued
	 * at render, a classic theme (its head already printed) would print them in the footer,
	 * and the grid would show unstyled until then.
	 */
	public function enqueue_for_page(): void {
		$post = get_queried_object();
		if ( $post instanceof \WP_Post && false !== strpos( $post->post_content, self::LIST_MARKER ) ) {
			$this->enqueue_list_rules();
		}
	}

	/**
	 * The card's classes leave the Product Button's wrapper for the control inside it.
	 *
	 * @param string               $html  Rendered block.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	public function dress_button( $html, $block ) {
		return '' === $html ? $html : $this->move_classes( $html, $this->marked_classes( $block, self::CART_MARKER ) );
	}

	/**
	 * `$own` leaves the wrapper (the first tag) for the first button or link inside it; with
	 * neither inside, the wrapper keeps them.
	 *
	 * @param string                  $html Rendered wrapper.
	 * @param array<int, string>|null $own  The card's classes; null leaves the markup alone.
	 * @return string
	 */
	private function move_classes( string $html, ?array $own ): string {
		if ( null === $own ) {
			return $html;
		}
		$p = new WP_HTML_Tag_Processor( $html );
		$p->next_tag();
		// no first tag, no bookmark
		if ( ! $p->set_bookmark( 'wrapper' ) ) {
			return $html;
		}
		while ( $p->next_tag() ) {
			if ( in_array( $p->get_tag(), array( 'BUTTON', 'A' ), true ) ) {
				foreach ( $own as $class ) {
					$p->add_class( $class );
				}
				// the wrapper gives them up only once the control wears them
				$p->seek( 'wrapper' );
				foreach ( $own as $class ) {
					$p->remove_class( $class );
				}
				return $p->get_updated_html();
			}
		}
		return $html;
	}

	/**
	 * The card's classes leave the `[add_to_cart]` shortcode's `<p>` for the link inside it.
	 *
	 * @param string|false                $html Shortcode output.
	 * @param string                      $tag  Shortcode name.
	 * @param array<string, mixed>|string $attr Shortcode attributes ('' when it has none).
	 * @return string|false
	 */
	public function dress_cart_shortcode( $html, $tag, $attr ) {
		if ( 'add_to_cart' !== $tag || ! is_string( $html ) || '' === $html || ! is_array( $attr ) ) {
			return $html;
		}
		return $this->move_classes( $html, $this->marked_classes( array( 'attrs' => array( 'className' => $attr['class'] ?? '' ) ), self::CART_MARKER ) );
	}

	/**
	 * A marked list brings the rules that lay its cards out.
	 *
	 * @param string               $html  Rendered block.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	public function dress_list( $html, $block ) {
		if ( null !== $this->marked_classes( $block, self::LIST_MARKER ) ) {
			$this->enqueue_list_rules();
		}
		return $html;
	}

	/**
	 * The list's rules, once per request.
	 */
	private function enqueue_list_rules(): void {
		if ( wp_style_is( self::STYLE_HANDLE ) ) {
			return;
		}
		wp_register_style( self::STYLE_HANDLE, false, array(), ZIPAI_MCP_VERSION );
		wp_add_inline_style( self::STYLE_HANDLE, self::LIST_CSS );
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * The block's own classes when it carries `$marker`, the marker left out; null when it does not.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param string               $marker The converter's mark.
	 * @return array<int, string>|null
	 */
	private function marked_classes( $block, string $marker ): ?array {
		$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$classes = preg_split( '/\s+/', is_string( $attrs['className'] ?? null ) ? $attrs['className'] : '', -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $classes ) || ! in_array( $marker, $classes, true ) ) {
			return null;
		}
		return array_values( array_diff( $classes, array( $marker ) ) );
	}
}
