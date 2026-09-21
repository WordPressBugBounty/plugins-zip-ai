<?php
/**
 * Get Global-Styles Post ID — the id of the active theme's editable
 * `wp_global_styles` CPT post (the user layer the Site Editor writes to).
 *
 * WHY: writing the theme's global typography (heading/body font family) needs
 * this post id, and it is NOT reliably reachable over the MCP REST proxy. The
 * core `/wp/v2/global-styles/themes/{stylesheet}` item carries no `id`, and the
 * `wp:user-global-styles` link that DOES carry it (on `/wp/v2/themes`) rides the
 * response `_links` — which `zipai/run-rest-request` strips, returning
 * `get_data()` only. So a server-side resolver is the one reliable door. The
 * build/import font-set step and the chat font picker both consume this id, then
 * read-merge-write `/wp/v2/global-styles/{id}` typography.
 *
 * `WP_Theme_JSON_Resolver::get_user_global_styles_post_id()` is the SSOT — the
 * same resolver the Site Editor uses; it lazily creates the (empty) user post on
 * first read, exactly as the editor would.
 *
 * @since 0.0.8
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\Builder;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Ability: resolve the active theme's user global-styles post id.
 */
class GetGlobalStylesId extends Abstract_Ability {

	/**
	 * Resolving lazily ensures the (empty) user global-styles post exists — an
	 * idempotent WP-core init the Site Editor performs itself, not a user write.
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
		$this->id          = 'zipai/get-global-styles-id';
		$this->label       = 'Get Global-Styles Post ID';
		$this->description = 'Read-only: the post id of the active theme\'s editable user global-styles (wp_global_styles) record — the target for writing the theme\'s global typography. Use before a read-merge-write of /wp/v2/global-styles/{id}; the id is not exposed by the REST theme endpoint over MCP.';
		$this->capability  = 'edit_theme_options';
		// Hidden from the AI chat catalog — the server calls it itself over MCP.
		$this->meta['visibility'] = 'internal';
	}

	/**
	 * Returns the tool-type classification for this ability.
	 *
	 * READ even though resolving the id lazily CREATES the (empty) user
	 * `wp_global_styles` post on first call — an idempotent WP-core init the Site
	 * Editor performs itself, not a user-facing write (see `$is_destructive`). Anything
	 * that gates real writes on `Tool_Types::READ` should be aware of that one caveat.
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
						'id'         => array( 'type' => 'integer' ),
						'stylesheet' => array( 'type' => 'string' ),
					),
				),
			),
		);
	}

	/**
	 * Resolve the active theme's user global-styles post id.
	 *
	 * @param array<string,mixed> $input Unused — the ability takes no arguments.
	 * @return array<string,mixed> Response payload: { id, stylesheet }.
	 */
	public function execute( $input = array() ) {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return Response::error( 'Global styles are unavailable on this site.' );
		}

		$id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( $id <= 0 ) {
			return Response::error( 'Could not resolve the user global-styles post id.' );
		}

		return Response::success(
			sprintf( 'User global-styles post id: %d.', $id ),
			array(
				'id'         => $id,
				'stylesheet' => (string) get_stylesheet(),
			)
		);
	}
}
