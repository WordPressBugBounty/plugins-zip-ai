<?php
/**
 * Install Fonts — self-hosts Google-served webfonts into the WP Font Library.
 *
 * A21 (render-interference inventory): authored pages declare webfonts the
 * import flow never installs, so weights the page uses render synthesized
 * (advance drift up to +17%) and visitor machines without local fonts degrade
 * to fallback families. This ability requests the page's own css2 spec for a
 * family, downloads every woff2 face Google serves for it server-side and
 * registers each, with its own style, weight range, stretch and unicode-range,
 * as core `wp_font_family` / `wp_font_face` posts — the native Font Library
 * store the Site Editor already understands.
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
		$this->description = 'Download Google Font families (every woff2 face of the requested css2 spec, or of the requested weights) and register them in the WordPress Font Library. Idempotent per face.';

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
					'description' => 'Families to install, each with the css2 spec the page requests or the numeric weights it uses.',
					'items'       => array(
						'type'       => 'object',
						'required'   => array( 'family' ),
						'properties' => array(
							'family'  => array(
								'type'        => 'string',
								'description' => 'Font family name as in the Google Fonts catalog (e.g. "Lora").',
							),
							'weights' => array(
								'type'        => 'array',
								'description' => 'Numeric font weights (100–900), requested when there is no spec.',
								'items'       => array( 'type' => 'integer' ),
							),
							'spec'    => array(
								'type'        => 'string',
								'description' => 'Optional css2 axis spec the page requests for the family, verbatim (e.g. "ital,opsz,wght@0,7..72,200..900;1,7..72,200..900"). Present installs every face it serves.',
							),
							'stack'   => array(
								'type'        => 'string',
								'description' => 'Optional font stack the page declares for the family (e.g. "\'Literata\', serif"), kept as the family\'s fontFamily so its fallbacks hold.',
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
	 * @param array<string,mixed> $args Ability arguments ({families:[{family, weights[], spec?, stack?}]}).
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

				// The spec rides the css2 URL as written: axis tags, then numeric tuples only.
				$spec = isset( $entry['spec'] ) && is_string( $entry['spec'] ) && preg_match( '/^[A-Za-z]+(?:,[A-Za-z]+)*@[-0-9.,;]+$/', $entry['spec'] ) ? $entry['spec'] : '';
				// A stack is kept only when it leads with this family.
				$stack = isset( $entry['stack'] ) && is_string( $entry['stack'] ) ? \WP_Font_Utils::sanitize_font_family( $entry['stack'] ) : '';
				if ( 0 !== strcasecmp( trim( explode( ',', $stack )[0], " \"'" ), $family ) ) {
					$stack = '';
				}

				if ( '' === $family || ( empty( $weights ) && '' === $spec ) ) {
					$results[]   = array(
						'family' => $family,
						'status' => 'failed',
						'error'  => 'family and a spec or at least one valid weight (100-900) are required.',
						'faces'  => array(),
					);
					$any_failure = true;
					continue;
				}

				$row       = $this->install_family( $family, '' !== $spec ? $spec : 'wght@' . implode( ';', $weights ), $stack );
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

			self::announce_ready( $results );

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
	 * An install that found every family present saves no post, so the Font
	 * Library is never re-activated for the active theme (a theme switch leaves
	 * the new theme's global styles without it). `zipai_font_library_ready`
	 * names the families this call installed or found — `string[]` of
	 * `wp_font_family` post names — and Spectra's bridge activates exactly those.
	 *
	 * @param array<int, array<string, mixed>> $results Per-family result rows.
	 * @return void
	 */
	public static function announce_ready( array $results ): void {
		$ready = array();
		foreach ( $results as $r ) {
			if ( isset( $r['status'], $r['slug'] ) && 'failed' !== $r['status'] && is_string( $r['slug'] ) && '' !== $r['slug'] ) {
				$ready[] = $r['slug'];
			}
		}
		if ( empty( $ready ) ) {
			return;
		}
		try {
			do_action( 'zipai_font_library_ready', $ready );
		} catch ( \Throwable $e ) {
			// A listener's failure never turns a finished install into a failed one.
			unset( $e );
		}
	}

	/**
	 * Install one family: fetch its css2 stylesheet, download every woff2 face
	 * it serves into the font directory, create the Library CPT entries.
	 *
	 * @param string $family Family name.
	 * @param string $spec   css2 axis spec (e.g. "ital,wght@0,400;1,400").
	 * @param string $stack  Sanitized font stack led by the family, or '' for the name alone.
	 * @return array<string,mixed> Per-family result row.
	 */
	private function install_family( $family, $spec, $stack = '' ) {
		$slug      = sanitize_title( $family );
		$name      = false !== strpos( $family, ' ' ) ? '"' . $family . '"' : $family;
		$family_id = $this->find_family_post( $slug );
		$faces     = array();
		$created   = 0;

		$css = $this->fetch_css2( $family, $spec );
		if ( is_wp_error( $css ) ) {
			return array(
				'family' => $family,
				'slug'   => $slug,
				'status' => 'failed',
				'error'  => $css->get_error_message(),
				'faces'  => array(),
			);
		}

		$sources = self::parse_faces( $css );
		if ( empty( $sources ) ) {
			return array(
				'family' => $family,
				'slug'   => $slug,
				'status' => 'failed',
				'error'  => 'No woff2 sources found in the Google css2 response.',
				'faces'  => array(),
			);
		}

		$font_dir = wp_font_dir();
		if ( ! empty( $font_dir['error'] ) ) {
			return array(
				'family' => $family,
				'slug'   => $slug,
				'status' => 'failed',
				'error'  => 'Font directory unavailable: ' . $font_dir['error'],
				'faces'  => array(),
			);
		}

		$font_family = '' !== $stack ? $stack : $name;
		if ( $family_id > 0 && '' !== $stack ) {
			$this->update_family_stack( $family_id, $stack );
		}

		$files = array();
		foreach ( $sources as $source ) {
			$settings = array( 'fontFamily' => $name ) + $source['settings'];
			$title    = \WP_Font_Utils::get_font_face_slug( $settings );

			// Idempotency: face posts are deduped by core's face slug
			// (family;style;weight;stretch;range) under the same parent.
			if ( $family_id > 0 && $this->face_exists( $family_id, $title ) ) {
				$faces[] = array(
					'face'   => $title,
					'status' => 'existed',
				);
				continue;
			}

			$file = $files[ $source['src'] ] ?? $this->download_face_file( $source['src'], $slug, $font_dir );
			if ( is_wp_error( $file ) ) {
				$faces[] = array(
					'face'   => $title,
					'status' => 'failed',
					'error'  => $file->get_error_message(),
				);
				continue;
			}
			$files[ $source['src'] ] = $file;

			if ( $family_id <= 0 ) {
				$family_id = $this->create_family_post( $family, $slug, $font_family );
				if ( $family_id <= 0 ) {
					$faces[] = array(
						'face'   => $title,
						'status' => 'failed',
						'error'  => 'Could not create the wp_font_family post.',
					);
					continue;
				}
			}

			$face_id = $this->create_face_post( $family_id, $title, $settings + array( 'src' => $file['url'] ) );
			if ( $face_id <= 0 ) {
				$faces[] = array(
					'face'   => $title,
					'status' => 'failed',
					'error'  => 'Could not create the wp_font_face post.',
				);
				continue;
			}

			$faces[]  = array(
				'face'   => $title,
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
			'slug'      => $slug,
			'family_id' => $family_id,
			'status'    => $status,
			'faces'     => $faces,
		);
	}

	/**
	 * Fetch the Google css2 stylesheet for a family + axis spec with a woff2 UA.
	 *
	 * @param string $family Family name.
	 * @param string $spec   css2 axis spec (e.g. "ital,wght@0,400;1,400").
	 * @return string|\WP_Error CSS body.
	 */
	private function fetch_css2( $family, $spec ) {
		$url = 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $family ) . ':' . $spec . '&display=swap';

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
	 * Every woff2 `@font-face` of a css2 body: its remote URL and its
	 * descriptors as core face settings (`font-style` → `fontStyle`, and so on
	 * for weight, stretch, unicode-range, display), latin and latin-ext first.
	 *
	 * @param string $css css2 response body.
	 * @return list<array{subset:string,src:string,settings:array<string,string>}>
	 */
	public static function parse_faces( $css ) {
		$faces = array();
		// Pair each optional subset comment with its @font-face block.
		preg_match_all( '#(?:/\*\s*([a-z0-9-]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}#i', $css, $blocks, PREG_SET_ORDER );
		foreach ( $blocks as $block ) {
			if ( ! preg_match( '/src\s*:[^;]*url\(\s*[\'"]?([^)\'"]+\.woff2)/i', $block[2], $src ) ) {
				continue;
			}
			$settings = array();
			foreach ( explode( ';', $block[2] ) as $declaration ) {
				$parts = explode( ':', $declaration, 2 );
				$prop  = strtolower( trim( $parts[0] ) );
				if ( 2 === count( $parts ) && '' !== $prop && 'src' !== $prop && 'font-family' !== $prop ) {
					$settings[ lcfirst( str_replace( '-', '', ucwords( $prop, '-' ) ) ) ] = trim( $parts[1] );
				}
			}
			$faces[] = array(
				'subset'   => strtolower( $block[1] ),
				'src'      => $src[1],
				'settings' => $settings,
			);
		}
		// shortcut: spectra-blocks' Font Library activation reads the first 50 faces of a family
		// (by post ID), so the subsets a page most likely shows are created first; drop this once it reads all.
		$rank = array(
			'latin'     => 0,
			'latin-ext' => 1,
		);
		usort( $faces, static fn ( $a, $b ) => ( $rank[ $a['subset'] ] ?? 2 ) <=> ( $rank[ $b['subset'] ] ?? 2 ) );
		return $faces;
	}

	/**
	 * Download one woff2 into the fonts directory under a deterministic name.
	 *
	 * @param string              $src      Remote woff2 URL.
	 * @param string              $slug     Family slug.
	 * @param array<string,mixed> $font_dir wp_font_dir() array.
	 * @return array{path:string,url:string}|\WP_Error { path, url } on success.
	 */
	private function download_face_file( $src, $slug, $font_dir ) {
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
		// Google names each file for its contents, so the name is stable per face.
		$filename = sanitize_file_name( $slug . '-' . basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) );
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
	 * @param string $family      Family name.
	 * @param string $slug        Family slug.
	 * @param string $font_family The CSS font-family value (the page's stack, or the name).
	 * @return int New post id, 0 on failure.
	 */
	private function create_family_post( $family, $slug, $font_family ) {
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
	 * Point an installed family's fontFamily at the page's stack, so the
	 * preset the theme reads keeps the page's fallbacks.
	 *
	 * @param int    $family_id Family post id.
	 * @param string $stack     Sanitized font stack.
	 * @return void
	 */
	private function update_family_stack( $family_id, $stack ) {
		$post     = get_post( $family_id );
		$settings = $post instanceof \WP_Post ? json_decode( $post->post_content, true ) : null;
		if ( ! is_array( $settings ) || ( $settings['fontFamily'] ?? '' ) === $stack ) {
			return;
		}
		$settings['fontFamily'] = $stack;
		// Slashed for the same reason as create_family_post.
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $family_id,
					'post_content' => (string) wp_json_encode( $settings ),
				)
			)
		);
	}

	/**
	 * Whether a face with this core face slug already exists under the family.
	 *
	 * @param int    $family_id Parent family post id.
	 * @param string $title     Core face slug (`WP_Font_Utils::get_font_face_slug`).
	 * @return bool
	 */
	private function face_exists( $family_id, $title ) {
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
	 * @param int                  $family_id Parent family post id.
	 * @param string               $title     Core face slug.
	 * @param array<string,string> $settings  Core face settings, src included.
	 * @return int New post id, 0 on failure.
	 */
	private function create_face_post( $family_id, $title, $settings ) {
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
									'slug'      => array( 'type' => 'string' ),
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
