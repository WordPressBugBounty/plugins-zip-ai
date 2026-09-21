<?php
/**
 * Abilities input normalizer.
 *
 * Makes a no-input GET to a readonly WP Ability validate as an empty object.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

defined( 'ABSPATH' ) || exit;

class Abilities_Input_Normalizer {

	/**
	 * Hook the core `wp_ability_normalize_input` filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_ability_normalize_input', array( __CLASS__, 'default_empty_object' ), 10, 3 );
	}

	/**
	 * Supply an empty object as the input when a readonly ability is called with
	 * none.
	 *
	 * WHY: core `WP_Ability::normalize_input()` only substitutes an input when the
	 * ability's `input_schema` declares a top-level `default`. An ability whose
	 * schema is an OBJECT with no `default` and no `required` fields omits it —
	 * notably Astra's `astra/list-font-family` (an object schema, every field
	 * optional). The browser Abilities run-route can't carry a JSON object on a GET
	 * (query params are strings; `?input={}` arrives as the string "{}"), so with no
	 * `input` param the ability receives `null` and the route rejects it 400 "input
	 * is not of type object" — even though `{}` is perfectly valid. The ZIP AI chat
	 * calls such abilities over exactly that GET route, and we can't patch a stock
	 * theme's schema, so we supply the empty object here. (Abilities with an EMPTY
	 * schema — `astra/get-font-heading` / `get-font-body` return `array()` — need no
	 * help: they fall out at the `empty( $schema )` guard below, no object to satisfy.)
	 *
	 * SCOPE: this feature needs it only for `zipai/*` and `astra/*` abilities, so it
	 * is gated on that namespace prefix — the filter fires for every ability on the
	 * site, but we no-op for anyone else's rather than widen their input handling.
	 * Beyond that: only `null` input, and only schemas where `{}` is genuinely valid —
	 * `type: object`, no `default` already (core handled those), and no `required`
	 * fields. An ability that truly needs input keeps its `required[]` and is still
	 * rejected by `validate_input()`, so this loosens nothing real; it only makes
	 * currently-uncallable no-input GET abilities callable.
	 *
	 * @param mixed  $input   The (possibly null) normalized input.
	 * @param string $name    The ability name.
	 * @param mixed  $ability The WP_Ability instance.
	 * @return mixed The input, or an empty array when an empty object is the right default.
	 */
	public static function default_empty_object( $input, $name, $ability ) {
		if ( null !== $input || ! $ability instanceof \WP_Ability ) {
			return $input;
		}

		// Only widen the abilities this feature actually calls over the run-route —
		// never every plugin's abilities site-wide.
		if ( 0 !== strpos( $name, 'zipai/' ) && 0 !== strpos( $name, 'astra/' ) ) {
			return $input;
		}

		$schema = $ability->get_input_schema();
		if ( empty( $schema ) ) {
			return $input;
		}

		// Only when `{}` is a valid input: object-typed, no default already
		// substituted by core, and no required fields.
		$is_object   = isset( $schema['type'] ) && 'object' === $schema['type'];
		$has_default = array_key_exists( 'default', $schema );
		$requires    = ! empty( $schema['required'] );

		if ( $is_object && ! $has_default && ! $requires ) {
			return array();
		}

		return $input;
	}
}
