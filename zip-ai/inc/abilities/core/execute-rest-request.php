<?php
/**
 * Execute REST Request Ability
 *
 * Generic REST proxy via rest_do_request(). WordPress handles ALL auth/permissions
 * via each route's permission_callback.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Core;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\RouteSchemaBuilder;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ExecuteRestRequest
 */
class ExecuteRestRequest extends Abstract_Ability {

	/**
	 * Maximum batch size (matches WP core rest_get_max_batch_size()).
	 *
	 * @var int
	 */
	const MAX_BATCH_SIZE = 25;

	/**
	 * Maximum response items to prevent context window overflow.
	 *
	 * @var int
	 */
	const MAX_RESPONSE_ITEMS = 100;

	/**
	 * Is destructive.
	 *
	 * @var bool
	 */
	protected $is_destructive = true;

	/**
	 * Every WordPress read and write the agent performs funnels through this one
	 * proxy — a multi-page website build spends hundreds of calls in a minute
	 * (page creates, style guide, templates, chrome, meta, site defaults), so the
	 * interactive default would throttle a legitimate build mid-flight.
	 *
	 * @var int
	 */
	protected $rate_limit = 1000;

	/**
	 * Configure the ability.
	 */
	public function configure() {
		$this->id          = 'zipai/run-rest-request';
		$this->label       = 'Execute REST API Request';
		$this->description = 'The primary tool for reading and writing WordPress data via the REST API. '
			. 'Supports GET, POST, PUT, PATCH, DELETE against any registered route — posts, pages, users, '
			. 'terms, plugins, settings, media, custom post types, and plugin-registered routes. '
			. 'WordPress enforces its own permission_callback per route. '
			. 'Supports batching up to 25 requests in a single call. '
			. 'Use search-endpoints to discover route names and required params before calling.';
		// WordPress REST endpoints enforce their own permission_callback per-route.
		// This tool is a generic proxy — use edit_posts (same as other zipwp tools).
		$this->capability = 'edit_posts';

		// Hidden from the LLM tool list but kept registered for internal
		// server-side callers. The server filters tools where
		// meta.visibility === 'internal' before exposing them to the model.
		$this->meta['visibility'] = 'internal';
	}

	/**
	 * Get tool type.
	 *
	 * @return string
	 */
	public function get_tool_type() {
		return Tool_Types::ACTION;
	}

	/**
	 * Get input schema.
	 *
	 * @return array<string,mixed>
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'method'   => array(
					'type'        => 'string',
					'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
					'default'     => 'GET',
					'description' => 'HTTP method.',
				),
				'route'    => array(
					'type'        => 'string',
					'description' => 'REST route (e.g. "/wp/v2/posts/42"). No /wp-json prefix.',
				),
				'params'   => array(
					'type'        => 'object',
					'description' => 'Query params for GET, body params for POST/PUT/PATCH/DELETE.',
				),
				'headers'  => array(
					'type'        => 'object',
					'description' => 'Additional HTTP headers.',
				),
				'requests' => array(
					'type'        => 'array',
					'description' => 'Batch: array of {method, route, params}. Max 25. Ignores top-level method/route/params.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'method' => array( 'type' => 'string' ),
							'route'  => array( 'type' => 'string' ),
							'params' => array( 'type' => 'object' ),
						),
					),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get examples.
	 *
	 * @return list<string>
	 */
	public function get_examples() {
		return array(
			'run REST request to get posts',
			'execute REST API call',
			'call WordPress REST endpoint',
			'delete post via REST',
			'batch REST requests',
		);
	}

	// check_permission() intentionally NOT overridden — uses base class
	// which checks $this->capability ('edit_posts'). Individual REST endpoints
	// enforce their own permission_callback via rest_do_request().

