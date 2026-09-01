<?php
/**
 * Grep Search Ability
 *
 * Unified search across files, database, options, and WordPress content.
 * Like Claude Code's Grep tool — searches everywhere for a pattern.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Core;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GrepSearch extends Abstract_Ability {

	/**
	 * Max results per source.
	 */
	const MAX_RESULTS = 30;

	/**
	 * Allowed file extensions for file search.
	 */
	const FILE_EXTENSIONS = array( 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'html', 'json', 'txt', 'md', 'xml' );

	/**
	 * Configure the ability.
	 *
	 * @since 0.0.5
	 */
	public function configure() {
		$this->id          = 'zipai/search-grep';
		$this->label       = 'Grep Search';
		$this->description = 'Search for a text or regex pattern across the WordPress codebase and database. '
			. 'Use this FIRST when debugging or exploring — find where code or config lives before editing. '
			. 'Sources: "files" scans wp-content/ (active theme first, then all themes/plugins, up to 1000 files); '
			. '"options" searches wp_options by name and value; "posts" searches post title and content; '
			. '"meta" searches postmeta and usermeta; "database" searches all core tables; "all" runs every source. '
			. 'Narrow file scope with path (e.g., "plugins/my-plugin") and file_type (e.g., "php", "js"). '
			. 'Supports both plain-text and regex patterns.';
		$this->capability  = 'manage_options';
	}

	/**
	 * Get tool type.
	 *
	 * @since 0.0.5
	 * @return string
	 */
	public function get_tool_type() {
		return Tool_Types::SEARCH;
	}

	/**
	 * Get input schema.
	 *
	 * @since 0.0.5
	 * @return array<string,mixed>
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'pattern'   => array(
					'type'        => 'string',
					'description' => 'Text or regex pattern to search for.',
				),
				'source'    => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'files', 'database', 'options', 'posts', 'meta' ),
					'default'     => 'all',
					'description' => 'Where to search. "all" searches everywhere. "files" searches wp-content/ files. '
						. '"database" searches across all tables. "options" searches wp_options. '
						. '"posts" searches post content/title. "meta" searches post_meta and user_meta.',
				),
				'path'      => array(
					'type'        => 'string',
					'description' => 'For file search: directory path relative to wp-content/ (e.g., "plugins/my-plugin"). Default searches all of wp-content/.',
				),
				'file_type' => array(
					'type'        => 'string',
					'description' => 'For file search: filter by extension (e.g., "php", "js", "css"). Default searches all allowed types.',
				),
			),
			'required'   => array( 'pattern' ),
		);
	}

	/**
	 * Execute the search.
	 *
	 * @since 0.0.5
	 * @param array<string,mixed> $input Validated input.
	 * @return array<string,mixed> Response data.
	 */
	public function execute( $input ) {
		$pattern   = isset( $input['pattern'] ) && is_string( $input['pattern'] ) ? $input['pattern'] : '';
		$source    = isset( $input['source'] ) && is_string( $input['source'] ) ? $input['source'] : 'all';
		$path      = isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : '';
		$file_type = isset( $input['file_type'] ) && is_string( $input['file_type'] ) ? $input['file_type'] : '';

		if ( empty( $pattern ) ) {
			return Response::error( 'Search pattern is required.' );
		}

		if ( strlen( $pattern ) < 2 ) {
			return Response::error( 'Pattern too short. Minimum 2 characters.' );
		}

		$results  = array();
		$searched = array();

		if ( in_array( $source, array( 'all', 'files' ), true ) ) {
			$results['files'] = $this->search_files( $pattern, $path, $file_type );
			$searched[]       = 'files';
		}

		if ( in_array( $source, array( 'all', 'database' ), true ) ) {
			$results['database'] = $this->search_database( $pattern );
			$searched[]          = 'database';
		}

		if ( in_array( $source, array( 'all', 'options' ), true ) ) {
			$results['options'] = $this->search_options( $pattern );
			$searched[]         = 'options';
		}

		if ( in_array( $source, array( 'all', 'posts' ), true ) ) {
			$results['posts'] = $this->search_posts( $pattern );
			$searched[]       = 'posts';
		}

		if ( in_array( $source, array( 'all', 'meta' ), true ) ) {
			$results['meta'] = $this->search_meta( $pattern );
			$searched[]      = 'meta';
		}

		$total_matches = 0;
		foreach ( $results as $source_results ) {
			$total_matches += $source_results['count'];
		}

		return Response::success(
			sprintf( 'Found %d matches across %s.', $total_matches, implode( ', ', $searched ) ),
			array(
				'pattern'       => $pattern,
				'total_matches' => $total_matches,
				'sources'       => $searched,
				'results'       => $results,
			)
		);
	}

	/**
	 * Search files in wp-content/ for a pattern.
	 *
	 * @since 0.0.5
	 * @param string $pattern   Text or regex pattern to search for.
	 * @param string $path      Directory path relative to wp-content/.
	 * @param string $file_type File extension filter (without leading dot).
	 * @return array{count:int,files_scanned?:int,error?:string,matches:array<int,array{file:string,matches:array<int,array{line:int,text:string}>}>} File match results.
	 */
	private function search_files( $pattern, $path, $file_type ) {
		$base_dir = WP_CONTENT_DIR;
		if ( ! empty( $path ) ) {
			$search_dir = $base_dir . '/' . ltrim( str_replace( '..', '', $path ), '/' );
			if ( ! is_dir( $search_dir ) ) {
				return array(
					'count'   => 0,
					'error'   => "Directory not found: {$path}",
					'matches' => array(),
				);
			}
			$base_dir = $search_dir;
		}

		// When searching all of wp-content/, search active theme first, then plugins
		$search_dirs = array( $base_dir );
		if ( WP_CONTENT_DIR === $base_dir && empty( $path ) ) {
			$search_dirs = array(
				get_stylesheet_directory(),                    // Active child theme
				get_template_directory(),                      // Active parent theme
				get_theme_root(),                              // All themes
				WP_PLUGIN_DIR,                                 // All plugins
				WPMU_PLUGIN_DIR,                               // Must-use plugins
			);
			$search_dirs = array_unique( array_filter( $search_dirs, 'is_dir' ) );
		}

		/**
		 * Accumulated file match entries.
		 *
		 * @var array<int,array{file:string,matches:array<int,array{line:int,text:string}>}> $matches
		 */
		$matches   = array();
		$scanned   = 0;
		$max_files = 1000;
		/**
		 * Map of already-scanned file paths.
		 *
		 * @var array<string,bool> $seen
		 */
		$seen = array();

		foreach ( $search_dirs as $dir ) {
			$this->scan_dir_for_pattern( $dir, $pattern, $file_type, $matches, $scanned, $max_files, $seen );
			if ( $scanned >= $max_files || count( $matches ) >= self::MAX_RESULTS ) {
				break;
			}
		}

		return array(
			'count'         => count( $matches ),
			'files_scanned' => $scanned,
			'matches'       => $matches,
		);
	}

	/**
	 * Scan a single directory for pattern matches.
	 *
	 * @since 0.0.5
	 * @param string                                                                       $base_dir  Directory to scan.
	 * @param string                                                                       $pattern   Text or regex pattern to search for.
	 * @param string                                                                       $file_type File extension filter (without leading dot).
	 * @param array<int,array{file:string,matches:array<int,array{line:int,text:string}>}> $matches   Accumulated match entries, by reference.
	 * @param int                                                                          $scanned   Running count of scanned files, by reference.
	 * @param int                                                                          $max_files Maximum number of files to scan.
	 * @param array<string,bool>                                                           $seen      Map of already-seen file paths, by reference.
	 * @return void
	 */
	private function scan_dir_for_pattern( $base_dir, $pattern, $file_type, &$matches, &$scanned, $max_files, &$seen ) {

		$extensions = self::FILE_EXTENSIONS;
		if ( ! empty( $file_type ) ) {
			$file_type  = ltrim( $file_type, '.' );
			$extensions = array( $file_type );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $scanned >= $max_files || count( $matches ) >= self::MAX_RESULTS ) {
				break;
			}

			if ( ! $file instanceof \SplFileInfo ) {
				continue;
			}

			if ( ! $file->isFile() ) {
				continue;
			}

			$filepath = $file->getPathname();

			// Skip already seen files (from priority scanning).
			if ( isset( $seen[ $filepath ] ) ) {
				continue;
			}
			$seen[ $filepath ] = true;

			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, $extensions, true ) ) {
				continue;
			}

			// Skip vendor/node_modules/build directories.
			if ( preg_match( '#/(vendor|node_modules|build|dist|\.git)/#', $filepath ) ) {
				continue;
			}

			// Skip files larger than 500KB.
			if ( $file->getSize() > 512000 ) {
				continue;
			}

			++$scanned;

			$content = file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $content ) {
				continue;
			}

			// Find matching lines — try regex first, fall back to plain text.
			$lines         = explode( "\n", $content );
			$matched_lines = array();
			$is_regex      = @preg_match( '/' . $pattern . '/i', '' ) !== false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- probes whether the user-supplied pattern is a valid regex; a malformed pattern yields false and falls back to plain-text search below.

			foreach ( $lines as $num => $line ) {
				$found = $is_regex
					? @preg_match( '/' . $pattern . '/i', $line ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- applies the user-supplied regex per line; validity was already probed above and any match failure is treated as no match.
					: ( false !== stripos( $line, $pattern ) );

				if ( $found ) {
					$matched_lines[] = array(
						'line' => $num + 1,
						'text' => mb_substr( trim( $line ), 0, 200 ),
					);
					if ( count( $matched_lines ) >= 5 ) {
						break;
					}
				}
			}

			if ( ! empty( $matched_lines ) ) {
				$relative  = str_replace( WP_CONTENT_DIR, basename( WP_CONTENT_DIR ), $filepath );
				$matches[] = array(
					'file'    => $relative,
					'matches' => $matched_lines,
				);
			}
		}
	}

	/**
	 * Search database tables for a pattern.
	 *
	 * @since 0.0.5
	 * @param string $pattern Text or regex pattern to search for.
	 * @return array{count:int,tables:int,matches:array<int,array{table:string,count:int,columns:array<int,string>,sample:array<int,array<string,string|null>>}>} Database match results.
	 */
	private function search_database( $pattern ) {
		/**
		 * Narrowed type for `$wpdb`.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		$matches = array();
		$like    = '%' . $wpdb->esc_like( $pattern ) . '%';

		// Search key tables with text columns.
		$searches = array(
			$wpdb->posts    => array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ),
			$wpdb->postmeta => array( 'meta_key', 'meta_value' ),
			$wpdb->options  => array( 'option_name', 'option_value' ),
			$wpdb->usermeta => array( 'meta_key', 'meta_value' ),
			$wpdb->comments => array( 'comment_content', 'comment_author', 'comment_author_url' ),
		);

		foreach ( $searches as $table => $columns ) {
			$conditions = array();
			foreach ( $columns as $col ) {
				$conditions[] = $wpdb->prepare( "`{$col}` LIKE %s", $like ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$where = implode( ' OR ', $conditions );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}" );

			if ( $count > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$sample = $wpdb->get_results(
					"SELECT * FROM `{$table}` WHERE {$where} LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				);
				if ( ! is_array( $sample ) ) {
					$sample = array();
				}
				// Truncate long values in sample.
				/**
				 * Narrowed type for `$sample`.
				 *
				 * @var array<int,array<string,string|null>> $sample
				 */
				$sample = array_map(
					function ( $row ) {
						return array_map(
							function ( $v ) {
								return is_string( $v ) && strlen( $v ) > 150 ? substr( $v, 0, 150 ) . '...' : $v;
							},
							$row 
						);
					},
					$sample 
				);

				$matches[] = array(
					'table'   => str_replace( $wpdb->prefix, '{prefix}', $table ),
					'count'   => (int) $count,
					'columns' => $columns,
					'sample'  => $sample,
				);
			}
		}

		return array(
			'count'   => array_sum( array_column( $matches, 'count' ) ),
			'tables'  => count( $matches ),
			'matches' => $matches,
		);
	}

	/**
	 * Search wp_options for a pattern.
	 *
	 * @since 0.0.5
	 * @param string $pattern Text or regex pattern to search for.
	 * @return array{count:int,options:array<int,array{option_name:string,option_value:string}>} Option match results.
	 */
	private function search_options( $pattern ) {
		/**
		 * Narrowed type for `$wpdb`.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $pattern ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, LEFT(option_value, 200) as option_value FROM %i WHERE option_name LIKE %s OR option_value LIKE %s LIMIT %d',
				$wpdb->options,
				$like,
				$like,
				self::MAX_RESULTS
			),
			ARRAY_A
		);
		if ( ! is_array( $results ) ) {
			$results = array();
		}
		/**
		 * Narrowed type for `$results`.
		 *
		 * @var array<int,array{option_name:string,option_value:string}> $results
		 */
		return array(
			'count'   => count( $results ),
			'options' => $results,
		);
	}

	/**
	 * Search post content and titles.
	 *
	 * @since 0.0.5
	 * @param string $pattern Text or regex pattern to search for.
	 * @return array{count:int,matches:array<int,array{ID:int,title:string,type:string,status:string,url:string|false}>} Post match results.
	 */
	private function search_posts( $pattern ) {
		$query = new \WP_Query(
			array(
				's'              => $pattern,
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => self::MAX_RESULTS,
			) 
		);

		/**
		 * Narrowed type for `$posts`.
		 *
		 * @var array<int,\WP_Post> $posts
		 */
		$posts = $query->posts;

		$matches = array_map(
			function ( \WP_Post $post ) {
				return array(
					'ID'     => $post->ID,
					'title'  => $post->post_title,
					'type'   => $post->post_type,
					'status' => $post->post_status,
					'url'    => get_permalink( $post->ID ),
				);
			},
			$posts
		);

		return array(
			'count'   => $query->found_posts,
			'matches' => $matches,
		);
	}

	/**
	 * Search post_meta and user_meta.
	 *
	 * @since 0.0.5
	 * @param string $pattern Text or regex pattern to search for.
	 * @return array{count:int,matches:array<string,array{count:int,matches:array<int,array<string,string|null>>}>} Meta match results grouped by post_meta/user_meta.
	 */
	private function search_meta( $pattern ) {
		/**
		 * Narrowed type for `$wpdb`.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		$like    = '%' . $wpdb->esc_like( $pattern ) . '%';
		$results = array();

		// Post meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_meta = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT pm.meta_id, pm.post_id, pm.meta_key, LEFT(pm.meta_value, 150) as meta_value, p.post_title
				FROM %i pm
				LEFT JOIN %i p ON pm.post_id = p.ID
				WHERE pm.meta_key LIKE %s OR pm.meta_value LIKE %s
				LIMIT %d',
				$wpdb->postmeta,
				$wpdb->posts,
				$like,
				$like,
				self::MAX_RESULTS
			),
			ARRAY_A
		);
		if ( ! is_array( $post_meta ) ) {
			$post_meta = array();
		}
		/**
		 * Narrowed type for `$post_meta`.
		 *
		 * @var array<int,array<string,string|null>> $post_meta
		 */
		if ( ! empty( $post_meta ) ) {
			$results['post_meta'] = array(
				'count'   => count( $post_meta ),
				'matches' => $post_meta,
			);
		}

		// User meta.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users -- grep tool searches meta values across all users; get_user_meta cannot query by value.
		$user_meta = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT um.umeta_id, um.user_id, um.meta_key, LEFT(um.meta_value, 150) as meta_value, u.user_login
				FROM %i um
				LEFT JOIN %i u ON um.user_id = u.ID
				WHERE um.meta_key LIKE %s OR um.meta_value LIKE %s
				LIMIT %d',
				$wpdb->usermeta,
				$wpdb->users,
				$like,
				$like,
				self::MAX_RESULTS
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		if ( ! is_array( $user_meta ) ) {
			$user_meta = array();
		}
		/**
		 * Narrowed type for `$user_meta`.
		 *
		 * @var array<int,array<string,string|null>> $user_meta
		 */
		if ( ! empty( $user_meta ) ) {
			$results['user_meta'] = array(
				'count'   => count( $user_meta ),
				'matches' => $user_meta,
			);
		}

		$total = ( $results['post_meta']['count'] ?? 0 ) + ( $results['user_meta']['count'] ?? 0 );

		return array(
			'count'   => $total,
			'matches' => $results,
		);
	}
}
