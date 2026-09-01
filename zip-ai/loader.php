<?php
/**
 * Plugin Class.
 *
 * @package zip-ai
 * @since 1.0.0
 */

namespace ZipAI\MCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ZipAI\MCP\Classes\Core\Container;
use ZipAI\MCP\Classes\Providers\Core_Service_Provider;
use ZipAI\MCP\Classes\Providers\Abilities_Service_Provider;
use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Core\Service_Provider;

if ( ! class_exists( '\ZipAI\MCP\Plugin' ) ) {
	/**
	 * Plugin Class
	 *
	 * @since 1.0.0
	 */
	class Plugin {

		/**
		 * Instance
		 *
		 * @access private
		 * @var self|null Class Instance.
		 * @since 1.0.0
		 */
		private static $instance;

		/**
		 * The dependency injection container.
		 *
		 * @var Container
		 */
		protected $container;

		/**
		 * Service Providers.
		 *
		 * @var array<int, Service_Provider>
		 */
		protected $providers = array();

		/**
		 * Initiator
		 *
		 * @since 1.0.0
		 * @return object initialized object of class.
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// Must be first — everything below (Container included) autoloads
			// through it.
			spl_autoload_register( array( __CLASS__, 'autoload' ) );

			$this->container = new Container();

			$this->define_constants();
			
			// Register Services.
			$this->register_services();

			// Boot Services.
			add_action( 'plugins_loaded', array( $this, 'boot_services' ), 5 );

			$this->setup_activation_hooks();

			// Site scanner — event-driven memory enrichment (after auth).
			// \ZipAI\MCP\Classes\Core\Site_Scanner::register_hooks(); // TODO: class not yet committed.

			// Code snippet executor — runs AI-created snippets (PHP/JS/CSS/HTML).
			// This is the ONLY persistent hook needed — it auto-loads all snippets
			// created by any tool (cookie consent, tracking codes, custom CSS, etc.)
			\ZipAI\MCP\Classes\Core\Snippet_Executor::init();

			// Plugin abilities toggler — listens for `activated_plugin` and
			// enables MCP abilities for mapped slugs regardless of activation
			// path (server-side ability, browser-proxied REST, admin UI,
			// WP-CLI). Idempotent + slug-map gated.
			\ZipAI\MCP\Classes\Core\Plugin_Abilities_Toggler::init();

			// Imported-chrome reader — renders imported header/footer template
			// parts verbatim on ANY classic theme via a `template_include`
			// canvas takeover when `zipai_chrome_mode === 'takeover'` (written
			// by the importer) or a page carries the standalone marker meta.
			// Inert on block themes (FSE renders the parts natively) and on
			// sites that never imported. Registering the option doubles as the
			// backend's capability probe.
			\ZipAI\MCP\Classes\Core\Imported_Chrome::init();

			// Privacy policy disclosure (WordPress Guideline 7).
			add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		}

		/**
		 * Plugin autoloader — single runtime autoloader for everything the
		 * plugin ships: its own classes (inc/) and the bundled
		 * composer-managed libraries in lib/ (mcp-adapter, php-mcp-schema).
		 * Composer's vendor/autoload.php is dev-tooling only and is never
		 * loaded at runtime.
		 *
		 * @param string $class Fully qualified class name to load.
		 * @return void
		 */
		public static function autoload( string $class ): void {
			// Own classes — WordPress-style file names: namespace path
			// lowercased, CamelCase split with hyphens, underscores as hyphens.
			// ZipAI\MCP\Classes\Abilities\Core\RunWpCli -> inc/abilities/core/run-wp-cli.php.
			$own_prefix = 'ZipAI\MCP\Classes\\';
			if ( 0 === strpos( $class, $own_prefix ) ) {
				$filename = preg_replace(
					array( '/([a-z])([A-Z])/', '/_/', '/\\\\/' ),
					array( '$1-$2', '-', '/' ),
					substr( $class, strlen( $own_prefix ) )
				);

				if ( is_string( $filename ) ) {
					$file = __DIR__ . '/inc/' . strtolower( $filename ) . '.php';

					if ( is_readable( $file ) ) {
						require_once $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path is derived from the class name.
					}
				}
				return;
			}

			// Bundled lib/ packages — strict PSR-4, directory case preserved.
			$psr4 = array(
				'WP\MCP\\'       => 'lib/mcp-adapter/includes/',
				'WP\McpSchema\\' => 'lib/php-mcp-schema/src/',
			);

			foreach ( $psr4 as $prefix => $base_dir ) {
				if ( 0 === strpos( $class, $prefix ) ) {
					$file = __DIR__ . '/' . $base_dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

					if ( is_readable( $file ) ) {
						require_once $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path is derived from a fixed prefix map.
					}
					return;
				}
			}
		}

		/**
		 * Register Service Providers.
		 *
		 * @return void
		 */
		private function register_services() {
			$this->providers[] = new Core_Service_Provider( $this->container );
			$this->providers[] = new Abilities_Service_Provider( $this->container );

			foreach ( $this->providers as $provider ) {
				$provider->register();
			}
		}

		/**
		 * Boot Service Providers.
		 *
		 * @return void
		 */
		public function boot_services() {
			$this->load_vendor_dependencies();

			foreach ( $this->providers as $provider ) {
				$provider->boot();
			}
		}

		/**
		 * Define the required constants.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function define_constants() {
			define( 'ZIPAI_MCP_FILE', __DIR__ . '/zip-ai.php' );
			define( 'ZIPAI_MCP_DIR', plugin_dir_path( ZIPAI_MCP_FILE ) );
			define( 'ZIPAI_MCP_URL', plugins_url( '/', ZIPAI_MCP_FILE ) );
			define( 'ZIPAI_MCP_VERSION', '0.0.10' );
			define( 'ZIPAI_MCP_MENU_SLUG', 'zip-ai' );

			// Base URL for ZIP AI credit server.
			if ( ! defined( 'ZIPAI_MCP_BASE_URL' ) ) {
				define( 'ZIPAI_MCP_BASE_URL', 'https://credits.zipwp.com' );
			}

			if ( ! defined( 'ZIPAI_MCP_MIDDLEWARE' ) ) {
				define( 'ZIPAI_MCP_MIDDLEWARE', 'https://app.zipwp.com/auth/' );
			}

			if ( ! defined( 'ZIPAI_API_BASE' ) ) {
				define( 'ZIPAI_API_BASE', 'https://api.zipwp.com/api/' );
			}

			// Base URL for direct server calls (e.g. inline-edit). Override via
			// wp-config.php or the `zipai_brain_url` filter.
			if ( ! defined( 'ZIPAI_BRAIN_URL' ) ) {
				define( 'ZIPAI_BRAIN_URL', 'https://brain.zipwp.com' );
			}

			// API endpoint for credit server.
			if ( ! defined( 'ZIPAI_MCP_CREDIT_SERVER_API' ) ) {
				define( 'ZIPAI_MCP_CREDIT_SERVER_API', ZIPAI_MCP_BASE_URL . '/api/' );
			}
		}

		/**
		 * Load bundled dependencies (mcp-adapter).
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private function load_vendor_dependencies() {
			// Initialize mcp-adapter plugin if not already loaded. It is vendored
			// in lib/mcp-adapter (git-committed) and its classes are served by the
			// plugin autoloader (Plugin::autoload). The library self-bootstraps
			// (defines its own constants, runs its autoloader and calls
			// \WP\MCP\Plugin::instance()) on require, and its self-autoload expects
			// a nested vendor/ it doesn't have — so we tell it to skip it, else it
			// prints a false "Composer autoloader was not found" admin notice and
			// bails before Plugin::instance().
			if ( ! defined( 'WP_MCP_AUTOLOAD' ) ) {
				define( 'WP_MCP_AUTOLOAD', false );
			}
			if ( ! defined( 'WP_MCP_VERSION' ) && file_exists( ZIPAI_MCP_DIR . 'lib/mcp-adapter/mcp-adapter.php' ) ) {
				require_once ZIPAI_MCP_DIR . 'lib/mcp-adapter/mcp-adapter.php';
			}
		}

		/**
		 * Setup plugin activation and deactivation hooks.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function setup_activation_hooks() {
			register_activation_hook( ZIPAI_MCP_FILE, array( $this, 'plugin_activated' ) );
			register_deactivation_hook( ZIPAI_MCP_FILE, array( $this, 'plugin_deactivated' ) );
		}

		/**
		 * Plugin activation callback.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function plugin_activated() {
			// Add the required capability to administrator role.
			$this->add_plugin_capabilities();

			// Generate and register shared secret with the server on activation.
			$this->register_hmac_secret();
		}

		/**
		 * Plugin deactivation callback.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function plugin_deactivated() {
			// Remove the plugin capabilities from all roles.
			$this->remove_plugin_capabilities();

			// Clear scheduled site scan.
			\ZipAI\MCP\Classes\Core\Site_Scanner::unschedule();
		}

		/**
		 * Suggest privacy policy content per WordPress Guideline 7.
		 *
		 * Discloses what data is sent to the ZIP AI cloud service.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function add_privacy_policy_content() {
			$policy_text = '<h2>ZIP AI Assistant</h2>
<p>This site uses the ZIP AI Assistant plugin ("ZIP AI"), which connects to a cloud-based AI service operated by Starter Templates (Starter Templates, a Brainstorm Force product).</p>

<h3>What data is collected</h3>
<p>When authenticated and actively using ZIP AI, the following data may be sent to our servers:</p>
<ul>
<li><strong>Chat messages</strong> — Messages you send to the AI assistant and the assistant\'s responses.</li>
<li><strong>Site structure data</strong> — Page titles, post counts, active plugin names, active theme name, and content categories. This helps ZIP AI understand your site and provide relevant suggestions.</li>
<li><strong>Site identity</strong> — Site title, tagline, language, and domain name.</li>
<li><strong>E-commerce data</strong> (if applicable) — Product counts, product categories, currency, and whether reviews are enabled. No individual product details, prices, or customer data is sent.</li>
</ul>

<h3>What data is NOT collected</h3>
<ul>
<li>Page or post content/body text</li>
<li>Customer or visitor personal information</li>
<li>Passwords, payment details, or financial data</li>
<li>Email addresses of site visitors</li>
<li>Analytics or traffic data</li>
</ul>

<h3>How data is used</h3>
<p>Data is used solely to power the AI assistant\'s responses and to build a memory of your site preferences so you don\'t have to repeat yourself across conversations. You can clear all stored memory at any time via the "Clear Site Memory" option in the ZIP AI menu.</p>

<h3>Data retention</h3>
<p>Chat messages and memory data are stored on our servers for as long as your account is active. You can request deletion at any time by clearing your site memory or contacting support.</p>

<h3>Third-party services</h3>
<p>ZIP AI uses AI language models (such as Google Gemini and Anthropic Claude) to process your messages. Your messages may be sent to these providers for processing. Please refer to their respective privacy policies:</p>
<ul>
<li><a href="https://policies.google.com/privacy">Google Privacy Policy</a></li>
<li><a href="https://www.anthropic.com/privacy">Anthropic Privacy Policy</a></li>
</ul>

<p>For more information, please see our <a href="https://developer.brainstormforce.com/privacy-policy/">Privacy Policy</a>.</p>';

			wp_add_privacy_policy_content( 'ZIP AI Assistant', $policy_text );
		}

		/**
		 * Add required capabilities to administrator role.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private function add_plugin_capabilities() {
			// Get the administrator role.
			$admin_role = get_role( 'administrator' );

			if ( $admin_role ) {
				// Add the required capability for ZipWP MCP management.
				$admin_role->add_cap( 'manage_zip_mcp_assistant', true );
			}
		}

		/**
		 * Remove plugin capabilities from all roles.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private function remove_plugin_capabilities() {
			// Get all roles.
			$roles = wp_roles();

			// Remove the capability from all roles that have it.
			foreach ( $roles->role_objects as $role ) {
				if ( $role->has_cap( 'manage_zip_mcp_assistant' ) ) {
					$role->remove_cap( 'manage_zip_mcp_assistant' );
				}
			}
		}

		/**
		 * Register HMAC shared secret with the server.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private function register_hmac_secret() {
			// Only register if not already registered.
			if ( ! Helper::is_hmac_registered() ) {
				$registration_result = Helper::register_shared_secret_with_laravel();

				if ( isset( $registration_result['error'] ) ) {
					// Note: Error logging removed for production.
					unset( $registration_result );
				}
			}
		}
	}

	/**
	 * Kicking this off by calling 'get_instance()' method
	 */
	Plugin::get_instance();
}
