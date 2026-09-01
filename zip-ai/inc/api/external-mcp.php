<?php
/**
 * External MCP server. Third-party AI clients connect to this endpoint. These
 * clients include Claude Code, Claude Desktop, Codex, Gemini CLI and Cursor.
 *
 * The server runs at `/wp-json/mcp/zip-ai`. It REBRANDS the MCP Adapter's own
 * default server (`mcp_adapter_default_server_config`). It does not stand a
 * second server beside it. This gives one server and one URL. The discover,
 * get-info and execute dispatcher comes with it. The auto-discovered resources
 * and prompts also come with it. If another plugin already claimed that server,
 * this plugin registers its own server as a fallback (`register_server`).
 *
 * This server is distinct from the plugin's own JSON-RPC endpoint at
 * `/wp-json/zip-ai/v1/mcp`. That endpoint stays untouched. It sees every
 * registered ability. There are two transports and one ability registry.
 *
 * The server has three deliberate properties:
 *   1. It is ON by default. The Connection screen can switch it off. When you
 *      switch it off, the route is removed. The abilities also lose the
 *      `mcp.public` flag. Nothing of this plugin stays reachable through the
 *      adapter's own server.
 *   2. This route raises the transport capability to `publish_pages`
 *      (`route_capability`). The default-server factory passes no permission
 *      callback. Without this, the adapter accepts bare `read`. Any subscriber
 *      with an Application Password could then reach it.
 *   3. The dispatcher is REUSED, not reimplemented. The adapter's own
 *      `meta.mcp.public` flag gates it. `External_Tool_Policy` sets this flag
 *      for every Era ability. There is no denylist. The caller authenticated as
 *      a real WP user. Each ability enforces its own capability. Annotations
 *      tell the client what to confirm.
 *
 * @since 0.0.8
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Api;

use ZipAI\MCP\Classes\Core\External_Tool_Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the curated, opt-in MCP server for external AI clients.
 */
class External_Mcp {

	const OPTION_KEY        = 'zipai_external_mcp_enabled';
	const DEFAULT_SERVER_ID = 'mcp-adapter-default-server';
	const ENABLED           = '1';
	const DISABLED          = '0';
	/**
	 * Capability required to speak to this endpoint AT ALL.
	 *
	 * `manage_options`, because the import writes run under the site's BOUND
	 * ADMIN credential regardless of who called — admitting a lower role would
	 * let them cause admin-level writes. Note the bar cannot rest on how
	 * credentials are issued: the Connection screen is admin-only, but WordPress
	 * lets ANY user mint an application password from their own profile, so the
	 * transport check here is what actually keeps non-admins out.
	 *
	 * The adapter's own default here is `read` (any logged-in user, subscriber
	 * included), which would let a subscriber enumerate every public ability's
	 * name, description and input schema through `discover-abilities`.
	 */
	const CAPABILITY = 'manage_options';

	const SERVER_ID = 'zip-ai';
	const ROUTE     = 'zip-ai';
	const NAMESPACE = 'mcp';

	/**
	 * Hook the adapter's init.
	 *
	 * @return void
	 */
	public function __construct() {
		// Preferred path: rebrand the adapter's OWN default server as Era's rather
		// than standing a second one beside it. One server, one URL, and the
		// dispatcher trio plus auto-discovered resources/prompts come with it.
		add_filter( 'mcp_adapter_default_server_config', array( $this, 'brand_default_server' ) );
		// The default-server factory passes no transport permission callback, so
		// the transport would fall back to the `read` capability — any subscriber
		// with an Application Password. Raise it, scoped to our route only.
		add_filter( 'mcp_adapter_default_transport_permission_user_capability', array( $this, 'route_capability' ), 10, 2 );
		// Fallback only: runs after the factory (priority 20) and stands up our own
		// server if another plugin had already claimed the default one.
		add_action( 'mcp_adapter_init', array( $this, 'register_server' ), 20 );
		// Only present in MCP Adapter 0.5.0+. On 0.4.1 there is no hook, so the
		// masking below cannot be corrected — which is precisely why the two
		// error-carrying import abilities are ADVERTISED as their own tools
		// rather than reached through the dispatcher.
		add_filter( 'mcp_adapter_tool_call_result', array( $this, 'unmask_failed_dispatch' ), 10, 3 );
	}

	/**
	 * Whether this instance rebranded the adapter's default server.
	 *
	 * @var bool
	 */
	private $claimed_default = false;

