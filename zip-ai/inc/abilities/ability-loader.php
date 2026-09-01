<?php
/**
 * Ability Loader Trait
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;

/**
 * Trait Ability_Loader
 */
trait Ability_Loader {

	/**
	 * Validate an ability ID against the canonical `{namespace}/{action}-{resource}` format.
	 *
	 * @param Abstract_Ability $ability Ability instance to validate.
	 * @return bool True when the ID matches the required format.
	 */
	public function check_ability_format( $ability ) {
		$id                = $ability->get_id();
		$canonical_actions = array(
			// CRUD.
			'list',
			'get',
			'create',
			'update',
			'delete',
			// State.
			'activate',
			'deactivate',
			'restore',
			// Lifecycle.
			'install',
			'uninstall',
			'upload',
			// Data.
			'import',
			'export',
			'flush',
			'replace',
			// Operations.
			'check',
			'clean',
			'run',
			'edit',
			'read',
			'search',
			'scan',
		);
		$pattern           = '/^[a-z0-9-]+\/(' . implode( '|', $canonical_actions ) . ')-[a-z0-9-]+$/';

		if ( ! preg_match( $pattern, $id ) ) {
			// Check if we are in export mode or debug mode.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- WP_DEBUG-gated developer diagnostic for malformed ability IDs.
				trigger_error(
					esc_html(
						sprintf(
							'Invalid Ability ID format: "%s" in class %s. Expected format: "{namespace}/{action}-{resource}" (e.g., "zipai/update-post_format", "zipai/list-block-patterns"). Action must be one of: %s.',
							$id,
							get_class( $ability ),
							implode( ', ', $canonical_actions )
						)
					),
					E_USER_WARNING
				);
			}
			return false;
		}
		return true;
	}

	/**
	 * Abilities skipped at registration. Hidden from MCP discovery entirely
	 * (LLM cannot see them, internal callers cannot use them either). Use the
	 * `internal` visibility flag in an ability's get_meta() to keep it
	 * registered for backend/server-only callers while hiding from the LLM.
	 *
	 * ExecuteRestRequest stays registered (internal callers like
	 * PageDeliveryService, ParallelPageBuilderService still need it); it is
	 * hidden from the LLM via meta visibility instead.
	 *
	 * Currently empty — ExecuteAjaxAction and SearchEndpoints were removed
	 * outright (unused by the LLM and every internal caller).
	 *
	 * @var array<int,string>
	 */
	protected static $disabled_abilities = array();

	/**
	 * Build a basename index for disabled abilities.
	 *
	 * Accepts either `ClassName` or `Vendor\Ns\ClassName` entries and
	 * normalizes both to `ClassName` for the file-basename match used by
	 * directory scanning.
	 *
	 * @return array<string, true>
	 */
	protected function get_disabled_abilities_index() {
		$index = array();

		foreach ( self::$disabled_abilities as $ability_class ) {
			if ( '' === $ability_class ) {
				continue;
			}

			$normalized_class_name = ltrim( $ability_class, '\\' );
			$last_separator        = strrpos( $normalized_class_name, '\\' );
			$basename              = false === $last_separator
				? $normalized_class_name
				: substr( $normalized_class_name, $last_separator + 1 );

			if ( '' !== $basename ) {
				$index[ $basename ] = true;
			}
		}

		return $index;
	}

	/**
	 * Assemble the `meta` array an ability is registered with.
	 *
	 * @param Abstract_Ability $ability Ability to describe.
	 * @return array<string,mixed>
	 */
	protected function build_meta( $ability ) {
		$meta = array(
			'tool_type'    => $ability->get_tool_type(),
			'examples'     => $ability->get_examples(),
			'api_endpoint' => $ability->get_api_endpoint(),
		);

		// Add boost screens if defined.
		$boost_screens = $ability->get_boost_screens();
		if ( ! empty( $boost_screens ) ) {
			$meta['boost_screens'] = $boost_screens;
		}

		// Add resource identifier if defined.
		$resource = $ability->get_resource();
		if ( ! empty( $resource ) ) {
			$meta['resource'] = $resource;
		}

		// Read-only sub-action allowlist for multiplexed abilities.
		// Forwarded through meta → MCP tools/list → the server so the
		// writes-require-approval gate can treat safe sub-actions
		// (e.g. `action:"list"` on an otherwise-destructive tool)
		// as reads. Empty for single-purpose abilities.
		$read_only_actions = $ability->get_read_only_actions();
		if ( ! empty( $read_only_actions ) ) {
			$meta['read_only_actions'] = array_values( $read_only_actions );
		}

		// Merge with any class-specific meta.
		$meta = array_merge( $meta, $ability->get_meta_data() );

		/*
		 * Both keys route around REST_API::check_permission(): `show_in_rest`
		 * makes the ability EXECUTABLE (not merely listed) on core's
		 * `/wp-abilities/v1/abilities/{name}/run`, which applies no capability
		 * beyond the ability's own; `mcp.public` exposes it on the bundled
		 * adapter's default server, whose floor is `read`. Exposing an ability
		 * there must be a reviewed decision, not a meta key.
		 *
		 * The whole `mcp` key goes, not just `mcp.public` — nothing here needs
		 * any of it (the adapter also reads `mcp.type`), and a partial strip
		 * would invite a fresh review of every sub-key someone adds later.
		 */
		unset( $meta['show_in_rest'], $meta['mcp'] );

		// Add constraints if the method exists.
		if ( method_exists( $ability, 'get_constraints' ) ) {
			$meta['constraints'] = $ability->get_constraints();
		}

		return $meta;
	}

	/**
	 * Discover and register ability classes found under a directory.
	 *
	 * @param string $directory Directory to scan for ability class files.
	 * @param string $namespace Base namespace for the discovered classes.
	 * @return void
	 */
	protected function load_abilities_from_dir( $directory, $namespace ) {
		// Normalize directory path.
		$directory                = trailingslashit( $directory );
		$disabled_abilities_index = $this->get_disabled_abilities_index();

		// Check if directory exists.
		if ( ! is_dir( $directory ) ) {
			return;
		}

		// Use RecursiveDirectoryIterator for recursive scanning.
		$directory_iterator = new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS );
		$iterator           = new \RecursiveIteratorIterator( $directory_iterator );

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof \SplFileInfo ) {
				continue;
			}

			if ( $file_info->isDir() || 'php' !== $file_info->getExtension() ) {
				continue;
			}

			$file_path  = $file_info->getPathname();
			$class_name = $file_info->getBasename( '.php' );

			// Skip index and handler files.
			if ( 'index' === $class_name || 'handler' === $class_name ) {
				continue;
			}

			if ( isset( $disabled_abilities_index[ $class_name ] ) ) {
				continue;
			}

			// Calculate class namespace based on relative path.
			$relative_path = str_replace( array( $directory, '.php' ), '', $file_path );
			$path_parts    = explode( DIRECTORY_SEPARATOR, $relative_path );
			array_pop( $path_parts ); // Remove class name from parts.

			// Capitalize each path part to match PSR-4 namespace convention.
			$path_parts = array_map( 'ucfirst', $path_parts );

			$sub_namespace = ! empty( $path_parts ) ? '\\' . implode( '\\', $path_parts ) : '';

			// File names are WordPress-style (plugin-install.php) while class
			// names may be CamelCase (PluginInstall) or Snake_Case
			// (Plugin_Install) — try each candidate against the autoloader.
			$candidates = array_unique(
				array(
					$class_name,
					str_replace( '-', '', ucwords( $class_name, '-' ) ),
					str_replace( '-', '_', ucwords( $class_name, '-' ) ),
				)
			);

			$full_class_name = '';
			foreach ( $candidates as $candidate ) {
				$fqcn = $namespace . $sub_namespace . '\\' . $candidate;
				// is_subclass_of also skips non-ability classes and the
				// abstract base itself (instantiating it would fatal).
				if ( class_exists( $fqcn ) && is_subclass_of( $fqcn, Abstract_Ability::class ) ) {
					$full_class_name = $fqcn;
					break;
				}
			}

			if ( '' === $full_class_name ) {
				continue;
			}

			// Instantiate the ability (guaranteed Abstract_Ability subclass).
			$ability = new $full_class_name();

			$meta = $this->build_meta( $ability );

			// MCP tool annotations, declared BY the ability (derived from its tool
			// type + destructive flag, overridden where those miss the point).
			$meta['annotations'] = $ability->get_annotations();

			// The MCP Adapter's `execute-ability` dispatcher gates on
			// `meta.mcp.public`. This class classifies the external surface. So it
			// sets the flag here. This lets an external agent reach Era's abilities
			// by name. We do not need to advertise every schema.
			//
			// This is the ONE sanctioned writer of the flag. It must stay AFTER
			// build_meta(). build_meta strips `show_in_rest` and `mcp` that an
			// ability declares on ITSELF (DSA-19/20). Self-exposure is not a
			// reviewed decision. This block IS the reviewed decision. Two gates
			// guard it: the Connection toggle and the exposure policy.
			//
			// There is NO denylist. Every `zipai/*` ability gets the flag. The
			// site's `zip_ai_external_allowed_abilities` filter can withhold it.
			// `is_exposed()` enforces that filter. So narrowing the filter governs
			// execution, not just the displayed list. Each ability's own capability
			// is the floor. So an ADMIN application password pasted into an AI
			// client can reach `run-wp-cli` and `run-snippet`. This runs arbitrary
			// code. This is by design. The admin already has this reach in wp-admin.
			//
			// This is gated on the endpoint being switched on. The adapter's
			// dispatcher reads the flag. It reads it on EVERY server, including the
			// adapter's own. Without this gate, turning the Connection toggle off
			// would stop advertising our tools. But the abilities would still run
			// elsewhere.
			if ( \ZipAI\MCP\Classes\Api\External_Mcp::is_enabled()
				&& \ZipAI\MCP\Classes\Core\External_Tool_Policy::is_exposed( $ability->get_id() ) ) {
				$meta['mcp'] = array( 'public' => true );
			}

			// Validate ID format.
			$this->check_ability_format( $ability );

			// Register directly with wp_register_ability (no lazy loading).
			// The skip is silent by design: check_ability_format() above has
			// already trigger_error()'d under WP_DEBUG for malformed IDs, and
			// this mirrors wp_register_ability's own lowercase-only rejection.
			$ability_id = $ability->get_id();
			if ( '' === $ability_id || '0' === $ability_id || strtolower( $ability_id ) !== $ability_id ) {
				continue;
			}
			wp_register_ability(
				$ability_id,
				array(
					'label'               => $ability->get_label(),
					'description'         => $ability->get_description(),
					'category'            => $ability->get_category(),
					'input_schema'        => $ability->get_final_input_schema(),
					'execute_callback'    => array( $ability, 'handle_execute' ),
					'permission_callback' => array( $ability, 'check_permission' ),
					'meta'                => $meta,
				)
			);
		}
	}
}
