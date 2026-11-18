<?php
/**
 * Transport-agnostic Live Chats core business engine.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class LiveChats_Engine {

	/**
	 * Sanitize browsing context array from visitor browser.
	 */
	public static function sanitize_context(array $context) {
		$clean = array();
		foreach (array('url', 'referrer') as $key) {
			$clean[$key] = isset($context[$key]) && is_string($context[$key]) ? esc_url_raw($context[$key]) : '';
		}
		foreach (array('title', 'browser', 'os', 'device', 'locale', 'timezone') as $key) {
			$clean[$key] = isset($context[$key]) && is_string($context[$key]) ? sanitize_text_field($context[$key]) : '';
		}
		return $clean;
	}

	/**
	 * Identify a guest or logged-in visitor and issue/refresh an HttpOnly session token.
	 */
	public static function identify_visitor(array $args) {
		global $wpdb;

		$name  = isset($args['name']) ? sanitize_text_field((string) $args['name']) : '';
		$email = isset($args['email']) ? sanitize_email((string) $args['email']) : '';
		$ip    = isset($args['ip']) ? (string) $args['ip'] : LiveChats_Data::client_ip();
		$actor = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();

		$wp_user_id = is_user_logged_in() ? get_current_user_id() : 0;

		if ($wp_user_id > 0 && ($name === '' || $email === '')) {
			$wp_user = wp_get_current_user();
			if ($name === '') {
				$name = (string) ($wp_user->display_name ?: $wp_user->user_login);
			}
			if ($email === '') {
				$email = (string) $wp_user->user_email;
			}
		}

		$returning = !empty($actor['id']) && isset($actor['role']) && $actor['role'] === 'Guest';

		$limit_key = $returning ? 'auth_acct_' . (int) $actor['id'] : 'auth_ip_' . $ip;
		if (!LiveChats_Auth::rate_limit($limit_key, 15, 10 * MINUTE_IN_SECONDS)) {
			return new WP_Error(
				'live_chats_rate_limited',
				__('Too many attempts. Please wait a few minutes and try again.', 'live-chats'),
				array('status' => 429)
			);
		}

		if ($name === '' || $email === '' || !is_email($email)) {
			return new WP_Error(
				'live_chats_invalid_input',
				__('Please enter a valid name and email address.', 'live-chats'),
				array('status' => 422)
			);
		}

		$name = mb_substr($name, 0, 100);

		if ($returning) {
			$account_id = (int) $actor['id'];
			$wpdb->update(
				$wpdb->lc_accounts,
				array(
					'full_name'  => $name,
					'email'      => $email,
					'wp_user_id' => $wp_user_id ?: (int) ($actor['wp_user_id'] ?? 0),
					'ip_address' => $ip,
					'last_seen'  => current_time('mysql'),
				),
				array('id' => $account_id),
				array('%s', '%s', '%d', '%s', '%s'),
				array('%d')
			);
			LiveChats_Auth::issue_token($account_id);
			return array('id' => $account_id, 'created' => false);
		}

		// If logged-in WP user already has a Guest account row, reuse it safely.
		if ($wp_user_id > 0) {
			$existing_id = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM %i WHERE wp_user_id = %d AND role = 'Guest' LIMIT 1",
				$wpdb->lc_accounts,
				$wp_user_id
			));
			if ($existing_id > 0) {
				$wpdb->update(
					$wpdb->lc_accounts,
					array(
						'full_name'  => $name,
						'email'      => $email,
						'ip_address' => $ip,
						'last_seen'  => current_time('mysql'),
					),
					array('id' => $existing_id),
					array('%s', '%s', '%s', '%s'),
					array('%d')
				);
				LiveChats_Auth::issue_token($existing_id);
				return array('id' => $existing_id, 'created' => false);
			}
		}

		$inserted = $wpdb->insert(
			$wpdb->lc_accounts,
			array(
				'wp_user_id' => $wp_user_id,
				'email'      => $email,
				'full_name'  => $name,
				'role'       => 'Guest',
				'last_seen'  => current_time('mysql'),
				'ip_address' => $ip,
				'country'    => '',
				'status'     => 'Online',
			),
			array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
		);

		if (!$inserted) {
			return new WP_Error(
				'live_chats_insert_failed',
				__('Could not start a chat session. Please try again.', 'live-chats'),
				array('status' => 500)
			);
		}

		$account_id = (int) $wpdb->insert_id;
		LiveChats_Auth::issue_token($account_id);

		return array('id' => $account_id, 'created' => true);
	}

	/**
	 * Select the least-busy operator (preferring online operators seen in last 3 minutes).
	 */
	public static function pick_operator() {
		global $wpdb;

		foreach (array(LiveChats_Data::time_ago(3 * MINUTE_IN_SECONDS), '1000-01-01 00:00:00') as $seen_since) {
			$id = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT a.id
				 FROM %i a
				 LEFT JOIN %i p ON p.operator_id = a.id AND p.is_available = 0
				 WHERE a.role IN ('Operator','Agent','Manager')
				   AND COALESCE(a.last_seen, '1000-01-01 00:00:00') >= %s
				 GROUP BY a.id
				 ORDER BY COUNT(p.id) ASC, a.last_seen DESC
				 LIMIT 1",
				$wpdb->lc_accounts,
				$wpdb->lc_participants,
				$seen_since
			));
			if ($id > 0) {
				return $id;
			}
		}

		// Auto-provision default admin operator if none exists yet.
		LiveChats_Installer::ensure_default_operator();

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM %i WHERE role IN ('Operator','Agent','Manager') ORDER BY id ASC LIMIT 1",
			$wpdb->lc_accounts
		));
	}

	/**
	 * Find visitor's active open conversation or create a new one atomically.
	 */
	public static function find_or_create_conversation(array $args) {
		global $wpdb;

		$actor    = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
		$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;

		if ($actor_id <= 0) {
			return new WP_Error('live_chats_invalid_request', __('Invalid request.', 'live-chats'), array('status' => 400));
		}

		if (!LiveChats_Auth::rate_limit('find_' . $actor_id, 30, MINUTE_IN_SECONDS)) {
			return new WP_Error('live_chats_rate_limited', __('Please slow down.', 'live-chats'), array('status' => 429));
		}

		$wpdb->update(
			$wpdb->lc_accounts,
			array('status' => 'Online', 'last_seen' => current_time('mysql')),
			array('id' => $actor_id),
			array('%s', '%s'),
			array('%d')
		);

		$force_new = !empty($args['force_new']);

		if ($force_new) {
			$wpdb->query($wpdb->prepare(
				"UPDATE %i
				 SET state = 'closed', open_sender_id = NULL, closed_at = %s
				 WHERE account_sender_id = %d AND state = 'open'",
				$wpdb->lc_conversations,
				current_time('mysql'),
				$actor_id
			));
		} else {
			$existing = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM %i
				 WHERE (account_sender_id = %d OR account_receiver_id = %d) AND state = 'open'
				 ORDER BY id DESC LIMIT 1",
				$wpdb->lc_conversations,
				$actor_id,
				$actor_id
			));

			if ($existing > 0) {
				return array('id' => $existing, 'created' => false);
			}
		}

		if (!empty($args['find_only'])) {
			return new WP_Error('live_chats_no_conversation', __('No active conversation.', 'live-chats'), array('status' => 404));
		}

		$operator_id = self::pick_operator();
		if ($operator_id <= 0) {
			return new WP_Error(
				'live_chats_no_operators',
				__('No support operators are configured yet. Please try again later.', 'live-chats'),
				array('status' => 503)
			);
		}

		$context = self::sanitize_context(isset($args['context']) && is_array($args['context']) ? $args['context'] : array());
		$custom_fields = array(
			'context' => $context,
			'tags'    => array(),
		);

		$country = isset($actor['country']) ? (string) $actor['country'] : '';
		if ($country === '' && !empty($context['timezone'])) {
			$country = LiveChats_Data::country_from_timezone($context['timezone']);
			if ($country !== '') {
				$wpdb->query($wpdb->prepare(
					"UPDATE %i SET country = %s WHERE id = %d AND (country IS NULL OR country = '')",
					$wpdb->lc_accounts,
					$country,
					$actor_id
				));
			}
		}

		$now      = current_time('mysql');
		$inserted = $wpdb->insert(
			$wpdb->lc_conversations,
			array(
				'account_sender_id'   => $actor_id,
				'account_receiver_id' => $operator_id,
				'country'             => $country,
				'state'               => 'open',
				'open_sender_id'      => $actor_id,
				'custom_fields'       => wp_json_encode($custom_fields),
				'submit_date'         => $now,
				'last_activity'       => $now,
			),
			array('%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s')
		);

		if (!$inserted) {
			if (strpos((string) $wpdb->last_error, 'open_conversation_per_sender') !== false) {
				$raced = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT id FROM %i WHERE account_sender_id = %d AND state = 'open' ORDER BY id DESC LIMIT 1",
					$wpdb->lc_conversations,
					$actor_id
				));
				if ($raced > 0) {
					return array('id' => $raced, 'created' => false);
				}
			}

			return new WP_Error(
				'live_chats_create_failed',
				__('Could not start a conversation. Please try again.', 'live-chats'),
				array('status' => 500)
			);
		}

		$conversation_id = (int) $wpdb->insert_id;

		// Queue conversation for all active operators.
		$operator_ids = (array) $wpdb->get_col($wpdb->prepare(
			"SELECT id FROM %i WHERE role IN ('Operator','Agent','Manager')",
			$wpdb->lc_accounts
		));

		foreach ($operator_ids as $op_id) {
			$wpdb->query($wpdb->prepare(
				'INSERT IGNORE INTO %i (conversation_id, operator_id, is_available) VALUES (%d, %d, 1)',
				$wpdb->lc_participants,
				$conversation_id,
				(int) $op_id
			));
		}

		// Record system event for staff audit trail.
		self::create_system_message(
			$conversation_id,
			sprintf(
				/* translators: 1: visitor name, 2: visitor email */
				__('Conversation started by %1$s (%2$s)', 'live-chats'),
				isset($actor['full_name']) ? $actor['full_name'] : __('Visitor', 'live-chats'),
				isset($actor['email']) ? $actor['email'] : ''
			)
		);

		do_action('live_chats_conversation_created', $conversation_id, $actor_id, $operator_id);

		return array('id' => $conversation_id, 'created' => true);
	}

	/**
	 * Assign conversation to one operator.
	 */
	public static function claim_conversation($conversation_id, $operator_id) {
		global $wpdb;

		$cid = (int) $conversation_id;
		$oid = (int) $operator_id;

		$wpdb->query($wpdb->prepare(
			'INSERT IGNORE INTO %i (conversation_id, operator_id, is_available) VALUES (%d, %d, 1)',
			$wpdb->lc_participants,
			$cid,
			$oid
		));
		$wpdb->query($wpdb->prepare(
			'UPDATE %i SET is_available = 2 WHERE conversation_id = %d AND is_available <> 3',
			$wpdb->lc_participants,
			$cid
		));
		$wpdb->query($wpdb->prepare(
			'UPDATE %i SET is_available = 0 WHERE conversation_id = %d AND operator_id = %d',
			$wpdb->lc_participants,
			$cid,
			$oid
		));
	}

	/**
	 * Atomically claim an unclaimed conversation with MySQL GET_LOCK.
	 */
	public static function claim_if_unclaimed($conversation_id, $operator_id) {
		global $wpdb;

		$cid  = (int) $conversation_id;
		$oid  = (int) $operator_id;
		$lock = 'lc_claim_' . $cid;

		if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== 1) {
			return false;
		}

		$holder = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT operator_id FROM %i WHERE conversation_id = %d AND is_available = 0 LIMIT 1',
			$wpdb->lc_participants,
			$cid
		));

		if ($holder === 0) {
			self::claim_conversation($cid, $oid);
		}

		$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));

		return $holder === 0 || $holder === $oid;
	}

	/**
	 * Fetch participants map keyed by operator_id.
	 */
	public static function conversation_assignments($conversation_id) {
		global $wpdb;
		return (array) $wpdb->get_results($wpdb->prepare(
			'SELECT operator_id, is_available FROM %i WHERE conversation_id = %d',
			$wpdb->lc_participants,
			(int) $conversation_id
		), OBJECT_K);
	}

	/**
	 * Check whether any operator holds the conversation.
	 */
	public static function is_conversation_claimed(array $assignments) {
		foreach ($assignments as $row) {
			if ((int) $row->is_available === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetch operators actively working a conversation (owner first, then co-agents).
	 */
	public static function conversation_team($conversation_id) {
		global $wpdb;

		return (array) $wpdb->get_results($wpdb->prepare(
			'SELECT a.id, a.full_name, p.is_available AS state
			 FROM %i p
			 JOIN %i a ON a.id = p.operator_id
			 WHERE p.conversation_id = %d AND p.is_available IN (0, 3)
			 ORDER BY p.is_available ASC, a.full_name ASC',
			$wpdb->lc_participants,
			$wpdb->lc_accounts,
			(int) $conversation_id
		), ARRAY_A);
	}

	/**
	 * Persist a visitor or operator message (or staff private note).
	 */
	public static function create_message(array $args) {
		global $wpdb;

		$conversation_id = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
		$actor           = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
		$actor_id        = isset($actor['id']) ? (int) $actor['id'] : 0;
		$is_operator     = !empty($args['is_operator']);
		$msg_type        = isset($args['msg_type']) ? sanitize_key((string) $args['msg_type']) : 'message';

		if (!in_array($msg_type, array('message', 'note'), true)) {
			$msg_type = 'message';
		}

		if ($msg_type === 'note' && !$is_operator) {
			return new WP_Error(
				'live_chats_forbidden',
				__('Only operators can create private notes.', 'live-chats'),
				array('status' => 403)
			);
		}

		$message         = isset($args['msg']) ? trim(sanitize_textarea_field((string) $args['msg'])) : '';
		$attachment_url  = isset($args['attachment_url']) ? esc_url_raw((string) $args['attachment_url']) : '';
		$attachment_type = isset($args['attachment_type']) ? sanitize_key((string) $args['attachment_type']) : '';
		$attachment_name = isset($args['attachment_name']) ? sanitize_text_field((string) $args['attachment_name']) : '';
		$attachment_size = isset($args['attachment_size']) ? absint($args['attachment_size']) : 0;

		// Validate attachment URL strictly originates from this site's uploads/live-chats/ folder.
		$uploads    = wp_upload_dir(null, false);
		$upload_url = trailingslashit($uploads['baseurl']) . 'live-chats/';
		if ($attachment_url !== '' && (strpos($attachment_url, $upload_url) !== 0 || strpos($attachment_url, '..') !== false)) {
			return new WP_Error(
				'live_chats_invalid_attachment',
				__('Invalid attachment URL. Please upload the file again.', 'live-chats'),
				array('status' => 422)
			);
		}

		if ($attachment_url === '') {
			$attachment_type = '';
			$attachment_name = '';
			$attachment_size = 0;
		} else {
			$filetype        = wp_check_filetype(wp_basename((string) wp_parse_url($attachment_url, PHP_URL_PATH)));
			$attachment_type = strpos((string) $filetype['type'], 'image/') === 0 ? 'image' : 'file';
		}

		if ($conversation_id <= 0 || $actor_id <= 0 || ($message === '' && $attachment_url === '')) {
			return new WP_Error(
				'live_chats_empty_message',
				__('Message text or attachment is required.', 'live-chats'),
				array('status' => 422)
			);
		}

		if (mb_strlen($message) > LIVE_CHATS_MAX_MESSAGE) {
			return new WP_Error(
				'live_chats_message_too_long',
				__('That message exceeds the maximum length.', 'live-chats'),
				array('status' => 422)
			);
		}

		if (!$is_operator && !LiveChats_Auth::rate_limit('msg_' . $actor_id, 30, MINUTE_IN_SECONDS)) {
			return new WP_Error(
				'live_chats_rate_limited',
				__('You are sending messages too quickly. Please wait a moment.', 'live-chats'),
				array('status' => 429)
			);
		}

		if (!LiveChats_Auth::can_access_conversation($actor_id, $conversation_id, $is_operator)) {
			return new WP_Error(
				'live_chats_forbidden',
				__('You are not authorized to access this conversation.', 'live-chats'),
				array('status' => 403)
			);
		}

		$state = $wpdb->get_var($wpdb->prepare(
			'SELECT state FROM %i WHERE id = %d',
			$wpdb->lc_conversations,
			$conversation_id
		));
		if ($state !== 'open') {
			return new WP_Error(
				'live_chats_conversation_closed',
				__('This chat conversation has ended.', 'live-chats'),
				array('status' => 422)
			);
		}

		if ($is_operator) {
			if (!LiveChats_Auth::operator_can($msg_type === 'note' ? 'note' : 'reply', $conversation_id, $actor)) {
				return new WP_Error(
					'live_chats_forbidden',
					__('You do not have permission to reply to this conversation.', 'live-chats'),
					array('status' => 403)
				);
			}

			if ($msg_type !== 'note') {
				$ctx = LiveChats_Auth::operator_context($actor_id, $conversation_id);
				if ($ctx && $ctx['holder_id'] === 0) {
					if (!self::claim_if_unclaimed($conversation_id, $actor_id)) {
						return new WP_Error(
							'live_chats_already_claimed',
							__('Another agent has just accepted this chat.', 'live-chats'),
							array('status' => 409)
						);
					}
				}
			}
		}

		$now    = current_time('mysql');
		$result = $wpdb->insert(
			$wpdb->lc_messages,
			array(
				'conversation_id' => $conversation_id,
				'account_id'      => $actor_id,
				'msg'             => $message,
				'attachment_url'  => $attachment_url,
				'attachment_type' => $attachment_type,
				'attachment_name' => $attachment_name,
				'attachment_size' => $attachment_size,
				'msg_type'        => $msg_type,
				'submit_date'     => $now,
				'is_read'         => ($msg_type === 'note' ? 1 : 0),
			),
			array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d')
		);

		if ($result === false) {
			return new WP_Error(
				'live_chats_send_failed',
				__('Failed to save message.', 'live-chats'),
				array('status' => 500)
			);
		}

		$message_id = (int) $wpdb->insert_id;
		LiveChats_Data::touch_conversation($conversation_id);
		LiveChats_Data::clear_typing($conversation_id, $actor_id);

		do_action('live_chats_after_save_message', $message_id, $conversation_id, $actor_id, $msg_type);

		return array(
			'id'              => $message_id,
			'msg'             => $message,
			'msg_type'        => $msg_type,
			'conversation_id' => $conversation_id,
			'time'            => mysql2date('H:i', $now),
			'date'            => mysql2date('Y/m/d', $now),
			'ts'              => (int) mysql2date('U', $now),
		);
	}

	/**
	 * Insert a staff-visible system audit message.
	 */
	public static function create_system_message($conversation_id, $text) {
		global $wpdb;

		$cid  = (int) $conversation_id;
		$text = sanitize_text_field((string) $text);
		if ($cid <= 0 || $text === '') {
			return false;
		}

		$wpdb->insert(
			$wpdb->lc_messages,
			array(
				'conversation_id' => $cid,
				'account_id'      => 0,
				'msg'             => $text,
				'msg_type'        => 'system',
				'submit_date'     => current_time('mysql'),
				'is_read'         => 1,
			),
			array('%d', '%d', '%s', '%s', '%s', '%d')
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Securely validate and store an uploaded chat file in uploads/live-chats/.
	 */
	public static function store_attachment(array $args) {
		$actor    = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
		$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;
		$file     = isset($args['file']) && is_array($args['file']) ? $args['file'] : array();

		if ($actor_id <= 0) {
			return new WP_Error('live_chats_unauthorized', __('Unauthorized.', 'live-chats'), array('status' => 401));
		}

		if (
			!LiveChats_Auth::rate_limit('upload_' . $actor_id, 10, MINUTE_IN_SECONDS)
			|| !LiveChats_Auth::rate_limit('upload_ip_' . LiveChats_Data::client_ip(), 30, 10 * MINUTE_IN_SECONDS)
		) {
			return new WP_Error(
				'live_chats_rate_limited',
				__('Too many uploads. Please wait a moment and try again.', 'live-chats'),
				array('status' => 429)
			);
		}

		if (empty($file) || empty($file['name']) || empty($file['tmp_name'])) {
			return new WP_Error('live_chats_no_file', __('No file was provided.', 'live-chats'), array('status' => 400));
		}

		$max_size = (int) apply_filters('live_chats_max_attachment_size', 5 * MB_IN_BYTES);
		if (!empty($file['size']) && (int) $file['size'] > $max_size) {
			return new WP_Error(
				'live_chats_file_too_large',
				sprintf(
					/* translators: %s: formatted size limit */
					__('File exceeds the %s limit.', 'live-chats'),
					size_format($max_size)
				),
				array('status' => 413)
			);
		}

		$allowed_mimes = (array) apply_filters('live_chats_attachment_mimes', array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'pdf'          => 'application/pdf',
			'txt'          => 'text/plain',
			'doc'          => 'application/msword',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		));

		// Verify actual file content / MIME before moving.
		$checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);
		if (empty($checked['ext']) || empty($checked['type'])) {
			return new WP_Error(
				'live_chats_invalid_file_type',
				__('This file type is not allowed. Allowed types: JPG, PNG, GIF, WEBP, PDF, TXT, DOC, DOCX.', 'live-chats'),
				array('status' => 415)
			);
		}

		if (!function_exists('wp_handle_upload')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$dir_filter = array(__CLASS__, 'attachment_upload_dir');
		add_filter('upload_dir', $dir_filter);
		$movefile = wp_handle_upload($file, array(
			'test_form'                => false,
			'mimes'                    => $allowed_mimes,
			'unique_filename_callback' => array(__CLASS__, 'attachment_filename'),
		));
		remove_filter('upload_dir', $dir_filter);

		if (!$movefile || isset($movefile['error'])) {
			return new WP_Error(
				'live_chats_upload_failed',
				isset($movefile['error']) ? $movefile['error'] : __('File upload failed.', 'live-chats'),
				array('status' => 500)
			);
		}

		// Protect upload directory against directory listing.
		$upload_dir = wp_get_upload_dir();
		$guard      = trailingslashit($upload_dir['basedir']) . 'live-chats/index.php';
		if (!file_exists($guard)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents($guard, "<?php\n// Silence is golden.\n");
		}

		return array(
			'url'  => esc_url_raw($movefile['url']),
			'type' => strpos((string) $movefile['type'], 'image/') === 0 ? 'image' : 'file',
			'name' => sanitize_file_name($file['name']),
			'size' => (int) $file['size'],
		);
	}

	/**
	 * Custom upload dir filter for Live Chats attachments.
	 */
	public static function attachment_upload_dir($dirs) {
		$dirs['subdir'] = '/live-chats';
		$dirs['path']   = $dirs['basedir'] . '/live-chats';
		$dirs['url']    = $dirs['baseurl'] . '/live-chats';
		return $dirs;
	}

	/**
	 * Randomize uploaded filename to prevent enumeration.
	 */
	public static function attachment_filename($dir, $name, $ext) {
		return wp_unique_filename($dir, strtolower(wp_generate_password(16, false)) . '-' . $name);
	}

	/**
	 * Close a conversation (operator action).
	 */
	public static function close_conversation($conversation_id, array $actor) {
		global $wpdb;

		$cid      = (int) $conversation_id;
		$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;

		if ($cid <= 0 || $actor_id <= 0) {
			return new WP_Error('live_chats_invalid_request', __('Conversation not found.', 'live-chats'), array('status' => 400));
		}

		$ctx = LiveChats_Auth::operator_context($actor_id, $cid);
		if (!$ctx) {
			return new WP_Error('live_chats_not_found', __('Conversation not found.', 'live-chats'), array('status' => 404));
		}

		if ($ctx['state'] !== 'open') {
			return new WP_Error('live_chats_already_closed', __('This conversation is already closed.', 'live-chats'), array('status' => 409));
		}

		if (!LiveChats_Auth::operator_can('close', $cid, $actor)) {
			return new WP_Error('live_chats_forbidden', __('Only the assigned agent or a manager can close this conversation.', 'live-chats'), array('status' => 403));
		}

		$wpdb->update(
			$wpdb->lc_conversations,
			array(
				'state'          => 'closed',
				'open_sender_id' => null,
				'closed_at'      => current_time('mysql'),
			),
			array('id' => $cid),
			array('%s', '%s', '%s'),
			array('%d')
		);

		self::create_system_message(
			$cid,
			sprintf(
				/* translators: %s: agent name */
				__('Conversation closed by %s', 'live-chats'),
				isset($actor['full_name']) ? $actor['full_name'] : __('Agent', 'live-chats')
			)
		);

		do_action('live_chats_conversation_closed', $cid, $actor_id);

		return array('id' => $cid, 'state' => 'closed');
	}

	/**
	 * End a visitor's active chat session (closes their open conversations).
	 */
	public static function end_visitor_session(array $actor) {
		global $wpdb;

		$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;
		if ($actor_id <= 0) {
			return new WP_Error('live_chats_invalid_request', __('Invalid request.', 'live-chats'), array('status' => 400));
		}

		$open_ids = (array) $wpdb->get_col($wpdb->prepare(
			"SELECT id FROM %i WHERE account_sender_id = %d AND state = 'open'",
			$wpdb->lc_conversations,
			$actor_id
		));

		$closed = (int) $wpdb->query($wpdb->prepare(
			"UPDATE %i
			 SET state = 'closed', open_sender_id = NULL, closed_at = %s
			 WHERE account_sender_id = %d AND state = 'open'",
			$wpdb->lc_conversations,
			current_time('mysql'),
			$actor_id
		));

		foreach ($open_ids as $cid) {
			self::create_system_message((int) $cid, __('Visitor ended the chat session.', 'live-chats'));
		}

		return array('closed' => $closed);
	}

	/**
	 * Save visitor CSAT rating (1-5) and optional feedback.
	 */
	public static function rate_conversation(array $args) {
		global $wpdb;

		$cid      = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
		$actor    = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
		$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;
		$rating   = isset($args['rating']) ? (int) $args['rating'] : 0;
		$feedback = isset($args['feedback']) ? sanitize_textarea_field((string) $args['feedback']) : '';

		if ($cid <= 0 || $actor_id <= 0 || $rating < 1) {
			return new WP_Error('live_chats_invalid_rating', __('Please select a rating from 1 to 5.', 'live-chats'), array('status' => 422));
		}

		if (!LiveChats_Auth::can_access_conversation($actor_id, $cid, false)) {
			return new WP_Error('live_chats_forbidden', __('Access denied.', 'live-chats'), array('status' => 403));
		}

		$rating = min(5, $rating);
		$wpdb->update(
			$wpdb->lc_conversations,
			array('rating' => $rating, 'feedback' => mb_substr($feedback, 0, 500)),
			array('id' => $cid),
			array('%d', '%s'),
			array('%d')
		);

		return array('id' => $cid, 'rating' => $rating);
	}

	/**
	 * Update conversation tags (operator action).
	 */
	public static function update_tags(array $args) {
		global $wpdb;

		$cid      = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
		$actor    = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
		$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;
		$tags     = isset($args['tags']) && is_array($args['tags'])
			? array_values(array_unique(array_filter(array_map(function ($t) {
				return mb_substr(trim(sanitize_text_field((string) $t)), 0, 30);
			}, $args['tags']))))
			: array();

		if ($cid <= 0 || $actor_id <= 0) {
			return new WP_Error('live_chats_invalid_request', __('Invalid request.', 'live-chats'), array('status' => 400));
		}

		if (!LiveChats_Auth::operator_can('manage', $cid, $actor)) {
			return new WP_Error('live_chats_forbidden', __('Access denied.', 'live-chats'), array('status' => 403));
		}

		$raw    = $wpdb->get_var($wpdb->prepare('SELECT custom_fields FROM %i WHERE id = %d', $wpdb->lc_conversations, $cid));
		$fields = json_decode((string) $raw, true);
		if (!is_array($fields)) {
			$fields = array();
		}

		$fields['tags'] = array_slice($tags, 0, 15);

		$wpdb->update(
			$wpdb->lc_conversations,
			array('custom_fields' => wp_json_encode($fields)),
			array('id' => $cid),
			array('%s'),
			array('%d')
		);

		return array('id' => $cid, 'tags' => $fields['tags']);
	}

	/**
	 * Email conversation transcript to visitor (or self).
	 */
	public static function send_transcript(array $args) {
		global $wpdb;

		$cid         = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
		$actor       = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
		$actor_id    = isset($actor['id']) ? (int) $actor['id'] : 0;
		$is_operator = !empty($args['is_operator']);
		$recipient   = ($args['recipient'] ?? 'self') === 'visitor' ? 'visitor' : 'self';

		if ($cid <= 0 || $actor_id <= 0) {
			return new WP_Error('live_chats_invalid_request', __('Invalid request.', 'live-chats'), array('status' => 400));
		}

		$allowed = $is_operator
			? LiveChats_Auth::operator_can('manage', $cid, $actor)
			: LiveChats_Auth::can_access_conversation($actor_id, $cid, false);

		if (!$allowed) {
			return new WP_Error('live_chats_forbidden', __('Access denied.', 'live-chats'), array('status' => 403));
		}

		if (
			!$is_operator
			&& (
				!LiveChats_Auth::rate_limit('transcript_' . $actor_id, 3, HOUR_IN_SECONDS)
				|| !LiveChats_Auth::rate_limit('transcript_ip_' . LiveChats_Data::client_ip(), 10, HOUR_IN_SECONDS)
			)
		) {
			return new WP_Error(
				'live_chats_rate_limited',
				__('Too many transcript requests. Please try again later.', 'live-chats'),
				array('status' => 429)
			);
		}

		if ($recipient === 'visitor') {
			$sender_id = (int) $wpdb->get_var($wpdb->prepare(
				'SELECT account_sender_id FROM %i WHERE id = %d',
				$wpdb->lc_conversations,
				$cid
			));
			$email = $sender_id ? (string) $wpdb->get_var($wpdb->prepare(
				'SELECT email FROM %i WHERE id = %d',
				$wpdb->lc_accounts,
				$sender_id
			)) : '';
		} else {
			$email = isset($actor['email']) ? (string) $actor['email'] : '';
		}

		if (!is_email($email)) {
			return new WP_Error('live_chats_invalid_email', __('No valid email address is available for this conversation.', 'live-chats'), array('status' => 400));
		}

		$messages = $wpdb->get_results($wpdb->prepare(
			"SELECT id, account_id, msg, attachment_name, submit_date
			 FROM %i
			 WHERE conversation_id = %d AND (msg_type IS NULL OR msg_type NOT IN ('note', 'system'))
			 ORDER BY id ASC",
			$wpdb->lc_messages,
			$cid
		));

		$names     = LiveChats_Data::account_names(wp_list_pluck((array) $messages, 'account_id'));
		$site_name = get_bloginfo('name');

		/* translators: %s: site name */
		$html = '<h2>' . sprintf(esc_html__('Chat Transcript — %s', 'live-chats'), esc_html($site_name)) . '</h2><hr>';
		foreach ((array) $messages as $m) {
			$author = isset($names[$m->account_id]) ? $names[$m->account_id]->full_name : __('User', 'live-chats');
			$body   = make_clickable(nl2br(esc_html($m->msg)));
			if (!empty($m->attachment_name)) {
				$body .= '<br><em>[' . esc_html__('Attachment:', 'live-chats') . ' ' . esc_html($m->attachment_name) . ']</em>';
			}
			$html .= '<p><strong>' . esc_html($author) . '</strong> (' . esc_html($m->submit_date) . '):<br>' . $body . '</p>';
		}

		$sent = wp_mail(
			$email,
			/* translators: %s: site name */
			sprintf(__('[%s] Your Chat Transcript', 'live-chats'), $site_name),
			$html,
			array('Content-Type: text/html; charset=UTF-8')
		);

		return array('sent_to' => $email, 'sent' => (bool) $sent);
	}

	/**
	 * Fetch visitor's conversation list ordered by latest activity.
	 */
	public static function visitor_conversations($account_id, $limit = LIVE_CHATS_LIST_LIMIT) {
		global $wpdb;

		return (array) $wpdb->get_results($wpdb->prepare(
			"SELECT c.id, c.account_sender_id, c.account_receiver_id, c.submit_date, c.state,
					COALESCE(c.last_activity, c.submit_date) AS sort_date,
					a.full_name  AS sender_name,
					a2.full_name AS receiver_name,
					lm.msg       AS msg,
					lm.attachment_name AS attachment_name,
					lm.submit_date AS msg_date,
					(SELECT COUNT(*) FROM %i um
					  WHERE um.conversation_id = c.id AND um.is_read = 0 AND um.account_id <> %d
					    AND (um.msg_type IS NULL OR um.msg_type NOT IN ('note','system'))) AS unread_count
			 FROM %i c
			 JOIN %i a  ON a.id  = c.account_sender_id
			 JOIN %i a2 ON a2.id = c.account_receiver_id
			 LEFT JOIN %i lm
					ON lm.id = (SELECT MAX(m2.id) FROM %i m2 WHERE m2.conversation_id = c.id AND (m2.msg_type IS NULL OR m2.msg_type NOT IN ('note','system')))
			 WHERE c.account_sender_id = %d OR c.account_receiver_id = %d
			 ORDER BY sort_date DESC
			 LIMIT %d",
			$wpdb->lc_messages,
			(int) $account_id,
			$wpdb->lc_conversations,
			$wpdb->lc_accounts,
			$wpdb->lc_accounts,
			$wpdb->lc_messages,
			$wpdb->lc_messages,
			(int) $account_id,
			(int) $account_id,
			(int) $limit
		), ARRAY_A);
	}

	/**
	 * Fetch operator's filtered conversation list.
	 */
	public static function operator_conversations($operator_id, $limit = LIVE_CHATS_LIST_LIMIT, $filter = 'all') {
		global $wpdb;

		$visibility = LiveChats_Auth::operator_visibility_sql($operator_id);

		$filters = array(
			'all'    => array('', array()),
			'closed' => array(" AND c.state = 'closed'", array()),
			'mine'   => array(" AND c.state = 'open' AND EXISTS (SELECT 1 FROM %i cp_mine WHERE cp_mine.conversation_id = c.id AND cp_mine.operator_id = %d AND cp_mine.is_available IN (0, 3))", array($wpdb->lc_participants, (int) $operator_id)),
			'queued' => array(" AND c.state = 'open' AND NOT EXISTS (SELECT 1 FROM %i cp_claimed WHERE cp_claimed.conversation_id = c.id AND cp_claimed.is_available = 0)", array($wpdb->lc_participants)),
			'unread' => array(" AND (SELECT COUNT(*) FROM %i um WHERE um.conversation_id = c.id AND um.is_read = 0 AND um.account_id = c.account_sender_id AND (um.msg_type IS NULL OR um.msg_type NOT IN ('note','system'))) > 0", array($wpdb->lc_messages)),
		);
		list($filter_sql, $filter_args) = isset($filters[$filter]) ? $filters[$filter] : $filters['all'];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results($wpdb->prepare(
			"SELECT c.id, c.account_sender_id, c.account_receiver_id, c.submit_date, c.last_activity, c.state,
					c.custom_fields, c.rating, c.feedback, c.closed_at,
					a.full_name  AS sender_name,   a.country  AS sender_country,   a.last_seen AS sender_last_seen,
					a2.full_name AS receiver_name, a2.country AS receiver_country, a2.last_seen AS receiver_last_seen,
					lm.msg AS msg, lm.attachment_name AS attachment_name, lm.submit_date AS msg_date, lm.msg_type AS msg_type,
					CASE
						WHEN EXISTS (SELECT 1 FROM %i cp_mine WHERE cp_mine.conversation_id = c.id AND cp_mine.operator_id = %d AND cp_mine.is_available = 0) THEN 0
						WHEN EXISTS (SELECT 1 FROM %i cp_join WHERE cp_join.conversation_id = c.id AND cp_join.operator_id = %d AND cp_join.is_available = 3) THEN 3
						WHEN EXISTS (SELECT 1 FROM %i cp_other WHERE cp_other.conversation_id = c.id AND cp_other.is_available = 0) THEN 2
						ELSE 1
					END AS is_available,
					(SELECT COUNT(*) FROM %i um
					  WHERE um.conversation_id = c.id AND um.is_read = 0
						AND um.account_id = c.account_sender_id
						AND (um.msg_type IS NULL OR um.msg_type NOT IN ('note','system'))) AS unread_count,
					(SELECT a3.full_name
					   FROM %i cp3
					   JOIN %i a3 ON a3.id = cp3.operator_id
					  WHERE cp3.conversation_id = c.id AND cp3.is_available = 0
					  LIMIT 1) AS assigned_name,
					(SELECT cp4.operator_id
					   FROM %i cp4
					  WHERE cp4.conversation_id = c.id AND cp4.is_available = 0
					  LIMIT 1) AS assigned_id
			 FROM %i c
			 JOIN %i a  ON a.id  = c.account_sender_id
			 JOIN %i a2 ON a2.id = c.account_receiver_id
			 LEFT JOIN %i lm
					ON lm.id = (SELECT MAX(m2.id) FROM %i m2 WHERE m2.conversation_id = c.id)
			 WHERE 1=1" . $visibility['sql'] . $filter_sql . '
			 ORDER BY COALESCE(c.last_activity, c.submit_date) DESC
			 LIMIT %d',
			array_merge(
				array(
					$wpdb->lc_participants,
					(int) $operator_id,
					$wpdb->lc_participants,
					(int) $operator_id,
					$wpdb->lc_participants,
					$wpdb->lc_messages,
					$wpdb->lc_participants,
					$wpdb->lc_accounts,
					$wpdb->lc_participants,
					$wpdb->lc_conversations,
					$wpdb->lc_accounts,
					$wpdb->lc_accounts,
					$wpdb->lc_messages,
					$wpdb->lc_messages,
				),
				$visibility['args'],
				$filter_args,
				array((int) $limit)
			)
		), ARRAY_A);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Load visitor thread HTML & metadata (marks incoming operator messages as read).
	 */
	public static function visitor_thread_view(array $args) {
		global $wpdb;

		$cid      = isset($args['conversation_id']) ? (int) $args['conversation_id'] : 0;
		$actor    = isset($args['actor']) && is_array($args['actor']) ? $args['actor'] : array();
		$actor_id = isset($actor['id']) ? (int) $actor['id'] : 0;

		if ($cid <= 0 || $actor_id <= 0) {
			return new WP_Error('live_chats_invalid_request', __('Conversation not found.', 'live-chats'), array('status' => 400));
		}

		if (!LiveChats_Auth::can_access_conversation($actor_id, $cid, false)) {
			return new WP_Error('live_chats_forbidden', __('Conversation not found.', 'live-chats'), array('status' => 403));
		}

		$conversation = $wpdb->get_row($wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			$wpdb->lc_conversations,
			$cid
		));
		if (!$conversation) {
			return new WP_Error('live_chats_not_found', __('Conversation not found.', 'live-chats'), array('status' => 404));
		}

		$wpdb->update(
			$wpdb->lc_accounts,
			array('status' => 'Online', 'last_seen' => current_time('mysql')),
			array('id' => $actor_id),
			array('%s', '%s'),
			array('%d')
		);

		$wpdb->query($wpdb->prepare(
			"UPDATE %i
			 SET is_read = 1
			 WHERE conversation_id = %d AND account_id <> %d AND is_read = 0
			   AND (msg_type IS NULL OR msg_type NOT IN ('note','system'))",
			$wpdb->lc_messages,
			$cid,
			$actor_id
		));

		$messages = (array) $wpdb->get_results($wpdb->prepare(
			"SELECT id, account_id, msg, attachment_url, attachment_type, attachment_name, attachment_size, msg_type, submit_date
			 FROM %i
			 WHERE conversation_id = %d AND (msg_type IS NULL OR msg_type NOT IN ('note', 'system'))
			 ORDER BY id ASC LIMIT 500",
			$wpdb->lc_messages,
			$cid
		));

		$names        = LiveChats_Data::account_names(wp_list_pluck($messages, 'account_id'));
		$state        = (string) $conversation->state;
		$read_through = LiveChats_Data::conversation_read_through($cid, $actor_id);

		$html  = LiveChats_Render::messages($messages, $names, $actor_id, array(), 'guest', $read_through);
		$html .= LiveChats_Render::guest_composer($cid, $state === 'open');

		return array(
			'html'         => $html,
			'state'        => $state,
			'read_through' => $read_through,
			'rating'       => (int) ($conversation->rating ?? 0),
		);
	}

	/**
	 * Load operator conversation view HTML (3-pane center + right Customer 360 panel).
	 */
	public static function operator_thread_view($conversation_id, array $account) {
		global $wpdb;

		$operator_id = (int) $account['id'];
		$cid         = (int) $conversation_id;

		if (!LiveChats_Auth::can_access_conversation($operator_id, $cid, true)) {
			return new WP_Error('live_chats_forbidden', __('Conversation not found.', 'live-chats'), array('status' => 403));
		}

		$conversation = $wpdb->get_row($wpdb->prepare(
			'SELECT c.*,
					a.full_name AS sender_name, a.email AS sender_email,
					a.ip_address AS sender_ip, a.country AS sender_country,
					a.wp_user_id AS sender_wp_user_id, a.role AS sender_role,
					a.last_seen AS sender_last_seen, a.status AS sender_status,
					a2.full_name AS receiver_name, a2.email AS receiver_email,
					a2.ip_address AS receiver_ip, a2.country AS receiver_country,
					a2.wp_user_id AS receiver_wp_user_id, a2.role AS receiver_role,
					a2.last_seen AS receiver_last_seen, a2.status AS receiver_status
			 FROM %i c
			 JOIN %i a  ON a.id  = c.account_sender_id
			 JOIN %i a2 ON a2.id = c.account_receiver_id
			 WHERE c.id = %d',
			$wpdb->lc_conversations,
			$wpdb->lc_accounts,
			$wpdb->lc_accounts,
			$cid
		));

		if (!$conversation) {
			return new WP_Error('live_chats_not_found', __('Conversation not found.', 'live-chats'), array('status' => 404));
		}

		$conversation->total_chats = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE account_sender_id = %d',
			$wpdb->lc_conversations,
			(int) $conversation->account_sender_id
		));

		$assignments  = self::conversation_assignments($cid);
		$is_available = isset($assignments[$operator_id])
			? (int) $assignments[$operator_id]->is_available
			: (self::is_conversation_claimed($assignments) ? 2 : 1);

		// Mark visitor messages as read when the assigned operator or manager views the thread.
		if ($is_available === 0 || $is_available === 3 || LiveChats_Auth::user_can('live_chats_assign')) {
			$wpdb->query($wpdb->prepare(
				"UPDATE %i
				 SET is_read = 1
				 WHERE conversation_id = %d AND account_id = %d AND is_read = 0",
				$wpdb->lc_messages,
				$cid,
				(int) $conversation->account_sender_id
			));
		}

		$messages = (array) $wpdb->get_results($wpdb->prepare(
			'SELECT id, account_id, msg, attachment_url, attachment_type, attachment_name, attachment_size, msg_type, submit_date
			 FROM %i
			 WHERE conversation_id = %d
			 ORDER BY id ASC LIMIT 500',
			$wpdb->lc_messages,
			$cid
		));

		$names        = LiveChats_Data::account_names(wp_list_pluck($messages, 'account_id'));
		$operator_ids = array_map('intval', array_keys($assignments));
		$read_through = LiveChats_Data::conversation_read_through($cid, $operator_id);

		$html = LiveChats_Render::admin_conversation(
			$conversation,
			$messages,
			$names,
			$account,
			$assignments,
			$operator_ids,
			$is_available,
			$read_through
		);

		return array(
			'html'         => $html,
			'state'        => (string) $conversation->state,
			'read_through' => $read_through,
		);
	}

	/**
	 * Build incremental delta poll payload for visitor or operator.
	 */
	public static function build_poll_response(array $account, $is_operator, array $params = array()) {
		global $wpdb;

		$account_id = (int) $account['id'];
		$cid        = isset($params['conversation_id']) ? (int) $params['conversation_id'] : 0;
		$since_id   = isset($params['since_id']) ? (int) $params['since_id'] : 0;
		$client_fp  = isset($params['fingerprint']) ? sanitize_text_field((string) $params['fingerprint']) : '';
		$filter     = isset($params['filter']) ? sanitize_text_field((string) $params['filter']) : 'all';
		$is_typing  = !empty($params['typing']);
		$peek       = isset($params['peek']) ? sanitize_text_field((string) $params['peek']) : '';

		$can_access = $cid > 0 && LiveChats_Auth::can_access_conversation($account_id, $cid, $is_operator);

		if ($is_typing && $can_access) {
			LiveChats_Data::set_typing($cid, $account_id, $is_operator ? '' : $peek);
		}

		LiveChats_Data::heartbeat($account, $is_operator);

		$typing_info = $can_access
			? LiveChats_Data::check_typing($cid, $account_id)
			: array('typing' => false, 'name' => '', 'peek' => '');

		if (!$is_operator && $typing_info['typing']) {
			$typing_info['name'] = LiveChats_Data::visitor_agent_name($typing_info['name']);
		}

		$response = array(
			'messages'         => array(),
			'last_id'          => $since_id,
			'list'             => null,
			'fingerprint'      => '',
			'state'            => '',
			'read_through'     => 0,
			'unread_total'     => 0,
			'typing'           => $typing_info['typing'],
			'typing_user'      => $typing_info['name'],
			'sneak_peek'       => $is_operator ? (string) $typing_info['peek'] : '',
			'operators_online' => $is_operator ? true : (LiveChats_Data::operators_online_count() > 0),
			'visitor_online'   => false,
			'next_delay'       => 15000,
		);

		$latest_activity = null;

		if ($can_access) {
			$conversation = $wpdb->get_row($wpdb->prepare(
				'SELECT c.id, c.state, c.last_activity, c.account_sender_id, a.last_seen AS visitor_last_seen
				 FROM %i c
				 JOIN %i a ON a.id = c.account_sender_id
				 WHERE c.id = %d',
				$wpdb->lc_conversations,
				$wpdb->lc_accounts,
				$cid
			));

			if ($conversation) {
				$response['state']        = (string) $conversation->state;
				$response['read_through'] = LiveChats_Data::conversation_read_through($cid, $account_id);
				$latest_activity          = $conversation->last_activity;

				if (!empty($conversation->visitor_last_seen)) {
					$response['visitor_online'] = (time() - (int) mysql2date('U', $conversation->visitor_last_seen)) <= (3 * MINUTE_IN_SECONDS);
				}

				if ($is_operator) {
					$messages = (array) $wpdb->get_results($wpdb->prepare(
						'SELECT id, account_id, msg, attachment_url, attachment_type, attachment_name, attachment_size, msg_type, submit_date
						 FROM %i
						 WHERE conversation_id = %d AND id > %d
						 ORDER BY id ASC LIMIT 200',
						$wpdb->lc_messages,
						$cid,
						$since_id
					));
				} else {
					$messages = (array) $wpdb->get_results($wpdb->prepare(
						"SELECT id, account_id, msg, attachment_url, attachment_type, attachment_name, attachment_size, msg_type, submit_date
						 FROM %i
						 WHERE conversation_id = %d AND id > %d AND (msg_type IS NULL OR msg_type NOT IN ('note', 'system'))
						 ORDER BY id ASC LIMIT 200",
						$wpdb->lc_messages,
						$cid,
						$since_id
					));
				}

				if ($messages) {
					$names        = LiveChats_Data::account_names(wp_list_pluck($messages, 'account_id'));
					$operator_ids = $is_operator ? array_map('intval', array_keys(self::conversation_assignments($cid))) : array();

					foreach ($messages as $m) {
						$row = LiveChats_Data::format_message($m, $names, $account_id, $operator_ids);
						if (!$is_operator && !$row['mine']) {
							$row['author'] = LiveChats_Data::visitor_agent_name($row['author'], (int) $m->account_id);
						}
						$response['messages'][] = $row;
						$response['last_id']    = max($response['last_id'], $row['id']);
					}

					// Mark newly delivered messages from the other party as read.
					if ($is_operator) {
						$wpdb->query($wpdb->prepare(
							'UPDATE %i SET is_read = 1 WHERE conversation_id = %d AND account_id = %d AND is_read = 0',
							$wpdb->lc_messages,
							$cid,
							(int) $conversation->account_sender_id
						));
					} else {
						$wpdb->query($wpdb->prepare(
							"UPDATE %i SET is_read = 1
							 WHERE conversation_id = %d AND account_id <> %d AND is_read = 0
							   AND (msg_type IS NULL OR msg_type NOT IN ('note','system'))",
							$wpdb->lc_messages,
							$cid,
							$account_id
						));
					}
				}
			}
		}

		// Compute unread badge count for visitor.
		if (!$is_operator) {
			$response['unread_total'] = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*)
				 FROM %i m
				 JOIN %i c ON c.id = m.conversation_id
				 WHERE (c.account_sender_id = %d OR c.account_receiver_id = %d)
				   AND m.account_id <> %d
				   AND m.is_read = 0
				   AND (m.msg_type IS NULL OR m.msg_type NOT IN ('note','system'))",
				$wpdb->lc_messages,
				$wpdb->lc_conversations,
				$account_id,
				$account_id,
				$account_id
			));
		}

		$fingerprint             = self::list_fingerprint($account_id, $is_operator, $filter);
		$response['fingerprint'] = $fingerprint;

		if ($fingerprint !== $client_fp) {
			$response['list'] = $is_operator
				? LiveChats_Render::admin_conversations($account, $filter)
				: LiveChats_Render::guest_conversations($account_id);
		}

		$response['next_delay'] = self::next_delay($latest_activity, !empty($response['messages']) || $typing_info['typing']);

		return $response;
	}

	/**
	 * Lightweight SQL fingerprint for conversation list change detection.
	 */
	public static function list_fingerprint($account_id, $is_operator, $filter = 'all') {
		global $wpdb;

		if ($is_operator) {
			$visibility = LiveChats_Auth::operator_visibility_sql($account_id);
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT COUNT(*) AS convos,
						COALESCE(MAX(c.id), 0) AS max_id,
						COALESCE(MAX(c.last_activity), '') AS latest,
						COALESCE(SUM(c.state = 'open'), 0) AS open_count,
						COALESCE(SUM((SELECT COUNT(*) FROM %i um WHERE um.conversation_id = c.id AND um.is_read = 0 AND um.account_id = c.account_sender_id)), 0) AS unread,
						COALESCE(SUM((SELECT COALESCE(SUM(pp.operator_id * 4 + pp.is_available), 0) FROM %i pp WHERE pp.conversation_id = c.id AND pp.is_available IN (0, 3))), 0) AS assignments
				 FROM %i c
				 WHERE 1=1" . $visibility['sql'],
				array_merge(array($wpdb->lc_messages, $wpdb->lc_participants, $wpdb->lc_conversations), $visibility['args'])
			), ARRAY_A);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT COUNT(*) AS convos,
						COALESCE(MAX(c.id), 0) AS max_id,
						COALESCE(MAX(c.last_activity), '') AS latest,
						COALESCE(SUM(c.state = 'open'), 0) AS open_count,
						COALESCE(SUM((SELECT COUNT(*) FROM %i um WHERE um.conversation_id = c.id AND um.is_read = 0 AND um.account_id <> %d)), 0) AS unread
				 FROM %i c
				 WHERE c.account_sender_id = %d OR c.account_receiver_id = %d",
				$wpdb->lc_messages,
				(int) $account_id,
				$wpdb->lc_conversations,
				(int) $account_id,
				(int) $account_id
			), ARRAY_A);
		}

		if (!$row) {
			return '';
		}

		return substr(md5(implode('|', $row) . '|' . $filter), 0, 16);
	}

	/**
	 * Server-driven adaptive polling interval (ms).
	 */
	public static function next_delay($last_activity, $is_active) {
		if ($is_active) {
			return 2000;
		}
		if (!$last_activity) {
			return 10000;
		}

		$idle = time() - (int) mysql2date('U', $last_activity);
		if ($idle < 45) {
			return 2000;
		}
		if ($idle < 3 * MINUTE_IN_SECONDS) {
			return 5000;
		}
		if ($idle < 10 * MINUTE_IN_SECONDS) {
			return 12000;
		}
		return 25000;
	}
}
