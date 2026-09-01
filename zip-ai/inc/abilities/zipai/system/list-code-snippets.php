<?php
/**
 * List Code Snippets — read-only discovery ability for ZIP AI's native snippet registry.
 *
 * Exists alongside `zipai/run-snippet` (ManageCodeSnippet) specifically to give the
 * LLM an unambiguous, read-only entry point for answering questions like
 * "how many snippets do I have?" — the LLM was reliably SKIPPING the manage tool
 * because its id ("run-snippet") implied execution rather than listing, leading
 * to hallucinated "0 snippets" answers even when the filesystem had several.
 *
 * This ability is a direct read of wp-content/zip-ai-snippets/manifest.php. It does
 * not mutate state and requires no approval. Calling it is always safe.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Snippet_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ListCodeSnippets extends Abstract_Ability {

	/**
	 * Flags this ability as non-destructive (read-only).
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
		$this->id    = 'zipai/list-snippets';
		$this->label = 'List Code Snippets';
		// Factual contract only. Instructional copy ("USE THIS FIRST",
		// "Never report a snippet count without calling this tool first")
		// belongs in the server prompts, not the schema description.
		$this->description = 'Read-only enumeration of every snippet registered in ZIP AI\'s native registry (wp-content/zip-ai-snippets/manifest.php). '
			. 'ZIP AI snippets are stored on the filesystem, NOT in any database table — DB queries (wp_snippets, wpcode posts, post_type=snippet, etc.) return zero. '
			. 'Returns: { count, snippets: [{ slug, title, files, enabled, description, execution, conditions }] }. '
			. 'To modify (create/update/enable/delete), use zipai/run-snippet.';
		// Read surface, so it tracks the REST list route's capability rather
		// than the write cap — an admin who cannot author snippets still needs
		// to see what is running. See Snippet_Store::read_capability().
		$this->capability = Snippet_Store::read_capability();
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
						'count'    => array( 'type' => 'integer' ),
						'snippets' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'        => array( 'type' => 'string' ),
									'title'       => array( 'type' => 'string' ),
									'enabled'     => array( 'type' => 'boolean' ),
									'description' => array( 'type' => 'string' ),
									'files'       => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Enumerates every snippet registered in the native registry.
	 *
	 * @param array<string,mixed> $input Validated input arguments (unused).
	 * @return array<string,mixed> Standardized success response with the snippet list.
	 */
	public function execute( $input = array() ) {
		$manifest = Snippet_Store::load();
		$snippets = array();

		foreach ( $manifest as $slug => $meta ) {
			if ( '_version' === $slug ) {
				continue;
			}
			/**
			 * Narrowed type for `$meta_array`.
			 *
			 * @var array<string,mixed> $meta_array
			 */
			$meta_array = is_array( $meta ) ? $meta : array();
			$normalized = Snippet_Store::normalize_snippet( $meta_array );
			$snippets[] = array(
				'slug'        => $slug,
				'title'       => $normalized['title'],
				'files'       => $normalized['files'],
				'enabled'     => (bool) $normalized['enabled'],
				'description' => $normalized['description'],
				'execution'   => $normalized['execution'],
				'conditions'  => $normalized['conditions'],
			);
		}

		return Response::success(
			count( $snippets ) . ' snippets found.',
			array(
				'count'    => count( $snippets ),
				'snippets' => $snippets,
			)
		);
	}
}
