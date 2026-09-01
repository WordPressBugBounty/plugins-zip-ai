<?php
/**
 * Imported Chrome — canvas takeover reader for classic themes.
 *
 * Renders the imported header/footer (stored as `wp_template_part` posts —
 * the SAME storage the FSE hierarchy reads natively) on ANY classic theme by
 * serving a plugin canvas template via the `template_include` filter (the
 * Elementor-Canvas pattern). The theme's templates never run on takeover
 * pages; the theme is never modified — no theme carries any code.
 *
 * Two takeover scopes:
 *   site — option `zipai_chrome_mode === 'takeover'` (written by the
 *          importer after a successful site-wide chrome write): every core
 *          content request renders through the canvas with the imported
 *          header/footer around it.
 *   page — post meta `zipai_chrome_takeover` (standalone imports, chrome
 *          embedded in post_content): only that page renders through the
 *          canvas, bare.
 *
 * Registering the option (show_in_rest) doubles as the backend's capability
 * probe: the key appearing in `GET /wp/v2/settings` is how the importer
 * knows this reader exists BEFORE writing anything. Rollback = empty the
 * option; the theme's own chrome returns untouched.
 *
 * @since 0.0.8
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canvas takeover reader — see the file header for the architecture.
 *
 * Lane structure: register_contract() is lane-agnostic (the option/meta are
 * the activation contract for ANY reader lane); takeover_scope() is the one
 * decision point a future lane extends. No theme conditionals live here —
 * the only theme-shaped checks are core's `wp_is_block_theme()` release
 * guard and the follow-the-theme re-home (maybe_follow_theme), which keys
 * on the active stylesheet, never on a theme's name.
 *
 * Follow-the-theme: chrome parts are written under the import-time theme's
 * `wp_theme` term, so a later theme switch would otherwise resolve stale or
 * missing parts (and kill the page-scope JS riding the header part). The
 * re-home lane promotes the site's zip-marked parts to the new theme's term
 * (term-append, marker-gated) and, for block themes on takeover sites,
 * ensures the bare zip `page` template — so switching themes keeps the
 * imported site intact in BOTH directions, classic and FSE.
 */
class Imported_Chrome {

	const OPTION_KEY    = 'zipai_chrome_mode';
	const MODE_TAKEOVER = 'takeover';
	const PAGE_META_KEY = 'zipai_chrome_takeover';

	/**
	 * The stylesheet the imported chrome is currently "homed" to (see the
	 * class header). Autoloaded and compared against the active stylesheet
	 * on every request, so ANY switch — admin UI, WP-CLI, or one made while
	 * this plugin was deactivated — heals on the next request.
	 */
	const THEME_HOME_OPTION = 'zipai_chrome_theme';

	/**
	 * Class the importer stamps on every imported part's root container — the
	 * provenance marker separating zip-written chrome from user- or
	 * theme-authored `wp_template_part` posts. Anything unmarked is treated
	 * as user-owned: never promoted, never displaced.
	 */
	const ZIP_PART_MARKER = 'zipai-part-root';

	/**
	 * Meta stamped on the `page` wp_template the re-home lane ensures —
	 * ZIP_PART_MARKER's template-tier sibling. It is what lets
	 * conceal_released_template() stand the template down on rollback
	 * without ever touching a user- or build-authored template.
	 */
	const TEMPLATE_META_KEY = '_zipai_chrome_template';

	/**
	 * Import marker the committer stamps on every imported page (registered
	 * by spectra-blocks' AssetLoader). The canvas prints `the_title()` only
	 * for singulars WITHOUT it — imported pages carry their H1 in content.
	 */
	const IMPORT_MARKER_META_KEY = '_zipai_imported';

	/**
	 * The resolved scope for the current request — set by maybe_takeover()
	 * and read by the canvas template ('site' | 'page').
	 *
	 * @var string
	 */
	public static $scope = '';

