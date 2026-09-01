<?php
/**
 * Snippet Versions — revision history for ZIP AI code snippets.
 *
 * Storage layout:
 *   wp-content/zip-ai-snippets/{slug}/.versions/index.json
 *   wp-content/zip-ai-snippets/{slug}/.versions/{id}/snippet.{php,js,css}
 *   wp-content/zip-ai-snippets/{slug}/.versions/{id}/meta.json
 *
 * Versions are append-only (newest first in index.json). Manual snapshots are
 * kept forever; auto-snapshots are GC'd to the last N entries (default 50).
 *
 * The version_id is sortable by creation: "{unix_ts}-{6 hex}" — no DB needed.
 * The user-visible label "v1, v2…" is derived from index position so it never
 * drifts when GC removes old auto-versions.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Snippet_Versions {

	/**
	 * Default retention: last N auto-versions kept. Manual snapshots and any
	 * versions inside the retention-day window are always preserved.
	 */
	private const DEFAULT_MAX_AUTO = 50;

	/**
	 * Default retention window in days. null = no day-based cap.
	 */
	private const DEFAULT_RETENTION_DAYS = null;

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Snapshot HEAD files into a new version.
	 *
	 * @param string              $slug    Validated snippet slug.
	 * @param array<string,mixed> $context Author/reason metadata. Keys:
	 *                         - author_type: user|agent|system   (required)
	 *                         - author_id:   int|null
	 *                         - author_name: string
	 *                         - reason:      string|null
	 *                         - manual:      bool                (default false).
	 *
	 * @return array<string,mixed>|null Created version index entry, or null on failure.
	 */
	public static function create( $slug, array $context = array() ) {
		$slug = Snippet_Store::validate_slug( $slug );
		if ( ! $slug ) {
			return null;
		}

		self::ensure_versions_dir( $slug );

		$id  = self::generate_id();
		$dir = self::version_dir( $slug, $id );
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		// Version files are byte copies of HEAD, so each version dir needs the
		// same deny files as the snippet dir — Apache, IIS and dir-listing.
		Snippet_Store::write_dir_protection( $dir );

		// Copy HEAD files into the version directory.
		$hashes = array();
		foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
			$src = Snippet_Store::snippet_file( $slug, $type );
			if ( file_exists( $src ) && Snippet_Store::is_safe_path( $src ) ) {
				$dst = $dir . '/snippet.' . $type;
				copy( $src, $dst ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$hash = hash_file( 'sha256', $dst );
				if ( false !== $hash ) {
					$hashes[ $type ] = $hash;
				}
			}
		}

		$index  = self::read_index( $slug );
		$parent = ( ! empty( $index ) && is_string( $index[0]['id'] ?? null ) ) ? $index[0]['id'] : null;

		$summary      = self::diff_summary( $slug, $parent, $hashes, $dir );
		$manifest     = Snippet_Store::load();
		$snippet_meta = array();
		if ( isset( $manifest[ $slug ] ) && is_array( $manifest[ $slug ] ) ) {
			/**
			 * Narrowed type for `$manifest_entry`.
			 *
			 * @var array<string,mixed> $manifest_entry
			 */
			$manifest_entry = $manifest[ $slug ];
			$snippet_meta   = self::snapshot_meta( $manifest_entry );
		}

		$author_type = $context['author_type'] ?? 'user';
		$author_name = $context['author_name'] ?? '';

		$entry = array(
			'id'           => $id,
			'parent_id'    => $parent,
			'author_type'  => self::sanitize_author_type( is_string( $author_type ) ? $author_type : 'user' ),
			'author_id'    => isset( $context['author_id'] ) && is_scalar( $context['author_id'] ) ? intval( $context['author_id'] ) : null,
			'author_name'  => sanitize_text_field( is_string( $author_name ) ? $author_name : '' ),
			'reason'       => isset( $context['reason'] ) && is_string( $context['reason'] ) ? sanitize_text_field( $context['reason'] ) : null,
			'manual'       => ! empty( $context['manual'] ),
			'created_at'   => gmdate( 'c' ),
			'hash'         => $hashes,
			'snippet_meta' => $snippet_meta,
			'diff_summary' => $summary,
		);

		// Write meta.json (full record per-version) and prepend to index.
		Snippet_Store::put_file( $dir . '/meta.json', (string) wp_json_encode( $entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

		array_unshift( $index, self::index_entry( $entry ) );
		self::write_index( $slug, $index );
		self::update_manifest_pointer( $slug, $id, count( $index ) );

		// GC old auto versions after each create.
		self::gc( $slug );

		return $entry;
	}

	/**
	 * List versions for a snippet (newest first). Index entries only — no code.
	 *
	 * @param string $slug Validated snippet slug.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_versions( $slug ) {
		$slug = Snippet_Store::validate_slug( $slug );
		if ( ! $slug ) {
			return array();
		}

		$index   = self::read_index( $slug );
		$current = self::current_id( $slug );
		$total   = count( $index );

		// Decorate index with derived label (vN — newest = highest) and current flag.
		$out = array();
		foreach ( $index as $i => $entry ) {
			$entry['label']   = 'v' . ( $total - $i );
			$entry['current'] = ( $entry['id'] === $current );
			$out[]            = $entry;
		}
		return $out;
	}

	/**
	 * Resolve a user-facing label (`vN`) or short alias to a real version id.
	 *
	 * Callers (the agent, the React UI) sometimes pass the label they see
	 * (`v3`) instead of the underlying ULID-style id. Resolve here so every
	 * version action accepts both forms transparently.
	 *
	 * @param string $slug             Validated snippet slug.
	 * @param string $version_or_label Either a raw id or `vN` label.
	 * @return string|null Real id if resolved, null if not found.
	 */
	public static function resolve_id( $slug, $version_or_label ) {
		$slug = Snippet_Store::validate_slug( $slug );
		if ( ! $slug || '' === $version_or_label ) {
			return null;
		}

		$index = self::read_index( $slug );
		$total = count( $index );

		// Direct id match.
		foreach ( $index as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $version_or_label ) {
				return $entry['id'];
			}
		}

		// Label match (`vN` — derived from index position, newest first).
		if ( preg_match( '/^v(\d+)$/i', (string) $version_or_label, $m ) ) {
			$n = (int) $m[1];
			if ( $n >= 1 && $n <= $total ) {
				$idx = $total - $n;
				$id  = $index[ $idx ]['id'] ?? null;
				return is_string( $id ) ? $id : null;
			}
		}

		return null;
	}

	/**
	 * Read full version (meta + file contents).
	 *
	 * Accepts either a raw id or a `vN` label.
	 *
	 * @param string $slug       Validated snippet slug.
	 * @param string $version_id Version id or `vN` label.
	 * @return array<string,mixed>|\WP_Error Result {meta, files} on success, WP_Error
	 *                         with diagnostic on failure.
	 */
	public static function get( $slug, $version_id ) {
		$valid_slug = Snippet_Store::validate_slug( $slug );
		if ( ! $valid_slug ) {
			return new \WP_Error( 'invalid_slug', sprintf( 'Invalid snippet slug "%s".', (string) $slug ) );
		}
		$slug = $valid_slug;

		// Accept label OR id.
		$requested = $version_id;
		$resolved  = self::resolve_id( $slug, $version_id );
		if ( $resolved ) {
			$version_id = $resolved;
		}

		$dir = self::version_dir( $slug, $version_id );
		if ( ! is_dir( $dir ) || ! Snippet_Store::is_safe_path( $dir ) ) {
			$known = array_map(
				static function ( array $e ) {
					$id = $e['id'] ?? '';
					return is_string( $id ) ? $id : '';
				},
				self::read_index( $slug )
			);
			return new \WP_Error(
				'version_not_found',
				sprintf(
					'Version "%s" not found for snippet "%s". Call list_versions to map labels (vN) to ids. Known ids: %s',
					(string) $requested,
					$slug,
					empty( $known ) ? '(none)' : implode( ', ', $known )
				)
			);
		}

		$meta_path = $dir . '/meta.json';
		if ( ! file_exists( $meta_path ) ) {
			return new \WP_Error(
				'version_meta_missing',
				sprintf( 'Version "%s" exists but its meta.json is missing — manifest may be corrupt.', $version_id )
			);
		}

		$meta  = json_decode( (string) file_get_contents( $meta_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$files = array();
		foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
			$file = $dir . '/snippet.' . $type;
			if ( file_exists( $file ) ) {
				$raw            = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$files[ $type ] = 'php' === $type ? Snippet_Store::unwrap_php( $raw ) : $raw;
			}
		}

		return array(
			'meta'  => is_array( $meta ) ? $meta : array(),
			'files' => $files,
		);
	}

	/**
	 * Compute add/del summary between two versions, or against HEAD.
	 *
	 * @param string      $slug Validated snippet slug.
	 * @param string      $a_id Base version id or `vN` label.
	 * @param string|null $b_id Defaults to current_id.
	 * @return array<string,mixed> { add, del, per_file }
	 */
	public static function diff( $slug, $a_id, $b_id = null ) {
		$slug = Snippet_Store::validate_slug( $slug );
		if ( ! $slug ) {
			return array(
				'add'      => 0,
				'del'      => 0,
				'per_file' => array(),
			);
		}

		// Accept labels (`vN`) for both ids.
		$resolved_a = self::resolve_id( $slug, $a_id );
		$a_id       = $resolved_a ? $resolved_a : $a_id;
		if ( null === $b_id ) {
			$b_id = self::current_id( $slug );
		} else {
			$resolved_b = self::resolve_id( $slug, $b_id );
			$b_id       = $resolved_b ? $resolved_b : $b_id;
		}

		$a_files = self::read_version_files( $slug, $a_id );
		$b_files = self::read_version_files( $slug, (string) $b_id );
		$types   = array_unique( array_merge( array_keys( $a_files ), array_keys( $b_files ) ) );

		$total_add = 0;
		$total_del = 0;
		$per_file  = array();

		foreach ( $types as $type ) {
			$old               = isset( $a_files[ $type ] ) ? explode( "\n", $a_files[ $type ] ) : array();
			$new               = isset( $b_files[ $type ] ) ? explode( "\n", $b_files[ $type ] ) : array();
			$add               = count( array_diff( $new, $old ) );
			$del               = count( array_diff( $old, $new ) );
			$per_file[ $type ] = array(
				'add' => $add,
				'del' => $del,
			);
			$total_add        += $add;
			$total_del        += $del;
		}

		return array(
			'add'      => $total_add,
			'del'      => $total_del,
			'per_file' => $per_file,
		);
	}

	/**
	 * Restore an older version. Creates a safety snapshot of HEAD first
	 * (so no work is lost), then copies version files to HEAD, then records
	 * a new manual version capturing the restore.
	 *
	 * Accepts either a raw version id or a `vN` label.
	 *
	 * @param string              $slug       Validated snippet slug.
	 * @param string              $version_id Version id or `vN` label to restore.
	 * @param array<string,mixed> $context    Author/reason metadata (see create()).
	 * @return array<string,mixed>|\WP_Error|null New restore-version entry, WP_Error with diagnostic, or null on failure.
	 */
	public static function restore( $slug, $version_id, array $context = array() ) {
		$valid_slug = Snippet_Store::validate_slug( $slug );
		if ( ! $valid_slug ) {
			return new \WP_Error( 'invalid_slug', sprintf( 'Invalid snippet slug "%s".', (string) $slug ) );
		}
		$slug = $valid_slug;

		// Resolve label (`vN`) → real id. If we can't resolve, surface the list
		// of available ids so the caller (or the agent) can self-correct.
		$requested = $version_id;
		$resolved  = self::resolve_id( $slug, $version_id );
		if ( $resolved ) {
			$version_id = $resolved;
		}

		$src = self::version_dir( $slug, $version_id );
		if ( ! is_dir( $src ) ) {
			$known = array_map(
				static function ( array $e ) {
					$id = $e['id'] ?? '';
					return is_string( $id ) ? $id : '';
				},
				self::read_index( $slug )
			);
			return new \WP_Error(
				'version_not_found',
				sprintf(
					'Version "%s" not found for snippet "%s". Call list_versions first to map the user-facing label (vN) to the real id. Known ids: %s',
					(string) $requested,
					$slug,
					empty( $known ) ? '(none)' : implode( ', ', $known )
				)
			);
		}

		// Snapshot HEAD first if it differs from current pointer — guarantees
		// the user's working code is preserved as an auto-version before we
		// overwrite HEAD.
		$current = self::current_id( $slug );
		if ( $current && ! self::head_matches_version( $slug, $current ) ) {
			self::create(
				$slug,
				array(
					'author_type' => $context['author_type'] ?? 'user',
					'author_id'   => $context['author_id'] ?? null,
					'author_name' => $context['author_name'] ?? '',
					'reason'      => 'Auto-snapshot before restore',
					'manual'      => false,
				) 
			);
		}

		// Sync version files back to HEAD.
		$snippet_dir = Snippet_Store::snippet_dir( $slug );
		foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
			$from = $src . '/snippet.' . $type;
			$to   = $snippet_dir . '/snippet.' . $type;
			if ( file_exists( $from ) && Snippet_Store::is_safe_path( $to ) ) {
				copy( $from, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				Snippet_Store::invalidate_opcache( $to );
			} elseif ( file_exists( $to ) && Snippet_Store::is_safe_path( $to ) ) {
				wp_delete_file( $to );
				Snippet_Store::invalidate_opcache( $to );
			}
		}

		// Update manifest hashes from the new HEAD and restore versioned metadata
		// when available. Older versions created before metadata snapshots only
		// restore files, then keep the current manifest metadata.
		$manifest = Snippet_Store::load();
		if ( isset( $manifest[ $slug ] ) && is_array( $manifest[ $slug ] ) ) {
			$version_meta = self::read_version_meta( $slug, $version_id );
			if ( ! empty( $version_meta['snippet_meta'] ) && is_array( $version_meta['snippet_meta'] ) ) {
				foreach ( $version_meta['snippet_meta'] as $key => $value ) {
					$manifest[ $slug ][ $key ] = $value;
				}
			}

			$files                            = array();
			$manifest[ $slug ]['file_hashes'] = array();
			foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
				$file = $snippet_dir . '/snippet.' . $type;
				if ( file_exists( $file ) ) {
					$files[]                                   = $type;
					$manifest[ $slug ]['file_hashes'][ $type ] = hash_file( 'sha256', $file );
				}
			}
			$manifest[ $slug ]['files'] = $files;
			Snippet_Store::save( $manifest );
		}

		// Find label of the version being restored for the reason text.
		$list  = self::list_versions( $slug );
		$label = '';
		foreach ( $list as $v ) {
			if ( $v['id'] === $version_id ) {
				$label = is_string( $v['label'] ?? null ) ? $v['label'] : '';
				break;
			}
		}

		// Record the restore as a new manual version.
		return self::create(
			$slug,
			array(
				'author_type' => $context['author_type'] ?? 'user',
				'author_id'   => $context['author_id'] ?? null,
				'author_name' => $context['author_name'] ?? '',
				'reason'      => sprintf( 'Restored from %s', $label ? $label : $version_id ),
				'manual'      => true,
			) 
		);
	}

	/**
	 * Delete a manual version. Auto-versions are not user-deletable.
	 *
	 * @param string $slug       Validated snippet slug.
	 * @param string $version_id Version id or `vN` label to delete.
	 * @return bool|\WP_Error
	 */
	public static function delete( $slug, $version_id ) {
		$valid_slug = Snippet_Store::validate_slug( $slug );
		if ( ! $valid_slug ) {
			return new \WP_Error( 'invalid_slug', sprintf( 'Invalid snippet slug "%s".', (string) $slug ) );
		}
		$slug = $valid_slug;

		// Accept label (`vN`) OR raw id.
		$requested = $version_id;
		$resolved  = self::resolve_id( $slug, $version_id );
		if ( $resolved ) {
			$version_id = $resolved;
		}

		$index = self::read_index( $slug );
		$entry = null;
		foreach ( $index as $e ) {
			if ( $e['id'] === $version_id ) {
				$entry = $e;
				break;
			}
		}
		if ( ! $entry ) {
			$known = array_map(
				static function ( array $e ) {
					$id = $e['id'] ?? '';
					return is_string( $id ) ? $id : '';
				},
				$index
			);
			return new \WP_Error(
				'version_not_found',
				sprintf( 'Version "%s" not found. Known ids: %s', (string) $requested, empty( $known ) ? '(none)' : implode( ', ', $known ) )
			);
		}

		// The only hard constraint: cannot delete the version HEAD currently
		// points to (would orphan the snippet's "now" state). Auto-versions
		// are otherwise user-deletable — same surface as manual snapshots.
		if ( self::current_id( $slug ) === $version_id ) {
			return new \WP_Error(
				'version_is_current',
				'Cannot delete the version that HEAD currently points to. Restore a different version first, then delete this one.'
			);
		}

		self::remove_version_dir( $slug, $version_id );
		$index = array_values(
			array_filter(
				$index,
				static function ( $e ) use ( $version_id ) {
					return $e['id'] !== $version_id;
				}
			) 
		);
		self::write_index( $slug, $index );
		self::update_manifest_pointer( $slug, self::current_id( $slug ), count( $index ) );

		return true;
	}

	/**
	 * Garbage-collect old auto versions.
	 *
	 * Keeps: all manual versions, all versions inside the retention-day window,
	 * and the latest N auto versions (default 50). Filters:
	 *   zip_ai_snippets_max_versions          (int)
	 *   zip_ai_snippets_version_retention_days (int|null)
	 *
	 * @param string $slug Validated snippet slug.
	 * @return void
	 */
	public static function gc( $slug ) {
		$slug = Snippet_Store::validate_slug( $slug );
		if ( ! $slug ) {
			return;
		}

		$max_filter     = apply_filters( 'zip_ai_snippets_max_versions', self::DEFAULT_MAX_AUTO );
		$max_auto       = max( 1, is_scalar( $max_filter ) ? intval( $max_filter ) : self::DEFAULT_MAX_AUTO );
		$retention_days = apply_filters( 'zip_ai_snippets_version_retention_days', self::DEFAULT_RETENTION_DAYS );
		$cutoff         = ( ! is_scalar( $retention_days ) ) ? null : ( time() - ( intval( $retention_days ) * DAY_IN_SECONDS ) );
		$current        = self::current_id( $slug );

		$index     = self::read_index( $slug );
		$auto_seen = 0;
		$keep      = array();
		$removed   = false;

		foreach ( $index as $entry ) {
			$is_manual  = ! empty( $entry['manual'] );
			$ts         = is_string( $entry['created_at'] ?? null ) ? strtotime( $entry['created_at'] ) : 0;
			$within     = ( null !== $cutoff && $ts >= $cutoff );
			$is_current = $entry['id'] === $current;

			if ( $is_manual || $within || $is_current ) {
				$keep[] = $entry;
				if ( ! $is_manual ) {
					++$auto_seen;
				}
				continue;
			}

			if ( $auto_seen < $max_auto ) {
				$keep[] = $entry;
				++$auto_seen;
				continue;
			}

			// Drop this auto version.
			$entry_id = $entry['id'] ?? '';
			self::remove_version_dir( $slug, is_string( $entry_id ) ? $entry_id : '' );
			$removed = true;
		}

		if ( $removed ) {
			self::write_index( $slug, $keep );
			self::update_manifest_pointer( $slug, $current, count( $keep ) );
		}
	}

	// ── Internal ──────────────────────────────────────────────────────────

	/**
	 * Read the index.json (newest first). Empty array if missing.
	 *
	 * @param string $slug Validated snippet slug.
	 * @return array<int,array<string,mixed>> Index entries, or empty array.
	 */
	private static function read_index( $slug ) {
		$path = self::index_path( $slug );
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$arr = json_decode( (string) $raw, true );
		if ( ! is_array( $arr ) ) {
			return array();
		}
		/**
		 * Narrowed type for `$arr`.
		 *
		 * @var array<int,array<string,mixed>> $arr
		 */
		return $arr;
	}

	/**
	 * Write the version index.json for a snippet.
	 *
	 * @param string                         $slug  Validated snippet slug.
	 * @param array<int,array<string,mixed>> $index Index entries to persist.
	 * @return void
	 */
	private static function write_index( $slug, array $index ) {
		$path = self::index_path( $slug );
		self::ensure_versions_dir( $slug );
		Snippet_Store::put_file( $path, (string) wp_json_encode( array_values( $index ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Reduce a full version entry to a lean index entry.
	 *
	 * @param array<string,mixed> $entry Full version entry.
	 * @return array<string,mixed> Lean index entry.
	 */
	private static function index_entry( array $entry ) {
		// Keep index entries lean — full meta lives in {id}/meta.json.
		return array(
			'id'           => $entry['id'],
			'parent_id'    => $entry['parent_id'] ?? null,
			'author_type'  => $entry['author_type'] ?? 'user',
			'author_name'  => $entry['author_name'] ?? '',
			'reason'       => $entry['reason'] ?? null,
			'manual'       => ! empty( $entry['manual'] ),
			'created_at'   => $entry['created_at'] ?? gmdate( 'c' ),
			'hash'         => $entry['hash'] ?? array(),
			'diff_summary' => $entry['diff_summary'] ?? array(
				'add'      => 0,
				'del'      => 0,
				'per_file' => array(),
			),
		);
	}

	/**
	 * Capture the snippet metadata to store alongside a version.
	 *
	 * @param array<string,mixed> $meta Snippet manifest entry.
	 * @return array<string,mixed> Snapshot of key metadata fields (title, description, execution, conditions).
	 */
	private static function snapshot_meta( array $meta ) {
		$normalized = Snippet_Store::normalize_snippet( $meta );
		return array(
			'title'       => is_string( $normalized['title'] ?? null ) ? $normalized['title'] : '',
			'description' => is_string( $normalized['description'] ?? null ) ? $normalized['description'] : '',
			'execution'   => is_array( $normalized['execution'] ?? null ) ? $normalized['execution'] : array(),
			'conditions'  => is_array( $normalized['conditions'] ?? null ) ? $normalized['conditions'] : array(),
		);
	}

	/**
	 * Read a version's meta.json.
	 *
	 * @param string $slug       Validated snippet slug.
	 * @param string $version_id Version id.
	 * @return array<string,mixed> Version meta, or empty array if missing.
	 */
	private static function read_version_meta( $slug, $version_id ) {
		$path = self::version_dir( $slug, $version_id ) . '/meta.json';
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$meta = json_decode( (string) $raw, true );
		if ( ! is_array( $meta ) ) {
			return array();
		}
		/**
		 * Narrowed type for `$meta`.
		 *
		 * @var array<string,mixed> $meta
		 */
		return $meta;
	}

	/**
	 * Ensure the .versions dir exists with HTTP-deny protection files.
	 *
	 * @param string $slug Validated snippet slug.
	 * @return void
	 */
	private static function ensure_versions_dir( $slug ) {
		$dir = self::versions_dir( $slug );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Defense in depth: even though the parent zip-ai-snippets/ already
		// denies HTTP access, drop the same guards inside .versions/. Routed
		// through the shared helper so the Apache, IIS and dir-listing denies
		// stay in one place instead of drifting per directory.
		Snippet_Store::write_dir_protection( $dir );
	}

	/**
	 * Absolute path to a snippet's .versions directory.
	 *
	 * @param string $slug Validated snippet slug.
	 * @return string
	 */
	private static function versions_dir( $slug ) {
		return Snippet_Store::snippet_dir( $slug ) . '/.versions';
	}

	/**
	 * Absolute path to a snippet's version index.json.
	 *
	 * @param string $slug Validated snippet slug.
	 * @return string
	 */
	private static function index_path( $slug ) {
		return self::versions_dir( $slug ) . '/index.json';
	}

	/**
	 * Absolute path to a single version directory (path-traversal safe).
	 *
	 * @param string $slug Validated snippet slug.
	 * @param string $id   Version id.
	 * @return string
	 */
	private static function version_dir( $slug, $id ) {
		// Validate id format defensively to keep path traversal impossible.
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', (string) $id ) ) {
			return self::versions_dir( $slug ) . '/__invalid__';
		}
		return self::versions_dir( $slug ) . '/' . $id;
	}

	/**
	 * Generate a sortable version id ("{unix_ts}-{6 hex}").
	 *
	 * @return string
	 */
	private static function generate_id() {
		return time() . '-' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 );
	}

	/**
	 * Clamp an author type to the allowed set (user, agent, system).
	 *
	 * @param string $type Raw author type.
	 * @return string
	 */
	private static function sanitize_author_type( $type ) {
		$valid = array( 'user', 'agent', 'system' );
		return in_array( $type, $valid, true ) ? $type : 'user';
	}

	/**
	 * Read a version's snippet file contents keyed by type.
	 *
	 * @param string $slug Validated snippet slug.
	 * @param string $id   Version id.
	 * @return array<string,string> File contents keyed by file type.
	 */
	private static function read_version_files( $slug, $id ) {
		$dir = self::version_dir( $slug, $id );
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
			$file = $dir . '/snippet.' . $type;
			if ( file_exists( $file ) ) {
				$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false !== $content ) {
					$out[ $type ] = 'php' === $type ? Snippet_Store::unwrap_php( $content ) : $content;
				}
			}
		}
		return $out;
	}

	/**
	 * Compute diff summary against the parent version (just-written files vs
	 * the previous version's files). Set-based add/del — not git-accurate but
	 * fast and sufficient for the +N -N badge.
	 *
	 * @param string               $slug       Validated snippet slug.
	 * @param string|null          $parent_id  Parent version id, or null if none.
	 * @param array<string,string> $new_hashes SHA-256 hashes of the new files, keyed by type.
	 * @param string               $new_dir    Directory holding the new version's files.
	 * @return array<string,mixed> Diff summary ({ add, del, per_file }).
	 */
	private static function diff_summary( $slug, $parent_id, array $new_hashes, $new_dir ) {
		$summary = array(
			'add'      => 0,
			'del'      => 0,
			'per_file' => array(),
		);

		$old_files = $parent_id ? self::read_version_files( $slug, $parent_id ) : array();
		$new_files = array();
		foreach ( $new_hashes as $type => $_hash ) {
			$file = $new_dir . '/snippet.' . $type;
			if ( file_exists( $file ) ) {
				$raw                = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$new_files[ $type ] = 'php' === $type ? Snippet_Store::unwrap_php( $raw ) : $raw;
			}
		}

		$types = array_unique( array_merge( array_keys( $old_files ), array_keys( $new_files ) ) );
		foreach ( $types as $type ) {
			$old                          = isset( $old_files[ $type ] ) ? explode( "\n", $old_files[ $type ] ) : array();
			$new                          = isset( $new_files[ $type ] ) ? explode( "\n", $new_files[ $type ] ) : array();
			$add                          = count( array_diff( $new, $old ) );
			$del                          = count( array_diff( $old, $new ) );
			$summary['per_file'][ $type ] = array(
				'add' => $add,
				'del' => $del,
			);
			$summary['add']              += $add;
			$summary['del']              += $del;
		}

		return $summary;
	}

	/**
	 * The manifest's current HEAD version id for a snippet.
	 *
	 * @param string $slug Validated snippet slug.
	 * @return string|null
	 */
	private static function current_id( $slug ) {
		$manifest = Snippet_Store::load();
		$entry    = $manifest[ $slug ] ?? null;
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$id = $entry['current_version_id'] ?? null;
		return is_string( $id ) ? $id : null;
	}

	/**
	 * Whether HEAD files hash-match a given version's files.
	 *
	 * @param string $slug       Validated snippet slug.
	 * @param string $version_id Version id to compare against.
	 * @return bool
	 */
	private static function head_matches_version( $slug, $version_id ) {
		$head_files  = array();
		$snippet_dir = Snippet_Store::snippet_dir( $slug );
		foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
			$file = $snippet_dir . '/snippet.' . $type;
			if ( file_exists( $file ) ) {
				$head_files[ $type ] = hash_file( 'sha256', $file );
			}
		}

		$ver_files = array();
		$ver_dir   = self::version_dir( $slug, $version_id );
		foreach ( Snippet_Store::ALLOWED_TYPES as $type ) {
			$file = $ver_dir . '/snippet.' . $type;
			if ( file_exists( $file ) ) {
				$ver_files[ $type ] = hash_file( 'sha256', $file );
			}
		}

		ksort( $head_files );
		ksort( $ver_files );
		return $head_files === $ver_files;
	}

	/**
	 * Update the manifest's version pointer, count, and timestamp.
	 *
	 * @param string      $slug       Validated snippet slug.
	 * @param string|null $version_id New current version id.
	 * @param int         $count      Total version count.
	 * @return void
	 */
	private static function update_manifest_pointer( $slug, $version_id, $count ) {
		$manifest = Snippet_Store::load();
		if ( ! isset( $manifest[ $slug ] ) || ! is_array( $manifest[ $slug ] ) ) {
			return;
		}
		$manifest[ $slug ]['current_version_id'] = $version_id;
		$manifest[ $slug ]['versions_count']     = (int) $count;
		$manifest[ $slug ]['last_version_at']    = gmdate( 'c' );
		Snippet_Store::save( $manifest );
	}

	/**
	 * Delete a single version directory and its files.
	 *
	 * @param string $slug Validated snippet slug.
	 * @param string $id   Version id.
	 * @return void
	 */
	private static function remove_version_dir( $slug, $id ) {
		$dir = self::version_dir( $slug, $id );
		if ( ! is_dir( $dir ) || ! Snippet_Store::is_safe_path( $dir ) ) {
			return;
		}
		// scandir + filter is portable; glob(GLOB_BRACE) is GNU-only and silently no-ops on some hosts.
		$entries = @scandir( $dir );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $dir . '/' . $entry;
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
		Snippet_Store::delete_dir( $dir );
	}
}
