<?php
/**
 * ZIP AI - Utils.
 *
 * This file contains all the utility functions of ZIP AI.
 * Utilities manipulate data and perform actions that are not directly related to the library.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Utils Class.
 */
class Utils {

	/**
	 * Option name for the per-site key salt. A random 32-byte value (base64)
	 * that forms HALF of the encryption-key input — the other half is
	 * `wp_salt()`, which lives in wp-config.php (filesystem), not the
	 * database. An attacker therefore needs BOTH a database dump (this salt +
	 * the ciphertext) AND filesystem access (wp-config salts) to decrypt;
	 * neither surface alone is sufficient.
	 *
	 * @var string
	 */
	const KEY_SALT_OPTION = 'zipwp_mcp_key_salt';

	/**
	 * Coerce a mixed value to a string (scalars cast; anything else falls back).
	 *
	 * Single source of truth for the scalar-coercion pattern used across the
	 * plugin when reading untyped input (decoded JSON, request args, options).
	 *
	 * @param mixed  $value   Raw value.
	 * @param string $default Fallback when the value is not a scalar.
	 * @return string
	 */
	public static function to_str( $value, string $default = '' ): string {
		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Coerce a mixed value to an int (scalars cast; anything else falls back).
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $default Fallback when the value is not a scalar.
	 * @return int
	 */
	public static function to_int( $value, int $default = 0 ): int {
		return is_scalar( $value ) ? (int) $value : $default;
	}

	/**
	 * Log a developer diagnostic, gated on WP_DEBUG so production logs stay
	 * quiet. ONE place for the WP_DEBUG gate, the `[zip-ai]` prefix, and the
	 * error_log phpcs allowance — call sites pass a context and the raw detail
	 * (an exception / WP_Error message) that must NEVER be returned to the client.
	 *
	 * @param string $context Human context, e.g. 'Media upload failed'.
	 * @param string $detail  Raw diagnostic message. Optional.
	 * @return void
	 */
	public static function debug_log( string $context, string $detail = '' ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$line = '' === $detail ? $context : $context . ': ' . $detail;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated developer diagnostic; centralized so call sites don't repeat the gate.
		error_log( '[zip-ai] ' . $line );
	}

	/**
	 * Domain-separation context mixed into the HMAC key derivation. Versioned
	 * so the scheme can be rotated later without colliding with old values.
	 *
	 * @var string
	 */
	const KEY_DERIVATION_INFO = 'zip-ai-enc-v1';

	/**
	 * Prefix identifying sodium-encrypted values.
	 *
	 * @var string
	 */
	const ENCRYPTED_PREFIX = 'sodium:';

	/**
	 * Derive the 32-byte encryption key.
	 *
	 * The key is NOT stored anywhere. It is derived on demand with HMAC-SHA256
	 * as a PRF — `wp_salt('secure_auth')` is the HMAC key, and a versioned
	 * context plus a per-site salt form the message — over two independent
	 * secrets:
	 *
	 *  - `wp_salt('secure_auth')` — bound to the `SECURE_AUTH_KEY` /
	 *    `SECURE_AUTH_SALT` constants in wp-config.php (filesystem). The plugin
	 *    only READS these; it never needs to define them.
	 *  - a per-site random salt persisted in {@see self::KEY_SALT_OPTION}
	 *    (database).
	 *
	 * Splitting the secret across the filesystem and the database means a
	 * database-only compromise (SQL injection, a leaked backup, a read
	 * replica) cannot reconstruct the key — the attacker would also need the
	 * wp-config salts. This is the protection a DB-stored key cannot provide.
	 *
	 * Fails closed (returns '') when sodium/`wp_salt()` are unavailable or the
	 * CSPRNG cannot mint the salt — callers treat '' as "not stored" rather
	 * than fataling, matching {@see self::encrypt()} / {@see self::decrypt()}.
	 *
	 * @since 1.1.0
	 * @return string The 32-byte derived key, or '' when unavailable.
	 */
	private static function get_derived_key() {
		if ( ! function_exists( 'wp_salt' ) ) {
			return '';
		}

		try {
			$salt = get_option( self::KEY_SALT_OPTION );
			if ( ! is_string( $salt ) || '' === $salt ) {
				// First use on this site — mint the DB half of the secret.
				// add_option() will NOT clobber an existing value, so under a
				// concurrent first-use race the first writer wins; the re-read
				// below settles every racer on that same persisted salt.
				add_option( self::KEY_SALT_OPTION, base64_encode( random_bytes( 32 ) ), '', false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$salt = get_option( self::KEY_SALT_OPTION );
				if ( ! is_string( $salt ) || '' === $salt ) {
					return '';
				}
			}

			// Raw 32-byte output = SODIUM_CRYPTO_SECRETBOX_KEYBYTES.
			return hash_hmac(
				'sha256',
				self::KEY_DERIVATION_INFO . '|' . $salt,
				wp_salt( 'secure_auth' ),
				true
			);
		} catch ( \Exception $e ) {
			// random_bytes() can throw when the platform CSPRNG is unavailable.
			return '';
		}
	}

	/**
	 * Encrypt data using sodium_crypto_secretbox under the derived key.
	 *
	 * @param string $input The input string which needs to be encrypted.
	 * @since 1.0.0
	 * @return string The encrypted string (prefixed with 'sodium:' and base64 encoded), or ''.
	 */
	public static function encrypt( $input ) {
		// If the input is empty, then abandon ship.
		if ( empty( $input ) ) {
			return '';
		}

		// Check if sodium is available.
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}

		$key = self::get_derived_key();
		if ( '' === $key ) {
			return '';
		}

		try {
			// Generate a random nonce.
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			// Encrypt the data.
			$ciphertext = sodium_crypto_secretbox( $input, $nonce, $key );

			// Combine nonce + ciphertext and encode.
			$encrypted = base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

			// Add prefix to identify this as sodium-encrypted.
			return self::ENCRYPTED_PREFIX . $encrypted;
		} catch ( \Exception $e ) {
			// If encryption fails, return empty.
			return '';
		}
	}

	/**
	 * Decrypt data using sodium_crypto_secretbox under the derived key.
	 *
	 * @param string $input The input string which needs to be decrypted.
	 * @since 1.0.0
	 * @return string The decrypted string.
	 */
	public static function decrypt( $input ) {
		// If the input is empty, then abandon ship.
		if ( empty( $input ) ) {
			return '';
		}

		// Check if this is a sodium-encrypted value.
		if ( strpos( $input, self::ENCRYPTED_PREFIX ) !== 0 ) {
			return '';
		}

		// Check if sodium is available.
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}

		$key = self::get_derived_key();
		if ( '' === $key ) {
			return '';
		}

		try {
			// Remove prefix and decode.
			$encrypted = substr( $input, strlen( self::ENCRYPTED_PREFIX ) );
			$decoded   = base64_decode( $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			// Extract nonce and ciphertext.
			$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			if ( strlen( $nonce ) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			// Decrypt.
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

			if ( false === $plaintext ) {
				// Decryption failed (wrong key or corrupted data).
				return '';
			}

			return $plaintext;
		} catch ( \Exception $e ) {
			return '';
		}
	}
}
