<?php
/**
 * Enqueue Trait
 *
 * Reusable trait for enqueueing scripts, styles, translations,
 * and localizing data with consistent handle prefixing.
 *
 * @package zip-ai
 * @since 1.0.0
 */

namespace ZipAI\MCP\Classes\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Enqueue.
 *
 * @method void wp_enqueue_scripts() Provided by the consuming class; registered on the wp_enqueue_scripts action.
 *
 * @since 1.0.0
 */
trait Enqueue {

	/**
	 * Handle prefix for all enqueued assets.
	 *
	 * @var string
	 */
	public $enqueue_prefix = 'zip-ai';

	/**
	 * Build path (absolute filesystem path).
	 *
	 * @var string
	 */
	public $build_path = ZIPAI_MCP_DIR . 'assets/';

	/**
	 * Build URL.
	 *
	 * @var string
	 */
	public $build_url = ZIPAI_MCP_URL . 'assets/';

	/**
	 * Language directory for translations.
	 *
	 * @var string
	 */
	public $language_dir = ZIPAI_MCP_DIR . 'languages';

	/**
	 * Register frontend enqueue hook.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_scripts() {
		add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
	}

	/**
	 * Register admin enqueue hook.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_scripts_admin() {
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	/**
	 * Register, enqueue, localize, and set translations for a script.
	 *
	 * @param string                                                                  $hook               Handle suffix (prefixed automatically).
	 * @param string                                                                  $path               Full URL to the script file.
	 * @param array<string>                                                           $dependency          Script dependencies.
	 * @param array{hook?: string, object_name?: string, data?: array<string, mixed>} $localization_array  Localization config: [ 'hook' => '', 'object_name' => '', 'data' => [] ].
	 * @param string                                                                  $version             Asset version. Defaults to plugin version.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function script_operations( $hook, $path, $dependency, $localization_array = array(), $version = ZIPAI_MCP_VERSION ) {
		$this->register_script( $hook, $path, $dependency, $version );
		$this->enqueue_script( $hook );

		if ( ! empty( $localization_array['hook'] ) && ! empty( $localization_array['object_name'] ) && ! empty( $localization_array['data'] ) ) {
			$this->localize_script( $localization_array['hook'], $localization_array['object_name'], $localization_array['data'] );
		}

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				$this->enqueue_prefix . '-' . $hook,
				$this->enqueue_prefix,
				$this->language_dir
			);
		}
	}

	/**
	 * Register and enqueue a style.
	 *
	 * @param string        $hook       Handle suffix (prefixed automatically).
	 * @param string        $path       Full URL to the CSS file.
	 * @param array<string> $dependency Style dependencies.
	 * @param string        $version    Asset version. Defaults to plugin version.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function style_operations( $hook, $path, $dependency = array(), $version = ZIPAI_MCP_VERSION ) {
		$this->register_style( $hook, $path, $dependency, $version );
		$this->enqueue_style( $hook );
	}

	/**
	 * Register a script with prefixed handle.
	 *
	 * @param string        $hook       Handle suffix.
	 * @param string        $path       Full URL to the script.
	 * @param array<string> $dependency Script dependencies.
	 * @param string        $version    Asset version.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_script( $hook, $path, $dependency, $version = ZIPAI_MCP_VERSION ) {
		// Cache-bust hand-enqueued source scripts on every edit: when the caller
		// uses the default plugin version, derive a filemtime version from the
		// on-disk file. In dev this busts the moment a handler changes; in
		// production it is the build time (stable per deploy). Falls back to the
		// constant for false/remote/missing sources.
		if ( ZIPAI_MCP_VERSION === $version && '' !== $path ) {
			$local = str_replace( ZIPAI_MCP_URL, ZIPAI_MCP_DIR, $path );
			if ( $local !== $path && file_exists( $local ) ) {
				$version = (string) filemtime( $local );
			}
		}
		wp_register_script(
			$this->enqueue_prefix . '-' . $hook,
			$path,
			$dependency,
			$version,
			true
		);
	}

	/**
	 * Enqueue a previously registered script.
	 *
	 * @param string $hook Handle suffix.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_script( $hook ) {
		wp_enqueue_script( $this->enqueue_prefix . '-' . $hook );
	}

	/**
	 * Localize a script with prefixed handle and object name.
	 *
	 * @param string               $hook        Handle suffix.
	 * @param string               $object_name JS global object name.
	 * @param array<string, mixed> $data        Key-value data to expose.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function localize_script( $hook, $object_name, $data ) {
		wp_localize_script(
			$this->enqueue_prefix . '-' . $hook,
			$object_name,
			$data
		);
	}

	/**
	 * Register a style with prefixed handle.
	 *
	 * @param string        $hook       Handle suffix.
	 * @param string        $path       Full URL to the CSS file.
	 * @param array<string> $dependency Style dependencies.
	 * @param string        $version    Asset version.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_style( $hook, $path, $dependency = array(), $version = ZIPAI_MCP_VERSION ) {
		wp_register_style(
			$this->enqueue_prefix . '-' . $hook,
			$path,
			$dependency,
			$version
		);
	}

	/**
	 * Enqueue a previously registered style.
	 *
	 * @param string $hook Handle suffix.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_style( $hook ) {
		wp_enqueue_style( $this->enqueue_prefix . '-' . $hook );
	}
}
