<?php
/**
 * Plugin Name: Live Chats
 * Description: Live Chats is a WordPress plugin that allows you to add interactive live chat widgets to your website.
 * Version: 1.0.0
 * Author: bPlugins
 * Author URI: https://bplugins.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: live-chats
 */

// ABS PATH
if ( !defined( 'ABSPATH' ) ) { exit; }

// Constant
define( 'DFORC_VERSION', isset( $_SERVER['HTTP_HOST'] ) && 'localhost' === $_SERVER['HTTP_HOST'] ? time() : '1.0.0' );
define( 'DFORC_DIR_URL', plugin_dir_url( __FILE__ ) );
define( 'DFORC_DIR_PATH', plugin_dir_path( __FILE__ ) );

// Live Chats Constants
if ( ! defined( 'LIVE_CHATS_VERSION' ) ) {
	define( 'LIVE_CHATS_VERSION', '1.0.0' );
	define( 'LIVE_CHATS_FILE', __FILE__ );
	define( 'LIVE_CHATS_DIR', dirname( __FILE__ ) );
	define( 'LIVE_CHATS_URL', rtrim( plugin_dir_url( __FILE__ ), '/' ) );
}

require_once LIVE_CHATS_DIR . '/includes/bootstrap.php';

if( !class_exists( 'DFORCPlugin' ) ){
	class DFORCPlugin{
		function __construct(){
			add_action( 'init', [ $this, 'onInit' ] );
		}

		function onInit(){
			register_block_type( __DIR__ . '/build' );
		}
	}
	new DFORCPlugin();
}