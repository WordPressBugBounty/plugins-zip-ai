<?php
/**
 * Search-Replace Engine Trait — text replacement across WordPress tables.
 * It is aware of serialized PHP.
 *
 * The code was moved out of RunWpCli to keep that class small. This trait
 * is private to the RunWpCli ability. It depends only on $wpdb, WordPress
 * core (`is_serialized`) and the standard Response helper. It does not
 * depend on RunWpCli state.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Core;

defined( 'ABSPATH' ) || exit;

use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Security\Protected_Options_Filter;

/**
 * Trait for `wp search-replace` execution.
 *
 * It walks every text column of every selected table. It deserializes a
 * payload when one is present. This keeps the length prefix consistent. A
 * naïve UPDATE … REPLACE() would corrupt that prefix. It always skips
 * `guid`, `user_pass` and `option_name`. It never walks the user, usermeta
 * or sitemeta tables. It never walks protected `wp_options` rows.
 */
trait Search_Replace_Engine_Trait {

	/**
	 * Handle "search-replace <old> <new> [--all-tables] [--skip-columns=…] [--dry-run]".
	 *
	 * It is aware of serialized PHP. When a cell holds a serialized structure,
	 * the code deserializes it. It replaces the value recursively. It then
	 * reserializes it. So it does not corrupt the length prefix. A naïve
	 * UPDATE ... REPLACE() would break that prefix.
	 *
	 * The code ALWAYS skips the `guid`, `user_pass` and `option_name` columns.
	 * `guid` must not change after publish. It identifies the post in RSS
	 * readers. `user_pass` is a bcrypt hash. A string replace would break
	 * logins. `option_name` is the identity of the option.
	 *
	 * Without `--all-tables`, the code walks only core WP tables
	 * (`$wpdb->tables()`). With it, the code walks every table with the wpdb
	 * prefix. Either way it excludes the user, usermeta and sitemeta tables.
	 * It also filters out protected `wp_options` rows. See the notes at each
	 * site.
	 *
	 * @param string[]                  $positional Remaining positional args ([0]=old, [1]=new).
	 * @param array<string,string|bool> $flags      Parsed flags.
	 * @return array<string,mixed>
	 */
	private function handle_search_replace( array $positional, array $flags ): array {
		global $wpdb;
		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		$old = $positional[0] ?? null;
		$new = $positional[1] ?? null;

		if ( null === $old || '' === $old ) {
			return Response::error(
				'Usage: search-replace "<old>" "<new>" [--all-tables] [--skip-columns=col1,col2] [--dry-run]'
			);
		}
		if ( null === $new ) {
			return Response::error( 'Replacement string missing. Use "" to delete occurrences.' );
		}

		// Hard cap. This is defensive. A 5 KB needle has no real site use.
		if ( strlen( $old ) > 5000 || strlen( (string) $new ) > 5000 ) {
			return Response::error( 'Search or replace string is larger than 5 KB. Run smaller substitutions.' );
		}

		// Refuse a no-op replacement early. It would walk the whole database
		// for nothing.
		if ( $old === $new ) {
			return Response::error( 'Old and new strings are identical, no replacement to perform.' );
		}

		// Structured storage cannot survive a byte-level splice of JSON
		// metacharacters. Gutenberg block attributes are JSON inside HTML
		// comments in `post_content`; many options and meta hold JSON too. A
		// `"` or `\` in EITHER direction corrupts them: in the replacement it
		// lands unescaped inside an encoded string; in the OLD string it
		// splices a quote/backslash OUT of the encoding (that JSON is not
		// PHP-serialized, so the serialized round-trip guard never fires).
		// Either way the document stops parsing and (for blocks) every
		// attribute — including styling classes — drops on the next editor
		// save. There is no per-cell way to know which text sits inside JSON,
		// so refuse outright — fail closed.
		foreach ( array(
			'Search'      => (string) $old,
			'Replacement' => (string) $new,
		) as $side => $value ) {
			if ( false !== strpos( $value, '"' ) || false !== strpos( $value, '\\' ) ) {
				return Response::error(
					$side . ' string contains `"` or `\\`, which would corrupt JSON-encoded content (block attributes, settings) via byte-level replacement. Edit those values through the REST/content tools instead.'
				);
			}
		}

		$dry_run    = ! empty( $flags['dry-run'] );
		$all_tables = ! empty( $flags['all-tables'] ) || ! empty( $flags['all-tables-with-prefix'] );

		// Always-skip columns. These are identity and control columns. They
		// are not the text content a replacement is for. `guid` is the post
		// permalink that feed readers key on. `user_pass` is a bcrypt hash.
		// `option_name` is the identity of an option. A rewrite deletes the
		// option as far as `get_option()` sees it. `autoload` is a `yes`/`no`
		// control column. A needle as ordinary as `no` would stop options
		// loading across the whole site.
		//
		// The same rule covers the rest of this list. `post_type` and
		// `taxonomy` are row identity — rewriting them to an unregistered
		// value makes every affected post/term vanish from the site and
		// wp-admin (`search-replace page landing` unregisters every page).
		// `post_status` and `comment_approved` are control enums stored as
		// text (`search-replace publish live` unpublishes the whole site).
		// `meta_key` is meta identity — a rewrite orphans the meta for every
		// consumer. `post_mime_type` and `comment_type` are typed selectors
		// queries filter on.
		$always_skip = array(
			'guid',
			'user_pass',
			'option_name',
			'autoload',
			'post_type',
			'post_status',
			'post_mime_type',
			'meta_key',
			'taxonomy',
			'comment_type',
			'comment_approved',
		);
		$user_skip   = array();
		if ( ! empty( $flags['skip-columns'] ) ) {
			$user_skip = array_filter(
				array_map( 'trim', explode( ',', (string) $flags['skip-columns'] ) )
			);
		}
		$skip_columns = array_unique( array_merge( $always_skip, $user_skip ) );

		// Resolve the set of tables to walk.
		if ( $all_tables ) {
			$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		} else {
			$tables = array_values( $wpdb->tables() );
		}

		// The code never walks identity and privilege tables. This holds with
		// or without `--all-tables`. This engine writes cells with raw
		// `$wpdb->update()`. So no `pre_update_option_*` or `update_user_meta`
		// filter observes it (DSA-15). These columns are authentication and
		// capability state. They are not the text content a search-replace is
		// for. `users.user_email` is the password-recovery address.
		// `usermeta.wp_capabilities` is the per-user role map.
		// `sitemeta.site_admins` is the network admin list. `blogs.domain` and
		// `site.domain` are subsite and network hostnames. `signups` and
		// `registration_log` hold pending-registration emails.
		//
		// Only multisite sets the multisite entries (`ms_global_tables`). They
		// matter because `--all-tables` from the MAIN site resolves `SHOW
		// TABLES LIKE '{$wpdb->prefix}%'` with the bare base prefix. So the list
		// holds every global table. It also holds the tables of every OTHER
		// subsite.
		//
		// The match is case-insensitive. `SHOW TABLES` reports names as MySQL
		// stored them. A case mismatch here would disable the exclusion. It
		// would not fail loudly.
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users -- the table NAMES are read to exclude them from the walk; no user data is queried.
		$excluded_tables = array( strtolower( $wpdb->users ), strtolower( $wpdb->usermeta ) );
		foreach ( array( 'sitemeta', 'blogs', 'site', 'signups', 'registration_log' ) as $ms_table ) {
			if ( ! empty( $wpdb->$ms_table ) ) {
				$excluded_tables[] = strtolower( $wpdb->$ms_table );
			}
		}

		$report         = array();
		$total_changes  = 0;
		$skipped_tables = array();

		foreach ( (array) $tables as $table ) {
			if ( ! is_scalar( $table ) ) {
				continue;
			}
			$table = (string) $table;
			if ( '' === $table ) {
				continue;
			}
			if ( in_array( strtolower( $table ), $excluded_tables, true ) ) {
				$skipped_tables[] = $table;
				continue;
			}
			$primary_key = $this->table_primary_key( $table );
			if ( null === $primary_key ) {
				continue; // Skip tables without a single-column PK.
			}
			$text_columns = $this->table_text_columns( $table );
			if ( empty( $text_columns ) ) {
				continue;
			}
			foreach ( $text_columns as $column ) {
				if ( in_array( $column, $skip_columns, true ) ) {
					continue;
				}
				$changed = $this->search_replace_column( $table, $column, $primary_key, (string) $old, (string) $new, $dry_run );
				if ( $changed > 0 ) {
					if ( ! isset( $report[ $table ] ) ) {
						$report[ $table ] = array();
					}
					$report[ $table ][ $column ] = $changed;
					$total_changes              += $changed;
				}
			}
		}

		// The walk writes cells with raw $wpdb->update(), which no cache layer
		// observes. With a persistent object cache the `alloptions`/`posts`
		// groups would keep serving pre-replace values, and a later legitimate
		// update_option() comparing against the stale pre-image could no-op or
		// resurrect it. One flush after the batch is the correct price for a
		// DB-level bulk rewrite (wp-cli's own search-replace tells users to
		// flush for the same reason).
		if ( ! $dry_run && $total_changes > 0 ) {
			wp_cache_flush();
		}

		return Response::success(
			array(
				'dry_run'         => $dry_run,
				'total_changes'   => $total_changes,
				'tables_changed'  => $report,
				'skipped_columns' => array_values( $skip_columns ),
				'skipped_tables'  => $skipped_tables,
			)
		);
	}

