<?php
/**
 * WordPress GDPR Privacy Exporter, Eraser, and Suggested Policy Content.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class LiveChats_Privacy {

	public static function register_hooks() {
		add_action('admin_init', array(__CLASS__, 'add_privacy_policy_content'));
		add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));
		add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'register_eraser'));
	}

	public static function add_privacy_policy_content() {
		if (!function_exists('wp_add_privacy_policy_content')) {
			return;
		}

		$content = '<p>' . esc_html__('When you use the Live Chats widget on this site, we store the name and email address you enter, your chat messages and uploaded attachments, your IP address, browsing page URL/referrer, browser/OS details, and any post-chat feedback you submit.', 'live-chats') . '</p>'
			. '<p>' . esc_html__('Live Chats sets an HttpOnly cookie (live_chats_token) to maintain your chat session for up to 30 days and stores your name/email in browser local storage for convenience.', 'live-chats') . '</p>';

		wp_add_privacy_policy_content('Live Chats', wp_kses_post($content));
	}

	public static function register_exporter($exporters) {
		$exporters['live-chats'] = array(
			'exporter_friendly_name' => __('Live Chats', 'live-chats'),
			'callback'               => array(__CLASS__, 'export_personal_data'),
		);
		return $exporters;
	}

	public static function register_eraser($erasers) {
		$erasers['live-chats'] = array(
			'eraser_friendly_name' => __('Live Chats', 'live-chats'),
			'callback'             => array(__CLASS__, 'erase_personal_data'),
		);
		return $erasers;
	}

	private static function guest_accounts($email) {
		global $wpdb;
		$email = sanitize_email($email);
		if ($email === '') {
			return array();
		}
		return (array) $wpdb->get_results($wpdb->prepare(
			"SELECT id, email, full_name, ip_address, country, last_seen FROM %i WHERE email = %s AND role = 'Guest'",
			$wpdb->lc_accounts,
			$email
		), ARRAY_A);
	}

	public static function export_personal_data($email, $page = 1) {
		global $wpdb;

		$accounts = self::guest_accounts($email);
		if (!$accounts) {
			return array('data' => array(), 'done' => true);
		}

		$page        = max(1, (int) $page);
		$per_page    = 200;
		$items       = array();
		$account_ids = array_map('intval', wp_list_pluck($accounts, 'id'));

		if ($page === 1) {
			foreach ($accounts as $acct) {
				$items[] = array(
					'group_id'    => 'live-chats-visitor',
					'group_label' => __('Live Chats Visitor', 'live-chats'),
					'item_id'     => 'live-chats-visitor-' . (int) $acct['id'],
					'data'        => array(
						array('name' => __('Name', 'live-chats'), 'value' => $acct['full_name']),
						array('name' => __('Email', 'live-chats'), 'value' => $acct['email']),
						array('name' => __('IP Address', 'live-chats'), 'value' => $acct['ip_address']),
						array('name' => __('Country', 'live-chats'), 'value' => $acct['country']),
						array('name' => __('Last Seen', 'live-chats'), 'value' => (string) $acct['last_seen']),
					),
				);
			}
		}

		$placeholders = implode(',', array_fill(0, count($account_ids), '%d'));
		$messages     = (array) $wpdb->get_results($wpdb->prepare(
			"SELECT id, conversation_id, msg, submit_date, attachment_url FROM %i WHERE account_id IN ($placeholders) ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge(array($wpdb->lc_messages), $account_ids, array($per_page, ($page - 1) * $per_page))
		), ARRAY_A);

		foreach ($messages as $m) {
			$data = array(
				array('name' => __('Conversation', 'live-chats'), 'value' => '#' . (int) $m['conversation_id']),
				array('name' => __('Sent', 'live-chats'), 'value' => (string) $m['submit_date']),
				array('name' => __('Message', 'live-chats'), 'value' => (string) $m['msg']),
			);
			if (!empty($m['attachment_url'])) {
				$data[] = array('name' => __('Attachment', 'live-chats'), 'value' => (string) $m['attachment_url']);
			}
			$items[] = array(
				'group_id'    => 'live-chats-messages',
				'group_label' => __('Live Chats Messages', 'live-chats'),
				'item_id'     => 'live-chats-message-' . (int) $m['id'],
				'data'        => $data,
			);
		}

		return array('data' => $items, 'done' => count($messages) < $per_page);
	}

	public static function erase_personal_data($email, $page = 1) {
		global $wpdb;

		$accounts = self::guest_accounts($email);
		if (!$accounts) {
			return array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true);
		}

		$account_ids  = array_map('intval', wp_list_pluck($accounts, 'id'));
		$placeholders = implode(',', array_fill(0, count($account_ids), '%d'));

		$conv_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
			"SELECT id FROM %i WHERE account_sender_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge(array($wpdb->lc_conversations), $account_ids)
		)));

		if ($conv_ids) {
			LiveChats_Data::delete_conversations($conv_ids);
		}

		foreach ($account_ids as $aid) {
			$wpdb->delete($wpdb->lc_accounts, array('id' => $aid), array('%d'));
		}

		return array('items_removed' => true, 'items_retained' => false, 'messages' => array(), 'done' => true);
	}
}
