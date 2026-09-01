<?php
/**
 * ZipWP Abilities Index - Register all zipwp namespace tools
 *
 * This class registers custom MCP tools for ZipWP integration
 * with both REST API and JS hook execution modes.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Zipai_Abilities Class.
 * Registers all abilities under the zipwp namespace.
 */
class Zipai_Abilities {

	use \ZipAI\MCP\Classes\Abilities\Ability_Loader;

	/**
	 * Constructor of this class.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ), 10 );
	}

	/**
	 * Register all ZipWP abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		$this->load_abilities_from_dir( __DIR__, __NAMESPACE__ );
	}
}
