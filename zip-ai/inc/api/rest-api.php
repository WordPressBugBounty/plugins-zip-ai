<?php
/**
 * REST API - Handle MCP tool execution via REST API
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Core\Utils;
use ZipAI\MCP\Classes\Security\Protected_Options_Filter;

// Explicit require — composer's classmap autoloader may not pick up
// newly-added files until `composer dump-autoload` runs in the install.
// Loading the filter file directly guarantees the class is available
// regardless of classmap freshness.
if ( ! class_exists( '\\ZipAI\\MCP\\Classes\\Security\\Protected_Options_Filter' ) ) {
	require_once dirname( __DIR__ ) . '/security/protected-options-filter.php';
}

/**
 * The REST_API Class.
 * Handles REST API endpoints for MCP tool execution.
 */
class REST_API {

	/**
	 * Capability floor for every route in this class — the MCP tool surface and
	 * the site-scan trigger. `Abstract_Ability::$capability` defaults to the same
	 * value so a per-ability omission can never grant more than the ingress.
	 *
	 * @var string
	 */
	public const INGRESS_CAPABILITY = 'manage_options';

	/**
	 * Constructor of this class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Install the protected-options filters at WP's pre_update_option_<key>
		// layer. This is the catch-all backstop for any code path — CLI, REST,
		// AJAX, custom-plugin endpoints, code snippets — that calls
		// update_option() while inside an MCP-bound request. The filters are
		// registered once; the actual refusal is gated on the per-request
		// `enter_mcp()` flag toggled below in `handle_mcp_request`.
		Protected_Options_Filter::install();
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		// Strict JSON-RPC 2.0 MCP Endpoint
		register_rest_route(
			'zip-ai/v1',
			'/mcp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_mcp_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Trigger site scan — sends raw site data to the server for memory enrichment.
		register_rest_route(
			'zip-ai/v1',
			'/site-scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_site_scan' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Single ingress point for all JSON-RPC 2.0 MCP requests.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function handle_mcp_request( $request ) {
		// Handle Authentication Context (from HTTP Headers/Session)
		$this->setup_user_context( $request );

		$body = $request->get_json_params();

		$raw_method = $body['method'] ?? null;
		$method     = is_string( $raw_method ) ? $raw_method : '';

		$raw_id = $body['id'] ?? null;
		$id     = ( is_int( $raw_id ) || is_string( $raw_id ) ) ? $raw_id : null;

		// JSON-RPC params object from the request body.
		/**
		 * Narrowed type for `$params`.
		 *
		 * @var array<string,mixed> $params
		 */
		$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

		if ( empty( $method ) ) {
			return $this->format_mcp_error( $id, -32600, 'Invalid Request: Missing method' );
		}

		// Mark this request as MCP-bound so the protected-options filters
		// installed via Protected_Options_Filter::install() refuse mutations
		// to site-critical keys (siteurl, home, template, …) regardless of
		// which ability-specific code path tries to write them. Cleared in
		// the `finally` block — `register_shutdown_function` is the safety
		// net for fatal-error paths.
		Protected_Options_Filter::enter_mcp();

