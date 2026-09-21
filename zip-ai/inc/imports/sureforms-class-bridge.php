<?php
/**
 * SureForms class bridge — the built site's forms wear the site's own classes.
 *
 * Why this exists
 * ----------------
 * A built page's `<form>` becomes a SureForms form so submissions work. SureForms
 * owns that form's markup and behaviour; the page owns its look. The two meet
 * here: the converter recorded which classes every part of the source form wore
 * (`form`, `submit`, and per field `row` / `label` / `control` / `help` /
 * `choices` / `choice` / `option` — the library class plus the `gs-*` class the
 * GBS store holds the rule under), the committer stored that map on the form
 * post as `_zipai_form_classes`, and this bridge ADDS those classes to the
 * elements SureForms renders. The page's own rules then lay the form out and
 * paint it — the same rules that styled the preview the user approved. Nothing
 * is removed or restructured: `WP_HTML_Tag_Processor::add_class` only.
 *
 * SureForms' own skin is switched off for every form we own through its
 * `srfm_disable_default_styles` filter (2.12.2+): a form carrying a
 * `_zipai_form_hash` is ours. No styling meta is written; the switch is a rule.
 *
 * Target map (SureForms 2.12.x anatomy, `inc/generate-form-markup.php`,
 * `inc/fields/*-markup.php`):
 *   form            → `form.srfm-form`
 *   submit          → `button#srfm-submit-btn`
 *   fields[i].row   → the i-th `.srfm-block-single`
 *          .label   → its `.srfm-block-label` / `.srfm-block-legend`
 *          .control → its `input|textarea.srfm-input-common`, `select.srfm-dropdown-common`
 *                     (TomSelect copies the select's classes onto `.ts-wrapper`);
 *                     NEVER a checkbox/radio input — SureForms hides those behind
 *                     a drawn box, and a text-control class on the proxy paints it.
 *          .help    → its `.srfm-description`
 *          .choices → a choice group's `.srfm-block-wrap` (the option grid)
 *          .choice  → each `.srfm-multi-choice-single` (the pill)
 *          .option  → each `input.srfm-input-multi-choice-single`
 * A field count that no longer matches the render (the form was edited in the
 * SureForms editor) dresses the form and the submit only.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Imports;

use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the source classes to SureForms' rendered elements and switches the
 * SureForms skin off for every form the build owns.
 *
 * @since 0.0.6
 */
class SureformsClassBridge {
	/**
	 * The form post meta holding the class map (a JSON string — see
	 * `wp-importer` `formClassesMeta`).
	 *
	 * @var string
	 */
	public const CLASSES_META_KEY = '_zipai_form_classes';

	/**
	 * The provenance meta every form the build creates carries (written by
	 * `wp-importer`, registered by spectra-blocks). Its presence is ownership.
	 *
	 * @var string
	 */
	public const HASH_META_KEY = '_zipai_form_hash';

	/**
	 * A class token the bridge will add: anything without whitespace or a quote
	 * or angle bracket. `sanitize_html_class` is too narrow — a Tailwind token
	 * carries `:` / `/` / `[`.
	 *
	 * @var string
	 */
	private const TOKEN_RE = '/^[^\s"\'<>]+$/';

