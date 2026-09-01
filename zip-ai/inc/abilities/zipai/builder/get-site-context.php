<?php
/**
 * Get Site Context — a read-only site snapshot for external AI clients.
 *
 * This is the counterpart to `zipai/import-html`. The client's own model
 * writes the HTML. Without this snapshot the model writes blind. It knows
 * nothing about the palette, the fonts, the existing pages or the brand of the
 * site. So imported pages clash with the design. They also collide with
 * existing slugs. One call returns the facts to author HTML that fits THIS
 * site.
 *
 * The snapshot holds facts only. It gives no instructions. The palette is the
 * LIVE WordPress global palette. It is origin-aware. The user palette from the
 * Site Editor wins over the stock theme palette. This single read is correct
 * with or without Spectra. The Style Guide colour sync pushes its role colours
 * INTO these stores. These stores are the FSE user palette and the Astra
 * slots. A plain block theme serves its own palette. Astra slots arrive as
 * ast-global-color-N presets.
 *
 * The code filters out machine entries. These are `spectra-*` ramp tokens,
 * `sg-*` sync mirrors and CSS keywords like currentColor. So the agent sees
 * about a dozen semantic colours. It does not see the 100+ internal ones.
 * Fonts are the resolved heading and body pairing from global styles. The code
 * follows the preset and custom var chains to the family string. Pages arrive
 * as slug and title. So links stay coherent and new slugs avoid collisions.
 * Brand context comes from the stored `zip_ai_brand_context` option when the
 * design system has captured one.
 *
 * @since 0.0.8
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\Builder;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Imported_Chrome;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Ability: read the site identity + design system an HTML author must match.
 */
class GetSiteContext extends Abstract_Ability {

	/**
	 * Read-only by nature.
	 *
	 * @var bool
	 */
	protected $is_destructive = false;

	/**
	 * Configures the ability's id, label, description and metadata.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/get-site-context';
		$this->label       = 'Get Site Context';
		$this->description = 'Read-only snapshot of this site for authoring HTML that matches it: title/tagline/language/URL, active theme, the live colour palette (palette: slug/name/hex, user customisations included), the resolved heading/body font pairing, existing pages (slug, title, front page) and stored brand context. Call this BEFORE writing HTML for zipai/import-html: match the returned palette and fonts, and pick a slug that does not collide with an existing page.';
		// Read surface. Deliberately looser than the ingress floor (like
		// run-rest-request): everything returned is design tokens and published
		// page titles, and the `manage_options` MCP ingress dominates anyway.
		$this->capability = 'edit_posts';
		// Hidden from the AI chat catalog — the server reads site state itself
		// over MCP (site-state fetcher); only external clients need this door,
		// and the external MCP server advertises it explicitly.
		$this->meta['visibility'] = 'internal';
	}

	/**
	 * Returns the tool-type classification for this ability.
	 *
	 * @return string One of the Tool_Types constants.
	 */
	public function get_tool_type() {
		return Tool_Types::READ;
	}

