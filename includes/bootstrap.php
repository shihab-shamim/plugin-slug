<?php
/**
 * Live Chats system bootstrapper.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

define('LIVE_CHATS_DB_VERSION', '1');
define('LIVE_CHATS_CAPS_VERSION', '1');
define('LIVE_CHATS_COOKIE', 'live_chats_token');
define('LIVE_CHATS_CAP_OPERATE', 'live_chats_operate');
define('LIVE_CHATS_CAP_MANAGE', 'live_chats_manage');
define('LIVE_CHATS_MAX_MESSAGE', 4000);
define('LIVE_CHATS_LIST_LIMIT', 50);

require_once LIVE_CHATS_DIR . '/includes/class-data.php';
require_once LIVE_CHATS_DIR . '/includes/class-installer.php';
require_once LIVE_CHATS_DIR . '/includes/class-auth.php';
require_once LIVE_CHATS_DIR . '/includes/class-render.php';
require_once LIVE_CHATS_DIR . '/includes/class-engine.php';
require_once LIVE_CHATS_DIR . '/includes/class-ajax.php';
require_once LIVE_CHATS_DIR . '/includes/class-rest-controller.php';
require_once LIVE_CHATS_DIR . '/includes/class-widget.php';
require_once LIVE_CHATS_DIR . '/includes/class-admin-pages.php';
require_once LIVE_CHATS_DIR . '/includes/class-privacy.php';

/**
 * Activation hook: install DB tables, seed canned responses, grant capabilities.
 */
register_activation_hook(LIVE_CHATS_FILE, array('LiveChats_Installer', 'install'));

/**
 * Bootstrap hooks on plugins_loaded.
 */
add_action('plugins_loaded', 'live_chats_boot', 5);
function live_chats_boot() {
	LiveChats_Data::register_tables();
	add_action('switch_blog', array('LiveChats_Data', 'register_tables'));

	// Auto-install/upgrade if DB version is missing or outdated.
	if (get_option('live_chats_db_version') !== LIVE_CHATS_DB_VERSION) {
		LiveChats_Installer::install();
	}

	if (get_option('live_chats_caps_version') !== LIVE_CHATS_CAPS_VERSION) {
		LiveChats_Installer::register_capabilities();
		update_option('live_chats_caps_version', LIVE_CHATS_CAPS_VERSION);
	}

	add_filter('user_has_cap', array('LiveChats_Auth', 'filter_user_has_cap'), 10, 4);

	add_action('set_user_role', array('LiveChats_Auth', 'sync_account_role'));
	add_action('add_user_role', array('LiveChats_Auth', 'sync_account_role'));
	add_action('remove_user_role', array('LiveChats_Auth', 'sync_account_role'));
	add_action('delete_user', function ($uid) {
		LiveChats_Auth::sync_account_role($uid, true);
	});

	add_shortcode('live_chats', array('LiveChats_Widget', 'render'));
	add_action('wp_footer', 'live_chats_maybe_render_footer_widget');
	add_action('wp_enqueue_scripts', 'live_chats_enqueue_front_assets');

	add_action('rest_api_init', function () {
		$controller = new LiveChats_REST_Controller();
		$controller->register_routes();
	});

	if (wp_doing_ajax()) {
		LiveChats_Ajax::register();
	}

	if (is_admin()) {
		LiveChats_Privacy::register_hooks();
		add_action('admin_menu', array('LiveChats_Admin_Pages', 'register_menu'));
		add_action('admin_enqueue_scripts', 'live_chats_enqueue_admin_assets');
		add_filter('plugin_action_links_' . plugin_basename(LIVE_CHATS_FILE), 'live_chats_plugin_action_links');

		if (get_option('live_chats_schema_missing')) {
			add_action('admin_notices', 'live_chats_schema_notice');
		}
	}
}

function live_chats_plugin_action_links($links) {
	$extra = array(
		'<a href="' . esc_url(admin_url('admin.php?page=live-chats-inbox')) . '">' . esc_html__('Live Inbox', 'live-chats') . '</a>',
		'<a href="' . esc_url(admin_url('admin.php?page=live-chats-settings')) . '">' . esc_html__('Settings', 'live-chats') . '</a>',
	);
	return array_merge($extra, $links);
}