		try {
			switch ( $method ) {
				case 'initialize':
					return $this->handle_initialize( $id );
				case 'tools/list':
					return $this->handle_tools_list( $id );
				case 'tools/call':
					return $this->handle_tools_call( $id, $params );
				case 'notifications/initialized':
					// Fire-and-forget notification, no response needed.
					return new \WP_REST_Response( null, 200 );
				default:
					return $this->format_mcp_error( $id, -32601, "Method not found: {$method}" );
			}
		} catch ( \Throwable $e ) {
			// Never send the exception text to the client — it can leak class
			// names, file paths, and DB errors. Keep the detail server-side
			// (WP_DEBUG) and return a static message; JSON-RPC callers branch on
			// the numeric code (-32000), not the prose.
			Utils::debug_log( sprintf( 'MCP dispatch failed for "%s"', $method ), $e->getMessage() );
			return $this->format_mcp_error( $id, -32000, 'Internal Server Error' );
		} finally {
			Protected_Options_Filter::exit_mcp();
		}
	}

	/**
	 * Handle MCP Initialization Protocol.
	 *
	 * @param string|int|null $id JSON-RPC request id.
	 * @return \WP_REST_Response
	 */
	private function handle_initialize( $id ) {
		return $this->format_mcp_response(
			$id,
			array(
				'protocolVersion' => '2024-11-05',
				'capabilities'    => array(
					'tools' => array(),
				),
				'serverInfo'      => array(
					'name'    => 'ZipWP WordPress MCP',
					'version' => '1.0.0',
				),
			)
		);
	}

	/**
	 * Handle MCP Tools List Protocol.
	 *
	 * @param string|int|null $id JSON-RPC request id.
	 * @return \WP_REST_Response
	 */
	private function handle_tools_list( $id ) {
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return $this->format_mcp_error( $id, -32001, 'Abilities API is not available' );
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( ! $registry instanceof \WP_Abilities_Registry ) {
			return $this->format_mcp_error( $id, -32001, 'Abilities API is not available' );
		}
		$abilities = $registry->get_all_registered();
		$tools     = array();

		// Exclude mcp-adapter/get-ability-info from the catalog. The client
		// already receives every tool's `inputSchema` in this same payload, so
		// runtime schema introspection is redundant — and this tool only adds
		// failure surface: it resolves names by canonical `namespace/name`, but
		// the client knows tools as `namespace__name`, so the argument never
		// resolves and the call returns a misleading "invalid permissions",
		// dead-ending arg-correction recovery. The client's own recovery policy
		// already steers AWAY from it (recoveryHints VALIDATION rule: "fix the
		// arguments, do NOT discover alternates"). discover-abilities and
		// execute-ability are KEPT — the client relies on them as the surface-
		// switch escape hatch for wp-cli-not-exposed errors.
		$excluded_meta_abilities = array(
			'mcp-adapter/get-ability-info',
		);

		foreach ( $abilities as $ability_name => $ability ) {
			$ability_display_name = $ability->get_name();
			$resolved_name        = $ability_display_name ? $ability_display_name : $ability_name;
			if ( in_array( $resolved_name, $excluded_meta_abilities, true ) ) {
				continue;
			}

			$input_schema = $ability->get_input_schema();

			$tool = array(
				'name'        => $resolved_name,
				'description' => $ability->get_description(),
				'inputSchema' => $input_schema ? $input_schema : array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			);

			// Expose output schema when declared — lets the model learn the tool's
			// response contract from the schema rather than prose.
			$output_schema = $ability->get_output_schema();
			if ( ! empty( $output_schema ) ) {
				$tool['outputSchema'] = $output_schema;
			}

			$label = $ability->get_label();
			if ( ! empty( $label ) ) {
				$tool['title'] = $label;
			}

			// Expose tool_type (read|write|list|search|action|delete) so the client's
			// classifier can set mutates_state from the source-of-truth annotation
			// instead of guessing from the tool name. Without this, the client falls
			// back to a verb-based heuristic that misclassifies tools like
			// read-type tools as writes, blocking legitimate reads
			// during plan/discover stages. WP_Ability core wrappers expose
			// `get_meta()`; Abstract_Ability instances also expose `get_tool_type()`.
			$tool_type = null;
			$meta      = $ability->get_meta();
			if ( ! empty( $meta['tool_type'] ) ) {
				$tool_type = $meta['tool_type'];
			}
			if ( null === $tool_type && method_exists( $ability, 'get_tool_type' ) ) {
				$tool_type = $ability->get_tool_type();
			}
			if ( ! empty( $tool_type ) ) {
				$tool['tool_type'] = $tool_type;
			}

			// MCP-spec safety annotations. Prefer the ability's own
			// declaration (theme abilities publish one via meta); otherwise
			// derive from tool_type. Without this key on the wire, a client
			// that gates confirmation on destructiveHint never gates ANY tool
			// here — the missing confirmation hop behind the
			// update-navigation styling-loss incident.
			$declared_annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : null;
			if ( null !== $declared_annotations ) {
				// destructiveHint is only meaningful when readOnlyHint is
				// false (MCP spec), so its default follows the readonly
				// declaration — a bare `readonly: true` must not emit the
				// contradictory readOnlyHint=true + destructiveHint=true.
				$declared_readonly   = (bool) ( $declared_annotations['readonly'] ?? false );
				$tool['annotations'] = array(
					'readOnlyHint'    => $declared_readonly,
					'destructiveHint' => (bool) ( $declared_annotations['destructive'] ?? ! $declared_readonly ),
					'idempotentHint'  => (bool) ( $declared_annotations['idempotent'] ?? $declared_readonly ),
				);
			} elseif ( ! empty( $tool_type ) ) {
				$is_read_shaped      = in_array( $tool_type, array( 'read', 'list', 'search' ), true );
				$tool['annotations'] = array(
					'readOnlyHint'    => $is_read_shaped,
					'destructiveHint' => ! $is_read_shaped,
					'idempotentHint'  => $is_read_shaped,
				);
			}

			// Read-only sub-action allowlist for multiplexed abilities (those
			// that route many operations through a single `action` enum).
			// Forwarded to the client so its writes-require-approval gate can
			// classify `action:"list"` on a generally-destructive tool as a
			// safe read. Empty when the ability doesn't declare any. The
			// registry returns a `WP_Ability` wrapper (not the original
			// subclass), so we read the allowlist from the meta map populated
			// by Abstract_Ability::register().
			$read_only_actions     = null;
			$ability_meta_for_read = $ability->get_meta();
			if ( ! empty( $ability_meta_for_read['read_only_actions'] ) && is_array( $ability_meta_for_read['read_only_actions'] ) ) {
				$read_only_actions = $ability_meta_for_read['read_only_actions'];
			}
			if ( null === $read_only_actions && method_exists( $ability, 'get_read_only_actions' ) ) {
				$read_only_actions = $ability->get_read_only_actions();
			}
			if ( is_array( $read_only_actions ) && ! empty( $read_only_actions ) ) {
				$tool['read_only_actions'] = array_values( $read_only_actions );
			}

			// Forward a whitelisted subset of ability meta. Only keys the client
			// actually consumes are exposed — keeps tools/list payload bounded and
			// prevents accidental leakage of new internal fields if abilities later
			// add private metadata. Update this list when a new key is needed
			// (and document the reason in the consuming code).
			$ability_meta = $ability->get_meta();
			if ( ! empty( $ability_meta ) ) {
					$default_allowed_meta_keys = array(
						'tool_type',
						'visibility',
						'execution_mode',
						'js_handler',
						'resource',
						'examples',
						'api_endpoint',
						'boost_screens',
						'required_plugin',
						'required_plugin_version',
						'version',
						// Server-side preflight against the site's installed-plugin list.
						// Declared on plugin lifecycle abilities (Activate/Deactivate/Delete)
						// so the server can refuse model-authored slugs that do not match
						// a currently installed plugin BEFORE the call reaches the browser.
						// Without this key in the allowlist, array_intersect_key strips
						// the meta and the server never receives it.
						'preflight_resource',
					);
					$allowed_meta_keys         = apply_filters(
						'zip_ai_tools_list_allowed_meta_keys',
						$default_allowed_meta_keys,
						$ability_name,
						$ability
					);
				if ( ! is_array( $allowed_meta_keys ) || empty( $allowed_meta_keys ) ) {
					$allowed_meta_keys = $default_allowed_meta_keys;
				}
					// Meta keys permitted to be forwarded for this ability.
					/**
					 * Narrowed type for `$allowed_meta_keys`.
					 *
					 * @var array<int|string,string> $allowed_meta_keys
					 */
					$forwarded_meta = array_intersect_key( $ability_meta, array_flip( $allowed_meta_keys ) );
				if ( ! empty( $forwarded_meta ) ) {
					$tool['meta'] = $forwarded_meta;
				}
			}

			$tools[] = $tool;
		}

		return $this->format_mcp_response( $id, array( 'tools' => $tools ) );
	}

	/**
	 * Handle MCP Tools Call Protocol.
	 *
	 * @param string|int|null     $id     JSON-RPC request id.
	 * @param array<string,mixed> $params JSON-RPC params, expects `name` and `arguments`.
	 * @return \WP_REST_Response
	 */
	private function handle_tools_call( $id, $params ) {
		$raw_tool_name = $params['name'] ?? '';
		$tool_name     = is_string( $raw_tool_name ) ? $raw_tool_name : '';
		$arguments     = $params['arguments'] ?? array();

		if ( empty( $tool_name ) ) {
			return $this->format_mcp_error( $id, -32602, 'Invalid params: tool name required' );
		}

		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return $this->format_mcp_error( $id, -32001, 'Abilities API is not available' );
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( ! $registry instanceof \WP_Abilities_Registry ) {
			return $this->format_mcp_error( $id, -32001, 'Abilities API is not available' );
		}
		$ability = $registry->get_registered( $tool_name );

		if ( ! $ability ) {
			return $this->format_mcp_error( $id, -32601, "Tool not found: {$tool_name}" );
		}

		// Execute the tool — WP_Ability::execute() dispatches to the registered execute_callback
		// (Abstract_Ability::handle_execute), which includes validation, rate-limiting, try-catch.
		$result = $ability->execute( $arguments );

		// Check if it's already a standard response from our Response class (Response::success/error)
		if ( is_array( $result ) && isset( $result['success'] ) ) {
			if ( ! $result['success'] ) {
				return $this->format_mcp_response(
					$id,
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => wp_json_encode( $result ),
							),
						),
						'isError' => true,
					)
				);
			}

			return $this->format_mcp_response(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => wp_json_encode( $result ),
						),
					),
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $this->format_mcp_response(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => wp_json_encode(
								array(
									'success' => false,
									'error'   => $result->get_error_message(),
									'code'    => $result->get_error_code(),
								)
							),
						),
					),
					'isError' => true,
				)
			);
		}

		return $this->format_mcp_response(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode(
							array(
								'success' => true,
								'data'    => $result,
							)
						),
					),
				),
			)
		);
	}

	/**
	 * Format an MCP JSON-RPC standard response.
	 *
	 * @param string|int|null     $id     JSON-RPC request id.
	 * @param array<string,mixed> $result JSON-RPC result payload.
	 * @return \WP_REST_Response
	 */
	private function format_mcp_response( $id, $result ) {
		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Format an MCP JSON-RPC standard error.
	 *
	 * @param string|int|null $id      JSON-RPC request id.
	 * @param int             $code    JSON-RPC error code.
	 * @param string          $message Human-readable error message.
	 * @return \WP_REST_Response
	 */
	private function format_mcp_error( $id, $code, $message ) {
		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			200
		);
	}

	/**
	 * Extracted user-context setup. App Password Basic auth via
	 * {@see is_basic_authenticated()} resolves and sets the current user
	 * inside `wp_authenticate_application_password()` as a side effect,
	 * so this method is a thin wrapper that just triggers the check —
	 * subsequent capability lookups (in this handler and in downstream
	 * third-party hooks like Elementor) see the App Password owner.
	 *
	 * The legacy `auth_token_wp_user_id` binding + `x_wp_user_id` header
	 * gate + `legacy_token_healed` migration scaffolding are retired:
	 * identity is now bound to the credential itself, not asserted by
	 * the caller.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return void
	 */
	private function setup_user_context( $request ) {
		$this->is_basic_authenticated();
	}

	/**
	 * Handle site scan — collects raw site data and sends to the server.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function handle_site_scan( $request ) {
		\ZipAI\MCP\Classes\Core\Site_Scanner::run_scan();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Site scan sent.',
			),
			200
		);
	}

	/**
	 * Check if the current request has permission to execute tools.
	 *
	 * This is ONE gate with ONE floor. Basic auth only resolves who the caller
	 * is. The capability check then applies to the current identity. That
	 * identity is the App Password owner or the logged-in browser session.
	 *
	 * Authentication is deliberately not authorization. Core puts no capability
	 * floor on Application Passwords. Every user can mint one. So admitting any
	 * valid one left the per-ability `$capability` as the only defence. This
	 * surface includes run-wp-cli and the snippet engine. The floor matches
	 * every UI that fronts this API. It also matches the credential the server
	 * uses. `Helper::ensure_app_password_provisioned()` only mints under a
	 * `manage_options` user.
	 *
	 * Recovery note. The bound App Password user can later lose
	 * `manage_options`. Then every call returns 401. Reconnecting AS THAT USER
	 * cannot fix it. `ensure_app_password_provisioned()` refuses to mint for
	 * them. Reconnecting as a different administrator does work. The idempotency
	 * lookup is scoped per user. So the stale UUID falls through and a fresh
	 * credential is minted.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return bool|\WP_Error True if permission granted, WP_Error otherwise.
	 */
	public function check_permission( $request ) {
		// The return value is intentionally unused. This resolves the App
		// Password owner and makes it the current user. The check below then
		// runs against the credential's real identity.
		$this->is_basic_authenticated();

		if ( current_user_can( self::INGRESS_CAPABILITY ) ) {
			return true;
		}

		// 401 when nobody is identified, 403 once someone is. Before the floor
		// existed only anonymous callers reached this line, so a flat 401 was
		// accurate; now a perfectly valid App Password whose owner lacks the
		// capability lands here too, and telling that caller "unauthenticated"
		// invites a pointless credential re-mint.
		return new \WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to execute tools.', 'zip-ai' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Check if the request is authenticated via Application Password
	 * Basic auth.
	 *
	 * Reads `Authorization: Basic <base64(username:app_password)>`,
	 * decodes the credential, and delegates to WP core's
	 * {@see wp_authenticate_application_password}. On success the
	 * current user is set as a side effect so capability checks
	 * downstream resolve against the App Password's owner.
	 *
	 * @return bool True if authenticated, false otherwise.
	 */
	protected function is_basic_authenticated() {
		$credential = $this->get_basic_credential();
		if ( null === $credential ) {
			return false;
		}

		list( $username, $password ) = $credential;
		if ( '' === $username || '' === $password ) {
			return false;
		}

		// `wp_authenticate_application_password` returns a WP_User on
		// success, or a WP_Error / null on failure.
		$result = wp_authenticate_application_password( null, $username, $password );
		if ( $result instanceof \WP_User ) {
			wp_set_current_user( $result->ID );
			return true;
		}

		return false;
	}

	/**
	 * Pull `(username, password)` out of an `Authorization: Basic …`
	 * header. Returns null when the header is absent, malformed, or
	 * uses any scheme other than Basic.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function get_basic_credential() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authorization header decoded for credential comparison only.
		$auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';

		if ( empty( $auth_header ) && function_exists( 'getallheaders' ) ) {
			$headers     = getallheaders();
			$auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
		}

		if ( ! is_string( $auth_header ) || 0 !== stripos( $auth_header, 'Basic ' ) ) {
			return null;
		}

		$encoded = trim( substr( $auth_header, 6 ) );
		if ( '' === $encoded ) {
			return null;
		}
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded || strpos( $decoded, ':' ) === false ) {
			return null;
		}

		list( $username, $password ) = explode( ':', $decoded, 2 );
		return array( (string) $username, (string) $password );
	}
}