	/**
	 * Dry run implementation — probes route without executing.
	 *
	 * @param array<string,mixed> $args Input arguments.
	 * @return array<string,mixed> Result array.
	 */
	protected function dry_run( $args ) {
		$batch = $args['requests'] ?? null;
		if ( is_array( $batch ) && ! empty( $batch ) ) {
			$results  = array();
			$requests = array_slice( $batch, 0, self::MAX_BATCH_SIZE );

			foreach ( $requests as $i => $req ) {
				$req    = is_array( $req ) ? $req : array();
				$method = strtoupper( sanitize_text_field( is_string( $req['method'] ?? null ) ? $req['method'] : 'GET' ) );
				$route  = sanitize_text_field( is_string( $req['route'] ?? null ) ? $req['route'] : '' );

				if ( empty( $route ) ) {
					$results[] = array(
						'index' => $i,
						'error' => 'Route is required.',
					);
					continue;
				}

				$results[] = $this->probe_request( $method, $route );
			}

			return Response::success(
				sprintf( 'Dry run: probed %d request(s).', count( $results ) ),
				array(
					'dry_run' => true,
					'results' => $results,
				)
			);
		}

		$method = strtoupper( sanitize_text_field( Utils::to_str( $args['method'] ?? 'GET', 'GET' ) ) );
		$route  = sanitize_text_field( Utils::to_str( $args['route'] ?? '' ) );

		if ( empty( $route ) ) {
			return Response::error( 'Route is required.', 'Provide a REST route like "/wp/v2/posts".' );
		}

		$probe = $this->probe_request( $method, $route );

		return Response::success(
			sprintf( 'Dry run: %s %s — %s.', $method, $route, Utils::to_str( $probe['permission'] ?? 'unknown', 'unknown' ) ),
			array(
				'dry_run' => true,
				'probe'   => $probe,
			)
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $args Input arguments.
	 * @return array<string,mixed> Result array.
	 */
	public function execute( $args ) {
		// Batch mode.
		$batch = $args['requests'] ?? null;
		if ( is_array( $batch ) && ! empty( $batch ) ) {
			/**
			 * List of batch request entries.
			 *
			 * @var array<int,mixed> $batch
			 */
			return $this->execute_batch( $batch );
		}

		// Single request mode.
		$method = strtoupper( sanitize_text_field( Utils::to_str( $args['method'] ?? 'GET', 'GET' ) ) );
		$route  = trim( Utils::to_str( wp_unslash( $args['route'] ?? '' ) ) );
		/**
		 * Request parameters.
		 *
		 * @var array<string,mixed> $params
		 */
		$params = is_array( $args['params'] ?? null ) ? $args['params'] : array();
		/**
		 * Request headers.
		 *
		 * @var array<string,mixed> $headers
		 */
		$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();

		if ( empty( $route ) ) {
			return Response::error( 'Route is required.', 'Provide a REST route like "/wp/v2/posts". Use search-endpoints to discover routes.' );
		}

		return $this->execute_single( $method, $route, $params, $headers );
	}

	/**
	 * Execute a single REST request.
	 *
	 * @param string                 $method  HTTP method.
	 * @param string                 $route   REST route.
	 * @param array<array-key,mixed> $params  Request parameters.
	 * @param array<array-key,mixed> $headers Request headers.
	 * @return array<string,mixed> Result array.
	 */
	private function execute_single( $method, $route, $params = array(), $headers = array() ) {
		/**
		 * Filter whether to allow this REST request.
		 *
		 * Return WP_Error or false to block. Return true to allow.
		 *
		 * @param bool   $allowed Whether the request is allowed.
		 * @param string $method  HTTP method.
		 * @param string $route   REST route.
		 * @param array  $params  Request parameters.
		 */
		/**
		 * Filter result, bool or WP_Error per the contract above.
		 *
		 * @var mixed $allowed
		 */
		$allowed = apply_filters( 'zip_ai_allow_rest_request', true, $method, $route, $params );

		if ( is_wp_error( $allowed ) ) {
			return Response::from_wp_error( $allowed );
		}

		if ( false === $allowed ) {
			return Response::error(
				sprintf( 'Request blocked: %s %s.', $method, $route ),
				'This request was blocked by a site filter (zip_ai_allow_rest_request).'
			);
		}

		// Ensure route starts with /.
		if ( strpos( $route, '/' ) !== 0 ) {
			$route = '/' . $route;
		}

		// Build WP_REST_Request.
		$request = new \WP_REST_Request( $method, $route );

		if ( ! empty( $params ) ) {
			if ( 'GET' === $method ) {
				$request->set_query_params( $params );
			} else {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $params ) );
			}
		}

		// Set additional headers.
		if ( ! empty( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				$request->set_header( sanitize_text_field( (string) $key ), sanitize_text_field( Utils::to_str( $value ) ) );
			}
		}

		// Execute via rest_do_request() — WordPress handles permission checks.
		$response = rest_do_request( $request );

		// Surface defaults that WordPress silently applied — same schema source
		// as search-endpoints so both tools share the same knowledge.
		/**
		 * Defaults WordPress applied to the request.
		 *
		 * @var array<string,mixed> $applied_defaults
		 */
		$applied_defaults = ( 'GET' === $method )
			? RouteSchemaBuilder::get_applied_defaults( $route, $params )
			: array();

		return $this->format_response( $response, $method, $route, $applied_defaults );
	}