	/**
	 * Rebrand the adapter's default server as Era's, and advertise our own tools
	 * alongside the dispatcher trio it already carries.
	 *
	 * Declines when another plugin already renamed this same server. Two plugins
	 * cannot both own it, and silently stealing it would break whichever loaded
	 * earlier — so we leave it and fall back to our own server in
	 * {@see self::register_server}.
	 *
	 * @param mixed $config Default server config from the factory.
	 * @return mixed
	 */
	public function brand_default_server( $config ) {
		if ( ! is_array( $config ) || ! self::is_enabled() ) {
			return $config;
		}
		if ( ( $config['server_id'] ?? self::DEFAULT_SERVER_ID ) !== self::DEFAULT_SERVER_ID ) {
			return $config;
		}

		$config['server_id']          = self::SERVER_ID;
		$config['server_route']       = self::ROUTE;
		$config['server_name']        = 'ZIP AI';
		$config['server_description'] = 'Import AI-authored HTML into this WordPress site as native blocks, and run this site\'s abilities.';
		$config['server_version']     = ZIPAI_MCP_VERSION;

		// Keep whatever the factory put there (the discover/get-info/execute
		// dispatcher) and add the import doors on top, so the write path is
		// advertised rather than something an agent has to discover around.
		// The factory's list is untyped from here, so narrow it before merging.
		$existing = isset( $config['tools'] ) && is_array( $config['tools'] )
			? array_values( array_filter( $config['tools'], 'is_string' ) )
			: array();

		/**
		 * The SAME lockdown filter the fallback server applies. Advertised
		 * tools execute through their own permission_callback, never the
		 * execute-ability dispatcher, so `zip_ai_external_allowed_abilities`
		 * (which governs `meta.mcp.public`) cannot remove them; this filter is
		 * the one that narrows the advertised surface — and this rebrand path
		 * is the COMMON one (single-plugin installs), so without it here the
		 * documented lockdown was inert exactly where most sites would use it.
		 * An empty result leaves the server with no tools: locked, on purpose.
		 *
		 * @param string[] $tools Advertised tool ids.
		 */
		$config['tools']       = array_values(
			array_filter(
				(array) apply_filters(
					'zip_ai_external_mcp_tools',
					array_values( array_unique( array_merge( $existing, External_Tool_Policy::ADVERTISED ) ) )
				),
				'is_string'
			)
		);
		$this->claimed_default = true;

		return $config;
	}

	/**
	 * Raise the transport capability for our route only.
	 *
	 * Scoped by request route so other MCP servers on the site keep the adapter's
	 * own default. Returning the unfiltered value for anything else matters: this
	 * filter is global, and tightening a sibling plugin's server would break it.
	 *
	 * @param mixed $capability Capability the transport will check.
	 * @param mixed $context    Request context (HttpRequestContext).
	 * @return mixed
	 */
	public function route_capability( $capability, $context = null ) {
		$route = '';
		if ( is_object( $context ) && isset( $context->request ) && $context->request instanceof \WP_REST_Request ) {
			$route = untrailingslashit( (string) $context->request->get_route() );
		}
		// Exact route or a sub-path of it, never a substring match — that would
		// also catch a sibling server whose route merely contains ours (e.g.
		// `/mcp/zip-ai-foo`) and break it by raising its capability.
		$base = '/' . self::NAMESPACE . '/' . self::ROUTE;
		if ( $route !== $base && ! str_starts_with( $route, $base . '/' ) ) {
			return $capability;
		}

		return self::capability();
	}

	/**
	 * The capability this endpoint demands. Filterable so a site can deliberately
	 * widen it (e.g. to let editors import pages with their own application
	 * password) — every ability still enforces its own capability underneath, so
	 * lowering this cannot grant anything the caller lacks.
	 *
	 * @return string
	 */
	public static function capability() {
		$capability = apply_filters( 'zip_ai_external_mcp_capability', self::CAPABILITY );

		return is_string( $capability ) && '' !== $capability ? $capability : self::CAPABILITY;
	}

