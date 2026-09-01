<?php
/**
 * ZIP AI - Tool Types.
 *
 * This file contains constants for tool types used in ability registration.
 * Using these constants ensures consistency and prevents typos across the codebase.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Tool Types Class.
 *
 * Defines constants for all available tool types that can be used
 * when registering abilities through wp_register_ability().
 *
 * @since 1.0.0
 */
class Tool_Types {

	/**
	 * Read operation - retrieves information without modification.
	 * Examples: get option, check status, read settings
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const READ = 'read';

	/**
	 * Write operation - creates or modifies content/state.
	 * Examples: create page, update block, install plugin, change settings
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const WRITE = 'write';

	/**
	 * List operation - enumerates items.
	 * Examples: list media, list plugins, list pages
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const LIST = 'list';

	/**
	 * Search operation - searches for content.
	 * Examples: search posts, find pages, query content
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SEARCH = 'search';

	/**
	 * Action operation - utility operations that don't fit other categories.
	 * Examples: clear cache, flush permalinks, refresh data
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ACTION = 'action';

	/**
	 * Delete operation - removes content or data.
	 * Examples: delete post, remove plugin, clear data
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const DELETE = 'delete';

	/**
	 * Get all available tool types.
	 *
	 * @since 1.0.0
	 * @return list<string> Array of all available tool type constants.
	 */
	public static function get_all() {
		return array(
			self::READ,
			self::WRITE,
			self::LIST,
			self::SEARCH,
			self::DELETE,
		);
	}

	/**
	 * Get tool types that require user confirmation before execution.
	 *
	 * These are destructive or state-changing operations that should
	 * require explicit user approval.
	 *
	 * @since 1.0.0
	 * @return list<string> Array of tool types requiring confirmation.
	 */
	public static function get_confirmation_required_types() {
		return array(
			self::WRITE,
			self::DELETE,
			self::ACTION,
		);
	}

	/**
	 * Check if a tool type requires confirmation.
	 *
	 * @since 1.0.0
	 * @param string $tool_type The tool type to check.
	 * @return bool True if confirmation required, false otherwise.
	 */
	public static function requires_confirmation( $tool_type ) {
		return in_array( $tool_type, self::get_confirmation_required_types(), true );
	}
}
