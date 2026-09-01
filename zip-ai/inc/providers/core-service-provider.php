<?php
/**
 * Core Service Provider.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Providers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ZipAI\MCP\Classes\Core\Service_Provider;
use ZipAI\MCP\Classes\Core\Context_Detector;
use ZipAI\MCP\Classes\Core\Tool_Registry;
use ZipAI\MCP\Classes\Api\Connection_REST_API;
use ZipAI\MCP\Classes\Api\Plugin_Update_REST_API;
use ZipAI\MCP\Classes\Api\External_Mcp;
use ZipAI\MCP\Classes\Api\REST_API;
use ZipAI\MCP\Classes\Api\AJAX_Handlers;
use ZipAI\MCP\Classes\Api\Snippet_REST_API;
use ZipAI\MCP\Classes\React\React_Manager;
use ZipAI\MCP\Classes\Admin\Snippet_Admin;
use ZipAI\MCP\Classes\Imports\ImportTextureGate;

/**
 * Core Service Provider Class.
 */
class Core_Service_Provider extends Service_Provider {

	/**
	 * Register services.
	 *
	 * @return void
	 */
	public function register() {
		// Register Context Detector.
		$this->container->singleton(
			Context_Detector::class,
			function () {
				return new Context_Detector();
			}
		);

		// Register Tool Registry.
		$this->container->singleton(
			Tool_Registry::class,
			function () {
				return new Tool_Registry();
			}
		);

		// Register REST API.
		$this->container->singleton(
			REST_API::class,
			function () {
				return new REST_API();
			}
		);

		// Register AJAX Handlers.
		$this->container->singleton(
			AJAX_Handlers::class,
			function () {
				return new AJAX_Handlers();
			}
		);

		// Register React Manager.
		$this->container->singleton(
			React_Manager::class,
			function () {
				return new React_Manager();
			}
		);

		// Register Snippet REST API.
		$this->container->singleton(
			Snippet_REST_API::class,
			function () {
				return new Snippet_REST_API();
			}
		);

		// Register Snippet Admin.
		$this->container->singleton(
			Snippet_Admin::class,
			function () {
				return new Snippet_Admin();
			}
		);

		// Connection screen's REST surface (endpoint state + Application
		// Password create/list/revoke). Admin-only, cookie-authenticated.
		$this->container->singleton(
			Connection_REST_API::class,
			function () {
				return new Connection_REST_API();
			}
		);

		// One-click "Update" for a plugin ZIP AI needs but finds too old.
		// Admin-only, cookie-authenticated, update-existing only.
		$this->container->singleton(
			Plugin_Update_REST_API::class,
			function () {
				return new Plugin_Update_REST_API();
			}
		);

		// External MCP endpoint for third-party AI clients (opt-in, curated
		// tool list). Registers nothing unless the site switched it on.
		$this->container->singleton(
			External_Mcp::class,
			function () {
				return new External_Mcp();
			}
		);

		// Register Import Texture Gate — disables WordPress wptexturize
		// on imported pages so authored characters render verbatim
		// (no `'`→`’`, `--`→`—`, etc. rewrites that shift text wrap
		// and break pixel-perfect fidelity).
		$this->container->singleton(
			ImportTextureGate::class,
			function () {
				return new ImportTextureGate();
			}
		);
	}

	/**
	 * Boot services.
	 *
	 * @return void
	 */
	public function boot() {
		// Instantiate services that need to hook into WordPress immediately.
		// In a pure DI world, we might do this differently, but for WP plugins,
		// we often need to instantiate to add_action/add_filter.

		$this->container->make( Context_Detector::class );
		$this->container->make( Tool_Registry::class );
		$this->container->make( REST_API::class );
		$this->container->make( AJAX_Handlers::class );
		$this->container->make( React_Manager::class );
		$this->container->make( Snippet_REST_API::class );
		$this->container->make( Snippet_Admin::class );
		$this->container->make( ImportTextureGate::class );
		$this->container->make( External_Mcp::class );
		$this->container->make( Connection_REST_API::class );
		$this->container->make( Plugin_Update_REST_API::class );
	}
}
