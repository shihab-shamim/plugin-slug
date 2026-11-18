<?php
/**
 * WordPress admin-ajax.php handlers for visitors and operators.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Each handler verifies its own nonce via check_ajax_referer() or Auth helpers.

class LiveChats_Ajax {

	public static function register() {
		$guest_actions = array(
			'live_chats_authenticate'       => 'guest_authenticate',
			'live_chats_conversations'      => 'guest_conversations',
			'live_chats_conversation'       => 'guest_conversation',
			'live_chats_find_conversation'  => 'guest_find_conversation',
			'live_chats_post_message'       => 'guest_post_message',
			'live_chats_upload_attachment'  => 'upload_attachment',
			'live_chats_rate_conversation'  => 'guest_rate_conversation',
			'live_chats_send_transcript'    => 'guest_send_transcript',
			'live_chats_end_session'        => 'guest_end_session',
			'live_chats_poll'               => 'guest_poll',
		);

		foreach ($guest_actions as $action => $method) {
			add_action('wp_ajax_' . $action, array(__CLASS__, $method));
			add_action('wp_ajax_nopriv_' . $action, array(__CLASS__, $method));
		}

		$admin_actions = array(
			'live_chats_admin_session'       => 'admin_session',
			'live_chats_admin_conversations' => 'admin_conversations',
			'live_chats_admin_conversation'  => 'admin_conversation',
			'live_chats_admin_post_message'  => 'admin_post_message',
			'live_chats_admin_poll'          => 'admin_poll',
			'live_chats_admin_claim'         => 'admin_claim',
			'live_chats_admin_close'         => 'admin_close',
			'live_chats_admin_delete'        => 'admin_delete',
			'live_chats_admin_tags'          => 'admin_update_tags',
			'live_chats_admin_suggestions'   => 'admin_suggestions',
			'live_chats_admin_transcript'    => 'admin_send_transcript',
		);

		foreach ($admin_actions as $action => $method) {
			add_action('wp_ajax_' . $action, array(__CLASS__, $method));
		}
	}

	/* -------------------------------------------------------------------------
	 * Visitor Handlers
	 * ---------------------------------------------------------------------- */

	public static function guest_authenticate() {
		check_ajax_referer('live_chats_guest_nonce', 'live_chats_nonce');

		$result = LiveChats_Engine::identify_visitor(array(
			'name'  => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
			'email' => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
			'ip'    => LiveChats_Data::client_ip(),
			'actor' => LiveChats_Auth::current_visitor_account() ?: array(),
		));

		LiveChats_Auth::ajax_respond($result);
	}

	public static function guest_conversations() {
		$account = LiveChats_Auth::require_guest_ajax();
		wp_send_json_success(array(
			'html' => LiveChats_Render::kses(LiveChats_Render::guest_conversations((int) $account['id'])),
		));
	}

	public static function guest_conversation() {
		$account = LiveChats_Auth::require_guest_ajax();

		$result = LiveChats_Engine::visitor_thread_view(array(
			'conversation_id' => isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0,
			'actor'           => $account,
		));

		if (is_wp_error($result)) {
			LiveChats_Auth::ajax_respond($result);
		}

		$result['html'] = LiveChats_Render::kses($result['html']);
		wp_send_json_success($result);
	}

	public static function guest_find_conversation() {
		$account = LiveChats_Auth::require_guest_ajax();

		$context         = isset($_POST['context']) && is_array($_POST['context']) ? LiveChats_Engine::sanitize_context(wp_unslash($_POST['context'])) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$initial_message = isset($_POST['initial_message']) ? trim(sanitize_textarea_field(wp_unslash($_POST['initial_message']))) : '';
		$force_new       = !empty($_POST['force_new']);
		$find_only       = !empty($_POST['find_only']);

		$result = LiveChats_Engine::find_or_create_conversation(array(
			'actor'     => $account,
			'context'   => $context,
			'force_new' => $force_new,
			'find_only' => $find_only,
		));

		if (is_wp_error($result)) {
			LiveChats_Auth::ajax_respond($result);
		}

		if (!empty($result['created']) && $initial_message !== '') {
			LiveChats_Engine::create_message(array(
				'conversation_id' => (int) $result['id'],
				'actor'           => $account,
				'is_operator'     => false,
				'msg'             => $initial_message,
			));
		}

		wp_send_json_success(array('id' => (int) $result['id'], 'created' => !empty($result['created'])));
	}

	public static function guest_post_message() {
		$account = LiveChats_Auth::require_guest_ajax();

		$result = LiveChats_Engine::create_message(array(
			'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
			'actor'           => $account,
			'is_operator'     => false,
			'msg'             => isset($_POST['msg']) ? sanitize_textarea_field(wp_unslash($_POST['msg'])) : '',
			'attachment_url'  => isset($_POST['attachment_url']) ? esc_url_raw(wp_unslash($_POST['attachment_url'])) : '',
			'attachment_type' => isset($_POST['attachment_type']) ? sanitize_key(wp_unslash($_POST['attachment_type'])) : '',
			'attachment_name' => isset($_POST['attachment_name']) ? sanitize_text_field(wp_unslash($_POST['attachment_name'])) : '',
			'attachment_size' => isset($_POST['attachment_size']) ? absint(wp_unslash($_POST['attachment_size'])) : 0,
		));

		LiveChats_Auth::ajax_respond($result);
	}

	public static function upload_attachment() {
		$account = check_ajax_referer('live_chats_admin_nonce', 'live_chats_nonce', false)
			? LiveChats_Auth::require_operator_ajax('live_chats_reply')
			: LiveChats_Auth::require_guest_ajax();

		$result = LiveChats_Engine::store_attachment(array(
			'actor' => $account,
			'file'  => isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		));

		LiveChats_Auth::ajax_respond($result);
	}

	public static function guest_rate_conversation() {
		$account = LiveChats_Auth::require_guest_ajax();

		$result = LiveChats_Engine::rate_conversation(array(
			'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
			'actor'           => $account,
			'rating'          => isset($_POST['rating']) ? (int) $_POST['rating'] : 0,
			'feedback'        => isset($_POST['feedback']) ? sanitize_textarea_field(wp_unslash($_POST['feedback'])) : '',
		));

		LiveChats_Auth::ajax_respond($result);
	}

	public static function guest_send_transcript() {
		$account = LiveChats_Auth::require_guest_ajax();

		$result = LiveChats_Engine::send_transcript(array(
			'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
			'actor'           => $account,
			'is_operator'     => false,
			'recipient'       => 'self',
		));

		LiveChats_Auth::ajax_respond($result);
	}

	public static function guest_end_session() {
		$account = LiveChats_Auth::require_guest_ajax();
		$result  = LiveChats_Engine::end_visitor_session($account);
		LiveChats_Auth::ajax_respond($result);
	}

	public static function guest_poll() {
		$account = LiveChats_Auth::require_guest_ajax();

		$params = array(
			'conversation_id' => isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0,
			'since_id'        => isset($_POST['since_id']) ? (int) $_POST['since_id'] : 0,
			'fingerprint'     => isset($_POST['fingerprint']) ? sanitize_text_field(wp_unslash($_POST['fingerprint'])) : '',
			'typing'          => !empty($_POST['typing']),
			'peek'            => isset($_POST['peek']) ? sanitize_text_field(wp_unslash($_POST['peek'])) : '',
		);

		$response = LiveChats_Engine::build_poll_response($account, false, $params);
		if ($response['list'] !== null) {
			$response['list'] = LiveChats_Render::kses($response['list']);
		}
		wp_send_json_success($response);
	}

	/* -------------------------------------------------------------------------
	 * Operator Handlers
	 * ---------------------------------------------------------------------- */

	public static function admin_session() {
		$account = LiveChats_Auth::require_operator_ajax('live_chats_view');

		wp_send_json_success(array(
			'id'         => (int) $account['id'],
			'full_name'  => (string) $account['full_name'],
			'role'       => (string) $account['role'],
			'can_manage' => LiveChats_Auth::user_can(LIVE_CHATS_CAP_MANAGE),
			'can_assign' => LiveChats_Auth::user_can('live_chats_assign'),
		));
	}

	public static function admin_conversations() {
		global $wpdb;

		$account = LiveChats_Auth::require_operator_ajax('live_chats_view');

		$wpdb->update(
			$wpdb->lc_accounts,
			array('status' => 'Online', 'last_seen' => current_time('mysql')),
			array('id' => (int) $account['id']),
			array('%s', '%s'),
			array('%d')
		);

		$filter = isset($_POST['filter']) ? sanitize_key(wp_unslash($_POST['filter'])) : 'all';

		wp_send_json_success(array(
			'html' => LiveChats_Render::kses(LiveChats_Render::admin_conversations($account, $filter)),
		));
	}

	public static function admin_conversation() {
		$account = LiveChats_Auth::require_operator_ajax('live_chats_view');
		$cid     = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

		$result = LiveChats_Engine::operator_thread_view($cid, $account);
		if (is_wp_error($result)) {
			LiveChats_Auth::ajax_respond($result);
		}

		$result['html'] = LiveChats_Render::kses($result['html']);
		wp_send_json_success($result);
	}

	public static function admin_post_message() {
		$account  = LiveChats_Auth::require_operator_ajax('live_chats_reply');
		$msg_type = isset($_POST['msg_type']) ? sanitize_key(wp_unslash($_POST['msg_type'])) : 'message';

		$result = LiveChats_Engine::create_message(array(
			'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
			'actor'           => $account,
			'is_operator'     => true,
			'msg'             => isset($_POST['msg']) ? sanitize_textarea_field(wp_unslash($_POST['msg'])) : '',
			'msg_type'        => $msg_type,
			'attachment_url'  => isset($_POST['attachment_url']) ? esc_url_raw(wp_unslash($_POST['attachment_url'])) : '',
			'attachment_type' => isset($_POST['attachment_type']) ? sanitize_key(wp_unslash($_POST['attachment_type'])) : '',
			'attachment_name' => isset($_POST['attachment_name']) ? sanitize_text_field(wp_unslash($_POST['attachment_name'])) : '',
			'attachment_size' => isset($_POST['attachment_size']) ? absint(wp_unslash($_POST['attachment_size'])) : 0,
		));

		LiveChats_Auth::ajax_respond($result);
	}

	public static function admin_poll() {
		$account = LiveChats_Auth::require_operator_ajax('live_chats_view');

		$params = array(
			'conversation_id' => isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0,
			'since_id'        => isset($_POST['since_id']) ? (int) $_POST['since_id'] : 0,
			'fingerprint'     => isset($_POST['fingerprint']) ? sanitize_text_field(wp_unslash($_POST['fingerprint'])) : '',
			'filter'          => isset($_POST['filter']) ? sanitize_key(wp_unslash($_POST['filter'])) : 'all',
			'typing'          => !empty($_POST['typing']),
		);

		$response = LiveChats_Engine::build_poll_response($account, true, $params);
		if ($response['list'] !== null) {
			$response['list'] = LiveChats_Render::kses($response['list']);
		}
		wp_send_json_success($response);
	}

	public static function admin_claim() {
		$account = LiveChats_Auth::require_operator_ajax('live_chats_reply');
		$cid     = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

		$ctx = LiveChats_Auth::operator_context((int) $account['id'], $cid);
		if ($ctx && $ctx['state'] === 'open' && $ctx['holder_id'] === (int) $account['id']) {
			wp_send_json_success(array('message' => __('Chat accepted.', 'live-chats')));
		}

		if (!LiveChats_Auth::operator_can('claim', $cid, $account)) {
			wp_send_json_error(__('You cannot claim this conversation.', 'live-chats'), 403);
		}

		if (!LiveChats_Engine::claim_if_unclaimed($cid, (int) $account['id'])) {
			wp_send_json_error(__('Another agent has already accepted this chat.', 'live-chats'), 409);
		}

		LiveChats_Engine::create_system_message(
			$cid,
			sprintf(
				/* translators: %s: agent display name */
				__('%s joined the conversation.', 'live-chats'),
				$account['full_name']
			)
		);

		wp_send_json_success(array('message' => __('Chat accepted.', 'live-chats')));
	}

	public static function admin_close() {
		$account = LiveChats_Auth::require_operator_ajax('live_chats_reply');
		$cid     = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

		$result = LiveChats_Engine::close_conversation($cid, $account);
		LiveChats_Auth::ajax_respond($result);
	}

	public static function admin_delete() {
		$account = LiveChats_Auth::require_operator_ajax('live_chats_manage_settings');
		$cid     = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

		if ($cid <= 0) {
			wp_send_json_error(__('Invalid conversation ID.', 'live-chats'), 400);
		}
		if (!LiveChats_Auth::operator_can('delete', $cid, $account) || !LiveChats_Auth::can_access_conversation((int) $account['id'], $cid, true)) {
			wp_send_json_error(__('Conversation not found.', 'live-chats'), 403);
		}
		if (!LiveChats_Data::delete_conversations(array($cid))) {
			wp_send_json_error(__('Failed to delete conversation.', 'live-chats'), 500);
		}

		wp_send_json_success(array('message' => __('Conversation deleted.', 'live-chats')));
	}

	public static function admin_update_tags() {
		$account = LiveChats_Auth::require_operator_ajax('live_chats_reply');
		$cid     = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;
		$raw     = isset($_POST['tags']) ? map_deep(wp_unslash($_POST['tags']), 'sanitize_text_field') : array();

		if (is_string($raw)) {
			$tags = array_filter(array_map('trim', explode(',', $raw)));
		} elseif (is_array($raw)) {
			$tags = $raw;
		} else {
			$tags = array();
		}

		$result = LiveChats_Engine::update_tags(array(
			'conversation_id' => $cid,
			'actor'           => $account,
			'tags'            => $tags,
		));

		LiveChats_Auth::ajax_respond($result);
	}

	public static function admin_suggestions() {
		global $wpdb;

		$account = LiveChats_Auth::require_operator_ajax('live_chats_view');
		$cid     = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

		if ($cid > 0 && !LiveChats_Auth::operator_can('view', $cid, $account)) {
			wp_send_json_error(__('Access denied.', 'live-chats'), 403);
		}

		$canned      = (array) $wpdb->get_results($wpdb->prepare('SELECT id, shortcut, title, content FROM %i ORDER BY shortcut ASC LIMIT 12', $wpdb->lc_canned_responses), ARRAY_A);
		$suggestions = array();

		foreach ($canned as $c) {
			$suggestions[] = array(
				'id'       => 'canned_' . (int) $c['id'],
				'shortcut' => $c['shortcut'],
				'title'    => '/' . $c['shortcut'] . ': ' . $c['title'],
				'text'     => $c['content'],
			);
		}

		wp_send_json_success(array('suggestions' => $suggestions));
	}

	public static function admin_send_transcript() {
		$account = LiveChats_Auth::require_operator_ajax('live_chats_reply');

		$result = LiveChats_Engine::send_transcript(array(
			'conversation_id' => isset($_POST['id']) ? (int) $_POST['id'] : 0,
			'actor'           => $account,
			'is_operator'     => true,
			'recipient'       => 'visitor',
		));

		LiveChats_Auth::ajax_respond($result);
	}
}