	/**
	 * Register the meta and the hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'srfm_disable_default_styles', array( $this, 'disable_skin_for_owned_form' ), 10, 2 );
		add_filter( 'do_shortcode_tag', array( $this, 'dress_shortcode' ), 10, 3 );
		add_filter( 'render_block_srfm/form', array( $this, 'dress_block' ), 10, 2 );
	}

	/**
	 * `_zipai_form_classes` on the SureForms form post — a string, REST-writable
	 * by anyone who may edit the form (the committer writes it through
	 * `/wp/v2/sureforms_form/{id}`).
	 *
	 * @return void
	 */
	public function register_meta(): void {
		register_post_meta(
			'sureforms_form',
			self::CLASSES_META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => static function ( $value ) {
					return is_string( $value ) ? substr( $value, 0, 65535 ) : '';
				},
			)
		);
	}

	/**
	 * `srfm_disable_default_styles`: a form the build owns renders without
	 * SureForms' stylesheets and inline variables.
	 *
	 * @param bool $disabled The stored per-form flag.
	 * @param int  $form_id  Form post ID.
	 * @return bool
	 */
	public function disable_skin_for_owned_form( $disabled, $form_id ): bool {
		return (bool) $disabled || $this->owns( (int) $form_id );
	}

	/**
	 * `do_shortcode_tag`: the `[sureforms id="N"]` embed the committer writes.
	 *
	 * @param string                     $output Rendered form.
	 * @param string                     $tag    Shortcode tag.
	 * @param array<string,mixed>|string $attr   Shortcode attributes.
	 * @return string
	 */
	public function dress_shortcode( $output, $tag, $attr ) {
		if ( 'sureforms' !== $tag || ! is_array( $attr ) ) {
			return $output;
		}
		return $this->dress( (string) $output, self::int_of( $attr['id'] ?? 0 ) );
	}

	/**
	 * `render_block_srfm/form`: the block embed.
	 *
	 * @param string               $content Rendered form.
	 * @param array<string, mixed> $block   Parsed block.
	 * @return string
	 */
	public function dress_block( $content, $block ) {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		return $this->dress( (string) $content, self::int_of( $attrs['id'] ?? 0 ) );
	}

	/**
	 * An id from a shortcode / block attribute.
	 *
	 * @param mixed $v Attribute value.
	 * @return int
	 */
	private static function int_of( $v ): int {
		return is_numeric( $v ) ? (int) $v : 0;
	}

	/**
	 * Add the stored classes to the rendered form's elements. Additions only;
	 * a form without a map renders untouched.
	 *
	 * @param string $html    Rendered form HTML.
	 * @param int    $form_id Form post ID.
	 * @return string
	 */
	public function dress( string $html, int $form_id ): string {
		$map = $this->class_map( $form_id );
		if ( null === $map || '' === $html ) {
			return $html;
		}
		// Rows are matched by position, so a render whose field count no longer
		// matches the map (the form was edited in the SureForms editor) keeps its
		// rows as rendered — only the form and the submit are dressed, and the
		// form is marked `data-zipai-classes="stale"` so support can see it.
		$stale  = $this->row_count( $html ) !== count( $map['fields'] );
		$fields = $stale ? array() : $map['fields'];
		$p      = new WP_HTML_Tag_Processor( $html );
		$row    = -1;
		$field  = null;
		while ( $p->next_tag() ) {
			$tag = $p->get_tag();
			if ( 'FORM' === $tag && $p->has_class( 'srfm-form' ) ) {
				$this->add( $p, $map['form'] );
				if ( $stale ) {
					$p->set_attribute( 'data-zipai-classes', 'stale' );
				}
				continue;
			}
			if ( 'BUTTON' === $tag && 'srfm-submit-btn' === $p->get_attribute( 'id' ) ) {
				$this->add( $p, $map['submit'] );
				continue;
			}
			if ( $p->has_class( 'srfm-block-single' ) ) {
				++$row;
				$field = $fields[ $row ] ?? null;
				if ( null !== $field ) {
					$this->add( $p, $field['row'] );
				}
				continue;
			}
			if ( null === $field ) {
				continue;
			}
			if ( $p->has_class( 'srfm-block-label' ) || $p->has_class( 'srfm-block-legend' ) ) {
				$this->add( $p, $field['label'] );
			} elseif ( $this->is_text_control( $p, $tag ) ) {
				$this->add( $p, $field['control'] );
			} elseif ( $p->has_class( 'srfm-description' ) ) {
				$this->add( $p, $field['help'] );
			} elseif ( $p->has_class( 'srfm-block-wrap' ) && '' !== $field['choices'] ) {
				$this->add( $p, $field['choices'] );
			} elseif ( $p->has_class( 'srfm-multi-choice-single' ) ) {
				$this->add( $p, $field['choice'] );
			} elseif ( $p->has_class( 'srfm-input-multi-choice-single' ) ) {
				$this->add( $p, $field['option'] );
			}
		}
		return $p->get_updated_html();
	}

	/**
	 * How many field rows the render holds.
	 *
	 * @param string $html Rendered form HTML.
	 * @return int
	 */
	private function row_count( string $html ): int {
		$p = new WP_HTML_Tag_Processor( $html );
		$n = 0;
		while ( $p->next_tag() ) {
			if ( $p->has_class( 'srfm-block-single' ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * A visible text-like control: input / textarea / select, never a
	 * checkbox, radio or hidden input.
	 *
	 * @param WP_HTML_Tag_Processor $p   Processor at the tag.
	 * @param string|null           $tag Tag name.
	 * @return bool
	 */
	private function is_text_control( WP_HTML_Tag_Processor $p, ?string $tag ): bool {
		if ( ! in_array( $tag, array( 'INPUT', 'TEXTAREA', 'SELECT' ), true ) ) {
			return false;
		}
		if ( ! $p->has_class( 'srfm-input-common' ) && ! $p->has_class( 'srfm-dropdown-common' ) ) {
			return false;
		}
		$type = strtolower( (string) $p->get_attribute( 'type' ) );
		return ! in_array( $type, array( 'checkbox', 'radio', 'hidden' ), true );
	}

	/**
	 * Add each token of a class string.
	 *
	 * @param WP_HTML_Tag_Processor $p       Processor at the tag.
	 * @param string                $classes Space-separated tokens.
	 * @return void
	 */
	private function add( WP_HTML_Tag_Processor $p, string $classes ): void {
		$tokens = preg_split( '/\s+/', trim( $classes ) );
		if ( ! is_array( $tokens ) ) {
			return;
		}
		foreach ( $tokens as $token ) {
			if ( '' !== $token && 1 === preg_match( self::TOKEN_RE, $token ) ) {
				$p->add_class( $token );
			}
		}
	}

	/**
	 * The stored class map, normalised: every key a string, every field every
	 * part. Null when the form carries none or the JSON is not a map.
	 *
	 * @param int $form_id Form post ID.
	 * @return array{form:string,submit:string,fields:array<int,array<string,string>>}|null
	 */
	private function class_map( int $form_id ): ?array {
		if ( $form_id <= 0 ) {
			return null;
		}
		$raw = get_post_meta( $form_id, self::CLASSES_META_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$str    = static fn( $v ): string => is_string( $v ) ? $v : '';
		$parts  = array( 'row', 'label', 'control', 'help', 'choices', 'choice', 'option' );
		$fields = array();
		foreach ( is_array( $data['fields'] ?? null ) ? $data['fields'] : array() as $f ) {
			$field = array();
			foreach ( $parts as $part ) {
				$field[ $part ] = is_array( $f ) ? $str( $f[ $part ] ?? '' ) : '';
			}
			$fields[] = $field;
		}
		return array(
			'form'   => $str( $data['form'] ?? '' ),
			'submit' => $str( $data['submit'] ?? '' ),
			'fields' => $fields,
		);
	}

	/**
	 * The build created this form.
	 *
	 * @param int $form_id Form post ID.
	 * @return bool
	 */
	private function owns( int $form_id ): bool {
		$hash = $form_id > 0 ? get_post_meta( $form_id, self::HASH_META_KEY, true ) : '';
		return is_string( $hash ) && '' !== $hash;
	}
}
