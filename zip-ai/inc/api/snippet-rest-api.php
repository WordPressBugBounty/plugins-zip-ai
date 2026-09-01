<?php
/**
 * Snippet REST API - Manage ZIP AI code snippets via REST endpoints.
 *
 * Endpoints:
 *   GET    /snippets              — List all snippets
 *   POST   /snippets              — Create new snippet
 *   GET    /snippets/{slug}       — Get single snippet with file contents
 *   POST   /snippets/{slug}       — Update snippet
 *   DELETE /snippets/{slug}       — Delete snippet
 *   POST   /snippets/{slug}/toggle — Toggle enable/disable
 *   GET    /snippets/export       — Export selected snippets as JSON
 *   POST   /snippets/import       — Import snippets from JSON
 *
 * @since 0.1.0
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Api;

use ZipAI\MCP\Classes\Core\Snippet_Store;
use ZipAI\MCP\Classes\Core\Snippet_Versions;
use ZipAI\MCP\Classes\Core\Snippet_Lint;
use ZipAI\MCP\Classes\Core\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Snippet_REST_API {

	/**
	 * REST API route namespace.
	 *
	 * @var non-falsy-string
	 */
	private $namespace = 'zip-ai/v1';

	private const MAX_CODE_SIZE = 102400; // 100KB

	/**
	 * Wire up the REST route registration hook.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	// ── Routes ─────────────────────────────────────────────────────────

	/**
	 * Register all snippet REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$slug_args = array(
			'slug' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_file_name',
				'validate_callback' => function ( $value ) {
					return (bool) preg_match( '/^[a-zA-Z0-9_-]+$/', Utils::to_str( $value ) );
				},
			),
		);

		// List all.
		register_rest_route(
			$this->namespace,
			'/snippets',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_snippets' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Create new.
		register_rest_route(
			$this->namespace,
			'/snippets',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_snippet' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		// Export.
		register_rest_route(
			$this->namespace,
			'/snippets/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_snippets' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Import.
		register_rest_route(
			$this->namespace,
			'/snippets/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_snippets' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		// Safe mode toggle.
		register_rest_route(
			$this->namespace,
			'/snippets/safe-mode',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle_safe_mode' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// NOTE: the `/snippets/{slug}/test` route was removed — it `exec()`d
		// request-body PHP with no lint and no create/enable step, i.e. a
		// direct arbitrary-code-execution surface, and nothing in the UI called
		// it. Sandbox mode (below) is how a snippet is trialled.

		// Sandbox mode toggle.
		register_rest_route(
			$this->namespace,
			'/snippets/(?P<slug>[a-zA-Z0-9_-]+)/sandbox',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle_sandbox' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => $slug_args,
			)
		);

		// Toggle.
		register_rest_route(
			$this->namespace,
			'/snippets/(?P<slug>[a-zA-Z0-9_-]+)/toggle',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle_snippet' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $slug_args,
			)
		);

		// Targeting lookups — autocomplete posts/pages for the post-picker condition.
		register_rest_route(
			$this->namespace,
			'/snippets/lookup/posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_posts' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Versions — list / create manual snapshot.
		register_rest_route(
			$this->namespace,
			'/snippets/(?P<slug>[a-zA-Z0-9_-]+)/versions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_versions' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $slug_args,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_manual_version' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
					'args'                => $slug_args,
				),
			)
		);

		// Versions — diff against another version (or current HEAD by default).
		register_rest_route(
			$this->namespace,
			'/snippets/(?P<slug>[a-zA-Z0-9_-]+)/versions/(?P<id>[a-zA-Z0-9_-]+)/diff',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'diff_version' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $slug_args,
			)
		);

		// Versions — restore.
		register_rest_route(
			$this->namespace,
			'/snippets/(?P<slug>[a-zA-Z0-9_-]+)/versions/(?P<id>[a-zA-Z0-9_-]+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'restore_version' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => $slug_args,
			)
		);

		// Versions — get / delete one.
		register_rest_route(
			$this->namespace,
			'/snippets/(?P<slug>[a-zA-Z0-9_-]+)/versions/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_version' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $slug_args,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_version' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $slug_args,
				),
			)
		);

		// Get / Update / Delete single.
		register_rest_route(
			$this->namespace,
			'/snippets/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_snippet' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $slug_args,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_snippet' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
					'args'                => $slug_args,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_snippet' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $slug_args,
				),
			)
		);
	}

	/**
	 * Permission callback for inspect-and-switch-off routes — requires
	 * `Snippet_Store::read_capability()` (`manage_options` by default).
	 *
	 * Kept below the write cap on purpose: the executor has no cap check, so an
	 * enabled snippet keeps running regardless. Locking these routes behind the
	 * file-editor cap would leave a DISALLOW_FILE_EDIT host or a multisite
	 * sub-site admin with live snippets they cannot inspect, disable or delete.
	 *
	 * @return true|\WP_Error True when allowed, error response otherwise.
	 */
	public function check_permission() {
		if ( Snippet_Store::current_user_can_read() ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to manage snippets.', 'zip-ai' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission callback for routes that put PHP on disk or turn it on —
	 * requires `Snippet_Store::manage_capability()` (`edit_plugins` by default).
	 *
	 * `manage_options` is NOT sufficient: a multisite sub-site admin holds it
	 * while being denied the file editor, and a site with DISALLOW_FILE_EDIT has
	 * asked not to have code written at all.
	 *
	 * @return true|\WP_Error True when allowed, error response otherwise.
	 */
	public function check_write_permission() {
		if ( Snippet_Store::current_user_can_manage() ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to create, change or enable snippets. Snippet code runs on your site, so this requires the same capability as the plugin file editor.', 'zip-ai' ),
			array( 'status' => 403 )
		);
	}

	// ── List ───────────────────────────────────────────────────────────

	/**
	 * List all snippets.
	 *
	 * @return \WP_REST_Response Response carrying the snippets list.
	 */
	public function get_snippets() {
		/**
		 * Narrowed type for `$manifest`.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();
		$snippets = array();

		foreach ( $manifest as $slug => $meta ) {
			if ( '_version' === $slug ) {
				continue;
			}
			$snippets[] = $this->format_snippet( $slug, $meta );
		}

		return new \WP_REST_Response( array( 'snippets' => $snippets ), 200 );
	}

	// ── Get Single ─────────────────────────────────────────────────────

	/**
	 * Get a single snippet including its file contents.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the snippet or an error.
	 */
	public function get_snippet( $request ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $request->get_param( 'slug' ) ) );
		if ( ! $slug ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid slug.' ), 400 );
		}

		/**
		 * Narrowed type for `$manifest`.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return new \WP_REST_Response( array( 'error' => 'Snippet not found.' ), 404 );
		}

		$snippet                  = $this->format_snippet( $slug, $manifest[ $slug ] );
		$snippet['file_contents'] = $this->read_snippet_files( $slug );

		return new \WP_REST_Response( $snippet, 200 );
	}

	// ── Create ─────────────────────────────────────────────────────────

	/**
	 * Create a new snippet.
	 *
	 * @since 0.0.5
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the created snippet or an error.
	 */
	public function create_snippet( $request ) {
		$body  = $request->get_json_params();
		$title = sanitize_text_field( Utils::to_str( $body['title'] ?? '' ) );

		if ( empty( $title ) ) {
			return new \WP_REST_Response( array( 'error' => 'Title is required.' ), 400 );
		}

		// Generate slug from title.
		$slug = sanitize_title( $title );
		$slug = Snippet_Store::validate_slug( $slug );
		if ( ! $slug ) {
			return new \WP_REST_Response( array( 'error' => 'Could not generate valid slug from title.' ), 400 );
		}

		/**
		 * Loaded snippets manifest keyed by slug.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();

		// Handle slug conflicts.
		$original_slug = $slug;
		$counter       = 1;
		while ( isset( $manifest[ $slug ] ) ) {
			$slug = $original_slug . '-' . $counter;
			++$counter;
		}

		/**
		 * Narrowed type for `$file_contents`.
		 *
		 * @var array<string, mixed> $file_contents
		 */
		$file_contents = $body['file_contents'] ?? array();
		$files         = array();
		$file_hashes   = array();
		$execution     = $this->sanitize_execution( $body['execution'] ?? array() );

		// Refuse a create whose targeting could not be represented faithfully
		// — persisting looser constraints than asked widens where the code
		// runs once enabled.
		$conditions_result = $this->sanitize_conditions( $body['conditions'] ?? array() );
		if ( ! empty( $conditions_result['rejected'] ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'Targeting conditions rejected — nothing was saved: ' . implode( '; ', $conditions_result['rejected'] ) ),
				400
			);
		}

		// Validate + wrap EVERY file before touching the filesystem. Minting the
		// dir first would leave `base/<slug>-0.0.9xxx/` behind on a rejected
		// create, with a fresh random suffix on every retry and no manifest
		// entry — invisible to the UI, undeletable through it.
		$prepared = array();
		foreach ( $file_contents as $type => $content ) {
			$type = sanitize_key( $type );
			if ( ! in_array( $type, Snippet_Store::ALLOWED_TYPES, true ) ) {
				continue;
			}

			$validation = $this->validate_code( $type, Utils::to_str( $content ), Utils::to_str( $execution[ $type ]['hook'] ?? '' ) );
			if ( is_wp_error( $validation ) ) {
				return new \WP_REST_Response( array( 'error' => $validation->get_error_message() ), 400 );
			}

			$stored = 'php' === $type
				? Snippet_Store::prepare_php_file( Utils::to_str( $content ), $title )
				: Utils::to_str( $content );
			if ( is_wp_error( $stored ) ) {
				return new \WP_REST_Response( array( 'error' => $stored->get_error_message() ), 400 );
			}

			$prepared[ $type ] = $stored;
		}

		// Randomized on-disk dir + HTTP-deny protection files.
		$dir_name    = Snippet_Store::prepare_snippet_dir( $slug, true );
		$snippet_dir = Snippet_Store::snippet_dir( $slug );

		foreach ( $prepared as $type => $stored ) {
			$filepath = $snippet_dir . '/snippet.' . $type;
			if ( ! Snippet_Store::is_safe_path( $filepath ) ) {
				$this->remove_directory( $snippet_dir );
				return new \WP_REST_Response( array( 'error' => 'Refused unsafe write path.' ), 400 );
			}
			Snippet_Store::put_file( $filepath, $stored );
			Snippet_Store::invalidate_opcache( $filepath );
			$files[]              = $type;
			$file_hashes[ $type ] = hash_file( 'sha256', $filepath );
		}

		// Build manifest entry.
		$manifest[ $slug ] = array(
			'title'           => $title,
			'dir'             => $dir_name, // randomized on-disk basename
			'description'     => sanitize_text_field( Utils::to_str( $body['description'] ?? '' ) ),
			'files'           => $files,
			'file_hashes'     => $file_hashes,
			'enabled'         => false,
			'execution'       => $execution,
			'conditions'      => $conditions_result['rows'],
			'created'         => gmdate( 'Y-m-d H:i:s' ),
			'created_by'      => get_current_user_id(),
			'updated'         => gmdate( 'Y-m-d H:i:s' ),
			'updated_by'      => get_current_user_id(),
			'updated_by_type' => 'user',
		);

		Snippet_Store::save( $manifest );

		// Seed v1 from the just-written HEAD files.
		Snippet_Versions::create(
			$slug,
			array(
				'author_type' => 'user',
				'author_id'   => get_current_user_id(),
				'author_name' => $this->current_user_display_name(),
				'reason'      => 'Initial version',
				'manual'      => false,
			)
		);

		// Re-load manifest because Snippet_Versions::create() updated the version pointer.
		/**
		 * Narrowed type for `$manifest`.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'slug'    => $slug,
				'snippet' => $this->format_snippet( $slug, $manifest[ $slug ] ),
			),
			201
		);
	}

	// ── Update ─────────────────────────────────────────────────────────

	/**
	 * Update an existing snippet.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the updated snippet or an error.
	 */
	public function update_snippet( $request ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $request->get_param( 'slug' ) ) );
		if ( ! $slug ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid slug.' ), 400 );
		}

		/**
		 * Narrowed type for `$manifest`.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return new \WP_REST_Response( array( 'error' => 'Snippet not found.' ), 404 );
		}

		$body = $request->get_json_params();

		// Keeps the existing dir, but back-fills anything a snippet written by
		// an older build is missing: the base-dir deny files, and this dir's
		// `.htaccess` / `web.config` / `index.php`. Saving is what migrates an
		// existing snippet — its body also picks up the ABSPATH wrapper below.
		Snippet_Store::prepare_snippet_dir( $slug, false );

		$snippet_dir     = Snippet_Store::snippet_dir( $slug );
		$applied         = array();
		$snapshot_needed = false;

		// Update description if provided.
		if ( isset( $body['description'] ) ) {
			$description = sanitize_text_field( Utils::to_str( $body['description'] ) );
			if ( ( $manifest[ $slug ]['description'] ?? '' ) !== $description ) {
				$snapshot_needed = true;
			}
			$manifest[ $slug ]['description'] = $description;
			$applied[]                        = 'description';
		}

		// Update execution if provided — merged over the PERSISTED execution:
		// a partial payload must not reset the other file types' bindings
		// back to defaults.
		if ( isset( $body['execution'] ) && is_array( $body['execution'] ) ) {
			$persisted_execution = is_array( $manifest[ $slug ]['execution'] ?? null ) ? $manifest[ $slug ]['execution'] : null;
			$execution           = $this->sanitize_execution( $body['execution'], $persisted_execution );
			if ( ( $manifest[ $slug ]['execution'] ?? array() ) !== $execution ) {
				$snapshot_needed = true;
			}
			$manifest[ $slug ]['execution'] = $execution;
			$applied[]                      = 'execution';
		}

		// Update conditions if provided. A row that could not be represented
		// faithfully rejects the whole write — persisting looser targeting
		// than asked widens where live code runs.
		if ( isset( $body['conditions'] ) && is_array( $body['conditions'] ) ) {
			$conditions = $this->sanitize_conditions( $body['conditions'] );
			if ( ! empty( $conditions['rejected'] ) ) {
				return new \WP_REST_Response(
					array( 'error' => 'Targeting conditions rejected — nothing was saved: ' . implode( '; ', $conditions['rejected'] ) ),
					400
				);
			}
			if ( ( $manifest[ $slug ]['conditions'] ?? array() ) !== $conditions['rows'] ) {
				$snapshot_needed = true;
			}
			$manifest[ $slug ]['conditions'] = $conditions['rows'];
			$applied[]                       = 'conditions';
		}

		// Delete files for types removed from the intended set. The React
		// editor sends `files` as the post-save desired list — anything in
		// the manifest's existing `files` but missing from this payload gets
		// dropped from disk + manifest. This lets the UI uncheck a file type
		// and have the actual file removed on save.
		if ( isset( $body['files'] ) && is_array( $body['files'] ) ) {
			$desired = array_values(
				array_intersect(
					array_map( fn( $file ) => sanitize_key( Utils::to_str( $file ) ), $body['files'] ),
					Snippet_Store::ALLOWED_TYPES
				)
			);
			/**
			 * Narrowed type for `$current`.
			 *
			 * @var list<string> $current
			 */
			$current = $manifest[ $slug ]['files'] ?? array();
			$removed = array_diff( $current, $desired );
			foreach ( $removed as $type ) {
				$filepath = $snippet_dir . '/snippet.' . $type;
				if ( Snippet_Store::is_safe_path( $filepath ) && file_exists( $filepath ) ) {
					wp_delete_file( $filepath );
				}
				$hashes = $manifest[ $slug ]['file_hashes'] ?? array();
				if ( is_array( $hashes ) && isset( $hashes[ $type ] ) ) {
					unset( $hashes[ $type ] );
					$manifest[ $slug ]['file_hashes'] = $hashes;
				}
			}
			if ( ! empty( $removed ) ) {
				$manifest[ $slug ]['files'] = array_values( array_diff( $current, $removed ) );
				$applied[]                  = 'files_removed';
				$snapshot_needed            = true;
			}
		}

		// Update file contents if provided. Track whether any file content
		// actually changed so we can record a new version snapshot.
		$content_changed = false;
		if ( ! empty( $body['file_contents'] ) && is_array( $body['file_contents'] ) ) {
			foreach ( $body['file_contents'] as $type => $content ) {
				$type = sanitize_key( $type );
				if ( ! in_array( $type, Snippet_Store::ALLOWED_TYPES, true ) ) {
					continue;
				}

				$exec_all       = $manifest[ $slug ]['execution'] ?? array();
				$exec_one       = ( is_array( $exec_all ) && isset( $exec_all[ $type ] ) && is_array( $exec_all[ $type ] ) ) ? $exec_all[ $type ] : array();
				$execution_hook = isset( $exec_one['hook'] ) ? Utils::to_str( $exec_one['hook'] ) : '';
				$validation     = $this->validate_code( $type, Utils::to_str( $content ), $execution_hook );
				if ( is_wp_error( $validation ) ) {
					return new \WP_REST_Response( array( 'error' => $validation->get_error_message() ), 400 );
				}

				$filepath = $snippet_dir . '/snippet.' . $type;
				if ( ! Snippet_Store::is_safe_path( $filepath ) ) {
					continue;
				}

				$hashes    = $manifest[ $slug ]['file_hashes'] ?? array();
				$hashes    = is_array( $hashes ) ? $hashes : array();
				$prev_hash = $hashes[ $type ] ?? null;

				$stored = 'php' === $type
					? Snippet_Store::prepare_php_file( Utils::to_str( $content ), Utils::to_str( $manifest[ $slug ]['title'] ?? '' ) )
					: Utils::to_str( $content );
				if ( is_wp_error( $stored ) ) {
					return new \WP_REST_Response( array( 'error' => $stored->get_error_message() ), 400 );
				}
				Snippet_Store::put_file( $filepath, $stored );
				Snippet_Store::invalidate_opcache( $filepath );

				// Update hash + files list.
				$new_hash                         = hash_file( 'sha256', $filepath );
				$hashes[ $type ]                  = $new_hash;
				$manifest[ $slug ]['file_hashes'] = $hashes;

				if ( $new_hash !== $prev_hash ) {
					$content_changed = true;
					$snapshot_needed = true;
				}

				$files_list = $manifest[ $slug ]['files'] ?? array();
				$files_list = is_array( $files_list ) ? $files_list : array();
				if ( ! in_array( $type, $files_list, true ) ) {
					$files_list[]               = $type;
					$manifest[ $slug ]['files'] = $files_list;
				}
			}
			if ( $content_changed ) {
				$applied[] = 'file_contents';
			}
		}

		$manifest[ $slug ]['updated']         = gmdate( 'Y-m-d H:i:s' );
		$manifest[ $slug ]['updated_by']      = get_current_user_id();
		$manifest[ $slug ]['updated_by_type'] = 'user';

		Snippet_Store::save( $manifest );

		// Auto-snapshot new version when the saved snippet behavior changes.
		// Versions include both files and snippet metadata, so rollback must
		// cover targeting/execution/file-removal edits as well as code changes.
		if ( $snapshot_needed ) {
			Snippet_Versions::create(
				$slug,
				array(
					'author_type' => 'user',
					'author_id'   => get_current_user_id(),
					'author_name' => $this->current_user_display_name(),
					'reason'      => sanitize_text_field( Utils::to_str( $body['version_reason'] ?? '' ) ),
					'manual'      => false,
				)
			);
			/**
			 * Narrowed type for `$manifest`.
			 *
			 * @var array<string, array<string, mixed>> $manifest
			 */
			$manifest = Snippet_Store::load();
		}

		return new \WP_REST_Response(
			array(
				'success'        => true,
				'snippet'        => $this->format_snippet( $slug, $manifest[ $slug ] ),
				'applied_fields' => array_values( array_unique( $applied ) ),
			),
			200
		);
	}

	// ── Sandbox Mode ───────────────────────────────────────────────────

	/**
	 * Toggle sandbox mode for a snippet.
	 *
	 * Actions:
	 *   activate   — enable sandbox for current user (snippet runs only for you)
	 *   deactivate — turn off sandbox (snippet stops running)
	 *   go_live    — promote sandbox to live (enable for everyone, clear sandbox)
	 *
	 * @since 0.0.5
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the sandbox and enabled state or an error.
	 */
	public function toggle_sandbox( $request ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $request->get_param( 'slug' ) ) );
		if ( ! $slug ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid slug.' ), 400 );
		}

		/**
		 * Narrowed type for `$manifest`.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return new \WP_REST_Response( array( 'error' => 'Snippet not found.' ), 404 );
		}

		$body   = $request->get_json_params();
		$action = $body['action'] ?? 'activate';

		switch ( $action ) {
			case 'activate':
				// No subprocess test needed — just enable sandbox for this user.
				// The executor will include the file ONLY for this user.
				// If it crashes, only their session is affected.
				$manifest[ $slug ]['sandbox']      = true;
				$manifest[ $slug ]['sandbox_user'] = get_current_user_id();
				$manifest[ $slug ]['enabled']      = false; // Not live — only sandbox.
				break;

			case 'deactivate':
				$manifest[ $slug ]['sandbox']      = false;
				$manifest[ $slug ]['sandbox_user'] = null;
				break;

			case 'go_live':
				// Promote from sandbox to live — you already tested it in sandbox.
				$manifest[ $slug ]['sandbox']         = false;
				$manifest[ $slug ]['sandbox_user']    = null;
				$manifest[ $slug ]['enabled']         = true;
				$manifest[ $slug ]['auto_disabled']   = false;
				$manifest[ $slug ]['disabled_reason'] = null;
				break;

			default:
				return new \WP_REST_Response( array( 'error' => 'Invalid action.' ), 400 );
		}

		Snippet_Store::save( $manifest );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'sandbox' => $manifest[ $slug ]['sandbox'],
				'enabled' => $manifest[ $slug ]['enabled'],
			),
			200
		);
	}

	// ── Safe Mode ──────────────────────────────────────────────────────

	/**
	 * Toggle, activate, or deactivate snippet safe mode.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the safe-mode state.
	 */
	public function toggle_safe_mode( $request ) {
		$body     = $request->get_json_params();
		$action   = $body['action'] ?? 'toggle';
		$duration = min( max( intval( Utils::to_str( $body['duration'] ?? 30 ) ), 1 ), 1440 ); // 1 min to 24 hours, default 30 min.
		$ttl      = $duration * MINUTE_IN_SECONDS;

		if ( 'activate' === $action ) {
			set_transient( 'zip_ai_snippets_safe_mode', time() + $ttl, $ttl );
			return new \WP_REST_Response(
				array(
					'success'  => true,
					'active'   => true,
					'duration' => $duration,
				),
				200
			);
		}

		// Leaving safe mode lets every enabled snippet run again — write-level
		// authority, same as enabling one. Entering it is the panic switch and
		// stays on the read cap.
		$is_active = (bool) get_transient( 'zip_ai_snippets_safe_mode' );
		if ( 'deactivate' === $action || ( 'toggle' === $action && $is_active ) ) {
			$allowed = $this->check_write_permission();
			if ( is_wp_error( $allowed ) ) {
				return new \WP_REST_Response( array( 'error' => $allowed->get_error_message() ), 403 );
			}
		}

		if ( 'deactivate' === $action ) {
			delete_transient( 'zip_ai_snippets_safe_mode' );
			return new \WP_REST_Response(
				array(
					'success' => true,
					'active'  => false,
				),
				200
			);
		}

		// Toggle.
		if ( $is_active ) {
			delete_transient( 'zip_ai_snippets_safe_mode' );
		} else {
			set_transient( 'zip_ai_snippets_safe_mode', time() + $ttl, $ttl );
		}

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'active'   => ! $is_active,
				'duration' => $duration,
			),
			200
		);
	}

	// ── Toggle ─────────────────────────────────────────────────────────

	/**
	 * Enable or disable a snippet.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the enabled state or an error.
	 */
	public function toggle_snippet( $request ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $request->get_param( 'slug' ) ) );
		if ( ! $slug ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid slug.' ), 400 );
		}

		/**
		 * Narrowed type for `$manifest`.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return new \WP_REST_Response( array( 'error' => 'Snippet not found.' ), 404 );
		}

		$will_enable = ! $manifest[ $slug ]['enabled'];

		// Turning a snippet ON makes its PHP run, which is write-level authority.
		// Turning it OFF is the safety valve and stays on the read cap.
		if ( $will_enable ) {
			$allowed = $this->check_write_permission();
			if ( is_wp_error( $allowed ) ) {
				return new \WP_REST_Response( array( 'error' => $allowed->get_error_message() ), 403 );
			}
		}

		$manifest[ $slug ]['enabled'] = $will_enable;

		if ( $will_enable ) {
			$manifest[ $slug ]['auto_disabled']   = false;
			$manifest[ $slug ]['disabled_reason'] = null;
			$manifest[ $slug ]['disabled_at']     = null;
		}

		Snippet_Store::save( $manifest );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'enabled' => $manifest[ $slug ]['enabled'],
			),
			200
		);
	}

	// ── Delete ─────────────────────────────────────────────────────────

	/**
	 * Delete a snippet and its on-disk directory.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response indicating success or an error.
	 */
	public function delete_snippet( $request ) {
		$slug = Snippet_Store::validate_slug( Utils::to_str( $request->get_param( 'slug' ) ) );
		if ( ! $slug ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid slug.' ), 400 );
		}

		// Pre-flight existence check using an un-locked read. The
		// authoritative existence check runs again under the lock below.
		/**
		 * Narrowed type for `$pre`.
		 *
		 * @var array<string, array<string, mixed>> $pre
		 */
		$pre = Snippet_Store::load();
		if ( ! isset( $pre[ $slug ] ) ) {
			return new \WP_REST_Response( array( 'error' => 'Snippet not found.' ), 404 );
		}

		// Remove the on-disk snippet directory BEFORE touching the manifest
		// so that on a crash between rmdir and save() the manifest still
		// describes a real directory (worst case: a stale entry pointing at
		// a vanished dir, which the loader / runtime already tolerate). The
		// inverse order — manifest first, files second — could orphan files
		// the manifest no longer knows about.
		$snippet_dir = Snippet_Store::snippet_dir( $slug );
		if ( is_dir( $snippet_dir ) ) {
			$this->remove_directory( $snippet_dir );
		}

		// Atomically drop the slug from the manifest. Lock spans the full
		// read-modify-write window, so parallel bulk-delete requests cannot
		// each load the same baseline and overwrite each other's deletions.
		Snippet_Store::with_lock(
			/**
			 * Drop the target slug from the loaded manifest.
			 *
			 * @param array<string, mixed> $manifest
			 */
			static function ( array &$manifest ) use ( $slug ) {
				unset( $manifest[ $slug ] );
			}
		);

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	// ── Export ─────────────────────────────────────────────────────────

	/**
	 * Export selected snippets as JSON.
	 *
	 * @since 0.0.5
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the exported snippets payload.
	 */
	public function export_snippets( $request ) {
		$slugs_param = $request->get_param( 'slugs' );
		/**
		 * Narrowed type for `$manifest`.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();
		$export   = array();

		$slugs = $slugs_param ? array_map( 'sanitize_key', explode( ',', Utils::to_str( $slugs_param ) ) ) : array_keys( $manifest );

		foreach ( $slugs as $slug ) {
			if ( '_version' === $slug || ! isset( $manifest[ $slug ] ) ) {
				continue;
			}

			$meta  = Snippet_Store::normalize_snippet( $manifest[ $slug ] );
			$files = array();

			foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
				$filepath = Snippet_Store::snippet_file( $slug, $type );
				if ( file_exists( $filepath ) ) {
					$raw            = (string) file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$files[ $type ] = base64_encode( 'php' === $type ? Snippet_Store::unwrap_php( $raw ) : $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				}
			}

			$export[] = array(
				'slug'        => $slug,
				'title'       => $meta['title'],
				'description' => $meta['description'],
				'execution'   => $meta['execution'],
				'conditions'  => $meta['conditions'],
				'files'       => $files,
			);
		}

		return new \WP_REST_Response(
			array(
				'version'     => 1,
				'exported_at' => gmdate( 'c' ),
				'snippets'    => $export,
			),
			200
		);
	}

	// ── Import ─────────────────────────────────────────────────────────

	/**
	 * Import snippets from JSON.
	 *
	 * @since 0.0.5
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with per-snippet import results.
	 */
	public function import_snippets( $request ) {
		$body    = $request->get_json_params();
		$preview = ! empty( $request->get_param( 'preview' ) );

		if ( empty( $body['snippets'] ) || ! is_array( $body['snippets'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid import format. Expected { snippets: [...] }' ), 400 );
		}

		/**
		 * Narrowed type for `$manifest`.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();
		$results  = array();

		foreach ( $body['snippets'] as $item ) {
			/**
			 * Narrowed type for `$item`.
			 *
			 * @var array<string, mixed> $item
			 */
			$slug = sanitize_title( Utils::to_str( $item['slug'] ?? $item['title'] ?? '' ) );
			$slug = Snippet_Store::validate_slug( $slug );

			if ( ! $slug ) {
				$results[] = array(
					'slug'    => $item['slug'] ?? '?',
					'success' => false,
					'error'   => 'Invalid slug.',
				);
				continue;
			}

			// Handle conflicts.
			$original = $slug;
			$counter  = 1;
			while ( isset( $manifest[ $slug ] ) ) {
				$slug = $original . '-imported-' . $counter;
				++$counter;
			}

			if ( $preview ) {
				/**
				 * Narrowed type for `$preview_files`.
				 *
				 * @var array<string, mixed> $preview_files
				 */
				$preview_files = $item['files'] ?? array();
				$results[]     = array(
					'slug'        => $slug,
					'title'       => $item['title'] ?? $slug,
					'files'       => array_keys( $preview_files ),
					'conflict'    => $slug !== $original,
					'will_create' => true,
				);
				continue;
			}

			$execution = $this->sanitize_execution( $item['execution'] ?? array() );
			/**
			 * Narrowed type for `$import_files`.
			 *
			 * @var array<string, mixed> $import_files
			 */
			$import_files = $item['files'] ?? array();

			// Decode + validate EVERY file before touching the filesystem. A
			// mid-loop bail after mkdir would orphan a randomly-named directory
			// the UI can neither see nor delete, one per retry.
			$bodies      = array();
			$item_failed = false;
			foreach ( $import_files as $type => $encoded ) {
				$type = sanitize_key( $type );
				if ( ! in_array( $type, Snippet_Store::ALLOWED_TYPES, true ) ) {
					continue;
				}

				$content = base64_decode( Utils::to_str( $encoded ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

				// An exported pack carries the stored wrapper; lint the author's
				// body, not our guard header.
				if ( 'php' === $type ) {
					$content = Snippet_Store::unwrap_php( $content );
				}

				$validation = $this->validate_code( $type, $content, Utils::to_str( $execution[ $type ]['hook'] ?? '' ) );
				if ( is_wp_error( $validation ) ) {
					$results[]   = array(
						'slug'    => $slug,
						'success' => false,
						'error'   => $validation->get_error_message(),
					);
					$item_failed = true;
					break;
				}

				if ( 'php' === $type ) {
					$stored = Snippet_Store::prepare_php_file( $content, Utils::to_str( $item['title'] ?? $slug ) );
					if ( is_wp_error( $stored ) ) {
						$results[]   = array(
							'slug'    => $slug,
							'success' => false,
							'error'   => $stored->get_error_message(),
						);
						$item_failed = true;
						break;
					}
					$bodies[ $type ] = $stored;
					continue;
				}

				$bodies[ $type ] = $content;
			}

			if ( $item_failed ) {
				continue;
			}

			// Randomized on-disk dir + HTTP-deny protection files. The slug
			// comes from the import payload, so a slug-named dir would be an
			// attacker-chosen, directly fetchable path.
			$dir_name    = Snippet_Store::prepare_snippet_dir( $slug, true );
			$snippet_dir = Snippet_Store::snippet_dir( $slug );

			// Write files. `$bodies` already holds storage-ready bytes — PHP was
			// wrapped and parse-checked in the validation pass above.
			$files       = array();
			$file_hashes = array();
			foreach ( $bodies as $type => $stored ) {
				$filepath = $snippet_dir . '/snippet.' . $type;
				if ( ! Snippet_Store::is_safe_path( $filepath ) ) {
					$results[]   = array(
						'slug'    => $slug,
						'success' => false,
						'error'   => 'Refused unsafe write path.',
					);
					$item_failed = true;
					break;
				}
				Snippet_Store::put_file( $filepath, $stored );
				Snippet_Store::invalidate_opcache( $filepath );
				$files[]              = $type;
				$file_hashes[ $type ] = hash_file( 'sha256', $filepath );
			}

			if ( $item_failed ) {
				// Nothing references this dir — import always resolves to a
				// fresh slug, so it was minted a few lines ago and has no
				// manifest entry. Drop it rather than leak it.
				$this->remove_directory( $snippet_dir );
				continue;
			}

			// Build manifest entry — always disabled on import. Rejected
			// condition rows don't block the import (the snippet arrives
			// DISABLED, so nothing can widen silently) but are surfaced on
			// the per-item result for review before enabling.
			$item_conditions = $this->sanitize_conditions( $item['conditions'] ?? array() );

			$manifest[ $slug ] = array(
				'title'       => sanitize_text_field( Utils::to_str( $item['title'] ?? $slug ) ),
				'dir'         => $dir_name, // randomized on-disk basename
				'description' => sanitize_text_field( Utils::to_str( $item['description'] ?? '' ) ),
				'files'       => $files,
				'file_hashes' => $file_hashes,
				'enabled'     => false,
				'execution'   => $execution,
				'conditions'  => $item_conditions['rows'],
				'created'     => gmdate( 'Y-m-d H:i:s' ),
				'created_by'  => get_current_user_id(),
				'updated'     => gmdate( 'Y-m-d H:i:s' ),
				'updated_by'  => get_current_user_id(),
			);

			$result_row = array(
				'slug'    => $slug,
				'success' => true,
			);
			if ( ! empty( $item_conditions['rejected'] ) ) {
				$result_row['condition_warnings'] = $item_conditions['rejected'];
			}
			$results[] = $result_row;
		}

		if ( ! $preview ) {
			Snippet_Store::save( $manifest );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'preview' => $preview,
				'results' => $results,
			),
			200
		);
	}

	// ── Targeting lookups ──────────────────────────────────────────────

	/**
	 * Search posts/pages by title for the post-picker condition. Returns up to
	 * 20 matches across publish + draft (the caller filters status client-side
	 * if needed).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with matched post/page results.
	 */
	public function lookup_posts( $request ) {
		$q = sanitize_text_field( Utils::to_str( $request->get_param( 'q' ) ) );
		if ( strlen( $q ) < 1 ) {
			return new \WP_REST_Response( array( 'results' => array() ), 200 );
		}

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$query      = new \WP_Query(
			array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'perm'           => 'readable',
				's'              => $q,
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$results = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$results[] = array(
				'id'    => (int) $post->ID,
				'title' => '' !== $post->post_title ? $post->post_title : sprintf( '(no title #%d)', $post->ID ),
				'type'  => $post->post_type,
				'slug'  => $post->post_name,
			);
		}

		return new \WP_REST_Response( array( 'results' => $results ), 200 );
	}

	// ── Versions ───────────────────────────────────────────────────────

	/**
	 * Current user's display name, or "You" when unavailable.
	 *
	 * @return string Display name.
	 */
	private function current_user_display_name() {
		$user = wp_get_current_user();
		return $user->ID ? $user->display_name : 'You';
	}

	/**
	 * Resolve and validate the slug param, ensuring the snippet exists.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return string|\WP_REST_Response Validated slug, or an error response.
	 */
	private function require_snippet( $request ) {
		$raw  = Utils::to_str( $request->get_param( 'slug' ) );
		$slug = Snippet_Store::validate_slug( $raw );
		if ( ! $slug ) {
			return new \WP_REST_Response(
				array( 'error' => sprintf( 'Invalid slug "%s". Slugs must contain only letters, numbers, and hyphens.', $raw ) ),
				400
			);
		}
		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return new \WP_REST_Response(
				array( 'error' => Snippet_Store::not_found_message( $slug ) ),
				404
			);
		}
		return $slug;
	}

	/**
	 * List the version history for a snippet.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the versions list or an error.
	 */
	public function list_versions( $request ) {
		$slug = $this->require_snippet( $request );
		if ( ! is_string( $slug ) ) {
			return $slug;
		}
		return new \WP_REST_Response(
			array(
				'success'  => true,
				'versions' => Snippet_Versions::list_versions( $slug ),
			),
			200
		);
	}

	/**
	 * Get a single stored version of a snippet.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the version or an error.
	 */
	public function get_version( $request ) {
		$slug = $this->require_snippet( $request );
		if ( ! is_string( $slug ) ) {
			return $slug;
		}
		$version = Snippet_Versions::get( $slug, Utils::to_str( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $version ) ) {
			$status = $version->get_error_code() === 'version_not_found' ? 404 : 400;
			return new \WP_REST_Response(
				array(
					'error' => $version->get_error_message(),
					'code'  => $version->get_error_code(),
				),
				$status
			);
		}
		return new \WP_REST_Response( array( 'success' => true ) + $version, 200 );
	}

	/**
	 * Diff a version against another version or current HEAD.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the computed diff.
	 */
	public function diff_version( $request ) {
		$slug = $this->require_snippet( $request );
		if ( ! is_string( $slug ) ) {
			return $slug;
		}
		$against = $request->get_param( 'against' );
		$against = $against ? Utils::to_str( $against ) : null;
		$diff    = Snippet_Versions::diff( $slug, Utils::to_str( $request->get_param( 'id' ) ), $against );
		return new \WP_REST_Response(
			array(
				'success' => true,
				'diff'    => $diff,
			),
			200
		);
	}

	/**
	 * Create a manual version snapshot for a snippet.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the created version or an error.
	 */
	public function create_manual_version( $request ) {
		$slug = $this->require_snippet( $request );
		if ( ! is_string( $slug ) ) {
			return $slug;
		}
		$body   = $request->get_json_params();
		$reason = sanitize_text_field( Utils::to_str( $body['reason'] ?? '' ) );
		if ( '' === $reason ) {
			return new \WP_REST_Response( array( 'error' => 'reason is required.' ), 400 );
		}
		$entry = Snippet_Versions::create(
			$slug,
			array(
				'author_type' => 'user',
				'author_id'   => get_current_user_id(),
				'author_name' => $this->current_user_display_name(),
				'reason'      => $reason,
				'manual'      => true,
			)
		);
		if ( ! $entry ) {
			return new \WP_REST_Response( array( 'error' => 'Failed to create version.' ), 500 );
		}
		return new \WP_REST_Response(
			array(
				'success' => true,
				'version' => $entry,
			),
			201
		);
	}

	/**
	 * Restore a snippet to a stored version.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response with the restore result and any lint warnings.
	 */
	public function restore_version( $request ) {
		$slug = $this->require_snippet( $request );
		if ( ! is_string( $slug ) ) {
			return $slug;
		}
		$entry = Snippet_Versions::restore(
			$slug,
			Utils::to_str( $request->get_param( 'id' ) ),
			array(
				'author_type' => 'user',
				'author_id'   => get_current_user_id(),
				'author_name' => $this->current_user_display_name(),
			)
		);
		if ( is_wp_error( $entry ) ) {
			return new \WP_REST_Response(
				array(
					'error' => $entry->get_error_message(),
					'code'  => $entry->get_error_code(),
				),
				400
			);
		}
		if ( ! $entry ) {
			return new \WP_REST_Response( array( 'error' => 'Failed to restore version.' ), 500 );
		}
		// Restore is a deliberate escape hatch — old code may pre-date current
		// lint rules. Surface warnings in the response so the caller can act
		// on them, but never block the restore itself.
		$lint_warnings = $this->lint_head_php( $slug );
		return new \WP_REST_Response(
			array(
				'success'       => true,
				'version'       => $entry,
				'lint_warnings' => $lint_warnings,
			),
			200
		);
	}

	/**
	 * Run lint on the current HEAD PHP file of a snippet (post-restore).
	 * Returns an array of warning records or an empty array when clean.
	 *
	 * @param string $slug Snippet slug.
	 * @return array<int,array{code:string,message:string}> Lint warning records, empty when clean.
	 */
	private function lint_head_php( $slug ) {
		/**
		 * Narrowed type for `$manifest`.
		 *
		 * @var array<string, array<string, mixed>> $manifest
		 */
		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return array();
		}
		/**
		 * Narrowed type for `$files`.
		 *
		 * @var list<string> $files
		 */
		$files = $manifest[ $slug ]['files'] ?? array();
		if ( ! in_array( 'php', $files, true ) ) {
			return array();
		}
		$file = Snippet_Store::snippet_file( $slug, 'php' );
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$code           = Snippet_Store::unwrap_php( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$exec_all       = $manifest[ $slug ]['execution'] ?? array();
		$exec_php       = ( is_array( $exec_all ) && isset( $exec_all['php'] ) && is_array( $exec_all['php'] ) ) ? $exec_all['php'] : array();
		$execution_hook = isset( $exec_php['hook'] ) ? Utils::to_str( $exec_php['hook'] ) : '';
		$result         = Snippet_Lint::check( (string) $code, $execution_hook );
		if ( ! is_wp_error( $result ) ) {
			return array();
		}
		return array(
			array(
				'code'    => (string) $result->get_error_code(),
				'message' => $result->get_error_message(),
			),
		);
	}

	/**
	 * Delete a stored version of a snippet.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response indicating success or an error.
	 */
	public function delete_version( $request ) {
		$slug = $this->require_snippet( $request );
		if ( ! is_string( $slug ) ) {
			return $slug;
		}
		$ok = Snippet_Versions::delete( $slug, Utils::to_str( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $ok ) ) {
			$status = $ok->get_error_code() === 'version_not_found' ? 404 : 400;
			return new \WP_REST_Response(
				array(
					'error' => $ok->get_error_message(),
					'code'  => $ok->get_error_code(),
				),
				$status
			);
		}
		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	// ── Validation ─────────────────────────────────────────────────────

	/**
	 * Validate code content (size limit + PHP blocklist).
	 *
	 * @since 0.0.5
	 * @param string $type           File type.
	 * @param string $content        Code content.
	 * @param string $execution_hook Execution hook name for lint context.
	 * @return true|\WP_Error True when valid, error describing the problem otherwise.
	 */
	private function validate_code( $type, $content, $execution_hook = '' ) {
		$content = str_replace( "\0", '', $content );

		if ( strlen( $content ) > self::MAX_CODE_SIZE ) {
			return new \WP_Error( 'too_large', 'Code exceeds maximum size of 100KB.' );
		}

		if ( 'php' === $type ) {
			$blocked = Snippet_Store::check_php_blocklist( $content );
			if ( $blocked ) {
				return new \WP_Error( 'blocked', "Blocked: '{$blocked}' is not allowed in PHP snippets." );
			}

			// Inline-targeting lint — production gate that forces routing
			// checks into conditions[] instead of `if (is_home()) return;`.
			$lint = Snippet_Lint::check( $content, $execution_hook );
			if ( is_wp_error( $lint ) ) {
				return $lint;
			}

			// Syntax check — prevents fatal errors on next page load.
			$syntax_error = $this->check_php_syntax( $content );
			if ( $syntax_error ) {
				return new \WP_Error( 'syntax_error', $syntax_error );
			}
		} else {
			// JS/CSS bodies must not contain HTML wrapper tags (the executor
			// already wraps them via wp_add_inline_script/style — an inner
			// `</script>`/`</style>` terminates the wrapper early). HTML
			// bodies must not contain `<?php`/`<?=` open tags (emitted via
			// echo, never executed). Same gate the agent ability runs.
			$non_php_lint = Snippet_Lint::check_non_php( $content, $type );
			if ( is_wp_error( $non_php_lint ) ) {
				return $non_php_lint;
			}
		}

		return true;
	}

	/**
	 * Check PHP code for syntax errors using php -l.
	 *
	 * Writes code to a temp file, runs php -l, parses output.
	 * Returns error message string on failure, null on success.
	 *
	 * @since 0.0.5
	 * @param string $code PHP code to check.
	 * @return string|null Error message or null if valid.
	 */
	private function check_php_syntax( $code ) {
		// Delegate to the shared parse-only validator (token_get_all with
		// TOKEN_PARSE). It validates the body as a complete file and throws on
		// invalid syntax, without the if(false){...} eval wrapper's two failure
		// modes: false-positives on a correct body that closes the PHP tag and
		// ends in trailing HTML, and the risk of an eval parse error escaping
		// as a fatal that takes down the request.
		$error = Snippet_Lint::detect_syntax_error( $code );
		return null === $error ? null : 'PHP Syntax Error: ' . $error;
	}


	/**
	 * Thin pass-through to the central Snippet_Store sanitizer. Kept as a
	 * private method so call sites stay readable.
	 *
	 * @param mixed $execution Raw execution config keyed by file type.
	 * @param mixed $persisted The snippet's persisted execution config — the merge baseline for partial updates.
	 * @return array<string,array<string,mixed>> Sanitized execution config keyed by file type.
	 */
	private function sanitize_execution( $execution, $persisted = null ) {
		/**
		 * Narrowed type for `$exec_arr`.
		 *
		 * @var array<string, mixed> $exec_arr
		 */
		$exec_arr = is_array( $execution ) ? $execution : array();
		/**
		 * Narrowed type for `$sanitized`.
		 *
		 * @var array<string, array<string, mixed>> $sanitized
		 */
		$sanitized = Snippet_Store::sanitize_execution( $exec_arr, is_array( $persisted ) ? $persisted : null );
		return $sanitized;
	}

	/**
	 * Thin pass-through to the central Snippet_Store conditions sanitizer.
	 *
	 * @param mixed $conditions Raw condition rows.
	 * @return array{rows: array<int,array<string,mixed>>, rejected: array<int,string>} Sanitized rows + rejection reasons.
	 */
	private function sanitize_conditions( $conditions ) {
		/**
		 * Narrowed type for `$cond_arr`.
		 *
		 * @var array<int, mixed> $cond_arr
		 */
		$cond_arr = is_array( $conditions ) ? $conditions : array();
		/**
		 * Narrowed type for `$sanitized`.
		 *
		 * @var array{rows: array<int, array<string, mixed>>, rejected: array<int, string>} $sanitized
		 */
		$sanitized = Snippet_Store::sanitize_conditions( $cond_arr );
		return $sanitized;
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Build the API-facing snippet payload from a manifest entry.
	 *
	 * @param string              $slug Snippet slug.
	 * @param array<string,mixed> $meta Manifest entry for the snippet.
	 * @return array<string,mixed> Formatted snippet fields for the response.
	 */
	private function format_snippet( $slug, $meta ) {
		$normalized = Snippet_Store::normalize_snippet( $meta );
		/**
		 * Narrowed type for `$manifest_files`.
		 *
		 * @var list<string> $manifest_files
		 */
		$manifest_files = $normalized['files'];
		$disk_files     = $this->scan_snippet_files( $slug );
		$all_files      = array_values( array_unique( array_merge( $manifest_files, $disk_files ) ) );

		return array(
			'slug'               => $slug,
			'title'              => $normalized['title'],
			'description'        => $normalized['description'],
			'enabled'            => $normalized['enabled'],
			'files'              => $all_files,
			'execution'          => $normalized['execution'],
			'conditions'         => $normalized['conditions'],
			'sandbox'            => $normalized['sandbox'],
			'sandbox_user'       => $normalized['sandbox_user'],
			'auto_disabled'      => $normalized['auto_disabled'],
			'disabled_reason'    => $normalized['disabled_reason'],
			'disabled_at'        => $normalized['disabled_at'],
			'created'            => $normalized['created'],
			'updated'            => $normalized['updated'],
			'updated_by_type'    => $normalized['updated_by_type'],
			'current_version_id' => $normalized['current_version_id'],
			'versions_count'     => $normalized['versions_count'],
			'last_version_at'    => $normalized['last_version_at'],
		);
	}

	/**
	 * List the file types present on disk for a snippet.
	 *
	 * @param string $slug Snippet slug.
	 * @return array<int,string> File-type extensions found on disk.
	 */
	private function scan_snippet_files( $slug ) {
		$snippet_dir = Snippet_Store::snippet_dir( $slug );
		$types       = array();

		foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
			$filepath = $snippet_dir . '/snippet.' . $type;
			if ( Snippet_Store::is_safe_path( $filepath ) && file_exists( $filepath ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Read the on-disk contents of each snippet file.
	 *
	 * @param string $slug Snippet slug.
	 * @return array<string,string> File-type extension mapped to file contents.
	 */
	private function read_snippet_files( $slug ) {
		$contents = array();

		foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
			$filepath = Snippet_Store::snippet_file( $slug, $type );
			if ( Snippet_Store::is_safe_path( $filepath ) && file_exists( $filepath ) ) {
				$raw               = (string) file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$contents[ $type ] = 'php' === $type ? Snippet_Store::unwrap_php( $raw ) : $raw;
			}
		}

		return $contents;
	}

	/**
	 * Recursively remove a snippet directory (guarded to the snippets base).
	 *
	 * @param string $dir Absolute directory path to remove.
	 * @return void
	 */
	private function remove_directory( $dir ) {
		$real_base = realpath( Snippet_Store::base_dir() );
		$real_dir  = realpath( $dir );
		if ( ! $real_base || ! $real_dir || strpos( $real_dir, $real_base . DIRECTORY_SEPARATOR ) !== 0 ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo ) {
				continue;
			}
			if ( $file->isDir() ) {
				Snippet_Store::delete_dir( (string) $file->getRealPath() );
			} else {
				wp_delete_file( (string) $file->getRealPath() );
			}
		}

		Snippet_Store::delete_dir( $dir );
	}
}