	/**
	 * Execute batch REST requests.
	 *
	 * @param array<array-key,mixed> $requests Array of {method, route, params}.
	 * @return array<string,mixed> Result array.
	 */
	private function execute_batch( $requests ) {
		if ( count( $requests ) > self::MAX_BATCH_SIZE ) {
			return Response::error(
				sprintf( 'Batch too large: %d requests (max %d).', count( $requests ), self::MAX_BATCH_SIZE ),
				'Split into smaller batches of ' . self::MAX_BATCH_SIZE . ' or fewer.'
			);
		}

		$results   = array();
		$succeeded = 0;
		$failed    = 0;

		foreach ( $requests as $i => $req ) {
			$req    = is_array( $req ) ? $req : array();
			$method = strtoupper( sanitize_text_field( Utils::to_str( $req['method'] ?? 'GET', 'GET' ) ) );
			$route  = sanitize_text_field( Utils::to_str( $req['route'] ?? '' ) );
			/**
			 * Per-request parameters.
			 *
			 * @var array<string,mixed> $params
			 */
			$params = is_array( $req['params'] ?? null ) ? $req['params'] : array();

			if ( empty( $route ) ) {
				$results[] = array(
					'index'   => $i,
					'method'  => $method,
					'route'   => '',
					'success' => false,
					'error'   => 'Route is required.',
				);
				++$failed;
				continue;
			}

			$result = $this->execute_single( $method, $route, $params );

			$results[] = array(
				'index'  => $i,
				'method' => $method,
				'route'  => $route,
			) + $result;

			if ( ! empty( $result['success'] ) ) {
				++$succeeded;
			} else {
				++$failed;
			}
		}

		$total   = count( $requests );
		$message = sprintf( 'Batch complete: %d/%d succeeded, %d failed.', $succeeded, $total, $failed );
		$data    = array(
			'results'   => $results,
			'total'     => $total,
			'succeeded' => $succeeded,
			'failed'    => $failed,
		);

		// A batch with ANY failed sub-request is NOT a success. Reporting the
		// whole call as success let the model tell the user "done" when some
		// (or all) operations actually failed — a false confirmation. Surface
		// it as a failure carrying the per-item results so the model can see
		// exactly which requests to retry.
		//
		// Self-documenting retry guidance: a partial batch's succeeded items
		// ALREADY ran server-side. Re-running the whole batch on retry would
		// duplicate the non-idempotent ones (extra posts/pages/etc.). Tell the
		// caller to retry ONLY the failed items from `data.results`. (Pure
		// mitigation — the model still owns the retry; this just makes the
		// safe path explicit, the way other errors here carry a `suggestion`.)
		if ( $failed > 0 ) {
			$suggestion = $succeeded > 0
				? 'Partial success — the succeeded sub-requests already ran. Retry ONLY the items whose result shows "success": false in data.results; do NOT re-send the whole batch or the succeeded writes will run again and create duplicates.'
				: 'Retry the failed sub-requests after fixing each error in data.results.';
			return Response::error( $message, $suggestion, $data );
		}

		return Response::success( $message, $data );
	}

