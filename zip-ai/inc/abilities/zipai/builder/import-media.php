<?php
/**
 * Import Media — server-side ability used by the website builder in place of
 * the three wp-cli calls (`wp media import …`, `wp post get …`,
 * `wp post meta update _wp_attachment_image_alt …`) that are denylisted or
 * unsupported by RunWpCli's `verify_command_security`.
 *
 * Sideloads a remote image into the WordPress media library via WP core's
 * `media_sideload_image()` and `media_handle_sideload()` helpers. SSRF guards:
 * https:// only, public IP only, must content-type as image, capped at 25 MB.
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\Builder;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Ability: sideload a remote image URL into the media library.
 */
class ImportMedia extends Abstract_Ability {

	const MAX_BYTES = 26214400; // 25 MB.

	/**
	 * Configures the ability's id, label, description and metadata.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/import-media';
		$this->label       = 'Import Media';
		$this->description = 'Sideload a remote image URL (e.g. a stock photo from Unsplash or Pexels) into the WordPress media library and return its attachment { id, url }. Use this to bring an external image URL into the site before applying it to a block. For AI-generated images use core ai_generate_image instead.';
		$this->capability  = 'upload_files';
	}

	/**
	 * Returns the tool-type classification for this ability.
	 *
	 * @return string One of the Tool_Types constants.
	 */
	public function get_tool_type() {
		return Tool_Types::ACTION;
	}

