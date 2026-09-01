<?php
/**
 * Media Service - Logic for handling WordPress media
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Services;

use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Media_Service
 */
class Media_Service {

	/**
	 * Upload media from a base64 data URL string.
	 *
	 * @param string              $base64_data Raw base64 string (without data: prefix).
	 * @param string              $mime_type   MIME type (e.g. image/jpeg).
	 * @param array<string,mixed> $args Optional metadata (title, alt_text, etc.).
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public function upload_from_base64( $base64_data, $mime_type, $args = array() ) {
		if ( empty( $base64_data ) || empty( $mime_type ) ) {
			return new \WP_Error( 'invalid_data', 'Base64 data and MIME type are required.' );
		}

		// Only allow image MIME types.
		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		if ( ! in_array( $mime_type, $allowed_mimes, true ) ) {
			return new \WP_Error( 'invalid_mime', 'Unsupported file type. Only JPEG, PNG, GIF, and WebP images are allowed.' );
		}

		$decoded = base64_decode( $base64_data, true );
		if ( false === $decoded ) {
			return new \WP_Error( 'decode_failed', 'Failed to decode base64 data.' );
		}

		// 5 MB limit.
		if ( strlen( $decoded ) > 5 * 1024 * 1024 ) {
			return new \WP_Error( 'file_too_large', 'File exceeds the 5 MB limit.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ext      = $this->get_extension_from_mime( $mime_type );
		$filename = ! empty( $args['filename'] )
			? sanitize_file_name( Utils::to_str( $args['filename'] ) )
			: 'upload-' . time() . '.' . $ext;

		// Ensure extension.
		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			$filename .= '.' . $ext;
		}

		// Write to temp file.
		$temp_file = wp_tempnam( $filename );
		if ( ! $temp_file ) {
			return new \WP_Error( 'temp_failed', 'Failed to create temporary file.' );
		}

		// Write the decoded bytes via WP_Filesystem (no advisory locking needed —
		// $temp_file is a fresh wp_tempnam() path with no concurrent writers).
		/**
		 * Narrowed type for `$wp_filesystem`.
		 *
		 * @var \WP_Filesystem_Base|null $wp_filesystem
		 */
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $temp_file, $decoded, FS_CHMOD_FILE ) ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			return new \WP_Error( 'write_failed', 'Failed to write the uploaded file.' );
		}

		// Verify actual file content matches declared MIME type.
		$detected_mime = function_exists( 'mime_content_type' ) ? mime_content_type( $temp_file ) : null;
		if ( $detected_mime && $detected_mime !== $mime_type ) {
			wp_delete_file( $temp_file );
			return new \WP_Error( 'mime_mismatch', 'File content does not match the declared MIME type.' );
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$this->update_attachment_metadata( $attachment_id, $args );

		return $attachment_id;
	}

	/**
	 * Update attachment metadata.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $args Metadata arguments.
	 * @return void
	 */
	public function update_attachment_metadata( $attachment_id, $args ) {
		$post_data = array( 'ID' => $attachment_id );
		$updated   = false;

		if ( ! empty( $args['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( Utils::to_str( $args['title'] ) );
			$updated                 = true;
		}

		if ( ! empty( $args['caption'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( Utils::to_str( $args['caption'] ) );
			$updated                   = true;
		}

		if ( ! empty( $args['description'] ) ) {
			$post_data['post_content'] = sanitize_textarea_field( Utils::to_str( $args['description'] ) );
			$updated                   = true;
		}

		if ( $updated ) {
			wp_update_post( $post_data );
		}

		if ( ! empty( $args['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( Utils::to_str( $args['alt_text'] ) ) );
		}
	}

	/**
	 * Get extension from mime type.
	 *
	 * @param string $mime Mime type.
	 * @return string Extension.
	 */
	private function get_extension_from_mime( $mime ) {
		$map = array(
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/gif'       => 'gif',
			'image/webp'      => 'webp',
			'application/pdf' => 'pdf',
		);
		return $map[ $mime ] ?? 'bin';
	}
}
