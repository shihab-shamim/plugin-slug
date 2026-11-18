<?php
/**
 * Authentication, session cookies, capabilities, conversation access control, and rate limiting.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class LiveChats_Auth {

	/**
	 * Issue a cryptographically secure HttpOnly session cookie for a visitor account.
	 */
	public static function issue_token($account_id) {
		global $wpdb;

		$account_id = (int) $account_id;
		$token      = bin2hex(random_bytes(32));
		$expires    = time() + (30 * DAY_IN_SECONDS);

		$wpdb->update(
			$wpdb->lc_accounts,
			array(
				'session_token_hash' => hash('sha256', $token),
				'token_expires'      => gmdate('Y-m-d H:i:s', $expires),
				'last_seen'          => current_time('mysql'),
			),
			array('id' => $account_id),
			array('%s', '%s', '%s'),
			array('%d')
		);

		$cookie_val = $account_id . ':' . $token;
		if (!headers_sent()) {
			if (PHP_VERSION_ID >= 70300) {
				setcookie(LIVE_CHATS_COOKIE, $cookie_val, array(
					'expires'  => $expires,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				));
			} else {
				setcookie(
					LIVE_CHATS_COOKIE,
					$cookie_val,
					$expires,
					(COOKIEPATH ? COOKIEPATH : '/') . '; samesite=Lax',
					COOKIE_DOMAIN,
					is_ssl(),
					true
				);
			}
		}

		$_COOKIE[LIVE_CHATS_COOKIE] = $cookie_val;
		self::current_visitor_account(true);

		return $token;
	}

	/**
	 * Revoke the visitor's session token and expire the cookie.
	 */
	public static function clear_token() {
		global $wpdb;

		$account = self::current_visitor_account();
		if ($account && $account['role'] === 'Guest') {
			$wpdb->update(
				$wpdb->lc_accounts,
				array('session_token_hash' => '', 'token_expires' => null, 'status' => 'Idle'),
				array('id' => (int) $account['id']),
				array('%s', '%s', '%s'),
				array('%d')
			);
		}

		if (!headers_sent()) {
			if (PHP_VERSION_ID >= 70300) {
				setcookie(LIVE_CHATS_COOKIE, '', array(
					'expires'  => time() - HOUR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				));
			} else {
				setcookie(
					LIVE_CHATS_COOKIE,
					'',
					time() - HOUR_IN_SECONDS,
					COOKIEPATH ? COOKIEPATH : '/',
					COOKIE_DOMAIN,
					is_ssl(),
					true
				);
			}
		}

		unset($_COOKIE[LIVE_CHATS_COOKIE]);
		self::current_visitor_account(true);
	}

	/**
	 * Resolve the visitor chat account from cookie (or logged-in non-operator WP user if requested).
	 *
	 * @param bool $reset Clear in-request static cache.
	 * @return array|null
	 */
	public static function current_visitor_account($reset = false) {
		static $cached = false;

		if ($reset) {
			$cached = false;
			return null;
		}

		if ($cached !== false) {
			return $cached;
		}

		$cached = null;

		if (!empty($_COOKIE[LIVE_CHATS_COOKIE])) {
			$raw = sanitize_text_field(wp_unslash($_COOKIE[LIVE_CHATS_COOKIE]));
			if (substr_count($raw, ':') === 1) {
				list($account_id, $token) = explode(':', $raw, 2);
				$account_id = (int) $account_id;

				if ($account_id > 0 && ctype_xdigit($token) && strlen($token) === 64) {
					global $wpdb;
					$account = $wpdb->get_row(
						$wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->lc_accounts, $account_id),
						ARRAY_A
					);

					if (
						$account
						&& !empty($account['session_token_hash'])
						&& hash_equals($account['session_token_hash'], hash('sha256', $token))
						&& (empty($account['token_expires']) || strtotime($account['token_expires']) >= time())
					) {
						$cached = $account;
						return $cached;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Resolve or auto-create a visitor chat account for a logged-in WordPress user.
	 */
	public static function logged_in_visitor_account() {
		if (!is_user_logged_in()) {
			return null;
		}

		global $wpdb;
		$user = wp_get_current_user();
		if (!$user || empty($user->ID)) {
			return null;
		}

		// Look for an existing Guest/Customer chat account linked to this WP user.
		$account = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM %i WHERE wp_user_id = %d AND role = 'Guest' LIMIT 1",
			$wpdb->lc_accounts,
			(int) $user->ID
		), ARRAY_A);

		if ($account) {
			return $account;
		}

		$wpdb->insert(
			$wpdb->lc_accounts,
			array(
				'wp_user_id' => (int) $user->ID,
				'email'      => (string) $user->user_email,
				'full_name'  => (string) ($user->display_name ?: $user->user_login),
				'role'       => 'Guest',
				'ip_address' => LiveChats_Data::client_ip(),
				'country'    => '',
				'last_seen'  => current_time('mysql'),
				'status'     => 'Idle',
			),
			array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
		);

		if (!$wpdb->insert_id) {
			return null;
		}

		$account_id = (int) $wpdb->insert_id;
		self::issue_token($account_id);

		return $wpdb->get_row($wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			$wpdb->lc_accounts,
			$account_id
		), ARRAY_A);
	}

	/**
	 * Resolve or create the operator chat account for the currently logged-in WordPress staff user.
	 */
	public static function operator_account($reset = false) {
		static $cached = false;

		if ($reset) {
			$cached = false;
			return null;
		}

		if ($cached !== false) {
			return $cached;
		}

		$cached = null;

		if (!is_user_logged_in() || !self::user_can('live_chats_view')) {
			return null;
		}

		global $wpdb;
		$user = wp_get_current_user();

		$account = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM %i WHERE wp_user_id = %d AND role <> 'Guest' LIMIT 1",
			$wpdb->lc_accounts,
			(int) $user->ID
		), ARRAY_A);

		$expected_role = self::role_for_user($user);

		if ($account) {
			if ($account['role'] !== $expected_role || $account['full_name'] !== $user->display_name) {
				$wpdb->update(
					$wpdb->lc_accounts,
					array(
						'role'      => $expected_role,
						'full_name' => (string) $user->display_name,
						'email'     => (string) $user->user_email,
					),
					array('id' => (int) $account['id']),
					array('%s', '%s', '%s'),
					array('%d')
				);
				$account['role']      = $expected_role;
				$account['full_name'] = (string) $user->display_name;
				$account['email']     = (string) $user->user_email;
			}
		} else {
			$wpdb->insert(
				$wpdb->lc_accounts,
				array(
					'wp_user_id' => (int) $user->ID,
					'email'      => (string) $user->user_email,
					'full_name'  => (string) $user->display_name,
					'role'       => $expected_role,
					'last_seen'  => current_time('mysql'),
					'status'     => 'Online',
				),
				array('%d', '%s', '%s', '%s', '%s', '%s')
			);

			if (!$wpdb->insert_id) {
				return null;
			}

			$account = $wpdb->get_row($wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$wpdb->lc_accounts,
				(int) $wpdb->insert_id
			), ARRAY_A);
		}

		$cached = $account;
		return $cached;
	}

	/**
	 * Derive Live Chats account role for a WP_User.
	 */
	public static function role_for_user($user) {
		if (!$user || empty($user->ID)) {
			return 'Inactive';
		}
		if (user_can($user, LIVE_CHATS_CAP_MANAGE) || user_can($user, 'live_chats_assign') || user_can($user, 'manage_options')) {
			return 'Manager';
		}
		if (user_can($user, LIVE_CHATS_CAP_OPERATE) || user_can($user, 'live_chats_view')) {
			return 'Operator';
		}
		return 'Inactive';
	}

	/**
	 * Sync one WP user's operator account role.
	 */
	public static function sync_account_role($user_id, $deleted = false) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ($user_id <= 0) {
			return;
		}

		$account = $wpdb->get_row($wpdb->prepare(
			"SELECT id, role FROM %i WHERE wp_user_id = %d AND role <> 'Guest' LIMIT 1",
			$wpdb->lc_accounts,
			$user_id
		), ARRAY_A);

		if (!$account) {
			return;
		}

		$role = $deleted ? 'Inactive' : self::role_for_user(get_userdata($user_id));
		if ($account['role'] !== $role) {
			$wpdb->update(
				$wpdb->lc_accounts,
				array('role' => $role),
				array('id' => (int) $account['id']),
				array('%s'),
				array('%d')
			);
		}
	}

	/**
	 * Sync all operator accounts with WordPress roles.
	 */
	public static function sync_all_account_roles() {
		global $wpdb;

		$user_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT wp_user_id FROM %i WHERE wp_user_id > 0 AND role <> 'Guest'",
			$wpdb->lc_accounts
		));

		foreach ((array) $user_ids as $uid) {
			self::sync_account_role((int) $uid);
		}
	}

	/**
	 * Capability check honoring umbrella capabilities.
	 */
	public static function user_can($cap) {
		if (current_user_can($cap) || current_user_can(LIVE_CHATS_CAP_MANAGE) || current_user_can('manage_options')) {
			return true;
		}
		return ($cap === 'live_chats_view' || $cap === 'live_chats_reply') && current_user_can(LIVE_CHATS_CAP_OPERATE);
	}

	/**
	 * Dynamic capability filter for WordPress user_has_cap.
	 */
	public static function filter_user_has_cap($allcaps, $caps, $args, $user) {
		foreach ((array) $caps as $cap) {
			if (!empty($allcaps[$cap]) || strpos((string) $cap, 'live_chats_') !== 0) {
				continue;
			}
			if (!empty($allcaps[LIVE_CHATS_CAP_MANAGE]) || !empty($allcaps['manage_options'])) {
				$allcaps[$cap] = true;
			} elseif (!empty($allcaps[LIVE_CHATS_CAP_OPERATE]) && ($cap === 'live_chats_view' || $cap === 'live_chats_reply')) {
				$allcaps[$cap] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Load operator assignment context for a conversation.
	 */
	public static function operator_context($account_id, $conversation_id) {
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT c.id, c.state,
					(SELECT p.is_available FROM %i p WHERE p.conversation_id = c.id AND p.operator_id = %d LIMIT 1) AS my_state,
					(SELECT h.operator_id FROM %i h WHERE h.conversation_id = c.id AND h.is_available = 0 LIMIT 1) AS holder_id
			 FROM %i c
			 WHERE c.id = %d',
			$wpdb->lc_participants,
			(int) $account_id,
			$wpdb->lc_participants,
			$wpdb->lc_conversations,
			(int) $conversation_id
		), ARRAY_A);

		if (!$row) {
			return null;
		}

		return array(
			'state'     => (string) $row['state'],
			'my_state'  => ($row['my_state'] === null || $row['my_state'] === '') ? null : (int) $row['my_state'],
			'holder_id' => (int) $row['holder_id'],
		);
	}

	/**
	 * Fine-grained operator policy check for a specific conversation action.
	 */
	public static function operator_can($action, $conversation_id, $account = null) {
		if ($account === null) {
			$account = self::operator_account();
		}

		$account_id      = is_array($account) && isset($account['id']) ? (int) $account['id'] : 0;
		$conversation_id = (int) $conversation_id;

		if ($account_id <= 0 || $conversation_id <= 0 || !self::user_can('live_chats_view')) {
			return false;
		}

		if ($action === 'delete') {
			return self::user_can('live_chats_manage_settings');
		}

		$ctx = self::operator_context($account_id, $conversation_id);
		if (!$ctx) {
			return false;
		}

		$can_assign = self::user_can('live_chats_assign');
		$can_reply  = self::user_can('live_chats_reply');
		$is_open    = ($ctx['state'] === 'open');
		$is_owner   = ($ctx['holder_id'] === $account_id);
		$is_joined  = ($ctx['my_state'] === 3);
		$involved   = $is_owner || $is_joined;
		$unclaimed  = ($ctx['holder_id'] === 0);

		switch ($action) {
			case 'view':
				return $can_assign || $involved || $unclaimed;
			case 'claim':
				return $can_reply && $is_open && $unclaimed;
			case 'reply':
				return $can_reply && $is_open && ($involved || $unclaimed || $can_assign);
			case 'note':
				return $can_reply && $is_open && ($involved || $unclaimed || $can_assign);
			case 'manage':
				return $can_reply && ($involved || $unclaimed || $can_assign);
			case 'close':
				return $is_open && (($can_reply && $is_owner) || $can_assign);
		}

		return false;
	}

	/**
	 * SQL WHERE fragment restricting conversation list visibility for an operator.
	 */
	public static function operator_visibility_sql($operator_id) {
		global $wpdb;

		if (self::user_can('live_chats_assign')) {
			return array('sql' => '', 'args' => array());
		}

		return array(
			'sql'  => ' AND (EXISTS (SELECT 1 FROM %i v_me WHERE v_me.conversation_id = c.id AND v_me.operator_id = %d AND v_me.is_available IN (0, 3))'
				. ' OR NOT EXISTS (SELECT 1 FROM %i v_h WHERE v_h.conversation_id = c.id AND v_h.is_available = 0))',
			'args' => array($wpdb->lc_participants, (int) $operator_id, $wpdb->lc_participants),
		);
	}

	/**
	 * Server-side conversation access check for both visitors and operators.
	 */
	public static function can_access_conversation($account_id, $conversation_id, $is_operator = false) {
		$account_id      = (int) $account_id;
		$conversation_id = (int) $conversation_id;

		if ($account_id <= 0 || $conversation_id <= 0) {
			return false;
		}

		if ($is_operator) {
			return self::operator_can('view', $conversation_id, array('id' => $account_id));
		}

		global $wpdb;
		return (bool) $wpdb->get_var($wpdb->prepare(
			'SELECT id FROM %i WHERE id = %d AND (account_sender_id = %d OR account_receiver_id = %d)',
			$wpdb->lc_conversations,
			$conversation_id,
			$account_id,
			$account_id
		));
	}

	/**
	 * AJAX preamble for visitor requests (verifies nonce & resolves session cookie or logged-in WP user).
	 */
	public static function require_guest_ajax() {
		check_ajax_referer('live_chats_guest_nonce', 'live_chats_nonce');

		$account = self::current_visitor_account();
		if (!$account && is_user_logged_in()) {
			$account = self::logged_in_visitor_account();
		}

		if (!$account) {
			wp_send_json_error(__('Your chat session has expired. Please start a new conversation.', 'live-chats'), 401);
		}

		return $account;
	}

	/**
	 * AJAX preamble for operator requests (verifies nonce, capability, and operator account).
	 */
	public static function require_operator_ajax($capability = 'live_chats_view') {
		check_ajax_referer('live_chats_admin_nonce', 'live_chats_nonce');

		if (!self::user_can($capability)) {
			wp_send_json_error(__('You are not allowed to do that.', 'live-chats'), 403);
		}

		$account = self::operator_account();
		if (!$account) {
			wp_send_json_error(__('No chat operator profile is available for your account.', 'live-chats'), 403);
		}

		return $account;
	}

	/**
	 * Atomic fixed-window rate limiter using MySQL GET_LOCK + WP transients.
	 */
	public static function rate_limit($key, $max, $window) {
		global $wpdb;

		$hash      = md5((string) $key);
		$transient = 'lc_rl_' . $hash;
		$lock_name = 'lc_rl_' . $hash;

		$acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $lock_name));
		if ((int) $acquired !== 1) {
			return false;
		}

		$now    = time();
		$stored = get_transient($transient);
		$count  = (is_array($stored) && isset($stored[0], $stored[1]) && (int) $stored[1] > $now) ? (int) $stored[0] : 0;
		$ends   = $count > 0 ? (int) $stored[1] : $now + (int) $window;

		$allowed = $count < (int) $max;
		if ($allowed) {
			set_transient($transient, array($count + 1, $ends), max(1, $ends - $now));
		}

		$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));

		return $allowed;
	}

	/**
	 * Send engine WP_Error or array response over admin-ajax.
	 */
	public static function ajax_respond($result) {
		if (is_wp_error($result)) {
			$data   = $result->get_error_data();
			$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
			wp_send_json_error($result->get_error_message(), $status);
		}
		wp_send_json_success($result);
	}
}
