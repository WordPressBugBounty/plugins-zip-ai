<?php
/**
 * Route Schema Builder
 *
 * Shared normalizer: converts WP REST route handler args into clean JSON Schema
 * objects. Used by ExecuteRestRequest (execution) as the single source of truth.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RouteSchemaBuilder
 */
class RouteSchemaBuilder {

	/**
	 * JSON Schema keys to preserve. All WP-specific keys
	 * (sanitize_callback, validate_callback, context, arg_options, etc.) are stripped.
	 */
	private const SCHEMA_KEYS = array(
		'type',
		'description',
		'default',
		'enum',
		'items',
		'properties',
		'required',
		'anyOf',
		'oneOf',
		'allOf',
		'format',
		'pattern',
		'minimum',
		'maximum',
		'minItems',
		'maxItems',
		'uniqueItems',
		'additionalProperties',
	);

	/**
	 * Compute applied defaults for a GET request.
	 *
	 * Returns defaults WordPress silently applies when a param is omitted.
	 * Annotates status=publish with a warning when "any" is a valid value,
	 * so the caller knows drafts are invisible.
	 *
	 * @param string                  $route           REST route (e.g. "/wp/v2/pages").
	 * @param array<array-key, mixed> $explicit_params Params the caller explicitly provided.
	 * @return array<array-key, mixed>
	 */
	public static function get_applied_defaults( string $route, array $explicit_params ): array {
		$handler = self::find_route_handler( $route, 'GET' );
		if ( ! $handler ) {
			return array();
		}

		$applied = array();

		$args = isset( $handler['args'] ) && is_array( $handler['args'] ) ? $handler['args'] : array();

		foreach ( $args as $name => $config ) {
			if ( array_key_exists( $name, $explicit_params ) ) {
				continue;
			}
			if ( ! is_array( $config ) || ! isset( $config['default'] ) ) {
				continue;
			}

			$value = $config['default'];

			// Schema-driven warning: only emit when the schema actually includes
			// "any" as a valid value — prevents false warnings on custom routes.
			if ( 'status' === $name && 'publish' === $value ) {
				$schema = self::normalize_arg( $config );
				if ( self::schema_includes_any( $schema ) ) {
					$value = 'publish (WARNING: drafts/pending/private hidden — pass status=any to see all)';
				}
			}

			$applied[ $name ] = $value;
		}

		return $applied;
	}

	/**
	 * Recursively normalize a WP REST arg config into a clean JSON Schema object.
	 *
	 * Preserves all JSON Schema-relevant keys. Strips WP-specific callbacks
	 * (sanitize_callback, validate_callback, context, arg_options, etc.).
	 *
	 * @param array<array-key, mixed> $config WP REST arg config.
	 * @param int                     $depth  Recursion depth guard (max 6).
	 * @return array<array-key, mixed>
	 */
	public static function normalize_arg( array $config, int $depth = 0 ): array {
		if ( $depth > 6 ) {
			return $config;
		}

		$out = array();

		foreach ( self::SCHEMA_KEYS as $key ) {
			if ( ! array_key_exists( $key, $config ) ) {
				continue;
			}

			$val = $config[ $key ];

			if ( 'items' === $key && is_array( $val ) ) {
				$out[ $key ] = self::normalize_arg( $val, $depth + 1 );

			} elseif ( 'properties' === $key && is_array( $val ) ) {
				$props = array();
				foreach ( $val as $prop_name => $prop_schema ) {
					$props[ $prop_name ] = is_array( $prop_schema )
						? self::normalize_arg( $prop_schema, $depth + 1 )
						: $prop_schema;
				}
				$out[ $key ] = $props;

			} elseif ( in_array( $key, array( 'anyOf', 'oneOf', 'allOf' ), true ) && is_array( $val ) ) {
				$d           = $depth;
				$out[ $key ] = array_map(
					function ( $s ) use ( $d ) {
						return is_array( $s ) ? self::normalize_arg( $s, $d + 1 ) : $s;
					},
					$val
				);

			} elseif ( 'additionalProperties' === $key && is_array( $val ) ) {
				$out[ $key ] = self::normalize_arg( $val, $depth + 1 );

			} else {
				$out[ $key ] = $val;
			}
		}

		return $out;
	}

	/**
	 * Check whether a normalized schema includes "any" as a valid value.
	 *
	 * Handles top-level enum, items.enum (array types), and anyOf/oneOf branches.
	 * Used to gate the status=publish warning to only routes that actually support
	 * status=any (i.e., standard WP content endpoints).
	 *
	 * @param array<array-key, mixed> $schema Normalized schema from normalize_arg().
	 * @return bool
	 */
	public static function schema_includes_any( array $schema ): bool {
		// Top-level enum (e.g. string type with enum).
		if (
			isset( $schema['enum'] ) &&
			is_array( $schema['enum'] ) &&
			in_array( 'any', $schema['enum'], true )
		) {
			return true;
		}

		// Recurse into items sub-schema (covers items.enum, items.anyOf, etc.).
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			if ( self::schema_includes_any( $schema['items'] ) ) {
				return true;
			}
		}

		// Recurse through anyOf / oneOf / allOf combiners.
		foreach ( array( 'anyOf', 'oneOf', 'allOf' ) as $combiner ) {
			if ( ! empty( $schema[ $combiner ] ) && is_array( $schema[ $combiner ] ) ) {
				foreach ( $schema[ $combiner ] as $sub ) {
					if ( is_array( $sub ) && self::schema_includes_any( $sub ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Find the matching handler for a route + method.
	 *
	 * @param string $route  REST route (leading slash required).
	 * @param string $method HTTP method.
	 * @return array<array-key, mixed>|null
	 */
	public static function find_route_handler( string $route, string $method ): ?array {
		if ( strpos( $route, '/' ) !== 0 ) {
			$route = '/' . $route;
		}

		$server = rest_get_server();
		$routes = $server->get_routes();

		foreach ( $routes as $pattern => $handlers ) {
			if ( ! preg_match( '#^' . $pattern . '$#', $route ) ) {
				continue;
			}
			if ( ! is_array( $handlers ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) || empty( $handler['methods'] ) ) {
					continue;
				}
				$raw_methods = $handler['methods'];
				$methods     = is_array( $raw_methods )
					? $raw_methods
					: array( (string) ( is_scalar( $raw_methods ) ? $raw_methods : '' ) => true );
				if ( isset( $methods[ $method ] ) ) {
					return $handler;
				}
			}
		}

		return null;
	}
}
