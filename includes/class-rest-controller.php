<?php
/**
 * WordPress REST API controller (`live-chats/v1`) for both Guest and Operator endpoints.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class LiveChats_REST_Controller extends WP_REST_Controller {

	protected $namespace = 'live-chats/v1';

	public function register_routes() {
		// Guest routes.
		register_rest_route($this->namespace, '/auth/identify', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'guest_identify'),
			'permission_callback' => array($this, 'check_guest_nonce'),
			'args'                => array(
				'name'  => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
				'email' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email'),
			),
		));

		register_rest_route($this->namespace, '/auth/logout', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'guest_logout'),
			'permission_callback' => array($this, 'check_guest_auth'),
		));

		register_rest_route($this->namespace, '/conversations', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'guest_list_conversations'),
				'permission_callback' => array($this, 'check_guest_auth'),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'guest_find_or_create'),
				'permission_callback' => array($this, 'check_guest_auth'),
			),
		));

		register_rest_route($this->namespace, '/conversations/(?P<id>[\d]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'guest_get_conversation'),
			'permission_callback' => array($this, 'check_guest_auth'),
			'args'                => array(
				'id' => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
			),
		));

		register_rest_route($this->namespace, '/conversations/(?P<id>[\d]+)/messages', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'guest_post_message'),
			'permission_callback' => array($this, 'check_guest_auth'),
			'args'                => array(
				'id'              => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				'msg'             => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field'),
				'attachment_url'  => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw'),
				'attachment_type' => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key'),
				'attachment_name' => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'),
				'attachment_size' => array('type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'),
			),
		));

		register_rest_route($this->namespace, '/conversations/(?P<id>[\d]+)/rate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'guest_rate'),
			'permission_callback' => array($this, 'check_guest_auth'),
			'args'                => array(
				'id'       => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				'rating'   => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				'feedback' => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field'),
			),
		));

		register_rest_route($this->namespace, '/attachments/upload', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'guest_upload_attachment'),
			'permission_callback' => array($this, 'check_guest_auth'),
		));

		register_rest_route($this->namespace, '/poll', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'guest_poll'),
			'permission_callback' => array($this, 'check_guest_auth'),
		));

		// Operator routes.
		register_rest_route($this->namespace, '/operator/session', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'operator_session'),
			'permission_callback' => array($this, 'check_operator_view'),
		));

		register_rest_route($this->namespace, '/operator/conversations', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'operator_list_conversations'),
			'permission_callback' => array($this, 'check_operator_view'),
		));

		register_rest_route($this->namespace, '/operator/conversations/(?P<id>[\d]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'operator_get_conversation'),
				'permission_callback' => array($this, 'check_operator_view'),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array($this, 'operator_delete_conversation'),
				'permission_callback' => array($this, 'check_operator_manage'),
			),
		));

		register_rest_route($this->namespace, '/operator/conversations/(?P<id>[\d]+)/messages', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'operator_post_message'),
			'permission_callback' => array($this, 'check_operator_reply'),
		));

		register_rest_route($this->namespace, '/operator/conversations/(?P<id>[\d]+)/close', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'operator_close_conversation'),
			'permission_callback' => array($this, 'check_operator_reply'),
		));

		register_rest_route($this->namespace, '/operator/canned-responses', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'operator_get_canned'),
				'permission_callback' => array($this, 'check_operator_view'),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'operator_save_canned'),
				'permission_callback' => array($this, 'check_operator_flows'),
			),
		));

		register_rest_route($this->namespace, '/operator/canned-responses/(?P<id>[\d]+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array($this, 'operator_delete_canned'),
			'permission_callback' => array($this, 'check_operator_flows'),
		));

		register_rest_route($this->namespace, '/operator/poll', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'operator_poll'),
			'permission_callback' => array($this, 'check_operator_view'),
		));
	}

	/* -------------------------------------------------------------------------
	 * Permission Callbacks
	 * ---------------------------------------------------------------------- */

	public function check_guest_nonce($request) {
		$nonce = $request->get_header('X-LiveChats-Nonce');
		if (!$nonce) {
			$nonce = $request->get_param('live_chats_nonce');
		}
		if (!is_string($nonce) || !wp_verify_nonce(sanitize_text_field($nonce), 'live_chats_guest_nonce')) {
			return new WP_Error('live_chats_invalid_nonce', __('Session expired. Please reload the page.', 'live-chats'), array('status' => 403));
		}
		return true;
	}

	public function check_guest_auth($request) {
		$nonce_ok = $this->check_guest_nonce($request);
		if (is_wp_error($nonce_ok)) {
			return $nonce_ok;
		}
		$account = LiveChats_Auth::current_visitor_account();
		if (!$account && is_user_logged_in()) {
			$account = LiveChats_Auth::logged_in_visitor_account();
		}
		if (!$account) {
			return new WP_Error('live_chats_unauthorized', __('Your chat session has expired.', 'live-chats'), array('status' => 401));
		}
		return true;
	}

	private function check_cap($cap) {
		if (!is_user_logged_in() || !LiveChats_Auth::user_can($cap)) {
			return new WP_Error('live_chats_forbidden', __('You are not allowed to do that.', 'live-chats'), array('status' => 403));
		}
		return true;
	}

	public function check_operator_view() {
		return $this->check_cap('live_chats_view');
	}

	public function check_operator_reply() {
		return $this->check_cap('live_chats_reply');
	}

	public function check_operator_flows() {
		return $this->check_cap('live_chats_manage_flows');
	}

	public function check_operator_manage() {
		return $this->check_cap('live_chats_manage_settings');
	}

	private function resolve_guest() {
		$acct = LiveChats_Auth::current_visitor_account();
		if (!$acct && is_user_logged_in()) {
			$acct = LiveChats_Auth::logged_in_visitor_account();
		}
		return $acct;
	}

	/* -------------------------------------------------------------------------
	 * Guest Callbacks
	 * ---------------------------------------------------------------------- */

	public function guest_identify($request) {
		$res = LiveChats_Engine::identify_visitor(array(
			'name'  => $request->get_param('name'),
			'email' => $request->get_param('email'),
			'ip'    => LiveChats_Data::client_ip(),
			'actor' => LiveChats_Auth::current_visitor_account() ?: array(),
		));
		return is_wp_error($res) ? $res : rest_ensure_response($res);
	}

	public function guest_logout() {
		$acct = $this->resolve_guest();
		if ($acct) {
			LiveChats_Engine::end_visitor_session($acct);
		}
		LiveChats_Auth::clear_token();
		return rest_ensure_response(array('message' => __('Chat session ended.', 'live-chats')));
	}

	public function guest_list_conversations() {
		$acct = $this->resolve_guest();
		return rest_ensure_response(array(
			'items' => LiveChats_Engine::visitor_conversations((int) $acct['id']),
			'html'  => LiveChats_Render::kses(LiveChats_Render::guest_conversations((int) $acct['id'])),
		));
	}

	public function guest_find_or_create($request) {
		$acct = $this->resolve_guest();
		$res  = LiveChats_Engine::find_or_create_conversation(array(
			'actor'     => $acct,
			'context'   => is_array($request->get_param('context')) ? $request->get_param('context') : array(),
			'force_new' => (bool) $request->get_param('force_new'),
			'find_only' => (bool) $request->get_param('find_only'),
		));
		return is_wp_error($res) ? $res : rest_ensure_response($res);
	}

	public function guest_get_conversation($request) {
		$res = LiveChats_Engine::visitor_thread_view(array(
			'conversation_id' => (int) $request->get_param('id'),
			'actor'           => $this->resolve_guest(),
		));
		if (is_wp_error($res)) {
			return $res;
		}
		$res['html'] = LiveChats_Render::kses($res['html']);
		return rest_ensure_response($res);
	}

	public function guest_post_message($request) {
		$res = LiveChats_Engine::create_message(array(
			'conversation_id' => (int) $request->get_param('id'),
			'actor'           => $this->resolve_guest(),
			'is_operator'     => false,
			'msg'             => $request->get_param('msg'),
			'attachment_url'  => $request->get_param('attachment_url'),
			'attachment_type' => $request->get_param('attachment_type'),
			'attachment_name' => $request->get_param('attachment_name'),
			'attachment_size' => $request->get_param('attachment_size'),
		));
		return is_wp_error($res) ? $res : rest_ensure_response($res);
	}

	public function guest_rate($request) {
		$res = LiveChats_Engine::rate_conversation(array(
			'conversation_id' => (int) $request->get_param('id'),
			'actor'           => $this->resolve_guest(),
			'rating'          => (int) $request->get_param('rating'),
			'feedback'        => (string) $request->get_param('feedback'),
		));
		return is_wp_error($res) ? $res : rest_ensure_response($res);
	}

	public function guest_upload_attachment($request) {
		$files = $request->get_file_params();
		$res   = LiveChats_Engine::store_attachment(array(
			'actor' => $this->resolve_guest(),
			'file'  => isset($files['file']) && is_array($files['file']) ? $files['file'] : array(),
		));
		return is_wp_error($res) ? $res : rest_ensure_response($res);
	}

	public function guest_poll($request) {
		$res = LiveChats_Engine::build_poll_response($this->resolve_guest(), false, $request->get_params());
		if ($res['list'] !== null) {
			$res['list'] = LiveChats_Render::kses($res['list']);
		}
		$response = rest_ensure_response($res);
		if (!empty($res['fingerprint'])) {
			$response->header('ETag', '"' . $res['fingerprint'] . '"');
		}
		$response->header('Cache-Control', 'no-cache, must-revalidate');
		return $response;
	}

	/* -------------------------------------------------------------------------
	 * Operator Callbacks
	 * ---------------------------------------------------------------------- */

	public function operator_session() {
		$acct = LiveChats_Auth::operator_account();
		if (!$acct) {
			return new WP_Error('live_chats_no_profile', __('No operator profile.', 'live-chats'), array('status' => 403));
		}
		return rest_ensure_response(array(
			'id'         => (int) $acct['id'],
			'full_name'  => $acct['full_name'],
			'role'       => $acct['role'],
			'can_manage' => LiveChats_Auth::user_can(LIVE_CHATS_CAP_MANAGE),
		));
	}

	public function operator_list_conversations($request) {
		$acct   = LiveChats_Auth::operator_account();
		$filter = sanitize_key((string) $request->get_param('filter')) ?: 'all';
		return rest_ensure_response(array(
			'items' => LiveChats_Engine::operator_conversations((int) $acct['id'], LIVE_CHATS_LIST_LIMIT, $filter),
			'html'  => LiveChats_Render::kses(LiveChats_Render::admin_conversations($acct, $filter)),
		));
	}

	public function operator_get_conversation($request) {
		$acct = LiveChats_Auth::operator_account();
		$res  = LiveChats_Engine::operator_thread_view((int) $request->get_param('id'), $acct);
		if (is_wp_error($res)) {
			return $res;
		}
		$res['html'] = LiveChats_Render::kses($res['html']);
		return rest_ensure_response($res);
	}

	public function operator_post_message($request) {
		$acct = LiveChats_Auth::operator_account();
		$res  = LiveChats_Engine::create_message(array(
			'conversation_id' => (int) $request->get_param('id'),
			'actor'           => $acct,
			'is_operator'     => true,
			'msg'             => sanitize_textarea_field((string) $request->get_param('msg')),
			'msg_type'        => sanitize_key((string) $request->get_param('msg_type')) ?: 'message',
			'attachment_url'  => esc_url_raw((string) $request->get_param('attachment_url')),
			'attachment_type' => sanitize_key((string) $request->get_param('attachment_type')),
			'attachment_name' => sanitize_text_field((string) $request->get_param('attachment_name')),
			'attachment_size' => absint($request->get_param('attachment_size')),
		));
		return is_wp_error($res) ? $res : rest_ensure_response($res);
	}

	public function operator_close_conversation($request) {
		$acct = LiveChats_Auth::operator_account();
		$res  = LiveChats_Engine::close_conversation((int) $request->get_param('id'), $acct);
		return is_wp_error($res) ? $res : rest_ensure_response($res);
	}

	public function operator_delete_conversation($request) {
		$acct = LiveChats_Auth::operator_account();
		$cid  = (int) $request->get_param('id');
		if (!LiveChats_Auth::operator_can('delete', $cid, $acct) || !LiveChats_Auth::can_access_conversation((int) $acct['id'], $cid, true)) {
			return new WP_Error('live_chats_forbidden', __('Conversation not found.', 'live-chats'), array('status' => 403));
		}
		if (!LiveChats_Data::delete_conversations(array($cid))) {
			return new WP_Error('live_chats_delete_failed', __('Delete failed.', 'live-chats'), array('status' => 500));
		}
		return rest_ensure_response(array('message' => __('Conversation deleted.', 'live-chats')));
	}

	public function operator_get_canned() {
		global $wpdb;
		$items = (array) $wpdb->get_results($wpdb->prepare(
			'SELECT id, shortcut, title, content FROM %i ORDER BY shortcut ASC',
			$wpdb->lc_canned_responses
		));
		return rest_ensure_response(array('items' => $items));
	}

	public function operator_save_canned($request) {
		global $wpdb;

		$shortcut = sanitize_key((string) $request->get_param('shortcut'));
		$title    = sanitize_text_field((string) $request->get_param('title'));
		$content  = sanitize_textarea_field((string) $request->get_param('content'));
		$id       = (int) $request->get_param('id');

		if ($shortcut === '' || $content === '') {
			return new WP_Error('live_chats_invalid', __('Shortcut and content are required.', 'live-chats'), array('status' => 422));
		}

		if ($id > 0) {
			$wpdb->update(
				$wpdb->lc_canned_responses,
				array('shortcut' => $shortcut, 'title' => $title, 'content' => $content),
				array('id' => $id),
				array('%s', '%s', '%s'),
				array('%d')
			);
		} else {
			$wpdb->insert(
				$wpdb->lc_canned_responses,
				array('shortcut' => $shortcut, 'title' => $title, 'content' => $content, 'created_at' => current_time('mysql')),
				array('%s', '%s', '%s', '%s')
			);
		}

		return rest_ensure_response(array('message' => __('Canned response saved.', 'live-chats')));
	}

	public function operator_delete_canned($request) {
		global $wpdb;
		$wpdb->delete($wpdb->lc_canned_responses, array('id' => (int) $request->get_param('id')), array('%d'));
		return rest_ensure_response(array('message' => __('Canned response deleted.', 'live-chats')));
	}

	public function operator_poll($request) {
		$acct = LiveChats_Auth::operator_account();
		$res  = LiveChats_Engine::build_poll_response($acct, true, $request->get_params());
		if ($res['list'] !== null) {
			$res['list'] = LiveChats_Render::kses($res['list']);
		}
		$response = rest_ensure_response($res);
		if (!empty($res['fingerprint'])) {
			$response->header('ETag', '"' . $res['fingerprint'] . '"');
		}
		$response->header('Cache-Control', 'no-cache, must-revalidate');
		return $response;
	}
}