	/**
	 * Returns the JSON Schema for this ability's input arguments.
	 *
	 * @return array<string,mixed> JSON Schema describing accepted arguments.
	 */
	public function get_input_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'url' ),
			'additionalProperties' => false,
			'properties'           => array(
				'url'      => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'Public https URL of the image. Internal/loopback IPs are refused.',
				),
				'title'    => array(
					'type'        => 'string',
					'description' => 'Optional attachment title.',
					'maxLength'   => 250,
				),
				'alt_text' => array(
					'type'        => 'string',
					'description' => 'Optional alt text — stored in the `_wp_attachment_image_alt` meta.',
					'maxLength'   => 500,
				),
			),
		);
	}

	/**
	 * Sideloads a remote image URL into the WordPress media library.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		// Validate the RAW URL first. `esc_url_raw` silently rewrites things
		// like `file://…` and `javascript:…` to '' which would otherwise
		// land in the "url is required" branch with a misleading error.
		$raw_url  = isset( $args['url'] ) && is_string( $args['url'] ) ? $args['url'] : '';
		$title    = isset( $args['title'] ) && is_string( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';
		$alt_text = isset( $args['alt_text'] ) && is_string( $args['alt_text'] ) ? sanitize_text_field( $args['alt_text'] ) : '';

		if ( '' === $raw_url ) {
			return Response::error( 'url is required.' );
		}

		$validation = $this->validate_remote_url( $raw_url );
		if ( is_wp_error( $validation ) ) {
			return Response::error( $validation->get_error_message() );
		}
		// Safe to esc_url_raw now — we've confirmed scheme/host/IP pass.
		$url = esc_url_raw( $raw_url );
		if ( '' === $url ) {
			return Response::error( 'url is not a valid URL.' );
		}

		// WP core helpers used below.
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// 1) Stream to a temp file with the size cap enforced mid-download
		// (download_url() has no streaming limit — it would write the full
		// body to disk before any size check could run).
		$tmp = wp_tempnam( $url );
		if ( ! $tmp ) {
			return Response::error( 'Could not create a temporary file.' );
		}
		// Cap + 1 so a transport that truncates at the limit (instead of
		// erroring) still leaves the file measurably over MAX_BYTES.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::MAX_BYTES + 1,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return Response::error( 'Download failed: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			wp_delete_file( $tmp );
			$reason = trim( wp_remote_retrieve_response_message( $response ) );
			return Response::error( 'Download failed: ' . ( '' !== $reason ? $reason : "HTTP {$status}" ) );
		}
		if ( filesize( $tmp ) > self::MAX_BYTES ) {
			wp_delete_file( $tmp );
			return Response::error( sprintf( 'Remote file is larger than %d bytes.', self::MAX_BYTES ) );
		}

		// 2) Verify it's actually an image and resolve a sideload-safe filename.
		$resolved = $this->resolve_image_file( $tmp, $url );
		if ( is_wp_error( $resolved ) ) {
			wp_delete_file( $tmp );
			return Response::error( $resolved->get_error_message() );
		}

		// 3) Hand off to media_handle_sideload — creates the attachment, returns ID.
		$file_array = array(
			'name'     => $resolved['name'],
			'tmp_name' => $tmp,
		);
		$post_data  = array();
		if ( '' !== $title ) {
			$post_data['post_title'] = $title;
		}
		$attachment_id = media_handle_sideload( $file_array, 0, '' !== $title ? $title : null, $post_data );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return Response::error( 'media_handle_sideload failed: ' . $attachment_id->get_error_message() );
		}

		// 4) Alt text.
		if ( '' !== $alt_text ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		$attachment_url = wp_get_attachment_url( (int) $attachment_id );
		if ( ! $attachment_url ) {
			// Roll back the just-created attachment so a URL-resolution failure
			// doesn't leave an orphan in the media library.
			wp_delete_attachment( (int) $attachment_id, true );
			return Response::error( 'Image was imported but its URL could not be resolved.' );
		}

		return Response::success(
			sprintf( 'Imported image into the media library (id %d).', (int) $attachment_id ),
			array(
				'id'    => (int) $attachment_id,
				'url'   => $attachment_url,
				'title' => $title,
				'alt'   => $alt_text,
				'mime'  => $resolved['type'],
			)
		);
	}

	/**
	 * Resolve the mime type and a sideload-safe filename for a downloaded file.
	 *
	 * The downloaded bytes are the authority: stock/CDN image URLs (Unsplash,
	 * dynamic image services) often have no extension in their path, and a URL
	 * extension can lie about the content. The URL filename is only a naming
	 * hint. Formats core cannot byte-sniff (SVG) fall back to the extension
	 * check, which also enforces the site's allowed mime types.
	 *
	 * @param string $tmp Path to the downloaded temp file.
	 * @param string $url Source URL — filename hint only, never trusted for type.
	 * @return array{type: string, name: string}|\WP_Error
	 */
	public function resolve_image_file( string $tmp, string $url ) {
		$url_path  = wp_parse_url( $url, PHP_URL_PATH );
		$path_name = is_string( $url_path ) ? basename( $url_path ) : '';

		$type = (string) wp_get_image_mime( $tmp );
		if ( '' !== $type ) {
			// Parity with the extension path: enforce the site's allowed mime
			// list here too, so a disallowed type fails with a precise error
			// instead of media_handle_sideload's generic one.
			if ( ! in_array( $type, get_allowed_mime_types(), true ) ) {
				return new \WP_Error(
					'disallowed_mime',
					sprintf( 'Image type "%s" is not allowed on this site.', $type )
				);
			}
			$ext = wp_get_default_extension_for_mime_type( $type );
			if ( ! $ext ) {
				return new \WP_Error(
					'unsupported_image',
					sprintf( 'Image type "%s" is not supported.', $type )
				);
			}
			$base = pathinfo( $path_name, PATHINFO_FILENAME );
			return array(
				'type' => $type,
				'name' => sanitize_file_name( ( '' !== $base ? $base : 'image' ) . '.' . $ext ),
			);
		}

		$mime_check = wp_check_filetype_and_ext( $tmp, $path_name );
		$ext_type   = (string) $mime_check['type'];
		if ( '' === $ext_type || 0 !== strpos( $ext_type, 'image/' ) ) {
			return new \WP_Error(
				'not_an_image',
				sprintf( 'URL does not resolve to an image (detected: "%s").', $ext_type )
			);
		}
		$proper_filename = (string) $mime_check['proper_filename'];
		return array(
			'type' => $ext_type,
			'name' => '' !== $proper_filename ? $proper_filename : $path_name,
		);
	}

	/**
	 * Reject URLs that wouldn't survive WP.org review. Returns true on success
	 * or a WP_Error on the first failed check.
	 *
	 * @param string $url Raw URL.
	 * @return true|\WP_Error
	 */
	private function validate_remote_url( string $url ) {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', 'url is not a valid URL.' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'invalid_url', 'url is missing scheme or host.' );
		}
		// HTTPS only — http allows MITM substitution; data:/file:/javascript: are obvious SSRF vectors.
		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return new \WP_Error( 'insecure_scheme', 'Only https:// URLs are accepted.' );
		}

		$host = strtolower( $parts['host'] );
		// Strip bracketed IPv6 form (parse_url returns "[::1]") so it lines up
		// with the literal IP we compare against.
		if ( '[' === $host[0] && str_ends_with( $host, ']' ) ) {
			$host = substr( $host, 1, -1 );
		}
		// Reject loopback hostnames outright (covers most local-dev SSRF).
		$blocked_hosts = array( 'localhost', '0.0.0.0', '127.0.0.1', '::1' );
		if ( in_array( $host, $blocked_hosts, true ) ) {
			return new \WP_Error( 'blocked_host', 'Internal hostnames are not allowed.' );
		}
		// If host is an IP literal, refuse private + link-local + loopback ranges.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new \WP_Error( 'private_ip', 'Private/reserved IPs are not allowed.' );
			}
		}
		return true;
	}

	/**
	 * Returns the JSON Schema for this ability's response.
	 *
	 * @return array<string,mixed> JSON Schema describing the response shape.
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
						'id'    => array( 'type' => 'integer' ),
						'url'   => array( 'type' => 'string' ),
						'title' => array( 'type' => 'string' ),
						'alt'   => array( 'type' => 'string' ),
						'mime'  => array( 'type' => 'string' ),
					),
				),
			),
		);
	}
}
