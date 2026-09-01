<?php
/**
 * Install Fonts — self-hosts Google-served webfonts into the WP Font Library.
 *
 * A21 (render-interference inventory): authored pages declare webfonts the
 * import flow never installs, so weights the page uses render synthesized
 * (advance drift up to +17%) and visitor machines without local fonts degrade
 * to fallback families. This ability downloads the latin-subset woff2 files
 * server-side and registers them as core `wp_font_family` / `wp_font_face`
 * posts — the native Font Library store the Site Editor already understands.
 *
 * Deliberately NO global-styles writes here: activation (merging Library
 * families into the user layer so core emits `@font-face`) is owned by the
 * spectra-blocks Global Styles Bridge, the one sanctioned `wp_global_styles`
 * writer (D5 policy).
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InstallFonts extends Abstract_Ability {

	/**
	 * A browser UA that makes fonts.googleapis.com serve woff2 sources.
	 *
	 * @var string
	 */
	const WOFF2_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	/**
	 * Configure the ability (id, label, visibility, capability).
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/install-fonts';
		$this->label       = 'Install Fonts';
		$this->description = 'Download Google Font families (latin woff2, requested weights) and register them in the WordPress Font Library. Idempotent per family slug + weight.';

		// Internal: called by the import committer with the page's fonts
		// manifest — never a tool the agent should pick for itself.
		$this->meta['visibility'] = 'internal';

		// Font Library entries are theme-level assets (core's font REST
		// controllers gate on the wp_font_family CPT caps, mapped to themes).
		$this->capability = 'edit_theme_options';
	}

	/**
	 * Tool type.
	 *
	 * @return string
	 */
	public function get_tool_type() {
		return Tool_Types::ACTION;
	}

	/**
	 * Input schema for the install request.
	 *
	 * @return array<string,mixed>
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'families' ),
			'properties' => array(
				'families' => array(
					'type'        => 'array',
					'description' => 'Families to install, each with the numeric weights the page uses.',
					'items'       => array(
						'type'       => 'object',
						'required'   => array( 'family', 'weights' ),
						'properties' => array(
							'family'  => array(
								'type'        => 'string',
								'description' => 'Font family name as in the Google Fonts catalog (e.g. "Lora").',
							),
							'weights' => array(
								'type'        => 'array',
								'description' => 'Numeric font weights (100–900).',
								'items'       => array( 'type' => 'integer' ),
							),
							'opsz'    => array(
								'type'        => 'string',
								'description' => 'Optional optical-size axis range (e.g. "9..144") when the source used the VARIABLE font. Present installs the opsz-variable woff2 so display-size headings do not rewrap against a static cut; absent stays static.',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Install every requested family; per-family soft-fail.
	 *
	 * @param array<string,mixed> $args Ability arguments ({families:[{family, weights[]}]}).
	 * @return array<string,mixed> Response envelope.
	 */
	public function execute( $args ) {
		try {
			$families = isset( $args['families'] ) && is_array( $args['families'] ) ? $args['families'] : array();
			if ( empty( $families ) ) {
				return Response::error( 'families is required and must be a non-empty array.' );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';

			$results     = array();
			$any_failure = false;

			foreach ( $families as $entry ) {
				$entry       = is_array( $entry ) ? $entry : array();
				$family      = isset( $entry['family'] ) && is_string( $entry['family'] ) ? sanitize_text_field( $entry['family'] ) : '';
				$weights_raw = isset( $entry['weights'] ) && is_array( $entry['weights'] ) ? $entry['weights'] : array();
				$weights     = array_values( array_unique( array_filter( array_map( static fn ( $w ): int => is_scalar( $w ) ? (int) $w : 0, $weights_raw ), array( $this, 'is_valid_weight' ) ) ) );
				sort( $weights );

				if ( '' === $family || empty( $weights ) ) {
					$results[]   = array(
						'family' => $family,
						'status' => 'failed',
						'error'  => 'family and at least one valid weight (100-900) are required.',
						'faces'  => array(),
					);
					$any_failure = true;
					continue;
				}

				$opsz = '';
				if ( isset( $entry['opsz'] ) && is_string( $entry['opsz'] ) && preg_match( '/^\\d+(?:\\.\\.\\d+)?$/', $entry['opsz'] ) ) {
					$opsz = $entry['opsz'];
				}

				$row       = $this->install_family( $family, $weights, $opsz );
				$results[] = $row;
				if ( 'failed' === $row['status'] ) {
					$any_failure = true;
				}
			}

			$summary = sprintf(
				'%d of %d families processed successfully.',
				count(
					array_filter(
						$results,
						function ( $r ) {
							return 'failed' !== $r['status'];
						}
					)
				),
				count( $results )
			);

			// Partial failure is still a useful result: the import proceeds
			// either way (caller logs + retries on the next import).
			return Response::success(
				$summary,
				array(
					'families'     => $results,
					'has_failures' => $any_failure,
				)
			);
		} catch ( \Throwable $e ) {
			return Response::error( 'Font install exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether a weight is a valid numeric font weight.
	 *
	 * @param int $weight Candidate weight.
	 * @return bool
	 */
	public function is_valid_weight( $weight ) {
		return $weight >= 100 && $weight <= 900;
	}

	/**
	 * Install one family: fetch the css2 stylesheet, download each weight's
	 * latin woff2 into the font directory, create the Library CPT entries.
	 *
	 * @param string    $family  Family name.
	 * @param list<int> $weights Sorted unique numeric weights.
	 * @param string    $opsz    Optical-size axis range (e.g. "9..144") or '' for the static cut.
	 * @return array<string,mixed> Per-family result row.
	 */
	private function install_family( $family, $weights, $opsz = '' ) {
		$slug      = sanitize_title( $family );
		$family_id = $this->find_family_post( $slug );
		$faces     = array();
		$created   = 0;
		$existed   = 0;

		// One css2 request covers every requested weight.
		$css = $this->fetch_css2( $family, $weights, $opsz );
		if ( is_wp_error( $css ) ) {
			return array(
				'family' => $family,
				'status' => 'failed',
				'error'  => $css->get_error_message(),
				'faces'  => array(),
			);
		}

		$sources = $this->parse_latin_woff2_sources( $css );
		if ( empty( $sources ) ) {
			return array(
				'family' => $family,
				'status' => 'failed',
				'error'  => 'No woff2 sources found in the Google css2 response.',
				'faces'  => array(),
			);
		}

		$font_dir = wp_font_dir();
		if ( ! empty( $font_dir['error'] ) ) {
			return array(
				'family' => $family,
				'status' => 'failed',
				'error'  => 'Font directory unavailable: ' . $font_dir['error'],
				'faces'  => array(),
			);
		}

		foreach ( $weights as $weight ) {
			if ( ! isset( $sources[ $weight ] ) ) {
				$faces[] = array(
					'weight' => $weight,
					'status' => 'failed',
					'error'  => 'Weight not present in the css2 response (family may not ship it).',
				);
				continue;
			}

			// Idempotency: face posts are deduped by core's face slug
			// (family;style;weight;stretch;range) under the same parent.
			if ( $family_id > 0 && $this->face_exists( $family_id, $family, $weight ) ) {
				$faces[]  = array(
					'weight' => $weight,
					'status' => 'existed',
				);
				$existed += 1;
				continue;
			}

			$file = $this->download_face_file( $sources[ $weight ], $slug, $weight, $font_dir );
			if ( is_wp_error( $file ) ) {
				$faces[] = array(
					'weight' => $weight,
					'status' => 'failed',
					'error'  => $file->get_error_message(),
				);
				continue;
			}

			if ( $family_id <= 0 ) {
				$family_id = $this->create_family_post( $family, $slug );
				if ( $family_id <= 0 ) {
					$faces[] = array(
						'weight' => $weight,
						'status' => 'failed',
						'error'  => 'Could not create the wp_font_family post.',
					);
					continue;
				}
			}

			$face_id = $this->create_face_post( $family_id, $family, $weight, $file['url'] );
			if ( $face_id <= 0 ) {
				$faces[] = array(
					'weight' => $weight,
					'status' => 'failed',
					'error'  => 'Could not create the wp_font_face post.',
				);
				continue;
			}

			$faces[]  = array(
				'weight' => $weight,
				'status' => 'installed',
				'url'    => $file['url'],
			);
			$created += 1;
		}

		$face_failures = array_filter(
			$faces,
			function ( $f ) {
				return 'failed' === $f['status'];
			}
		);

		$status = 'existed';
		if ( count( $face_failures ) === count( $faces ) ) {
			$status = 'failed';
		} elseif ( $created > 0 ) {
			$status = 'installed';
		}

		return array(
			'family'    => $family,
			'family_id' => $family_id,
			'status'    => $status,
			'faces'     => $faces,
		);
	}

	/**
	 * Fetch the Google css2 stylesheet for a family + weights with a woff2 UA.
	 *
	 * @param string    $family  Family name.
	 * @param list<int> $weights Numeric weights.
	 * @param string    $opsz    Optical-size axis range (e.g. "9..144") or '' to skip the variable request.
	 * @return string|\WP_Error CSS body.
	 */
	private function fetch_css2( $family, $weights, $opsz = '' ) {
		$weight_list = implode(
			';',
			array_map(
				function ( $w ) {
					return (string) $w;
				},
				$weights
			)
		);
		// Prefer the VARIABLE (optical-size) font when the source used opsz — a
		// static wght-only cut renders WIDER at display sizes, so headings rewrap.
		// Fall through to the static request if the family ships no opsz axis.
		if ( '' !== $opsz ) {
			$tuples  = implode(
				';',
				array_map(
					function ( $w ) use ( $opsz ) {
						return $opsz . ',' . $w;
					},
					$weights
				)
			);
			$var_url = 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $family ) . ':opsz,wght@' . $tuples . '&display=swap';
			$var     = wp_remote_get(
				$var_url,
				array(
					'timeout'    => 20,
					'user-agent' => self::WOFF2_USER_AGENT,
				)
			);
			if ( ! is_wp_error( $var ) && 200 === (int) wp_remote_retrieve_response_code( $var ) ) {
				$var_body = (string) wp_remote_retrieve_body( $var );
				if ( '' !== $var_body ) {
					return $var_body;
				}
			}
		}
		$url = 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $family ) . ':wght@' . $weight_list . '&display=swap';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => self::WOFF2_USER_AGENT,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'css2_http_' . $code, 'Google css2 returned HTTP ' . $code . ' for ' . $family . '.' );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new \WP_Error( 'css2_empty', 'Google css2 returned an empty body for ' . $family . '.' );
		}
		return $body;
	}

	/**
	 * Parse the css2 body into weight → latin woff2 URL.
	 *
	 * The css2 format groups faces per unicode subset with a leading subset
	 * comment (latin, latin-ext, cyrillic, ...). Normal-style blocks only are
	 * requested; per weight the latin subset wins, with the first comment-less
	 * block as fallback for responses without subset comments.
	 *
	 * @param string $css css2 response body.
	 * @return array<int, string> Weight → woff2 URL.
	 */
	private function parse_latin_woff2_sources( $css ) {
		$latin    = array();
		$fallback = array();

		// Pair each optional subset comment with its @font-face block.
		if ( ! preg_match_all( '#(?:/\*\s*([a-z0-9-]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}#i', $css, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $matches as $m ) {
			$subset = strtolower( trim( $m[1] ) );
			$block  = $m[2];

			if ( ! preg_match( '/font-weight\s*:\s*(\d{3})/i', $block, $wm ) ) {
				continue;
			}
			$weight = (int) $wm[1];

			if ( ! preg_match( '/src\s*:[^;]*url\(\s*([^)]+\.woff2)\s*\)/i', $block, $sm ) ) {
				continue;
			}
			$src = trim( $sm[1], " \t\n\r\0\x0B'\"" );

			if ( 'latin' === $subset ) {
				$latin[ $weight ] = $src;
			} elseif ( ! isset( $fallback[ $weight ] ) || '' === $subset ) {
				// Prefer comment-less blocks (single-subset responses); else
				// keep the first non-latin subset seen as a last resort.
				$fallback[ $weight ] = $src;
			}
		}

		return $latin + $fallback;
	}

	/**
	 * Download one woff2 into the fonts directory under a deterministic name.
	 *
	 * @param string              $src      Remote woff2 URL.
	 * @param string              $slug     Family slug.
	 * @param int                 $weight   Font weight.
	 * @param array<string,mixed> $font_dir wp_font_dir() array.
	 * @return array{path:string,url:string}|\WP_Error { path, url } on success.
	 */
	private function download_face_file( $src, $slug, $weight, $font_dir ) {
		$tmp = download_url( $src, 20 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		global $wp_filesystem;
		/**
		 * WordPress filesystem handle, null until initialized.
		 *
		 * @var \WP_Filesystem_Base|null $wp_filesystem
		 */
		if ( ! $wp_filesystem && ! WP_Filesystem() ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'fs_unavailable', 'WP_Filesystem could not be initialized.' );
		}

		$dir_path = isset( $font_dir['path'] ) && is_string( $font_dir['path'] ) ? $font_dir['path'] : '';
		$dir_url  = isset( $font_dir['url'] ) && is_string( $font_dir['url'] ) ? $font_dir['url'] : '';
		$filename = sanitize_file_name( $slug . '-' . $weight . '-latin.woff2' );
		$dest     = trailingslashit( $dir_path ) . $filename;

		// Deterministic filename — overwriting a partial prior download is the
		// idempotent, self-healing behavior.
		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'fs_unavailable', 'WP_Filesystem could not be initialized.' );
		}
		if ( ! $wp_filesystem->move( $tmp, $dest, true ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'fs_move_failed', 'Could not move the downloaded font into the fonts directory.' );
		}

		return array(
			'path' => $dest,
			'url'  => trailingslashit( $dir_url ) . $filename,
		);
	}

	/**
	 * Find an existing wp_font_family post id by slug.
	 *
	 * @param string $slug Family slug.
	 * @return int Post id, 0 when absent.
	 */
	private function find_family_post( $slug ) {
		$posts = get_posts(
			array(
				'post_type'      => 'wp_font_family',
				'name'           => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	/**
	 * Create the wp_font_family post (core REST storage shape: name/slug on
	 * the post, remaining settings as JSON content).
	 *
	 * @param string $family Family name.
	 * @param string $slug   Family slug.
	 * @return int New post id, 0 on failure.
	 */
	private function create_family_post( $family, $slug ) {
		$font_family = false !== strpos( $family, ' ' ) ? '"' . $family . '"' : $family;
		// wp_insert_post expects SLASHED data (core's font REST controller
		// wraps with wp_slash too) — without it the JSON's escaped quotes
		// lose their backslashes in content_save_pre and the stored settings
		// no longer parse.
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'wp_font_family',
					'post_status'  => 'publish',
					'post_title'   => $family,
					'post_name'    => $slug,
					'post_content' => (string) wp_json_encode(
						array(
							'fontFamily' => $font_family,
							'preview'    => '',
						)
					),
				)
			)
		);
		return (int) $post_id;
	}

	/**
	 * Whether a face for this family + weight already exists (core face slug).
	 *
	 * @param int    $family_id Parent family post id.
	 * @param string $family    Family name.
	 * @param int    $weight    Font weight.
	 * @return bool
	 */
	private function face_exists( $family_id, $family, $weight ) {
		$title = \WP_Font_Utils::get_font_face_slug( $this->face_settings( $family, $weight, '' ) );
		$posts = get_posts(
			array(
				'post_type'      => 'wp_font_face',
				'post_parent'    => $family_id,
				'post_status'    => 'publish',
				'title'          => $title,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return ! empty( $posts );
	}

	/**
	 * Create the wp_font_face post (core REST storage shape).
	 *
	 * @param int    $family_id Parent family post id.
	 * @param string $family    Family name.
	 * @param int    $weight    Font weight.
	 * @param string $url       Self-hosted woff2 URL.
	 * @return int New post id, 0 on failure.
	 */
	private function create_face_post( $family_id, $family, $weight, $url ) {
		$settings = $this->face_settings( $family, $weight, $url );
		$title    = \WP_Font_Utils::get_font_face_slug( $settings );

		// Slashed for the same reason as create_family_post.
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'wp_font_face',
					'post_parent'  => $family_id,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => sanitize_title( $title ),
					'post_content' => (string) wp_json_encode( $settings ),
				)
			)
		);
		return (int) $post_id;
	}

	/**
	 * Core-shaped font face settings.
	 *
	 * @param string $family Family name.
	 * @param int    $weight Font weight.
	 * @param string $url    Self-hosted woff2 URL ('' when only slugging).
	 * @return array{fontFamily:string,fontStyle:string,fontWeight:string,src?:string}
	 */
	private function face_settings( $family, $weight, $url ) {
		$settings = array(
			'fontFamily' => false !== strpos( $family, ' ' ) ? '"' . $family . '"' : $family,
			'fontStyle'  => 'normal',
			'fontWeight' => (string) $weight,
		);
		if ( '' !== $url ) {
			$settings['src'] = $url;
		}
		return $settings;
	}

	/**
	 * Output schema for the install response.
	 *
	 * @return array<string,mixed>
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'success' ),
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'data'    => array(
					'type'       => 'object',
					'properties' => array(
						'families'     => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'family'    => array( 'type' => 'string' ),
									'family_id' => array( 'type' => 'integer' ),
									'status'    => array( 'type' => 'string' ),
									'faces'     => array( 'type' => 'array' ),
								),
							),
						),
						'has_failures' => array( 'type' => 'boolean' ),
					),
				),
			),
		);
	}
}
