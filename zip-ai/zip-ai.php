<?php
/**
 * Plugin Name: ZIP AI
 * Description: ZIP AI is a conversational AI agent that builds, edits, and manages your WordPress site by chat - create pages, edit blocks, and run site operations right inside wp-admin. Works with Astra and block (FSE) themes.
 * Author: Brainstorm Force
 * Author URI: https://brainstormforce.com/
 * Plugin URI: https://zipwp.com/
 * Version: 0.0.13
 * Requires at least: 6.4
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zip-ai
 * Domain Path: /languages
 *
 * External Services:
 * This plugin connects to the ZIP AI platform (https://credits.zipwp.com)
 * to provide AI-powered site management. Chat messages, site structure data (page titles,
 * plugin names, post counts), site identity, and the results of the WordPress tools the
 * agent runs at your request (which can include post and page content, option values, and
 * command output) are sent to this service after user authentication. No visitor data or
 * payment details are transmitted.
 * Terms of Service: https://store.brainstormforce.com/terms-and-conditions/
 * Privacy Policy: https://store.brainstormforce.com/privacy-policy/
 *
 * @package zip-ai
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Exit if ZipWP MCP is already loaded.
if ( defined( 'ZIPAI_MCP_DIR' ) ) {
	return;
}

// Load the plugin (registers the autoloader and boots everything).
require_once __DIR__ . '/loader.php';
