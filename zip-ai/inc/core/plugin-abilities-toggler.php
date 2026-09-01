<?php
/**
 * Plugin abilities toggler.
 *
 * Data-driven post-install MCP ability toggles per plugin slug.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

defined( 'ABSPATH' ) || exit;

class Plugin_Abilities_Toggler {

	/**
	 * Wire the `activated_plugin` core action so any activation path
	 * (server-side ability, browser-proxied `POST /wp/v2/plugins`, admin
	 * UI, WP-CLI) triggers MCP-ability toggles for mapped slugs.
	 *
	 * Idempotent — `enable_for_slug` is safe to call multiple times and
	 * returns `handled=false` for unmapped slugs, so this hook adds no
	 * cost for plugins outside MAP.
	 */
	public static function init(): void {
		add_action( 'activated_plugin', array( __CLASS__, 'on_activated_plugin' ), 20, 2 );
	}

	/**
	 * Map the WP-core plugin file ("folder/file.php") to a wp.org slug
	 * (folder) and dispatch to `enable_for_slug`. Non-wp.org plugins
	 * (single-file or non-conforming folder layout) fall through to a
	 * no-op since the slug won't appear in MAP.
	 *
	 * @param string $plugin_file  Relative plugin file path as stored in
	 *                             the `active_plugins` option.
	 * @param bool   $network_wide Whether activation was network-wide.
	 */
	public static function on_activated_plugin( $plugin_file, $network_wide = false ): void {
		if ( '' === $plugin_file ) {
			return;
		}
		if ( false === strpos( $plugin_file, '/' ) ) {
			return;
		}
		$slug = strtolower( explode( '/', $plugin_file )[0] );
		if ( '' === $slug ) {
			return;
		}

		// ZIP AI itself (re)activated: a mapped plugin that was ALREADY active
		// won't fire its own `activated_plugin`, so sweep every active mapped
		// slug rather than the (unmapped) zip-ai slug.
		$sweep_self = ( self::self_slug() === $slug );

		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					if ( $sweep_self ) {
						self::sweep_active_mapped_plugins();
					} else {
						self::enable_for_slug( $slug );
					}
				} finally {
					restore_current_blog();
				}
			}
			return;
		}

		if ( $sweep_self ) {
			self::sweep_active_mapped_plugins();
			return;
		}

		self::enable_for_slug( $slug );
	}

	/**
	 * This plugin's own folder slug, derived from its real install path rather
	 * than assumed to be `zip-ai`. A branch zip from GitHub unpacks as
	 * `zip-ai-<branch>/` and the legacy folder was `zipwp-mcp/` — hardcoding
	 * the wp.org slug makes the self-activation sweep silently no-op on exactly
	 * the install shapes QA uses.
	 *
	 * @param string $plugin_file Absolute plugin-file path to derive from.
	 *                            Defaults to this plugin's entry file.
	 */
	public static function self_slug( string $plugin_file = '' ): string {
		$file = '' !== $plugin_file ? $plugin_file : ZIPAI_MCP_FILE;
		return strtolower( dirname( plugin_basename( $file ) ) );
	}

	/**
	 * Apply toggles for every currently-active MAPped plugin on the current
	 * site. Covers activation orderings the `activated_plugin` hook misses — a
	 * mapped plugin already active before ZIP AI, or before the site connected
	 * to ERA. Idempotent: `enable_for_slug` re-applies harmlessly.
	 *
	 * @return void
	 */
	public static function sweep_active_mapped_plugins(): void {
		foreach ( self::active_plugin_slugs() as $slug ) {
			if ( array_key_exists( $slug, self::MAP ) ) {
				self::enable_for_slug( $slug );
			}
		}
	}

	/**
	 * Folder slugs of plugins active on the current site (plus network-active
	 * plugins on multisite).
	 *
	 * @return array<int,string>
	 */
	private static function active_plugin_slugs(): array {
		$files = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$slugs = array();
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || false === strpos( $file, '/' ) ) {
				continue;
			}
			$slug = strtolower( explode( '/', $file )[0] );
			if ( '' !== $slug ) {
				$slugs[ $slug ] = true;
			}
		}

		return array_keys( $slugs );
	}

	/**
	 * In-memory plugin abilities map.
	 *
	 * Add new plugins here by slug with an `operations` list.
	 */
	private const MAP = array(
		'sureforms'      => array(
			'operations' => array(
				array(
					'type'  => 'set_option',
					'name'  => 'srfm_abilities_api',
					'value' => '1',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'srfm_abilities_api_edit',
					'value' => '1',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'srfm_abilities_api_delete',
					'value' => '1',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'srfm_mcp_server',
					'value' => '1',
				),
				array(
					// Merge, never whole-value replace: a future SureForms
					// release adding a fifth key to this option would lose it
					// on any ZIP-AI-driven activation otherwise (the same
					// reason the surerank/astra entries below use this type).
					'type'   => 'set_option_array_keys',
					'name'   => 'srfm_mcp_settings_options',
					'values' => array(
						'srfm_abilities_api'        => true,
						'srfm_abilities_api_edit'   => true,
						'srfm_abilities_api_delete' => true,
						'srfm_mcp_server'           => true,
					),
				),
				array(
					'type' => 'call_function_if_exists',
					'name' => 'srfm_save_mcp_settings',
					'args' => array(
						array(
							'srfm_abilities_api'        => true,
							'srfm_abilities_api_edit'   => true,
							'srfm_abilities_api_delete' => true,
							'srfm_mcp_server'           => true,
						),
					),
				),
				// Suppress post-activation redirect to onboarding wizard.
				array(
					'type' => 'delete_option',
					'name' => '__srfm_do_redirect',
				),
			),
		),
		'suremails'      => array(
			'operations' => array(
				array(
					'type'  => 'set_option',
					'name'  => 'suremails_abilities_api_edit',
					'value' => '1',
				),
				// Suppress post-activation redirect to onboarding wizard.
				array(
					'type' => 'delete_option',
					'name' => 'suremails_do_redirect',
				),
			),
		),
		'spectra-blocks' => array(
			'operations' => array(
				// Unlock Spectra Blocks' MCP abilities so the ZIP AI chat can use
				// them. All three gates default to 'disabled' (string) — read
				// abilities + wp_abilities_api_init, write abilities, and the
				// dedicated MCP server respectively.
				array(
					'type'  => 'set_option',
					'name'  => 'spectra_blocks_enable_abilities',
					'value' => 'enabled',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'spectra_blocks_enable_edit_abilities',
					'value' => 'enabled',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'spectra_blocks_enable_mcp_server',
					'value' => 'enabled',
				),
				// Suppress post-activation redirect to onboarding wizard.
				// `__spectra_blocks_do_redirect` is set true in the plugin's
				// activation hook and consumed once in
				// Spectra_Blocks_Onboarding::maybe_redirect_to_onboarding().
				array(
					'type' => 'delete_option',
					'name' => '__spectra_blocks_do_redirect',
				),
			),
		),
		'surecart'       => array(
			'operations' => array(
				array(
					'type'  => 'set_option',
					'name'  => 'surecart_mcp_abilities_enabled',
					'value' => '1',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'surecart_mcp_edit_abilities_enabled',
					'value' => '1',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'surecart_mcp_delete_abilities_enabled',
					'value' => '1',
				),
				// Mark zipwp as the SureCart referrer source for marketing attribution.
				array(
					'type'  => 'set_option_if_absent',
					'name'  => 'surecart_source',
					'value' => 'zipwp',
				),
			),
		),
		'surerank'       => array(
			'operations' => array(
				// Suppress post-activation redirect + mark onboarding complete.
				array(
					'type' => 'delete_option',
					'name' => 'surerank_redirect_on_activation',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'surerank_onboarding_completed',
					'value' => true,
				),
				// Enable SureRank's MCP integration on activation (SureForms-style):
				// flip its existing `enable_mcp` setting, which registers SureRank's
				// abilities into the shared WP Abilities registry (and its dedicated
				// MCP server) so the server can use them — notably
				// surerank/update-post-seo for page-level SEO. Merged into
				// surerank_settings so other keys are preserved.
				array(
					'type'   => 'set_option_array_keys',
					'name'   => 'surerank_settings',
					'values' => array( 'enable_mcp' => true ),
				),
			),
		),
		'suredonation'   => array(
			'operations' => array(
				// Enable SureDonation's Abilities API (read + edit + delete) plus its
				// dedicated MCP server so the ZIP AI chat can read AND modify
				// campaigns/donations on the built site — a strict mirror of the
				// SureForms entry above. These toggles live NESTED under
				// `suredonation_options['ai_settings']` (the plugin's
				// `SureDonation\Inc\Helper::OPTION_NAME`) and are read at plugin boot
				// in `suredonation.php`; SureForms uses flat `srfm_*` options instead.
				array(
					'type'   => 'set_nested_option_keys',
					'name'   => 'suredonation_options',
					'path'   => 'ai_settings',
					'values' => array(
						'enable_abilities' => true,
						'allow_updates'    => true,
						'allow_delete'     => true,
						'mcp_server'       => true,
					),
				),
				// Suppress the post-activation redirect to the onboarding wizard.
				// `__suredonation_do_redirect` is set on activation and consumed once
				// in `inc/onboarding.php` maybe_redirect_to_onboarding().
				array(
					'type' => 'delete_option',
					'name' => '__suredonation_do_redirect',
				),
			),
		),
		'surecookie'     => array(
			'operations' => array(
				// Unlock SureCookie's MCP abilities. `enable_mcp` lives in the
				// nested `surecookie_settings` option and gates both the Abilities
				// API integration and the dedicated MCP server (mcp/server.php).
				array(
					'type'   => 'set_option_array_keys',
					'name'   => 'surecookie_settings',
					'values' => array(
						'enable_mcp' => true,
					),
				),
				// Suppress post-activation redirect to onboarding wizard.
				// `surecookie_do_activation_redirect` is set true on activation
				// and consumed once in loader.php (redirects to
				// admin.php?page=surecookie-onboarding).
				array(
					'type' => 'delete_option',
					'name' => 'surecookie_do_activation_redirect',
				),
			),
		),
		'suremembers'    => array(
			'operations' => array(
				// Unlock SureMembers' MCP abilities (read + edit + delete + MCP
				// server). These keys live nested in the `suremembers_abilities_settings`
				// option (inc/services/abilities/abilities-settings.php) and gate the
				// membership read/grant/revoke/delete abilities exposed to ZIP AI.
				array(
					'type'   => 'set_option_array_keys',
					'name'   => 'suremembers_abilities_settings',
					'values' => array(
						'suremembers_abilities_api'        => true,
						'suremembers_abilities_api_edit'   => true,
						'suremembers_abilities_api_delete' => true,
						'suremembers_mcp_server'           => true,
					),
				),
				// Suppress post-activation redirect to onboarding wizard.
				// `__suremembers_do_redirect` is set true in inc/activator.php and
				// consumed once in plugin-loader.php (redirects to
				// admin.php?page=suremembers-onboarding).
				array(
					'type' => 'delete_option',
					'name' => '__suremembers_do_redirect',
				),
			),
		),
		'astra-sites'    => array(
			'operations' => array(
				array(
					'type' => 'delete_option',
					'name' => 'st_start_onboarding',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'astra_sites_import_complete',
					'value' => '1',
				),
			),
		),
		'latepoint'      => array(
			'operations' => array(
				array(
					'type' => 'delete_option',
					'name' => 'latepoint_redirect_to_wizard',
				),
			),
		),
		'suretriggers'   => array(
			'operations' => array(
				array(
					'type' => 'delete_transient',
					'name' => 'st-redirect-after-activation',
				),
				array(
					'type'  => 'set_option_if_absent',
					'name'  => 'suretriggers_source',
					'value' => 'zipwp',
				),
			),
		),
		'cartflows'      => array(
			'operations' => array(
				array(
					'type'  => 'set_option',
					'name'  => 'wcf_start_onboarding',
					'value' => 'false',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'wcf_setup_skipped',
					'value' => 'true',
				),
			),
		),
		'modern-cart'    => array(
			'operations' => array(
				array(
					'type'  => 'set_option',
					'name'  => 'moderncart_is_onboarding_complete',
					'value' => 'yes',
				),
				array(
					'type' => 'delete_transient',
					'name' => 'moderncart_redirect_to_onboarding',
				),
			),
		),
		'suredash'       => array(
			'operations' => array(
				// Canonical API — `SureDashboard\Inc\Modules\MCP\Module::save_settings`
				// merges the four toggles into `portal_settings` and updates the
				// SUREDASHBOARD_SETTINGS option in one call. Plugin namespace is
				// `SureDashboard` (not `SureDash`); class lives at
				// inc/modules/mcp/module.php.
				array(
					'type'   => 'call_static_method_if_exists',
					'class'  => '\\SureDashboard\\Inc\\Modules\\MCP\\Module',
					'method' => 'save_settings',
					'args'   => array(
						array(
							'suredash_abilities_api'      => true,
							'suredash_abilities_api_edit' => true,
							'suredash_abilities_api_delete' => true,
							'suredash_mcp_server'         => true,
						),
					),
				),
				// Fallback — write `portal_settings` keys directly when the
				// canonical class isn't loaded (e.g. autoloader race during
				// activation). Same key names as the canonical settings shape.
				array(
					'type' => 'set_portal_settings_true_if_exists',
					'keys' => array(
						'suredash_abilities_api',
						'suredash_abilities_api_edit',
						'suredash_abilities_api_delete',
						'suredash_mcp_server',
					),
				),
				// Suppress post-activation redirect to onboarding wizard.
				// `__suredash_do_redirect` is set true in activation_actions
				// and consumed in activation_redirect (loader.php).
				array(
					'type' => 'delete_option',
					'name' => '__suredash_do_redirect',
				),
				array(
					'type'  => 'set_option',
					'name'  => 'suredash_onboarding_completed',
					'value' => true,
				),
			),
		),
		'astra'          => array(
			'operations' => array(
				array(
					'type'   => 'set_option_array_keys_if_exists',
					'name'   => 'astra_admin_settings',
					'values' => array(
						'enable_abilities'      => true,
						'enable_edit_abilities' => true,
						'enable_mcp_server'     => true,
					),
				),
			),
		),
	);

	/**
	 * Enable MCP abilities for a plugin slug when mapped.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>
	 */
	public static function enable_for_slug( string $slug ): array {
		$slug = sanitize_key( $slug );
		$map  = self::MAP;

		if ( ! isset( $map[ $slug ] ) ) {
			return array(
				'handled' => false,
				'slug'    => $slug,
				'mode'    => 'none',
				'applied' => 0,
			);
		}

		$operations = $map[ $slug ]['operations'];

		$applied = 0;
		$mode    = 'operations';

		foreach ( $operations as $operation ) {
			if ( self::run_operation( $operation ) ) {
				++$applied;
			}
			if ( 'set_portal_settings_true_if_exists' === $operation['type'] ) {
				$mode = 'portal_settings';
			}
		}

		return array(
			'handled' => true,
			'slug'    => $slug,
			'mode'    => $mode,
			'applied' => $applied,
		);
	}

	/**
	 * Read a string value from an operation array, defaulting to ''.
	 *
	 * @param array<string,mixed> $operation Operation config.
	 * @param string              $key       Key to read.
	 */
	private static function str_value( array $operation, string $key ): string {
		$value = $operation[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Coerce an unknown value into a string-keyed array.
	 *
	 * @param mixed $value Raw value from an operation descriptor.
	 * @return array<string,mixed>
	 */
	private static function assoc( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ (string) $key ] = $item;
		}
		return $out;
	}

	/**
	 * Execute one operation.
	 *
	 * @param array<string,mixed> $operation Operation config.
	 * @return bool Whether it applied a change/action.
	 */
	private static function run_operation( array $operation ): bool {
		$type = self::str_value( $operation, 'type' );

		switch ( $type ) {
			case 'set_option':
				$name = self::str_value( $operation, 'name' );
				if ( '' === $name ) {
					return false;
				}
				$value = $operation['value'] ?? null;
				update_option( $name, $value );
				return true;

			case 'set_option_if_absent':
				$name = self::str_value( $operation, 'name' );
				if ( '' === $name ) {
					return false;
				}
				$value = $operation['value'] ?? null;
				return add_option( $name, $value, '', false );

			case 'delete_option':
				$name = self::str_value( $operation, 'name' );
				if ( '' === $name ) {
					return false;
				}
				delete_option( $name );
				return true;

			case 'delete_transient':
				$name = self::str_value( $operation, 'name' );
				if ( '' === $name ) {
					return false;
				}
				delete_transient( $name );
				return true;

			case 'call_function_if_exists':
				$name = self::str_value( $operation, 'name' );
				$args = isset( $operation['args'] ) && is_array( $operation['args'] ) ? $operation['args'] : array();
				if ( '' === $name || ! function_exists( $name ) ) {
					return false;
				}
				call_user_func_array( $name, $args );
				return true;

			case 'call_static_method_if_exists':
				$class  = self::str_value( $operation, 'class' );
				$method = self::str_value( $operation, 'method' );
				$args   = isset( $operation['args'] ) && is_array( $operation['args'] ) ? $operation['args'] : array();
				if ( '' === $class || '' === $method || ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
					return false;
				}
				$callback = array( $class, $method );
				if ( ! is_callable( $callback ) ) {
					return false;
				}
				call_user_func_array( $callback, $args );
				return true;

			case 'call_first_supported':
				$calls = isset( $operation['calls'] ) && is_array( $operation['calls'] ) ? $operation['calls'] : array();
				foreach ( $calls as $call_op ) {
					if ( ! is_array( $call_op ) ) {
						continue;
					}
					if ( self::run_operation( self::assoc( $call_op ) ) ) {
						return true;
					}
				}
				return false;

			case 'set_portal_settings_true_if_exists':
				$keys = isset( $operation['keys'] ) && is_array( $operation['keys'] ) ? array_values( $operation['keys'] ) : array();
				return self::set_portal_settings_true_if_exists( $keys );

			case 'set_option_array_keys':
				$name   = self::str_value( $operation, 'name' );
				$values = self::assoc( $operation['values'] ?? null );
				if ( '' === $name || empty( $values ) ) {
					return false;
				}
				return self::set_option_array_keys( $name, $values );

			case 'set_option_array_keys_if_exists':
				$name   = self::str_value( $operation, 'name' );
				$values = self::assoc( $operation['values'] ?? null );
				if ( '' === $name || empty( $values ) ) {
					return false;
				}
				return self::set_option_array_keys_if_exists( $name, $values );

			case 'set_nested_option_keys':
				$name   = self::str_value( $operation, 'name' );
				$path   = self::str_value( $operation, 'path' );
				$values = self::assoc( $operation['values'] ?? null );
				if ( '' === $name || '' === $path || empty( $values ) ) {
					return false;
				}
				return self::set_nested_option_keys( $name, $path, $values );

			default:
				return false;
		}
	}

	/**
	 * Set known keys to true only when `portal_settings` exists and keys exist.
	 *
	 * @param array<int,mixed> $keys Candidate keys.
	 * @return bool Whether any value was updated.
	 */
	private static function set_portal_settings_true_if_exists( array $keys ): bool {
		$settings = get_option( 'portal_settings', null );
		if ( ! is_array( $settings ) ) {
			return false;
		}

		$changed = false;
		$truthy  = array( 1, true, '1', 'true' );

		foreach ( $keys as $raw_key ) {
			$key = is_string( $raw_key ) ? $raw_key : '';
			if ( '' === $key || ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			if ( in_array( $settings[ $key ], $truthy, true ) ) {
				continue;
			}
			$settings[ $key ] = true;
			$changed          = true;
		}

		if ( $changed ) {
			update_option( 'portal_settings', $settings );
		}

		return $changed;
	}

	/**
	 * Merge key/value pairs into an option array.
	 *
	 * @param string              $option_name Option key.
	 * @param array<string,mixed> $values      Keys to upsert.
	 * @return bool Whether changes were applied.
	 */
	private static function set_option_array_keys( string $option_name, array $values ): bool {
		$current = get_option( $option_name, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$changed = false;
		foreach ( $values as $key => $value ) {
			if ( '' === $key ) {
				continue;
			}
			if ( array_key_exists( $key, $current ) && $current[ $key ] === $value ) {
				continue;
			}
			$current[ $key ] = $value;
			$changed         = true;
		}

		if ( $changed ) {
			update_option( $option_name, $current );
		}

		return $changed;
	}

	/**
	 * Merge key/value pairs into an option array when the option already exists.
	 *
	 * Prevents pre-emptively creating option stubs before the plugin's own
	 * bootstrap writes defaults.
	 *
	 * @param string              $option_name Option key.
	 * @param array<string,mixed> $values      Keys to upsert.
	 * @return bool Whether changes were applied.
	 */
	private static function set_option_array_keys_if_exists( string $option_name, array $values ): bool {
		$current = get_option( $option_name, null );
		if ( null === $current || ! is_array( $current ) ) {
			return false;
		}
		return self::set_option_array_keys( $option_name, $values );
	}

	/**
	 * Merge key/value pairs into a sub-array nested one level inside an option.
	 *
	 * For settings stored as `option[ $path ][ $key ] = $value` — e.g.
	 * SureDonation keeps its Abilities/MCP toggles at
	 * `suredonation_options['ai_settings']`. Read-modify-write so sibling keys
	 * the plugin or the site owner already set are preserved; the option and
	 * the sub-array are created when absent (the toggler runs on
	 * `activated_plugin`, AFTER the plugin's own activation defaults, so in
	 * practice this only ever merges).
	 *
	 * @param string              $option_name Top-level option key.
	 * @param string              $path        Sub-array key inside the option.
	 * @param array<string,mixed> $values      Keys to upsert into the sub-array.
	 * @return bool Whether changes were applied.
	 */
	private static function set_nested_option_keys( string $option_name, string $path, array $values ): bool {
		$current = get_option( $option_name, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$sub = isset( $current[ $path ] ) && is_array( $current[ $path ] ) ? $current[ $path ] : array();

		$changed = false;
		foreach ( $values as $key => $value ) {
			if ( '' === $key ) {
				continue;
			}
			if ( array_key_exists( $key, $sub ) && $sub[ $key ] === $value ) {
				continue;
			}
			$sub[ $key ] = $value;
			$changed     = true;
		}

		if ( $changed ) {
			$current[ $path ] = $sub;
			update_option( $option_name, $current );
		}

		return $changed;
	}
}
