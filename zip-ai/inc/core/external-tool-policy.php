<?php
/**
 * External tool policy — which abilities a third-party AI client may reach, and
 * how each one is annotated.
 *
 * Decides which of Era's abilities an external AI client can reach, and which
 * are advertised as first-class MCP tools rather than reached by name through the
 * adapter's dispatcher.
 *
 * There is deliberately NO denylist. The caller authenticated with an Application
 * Password a real WP user created, every ability still enforces its own
 * capability, and MCP annotations tell the client what to confirm first.
 * Withholding `run-wp-cli` or `run-snippet` from an administrator who can
 * already run both from wp-admin buys nothing and breaks the agent-as-operator
 * workflow. A site that wants a narrower surface uses the
 * `zip_ai_external_allowed_abilities` filter — enforced in {@see self::is_exposed()},
 * which is what actually flags an ability reachable.
 *
 * Reachability is the adapter's own `meta.mcp.public` flag, set per ability by
 * the loader from this policy. The flag is read by EVERY adapter server on the
 * site, not just ours — if another plugin stands up a server with the
 * dispatcher trio, our public abilities are dispatchable there too. Accepted:
 * each ability's own capability is the real floor, and `zipai/run-rest-request`
 * dispatches under the caller's own REST permissions. The flag is only set
 * while the Connection toggle is ON, so switching off closes every server at
 * once.
 *
 * @since 0.0.8
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Decides the external AI-client tool surface.
 */
class External_Tool_Policy {

	/**
	 * Namespaces an external client may reach at all. Only `zipai` — the
	 * abilities this plugin registers, and therefore the only ones whose meta we
	 * set. `core/*` and other plugins' abilities are theirs to expose on their own
	 * terms; we neither flag nor block them.
	 */
	const ALLOWED_NAMESPACES = array( 'zipai' );

	/**
	 * Advertised as first-class MCP tools (full schema at connect time).
	 *
	 * The first three are the MCP Adapter's OWN dispatcher — discovery plus
	 * execute-by-name. Reused rather than reimplemented: they already resolve
	 * abilities, enforce each target's `permission_callback`, and gate on
	 * `meta.mcp.public`, which is exactly the switch this class owns per ability.
	 * An agent therefore reaches the whole allowed surface for the cost of three
	 * schemas instead of ~20.
	 *
	 * The import door stays advertised on top, deliberately: the write path must
	 * be the obvious thing to call, never something an agent discovers its way
	 * around into raw REST.
	 */
	const ADVERTISED = array(
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
		'zipai/get-site-context',
		'zipai/import-html',
	);

	/**
	 * Every ability an external client may reach, advertised or dispatched.
	 *
	 * Derived from the live registry rather than hardcoded, so a newly added
	 * ability is reachable without editing this file — while the namespace
	 * bound still applies. Display surface only; execution is gated per ability
	 * by {@see self::is_exposed()}, which applies the same filter.
	 *
	 * @return string[] Ability ids, sorted.
	 */
	public static function allowed() {
		if ( ! class_exists( '\WP_Abilities_Registry' ) ) {
			return array();
		}
		$registry = \WP_Abilities_Registry::get_instance();
		if ( ! $registry instanceof \WP_Abilities_Registry ) {
			return array();
		}

		$allowed = array();
		foreach ( $registry->get_all_registered() as $name => $ability ) {
			$id = $ability->get_name() ? $ability->get_name() : (string) $name;
			if ( self::is_allowed( $id ) ) {
				$allowed[] = $id;
			}
		}
		sort( $allowed );

		/**
		 * Filter the abilities an external AI client may reach.
		 *
		 * Use this to LOCK DOWN a site — return a narrower list to withhold
		 * abilities from connected agents. The namespace bound is re-applied
		 * afterwards, so a filter cannot widen the surface past Era's own
		 * abilities. Treat it as a membership question: it may be handed the
		 * full list (here) or a single-ability list ({@see self::is_exposed()}),
		 * so filter the ids you are given rather than inspecting the set.
		 *
		 * @param string[] $allowed Ability ids.
		 */
		$filtered = apply_filters( 'zip_ai_external_allowed_abilities', $allowed );
		$filtered = array_filter( (array) $filtered, 'is_string' );

		return array_values( array_filter( $filtered, array( self::class, 'is_allowed' ) ) );
	}

	/**
	 * Whether one ability id is reachable externally.
	 *
	 * @param string $id Ability id.
	 * @return bool
	 */
	public static function is_allowed( string $id ) {
		$namespace = strtok( $id, '/' );

		return in_array( $namespace, self::ALLOWED_NAMESPACES, true );
	}

	/**
	 * Whether one ability may be flagged `meta.mcp.public` — the check that
	 * governs EXECUTION, not just the displayed list. The namespace bound and
	 * the site's `zip_ai_external_allowed_abilities` lockdown filter both
	 * apply; without the filter here, a site narrowing its surface would trim
	 * the Connection screen while every ability stayed dispatchable.
	 *
	 * @param string $id Ability id.
	 * @return bool
	 */
	public static function is_exposed( string $id ) {
		if ( ! self::is_allowed( $id ) ) {
			return false;
		}

		/** This filter is documented on {@see self::allowed()}. */
		$filtered = array_filter( (array) apply_filters( 'zip_ai_external_allowed_abilities', array( $id ) ), 'is_string' );

		return in_array( $id, $filtered, true );
	}
}
