<?php
/**
 * Get Site Plan ability.
 *
 * Reads the plan the site was built from — the blueprint the brain stores in the
 * `zip-ai-site-blueprint` option so a page added later builds into the family the
 * site already has. Brain-only: the plan is ~200 KB of sitemap, tokens and chrome
 * HTML the model has no use for, so it never rides `/wp/v2/settings` and this
 * ability is hidden from the AI chat catalog.
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
 * Read the stored site plan.
 */
class GetSitePlan extends Abstract_Ability {

	/**
	 * Read-only.
	 *
	 * @var bool
	 */
	protected $is_destructive = false;

	/**
	 * Configure the ability (id, label, visibility, capability).
	 */
	public function configure() {
		$this->id          = 'zipai/get-site-plan';
		$this->label       = 'Get Site Plan';
		$this->description = 'Read-only: the plan (blueprint) this site was built from, as the JSON the build stored. Null when the site has none.';
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
		return Tool_Types::READ;
	}

	/**
	 * No input.
	 *
	 * @return array<string,mixed>
	 */
	public function get_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
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
						'plan'  => array( 'type' => array( 'string', 'null' ) ),
						'bytes' => array( 'type' => 'integer' ),
					),
				),
			),
		);
	}

	/**
	 * Return the stored plan verbatim, or null.
	 *
	 * @param array<string,mixed> $input Unused.
	 * @return array<string,mixed>
	 */
	public function execute( $input = array() ) {
		$raw = get_option( Site_Blueprint::OPTION_KEY, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return Response::success(
				'No site plan is stored.',
				array(
					'plan'  => null,
					'bytes' => 0,
				)
			);
		}
		return Response::success(
			sprintf( 'Site plan: %d bytes.', strlen( $raw ) ),
			array(
				'plan'  => $raw,
				'bytes' => strlen( $raw ),
			)
		);
	}
}
