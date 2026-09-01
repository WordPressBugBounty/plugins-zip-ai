<?php
/**
 * Abilities Service Provider.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Providers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ZipAI\MCP\Classes\Abilities\Zipai_Abilities;
use ZipAI\MCP\Classes\Core\Service_Provider;

/**
 * Abilities Service Provider Class.
 */
class Abilities_Service_Provider extends Service_Provider {

	/**
	 * Register services.
	 *
	 * @return void
	 */
	public function register() {
		// Register ZipWP Abilities.
		$this->container->singleton(
			Zipai_Abilities::class,
			function () {
				return new Zipai_Abilities();
			}
		);

		// SureForms abilities provided natively by SureForms plugin (v2.7+).
	}

	/**
	 * Boot services.
	 *
	 * @return void
	 */
	public function boot() {
		// Register ability categories.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );

		// Instantiate abilities.
		$this->container->make( Zipai_Abilities::class );
	}

	/**
	 * Register ability categories.
	 *
	 * @return void
	 */
	public function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// ZipWP - WordPress settings, admin, site management
		wp_register_ability_category(
			'zipai',
			array(
				'label'       => 'WordPress Management',
				'description' => 'WordPress site settings, theme, plugin, and media management tools',
			)
		);

		// SureForms - Form creation and management
		wp_register_ability_category(
			'sureforms',
			array(
				'label'       => 'SureForms',
				'description' => 'Form creation and management tools',
			)
		);

		// SureCart - E-commerce, products, payments
		wp_register_ability_category(
			'surecart',
			array(
				'label'       => 'SureCart E-commerce',
				'description' => 'E-commerce tools for products, cart, and payments',
			)
		);
		
		// WooCommerce - Products, orders, customers.
		wp_register_ability_category(
			'woo',
			array(
				'label'       => 'WooCommerce',
				'description' => 'WooCommerce product, order, and customer management tools',
			)
		);

		// Accessibility - WCAG compliance and a11y tools.
		wp_register_ability_category(
			'a11y',
			array(
				'label'       => 'Accessibility',
				'description' => 'Accessibility compliance and WCAG audit tools',
			)
		);
	}
}
