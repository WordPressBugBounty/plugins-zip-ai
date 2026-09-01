<?php
/**
 * Abstract Ability Class
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities;

use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Validator;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Event_Logger;
use ZipAI\MCP\Classes\Core\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Class Abstract_Ability
 */
abstract class Abstract_Ability {

	/**
	 * Ability ID (e.g. 'zipai/list-media').
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Ability Category.
	 *
	 * @var string
	 */
	protected $category = 'zipai';

	/**
	 * Ability Label.
	 *
	 * @var string
	 */
	protected $label;

	/**
	 * Ability Description.
	 *
	 * @var string
	 */
	protected $description;

	/**
	 * Required capability for this ability. Every ability MUST set it in
	 * `configure()`; this default only makes forgetting fail CLOSED. It mirrors
	 * `REST_API::INGRESS_CAPABILITY` (pinned by AbilityCapabilityPolicyTest), so
	 * an omission can never grant more than the ingress already required. It was
	 * `edit_posts` — looser than the ingress — which is DSA-18.
	 *
	 * Do NOT flatten the per-ability values to this default: core maps the
	 * plugin/theme file-mod caps to `do_not_allow` under `DISALLOW_FILE_MODS`
	 * and for non-super-admins on multisite, where `manage_options` still
	 * passes (`capabilities.php:619-642`).
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Ability Meta Data.
	 *
	 * @var array<string,mixed>
	 */
	protected $meta = array();

	/**
	 * Whether the ability is destructive or state-changing.
	 * If true, it supports dry_run by default.
	 *
	 * @var bool
	 */
	protected $is_destructive = false;

	/**
	 * Requests allowed per user per minute for this ability.
	 *
	 * The default suits interactive chat. Override it on the abilities the
	 * agent drives in bulk — a website build routes every page create, style
	 * guide push, template upsert and chrome write through ONE ability, so a
	 * single build legitimately spends hundreds of calls a minute.
	 *
	 * @var int
	 */
	protected $rate_limit = 100;

	/**
	 * Read-only sub-action names on a multiplexed ability.
	 *
	 * Many abilities expose multiple operations through a single tool via
	 * an `action` enum (e.g. `zipai/run-snippet` has `create|list|get|...`).
	 * Marking the whole tool as `is_destructive=true` is correct for the
	 * default classification but trips the writes-require-approval
	 * gate even on pure read sub-actions like `list` or `get`. Override this
	 * property on subclasses to enumerate which `action` values are safe
	 * reads. The MCP server forwards the list to the client, which reads it
	 * generically — no per-tool hardcoding on the client side.
	 *
	 * @var array<int,string>
	 */
	protected $read_only_actions = array();

	/**
	 * Tool version (semantic versioning).
	 * Increment when tool behavior or schema changes.
	 *
	 * @var string
	 */
	protected $version = '1.0.0';

	/**
	 * Required plugin slug for this tool (e.g., 'starter-templates', 'spectra', 'sureforms').
	 * Set this when the tool depends on a specific plugin being installed.
	 *
	 * @var string|null
	 */
	protected $required_plugin = null;

	/**
	 * Minimum version of the required plugin.
	 * Only used when $required_plugin is set.
	 *
	 * @var string|null
	 */
	protected $required_plugin_version = null;

	/**
	 * Admin screens where this tool should be boosted in search results.
	 * Use WordPress screen IDs or bases (e.g., 'plugins', 'edit-post', 'upload').
	 *
	 * @var list<string>
	 */
	protected $boost_screens = array();