	/**
	 * Whether a table is an options table whose rows the protect-list covers.
	 *
	 * Matches the FAMILY, not just the current blog's table. `--all-tables` run
	 * from a multisite MAIN site resolves `SHOW TABLES LIKE
	 * '{$wpdb->prefix}%'` with the bare base prefix, so every OTHER subsite's
	 * `{base}_{id}_options` is in the walk. Comparing against `$wpdb->options`
	 * alone left those unfiltered — the same raw-`$wpdb->update()` bypass this
	 * change exists to close, one prefix over.
	 *
	 * `base_prefix` equals `prefix` on single-site, so this is one code path for
	 * both. The `\d+_` segment is what keeps a plugin's own
	 * `{base}_myplugin_options` from matching.
	 *
	 * @param string $table Table name (with prefix).
	 * @return bool
	 */
	private function is_options_table( string $table ): bool {
		global $wpdb;
		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		return 1 === preg_match(
			'/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+_)?options$/i',
			$table
		);
	}

	/**
	 * Return the single-column primary key of a table, or null when the table
	 * has a composite or no primary key.
	 *
	 * @param string $table Fully-qualified table name (with prefix).
	 * @return string|null
	 */
	private function table_primary_key( string $table ): ?string {
		global $wpdb;
		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		// `SHOW KEYS` cannot use placeholders for identifiers, but the table
		// name has been resolved from $wpdb->tables() / SHOW TABLES — never
		// from user input — and is backtick-quoted defensively.
		$safe_table = str_replace( '`', '', $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW KEYS FROM `{$safe_table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return null;
		}
		$first   = $rows[0];
		$col_raw = $first['Column_name'] ?? '';
		$col     = is_scalar( $col_raw ) ? (string) $col_raw : '';
		return '' === $col ? null : $col;
	}

	/**
	 * Return the names of text-typed columns in a table (char/varchar/text
	 * family). Only these columns can hold the kind of values search-replace
	 * needs to touch.
	 *
	 * @param string $table Table name (with prefix).
	 * @return string[]
	 */
	private function table_text_columns( string $table ): array {
		global $wpdb;
		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		$safe_table = str_replace( '`', '', $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$safe_table}`", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$type_raw  = $row['Type'] ?? '';
			$type      = is_string( $type_raw ) ? strtolower( $type_raw ) : '';
			$field_raw = $row['Field'] ?? '';
			if ( preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set)/', $type ) && is_scalar( $field_raw ) ) {
				$out[] = (string) $field_raw;
			}
		}
		return $out;
	}

	/**
	 * Replace occurrences of $old with $new in one column of one table.
	 *
	 * The scan is keyed by primary-key list rather than offset paging so
	 * concurrent UPDATEs that move rows out of the LIKE result set don't
	 * shift the cursor.
	 *
	 * @param string $table       Table name.
	 * @param string $column      Column name.
	 * @param string $primary_key Primary-key column.
	 * @param string $old         Search string.
	 * @param string $new         Replacement string.
	 * @param bool   $dry_run     When true, count matches without writing.
	 * @return int Number of rows whose value changed.
	 */
	private function search_replace_column( string $table, string $column, string $primary_key, string $old, string $new, bool $dry_run ): int {
		global $wpdb;
		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		$safe_table  = str_replace( '`', '', $table );
		$safe_column = str_replace( '`', '', $column );
		$safe_pk     = str_replace( '`', '', $primary_key );
		$like        = '%' . $wpdb->esc_like( $old ) . '%';

		// Protected options are excluded at row-selection, the single choke
		// point every write below passes through. `Protected_Options_Filter`
		// cannot cover this engine — it hooks `pre_update_option_<key>` and we
		// write the cell with raw `$wpdb->update()`, so the bypass was total
		// and silent (DSA-15): `search-replace admin@old attacker@evil` rewrote
		// `admin_email` with no refusal and no log line.
		$protected_where    = '';
		$protected_bindings = array();
		if ( $this->is_options_table( $table ) ) {
			$protected_bindings = Protected_Options_Filter::write_protected_keys();
			if ( ! empty( $protected_bindings ) ) {
				$protected_where = ' AND option_name NOT IN ('
					. implode( ',', array_fill( 0, count( $protected_bindings ), '%s' ) )
					. ')';
			}
			// `write_protected_keys()` can only name the CURRENT blog's role map
			// (`{$wpdb->prefix}user_roles`), but on multisite this table may
			// belong to another subsite, whose map is `{base}_{id}_user_roles`.
			// Match the suffix instead — the same rule applied server-side for
			// the same reason. Refuses an unrelated `*_user_roles` option too,
			// which is the safe direction.
			$protected_where     .= ' AND option_name NOT LIKE %s';
			$protected_bindings[] = '%' . $wpdb->esc_like( 'user_roles' );
		}

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- identifiers bind via %i; the concatenated {$protected_where} is a controlled list of %s tokens; the statement is fully prepared.
		$pk_sql = $wpdb->prepare(
			// Identifiers bind via %i (backtick-quoted by wpdb); values bind via %s.
			'SELECT %i FROM %i WHERE %i LIKE %s' . $protected_where,
			array_merge( array( $safe_pk, $safe_table, $safe_column, $like ), $protected_bindings )
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$pks = $wpdb->get_col( $pk_sql );
		if ( empty( $pks ) ) {
			return 0;
		}

		$changes    = 0;
		$batch_size = 200;

		foreach ( array_chunk( $pks, $batch_size ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers bind via %i; the interpolated {$placeholders} is a controlled list of %s tokens; the statement is fully prepared.
			$batch_sql = $wpdb->prepare(
				"SELECT %i AS pk, %i AS val FROM %i WHERE %i IN ({$placeholders})",
				array_merge( array( $safe_pk, $safe_column, $safe_table, $safe_pk ), $chunk )
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $batch_sql );
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$val_raw  = $row->val ?? '';
				$original = is_scalar( $val_raw ) ? (string) $val_raw : '';
				$replaced = $this->replace_recursively( $original, $old, $new );
				if ( ! is_string( $replaced ) || $replaced === $original ) {
					continue;
				}
				if ( $dry_run ) {
					++$changes;
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					$table,
					array( $column => $replaced ),
					array( $primary_key => $row->pk )
				);
				// wpdb::update() returns the number of rows changed, 0 when the row
				// no longer matched (e.g. a concurrent writer), or false on a DB
				// error. Count only rows that actually changed so the reported
				// total never over-reports persisted writes.
				if ( $updated > 0 ) {
					++$changes;
				}
			}
		}

		return $changes;
	}

	/**
	 * Replace $old with $new inside $data, descending through serialized
	 * payloads (arrays / objects / nested serialized strings).
	 *
	 * Returns a string in all branches so the caller can compare against the
	 * original cell value verbatim. Serialized inputs are returned as
	 * serialized strings; plain strings as plain strings.
	 *
	 * `unserialize` is called with `allowed_classes => false` so a poisoned
	 * payload cannot instantiate plugin/theme classes during the walk.
	 *
	 * @param mixed  $data  Value to recurse into.
	 * @param string $old   Search string.
	 * @param string $new   Replacement string.
	 * @param int    $depth Recursion guard.
	 * @return mixed
	 */
	private function replace_recursively( $data, string $old, string $new, int $depth = 0 ) {
		if ( $depth > 50 ) {
			return $data;
		}
		if ( is_string( $data ) ) {
			if ( is_serialized( $data ) ) {
				$unserialized = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- round-trips WP's own serialized DB values; allowed_classes=false is safer than maybe_unserialize, which instantiates objects.
				if ( false !== $unserialized || 'b:0;' === $data ) {
					$replaced = $this->replace_recursively( $unserialized, $old, $new, $depth + 1 );
					return serialize( $replaced ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- re-serialize to preserve WP's stored format after in-place search-replace.
				}
				// The cell LOOKS serialized but does not round-trip (truncated
				// value, or a `C:`-format Serializable payload is_serialized()
				// cannot classify). A byte-level replace here would break the
				// length prefixes and destroy the cell permanently — the exact
				// corruption this walker exists to prevent. Skip the cell
				// (returning it unchanged means the caller writes nothing).
				return $data;
			}
			return str_replace( $old, $new, $data );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = $this->replace_recursively( $v, $old, $new, $depth + 1 );
			}
			return $data;
		}
		if ( is_object( $data ) ) {
			// Only stdClass round-trips safely. With allowed_classes=false a
			// class'd payload arrives as __PHP_Incomplete_Class whose private/
			// protected properties keep their NUL-mangled names ("\0Class\0prop");
			// assigning through those throws, and re-serializing the incomplete
			// class stamps the wrong class name. Leave such cells unchanged.
			if ( ! ( $data instanceof \stdClass ) ) {
				return $data;
			}
			$clone = clone $data;
			foreach ( get_object_vars( $clone ) as $k => $v ) {
				$clone->$k = $this->replace_recursively( $v, $old, $new, $depth + 1 );
			}
			return $clone;
		}
		return $data;
	}
}
