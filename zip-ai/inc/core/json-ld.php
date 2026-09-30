<?php
/**
 * Json Ld — the site's schema.org structured data, printed in `<head>`.
 *
 * The website build writes the site's JSON-LD blocks (Organization, plus
 * LocalBusiness when the brief states an address) as one JSON string to
 * `zip_ai_jsonld` over `/wp/v2/settings`; every front-end page prints it once
 * as `<script type="application/ld+json">`. The option holds only JSON-LD:
 * any other value is stored as '' and prints nothing.
 *
 * @since 0.0.11
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and prints the JSON-LD option — see the file header.
 */
class Json_Ld {

	/**
	 * The JSON-LD blocks, as the JSON string the build wrote.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'zip_ai_jsonld';

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_contract' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_script' ) );
	}

	/**
	 * Register the option for REST writes (`/wp/v2/settings`, manage_options).
	 *
	 * The group is the plugin's own, which no admin form posts: wp-admin/options.php
	 * writes null to every option of the posted group the form did not send, so
	 * under 'general' a Settings > General save would erase the build's JSON-LD.
	 */
	public static function register_contract(): void {
		register_setting(
			'zip_ai',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'show_in_rest'      => true,
				'default'           => '',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * Keep the value only when it is JSON-LD.
	 *
	 * @param mixed $value The submitted value.
	 * @return string The value unchanged, or '' when it is not JSON-LD.
	 */
	public static function sanitize( $value ): string {
		return is_string( $value ) && null !== self::decode( $value ) ? $value : '';
	}

	/**
	 * `wp_head`: print the stored blocks, re-encoded from their decoded value.
	 */
	public static function print_script(): void {
		$data = self::decode( get_option( self::OPTION_KEY, '' ) );
		if ( null === $data ) {
			return;
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( false === $json ) {
			return;
		}
		wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
	}

	/**
	 * Decode a JSON-LD document: one object carrying `@context`, or a non-empty
	 * list of such objects.
	 *
	 * @param mixed $value The stored or submitted value.
	 * @return \stdClass|array<int,\stdClass>|null The decoded document, or null when it is not JSON-LD.
	 */
	private static function decode( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$data   = json_decode( $value );
		$blocks = array();
		foreach ( is_array( $data ) ? $data : array( $data ) as $block ) {
			if ( ! $block instanceof \stdClass || ! property_exists( $block, '@context' ) ) {
				return null;
			}
			$blocks[] = $block;
		}
		if ( array() === $blocks ) {
			return null;
		}
		return $data instanceof \stdClass ? $data : $blocks;
	}
}
