<?php
/**
 * Uninstall cleanup for Live Chats plugin.
 *
 * @package LiveChats
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

if (get_option('live_chats_db_version')) {
	$tables = array(
		'lc_messages',
		'lc_participants',
		'lc_conversations',
		'lc_accounts',
		'lc_canned_responses',
	);
	foreach ($tables as $t) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $t));
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query($wpdb->prepare(
	"DELETE FROM %i
	 WHERE option_name LIKE %s
	    OR option_name LIKE %s
	    OR option_name LIKE %s",
	$wpdb->options,
	$wpdb->esc_like('live_chats_') . '%',
	$wpdb->esc_like('_transient_lc_') . '%',
	$wpdb->esc_like('_transient_timeout_lc_') . '%'
));
wp_cache_delete('alloptions', 'options');

$caps = array(
	'live_chats_operate',
	'live_chats_manage',
	'live_chats_view',
	'live_chats_reply',
	'live_chats_assign',
	'live_chats_manage_agents',
	'live_chats_manage_flows',
	'live_chats_manage_settings',
);

foreach (array_keys(wp_roles()->get_names()) as $slug) {
	$role = get_role($slug);
	if ($role) {
		foreach ($caps as $cap) {
			$role->remove_cap($cap);
		}
	}
}