function live_chats_schema_notice() {
	if (!current_user_can('activate_plugins')) {
		return;
	}
	$missing = (array) get_option('live_chats_schema_missing');
	if (!$missing) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>Live Chats:</strong> '
		. esc_html__('Some database tables or columns are missing. Please deactivate and reactivate the plugin to repair.', 'live-chats')
		. ' <code>' . esc_html(implode(', ', $missing)) . '</code></p></div>';
}

function live_chats_asset_ver($relative_path) {
	$path  = LIVE_CHATS_DIR . '/' . ltrim($relative_path, '/');
	$mtime = file_exists($path) ? filemtime($path) : 0;
	return $mtime ? LIVE_CHATS_VERSION . '.' . $mtime : LIVE_CHATS_VERSION;
}

function live_chats_widget_should_load() {
	if (is_admin()) {
		return false;
	}
	if ((int) get_option('live_chats_auto_display', 1) === 1) {
		return true;
	}
	$post = get_post();
	return $post instanceof WP_Post && has_shortcode($post->post_content, 'live_chats');
}

function live_chats_maybe_render_footer_widget() {
	if ((int) get_option('live_chats_auto_display', 1) !== 1) {
		return;
	}
	echo LiveChats_Render::kses(LiveChats_Widget::render()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function live_chats_enqueue_front_assets() {
	if (!live_chats_widget_should_load()) {
		return;
	}

	wp_enqueue_style(
		'live-chats-widget',
		LIVE_CHATS_URL . '/assets/css/widget.css',
		array(),
		live_chats_asset_ver('assets/css/widget.css')
	);

	wp_add_inline_style(
		'live-chats-widget',
		'#live-chats-widget{' . LiveChats_Data::generate_theme_vars(get_option('live_chats_primary_color', '#2563eb')) . '}'
	);

	wp_enqueue_script(
		'live-chats-widget',
		LIVE_CHATS_URL . '/assets/js/widget.js',
		array('jquery'),
		live_chats_asset_ver('assets/js/widget.js'),
		true
	);

	$has_session = (bool) LiveChats_Auth::current_visitor_account() || is_user_logged_in();

	wp_localize_script('live-chats-widget', 'liveChatsConfig', array(
		'ajaxUrl'      => admin_url('admin-ajax.php'),
		'restUrl'      => esc_url_raw(rest_url('live-chats/v1/')),
		'nonce'        => wp_create_nonce('live_chats_guest_nonce'),
		'restNonce'    => wp_create_nonce('wp_rest'),
		'maxMessage'   => LIVE_CHATS_MAX_MESSAGE,
		'widgetMode'   => get_option('live_chats_widget_mode', 'hub'),
		'showHome'     => (int) get_option('live_chats_show_home_tab', 1),
		'isLoggedIn'   => is_user_logged_in(),
		'hasSession'   => $has_session,
		'siteName'     => get_bloginfo('name'),
	));
}

function live_chats_enqueue_admin_assets($hook) {
	$hook = (string) $hook;
	if (strpos($hook, 'live-chats') === false || !LiveChats_Auth::user_can('live_chats_view')) {
		return;
	}

	wp_enqueue_style(
		'live-chats-admin',
		LIVE_CHATS_URL . '/assets/css/admin.css',
		array(),
		live_chats_asset_ver('assets/css/admin.css')
	);

	if (strpos($hook, 'live-chats-inbox') !== false) {
		wp_enqueue_script(
			'live-chats-admin',
			LIVE_CHATS_URL . '/assets/js/admin.js',
			array('jquery'),
			live_chats_asset_ver('assets/js/admin.js'),
			true
		);

		wp_localize_script('live-chats-admin', 'liveChatsAdminConfig', array(
			'ajaxUrl'    => admin_url('admin-ajax.php'),
			'restUrl'    => esc_url_raw(rest_url('live-chats/v1/')),
			'nonce'      => wp_create_nonce('live_chats_admin_nonce'),
			'restNonce'  => wp_create_nonce('wp_rest'),
			'maxMessage' => LIVE_CHATS_MAX_MESSAGE,
			'canManage'  => LiveChats_Auth::user_can(LIVE_CHATS_CAP_MANAGE),
		));
	}

	if (strpos($hook, 'live-chats-settings') !== false) {
		wp_enqueue_script(
			'live-chats-settings-preview',
			LIVE_CHATS_URL . '/assets/js/settings-preview.js',
			array('jquery'),
			live_chats_asset_ver('assets/js/settings-preview.js'),
			true
		);
	}
}