	/**
	 * Surface a failure that the adapter's dispatcher hides.
	 *
	 * `mcp-adapter/execute-ability` wraps the target result as
	 * `{success: true, data: <inner>}`. This means "the dispatch worked". The
	 * inner value can itself be `{success: false, error: …}`. In that case the
	 * outer flag still reads true and `isError` is never set. An agent that
	 * checks the top level then marches past a real failure. For example, a
	 * plugin install that did not happen, or an import that was rejected.
	 *
	 * This method returns the inner envelope. The adapter then recognises the
	 * failure and marks the result as an error. The other sibling fields ride
	 * along in the message. The adapter's error path keeps only a string. The
	 * `suggestion` and `violations` fields let the agent self-correct. It does
	 * not need another round-trip.
	 *
	 * @param mixed              $result    The tool call result.
	 * @param array<mixed,mixed> $args      Call arguments (unused).
	 * @param string             $tool_name MCP tool name (slashes become hyphens).
	 * @return mixed
	 */
	public function unmask_failed_dispatch( $result, $args, $tool_name ) {
		unset( $args );
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// The dispatcher reports "the dispatch worked" even when the ability it
		// ran failed. Unwrap one level so the real envelope is judged below.
		if ( 'mcp-adapter-execute-ability' === $tool_name && true === ( $result['success'] ?? null ) ) {
			$inner = $result['data'] ?? null;
			if ( is_array( $inner ) && false === ( $inner['success'] ?? null ) ) {
				$result = $inner;
			}
		}

		if ( false !== ( $result['success'] ?? null ) ) {
			return $result;
		}

		$error = isset( $result['error'] ) && is_string( $result['error'] ) ? trim( $result['error'] ) : '';
		if ( '' === $error ) {
			return $result;
		}

		// Fold the remaining fields into the message. The adapter's error path
		// keeps ONLY this string — `suggestion` and `data` (repair hints such as
		// `violations`, and the import consent token) are otherwise dropped
		// before the client ever sees them.
		$suggestion = isset( $result['suggestion'] ) && is_string( $result['suggestion'] ) ? trim( $result['suggestion'] ) : '';
		if ( '' !== $suggestion && ! str_contains( $error, $suggestion ) ) {
			$error .= ' ' . $suggestion;
		}
		if ( isset( $result['data'] ) && is_array( $result['data'] ) && ! empty( $result['data'] ) ) {
			$encoded = wp_json_encode( $result['data'] );
			if ( false !== $encoded ) {
				$error .= ' ' . $encoded;
			}
		}

		return array(
			'success' => false,
			'error'   => $error,
		);
	}

	/**
	 * Whether the external endpoint is switched on for this site. Defaults to
	 * OFF; the endpoint is opt-in, so a site with no option row stays closed
	 * until an admin turns it on in the Connection screen.
	 *
	 * Stored as the STRING '1' / '0', never a boolean. `get_option()` cannot tell
	 * a stored `false` from a missing row — both arrive as `false` — so a string
	 * keeps an explicit ON distinguishable from a never-touched site.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$stored = get_option( self::OPTION_KEY, self::DISABLED );
		// A legacy boolean/empty value (written before this key was normalised to
		// a string) is treated as an explicit OFF, matching what the admin chose.
		$enabled = is_string( $stored ) ? self::ENABLED === $stored : (bool) $stored;

		/**
		 * Filter whether the external MCP endpoint is available. Return false to
		 * force it off site-wide regardless of the stored setting.
		 *
		 * @param bool $enabled Current state.
		 */
		return (bool) apply_filters( 'zip_ai_external_mcp_enabled', $enabled );
	}

	/**
	 * Persist the on/off state. Kept here so the storage format lives with the
	 * reader that depends on it.
	 *
	 * @param bool $enabled Desired state.
	 * @return void
	 */
	public static function set_enabled( bool $enabled ) {
		update_option( self::OPTION_KEY, $enabled ? self::ENABLED : self::DISABLED );
	}

	/**
	 * The endpoint URL clients should be given.
	 *
	 * @return string
	 */
	public static function server_url() {
		return rest_url( self::NAMESPACE . '/' . self::ROUTE );
	}

	/**
	 * Register the curated server with the adapter.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The adapter that fired this hook.
	 * @return void
	 */
	public function register_server( $adapter ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		// The default server was successfully rebranded as ours — nothing to add.
		if ( $this->claimed_default ) {
			return;
		}
		// Someone else owns the default server. Stand up our own so the endpoint
		// exists either way; it costs one extra route and keeps the URL stable.
		if ( null !== $adapter->get_server( self::SERVER_ID ) ) {
			return;
		}

		/**
		 * Filter the abilities exposed to external AI clients.
		 *
		 * Return a NARROWER list to lock a site down. Widening past Era's own
		 * abilities is not possible — the namespace bound is re-applied after
		 * this filter.
		 *
		 * @param string[] $tools Ability ids.
		 */
		// Cast + filter rather than trust: a third-party filter is under no
		// obligation to honour the documented shape, and a junk entry here would
		// surface as an adapter-side registration failure.
		$tools = array_values( array_filter( (array) apply_filters( 'zip_ai_external_mcp_tools', External_Tool_Policy::ADVERTISED ), 'is_string' ) );
		if ( empty( $tools ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::NAMESPACE,
			self::ROUTE,
			// Same identity the rebrand path uses — a fallback server must not
			// present itself differently to a client.
			'ZIP AI',
			'Import AI-authored HTML into this WordPress site as native blocks, and run this site\'s abilities.',
			ZIPAI_MCP_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			null,
			null,
			$tools,
			array(),
			array(),
			array( $this, 'check_transport_permission' )
		);
	}

	/**
	 * Transport-level gate for the fallback server, where we DO pass a callback
	 * (so the `route_capability` filter is never consulted). Same bar as the
	 * rebrand path; each ability still enforces its own capability on top.
	 *
	 * @return bool
	 */
	public function check_transport_permission() {
		return is_user_logged_in() && current_user_can( self::capability() );
	}
}
