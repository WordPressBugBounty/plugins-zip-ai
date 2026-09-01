<?php
/**
 * Service Provider Interface.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service Provider Class.
 */
abstract class Service_Provider {

	/**
	 * The container instance.
	 *
	 * @var Container
	 */
	protected $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container The container instance.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Register services.
	 *
	 * @return void
	 */
	abstract public function register();

	/**
	 * Boot services.
	 *
	 * @return void
	 */
	public function boot() {
		// Optional boot method.
	}
}
