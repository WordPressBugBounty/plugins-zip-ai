<?php
/**
 * Import Texture Gate. This disables WordPress `wptexturize` on imported pages.
 *
 * Why this exists
 * ----------------
 * WordPress runs `wptexturize` on every `the_content` render. The filter
 * silently rewrites characters in the post body:
 *
 *   `'`     → `’`        (curly right single quotation mark, U+2019)
 *   `"foo"` → `“foo”`    (curly double quotes)
 *   `--`    → `—`        (em-dash)
 *   `...`   → `…`        (horizontal ellipsis)
 *
 * For a blog post this is a usability win. For a designed page imported from
 * raw HTML it is text corruption. The source authored "I'd" with ASCII U+0027
 * and expects that exact glyph. The rendered page ships "I’d" with U+2019. That
 * glyph renders about 10px wider in most fonts. It shifts text wrapping. This
 * was verified on adam-preiser. The hero h1 wrapped to 5 lines on the import
 * and 4 lines on the source. The CSS, font, width and line-height matched. Only
 * the apostrophe character differed. This added 73px of vertical drift. Across
 * every paragraph with punctuation, the whole hero section grew 186px taller
 * than the source.
 *
 * Scope
 * -----
 * This is active only when the rendered post has the
 * `spectra_blocks_pro_gs_user_css` post meta. The FSE import pipeline sets that
 * meta. All non-imported posts still get the standard wptexturize treatment.
 *
 * `run_wptexturize` is a core WordPress filter. It short-circuits
 * `wptexturize()` entirely. Returning `false` makes the function pass text
 * through unchanged. The filter is checked once per `wptexturize()` call, not
 * per character. So this adds one extra `get_post_meta` lookup per
 * imported-page render. The result is cached statically after the first call.
 * This avoids repeating it across the many internal wptexturize calls on a
 * single page.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Imports;

defined( 'ABSPATH' ) || exit;

/**
 * Disables wptexturize on imported posts so authored characters render
 * verbatim (no `'`→`’`, `"`→`“”`, `--`→`—`, `...`→`…` rewrites).
 *
 * @since 0.0.5
 */
class ImportTextureGate {
	/**
	 * Per-page imported-CSS post-meta key (the GBS-local store). Its presence
	 * marks a page as FSE-imported. Mirrors spectra-blocks'
	 * `GenCssOrphanStripper::META_KEY` (kept local to avoid coupling to a
	 * spectra-blocks class that may be inactive).
	 *
	 * @var string
	 */
	private const PAGE_CSS_META_KEY = 'spectra_blocks_pro_gs_user_css';

	/**
	 * Register the wptexturize gate filter.
	 *
	 * @return void
	 */
	public function __construct() {
		add_filter( 'run_wptexturize', array( $this, 'maybe_disable' ) );
	}

	/**
	 * Filter callback. Returns `false` (disable wptexturize) when the
	 * current post is an imported page; otherwise returns the value
	 * unchanged so other plugins / WP defaults still drive the decision.
	 *
	 * @param bool $run Current wptexturize-enabled state.
	 * @return bool
	 */
	public function maybe_disable( bool $run ): bool {
		// Skip admin views — block editor (and any admin renders of
		// post content) should see the WP-standard treatment. The
		// editor doesn't actually run wptexturize on the served block
		// markup, but we keep the admin/REST skips for consistency.
		if ( is_admin() ) {
			return $run;
		}

		// Skip REST renders (block editor's `ServerSideRender` preview)
		// for the same consistency reason.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $run;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $run;
		}

		// Gate: per-page imported CSS post meta exists IFF the page came
		// through the FSE import pipeline.
		/**
		 * Narrowed type for `$is_imported_cache`.
		 *
		 * @var array<int,bool> $is_imported_cache
		 */
		static $is_imported_cache = array();
		if ( ! isset( $is_imported_cache[ $post_id ] ) ) {
			$is_imported_cache[ $post_id ] = '' !== get_post_meta( $post_id, self::PAGE_CSS_META_KEY, true );
		}

		return $is_imported_cache[ $post_id ] ? false : $run;
	}
}
