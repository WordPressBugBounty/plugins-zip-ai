<?php
/**
 * Plugin Update. The server runs this.
 *
 * This ability updates one or more plugins from WordPress.org. It accepts a list
 * of slugs. It also accepts `all: true` to update every plugin that has an
 * available update. It runs in-process as the App Password user. It uses WP
 * core `Plugin_Upgrader::bulk_upgrade()`.
 *
 * The compliance checks match PluginInstall. There are five checks.
 *   1. It checks the user capability (`update_plugins`) through the App Password.
 *   2. It uses standard `Plugin_Upgrader` code. It uses ZIP files from WordPress.org.
 *   3. It honors `DISALLOW_FILE_MODS` first.
 *   4. It honors a `WP_Filesystem` init failure on FTP-mode hosts. It returns a
 *      clear error the user can act on.
 *   5. It requires a super-admin on multisite. A sub-site admin App Password gets
 *      a clear error. It does not do a silent partial update.
 *
 * `bulk_upgrade()` does not throw when one slug fails. It returns `null` or a
 * `WP_Error` in that plugin's result slot. So this ability compares the plugin
 * version before and after the update, per slug. This catches a silent no-op.
 * A no-op is when the upgrader reports success but the version did not change.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

use Plugin_Upgrader;
use WP_Ajax_Upgrader_Skin;
use WP_Error;
use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Abilities\Zipai\System\PluginResolver;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PluginUpdate extends Abstract_Ability {

	/**
	 * Flags this ability as destructive (mutates site state).
	 *
	 * @var bool
	 */
	protected $is_destructive = true;

	/**
	 * Configures the ability's id, label, description and metadata.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/update-plugin';
		$this->label       = 'Update Plugin';
		$this->description = 'Update one or more plugins from WordPress.org. Pass `slugs: [..]` for specific plugins or `all: true` to update every plugin with an available update. '
			. 'Runs in-process under the App-Password user\'s identity via `Plugin_Upgrader::bulk_upgrade()`. '
			. 'Requires `update_plugins` capability (network-admin / super_admin on multisite). '
			. 'Synchronous: response carries the actual per-plugin from→to version transitions. '
			. 'Verifies each upgrade by reading back the plugin\'s Version header — catches silent failures where bulk_upgrade returns null for a slot but doesn\'t throw.';
		$this->capability  = 'update_plugins';

		$this->meta = array(
			'tool_type' => Tool_Types::WRITE,
		);
	}

	/**
	 * Returns the tool-type classification for this ability.
	 *
	 * @return string One of the Tool_Types constants.
	 */
	public function get_tool_type() {
		return Tool_Types::WRITE;
	}

	/**
	 * Returns the JSON Schema for this ability's input arguments.
	 *
	 * @return array<string,mixed> JSON Schema describing accepted arguments.
	 */
	public function get_input_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'slugs' => array(
					'type'        => 'array',
					'items'       => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9][a-z0-9-]*(?:/[a-z0-9._-]+(?:\.php)?)?$',
					),
					'minItems'    => 1,
					'description' => 'Specific plugins to update. Bare slug ("contact-form-7") or WP REST plugin id "folder/file" (`.php` optional). Mutually exclusive with `all`.',
				),
				'all'   => array(
					'type'        => 'boolean',
					'description' => 'When true, update every plugin with an available update. Mutually exclusive with `slugs`.',
				),
			),
		);
	}

	/**
	 * Updates one or more plugins from WordPress.org, verifying each version bump.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		// Hard fail on locked-down sites — same gate PluginInstall uses.
		if ( ! wp_is_file_mod_allowed( 'zipai_update_plugin' ) ) {
			return Response::error( 'Plugin updates are disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter).' );
		}

		// Multisite: plugin updates are a network-admin operation. A
		// sub-site admin's App Password won't have `manage_network_plugins`.
		// Fail loudly + precisely rather than silently no-op'ing every slug.
		if ( is_multisite() && ! is_super_admin() ) {
			return Response::error( 'On multisite, plugin updates require super_admin (network-admin). The connected user lacks that role.' );
		}

		// Discriminate input shape — exactly one of `slugs` / `all` must be set.
		$update_all     = isset( $args['all'] ) && true === $args['all'];
		$explicit_slugs = array();
		if ( isset( $args['slugs'] ) && is_array( $args['slugs'] ) ) {
			foreach ( $args['slugs'] as $s ) {
				if ( is_string( $s ) && '' !== trim( $s ) ) {
					$explicit_slugs[] = trim( $s );
				}
			}
		}

		if ( $update_all && ! empty( $explicit_slugs ) ) {
			return Response::error( 'Pass either `slugs` (specific plugins) or `all: true` — not both.' );
		}
		if ( ! $update_all && empty( $explicit_slugs ) ) {
			return Response::error( 'Pass either `slugs` (array of plugin slugs) or `all: true`.' );
		}

		// Load WP-Admin upgrader stack — not auto-loaded in REST context.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		if ( ! WP_Filesystem() ) {
			return Response::error(
				'Filesystem credentials required for plugin update. '
				. 'Configure FS_METHOD or store FTP credentials in wp-config.php.'
			);
		}

		// Force-refresh the available-updates transient so the upgrader sees
		// the latest versions on WP.org. `wp_update_plugins()` alone early-
		// returns when the last check was inside 12 hours, so a release
		// newer than the last cron check would read as "already at latest".
		// Deleting the transient first bypasses that throttle — same thing
		// update-core.php?force-check=1 does via wp_clean_update_cache().
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$available = get_site_transient( 'update_plugins' );

		// Resolve target plugin files.
		$plugin_files = array();
		$skipped      = array(); // { slug, plugin_file?, reason } — pre-upgrade filter

		if ( $update_all ) {
			$plugin_files = ( is_object( $available ) && ! empty( $available->response ) )
				? array_keys( (array) $available->response )
				: array();
			if ( empty( $plugin_files ) ) {
				return Response::success(
					array(
						'updated'           => array(),
						'failed'            => array(),
						'skipped_no_update' => array(),
						'message'           => 'No plugin updates available — every plugin is already at its latest version.',
					) 
				);
			}
		} else {
			foreach ( $explicit_slugs as $slug ) {
				$plugin_file = PluginResolver::resolve_plugin_file( $slug );
				if ( null === $plugin_file ) {
					$skipped[] = array(
						'slug'   => $slug,
						'reason' => 'not installed',
					);
					continue;
				}
				$has_update = is_object( $available )
					&& isset( $available->response )
					&& isset( ( (array) $available->response )[ $plugin_file ] );
				if ( ! $has_update ) {
					$skipped[] = array(
						'slug'        => $slug,
						'plugin_file' => $plugin_file,
						'reason'      => 'already at latest version',
					);
					continue;
				}
				$plugin_files[] = $plugin_file;
			}
			if ( empty( $plugin_files ) ) {
				return Response::success(
					array(
						'updated'           => array(),
						'failed'            => array(),
						'skipped_no_update' => $skipped,
						'message'           => 'No updates needed for the requested plugins.',
					) 
				);
			}
		}

		// Snapshot pre-upgrade versions for verify.
		$pre_versions = array();
		foreach ( $plugin_files as $file ) {
			$data                  = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$pre_versions[ $file ] = (string) $data['Version'];
		}

		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );

		/**
		 * Widen bulk_upgrade()'s stubbed `array|false` return: result slots
		 * carry `true|null|WP_Error|array`, and a defensive top-level WP_Error
		 * is guarded below.
		 *
		 * @var array<string,mixed>|WP_Error|false $results
		 */
		$results = $upgrader->bulk_upgrade( $plugin_files );

		// `bulk_upgrade` returns `false` only when the upgrader couldn't
		// even start (filesystem init failed mid-flight, no plugins
		// supplied). Top-level `WP_Error` is the same — surface and bail.
		if ( false === $results || is_wp_error( $results ) ) {
			return Response::error(
				is_wp_error( $results )
					? 'Plugin update did not start: ' . $results->get_error_message()
					: 'Plugin update did not start (Plugin_Upgrader::bulk_upgrade returned false).'
			);
		}

		wp_clean_plugins_cache();

		// Per-slug verify: compare post-upgrade Version against pre.
		// `bulk_upgrade` populates `$results[$file]` with either `true`,
		// `null` (no-op), a `WP_Error`, or the upgrader-internal array.
		// We trust the read-back, not the result-slot type — same lesson
		// as theme-activate (filter silently no-op'd, handler reported
		// success). Authoritative signal: did Version advance?
		$updated = array();
		$failed  = array();

		foreach ( $plugin_files as $file ) {
			$slot   = $results[ $file ] ?? null;
			$pre_v  = $pre_versions[ $file ];
			$post   = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$post_v = (string) $post['Version'];

			if ( $slot instanceof WP_Error ) {
				$failed[] = array(
					'plugin_file' => $file,
					'from'        => $pre_v,
					'reason'      => $slot->get_error_message(),
				);
				continue;
			}
			if ( '' === $post_v ) {
				$failed[] = array(
					'plugin_file' => $file,
					'from'        => $pre_v,
					'reason'      => 'plugin file unreadable after upgrade — possibly half-installed',
				);
				continue;
			}
			if ( $post_v === $pre_v ) {
				// Version didn't advance — silent failure or no-op (the
				// upgrader's per-slot null path).
				$failed[] = array(
					'plugin_file' => $file,
					'from'        => $pre_v,
					'to'          => $post_v,
					'reason'      => 'version did not advance — upgrade silently rejected',
				);
				continue;
			}
			$updated[] = array(
				'plugin_file' => $file,
				'from'        => $pre_v,
				'to'          => $post_v,
			);
		}

		// All-fail → error envelope so the caller can't mark dependent
		// todos done. Same truth-telling rule as PluginInstall +
		// post-delete + core__bulk_run_wp_cli.
		if ( empty( $updated ) && ! empty( $failed ) ) {
			$summaries = array_map(
				static fn ( $f ) => $f['plugin_file'] . ' (' . $f['reason'] . ')',
				$failed
			);
			return Response::error(
				sprintf(
					'No plugins updated. Failures: %s.',
					implode( '; ', $summaries )
				) 
			);
		}

		return Response::success(
			array(
				'updated'           => $updated,
				'failed'            => $failed,
				'skipped_no_update' => $skipped,
				'message'           => sprintf(
					'%d plugin(s) updated%s%s.',
					count( $updated ),
					! empty( $failed ) ? ', ' . count( $failed ) . ' failed' : '',
					! empty( $skipped ) ? ', ' . count( $skipped ) . ' skipped (already current or not installed)' : ''
				),
			) 
		);
	}

	/**
	 * Returns the JSON Schema for this ability's response.
	 *
	 * @return array<string,mixed> JSON Schema describing the response shape.
	 */
	public function get_output_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'success' ),
			'additionalProperties' => true,
			'properties'           => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'data'    => array(
					'type'       => 'object',
					'properties' => array(
						'updated'           => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'plugin_file' => array( 'type' => 'string' ),
									'from'        => array( 'type' => 'string' ),
									'to'          => array( 'type' => 'string' ),
								),
							),
						),
						'failed'            => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'plugin_file' => array( 'type' => 'string' ),
									'from'        => array( 'type' => 'string' ),
									'to'          => array( 'type' => 'string' ),
									'reason'      => array( 'type' => 'string' ),
								),
							),
						),
						'skipped_no_update' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'        => array( 'type' => 'string' ),
									'plugin_file' => array( 'type' => 'string' ),
									'reason'      => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
			),
		);
	}
}
