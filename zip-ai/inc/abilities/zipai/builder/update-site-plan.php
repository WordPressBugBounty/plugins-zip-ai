<?php
/**
 * Update Site Plan ability.
 *
 * Stores the plan a build ran from into the `zip-ai-site-blueprint` option. The
 * plan measures ~200 KB, which rules out the two other doors: `run-wp-cli` caps a
 * command at 4 KB, and `/wp/v2/settings` would put the whole document into every
 * settings response the model reads for a site title. Brain-only, hidden from the
 * AI chat catalog. The response is a small acknowledgement — never the plan.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\Builder;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Site_Blueprint;
use ZipAI\MCP\Classes\Core\Tool_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Write the site plan.
 */
class UpdateSitePlan extends Abstract_Ability {

	/**
	 * Hard cap on the stored document. A real plan is ~200 KB; this is headroom,
	 * not a target.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Replaces the plan option and its `rootAttrs` copy; nothing else is touched.
	 *
	 * @var bool
	 */
	protected $is_destructive = false;

	/**
	 * Configure the ability (id, label, visibility, capability).
	 */
	public function configure() {
		$this->id          = 'zipai/update-site-plan';
		$this->label       = 'Update Site Plan';
		$this->description = 'Store the plan (blueprint) this site is built from, as a JSON string. Replaces the previous plan.';
		$this->capability  = 'manage_options';
		// Hidden from the AI chat catalog — the server calls it itself over MCP.
		$this->meta['visibility'] = 'internal';
	}

	/**
	 * Tool type.
	 *
	 * @return string
	 */
	public function get_tool_type() {
		return Tool_Types::WRITE;
	}

	/**
	 * Input schema.
	 *
	 * @return array<string,mixed>
	 */
	public function get_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'plan' => array(
					'type'        => 'string',
					'description' => 'The plan as a JSON object, serialised. At most ' . self::MAX_BYTES . ' bytes.',
				),
			),
			'required'             => array( 'plan' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema.
	 *
	 * @return array<string,mixed>
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
						'stored'     => array( 'type' => 'boolean' ),
						'bytes'      => array( 'type' => 'integer' ),
						'id'         => array( 'type' => 'string' ),
						'root_attrs' => array( 'type' => 'integer' ),
					),
				),
			),
		);
	}

	/**
	 * Validate and store the plan.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public function execute( $input = array() ) {
		$plan = isset( $input['plan'] ) ? $input['plan'] : null;
		if ( ! is_string( $plan ) || '' === trim( $plan ) ) {
			return Response::error( 'plan is required and must be a non-empty JSON string.' );
		}
		$bytes = strlen( $plan );
		if ( $bytes > self::MAX_BYTES ) {
			return Response::error( sprintf( 'plan is %d bytes; the cap is %d.', $bytes, self::MAX_BYTES ) );
		}
		$decoded = json_decode( $plan, true );
		if ( ! is_array( $decoded ) || array() === $decoded || array_values( $decoded ) === $decoded ) {
			return Response::error( 'plan must be a JSON object.' );
		}
		$id = isset( $decoded['id'] ) && is_string( $decoded['id'] ) ? $decoded['id'] : '';

		// update_option() answers false when the stored value is already identical,
		// which is not a failure — so confirm by reading the option back.
		update_option( Site_Blueprint::OPTION_KEY, $plan, false );
		if ( get_option( Site_Blueprint::OPTION_KEY ) !== $plan ) {
			return Response::error( 'The site did not store the plan.' );
		}

		// `rootAttrs` rides its own small option so a page never loads the plan.
		$html_attrs = Site_Blueprint::html_attrs_from( isset( $decoded['rootAttrs'] ) ? $decoded['rootAttrs'] : null );
		if ( array() === $html_attrs ) {
			delete_option( Site_Blueprint::HTML_ATTRS_OPTION_KEY );
		} else {
			update_option( Site_Blueprint::HTML_ATTRS_OPTION_KEY, $html_attrs );
		}

		return Response::success(
			sprintf( 'Site plan stored (%d bytes).', $bytes ),
			array(
				'stored'     => true,
				'bytes'      => $bytes,
				'id'         => $id,
				'root_attrs' => count( $html_attrs ),
			)
		);
	}
}