	/**
	 * Always-on core service (loader.php precedent: Snippet_Executor,
	 * Plugin_Abilities_Toggler). Cheap no-op everywhere it doesn't apply:
	 * the filter exits on one autoloaded get_option / block-theme check.
	 *
	 * @since 0.0.8
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_contract' ) );
		// After register_contract, before any template resolution: the first
		// request after a theme switch re-homes the chrome, so even that very
		// first front-end view renders the right parts.
		add_action( 'init', array( __CLASS__, 'maybe_follow_theme' ), 20 );
		add_action( 'after_setup_theme', array( __CLASS__, 'enable_part_editing' ) );
		add_filter( 'template_include', array( __CLASS__, 'maybe_takeover' ), PHP_INT_MAX - 10 );
		// FSE mirror of the canvas's per-request option check: the ensured
		// `page` template stands down while takeover is off (the rollback
		// contract in the file header).
		add_filter( 'get_block_templates', array( __CLASS__, 'conceal_released_template' ) );
	}

	/**
	 * Native edit surface for the imported chrome on CLASSIC themes: core's
	 * `block-template-parts` support (WP 6.1+) adds Appearance → Template
	 * Parts — a block-editor screen over the SAME `wp_template_part` posts
	 * the canvas renders, so a user edit shows on the next front-end load
	 * with no importer round-trip. Gated on the takeover option: the menu
	 * appears exactly when the canvas owns the chrome (standalone-only sites
	 * edit their chrome inside the page itself). Block themes already have
	 * the full Site Editor — the flag is theirs to ignore.
	 *
	 * @since 0.0.8
	 * @return void
	 */
	public static function enable_part_editing(): void {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return;
		}
		if ( self::is_takeover_active() ) {
			add_theme_support( 'block-template-parts' );
		}
	}

	/**
	 * Register the activation contract — both REST-visible because the
	 * importer writes them over `/wp/v2/settings` and `/wp/v2/pages/{id}`,
	 * and the OPTION key's presence in GET /wp/v2/settings is the backend's
	 * capability probe (probeChromeReader).
	 *
	 * @since 0.0.8
	 * @return void
	 */
	public static function register_contract(): void {
		register_setting(
			'general',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'show_in_rest'      => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			)
		);
		register_post_meta(
			'page',
			self::PAGE_META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);
	}

	/**
	 * The `template_include` gate — THIN, ordered guards; the first hit
	 * returns the incoming template untouched.
	 *
	 * @since 0.0.8
	 * @param string $template The template WordPress resolved.
	 * @return string
	 */
	public static function maybe_takeover( $template ) {
		// 1. Never outside a normal frontend render.
		if ( is_admin() || is_feed() || is_embed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $template;
		}
		// 2. Resolve the takeover scope (null = leave the theme alone).
		$scope = self::takeover_scope();
		if ( null === $scope ) {
			return $template;
		}
		// 3. Block themes render SITE-wide chrome natively via the FSE
		// hierarchy — the canvas must never fight the native engine for
		// site scope (covers option-still-set after a classic→FSE theme
		// switch). Marker ('page') scope stays on the canvas even on block
		// themes: a standalone page's chrome is EMBEDDED in post_content,
		// and a native template would double-wrap it with theme chrome.
		if ( 'site' === $scope && function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return $template;
		}
		// 4. A missing canvas file (broken deploy) must degrade to the
		// theme, never white-screen the site — the documented
		// template_include pitfall.
		$canvas = ZIPAI_MCP_DIR . 'inc/templates/imported-chrome-canvas.php';
		if ( ! is_readable( $canvas ) ) {
			return $template;
		}
		self::$scope = $scope;
		return $canvas;
	}

	/**
	 * PURE scope decision — the one place a future reader lane extends.
	 *
	 *   'page' — singular carrying the standalone marker (checked FIRST:
	 *            the marker WINS over the site option, so a page with
	 *            embedded chrome is never double-wrapped by site parts).
	 *   'site' — option active, core content only (never CPT territory),
	 *            no explicit page-template assignment, and at least one
	 *            stored part resolves (else RELEASE — e.g. after a
	 *            classic→classic theme switch the parts are keyed to the
	 *            old theme; a naked canvas is strictly worse than the
	 *            theme's own chrome).
	 *   null   — no takeover.
	 *
	 * @since 0.0.8
	 * @return string|null
	 */
	public static function takeover_scope() {
		$post_id = is_singular() ? get_the_ID() : false;
		// Marker wins over the site option (anti-double-chrome) — but a
		// LATER explicit template assignment on the page wins over the
		// marker: the user took ownership of that page's rendering.
		if (
			false !== $post_id
			&& '' !== self::meta_string( $post_id, self::PAGE_META_KEY )
			&& '' === (string) get_page_template_slug( $post_id )
		) {
			return 'page';
		}
		if ( ! self::is_takeover_active() ) {
			return null;
		}
		// Core content only: pages, posts, home, CORE archives (category/
		// tag/date/author), search, 404. CPT singulars, post-type archives
		// and custom-taxonomy archives (Woo, LMS, …) keep their own
		// machinery — bare is_archive() would hijack /shop.
		$is_core_archive = is_archive() && ! is_post_type_archive() && ! is_tax();
		$is_core_content = is_singular( array( 'page', 'post' ) ) || is_home() || $is_core_archive || is_search() || is_404();
		if ( ! $is_core_content ) {
			return null;
		}
		// An explicitly assigned page template (Elementor Canvas, theme
		// full-width, …) always wins — structural respect, no name-lists.
		if ( false !== $post_id && '' !== (string) get_page_template_slug( $post_id ) ) {
			return null;
		}
		// Release when nothing resolves: parts are keyed to the import-time
		// theme term; render the theme's own chrome instead of an empty shell.
		if ( '' === self::imported_part( 'header' ) && '' === self::imported_part( 'footer' ) ) {
			return null;
		}
		return 'site';
	}

	/**
	 * Whether site-wide takeover is switched on — STRICT comparison, so the
	 * legacy theme-prototype value 'astra' (and any other value) never
	 * engages the plugin lane. Public seam: theme-independent, so tests can
	 * assert the mutual-exclusion contract without a main query or a
	 * classic theme active.
	 *
	 * @since 0.0.8
	 * @return bool
	 */
	public static function is_takeover_active(): bool {
		return self::MODE_TAKEOVER === get_option( self::OPTION_KEY );
	}

	/**
	 * The imported template part's block markup for an area, or '' when
	 * absent. Parts are namespaced by the ACTIVE stylesheet (child-theme
	 * safe — the importer addresses writes the same way); parent slug is
	 * the fallback.
	 *
	 * @since 0.0.8
	 * @param string $slug 'header' or 'footer'.
	 * @return string Block markup.
	 */
	public static function imported_part( $slug ): string {
		// ponytail: two keyed queries per takeover view; add a transient
		// cache keyed theme+slug (invalidate on wp_template_part save) only
		// if profiling ever shows it.
		//
		// `post_name__in`, NOT `name`: a `name` query is singular-shaped and
		// WP_Query silently DROPS tax_query on singular queries — the theme
		// prototype's lookup was never actually theme-scoped ("newest part
		// wins globally", masked because the active theme's parts were
		// always newest; measured live 2026-07-13 on a theme switch).
		foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $theme ) {
			$posts = get_posts(
				array(
					'post_type'     => 'wp_template_part',
					'post_name__in' => array( $slug ),
					'post_status'   => 'publish',
					'numberposts'   => 1,
					'orderby'       => 'date',
					'order'         => 'DESC',
					'tax_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'wp_theme',
							'field'    => 'slug',
							'terms'    => $theme,
						),
					),
				)
			);
			if ( ! empty( $posts ) && '' !== $posts[0]->post_content ) {
				return $posts[0]->post_content;
			}
		}
		return '';
	}

	/**
	 * Make the imported chrome FOLLOW a theme switch.
	 *
	 * Runs on every request; exits on one autoloaded-option compare in the
	 * steady state. On a mismatch (the active stylesheet is not the one the
	 * chrome is homed to) it re-homes once, then stamps the new home. Lazy
	 * option-compare instead of the `switch_theme` action so switches that
	 * fire where the hook can't see them — WP-CLI on another process model,
	 * a switch while this plugin was deactivated — still heal on the next
	 * request.
	 *
	 * Never during a theme PREVIEW: `get_stylesheet()` is filtered to the
	 * previewed theme there, and re-homing on a preview would move the chrome
	 * under a theme the admin is only looking at.
	 *
	 * Deliberately un-locked: the heal is idempotent (term-append and de-term
	 * converge), so simultaneous first requests after a switch — or a
	 * per-request `stylesheet` filter re-healing every load — cost redundant
	 * reads at worst. A lock's stuck-state failure mode (the heal never runs
	 * again) is the worse trade.
	 *
	 * @since 0.0.9
	 * @return void
	 */
	public static function maybe_follow_theme(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check; no state derives from the value.
		if ( is_customize_preview() || isset( $_GET['wp_theme_preview'] ) ) {
			return;
		}
		$current = get_stylesheet();
		$homed   = get_option( self::THEME_HOME_OPTION, '' );
		if ( $current === $homed ) {
			return;
		}
		self::rehome_chrome( is_string( $homed ) ? $homed : '', $current );
		update_option( self::THEME_HOME_OPTION, $current );
	}

	/**
	 * Re-home the site's imported chrome from one theme term to another.
	 *
	 * PROMOTION IS TERM-APPEND, NEVER A CONTENT COPY. Appending the new
	 * `wp_theme` term to the SAME `wp_template_part` post means: no content
	 * write (so the `spectraCustomJS` riding the part — scroll reveals, nav
	 * toggle — can never be stripped by the save-time JS gate, which only
	 * lets snippets through for `unfiltered_html` users), no duplicate posts,
	 * and edits made under one theme show under every other. A part
	 * accumulates one term per theme it has served; switching back is a
	 * no-op append.
	 *
	 * DISPLACEMENT IS MARKER-GATED. A zip-marked part already carrying the
	 * new theme's term is a STALE SIBLING — chrome of whatever site was last
	 * built under that theme (measured live 2026-08-17: without this, core's
	 * newest-first part resolution rendered that previous site's header and
	 * footer). It loses the term, nothing else — content and its other terms
	 * survive. An UNMARKED part in the slot means the slot is user- or
	 * theme-authored: the whole slug is left alone, promotion included.
	 *
	 * Public seam (like {@see self::is_takeover_active()}): tests drive it
	 * with throwaway theme terms, so the live slots never move.
	 *
	 * @since 0.0.9
	 * @param string $from Stylesheet the chrome is homed to ('' = unknown, first run).
	 * @param string $to   Stylesheet to home it to (the active theme).
	 * @return void
	 */
	public static function rehome_chrome( string $from, string $to ): void {
		$promoted = 0;
		foreach ( array( 'header', 'footer' ) as $slug ) {
			$source = null;
			foreach ( self::part_posts( $slug, $from ) as $candidate ) {
				if ( self::is_zip_part( $candidate ) ) {
					$source = $candidate;
					break;
				}
			}
			if ( null === $source ) {
				// First run, or the home option predates the last build:
				// the newest zip-marked part IS the current site's chrome
				// (the importer updates the site-wide slot in place, so the
				// newest modified part is always the live site's).
				$source = self::newest_zip_part( $slug );
			}
			if ( null === $source ) {
				continue;
			}
			$stale   = array();
			$foreign = false;
			foreach ( self::part_posts( $slug, $to ) as $occupant ) {
				if ( $occupant->ID === $source->ID ) {
					continue;
				}
				if ( self::is_zip_part( $occupant ) ) {
					$stale[] = $occupant->ID;
				} else {
					$foreign = true;
				}
			}
			if ( $foreign ) {
				continue;
			}
			wp_set_object_terms( $source->ID, $to, 'wp_theme', true );
			foreach ( $stale as $stale_id ) {
				wp_remove_object_terms( $stale_id, $to, 'wp_theme' );
			}
			++$promoted;
		}
		// Block themes render through the FSE hierarchy, so a site whose
		// chrome the canvas owned site-wide (takeover) also needs the bare
		// zip `page` template there — without it the theme's own page
		// template wraps every imported page in its banner/title/width.
		if ( $promoted > 0 && self::is_takeover_active() && wp_is_block_theme() ) {
			self::ensure_page_template( $to );
		}
	}

	/**
	 * All published parts in a theme's slot.
	 *
	 * `post_name__in`, NOT `name` — a `name` query is singular-shaped and
	 * WP_Query silently DROPS tax_query on singular queries (measured live
	 * 2026-07-13; same trap as {@see self::imported_part()}).
	 *
	 * @since 0.0.9
	 * @param string $slug  'header' or 'footer'.
	 * @param string $theme Theme term slug ('' returns nothing).
	 * @return \WP_Post[]
	 */
	private static function part_posts( string $slug, string $theme ): array {
		if ( '' === $theme ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'     => 'wp_template_part',
				'post_name__in' => array( $slug ),
				'post_status'   => 'publish',
				'numberposts'   => -1,
				// Modified DESC — the SAME invariant as newest_zip_part():
				// the importer upserts the slot in place, so created dates
				// lie. rehome_chrome()'s source pick takes the FIRST zip part
				// returned; without this the created-date default could hand
				// it a stale sibling as the promotion source.
				'orderby'       => 'modified',
				'order'         => 'DESC',
				'tax_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'slug',
						'terms'    => $theme,
					),
				),
			)
		);
	}

	/**
	 * The newest zip-marked part in a slot, across ALL theme terms.
	 *
	 * Ordered by MODIFIED: the importer upserts the site-wide slot in place,
	 * so the most recently modified zip part is the live site's chrome even
	 * when its created date is old.
	 *
	 * @since 0.0.9
	 * @param string $slug 'header' or 'footer'.
	 * @return \WP_Post|null
	 */
	private static function newest_zip_part( string $slug ): ?\WP_Post {
		$posts = get_posts(
			array(
				'post_type'     => 'wp_template_part',
				'post_name__in' => array( $slug ),
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'orderby'       => 'modified',
				'order'         => 'DESC',
			)
		);
		foreach ( $posts as $post ) {
			if ( self::is_zip_part( $post ) ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Whether a template part was written by the importer.
	 *
	 * @since 0.0.9
	 * @param \WP_Post $post Template-part post.
	 * @return bool
	 */
	private static function is_zip_part( \WP_Post $post ): bool {
		return false !== strpos( $post->post_content, self::ZIP_PART_MARKER );
	}

	/**
	 * Ensure the bare zip `page` wp_template exists for a block theme.
	 *
	 * Imported pages carry their own H1 and full-width sections in
	 * post_content; a theme's page template (title banner + constrained
	 * content) double-titles and squeezes them. The bare template is
	 * header part + full-width post-content + footer part — the same shape
	 * the importer writes on FSE-delivery builds.
	 *
	 * Resolution order: an existing `page` template for this theme wins
	 * (zip-written or user-authored alike); else a template this lane
	 * previously stamped ({@see self::TEMPLATE_META_KEY}) under ANOTHER term
	 * is term-appended (its part refs keep resolving — the parts accumulate
	 * terms the same way); else a
	 * fresh insert. The fresh insert forces its exact slug past core's
	 * collision suffixing (`pre_wp_unique_post_slug`) — a suffixed template
	 * matches nothing in the hierarchy, and the guard above already proved
	 * this theme's slot is empty. Every template this lane claims — reused
	 * or fresh — is stamped {@see self::TEMPLATE_META_KEY} so
	 * {@see self::conceal_released_template()} can stand it down on rollback.
	 *
	 * Public seam (like {@see self::rehome_chrome()}): tests exercise the
	 * reuse branch with a throwaway term.
	 *
	 * @since 0.0.9
	 * @param string $theme Active (block) theme stylesheet.
	 * @return void
	 */
	public static function ensure_page_template( string $theme ): void {
		$slot      = array(
			'post_type'     => 'wp_template',
			'post_name__in' => array( 'page' ),
			'post_status'   => 'publish',
			'numberposts'   => -1,
		);
		$for_theme = get_posts(
			$slot + array(
				'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'slug',
						'terms'    => $theme,
					),
				),
			)
		);
		if ( ! empty( $for_theme ) ) {
			return;
		}
		// Reuse is provenance-gated exactly like part promotion: only a
		// template THIS lane stamped is ever appropriated. A user-authored
		// bare template that merely LOOKS like ours (post-content +
		// template-part, no title) is someone's content — appropriating it
		// would stamp it, and rollback would then conceal it on its home
		// theme too.
		foreach ( get_posts( $slot ) as $candidate ) {
			if ( '1' === get_post_meta( $candidate->ID, self::TEMPLATE_META_KEY, true ) ) {
				wp_set_object_terms( $candidate->ID, $theme, 'wp_theme', true );
				return;
			}
		}
		$force = static function () {
			return 'page';
		};
		add_filter( 'pre_wp_unique_post_slug', $force, 100 );
		$template_id = wp_insert_post(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'page',
				'post_title'   => 'Pages',
				'post_status'  => 'publish',
				'post_content' => implode(
					"\n",
					array(
						// No `"theme"` pin on the refs: the template follows
						// the active theme, so active-theme-relative refs
						// resolve the parts this same pass just promoted.
						'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->',
						'<!-- wp:post-content {"align":"full","layout":{"type":"constrained"}} /-->',
						'<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
					)
				),
			),
			true
		);
		remove_filter( 'pre_wp_unique_post_slug', $force, 100 );
		if ( is_wp_error( $template_id ) ) {
			return;
		}
		update_post_meta( $template_id, self::TEMPLATE_META_KEY, '1' );
		wp_set_object_terms( $template_id, $theme, 'wp_theme' );
	}

	/**
	 * Stand the ensured `page` template down while takeover is OFF.
	 *
	 * The bare template is the FSE rendition of the classic canvas — and the
	 * canvas checks the option on EVERY request, so its template must obey
	 * the same switch, or rollback (empty the option) leaves every page on a
	 * block theme rendering bare. Concealed, never deleted: re-enabling
	 * takeover restores it instantly (a delete could not — nothing would
	 * re-create the template until the next theme switch), and the hierarchy
	 * falls back to the theme's own `page` template while it is hidden.
	 * Meta-gated provenance ({@see self::TEMPLATE_META_KEY}): only a template
	 * this lane ensured is ever touched — user- and build-authored templates
	 * pass through untouched, always.
	 *
	 * @since 0.0.9
	 * @param \WP_Block_Template[] $templates `get_block_templates()` result.
	 * @return \WP_Block_Template[]
	 */
	public static function conceal_released_template( $templates ) {
		if ( self::is_takeover_active() ) {
			return $templates;
		}
		// Never re-homed → this lane never stamped a template. One autoloaded
		// option compare spares every un-imported install the per-template
		// meta walk below.
		if ( '' === get_option( self::THEME_HOME_OPTION, '' ) ) {
			return $templates;
		}
		$kept = array();
		foreach ( $templates as $template ) {
			if (
				empty( $template->wp_id )
				|| '1' !== get_post_meta( (int) $template->wp_id, self::TEMPLATE_META_KEY, true )
			) {
				$kept[] = $template;
				continue;
			}
			// Concealing the custom template alone leaves a HOLE, not a
			// fallback (measured live 2026-08-17, wp-playground): core drops
			// the theme's FILE template from this result set whenever a
			// custom one with the same slug exists, so with ours removed the
			// hierarchy fell through to `index`. Removal must put the
			// theme's own file template back in its place.
			$file = get_block_file_template( get_stylesheet() . '//' . $template->slug, 'wp_template' );
			if ( $file instanceof \WP_Block_Template ) {
				$kept[] = $file;
			}
		}
		return $kept;
	}

	/**
	 * Whether the current singular post was created by the importer — the
	 * canvas prints `the_title()` only when it was NOT (imported pages
	 * carry their H1 in post_content; non-imported posts/pages must not
	 * lose their headline).
	 *
	 * @since 0.0.8
	 * @return bool
	 */
	public static function is_imported_post(): bool {
		$post_id = is_singular() ? get_the_ID() : false;
		return false !== $post_id && '' !== self::meta_string( $post_id, self::IMPORT_MARKER_META_KEY );
	}

	/**
	 * Single post-meta value as a string ('' when unset or non-string).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private static function meta_string( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );
		return is_string( $value ) ? $value : '';
	}
}