	/**
	 * Resource identifier for read-first-write pattern matching.
	 * Tools that operate on the same resource should share the same identifier.
	 * E.g., 'site-setting', 'posts', 'media', 'menus', 'plugins', 'themes'.
	 *
	 * @var string|null
	 */
	protected $resource = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->configure();
	}

	/**
	 * Configure the ability (set ID, label, description, etc.).
	 *
	 * @return void
	 */
	abstract public function configure();

	/**
	 * Get the input schema for the ability.
	 *
	 * @return array<string,mixed>
	 */
	abstract public function get_input_schema();

	/**
	 * Get the output schema for the ability.
	 *
	 * Default returns an empty array (no output validation). Override in child
	 * classes to declare a JSON Schema for the tool's successful response shape.
	 * When non-empty, WordPress core's Abilities API validates tool output
	 * against this schema after execute().
	 *
	 * @since 0.0.5
	 * @return array<string,mixed>
	 */
	public function get_output_schema() {
		return array();
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $args Input arguments.
	 * @return array<string,mixed> Result array.
	 */
	abstract public function execute( $args );

	/**
	 * Get the final input schema, including any automatically added parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function get_final_input_schema() {
		$schema = $this->get_input_schema();

		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			$schema['properties'] = array();
		}

		if ( $this->is_destructive && ! isset( $schema['properties']['dry_run'] ) ) {
			$schema['properties']['dry_run'] = array(
				'type'        => 'boolean',
				'description' => 'If true, will only simulate the changes without applying them.',
				'default'     => false,
			);
		}
		return $schema;
	}

	/**
	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_capability() {
		return $this->capability;
	}

	/**
	 * Handle execution of the ability with centralized validation.
	 *
	 * @param array<string,mixed> $args Input arguments.
	 * @return array<string,mixed> Result array.
	 */
	public function handle_execute( $args ) {
		$start_time = microtime( true );
		$start_mem  = memory_get_usage();

		try {
			// Rate limiting — $rate_limit req/min per user+ability. Skipped only under
			// the ZIPAI_TESTING constant (CLI batch imports). The dev-time `false &&`
			// short-circuit (ZIPAI_RATE_LIMIT_DISABLED) was removed before ship.
			//
			// The window is FIXED: the transient carries its own start time and the
			// TTL is whatever is LEFT of the minute. Storing a bare counter with a
			// flat 60s TTL made the window slide — set_transient refreshes the
			// timeout on every call, so continuous traffic (a website build fires
			// REST writes back-to-back) renewed the transient forever and the
			// counter became a per-SESSION budget that only cleared after 60s of
			// total silence.
			if ( ! defined( 'ZIPAI_TESTING' ) ) {
				$user_id  = get_current_user_id();
				$rate_key = 'zip_ai_rate_' . $user_id . '_' . $this->id;
				$now      = time();
				$stored   = get_transient( $rate_key );

				// The window is read into typed LOCALS rather than cast at each
				// use. get_transient() returns mixed, and `isset` proves only
				// that the keys exist, not that they hold numbers — so casting
				// off the raw value is unprovable at PHPStan level 10.
				//
				// `is_numeric` keeps the tolerance this guard was written for: a
				// bare-integer transient from the pre-window version fails
				// is_array and simply starts a fresh window, and an object cache
				// that returns numeric strings still counts correctly.
				$start = $now;
				$count = 0;
				if (
					is_array( $stored )
					&& isset( $stored['start'], $stored['count'] )
					&& is_numeric( $stored['start'] )
					&& is_numeric( $stored['count'] )
					&& ( $now - (int) $stored['start'] ) < 60
				) {
					$start = (int) $stored['start'];
					$count = (int) $stored['count'];
				}

				if ( $count >= $this->rate_limit ) {
					return Response::error( 'Rate limit exceeded. Please try again in a minute.' );
				}
				++$count;
				set_transient(
					$rate_key,
					array(
						'start' => $start,
						'count' => $count,
					),
					max( 1, 60 - ( $now - $start ) )
				);
			}

			// Validate and sanitize input against the final schema.
			$validated_args = Validator::validate( $this->get_final_input_schema(), $args );

			if ( is_wp_error( $validated_args ) ) {
				return Response::from_wp_error( $validated_args );
			}

			// Handle dry_run if requested.
			if ( $this->is_destructive && ! empty( $validated_args['dry_run'] ) ) {
				$response    = $this->dry_run( $validated_args );
				$performance = array(
					'execution_time' => round( ( microtime( true ) - $start_time ) * 1000, 2 ) . 'ms',
					'memory_peak'    => round( ( memory_get_peak_usage() - $start_mem ) / 1024, 2 ) . 'KB',
				);
				Event_Logger::log( $this->id, $args, $response, $performance );
				return $response;
			}

			// Call the actual tool execution logic.
			$response = $this->execute( $validated_args );

			// Capture metrics.
			$performance = array(
				'execution_time' => round( ( microtime( true ) - $start_time ) * 1000, 2 ) . 'ms',
				'memory_peak'    => round( ( memory_get_peak_usage() - $start_mem ) / 1024, 2 ) . 'KB',
			);

			// Log the execution.
			Event_Logger::log( $this->id, $args, $response, $performance );

			return $response;

		} catch ( \Exception $e ) {
			// Never send exception text to the client — this wrapper catches EVERY
			// ability, so a raw message leaks DB errors, file paths, class names.
			// Keep the detail server-side (WP_DEBUG) and return a static message.
			$this->log_execution_throwable( $e );
			$response    = Response::error( 'An unexpected error occurred while running this action. Please try again.' );
			$performance = array(
				'execution_time' => round( ( microtime( true ) - $start_time ) * 1000, 2 ) . 'ms',
				'memory_peak'    => round( ( memory_get_peak_usage() - $start_mem ) / 1024, 2 ) . 'KB',
			);
			Event_Logger::log( $this->id, $args, $response, $performance );
			return $response;
		} catch ( \Error $e ) {
			$this->log_execution_throwable( $e );
			$response    = Response::error( 'A system error occurred while running this action. Please try again.' );
			$performance = array(
				'execution_time' => round( ( microtime( true ) - $start_time ) * 1000, 2 ) . 'ms',
				'memory_peak'    => round( ( memory_get_peak_usage() - $start_mem ) / 1024, 2 ) . 'KB',
			);
			Event_Logger::log( $this->id, $args, $response, $performance );
			return $response;
		}
	}

	/**
	 * Record an ability execution failure server-side without leaking the raw
	 * message to the client. WP_DEBUG-gated so production logs stay quiet.
	 *
	 * @param \Throwable $e The caught exception or error.
	 * @return void
	 */
	private function log_execution_throwable( $e ) {
		Utils::debug_log( sprintf( 'Ability "%s" failed', $this->id ), $e->getMessage() );
	}

	/**
	 * Default dry run implementation.
	 * Abilities should override this if they support is_destructive.
	 *
	 * The default is honest about its limits: claiming "dry run completed
	 * successfully" fabricated a preview that inspected nothing, and callers
	 * treated it as evidence the real run was safe.
	 *
	 * @param array<string,mixed> $args Input arguments.
	 * @return array<string,mixed> Result array.
	 */
	protected function dry_run( $args ) {
		return Response::success(
			'This ability has no dry-run preview, nothing was inspected and no changes were made. Treat the real run as unpreviewed.',
			array(
				'dry_run'           => true,
				'preview_available' => false,
			)
		);
	}

	/**
	 * Get usage examples.
	 *
	 * @return array<int,mixed>
	 */
	public function get_examples() {
		return array();
	}

	/**
	 * Get tool type (read, write, list, search, action, delete).
	 * Override in child classes for a specific type.
	 *
	 * Fail-SAFE default: a destructive / state-changing ability that does NOT
	 * override this is classified as a mutating ACTION (not READ), so it can
	 * never silently skip the approval gate by omission. Previously the default
	 * was READ, so a new mutating ability that forgot to override
	 * get_tool_type() was treated as read-only and bypassed approval entirely.
	 * Read abilities keep the READ default; abilities that override this win.
	 *
	 * @return string
	 */
	public function get_tool_type() {
		return $this->is_destructive ? Tool_Types::ACTION : Tool_Types::READ;
	}

	/**
	 * MCP tool annotations for this ability.
	 *
	 * DERIVED from what each ability already declares — its `get_tool_type()` and
	 * its `$is_destructive` flag. Nothing new to maintain per ability, and no
	 * central table to drift out of sync with the files it describes.
	 *
	 * Clients read these to decide what needs a human approval prompt. Override
	 * only where the tool type does not capture the consequences — a WRITE that
	 * REPLACES existing site design is destructive even though writing normally
	 * is not.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function get_annotations() {
		$type    = $this->get_tool_type();
		$is_read = in_array( $type, array( Tool_Types::READ, Tool_Types::LIST, Tool_Types::SEARCH ), true );

		return array(
			'readonly'    => $is_read,
			// DELETE destroys by definition; anything already flagged destructive
			// for the dry-run gate is destructive here too — one declaration,
			// both consumers.
			'destructive' => $this->is_destructive || Tool_Types::DELETE === $type,
			// A read can be repeated safely. A write makes no such claim unless
			// the ability says so.
			'idempotent'  => $is_read,
		);
	}

	/**
	 * Get the read-only sub-action allowlist for multiplexed abilities. Default
	 * is the protected `$read_only_actions` array (empty unless overridden).
	 *
	 * @return array<int,string>
	 */
	public function get_read_only_actions() {
		return array_values( $this->read_only_actions );
	}

	/**
	 * Get API endpoint configuration.
	 *
	 * @return array{url:string,method:string,auth:string}
	 */
	public function get_api_endpoint() {
		return array(
			'url'    => rest_url( 'mcp/v1/tools/call' ),
			'method' => 'POST',
			'auth'   => 'bearer',
		);
	}

	/**
	 * Check permissions.
	 *
	 * @param \WP_REST_Request $request REST Request.
	 * @return bool|\WP_Error
	 */
	public function check_permission( $request ) {
		return current_user_can( $this->capability );
	}

	/**
	 * Get the ability ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get the ability label.
	 *
	 * @return string
	 */
	public function get_label() {
		return $this->label;
	}

	/**
	 * Get the ability description.
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Get the category.
	 *
	 * @return string
	 */
	public function get_category() {
		return $this->category;
	}

	/**
	 * Get the meta data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_meta_data() {
		return $this->meta;
	}

	/**
	 * Get the tool version.
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Get the required plugin slug.
	 *
	 * @return string|null
	 */
	public function get_required_plugin() {
		return $this->required_plugin;
	}

	/**
	 * Get the required plugin version.
	 *
	 * @return string|null
	 */
	public function get_required_plugin_version() {
		return $this->required_plugin_version;
	}

	/**
	 * Get admin screens where this tool should be boosted.
	 *
	 * Override this method in a child class to set boost screens at runtime.
	 * You can also set the $boost_screens property in configure().
	 *
	 * Examples of screen values:
	 * - 'plugins' - Plugins list screen
	 * - 'plugin-install' - Add new plugin screen
	 * - 'edit-post' - Posts list screen
	 * - 'post' - Post editor screen
	 * - 'edit-page' - Pages list screen
	 * - 'page' - Page editor screen
	 * - 'upload' - Media library screen
	 * - 'themes' - Themes screen
	 * - 'site-editor' - Site editor screen
	 * - 'nav-menus' - Menus screen
	 * - 'widgets' - Widgets screen
	 * - 'users' - Users list screen
	 * - 'options-general' - General settings screen
	 *
	 * @return list<string> Array of WordPress admin screen IDs/bases.
	 */
	public function get_boost_screens() {
		return $this->boost_screens;
	}

	/**
	 * Get the resource identifier for read-first-write pattern matching.
	 *
	 * Tools that operate on the same resource should return the same identifier.
	 * This allows the context fulfillment service to match read tools with write tools.
	 *
	 * @return string|null Resource identifier or null if not set.
	 */
	public function get_resource() {
		return $this->resource;
	}
}
