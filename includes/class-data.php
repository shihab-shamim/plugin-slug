<?php
/**
 * Shared database helpers, presence, typing transients, formatting, and theme color math.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class LiveChats_Data {

	/**
	 * Short table keys => unprefixed table names.
	 */
	public static function table_names() {
		return (array) apply_filters('live_chats_tables', array(
			'accounts'         => 'lc_accounts',
			'conversations'    => 'lc_conversations',
			'messages'         => 'lc_messages',
			'participants'     => 'lc_participants',
			'canned_responses' => 'lc_canned_responses',
		));
	}

	/**
	 * Return full prefixed table name.
	 */
	public static function table($short) {
		global $wpdb;
		$map = self::table_names();
		return isset($map[$short]) ? $wpdb->prefix . $map[$short] : '';
	}

	/**
	 * Bind table names onto $wpdb for clean queries.
	 */
	public static function register_tables() {
		global $wpdb;
		foreach (self::table_names() as $table) {
			$wpdb->$table = $wpdb->prefix . $table;
		}
	}

	/**
	 * Bulk-load account display names by account IDs.
	 */
	public static function account_names(array $account_ids) {
		global $wpdb;

		$account_ids = array_values(array_unique(array_filter(array_map('intval', $account_ids))));
		if (!$account_ids) {
			return array();
		}

		$placeholders = implode(',', array_fill(0, count($account_ids), '%d'));

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, full_name, role FROM %i WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge(array($wpdb->lc_accounts), $account_ids)
			),
			OBJECT_K
		);
	}

	/**
	 * Update conversation last_activity timestamp.
	 */
	public static function touch_conversation($conversation_id) {
		global $wpdb;
		$wpdb->update(
			$wpdb->lc_conversations,
			array('last_activity' => current_time('mysql')),
			array('id' => (int) $conversation_id),
			array('%s'),
			array('%d')
		);
	}

	/**
	 * Format local WP MySQL datetime string N seconds ago.
	 */
	public static function time_ago($seconds) {
		return wp_date('Y-m-d H:i:s', time() - (int) $seconds);
	}

	/**
	 * Count operators seen within the last 3 minutes.
	 */
	public static function operators_online_count() {
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM %i
			 WHERE role IN ('Operator','Agent','Manager')
			   AND last_seen >= %s",
			$wpdb->lc_accounts,
			self::time_ago(3 * MINUTE_IN_SECONDS)
		));
	}

	/**
	 * Update presence heartbeat at most once every 30 seconds.
	 */
	public static function heartbeat(array $account, $is_operator) {
		global $wpdb;

		$last_seen = !empty($account['last_seen']) ? (int) mysql2date('U', $account['last_seen']) : 0;
		if ((time() - $last_seen) < 30) {
			return;
		}

		$data = array('last_seen' => current_time('mysql'));
		if ($is_operator) {
			$data['status'] = 'Online';
		}

		$wpdb->update(
			$wpdb->lc_accounts,
			$data,
			array('id' => (int) $account['id']),
			array_fill(0, count($data), '%s'),
			array('%d')
		);
	}

	/**
	 * Record active typing state & optional live sneak-peek text in short-TTL transients.
	 */
	public static function set_typing($conversation_id, $account_id, $peek = '') {
		$cid = (int) $conversation_id;
		$aid = (int) $account_id;
		if ($cid <= 0 || $aid <= 0) {
			return;
		}

		set_transient("lc_type_{$cid}_{$aid}", time(), 5);

		$peek_key = "lc_peek_{$cid}_{$aid}";
		if ($peek !== '') {
			set_transient($peek_key, mb_substr(sanitize_text_field($peek), 0, 200), 6);
		} else {
			delete_transient($peek_key);
		}
	}

	/**
	 * Clear typing state (e.g., immediately after sending a message).
	 */
	public static function clear_typing($conversation_id, $account_id) {
		$cid = (int) $conversation_id;
		$aid = (int) $account_id;
		delete_transient("lc_type_{$cid}_{$aid}");
		delete_transient("lc_peek_{$cid}_{$aid}");
	}

	/**
	 * Check whether another participant in the conversation is currently typing.
	 */
	public static function check_typing($conversation_id, $viewer_account_id) {
		global $wpdb;

		$cid = (int) $conversation_id;
		$vid = (int) $viewer_account_id;

		if ($cid <= 0) {
			return array('typing' => false, 'name' => '', 'peek' => '');
		}

		$participants = $wpdb->get_results($wpdb->prepare(
			'SELECT operator_id AS id FROM %i WHERE conversation_id = %d AND is_available IN (0, 3)
			 UNION
			 SELECT account_sender_id AS id FROM %i WHERE id = %d',
			$wpdb->lc_participants,
			$cid,
			$wpdb->lc_conversations,
			$cid
		));

		foreach ((array) $participants as $p) {
			$aid = (int) $p->id;
			if ($aid <= 0 || $aid === $vid) {
				continue;
			}
			if (get_transient("lc_type_{$cid}_{$aid}") !== false) {
				$name = (string) $wpdb->get_var($wpdb->prepare(
					'SELECT full_name FROM %i WHERE id = %d',
					$wpdb->lc_accounts,
					$aid
				));
				$peek = (string) get_transient("lc_peek_{$cid}_{$aid}");
				return array(
					'typing' => true,
					'name'   => $name,
					'peek'   => $peek,
				);
			}
		}

		return array('typing' => false, 'name' => '', 'peek' => '');
	}

	/**
	 * Predefined conversation tags with colors.
	 */
	public static function standard_tags() {
		$defaults = array(
			'VIP'      => '#8b5cf6',
			'Sales'    => '#10b981',
			'Support'  => '#3b82f6',
			'Billing'  => '#f59e0b',
			'Bug'      => '#ef4444',
			'Feature'  => '#06b6d4',
			'Resolved' => '#6b7280',
		);

		return (array) apply_filters('live_chats_tags_list', $defaults);
	}

	/**
	 * Atomically delete one or more conversations and all associated messages, participants, and uploads.
	 */
	public static function delete_conversations(array $conversation_ids) {
		global $wpdb;

		$ids = array_values(array_filter(array_map('intval', $conversation_ids)));
		if (!$ids) {
			return 0;
		}

		$placeholders = implode(',', array_fill(0, count($ids), '%d'));

		// Delete local attachment files belonging to these conversations.
		$files = $wpdb->get_col($wpdb->prepare(
			"SELECT attachment_url FROM %i WHERE conversation_id IN ($placeholders) AND attachment_url <> ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge(array($wpdb->lc_messages), $ids)
		));
		foreach ((array) $files as $url) {
			self::delete_upload_file($url);
		}

		$wpdb->query('START TRANSACTION');

		$wpdb->query($wpdb->prepare(
			"DELETE FROM %i WHERE conversation_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge(array($wpdb->lc_messages), $ids)
		));
		$wpdb->query($wpdb->prepare(
			"DELETE FROM %i WHERE conversation_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge(array($wpdb->lc_participants), $ids)
		));
		$deleted = $wpdb->query($wpdb->prepare(
			"DELETE FROM %i WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge(array($wpdb->lc_conversations), $ids)
		));

		$wpdb->query('COMMIT');

		return (int) $deleted;
	}

	/**
	 * Safely delete an uploaded file inside wp-content/uploads/live-chats/.
	 */
	public static function delete_upload_file($url) {
		$uploads = wp_get_upload_dir();
		if (empty($uploads['baseurl']) || strpos((string) $url, $uploads['baseurl'] . '/live-chats/') !== 0) {
			return;
		}

		$path = realpath($uploads['basedir'] . substr((string) $url, strlen($uploads['baseurl'])));
		$base = realpath($uploads['basedir'] . '/live-chats');

		if ($path && $base && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
			wp_delete_file($path);
		}
	}

	/**
	 * Agent display name shown to visitors.
	 */
	public static function visitor_agent_name($real_name, $account_id = 0) {
		$fallback = $real_name !== '' ? $real_name : __('Support Agent', 'live-chats');
		return (string) apply_filters('live_chats_visitor_agent_name', $fallback, (string) $real_name, (int) $account_id);
	}

	/**
	 * Format a message DB row into a JSON-safe payload.
	 */
	public static function format_message($message, array $names, $viewer_account_id, array $operator_ids = array()) {
		$account_id = (int) $message->account_id;
		$msg_type   = !empty($message->msg_type) ? sanitize_key($message->msg_type) : 'message';
		$is_staff   = in_array($account_id, $operator_ids, true);

		if (!$is_staff && isset($names[$account_id]) && in_array($names[$account_id]->role, array('Operator', 'Agent', 'Manager'), true)) {
			$is_staff = true;
		}

		$author = isset($names[$account_id]) ? (string) $names[$account_id]->full_name : '';

		return array(
			'id'              => (int) $message->id,
			'mine'            => $account_id === (int) $viewer_account_id,
			'staff'           => $is_staff,
			'author'          => $author,
			'msg'             => (string) $message->msg,
			'msg_type'        => $msg_type,
			'attachment_url'  => !empty($message->attachment_url) ? esc_url_raw($message->attachment_url) : '',
			'attachment_type' => !empty($message->attachment_type) ? sanitize_key($message->attachment_type) : '',
			'attachment_name' => !empty($message->attachment_name) ? sanitize_text_field($message->attachment_name) : '',
			'attachment_size' => !empty($message->attachment_size) ? (int) $message->attachment_size : 0,
			'date'            => mysql2date('Y/m/d', $message->submit_date),
			'time'            => mysql2date('H:i', $message->submit_date),
			'ts'              => (int) mysql2date('U', $message->submit_date),
		);
	}

	/**
	 * Highest message ID sent by $account_id that has been read by the other party.
	 */
	public static function conversation_read_through($conversation_id, $account_id) {
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			'SELECT MAX(id) FROM %i
			 WHERE conversation_id = %d AND account_id = %d AND is_read = 1',
			$wpdb->lc_messages,
			(int) $conversation_id,
			(int) $account_id
		));
	}

	/**
	 * Check if IP is public (not private/reserved/CGNAT).
	 */
	public static function is_public_ip($ip) {
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			return false;
		}
		$long = ip2long($ip);
		if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
			return false;
		}
		return true;
	}

	/**
	 * Resolve client IP safely (only trusts proxy headers when explicitly configured).
	 */
	public static function client_ip() {
		if (get_option('live_chats_trust_proxy', 0)) {
			foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP') as $header) {
				if (!empty($_SERVER[$header])) {
					$candidate = trim(sanitize_text_field(wp_unslash($_SERVER[$header])));
					if (filter_var($candidate, FILTER_VALIDATE_IP)) {
						return $candidate;
					}
				}
			}

			if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
				foreach (explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))) as $hop) {
					$hop = trim($hop);
					if (self::is_public_ip($hop)) {
						return $hop;
					}
				}
			}
		}

		$remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
	}

	/**
	 * Derive ISO-2 country code from browser IANA timezone without any external HTTP call.
	 */
	public static function country_from_timezone($timezone) {
		$timezone = (string) $timezone;
		if ($timezone === '' || !preg_match('#^[A-Za-z]+/[A-Za-z0-9_+/-]+$#', $timezone)) {
			return '';
		}

		$legacy = array(
			'Asia/Calcutta'        => 'IN',
			'Asia/Dacca'           => 'BD',
			'Asia/Katmandu'        => 'NP',
			'Asia/Rangoon'         => 'MM',
			'Asia/Saigon'          => 'VN',
			'America/Buenos_Aires' => 'AR',
			'America/Montreal'     => 'CA',
			'Europe/Kiev'          => 'UA',
		);
		if (isset($legacy[$timezone])) {
			return $legacy[$timezone];
		}

		try {
			$loc = (new DateTimeZone($timezone))->getLocation();
		} catch (Exception $e) {
			return '';
		}

		return (is_array($loc) && !empty($loc['country_code']) && $loc['country_code'] !== '??')
			? strtoupper($loc['country_code'])
			: '';
	}

	/**
	 * Convert hex color to RGB array.
	 */
	public static function hex_to_rgb($hex) {
		$hex = ltrim((string) $hex, '#');
		if (strlen($hex) === 3) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
			return array(37, 99, 235);
		}
		return array(
			(int) hexdec(substr($hex, 0, 2)),
			(int) hexdec(substr($hex, 2, 2)),
			(int) hexdec(substr($hex, 4, 2)),
		);
	}

	/**
	 * Lighten or darken hex color.
	 */
	public static function adjust_brightness($hex, $steps) {
		$rgb = self::hex_to_rgb($hex);
		$out = array();
		foreach ($rgb as $c) {
			$out[] = str_pad(dechex(max(0, min(255, (int) ($c + $steps)))), 2, '0', STR_PAD_LEFT);
		}
		return '#' . implode('', $out);
	}

	/**
	 * Choose readable contrast text color (#ffffff or #0f172a).
	 */
	public static function contrast_color($hex) {
		$rgb = self::hex_to_rgb($hex);
		$r   = $rgb[0] / 255;
		$g   = $rgb[1] / 255;
		$b   = $rgb[2] / 255;
		$lum = 0.2126 * ($r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4))
			+ 0.7152 * ($g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4))
			+ 0.0722 * ($b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4));

		return ($lum > 0.45) ? '#0f172a' : '#ffffff';
	}

	/**
	 * Generate CSS custom properties for widget theming.
	 */
	public static function generate_theme_vars($primary_hex) {
		$primary_hex = sanitize_hex_color($primary_hex) ?: '#2563eb';
		$rgb_str     = implode(', ', self::hex_to_rgb($primary_hex));
		$light_hex   = self::adjust_brightness($primary_hex, 28);
		$dark_hex    = self::adjust_brightness($primary_hex, -25);
		$text_color  = self::contrast_color($primary_hex);
		$icon_bg     = ($text_color === '#0f172a') ? 'rgba(0, 0, 0, 0.08)' : 'rgba(255, 255, 255, 0.22)';

		return "--lc-primary: {$primary_hex}; "
			. "--lc-primary-light: {$light_hex}; "
			. "--lc-primary-dark: {$dark_hex}; "
			. "--lc-primary-rgb: {$rgb_str}; "
			. "--lc-primary-gradient: linear-gradient(135deg, {$primary_hex} 0%, {$light_hex} 100%); "
			. "--lc-primary-glow: rgba({$rgb_str}, 0.35); "
			. "--lc-header-text: {$text_color}; "
			. "--lc-header-icon-bg: {$icon_bg};";
	}

	/**
	 * Safely encode/decode emoji in WP options for utf8/utf8mb4 DBs.
	 */
	public static function encode_emoji($val) {
		return wp_encode_emoji((string) $val);
	}

	public static function decode_emoji($val) {
		return html_entity_decode((string) $val, ENT_QUOTES, 'UTF-8');
	}
}
