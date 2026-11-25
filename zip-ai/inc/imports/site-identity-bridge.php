<?php
/**
 * Site identity bridge — a built site's brand shows the site's own title, tagline and logo.
 *
 * The converter makes the design library's brand parts in a built site's header and footer
 * WordPress's own blocks, each marked `zip-ai-identity`: the name Site Title, the tagline
 * Site Tagline, the mark Site Logo. So what the owner sets in WordPress shows there. Each
 * block prints another box than the part the brand drew, so two rules return it to that box:
 *
 *   - the title and the tagline print as a `<p>`, where the brand drew a bare text part: no
 *     paragraph margin, above a theme's own `p` rule and below the design's part classes, so
 *     a margin the design gives the part still wins;
 *   - the logo stands in the mark's place, where the mark's own class draws a square tile
 *     around a drawing; a logo is a picture of its own shape, so the mark takes the logo's
 *     width at the mark's height, with no tile behind it (the build's logo carries its own).
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Imports;

use ZipAI\MCP\Classes\Core\Imported_Chrome;

defined( 'ABSPATH' ) || exit;

/**
 * Returns a site identity block standing in a brand part's place to that part's box.
 *
 * @since 0.0.23
 */
class SiteIdentityBridge {

	/**
	 * The converter's mark on a site identity block, and the handle its rules ride.
	 *
	 * @var string
	 */
	public const MARKER = 'zip-ai-identity';

	/**
	 * The margin reset is (0,1,1): above a theme's paragraph rule (`p`, `body p`; Astra's
	 * `p{margin-bottom:1.75em}`) and below the design's `gs-*` part rules (0,3,0).
	 * The logo rules are (0,3,1) and (0,2,2): above the mark's own `gs-*` rule (0,3,0) and core's
	 * `.wp-block-site-logo.is-default-size img` (0,2,1) and `.wp-block-site-logo a` (0,1,1).
	 * The picture takes the mark's height (`inherit`: a percentage of the mark's grid area
	 * does not resolve), and so does the home link core wraps it in when the owner links
	 * the logo, so the picture inherits the mark's height through it.
	 *
	 * @var string
	 */
	private const CSS = 'body .zip-ai-identity{margin:0}'
		. 'body .wp-block-site-logo.zip-ai-identity.zip-ai-identity{width:auto;background:none}'
		. 'body .wp-block-site-logo.zip-ai-identity a{display:block;height:inherit}'
		. 'body .wp-block-site-logo.zip-ai-identity img{display:block;height:inherit;width:auto;max-width:none}';

	/**
	 * The blocks the converter marks.
	 *
	 * @var string[]
	 */
	private const BLOCKS = array( 'core/site-title', 'core/site-tagline', 'core/site-logo' );

	/**
	 * Register the render filters and the canvas check.
	 */
	public function __construct() {
		foreach ( self::BLOCKS as $name ) {
			add_filter( 'render_block_' . $name, array( $this, 'enqueue_for_block' ), 10, 2 );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_for_canvas' ) );
	}

	/**
	 * A marked identity block brings the rules as it renders: a block theme renders its
	 * template before it prints the head, so they ride the head.
	 *
	 * @param string               $html  Rendered block.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	public function enqueue_for_block( $html, $block ) {
		$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$classes = preg_split( '/\s+/', is_string( $attrs['className'] ?? null ) ? $attrs['className'] : '', -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $classes ) && in_array( self::MARKER, $classes, true ) ) {
			$this->enqueue();
		}
		return $html;
	}

	/**
	 * The takeover canvas prints the head before it renders the chrome, so the rules are asked
	 * for up front when that chrome carries a marked block: the site's stored parts (scope
	 * `site`), or a standalone page's own content, which embeds its chrome (scope `page`).
	 */
	public function enqueue_for_canvas(): void {
		if ( 'site' === Imported_Chrome::$scope ) {
			$chrome = Imported_Chrome::imported_part( 'header' ) . Imported_Chrome::imported_part( 'footer' );
		} elseif ( 'page' === Imported_Chrome::$scope ) {
			$post   = get_queried_object();
			$chrome = $post instanceof \WP_Post ? $post->post_content : '';
		} else {
			return;
		}
		if ( false !== strpos( $chrome, self::MARKER ) ) {
			$this->enqueue();
		}
	}

	/**
	 * The rules, once per request.
	 */
	private function enqueue(): void {
		if ( wp_style_is( self::MARKER ) ) {
			return;
		}
		wp_register_style( self::MARKER, false, array(), ZIPAI_MCP_VERSION );
		wp_add_inline_style( self::MARKER, self::CSS );
		wp_enqueue_style( self::MARKER );
	}
}
