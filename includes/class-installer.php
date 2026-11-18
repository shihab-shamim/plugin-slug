<?php
/**
 * Database schema, migrations, seeding, and capability management.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class LiveChats_Installer {

	/**
	 * Granular capabilities mapped to labels.
	 */
	public static function capabilities() {
		return array(
			'live_chats_view'            => __('View live chat conversations', 'live-chats'),
			'live_chats_reply'           => __('Reply to live chat conversations', 'live-chats'),
			'live_chats_assign'          => __('Assign and manage all conversations', 'live-chats'),
			'live_chats_manage_agents'   => __('Manage live chat agents', 'live-chats'),
			'live_chats_manage_flows'    => __('Manage canned responses', 'live-chats'),
			'live_chats_manage_settings' => __('Manage live chat settings', 'live-chats'),
		);
	}

	/**
	 * All capability names managed by the plugin.
	 */
	public static function all_capability_names() {
		return array_merge(
			array_keys(self::capabilities()),
			array(LIVE_CHATS_CAP_OPERATE, LIVE_CHATS_CAP_MANAGE)
		);
	}

	/**
	 * Capabilities implied by a base umbrella capability.
	 */
	public static function caps_for_base($cap) {
		if ($cap === LIVE_CHATS_CAP_MANAGE) {
			return self::all_capability_names();
		}

		return array('live_chats_view', 'live_chats_reply', LIVE_CHATS_CAP_OPERATE);
	}

	/**
	 * Install or upgrade database tables, seed canned replies, register capabilities.
	 */
	public static function install() {
		global $wpdb;

		LiveChats_Data::register_tables();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();

		$sql[] = "CREATE TABLE {$wpdb->lc_accounts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(191) NOT NULL DEFAULT '',
			full_name varchar(191) NOT NULL DEFAULT '',
			role varchar(20) NOT NULL DEFAULT 'Guest',
			session_token_hash char(64) NOT NULL DEFAULT '',
			token_expires datetime DEFAULT NULL,
			ip_address varchar(45) NOT NULL DEFAULT '',
			country varchar(100) NOT NULL DEFAULT '',
			last_seen datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'Idle',
			PRIMARY KEY  (id),
			KEY wp_user_id (wp_user_id),
			KEY email (email),
			KEY session_token_hash (session_token_hash),
			KEY role_status (role,status)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$wpdb->lc_conversations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
			account_receiver_id bigint(20) unsigned NOT NULL DEFAULT 0,
			country varchar(100) NOT NULL DEFAULT '',
			state varchar(20) NOT NULL DEFAULT 'open',
			rating tinyint(2) unsigned DEFAULT NULL,
			feedback text DEFAULT NULL,
			closed_at datetime DEFAULT NULL,
			custom_fields text DEFAULT NULL,
			submit_date datetime DEFAULT NULL,
			last_activity datetime DEFAULT NULL,
			source varchar(20) NOT NULL DEFAULT 'chat',
			open_sender_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY account_sender_id (account_sender_id),
			KEY account_receiver_id (account_receiver_id),
			KEY state_activity (state,last_activity),
			KEY rating (rating),
			UNIQUE KEY open_conversation_per_sender (open_sender_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$wpdb->lc_messages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			account_id bigint(20) unsigned NOT NULL DEFAULT 0,
			msg text NOT NULL,
			attachment_url varchar(500) NOT NULL DEFAULT '',
			attachment_type varchar(50) NOT NULL DEFAULT '',
			attachment_name varchar(255) NOT NULL DEFAULT '',
			attachment_size int(10) unsigned NOT NULL DEFAULT 0,
			msg_type varchar(20) NOT NULL DEFAULT 'message',
			submit_date datetime DEFAULT NULL,
			is_read tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY conversation_seq (conversation_id,id),
			KEY conversation_unread (conversation_id,is_read),
			KEY conversation_type (conversation_id,msg_type),
			KEY account_id (account_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$wpdb->lc_participants} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			operator_id bigint(20) unsigned NOT NULL DEFAULT 0,
			is_available tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY conversation_operator (conversation_id,operator_id),
			KEY operator_available (operator_id,is_available)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$wpdb->lc_canned_responses} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			shortcut varchar(50) NOT NULL DEFAULT '',
			title varchar(100) NOT NULL DEFAULT '',
			content text NOT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY shortcut (shortcut)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ($sql as $statement) {
			dbDelta($statement);
		}

		self::seed_canned_responses();
		self::register_capabilities();
		self::ensure_default_operator();
		self::verify_schema();

		update_option('live_chats_db_version', LIVE_CHATS_DB_VERSION);
		update_option('live_chats_caps_version', LIVE_CHATS_CAPS_VERSION);
	}

	/**
	 * Ensure at least one administrator has an operator account row so visitors can always start a chat.
	 */
	public static function ensure_default_operator() {
		global $wpdb;

		$existing = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE role IN ('Operator','Agent','Manager')",
			$wpdb->lc_accounts
		));
		if ($existing > 0) {
			return;
		}

		$admins = get_users(array(
			'role'    => 'administrator',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => 5,
		));

		foreach ($admins as $user) {
			$has = (int) $wpdb->get_var($wpdb->prepare(
				'SELECT id FROM %i WHERE wp_user_id = %d LIMIT 1',
				$wpdb->lc_accounts,
				(int) $user->ID
			));
			if ($has > 0) {
				$wpdb->update(
					$wpdb->lc_accounts,
					array('role' => 'Manager'),
					array('id' => $has),
					array('%s'),
					array('%d')
				);
				continue;
			}
			$wpdb->insert(
				$wpdb->lc_accounts,
				array(
					'wp_user_id' => (int) $user->ID,
					'email'      => (string) $user->user_email,
					'full_name'  => (string) $user->display_name,
					'role'       => 'Manager',
					'last_seen'  => current_time('mysql'),
					'status'     => 'Idle',
				),
				array('%d', '%s', '%s', '%s', '%s', '%s')
			);
		}
	}

	/**
	 * Verify required tables and columns exist after installation/upgrade.
	 */
	public static function verify_schema() {
		global $wpdb;

		$expected = array(
			'accounts'         => array('id', 'wp_user_id', 'email', 'full_name', 'role', 'session_token_hash', 'last_seen'),
			'conversations'    => array('id', 'account_sender_id', 'account_receiver_id', 'state', 'rating', 'feedback', 'closed_at', 'open_sender_id'),
			'messages'         => array('id', 'conversation_id', 'account_id', 'msg', 'attachment_url', 'attachment_type', 'attachment_name', 'attachment_size', 'msg_type', 'is_read'),
			'participants'     => array('id', 'conversation_id', 'operator_id', 'is_available'),
			'canned_responses' => array('id', 'shortcut', 'title', 'content'),
		);

		$missing = array();
		foreach ($expected as $short => $columns) {
			$table = LiveChats_Data::table($short);
			if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
				$missing[] = $short;
				continue;
			}

			$present = (array) $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
			foreach (array_diff($columns, $present) as $col) {
				$missing[] = $short . '.' . $col;
			}
		}

		update_option('live_chats_schema_missing', $missing);
		return $missing;
	}

	/**
	 * Seed default canned responses on fresh install.
	 */
	public static function seed_canned_responses() {
		global $wpdb;

		$count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->lc_canned_responses));
		if ($count > 0) {
			return;
		}

		$now      = current_time('mysql');
		$defaults = array(
			array(
				'shortcut' => 'hello',
				'title'    => __('Greeting', 'live-chats'),
				'content'  => __('Hello! How can I help you today?', 'live-chats'),
			),
			array(
				'shortcut' => 'wait',
				'title'    => __('Checking on this', 'live-chats'),
				'content'  => __('Thanks for waiting! Let me look into this for you right now.', 'live-chats'),
			),
			array(
				'shortcut' => 'docs',
				'title'    => __('Help Center', 'live-chats'),
				'content'  => __('You can find helpful guides and answers in our documentation.', 'live-chats'),
			),
			array(
				'shortcut' => 'bye',
				'title'    => __('Closing', 'live-chats'),
				'content'  => __('Thank you for chatting with us! Let us know if you need anything else.', 'live-chats'),
			),
		);

		foreach ($defaults as $row) {
			$wpdb->insert(
				$wpdb->lc_canned_responses,
				array(
					'shortcut'   => $row['shortcut'],
					'title'      => $row['title'],
					'content'    => $row['content'],
					'created_at' => $now,
				),
				array('%s', '%s', '%s', '%s')
			);
		}
	}

	/**
	 * Grant capabilities to administrator role and sync operator roles.
	 */
	public static function register_capabilities() {
		$admin = get_role('administrator');
		if ($admin) {
			foreach (self::caps_for_base(LIVE_CHATS_CAP_MANAGE) as $cap) {
				$admin->add_cap($cap);
			}
		}

		if (class_exists('LiveChats_Auth')) {
			LiveChats_Auth::sync_all_account_roles();
		}
	}
}
