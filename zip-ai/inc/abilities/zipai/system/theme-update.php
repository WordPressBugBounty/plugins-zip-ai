<?php
/**
 * Theme Update. The server runs this.
 *
 * This ability updates one or more themes from WordPress.org. It accepts a
 * list of stylesheet slugs. It also accepts `all: true` to update every theme
 * that has an available update. It runs in-process as the App Password user.
 * It uses WP core `Theme_Upgrader::bulk_upgrade()`.
 *
 * The compliance checks match PluginUpdate. There are five checks.
 *   1. It checks the user capability (`update_themes`) through the App Password.
 *   2. It uses standard `Theme_Upgrader` code. It uses ZIP files from WordPress.org.
 *   3. It honors `DISALLOW_FILE_MODS` first.
 *   4. It honors a `WP_Filesystem` init failure on FTP-mode hosts. It returns a
 *      clear error the user can act on.
 *   5. It requires a super-admin on multisite. A sub-site admin App Password gets
 *      a clear error. It does not do a silent partial update.
 *
 * `bulk_upgrade()` does not throw when one slug fails. It returns `null` or a
 * `WP_Error` in that theme's result slot. So this ability compares the theme
 * version before and after the update, per slug. This catches a silent no-op.
 * A no-op is when the upgrader reports success but the version did not change.
 *
 * Updating the ACTIVE theme is safe — the upgrader replaces files in place and
 * never switches themes, so the active theme stays active.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

use Theme_Upgrader;
use WP_Ajax_Upgrader_Skin;
use WP_Error;
use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ThemeUpdate extends Abstract_Ability {

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
		$this->id          = 'zipai/update-theme';
		$this->label       = 'Update Theme';
		$this->description = 'Update one or more themes from WordPress.org. Pass `slugs: [..]` for specific themes (the stylesheet/directory slug, e.g. "astra") or `all: true` to update every theme with an available update. '
			. 'Runs in-process under the App-Password user\'s identity via `Theme_Upgrader::bulk_upgrade()`. '
			. 'Requires `update_themes` capability (network-admin / super_admin on multisite). '
			. 'Updating the active theme keeps it active — files are replaced in place, no theme switch happens. '
			. 'Synchronous: response carries the actual per-theme from→to version transitions. '
			. 'Verifies each upgrade by reading back the theme\'s Version header — catches silent failures where bulk_upgrade returns null for a slot but doesn\'t throw.';
		$this->capability  = 'update_themes';

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
						// Mixed case + dots allowed: theme directories aren't
						// required to be lowercase (Divi, Avada) and wp_get_theme()
						// matches the directory name exactly (case-sensitive on
						// Linux). The value only ever feeds wp_get_theme(), never
						// a filesystem path built by this ability.
						'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]*$',
					),
					'minItems'    => 1,
					'description' => 'Specific themes to update, by stylesheet slug (the theme directory name, e.g. "astra", "twentytwentyfour"). Mutually exclusive with `all`.',
				),
				'all'   => array(
					'type'        => 'boolean',
					'description' => 'When true, update every theme with an available update. Mutually exclusive with `slugs`.',
				),
			),
		);
	}

	/**
	 * Updates one or more themes from WordPress.org, verifying each version bump.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		// Hard fail on locked-down sites — same gate PluginUpdate uses.
		if ( ! wp_is_file_mod_allowed( 'zipai_update_theme' ) ) {
			return Response::error( 'Theme updates are disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter).' );
		}

		// Multisite: theme updates are a network-admin operation. A
		// sub-site admin's App Password won't have `update_themes` network-wide.
		// Fail loudly + precisely rather than silently no-op'ing every slug.
		if ( is_multisite() && ! is_super_admin() ) {
			return Response::error( 'On multisite, theme updates require super_admin (network-admin). The connected user lacks that role.' );
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
			return Response::error( 'Pass either `slugs` (specific themes) or `all: true` — not both.' );
		}
		if ( ! $update_all && empty( $explicit_slugs ) ) {
			return Response::error( 'Pass either `slugs` (array of theme stylesheet slugs) or `all: true`.' );
		}

		// Load WP-Admin upgrader stack — not auto-loaded in REST context.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		if ( ! WP_Filesystem() ) {
			return Response::error(
				'Filesystem credentials required for theme update. '
				. 'Configure FS_METHOD or store FTP credentials in wp-config.php.'
			);
		}

		// Force-refresh the available-updates transient so the upgrader sees
		// the latest versions on WP.org. `wp_update_themes()` alone early-
		// returns when the last check was inside 12 hours, so a release
		// newer than the last cron check would read as "already at latest".
		// Deleting the transient first bypasses that throttle — same thing
		// update-core.php?force-check=1 does via wp_clean_update_cache().
		delete_site_transient( 'update_themes' );
		wp_update_themes();
		$available = get_site_transient( 'update_themes' );

		// Resolve target stylesheets. Unlike plugins there is no file-path
		// indirection — the stylesheet slug IS the identifier everywhere.
		$stylesheets = array();
		$skipped     = array(); // { slug, reason } — pre-upgrade filter

		if ( $update_all ) {
			$stylesheets = ( is_object( $available ) && ! empty( $available->response ) )
				? array_keys( (array) $available->response )
				: array();
			if ( empty( $stylesheets ) ) {
				return Response::success(
					array(
						'updated'           => array(),
						'failed'            => array(),
						'skipped_no_update' => array(),
						'message'           => 'No theme updates available — every theme is already at its latest version.',
					)
				);
			}
		} else {
			foreach ( $explicit_slugs as $slug ) {
				if ( ! wp_get_theme( $slug )->exists() ) {
					$skipped[] = array(
						'slug'   => $slug,
						'reason' => 'not installed',
					);
					continue;
				}
				$has_update = is_object( $available )
					&& isset( $available->response )
					&& isset( ( (array) $available->response )[ $slug ] );
				if ( ! $has_update ) {
					$skipped[] = array(
						'slug'   => $slug,
						'reason' => 'already at latest version',
					);
					continue;
				}
				$stylesheets[] = $slug;
			}
			if ( empty( $stylesheets ) ) {
				// Don't let "not installed" masquerade as "already current" —
				// wp_get_theme() is an exact directory match, so a wrong slug
				// ("astra-child", a typo) lands here, not at latest version.
				$not_installed = array_column(
					array_filter( $skipped, static fn ( $s ) => 'not installed' === $s['reason'] ),
					'slug'
				);
				if ( count( $not_installed ) === count( $skipped ) ) {
					return Response::error(
						sprintf(
							'No such theme(s) installed: %s. Pass the theme directory slug exactly as it appears on disk (e.g. "astra").',
							implode( ', ', $not_installed )
						)
					);
				}
				return Response::success(
					array(
						'updated'           => array(),
						'failed'            => array(),
						'skipped_no_update' => $skipped,
						'message'           => empty( $not_installed )
							? 'No updates needed for the requested themes.'
							: sprintf(
								'No updates needed for the installed themes; not installed: %s.',
								implode( ', ', $not_installed )
							),
					)
				);
			}
		}

		// Snapshot pre-upgrade versions for verify.
		$pre_versions = array();
		foreach ( $stylesheets as $stylesheet ) {
			$pre_versions[ $stylesheet ] = (string) wp_get_theme( $stylesheet )->get( 'Version' );
		}

		$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );

		/**
		 * Widen bulk_upgrade()'s stubbed `array[]|false` return: result slots
		 * carry `true|null|WP_Error|array`, and a defensive top-level WP_Error
		 * is guarded below.
		 *
		 * @var array<string,mixed>|WP_Error|false $results
		 */
		$results = $upgrader->bulk_upgrade( $stylesheets );

		// `bulk_upgrade` returns `false` only when the upgrader couldn't
		// even start (filesystem init failed mid-flight, no themes
		// supplied). Top-level `WP_Error` is the same — surface and bail.
		if ( false === $results || is_wp_error( $results ) ) {
			return Response::error(
				is_wp_error( $results )
					? 'Theme update did not start: ' . $results->get_error_message()
					: 'Theme update did not start (Theme_Upgrader::bulk_upgrade returned false).'
			);
		}

		// Drop the stale WP_Theme cache so the read-back below sees the new
		// Version header, not the pre-upgrade cached one.
		wp_clean_themes_cache();

		// Per-slug verify: compare post-upgrade Version against pre.
		// `bulk_upgrade` populates `$results[$stylesheet]` with either `true`,
		// `null` (no-op), a `WP_Error`, or the upgrader-internal array.
		// We trust the read-back, not the result-slot type — same lesson
		// as PluginUpdate. Authoritative signal: did Version advance?
		$updated = array();
		$failed  = array();

		foreach ( $stylesheets as $stylesheet ) {
			$slot   = $results[ $stylesheet ] ?? null;
			$pre_v  = $pre_versions[ $stylesheet ];
			$post   = wp_get_theme( $stylesheet );
			$post_v = $post->exists() ? (string) $post->get( 'Version' ) : '';

			if ( $slot instanceof WP_Error ) {
				$failed[] = array(
					'slug'   => $stylesheet,
					'from'   => $pre_v,
					'reason' => $slot->get_error_message(),
				);
				continue;
			}
			if ( '' === $post_v ) {
				$failed[] = array(
					'slug'   => $stylesheet,
					'from'   => $pre_v,
					'reason' => 'theme unreadable after upgrade — possibly half-installed',
				);
				continue;
			}
			if ( $post_v === $pre_v ) {
				// Version didn't advance — silent failure or no-op (the
				// upgrader's per-slot null path).
				$failed[] = array(
					'slug'   => $stylesheet,
					'from'   => $pre_v,
					'to'     => $post_v,
					'reason' => 'version did not advance — upgrade silently rejected',
				);
				continue;
			}
			$updated[] = array(
				'slug' => $stylesheet,
				'from' => $pre_v,
				'to'   => $post_v,
			);
		}

		// All-fail → error envelope so the caller can't mark dependent
		// todos done. Same truth-telling rule as PluginUpdate.
		if ( empty( $updated ) && ! empty( $failed ) ) {
			$summaries = array_map(
				static fn ( $f ) => $f['slug'] . ' (' . $f['reason'] . ')',
				$failed
			);
			return Response::error(
				sprintf(
					'No themes updated. Failures: %s.',
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
					'%d theme(s) updated%s%s.',
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
									'slug' => array( 'type' => 'string' ),
									'from' => array( 'type' => 'string' ),
									'to'   => array( 'type' => 'string' ),
								),
							),
						),
						'failed'            => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'   => array( 'type' => 'string' ),
									'from'   => array( 'type' => 'string' ),
									'to'     => array( 'type' => 'string' ),
									'reason' => array( 'type' => 'string' ),
								),
							),
						),
						'skipped_no_update' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'   => array( 'type' => 'string' ),
									'reason' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
			),
		);
	}
}
