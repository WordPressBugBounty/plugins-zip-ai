<?php
/**
 * Validator - Centralized schema-based validation and sanitation
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Validator
 */
class Validator {

	/**
	 * Validate and sanitize input against a JSON schema.
	 *
	 * @param array<string,mixed> $schema The JSON schema to validate against.
	 * @param array<string,mixed> $input  The raw input data.
	 * @return array<string,mixed>|\WP_Error Validated and sanitized data on success, WP_Error on failure.
	 */
	public static function validate( $schema, $input ) {
		$valid_data = array();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

		// Check for missing required fields.
		foreach ( $required as $field ) {
			$field = Utils::to_str( $field );
			if ( ! isset( $input[ $field ] ) ) {
				return new \WP_Error(
					'missing_required_field',
					sprintf( 'Missing required field: %s', $field )
				);
			}
		}

		// Validate and sanitize each property.
		foreach ( $properties as $field => $config ) {
			$field  = (string) $field;
			$config = is_array( $config ) ? $config : array();
			$value  = isset( $input[ $field ] ) ? $input[ $field ] : ( isset( $config['default'] ) ? $config['default'] : null );

			if ( null === $value ) {
				continue;
			}

			$type = isset( $config['type'] ) ? $config['type'] : 'string';

			switch ( $type ) {
				case 'integer':
					$value = Utils::to_int( $value );
					if ( isset( $config['minimum'] ) && $value < Utils::to_int( $config['minimum'] ) ) {
						return new \WP_Error( 'invalid_range', sprintf( '%s is below minimum %d', $field, Utils::to_int( $config['minimum'] ) ) );
					}
					if ( isset( $config['maximum'] ) && $value > Utils::to_int( $config['maximum'] ) ) {
						return new \WP_Error( 'invalid_range', sprintf( '%s is above maximum %d', $field, Utils::to_int( $config['maximum'] ) ) );
					}
					break;

				case 'boolean':
					$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
					break;

				case 'string':
					if ( isset( $config['enum'] ) && is_array( $config['enum'] ) && ! in_array( $value, $config['enum'], true ) ) {
						return new \WP_Error( 'invalid_enum', sprintf( 'Invalid value for %s. Expected one of: %s', $field, implode( ', ', array_map( array( Utils::class, 'to_str' ), $config['enum'] ) ) ) );
					}
					// Default string sanitation if not explicitly handled by the tool.
					// Note: Tools can still do their own more specific sanitation.
					break;

				case 'array':
					if ( ! is_array( $value ) ) {
						return new \WP_Error( 'invalid_type', sprintf( '%s must be an array', $field ) );
					}
					break;
			}

			$valid_data[ $field ] = $value;
		}

		return $valid_data;
	}
}
