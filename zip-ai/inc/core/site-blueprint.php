<?php
/**
 * Site Blueprint — the plan the site was built from, stored WITH the site.
 *
 * The builder plans a whole website once: its sitemap, style, design tokens,
 * and the exact header/footer bytes every page wears. That plan lived only in
 * the build session, so a page added later — days later, from a fresh session —
 * had nothing to build against and re-derived its own design. Same site, two
 * looks. Storing the plan here is what lets "add a page" and vibe-editing's
 * "add a section" build into the family the site already has.
 *
 * The plan is reached ONLY through two brain-only abilities, `zipai/get-site-plan`
 * and `zipai/update-site-plan` (visibility `internal`, so the AI chat catalog never
 * lists them). It is deliberately NOT exposed to REST: a plan is ~200 KB, and
 * `/wp/v2/settings` serves every registered option whole, ignoring `_fields` when
 * dispatched through `rest_do_request` — so a plan in that collection turned every
 * settings read into a truncated response, the model's reads for a site title
 * included. `zipai/run-wp-cli` is no door either: it caps a command at 4,096 bytes.
 *
 * One slice IS printed: `rootAttrs`, the lever row (`data-style`, `data-motion`, …)
 * the site's JS and root-headed CSS read. `zipai/update-site-plan` copies it into the
 * small autoloaded `zip-ai-site-html-attrs` option; `language_attributes` prints it.
 *
 * @since 0.0.9
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the site-blueprint option — see the file header.
 */
class Site_Blueprint {

	/**
	 * The stored plan, as the JSON the builder produced.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'zip-ai-site-blueprint';

	/**
	 * The plan's `rootAttrs`, `name => value`, printed on `<html>` as `data-<name>="<value>"`.
	 *
	 * @var string
	 */
	const HTML_ATTRS_OPTION_KEY = 'zip-ai-site-html-attrs';

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_contract' ) );
		add_filter( 'wp_default_autoload_value', array( __CLASS__, 'never_autoload' ), 10, 2 );
		add_filter( 'language_attributes', array( __CLASS__, 'add_html_attrs' ) );
	}

	/**
	 * Register the option for its type and default only — never for REST.
	 *
	 * No sanitize_callback: the value is a JSON document, and WP's string
	 * sanitizers would corrupt it. It is written only by the authenticated
	 * builder over MCP and is never echoed into a page; its `rootAttrs` are
	 * copied to HTML_ATTRS_OPTION_KEY, the one option that IS printed on `<html>`.
	 */
	public static function register_contract(): void {
		register_setting(
			'general',
			self::OPTION_KEY,
			array(
				'type'         => 'string',
				'show_in_rest' => false,
				'default'      => '',
			)
		);
	}

	/**
	 * Keep the blueprint out of the autoloaded set.
	 *
	 * Core only sizes an option off automatically above 150,000 bytes
	 * (`wp_max_autoloaded_option_size`) and a blueprint measures under that, so
	 * it would autoload by default. A filter rather than a one-time
	 * `add_option(..., false)`: the existence check that would need loads the
	 * whole payload on every request — the exact cost being avoided.
	 *
	 * @param bool|null $autoload The autoload value core is about to use.
	 * @param string    $option   The option being written.
	 * @return bool|null False for the blueprint, untouched for everything else.
	 */
	public static function never_autoload( $autoload, $option ) {
		return self::OPTION_KEY === $option ? false : $autoload;
	}

	/**
	 * The printable entries of a `rootAttrs` map (lowercase name, string value) — applied
	 * on write and again on print.
	 *
	 * @param mixed $attrs The plan's `rootAttrs`, or the stored option value.
	 * @return array<string,string>
	 */
	public static function html_attrs_from( $attrs ): array {
		if ( ! is_array( $attrs ) ) {
			return array();
		}
		$row = array();
		foreach ( $attrs as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) && preg_match( '/^[a-z][a-z0-9-]*$/D', $name ) ) {
				$row[ $name ] = $value;
			}
		}
		return $row;
	}

	/**
	 * `language_attributes` filter: append the row to core's `lang`/`dir` output.
	 *
	 * @param string $output The attributes core assembled.
	 * @return string
	 */
	public static function add_html_attrs( $output ) {
		if ( is_admin() ) {
			return $output;
		}
		foreach ( self::html_attrs_from( get_option( self::HTML_ATTRS_OPTION_KEY ) ) as $name => $value ) {
			$output .= ' data-' . $name . '="' . esc_attr( $value ) . '"';
		}
		return $output;
	}
}
