<?php
/**
 * Upload Media — accepts base64 image data, saves to WP media library.
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

class UploadMedia extends Abstract_Ability {

	/**
	 * Configures the ability's id, label, description and metadata.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/upload-media';
		$this->label       = 'Upload Media';
		$this->description = 'Upload a base64-encoded image to the WordPress media library. Returns the attachment URL and ID.';

		// `upload_files`, not the framework default `edit_posts` — this writes a
		// real file through wp_upload_bits(). WP separates the two caps on
		// purpose (a Contributor has edit_posts but not upload_files). Matches
		// the sibling `zipai/import-media` and the AJAX twin.
		$this->capability = 'upload_files';

		// Hidden from the agent's tool menu: it takes BASE64, not a URL, so the
		// LLM mis-picks it for stock URLs (→ "base64 is required" failures). It
		// stays registered — the AI-image tool and the site-builder call it
		// directly by name. The agent's URL path is `zipai/import-media`.
		$this->meta['visibility'] = 'internal';
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
			'type'       => 'object',
			'required'   => array( 'base64', 'filename' ),
			'properties' => array(
				'base64'   => array(
					'type'        => 'string',
					'description' => 'Base64-encoded image data (no data: prefix).',
				),
				'filename' => array(
					'type'        => 'string',
					'description' => 'Filename with extension (e.g. "ai-generated-123.png").',
				),
				'alt_text' => array(
					'type'        => 'string',
					'description' => 'Alt text for the image.',
				),
				'title'    => array(
					'type'        => 'string',
					'description' => 'Image title.',
				),
			),
		);
	}

	/**
	 * Decodes base64 image data and saves it to the WordPress media library.
	 *
	 * @param array{base64?:string,filename?:string,alt_text?:string,title?:string} $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		try {
			$base64   = (string) ( $args['base64'] ?? '' );
			$filename = sanitize_file_name( (string) ( $args['filename'] ?? 'upload.png' ) );
			$alt_text = sanitize_text_field( (string) ( $args['alt_text'] ?? '' ) );
			$title    = sanitize_text_field( (string) ( $args['title'] ?? $filename ) );

			if ( empty( $base64 ) ) {
				return Response::error( 'base64 data is required.' );
			}

			// Strip data URI prefix if present.
			if ( strpos( $base64, 'base64,' ) !== false ) {
				$base64 = substr( $base64, strpos( $base64, 'base64,' ) + 7 );
			}

			// Decode.
			$decoded = base64_decode( $base64, true );
			if ( ! $decoded || strlen( $decoded ) < 100 ) {
				return Response::error( 'Invalid or empty base64 data (decoded ' . strlen( (string) $decoded ) . ' bytes).' );
			}

			// Determine mime type from decoded data.
			$finfo = new \finfo( FILEINFO_MIME_TYPE );
			$mime  = $finfo->buffer( $decoded );
			if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) {
				$mime = 'image/png'; // fallback
			}

			// Load required WP functions.
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			// Write to uploads directory.
			if ( '' === $filename ) {
				$filename = 'upload.png';
			}
			$upload = wp_upload_bits( $filename, null, $decoded );
			if ( ! empty( $upload['error'] ) ) {
				return Response::error( 'wp_upload_bits failed: ' . $upload['error'] );
			}

			// Create attachment post.
			$attachment = array(
				'post_mime_type' => $mime,
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'guid'           => $upload['url'],
			);

			$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
			// Called without the $wp_error flag, wp_insert_attachment returns 0
			// (not a WP_Error) when the DB insert fails — guard the falsy id so a
			// failed insert can't be reported as a successful upload.
			if ( ! $attach_id ) {
				return Response::error( 'wp_insert_attachment failed: the attachment could not be created.' );
			}

			// Generate thumbnails — wrapped in try/catch since GD may not be available.
			try {
				$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
				if ( ! empty( $metadata ) ) {
					wp_update_attachment_metadata( $attach_id, $metadata );
				}
			} catch ( \Throwable $e ) {
				// Non-fatal — image still usable without thumbnails.
			}

			// Set alt text.
			if ( $alt_text ) {
				update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt_text );
			}

			$url = wp_get_attachment_url( $attach_id );
			if ( ! $url ) {
				// Roll back the just-created attachment so a URL-resolution
				// failure doesn't leave an orphan in the media library.
				wp_delete_attachment( $attach_id, true );
				return Response::error( 'Attachment was created but its URL could not be resolved.' );
			}

			return Response::success(
				'Image uploaded to media library.',
				array(
					'id'  => $attach_id,
					'url' => $url,
					'alt' => $alt_text,
				)
			);
		} catch ( \Throwable $e ) {
			return Response::error( 'Upload exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Output schema for the successful upload response.
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
						'id'  => array( 'type' => 'integer' ),
						'url' => array( 'type' => 'string' ),
						'alt' => array( 'type' => 'string' ),
					),
				),
			),
		);
	}
}