	/**
	 * Returns the JSON Schema for this ability's input arguments.
	 *
	 * @return array<string,mixed> JSON Schema describing accepted arguments.
	 */
	public function get_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the JSON Schema for this ability's response.
	 *
	 * @return array<string,mixed> JSON Schema describing the response shape.
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'data'    => array(
					'type'       => 'object',
					'properties' => array(
						'site'          => array(
							'type'       => 'object',
							'properties' => array(
								'title'    => array( 'type' => 'string' ),
								'tagline'  => array( 'type' => 'string' ),
								'language' => array( 'type' => 'string' ),
								'url'      => array( 'type' => 'string' ),
								'logo_url' => array( 'type' => 'string' ),
							),
						),
						'theme'         => array(
							'type'       => 'object',
							'properties' => array(
								'name'            => array( 'type' => 'string' ),
								'is_block_theme'  => array( 'type' => 'boolean' ),
								'imported_chrome' => array( 'type' => 'boolean' ),
							),
						),
						// Live global palette: [{ slug, name, hex }].
						'palette'       => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug' => array( 'type' => 'string' ),
									'name' => array( 'type' => 'string' ),
									'hex'  => array( 'type' => 'string' ),
								),
							),
						),
						// Resolved font pairing: { heading?: string, body?: string }.
						'fonts'         => array(
							'type'       => 'object',
							'properties' => array(
								'heading' => array( 'type' => 'string' ),
								'body'    => array( 'type' => 'string' ),
							),
						),
						'pages'         => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'     => array( 'type' => 'string' ),
									'title'    => array( 'type' => 'string' ),
									'is_front' => array( 'type' => 'boolean' ),
								),
							),
						),
						'brand_context' => array( 'type' => 'object' ),
					),
				),
			),
		);
	}

	/**
	 * Assemble the snapshot.
	 *
	 * @param array<string,mixed> $input Validated input arguments (unused).
	 * @return array<string,mixed> Standardized success response.
	 */
	public function execute( $input = array() ) {
		$theme = wp_get_theme();

		$site     = array(
			'title'    => (string) get_bloginfo( 'name' ),
			'tagline'  => (string) get_bloginfo( 'description' ),
			'language' => (string) get_bloginfo( 'language' ),
			'url'      => (string) home_url(),
		);
		$logo_mod = get_theme_mod( 'custom_logo' );
		$logo_id  = is_numeric( $logo_mod ) ? (int) $logo_mod : 0;
		if ( $logo_id > 0 ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( is_string( $logo_url ) && '' !== $logo_url ) {
				$site['logo_url'] = $logo_url;
			}
		}

		// Live global palette — one read that is correct with or without
		// Spectra: the Style Guide's colour sync (v2) pushes its role colours
		// INTO these stores, Astra's slots register as ast-global-color-N
		// presets, plain block themes serve their own. Origin preference and
		// machine-token filtering live in palette_from_node().
		$raw_settings    = wp_get_global_settings();
		$global_settings = is_array( $raw_settings ) ? $raw_settings : array();
		$palette         = $this->palette_from_node( $this->array_node( $global_settings, 'color', 'palette' ) );

		// The resolved heading/body font pairing from global styles — what the
		// site actually renders with, not the theme's whole font library.
		$styles       = wp_get_global_styles();
		$fonts        = array();
		$root_typo    = $this->array_node( $styles, 'typography' );
		$heading_typo = $this->array_node( $styles, 'elements', 'heading', 'typography' );
		$body         = $this->resolve_font_var(
			isset( $root_typo['fontFamily'] ) && is_string( $root_typo['fontFamily'] )
				? $root_typo['fontFamily']
				: '',
			$global_settings
		);
		$heading      = $this->resolve_font_var(
			isset( $heading_typo['fontFamily'] ) && is_string( $heading_typo['fontFamily'] )
				? $heading_typo['fontFamily']
				: '',
			$global_settings
		);
		if ( '' !== $heading ) {
			$fonts['heading'] = $heading;
		}
		if ( '' !== $body ) {
			$fonts['body'] = $body;
		}

		$pages = array();
		// `page_on_front` can hold a stale id while the site shows latest
		// posts — only a `show_on_front = page` site has a front PAGE at all.
		$front_raw = 'page' === get_option( 'show_on_front' ) ? get_option( 'page_on_front' ) : 0;
		$front_id  = is_numeric( $front_raw ) ? (int) $front_raw : 0;
		$page_rows = get_pages( array( 'number' => 100 ) );
		foreach ( is_array( $page_rows ) ? $page_rows : array() as $page ) {
			$pages[] = array(
				'slug'     => (string) $page->post_name,
				'title'    => (string) $page->post_title,
				'is_front' => $page->ID === $front_id,
			);
		}

		// Stored by the design system; may be a JSON string or an array
		// (same tolerant read as the chat panel's ZIPAI_CONFIG.brandContext).
		$brand_context = get_option( 'zip_ai_brand_context', array() );
		if ( is_string( $brand_context ) ) {
			$brand_context = json_decode( $brand_context, true );
		}
		if ( ! is_array( $brand_context ) ) {
			$brand_context = array();
		}

		$data = array(
			'site'  => $site,
			'theme' => array(
				'name'            => (string) $theme->get( 'Name' ),
				'is_block_theme'  => (bool) $theme->is_block_theme(),
				// True when the zip-ai canvas renders imported header/footer
				// site-wide — the site's visible chrome is the IMPORTED design,
				// whatever the theme says.
				'imported_chrome' => Imported_Chrome::is_takeover_active(),
			),
			'pages' => $pages,
		);
		if ( ! empty( $palette ) ) {
			$data['palette'] = $palette;
		}
		if ( ! empty( $fonts ) ) {
			$data['fonts'] = $fonts;
		}
		if ( ! empty( $brand_context ) ) {
			$data['brand_context'] = $brand_context;
		}

		$message = sprintf(
			'Site context for "%s": %d pages, %s.',
			$site['title'],
			count( $pages ),
			! empty( $palette )
				? 'a palette of ' . count( $palette ) . ' colours'
				: 'no global palette (rely on brand_context or ask the user)'
		);

		return Response::success( $message, $data );
	}

	/**
	 * Resolve a global-styles font value to the actual family string.
	 *
	 * Themes rarely store the family literally — Spectra One chains
	 * `var(--wp--custom--font-family--body)` → custom['font-family']['body']
	 * → `var(--wp--preset--font-family--inter)` → the preset's fontFamily
	 * (measured live). Follows both var shapes against the settings tree,
	 * trying each custom path segment as-written and camelCased (WP kebab-cases
	 * custom keys when minting the var, so `fontFamily` and `font-family`
	 * produce the SAME var name), bounded to a few hops; anything unresolvable
	 * returns '' rather than handing the agent a CSS var it cannot render.
	 *
	 * @param string       $value    Font value from global styles.
	 * @param array<mixed> $settings Full wp_get_global_settings() tree.
	 * @return string Family string, or '' when absent/unresolvable.
	 */
	private function resolve_font_var( string $value, array $settings ): string {
		for ( $hop = 0; $hop < 4; $hop++ ) {
			$value = trim( $value );
			if ( '' === $value ) {
				return '';
			}
			if ( ! preg_match( '/^var\(\s*--wp--(preset|custom)--(.+?)\s*\)$/', $value, $m ) ) {
				return $value;
			}
			if ( 'preset' === $m[1] ) {
				// --wp--preset--font-family--{slug} → typography.fontFamilies.
				if ( ! preg_match( '/^font-family--([a-z0-9-]+)$/i', $m[2], $pm ) ) {
					return '';
				}
				$value = $this->preset_family( $pm[1], $settings );
				continue;
			}
			// --wp--custom--{a--b--…} → settings['custom'][a][b]…
			$node = $this->array_node( $settings, 'custom' );
			foreach ( explode( '--', $m[2] ) as $segment ) {
				if ( ! is_array( $node ) ) {
					return '';
				}
				if ( ! isset( $node[ $segment ] ) ) {
					// WP kebab-cases custom keys when minting the CSS var, so a
					// camelCase theme.json key (fontFamily) produces the SAME
					// var name as a hyphenated one — try that spelling too.
					$segment = lcfirst( str_replace( ' ', '', ucwords( str_replace( '-', ' ', $segment ) ) ) );
					if ( ! isset( $node[ $segment ] ) ) {
						return '';
					}
				}
				$node = $node[ $segment ];
			}
			if ( ! is_string( $node ) ) {
				return '';
			}
			$value = $node;
		}
		return '';
	}

	/**
	 * Collect usable colours from a global-settings palette node.
	 *
	 * Origin-aware: the Site Editor's user palette ('custom') wins over the
	 * theme's stock one, and core's 'default' grayscale is ignored. An origin
	 * only wins when it yields at least one USABLE colour after filtering —
	 * a user layer holding only machine tokens must fall through to the
	 * theme layer, not blank the palette. Machine entries are dropped:
	 * `spectra-*` ramp tokens and `sg-*` sync mirrors duplicate the semantic
	 * head (measured live: 115 raw entries, ~19 semantic), and CSS keywords
	 * (currentColor/inherit/transparent) are not colours an author can match.
	 *
	 * @param array<mixed> $palette_node The settings' color.palette node.
	 * @return array<int,array{slug: string, name: string, hex: string}> Capped at 30.
	 */
	private function palette_from_node( array $palette_node ): array {
		foreach ( array( 'custom', 'theme' ) as $origin ) {
			if ( empty( $palette_node[ $origin ] ) || ! is_array( $palette_node[ $origin ] ) ) {
				continue;
			}
			$collected = array();
			foreach ( $palette_node[ $origin ] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['color'] ) || ! is_string( $entry['color'] ) ) {
					continue;
				}
				$slug = isset( $entry['slug'] ) && is_string( $entry['slug'] ) ? $entry['slug'] : '';
				if ( 0 === strpos( $slug, 'spectra-' ) || 0 === strpos( $slug, 'sg-' ) ) {
					continue;
				}
				if ( ! preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\(|hsla?\()/', $entry['color'] ) ) {
					continue;
				}
				$collected[] = array(
					'slug' => $slug,
					'name' => isset( $entry['name'] ) && is_string( $entry['name'] ) ? $entry['name'] : '',
					'hex'  => $entry['color'],
				);
			}
			if ( ! empty( $collected ) ) {
				return array_slice( $collected, 0, 30 );
			}
		}
		return array();
	}

	/**
	 * Walk nested array offsets, returning array() the moment a level is
	 * missing or not an array — wp_get_global_settings()/wp_get_global_styles()
	 * hand back untyped trees.
	 *
	 * @param mixed  $tree    Root value.
	 * @param string ...$path Offsets to descend.
	 * @return array<mixed>
	 */
	private function array_node( $tree, string ...$path ): array {
		foreach ( $path as $key ) {
			if ( ! is_array( $tree ) || ! isset( $tree[ $key ] ) ) {
				return array();
			}
			$tree = $tree[ $key ];
		}
		return is_array( $tree ) ? $tree : array();
	}

	/**
	 * Look up a font-family preset by slug across origins (user wins).
	 *
	 * @param string       $slug     Preset slug.
	 * @param array<mixed> $settings Full wp_get_global_settings() tree.
	 * @return string The preset's fontFamily, or '' when not found.
	 */
	private function preset_family( string $slug, array $settings ): string {
		$families = $this->array_node( $settings, 'typography', 'fontFamilies' );
		foreach ( array( 'custom', 'theme', 'default' ) as $origin ) {
			if ( empty( $families[ $origin ] ) || ! is_array( $families[ $origin ] ) ) {
				continue;
			}
			foreach ( $families[ $origin ] as $family ) {
				if ( is_array( $family )
					&& isset( $family['slug'] ) && $family['slug'] === $slug
					&& isset( $family['fontFamily'] ) && is_string( $family['fontFamily'] ) ) {
					return $family['fontFamily'];
				}
			}
		}
		return '';
	}
}