	/**
	 * Probe a REST request without executing.
	 *
	 * Uses route pattern matching against registered routes and probes
	 * the permission_callback directly.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @return array<string,mixed> Probe result.
	 */
	private function probe_request( $method, $route ) {
		// Ensure route starts with /.
		if ( strpos( $route, '/' ) !== 0 ) {
			$route = '/' . $route;
		}

		$server = rest_get_server();
		$routes = $server->get_routes();

		// Find matching route handler by testing regex patterns.
		$matched_handler = null;
		foreach ( $routes as $route_pattern => $handlers ) {
			$regex = '#^' . $route_pattern . '$#';
			if ( ! is_array( $handlers ) || ! preg_match( $regex, $route ) ) {
				continue;
			}

			// Find handler that supports the requested method.
			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) ) {
					continue;
				}
				$handler_methods = is_array( $handler['methods'] ) ? $handler['methods'] : array( Utils::to_str( $handler['methods'] ) => true );
				if ( isset( $handler_methods[ $method ] ) ) {
					$matched_handler = $handler;
					break 2;
				}
			}
		}

		if ( ! $matched_handler ) {
			return array(
				'method'     => $method,
				'route'      => $route,
				'exists'     => false,
				'permission' => 'unknown',
				'error'      => 'No matching route found.',
			);
		}

		// Probe permission.
		$permission = 'unknown';
		if ( isset( $matched_handler['permission_callback'] ) && is_callable( $matched_handler['permission_callback'] ) ) {
			try {
				$request     = new \WP_REST_Request( $method, $route );
				$perm_result = call_user_func( $matched_handler['permission_callback'], $request );
				if ( is_wp_error( $perm_result ) ) {
					$permission = 'denied';
				} else {
					$permission = $perm_result ? 'allowed' : 'denied';
				}
			} catch ( \Exception $e ) {
				$permission = 'unknown';
			} catch ( \Error $e ) {
				$permission = 'unknown';
			}
		}

		return array(
			'method'     => $method,
			'route'      => $route,
			'exists'     => true,
			'permission' => $permission,
		);
	}

	/**
	 * Format a WP_REST_Response for output.
	 *
	 * Extracts pagination headers and truncates large arrays.
	 *
	 * @param \WP_REST_Response      $response         REST response.
	 * @param string                 $method           HTTP method.
	 * @param string                 $route            REST route.
	 * @param array<array-key,mixed> $applied_defaults Defaults WordPress silently applied.
	 * @return array<string,mixed> Formatted result.
	 */
	private function format_response( $response, $method, $route, $applied_defaults = array() ) {
		$status = $response->get_status();
		$data   = $response->get_data();

		// Extract pagination headers.
		$headers    = $response->get_headers();
		$pagination = array();

		if ( isset( $headers['X-WP-Total'] ) ) {
			$pagination['total'] = (int) ( is_scalar( $headers['X-WP-Total'] ) ? $headers['X-WP-Total'] : 0 );
		}
		if ( isset( $headers['X-WP-TotalPages'] ) ) {
			$pagination['total_pages'] = (int) ( is_scalar( $headers['X-WP-TotalPages'] ) ? $headers['X-WP-TotalPages'] : 0 );
		}

		// Handle error responses.
		if ( $status >= 400 ) {
			$error_message = 'Request failed.';
			$suggestion    = '';

			if ( is_array( $data ) ) {
				if ( isset( $data['message'] ) ) {
					$error_message = Utils::to_str( $data['message'] );
				}
				if ( isset( $data['code'] ) ) {
					if ( 'rest_forbidden' === $data['code'] ) {
						$suggestion = 'Permission denied. The current user lacks the required capability for this endpoint.';
					} elseif ( 'rest_no_route' === $data['code'] ) {
						$suggestion = 'This route does not exist. Use search-endpoints to find the actual registered routes for this plugin — do NOT guess endpoint names.';
					}
				}
			}

			$result = array(
				'success'     => false,
				'error'       => sprintf( '%s %s → %d: %s', $method, $route, $status, $error_message ),
				'status_code' => $status,
			);

			if ( ! empty( $suggestion ) ) {
				$result['suggestion'] = $suggestion;
			}

			return $result;
		}

		// Suspicious 2xx with empty/null data on a write request — treat as failure.
		// Real CREATE/UPDATE/DELETE endpoints return the affected entity or { id, ... }.
		// An empty 200 on POST/PUT/PATCH/DELETE almost always means the handler bailed silently.
		$is_write = in_array( strtoupper( $method ), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
		$is_empty = ( null === $data || '' === $data || ( is_array( $data ) && empty( $data ) ) );
		if ( $is_write && $is_empty ) {
			return array(
				'success'     => false,
				'error'       => sprintf(
					'%s %s → %d but response body is empty. The endpoint accepted the request but returned nothing — the handler likely failed silently or the route does not actually create what you expected.',
					$method,
					$route,
					$status
				),
				'status_code' => $status,
				'suggestion'  => 'Verify by GET-ing the entity you tried to create. If it does not exist, the route is wrong. Use search-endpoints + read plugin source code to find the correct create endpoint.',
			);
		}

		// Truncate large array responses.
		$truncated = false;
		if ( is_array( $data ) && ! $this->is_assoc( $data ) && count( $data ) > self::MAX_RESPONSE_ITEMS ) {
			$total_items = count( $data );
			$data        = array_slice( $data, 0, self::MAX_RESPONSE_ITEMS );
			$truncated   = true;
		}

		$result_data = array(
			'status_code' => $status,
			'data'        => $data,
		);

		if ( ! empty( $pagination ) ) {
			$result_data['pagination'] = $pagination;
		}

		if ( $truncated ) {
			$result_data['truncated']         = true;
			$result_data['truncated_message'] = sprintf(
				'Response truncated to %d items (total: %d). Use pagination params (per_page, page) to get more.',
				self::MAX_RESPONSE_ITEMS,
				$total_items
			);
		}

		if ( ! empty( $applied_defaults ) ) {
			$result_data['applied_defaults'] = $applied_defaults;
		}

		$message = sprintf( '%s %s → %d OK.', $method, $route, $status );
		if ( ! empty( $applied_defaults ) ) {
			$parts = array();
			foreach ( $applied_defaults as $key => $val ) {
				$parts[] = $key . '=' . ( is_string( $val ) ? $val : wp_json_encode( $val ) );
			}
			$message .= ' Note: defaults applied: ' . implode( ', ', $parts ) . '.';
		}

		return Response::success( $message, $result_data );
	}

	/**
	 * Check if an array is associative.
	 *
	 * @param array<array-key,mixed> $arr Array to check.
	 * @return bool True if associative.
	 */
	private function is_assoc( $arr ) {
		if ( empty( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
