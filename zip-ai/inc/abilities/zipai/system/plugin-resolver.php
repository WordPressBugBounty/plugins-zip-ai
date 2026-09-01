<?php
/**
 * Shared slug → plugin-file resolver for the plugin-lifecycle abilities.
 *
 * Activate / Deactivate / Delete all need to map an LLM-supplied slug
 * (or `folder/file` identifier) to the actual `plugin_file` key
 * WordPress core uses internally. Centralise the matching rules so
 * the four abilities agree on what shapes they accept and how the
 * lookup falls back.
 *
 * Accepted input shapes:
 *   - bare folder slug:                 "contact-form-7"
 *   - WP REST plugin id (no extension): "contact-form-7/wp-contact-form-7"
 *   - WP REST plugin id (with .php):    "contact-form-7/wp-contact-form-7.php"
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\System;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PluginResolver {

	/**
	 * Whether a resolved plugin file is ZIP AI itself — the plugin serving
	 * this very MCP request. Lifecycle abilities refuse to deactivate or
	 * delete it: doing so severs the assistant's own transport (chat, tools,
	 * and the in-flight session) — observed live 2026-08-06, where a bulk
	 * "deactivate the rest" sweep took the agent down mid-plan. Toggling
	 * ZIP AI off is supported only from wp-admin → Plugins, outside MCP.
	 *
	 * @param string $plugin_file Canonical `folder/file.php` key.
	 * @return bool
	 */
	public static function is_self( $plugin_file ) {
		return plugin_basename( ZIPAI_MCP_FILE ) === $plugin_file;
	}

	/**
	 * Resolve a slug-shaped identifier to the actual `folder/file.php`
	 * key WP core uses for installed plugins. Returns null when no
	 * installed plugin matches.
	 *
	 * Callers MUST `require_once ABSPATH . 'wp-admin/includes/plugin.php'`
	 * before invoking — `get_plugins()` is a wp-admin function.
	 *
	 * @param string $slug Slug, `folder/file`, or `folder/file.php`.
	 * @return string|null `folder/file.php` key, or null if not installed.
	 */
	public static function resolve_plugin_file( $slug ) {
		$slug    = (string) $slug;
		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();

		if ( array() === $plugins ) {
			return null;
		}

		// Direct match: caller already provided the canonical key.
		if ( isset( $plugins[ $slug ] ) ) {
			return $slug;
		}

		// Caller provided `folder/file` without `.php` — try appending.
		if ( isset( $plugins[ $slug . '.php' ] ) ) {
			return $slug . '.php';
		}

		// Bare-slug match against the folder component of every key. Root-level
		// single-file plugins are eligible here — "the user named this file" is
		// the intent for activate/deactivate/delete.
		return self::match_folder_slug( $plugins, $slug, false );
	}

	/**
	 * Resolve a bare WordPress.org folder slug to its installed
	 * `folder/file.php` key, matching ONLY the folder component.
	 *
	 * Unlike resolve_plugin_file(), this does NOT match a root-level
	 * single-file plugin whose bare filename equals the slug (e.g. a
	 * `redirects.php` snippet for slug "redirects"). PluginInstall needs proof
	 * that the wp.org *folder-slug* plugin is present; a bare-filename match is
	 * a false positive there — it would report an unrelated file as the plugin
	 * and, with `status:active`, activate it. Activate / deactivate / delete
	 * keep resolve_plugin_file(), where "the user named this file" is the
	 * intent and a root-file match is correct.
	 *
	 * Callers MUST `require_once ABSPATH . 'wp-admin/includes/plugin.php'`
	 * before invoking — `get_plugins()` is a wp-admin function.
	 *
	 * @param string $slug Bare folder slug (e.g. "sureforms").
	 * @return string|null `folder/file.php` key, or null if not installed.
	 */
	public static function resolve_installed_folder( $slug ) {
		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();

		// Skip root-level single-file plugins — PluginInstall needs proof the
		// wp.org *folder-slug* plugin is present, so a bare-filename hit is a
		// false positive here.
		return self::match_folder_slug( $plugins, (string) $slug, true );
	}

	/**
	 * Return the first installed plugin key whose folder component (case-
	 * insensitively) equals $slug, or null. When $skip_root is true, root-level
	 * single-file plugins (no folder, e.g. "hello.php") are ignored.
	 *
	 * @param array<array<mixed>> $plugins   get_plugins() result.
	 * @param string              $slug      Bare folder slug.
	 * @param bool                $skip_root Whether to skip root-level single-file plugins.
	 * @return string|null `folder/file.php` key, or null if no match.
	 */
	private static function match_folder_slug( $plugins, $slug, $skip_root ) {
		// Prefer the conventional main file (`slug/slug.php`) — a folder shipping
		// two files with plugin headers otherwise resolves to whichever
		// get_plugins() lists first, and activate/deactivate hits the wrong one.
		$lower_slug = strtolower( $slug );
		$fallback   = null;
		foreach ( $plugins as $file => $_data ) {
			$file = (string) $file;
			if ( false === strpos( $file, '/' ) ) {
				if ( $skip_root ) {
					continue;
				}
				$folder = $file;
			} else {
				$folder = explode( '/', $file, 2 )[0];
			}
			if ( '' === $folder || strtolower( $folder ) !== $lower_slug ) {
				continue;
			}
			if ( strtolower( $file ) === $lower_slug . '/' . $lower_slug . '.php' ) {
				return $file;
			}
			if ( null === $fallback ) {
				$fallback = $file;
			}
		}

		return $fallback;
	}
}
