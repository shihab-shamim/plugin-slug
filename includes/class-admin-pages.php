<?php
/**
 * Admin Operator Live Inbox and Settings screens.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class LiveChats_Admin_Pages {

	/**
	 * Settings tabs configuration.
	 */
	public static function settings_tabs() {
		return array(
			'branding' => array(
				'icon'      => 'dashicons-admin-appearance',
				'label'     => __('Widget Customizer', 'live-chats'),
				'summary'   => __('Customize how the chat widget looks and greets visitors.', 'live-chats'),
				'cap'       => 'live_chats_manage_settings',
				'render'    => array(__CLASS__, 'render_tab_branding'),
				'save'      => array(__CLASS__, 'handle_settings_save'),
				'save_form' => array('lc-customizer-form', __('Save Changes', 'live-chats')),
			),
			'agents'   => array(
				'icon'    => 'dashicons-groups',
				'label'   => __('Agents', 'live-chats'),
				'summary' => __('WordPress users who can view and answer live chats.', 'live-chats'),
				'cap'     => 'live_chats_manage_agents',
				'render'  => array(__CLASS__, 'render_tab_agents'),
			),
			'canned'   => array(
				'icon'    => 'dashicons-format-chat',
				'label'   => __('Canned Responses', 'live-chats'),
				'summary' => __('Saved quick replies inserted in the Live Inbox by typing /shortcut.', 'live-chats'),
				'cap'     => 'live_chats_manage_flows',
				'render'  => array(__CLASS__, 'render_tab_canned'),
				'save'    => array(__CLASS__, 'handle_canned_save'),
			),
			'general'  => array(
				'icon'      => 'dashicons-admin-settings',
				'label'     => __('General & Security', 'live-chats'),
				'summary'   => __('Capabilities, shortcode usage, and network privacy settings.', 'live-chats'),
				'cap'       => 'live_chats_manage_settings',
				'render'    => array(__CLASS__, 'render_tab_general'),
				'save'      => array(__CLASS__, 'handle_settings_save'),
				'save_form' => array('lc-general-form', __('Save Settings', 'live-chats')),
			),
		);
	}

	/**
	 * Tabs the current user is allowed to open.
	 */
	public static function allowed_settings_tabs() {
		$allowed = array();
		foreach (self::settings_tabs() as $slug => $tab) {
			if (LiveChats_Auth::user_can($tab['cap'])) {
				$allowed[] = $slug;
			}
		}
		return $allowed;
	}

	/**
	 * Register top-level admin menu and submenus.
	 */
	public static function register_menu() {
		$allowed      = self::allowed_settings_tabs();
		$tabs         = self::settings_tabs();
		$settings_cap = $allowed ? $tabs[$allowed[0]]['cap'] : LIVE_CHATS_CAP_MANAGE;

		add_menu_page(
			__('Live Chats Inbox', 'live-chats'),
			__('Live Chats', 'live-chats'),
			'live_chats_view',
			'live-chats-inbox',
			array(__CLASS__, 'render_inbox_page'),
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			'live-chats-inbox',
			__('Live Inbox', 'live-chats'),
			__('Live Inbox', 'live-chats'),
			'live_chats_view',
			'live-chats-inbox',
			array(__CLASS__, 'render_inbox_page')
		);

		if ($allowed) {
			add_submenu_page(
				'live-chats-inbox',
				__('Live Chats Settings', 'live-chats'),
				__('Settings', 'live-chats'),
				$settings_cap,
				'live-chats-settings',
				array(__CLASS__, 'render_settings_page')
			);
		}
	}

	/**
	 * Render the 3-Pane Operator Live Inbox.
	 */
	public static function render_inbox_page() {
		if (!LiveChats_Auth::user_can('live_chats_view')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'live-chats'));
		}

		$allowed_settings = self::allowed_settings_tabs();
		?>
		<div class="wrap lc-admin-wrap" id="live-chats-admin">
			<hr class="wp-header-end">
			<div class="lc-inbox-shell">

				<header class="lc-inbox-header">
					<div class="lc-inbox-brand">
						<span class="lc-brand-mark"><?php LiveChats_Render::icon_e('comment-dots', 22); ?></span>
						<div>
							<h1 class="lc-inbox-title"><?php esc_html_e('Live Inbox', 'live-chats'); ?></h1>
							<span class="lc-inbox-sub"><?php esc_html_e('Real-time Live Chats Workspace', 'live-chats'); ?></span>
						</div>
					</div>

					<div class="lc-inbox-header-right">
						<button type="button" class="lc-hdr-icon-btn lc-inbox-sound-btn" aria-pressed="true"
							title="<?php esc_attr_e('Sound alerts on', 'live-chats'); ?>"
							data-title-on="<?php esc_attr_e('Sound alerts on', 'live-chats'); ?>"
							data-title-off="<?php esc_attr_e('Sound alerts muted', 'live-chats'); ?>">
							<span class="lc-sound-on"><?php LiveChats_Render::icon_e('volume-up', 18); ?></span>
							<span class="lc-sound-off"><?php LiveChats_Render::icon_e('volume-mute', 18); ?></span>
						</button>

						<?php if ($allowed_settings) : ?>
						<a href="<?php echo esc_url(admin_url('admin.php?page=live-chats-settings')); ?>" class="lc-hdr-icon-btn" title="<?php esc_attr_e('Settings', 'live-chats'); ?>">
							<?php LiveChats_Render::icon_e('cog', 18); ?>
						</a>
						<?php endif; ?>

						<div class="lc-agent-chip">
							<div class="lc-agent-avatar" id="lc-admin-avatar" aria-hidden="true"></div>
							<div class="lc-agent-meta">
								<span class="lc-agent-name" id="lc-admin-name"></span>
								<span class="lc-agent-sub">
									<span class="lc-agent-status-pill"><span class="lc-dot-online"></span> <?php esc_html_e('Online', 'live-chats'); ?></span>
									<span class="lc-agent-role" id="lc-admin-role"></span>
								</span>
							</div>
						</div>
					</div>
				</header>

				<div class="lc-inbox-body">
					<aside class="lc-inbox-sidebar">
						<div class="lc-search-box">
							<input type="text" id="lc-conv-search" placeholder="<?php esc_attr_e('Search visitors, messages, or tags…', 'live-chats'); ?>" aria-label="<?php esc_attr_e('Search conversations', 'live-chats'); ?>">
						</div>

						<div class="lc-filter-tabs" id="lc-inbox-filters">
							<button type="button" class="lc-filter-tab active" data-filter="all"><?php esc_html_e('All', 'live-chats'); ?></button>
							<button type="button" class="lc-filter-tab" data-filter="unread"><?php esc_html_e('Unread', 'live-chats'); ?> <span class="lc-tab-badge" id="lc-unread-tab-count" style="display:none;"></span></button>
							<button type="button" class="lc-filter-tab" data-filter="mine"><?php esc_html_e('Mine', 'live-chats'); ?></button>
							<button type="button" class="lc-filter-tab" data-filter="queued"><?php esc_html_e('Queued', 'live-chats'); ?></button>
							<button type="button" class="lc-filter-tab" data-filter="closed"><?php esc_html_e('Closed', 'live-chats'); ?></button>
						</div>

						<div class="lc-sidebar-status" id="lc-sidebar-status" role="status"></div>
						<div class="lc-conversations-pane" id="lc-admin-conversations-pane"></div>
					</aside>

					<main class="lc-inbox-main" id="lc-admin-thread-pane">
						<div class="lc-inbox-empty">
							<div class="lc-empty-icon"><?php LiveChats_Render::icon_e('comments', 46); ?></div>
							<h3><?php esc_html_e('Select a conversation to start chatting', 'live-chats'); ?></h3>
							<p><?php esc_html_e('Pick an incoming or active visitor from the left sidebar to reply in real time and view customer details.', 'live-chats'); ?></p>
						</div>
					</main>
				</div>

				<div class="lc-canned-autocomplete" id="lc-canned-autocomplete" style="display:none;"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render tabbed Settings page.
	 */
	public static function render_settings_page() {
		$tabs    = self::settings_tabs();
		$allowed = self::allowed_settings_tabs();
		if (!$allowed) {
			wp_die(esc_html__('You do not have permission to access this page.', 'live-chats'));
		}

		// Run save handlers once.
		$ran = array();
		foreach ($allowed as $slug) {
			if (!empty($tabs[$slug]['save']) && is_callable($tabs[$slug]['save'])) {
				$key = is_array($tabs[$slug]['save']) ? implode('::', $tabs[$slug]['save']) : (string) $tabs[$slug]['save'];
				if (!isset($ran[$key])) {
					$ran[$key] = true;
					call_user_func($tabs[$slug]['save']);
				}
			}
		}

		$current = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : $allowed[0]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (!in_array($current, $allowed, true)) {
			$current = $allowed[0];
		}
		$tab = $tabs[$current];
		?>
		<div class="wrap lc-settings-wrap">
			<hr class="wp-header-end">
			<div class="lc-settings-shell">
				<aside class="lc-settings-sidebar">
					<div class="lc-settings-logo">
						<span class="lc-brand-mark"><?php LiveChats_Render::icon_e('comment-dots', 22); ?></span>
						<div>
							<strong><?php esc_html_e('Live Chats', 'live-chats'); ?></strong>
							<span>v<?php echo esc_html(LIVE_CHATS_VERSION); ?></span>
						</div>
					</div>
					<nav class="lc-settings-nav" aria-label="<?php esc_attr_e('Settings sections', 'live-chats'); ?>">
						<?php foreach ($allowed as $slug) : ?>
						<a href="<?php echo esc_url(admin_url('admin.php?page=live-chats-settings&tab=' . $slug)); ?>"
						   class="lc-snav-item <?php echo $current === $slug ? 'is-active' : ''; ?>">
							<span class="dashicons <?php echo esc_attr($tabs[$slug]['icon']); ?>" aria-hidden="true"></span>
							<span><?php echo esc_html($tabs[$slug]['label']); ?></span>
						</a>
						<?php endforeach; ?>
					</nav>
				</aside>

				<div class="lc-settings-main">
					<header class="lc-settings-top">
						<div>
							<h1><?php echo esc_html($tab['label']); ?></h1>
							<p class="lc-settings-intro"><?php echo esc_html($tab['summary']); ?></p>
						</div>
						<div class="lc-settings-actions">
							<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=live-chats-inbox')); ?>"><?php esc_html_e('Open Live Inbox', 'live-chats'); ?></a>
							<?php if (!empty($tab['save_form'])) : ?>
							<button type="submit" form="<?php echo esc_attr($tab['save_form'][0]); ?>" class="button button-primary">
								<?php echo esc_html($tab['save_form'][1]); ?>
							</button>
							<?php endif; ?>
						</div>
					</header>

					<div class="lc-settings-content">
						<?php call_user_func($tab['render']); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab 1: Widget Customizer with real-time mockup preview.
	 */
	public static function render_tab_branding() {
		$widget_mode         = get_option('live_chats_widget_mode', 'hub');
		$show_home_tab       = (int) get_option('live_chats_show_home_tab', 1);
		$primary_color       = get_option('live_chats_primary_color', '#2563eb');
		$widget_pos          = get_option('live_chats_widget_position', 'right');
		$brand_title         = LiveChats_Data::decode_emoji(get_option('live_chats_brand_title', get_bloginfo('name') . ' ' . __('Support', 'live-chats')));
		$greeting_title      = LiveChats_Data::decode_emoji(get_option('live_chats_greeting_title', __('Hi there 👋', 'live-chats')));
		$greeting_desc       = LiveChats_Data::decode_emoji(get_option('live_chats_greeting_desc', __('How can we help you today? Our team typically replies in minutes.', 'live-chats')));
		$action_title        = LiveChats_Data::decode_emoji(get_option('live_chats_home_action_title', __('Send us a message', 'live-chats')));
		$action_sub          = LiveChats_Data::decode_emoji(get_option('live_chats_home_action_sub', __('We reply in minutes ⚡', 'live-chats')));
		$offline_msg         = LiveChats_Data::decode_emoji(get_option('live_chats_offline_message', __('Leave your message and email below, and our team will get back to you shortly.', 'live-chats')));
		$auto_display        = (int) get_option('live_chats_auto_display', 1);
		$prechat_show_msg    = (int) get_option('live_chats_prechat_show_message', 1);
		$prechat_require_msg = (int) get_option('live_chats_prechat_require_message', 0);
		$prechat_title       = LiveChats_Data::decode_emoji(get_option('live_chats_prechat_intro_title', __('Start a conversation', 'live-chats')));
		$prechat_text        = LiveChats_Data::decode_emoji(get_option('live_chats_prechat_intro_text', __('Tell us who you are so an agent can help you right away.', 'live-chats')));

		$presets = array(
			array('name' => 'Royal Blue',   'color' => '#2563eb'),
			array('name' => 'Classic Navy', 'color' => '#1e3c72'),
			array('name' => 'Indigo',       'color' => '#4f46e5'),
			array('name' => 'Emerald',      'color' => '#059669'),
			array('name' => 'Rose',         'color' => '#e11d48'),
			array('name' => 'Violet',       'color' => '#7c3aed'),
			array('name' => 'Cyan',         'color' => '#0891b2'),
			array('name' => 'Slate',        'color' => '#1e293b'),
		);
		?>
		<div class="lc-customizer-split">
			<div class="lc-customizer-form-col">
				<form method="post" id="lc-customizer-form">
					<?php wp_nonce_field('live_chats_settings', 'live_chats_settings_nonce'); ?>
					<input type="hidden" name="live_chats_tab" value="branding">

					<div class="lc-card">
						<div class="lc-card-hdr"><h3><?php esc_html_e('Experience Mode', 'live-chats'); ?></h3></div>
						<div class="lc-mode-cards">
							<label class="lc-mode-card <?php echo $widget_mode === 'hub' ? 'active' : ''; ?>">
								<input type="radio" name="live_chats_widget_mode" value="hub" <?php checked($widget_mode, 'hub'); ?>>
								<div>
									<strong><?php esc_html_e('Self-Service Hub', 'live-chats'); ?></strong>
									<p><?php esc_html_e('Welcome screen with greeting hero, start-chat card, and bottom Home/Messages navigation.', 'live-chats'); ?></p>
								</div>
							</label>
							<label class="lc-mode-card <?php echo $widget_mode === 'direct' ? 'active' : ''; ?>">
								<input type="radio" name="live_chats_widget_mode" value="direct" <?php checked($widget_mode, 'direct'); ?>>
								<div>
									<strong><?php esc_html_e('Direct Live Chat', 'live-chats'); ?></strong>
									<p><?php esc_html_e('Opens directly into the pre-chat form or active conversation thread.', 'live-chats'); ?></p>
								</div>
							</label>
						</div>

						<div class="lc-setting-row" id="lc-hub-toggles" style="<?php echo $widget_mode === 'direct' ? 'display:none;' : ''; ?>">
							<div>
								<label for="lc-toggle-home-tab" class="lc-setting-label"><?php esc_html_e('Show Home Screen', 'live-chats'); ?></label>
								<p class="lc-setting-desc"><?php esc_html_e('Display welcome hero and start-chat action card.', 'live-chats'); ?></p>
							</div>
							<input type="checkbox" name="live_chats_show_home_tab" id="lc-toggle-home-tab" value="1" <?php checked($show_home_tab, 1); ?>>
						</div>
					</div>

					<div class="lc-card">
						<div class="lc-card-hdr"><h3><?php esc_html_e('Branding & Copy', 'live-chats'); ?></h3></div>
						<div class="lc-field-row">
							<label for="lc-brand-title-input"><?php esc_html_e('Header Title', 'live-chats'); ?></label>
							<input type="text" id="lc-brand-title-input" name="live_chats_brand_title" class="regular-text" value="<?php echo esc_attr($brand_title); ?>">
						</div>
						<div class="lc-field-row">
							<label for="lc-greeting-title-input"><?php esc_html_e('Greeting Headline', 'live-chats'); ?></label>
							<input type="text" id="lc-greeting-title-input" name="live_chats_greeting_title" class="regular-text" value="<?php echo esc_attr($greeting_title); ?>">
						</div>
						<div class="lc-field-row">
							<label for="lc-greeting-desc-input"><?php esc_html_e('Greeting Subtext', 'live-chats'); ?></label>
							<textarea id="lc-greeting-desc-input" name="live_chats_greeting_desc" rows="2" class="large-text"><?php echo esc_textarea($greeting_desc); ?></textarea>
						</div>
						<div class="lc-field-row">
							<label for="lc-action-title-input"><?php esc_html_e('Action Card Title', 'live-chats'); ?></label>
							<input type="text" id="lc-action-title-input" name="live_chats_home_action_title" class="regular-text" value="<?php echo esc_attr($action_title); ?>">
						</div>
						<div class="lc-field-row">
							<label for="lc-action-sub-input"><?php esc_html_e('Action Card Subtitle', 'live-chats'); ?></label>
							<input type="text" id="lc-action-sub-input" name="live_chats_home_action_sub" class="regular-text" value="<?php echo esc_attr($action_sub); ?>">
						</div>
						<div class="lc-field-row">
							<label for="lc-offline-msg-input"><?php esc_html_e('Offline Message', 'live-chats'); ?></label>
							<textarea id="lc-offline-msg-input" name="live_chats_offline_message" rows="2" class="large-text"><?php echo esc_textarea($offline_msg); ?></textarea>
						</div>
					</div>

					<div class="lc-card">
						<div class="lc-card-hdr"><h3><?php esc_html_e('Pre-Chat Form', 'live-chats'); ?></h3></div>
						<div class="lc-field-row">
							<label for="lc-prechat-title-input"><?php esc_html_e('Form Heading', 'live-chats'); ?></label>
							<input type="text" id="lc-prechat-title-input" name="live_chats_prechat_intro_title" class="regular-text" value="<?php echo esc_attr($prechat_title); ?>">
						</div>
						<div class="lc-field-row">
							<label for="lc-prechat-text-input"><?php esc_html_e('Form Description', 'live-chats'); ?></label>
							<textarea id="lc-prechat-text-input" name="live_chats_prechat_intro_text" rows="2" class="large-text"><?php echo esc_textarea($prechat_text); ?></textarea>
						</div>
						<div class="lc-setting-row">
							<div>
								<label for="lc-prechat-show-msg" class="lc-setting-label"><?php esc_html_e('Show initial message field', 'live-chats'); ?></label>
								<p class="lc-setting-desc"><?php esc_html_e('Allow visitors to type their first question on the pre-chat form.', 'live-chats'); ?></p>
							</div>
							<input type="checkbox" id="lc-prechat-show-msg" name="live_chats_prechat_show_message" value="1" <?php checked($prechat_show_msg, 1); ?>>
						</div>
						<div class="lc-setting-row">
							<div>
								<label for="lc-prechat-req-msg" class="lc-setting-label"><?php esc_html_e('Require initial message', 'live-chats'); ?></label>
								<p class="lc-setting-desc"><?php esc_html_e('Visitors must enter a message before starting the chat.', 'live-chats'); ?></p>
							</div>
							<input type="checkbox" id="lc-prechat-req-msg" name="live_chats_prechat_require_message" value="1" <?php checked($prechat_require_msg, 1); ?>>
						</div>
					</div>

					<div class="lc-card">
						<div class="lc-card-hdr"><h3><?php esc_html_e('Appearance & Placement', 'live-chats'); ?></h3></div>
						<div class="lc-field-row">
							<label for="lc-primary-color-picker"><?php esc_html_e('Brand Color', 'live-chats'); ?></label>
							<div class="lc-color-row">
								<input type="color" id="lc-primary-color-picker" name="live_chats_primary_color" value="<?php echo esc_attr($primary_color); ?>">
								<input type="text" id="lc-primary-color-hex" value="<?php echo esc_attr($primary_color); ?>" maxlength="7" class="lc-hex-input">
							</div>
							<div class="lc-preset-chips">
								<?php foreach ($presets as $p) : ?>
								<button type="button" class="lc-preset-chip <?php echo strtolower($primary_color) === strtolower($p['color']) ? 'active' : ''; ?>" data-color="<?php echo esc_attr($p['color']); ?>">
									<span class="lc-preset-dot" style="background:<?php echo esc_attr($p['color']); ?>;"></span>
									<?php echo esc_html($p['name']); ?>
								</button>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="lc-setting-row">
							<div>
								<label for="lc-widget-pos-select" class="lc-setting-label"><?php esc_html_e('Screen Position', 'live-chats'); ?></label>
							</div>
							<select name="live_chats_widget_position" id="lc-widget-pos-select">
								<option value="right" <?php selected($widget_pos, 'right'); ?>><?php esc_html_e('Bottom Right', 'live-chats'); ?></option>
								<option value="left" <?php selected($widget_pos, 'left'); ?>><?php esc_html_e('Bottom Left', 'live-chats'); ?></option>
							</select>
						</div>

						<div class="lc-setting-row">
							<div>
								<label for="lc-auto-display" class="lc-setting-label"><?php esc_html_e('Display on every page', 'live-chats'); ?></label>
								<p class="lc-setting-desc"><?php esc_html_e('When off, place the [live_chats] shortcode on pages where you want the widget.', 'live-chats'); ?></p>
							</div>
							<input type="checkbox" id="lc-auto-display" name="live_chats_auto_display" value="1" <?php checked($auto_display, 1); ?>>
						</div>
					</div>
				</form>
			</div>

			<div class="lc-customizer-preview-col">
				<div class="lc-preview-sticky" id="lc-preview-container">
					<div class="lc-preview-bar">
						<strong><?php esc_html_e('Live Widget Preview', 'live-chats'); ?></strong>
						<span class="lc-preview-tag" id="lc-preview-mode-tag"><?php echo $widget_mode === 'direct' ? esc_html__('Direct Live Chat', 'live-chats') : esc_html__('Self-Service Hub', 'live-chats'); ?></span>
					</div>

					<div class="lc-mockup-window">
						<div class="lc-mockup-hdr" id="lc-mockup-hdr">
							<div>
								<div class="lc-mockup-title" id="lc-mockup-title"><?php echo esc_html($brand_title); ?></div>
								<div class="lc-mockup-sub"><?php esc_html_e('Online • Typically replies in minutes', 'live-chats'); ?></div>
							</div>
							<span>&times;</span>
						</div>

						<div class="lc-mockup-body">
							<div id="lc-mockup-home" style="<?php echo $widget_mode === 'direct' ? 'display:none;' : ''; ?>">
								<div class="lc-mockup-hero">
									<h4 id="lc-mockup-greeting"><?php echo esc_html($greeting_title); ?></h4>
									<p id="lc-mockup-desc"><?php echo esc_html($greeting_desc); ?></p>
								</div>
								<div class="lc-mockup-card">
									<strong id="lc-mockup-card-title"><?php echo esc_html($action_title); ?></strong>
									<span id="lc-mockup-card-sub"><?php echo esc_html($action_sub); ?></span>
								</div>
							</div>

							<div id="lc-mockup-direct" style="<?php echo $widget_mode === 'direct' ? '' : 'display:none;'; ?>">
								<div class="lc-mockup-bubble alt"><?php esc_html_e('Hi! How can we help you today?', 'live-chats'); ?></div>
								<div class="lc-mockup-bubble mine"><?php esc_html_e('I have a question about my order.', 'live-chats'); ?></div>
							</div>
						</div>

						<div class="lc-mockup-nav" id="lc-mockup-nav" style="<?php echo $widget_mode === 'direct' ? 'display:none;' : ''; ?>">
							<span class="active"><?php esc_html_e('Home', 'live-chats'); ?></span>
							<span><?php esc_html_e('Messages', 'live-chats'); ?></span>
						</div>
					</div>

					<div class="lc-mockup-launcher-row" id="lc-mockup-launcher-row">
						<span class="lc-mockup-launcher" id="lc-mockup-launcher"><?php LiveChats_Render::icon_e('comment-dots', 22); ?></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab 2: Agents directory with online presence.
	 */
	public static function render_tab_agents() {
		global $wpdb;

		$agents     = get_users(array('capability__in' => array(LIVE_CHATS_CAP_OPERATE, LIVE_CHATS_CAP_MANAGE, 'manage_options'), 'orderby' => 'display_name'));
		$role_names = wp_roles()->get_names();
		$cutoff     = LiveChats_Data::time_ago(3 * MINUTE_IN_SECONDS);
		$seen_map   = array();

		$rows = (array) $wpdb->get_results($wpdb->prepare(
			"SELECT wp_user_id, last_seen FROM %i WHERE wp_user_id > 0 AND role <> 'Guest'",
			$wpdb->lc_accounts
		), ARRAY_A);
		foreach ($rows as $r) {
			$seen_map[(int) $r['wp_user_id']] = $r['last_seen'];
		}
		?>
		<div class="lc-card">
			<div class="lc-card-hdr"><h3><?php esc_html_e('Authorized Support Agents', 'live-chats'); ?></h3></div>
			<p class="lc-setting-desc"><?php esc_html_e('Administrators and users granted the live_chats_operate capability can answer chats in the Live Inbox.', 'live-chats'); ?></p>
			<table class="widefat striped lc-table">
				<thead>
					<tr>
						<th><?php esc_html_e('Agent Name', 'live-chats'); ?></th>
						<th><?php esc_html_e('Email', 'live-chats'); ?></th>
						<th><?php esc_html_e('WordPress Role', 'live-chats'); ?></th>
						<th><?php esc_html_e('Chat Access', 'live-chats'); ?></th>
						<th><?php esc_html_e('Status', 'live-chats'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($agents as $agent) : ?>
					<?php
					$roles     = array_map(function ($s) use ($role_names) {
						return isset($role_names[$s]) ? translate_user_role($role_names[$s]) : $s;
					}, (array) $agent->roles);
					$last_seen = isset($seen_map[(int) $agent->ID]) ? (string) $seen_map[(int) $agent->ID] : '';
					$is_online = $last_seen !== '' && $last_seen >= $cutoff;
					?>
					<tr>
						<td><strong><?php echo esc_html($agent->display_name); ?></strong></td>
						<td><?php echo esc_html($agent->user_email); ?></td>
						<td><?php echo esc_html(implode(', ', $roles)); ?></td>
						<td><?php echo user_can($agent, LIVE_CHATS_CAP_MANAGE) || user_can($agent, 'manage_options') ? esc_html__('Full Access (Manager)', 'live-chats') : esc_html__('Operator (View & Reply)', 'live-chats'); ?></td>
						<td>
							<span class="lc-status-pill <?php echo $is_online ? 'is-online' : 'is-offline'; ?>">
								<?php echo $is_online ? esc_html__('Online', 'live-chats') : esc_html__('Offline', 'live-chats'); ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Tab 3: Canned Responses CRUD.
	 */
	public static function render_tab_canned() {
		global $wpdb;
		$items = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY shortcut ASC', $wpdb->lc_canned_responses));
		?>
		<div class="lc-card">
			<div class="lc-card-hdr"><h3><?php esc_html_e('Add Canned Response', 'live-chats'); ?></h3></div>
			<form method="post">
				<?php wp_nonce_field('live_chats_canned', 'live_chats_canned_nonce'); ?>
				<div class="lc-field-row">
					<label for="lc-canned-shortcut"><?php esc_html_e('Shortcut', 'live-chats'); ?></label>
					<div>/ <input type="text" id="lc-canned-shortcut" name="shortcut" required pattern="[a-zA-Z0-9_-]+" placeholder="pricing"></div>
				</div>
				<div class="lc-field-row">
					<label for="lc-canned-title"><?php esc_html_e('Title', 'live-chats'); ?></label>
					<input type="text" id="lc-canned-title" name="title" required class="regular-text" placeholder="<?php esc_attr_e('Pricing Overview', 'live-chats'); ?>">
				</div>
				<div class="lc-field-row">
					<label for="lc-canned-content"><?php esc_html_e('Reply Text', 'live-chats'); ?></label>
					<textarea id="lc-canned-content" name="content" rows="3" class="large-text" required placeholder="<?php esc_attr_e('Write the canned reply template…', 'live-chats'); ?>"></textarea>
				</div>
				<?php submit_button(__('Add Canned Reply', 'live-chats'), 'primary', 'submit_canned', false); ?>
			</form>
		</div>

		<div class="lc-card">
			<div class="lc-card-hdr"><h3><?php esc_html_e('Saved Canned Responses', 'live-chats'); ?></h3></div>
			<table class="widefat striped lc-table">
				<thead>
					<tr>
						<th><?php esc_html_e('Command', 'live-chats'); ?></th>
						<th><?php esc_html_e('Title', 'live-chats'); ?></th>
						<th><?php esc_html_e('Template', 'live-chats'); ?></th>
						<th><?php esc_html_e('Actions', 'live-chats'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if (!$items) : ?>
					<tr><td colspan="4"><?php esc_html_e('No canned responses yet.', 'live-chats'); ?></td></tr>
				<?php else : ?>
					<?php foreach ($items as $item) : ?>
					<tr>
						<td><code>/<?php echo esc_html($item->shortcut); ?></code></td>
						<td><strong><?php echo esc_html($item->title); ?></strong></td>
						<td><?php echo nl2br(esc_html($item->content)); ?></td>
						<td>
							<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('tab' => 'canned', 'delete_canned' => (int) $item->id)), 'live_chats_delete_canned')); ?>"
							   class="button button-small button-link-delete"
							   onclick="return confirm('<?php echo esc_js(__('Delete this canned response?', 'live-chats')); ?>');">
								<?php esc_html_e('Delete', 'live-chats'); ?>
							</a>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Tab 4: General & Security settings.
	 */
	public static function render_tab_general() {
		?>
		<form method="post" id="lc-general-form">
			<?php wp_nonce_field('live_chats_settings', 'live_chats_settings_nonce'); ?>
			<input type="hidden" name="live_chats_tab" value="general">

			<div class="lc-card">
				<div class="lc-card-hdr"><h3><?php esc_html_e('Shortcode & Integration', 'live-chats'); ?></h3></div>
				<p><?php esc_html_e('The chat widget automatically renders site-wide when "Display on every page" is enabled in Widget Customizer. You can also place the widget on specific pages using the shortcode:', 'live-chats'); ?></p>
				<p><code>[live_chats]</code></p>
			</div>

			<div class="lc-card">
				<div class="lc-card-hdr"><h3><?php esc_html_e('Network & Privacy', 'live-chats'); ?></h3></div>
				<div class="lc-setting-row">
					<div>
						<label for="lc-trust-proxy" class="lc-setting-label"><?php esc_html_e('Behind a Reverse Proxy / CDN', 'live-chats'); ?></label>
						<p class="lc-setting-desc"><?php esc_html_e('Trust X-Forwarded-For / CF-Connecting-IP headers when recording visitor IPs. Enable only behind Cloudflare, Nginx, or a trusted proxy.', 'live-chats'); ?></p>
					</div>
					<input type="checkbox" id="lc-trust-proxy" name="live_chats_trust_proxy" value="1" <?php checked(get_option('live_chats_trust_proxy', 0), 1); ?>>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Save Branding & General settings.
	 */
	public static function handle_settings_save() {
		if (!isset($_POST['live_chats_settings_nonce'])) {
			return;
		}
		check_admin_referer('live_chats_settings', 'live_chats_settings_nonce');

		if (!LiveChats_Auth::user_can('live_chats_manage_settings')) {
			wp_die(esc_html__('You do not have permission to do that.', 'live-chats'));
		}

		$tab = isset($_POST['live_chats_tab']) ? sanitize_key(wp_unslash($_POST['live_chats_tab'])) : 'general';

		if ($tab === 'branding') {
			if (isset($_POST['live_chats_widget_mode'])) {
				$mode = sanitize_key(wp_unslash($_POST['live_chats_widget_mode']));
				if (in_array($mode, array('hub', 'direct'), true)) {
					update_option('live_chats_widget_mode', $mode);
				}
			}
			update_option('live_chats_show_home_tab', isset($_POST['live_chats_show_home_tab']) ? 1 : 0);
			update_option('live_chats_auto_display', isset($_POST['live_chats_auto_display']) ? 1 : 0);
			update_option('live_chats_prechat_show_message', isset($_POST['live_chats_prechat_show_message']) ? 1 : 0);
			update_option('live_chats_prechat_require_message', isset($_POST['live_chats_prechat_require_message']) ? 1 : 0);

			$text_fields = array(
				'live_chats_brand_title'         => 'sanitize_text_field',
				'live_chats_greeting_title'      => 'sanitize_text_field',
				'live_chats_greeting_desc'       => 'sanitize_textarea_field',
				'live_chats_home_action_title'   => 'sanitize_text_field',
				'live_chats_home_action_sub'     => 'sanitize_text_field',
				'live_chats_offline_message'     => 'sanitize_textarea_field',
				'live_chats_prechat_intro_title' => 'sanitize_text_field',
				'live_chats_prechat_intro_text'  => 'sanitize_textarea_field',
			);
			foreach ($text_fields as $opt => $sanitizer) {
				if (isset($_POST[$opt])) {
					update_option($opt, LiveChats_Data::encode_emoji(call_user_func($sanitizer, wp_unslash($_POST[$opt]))));
				}
			}

			if (isset($_POST['live_chats_primary_color'])) {
				$color = sanitize_hex_color(wp_unslash($_POST['live_chats_primary_color']));
				if ($color) {
					update_option('live_chats_primary_color', $color);
				}
			}
			if (isset($_POST['live_chats_widget_position'])) {
				$pos = sanitize_key(wp_unslash($_POST['live_chats_widget_position']));
				if (in_array($pos, array('left', 'right'), true)) {
					update_option('live_chats_widget_position', $pos);
				}
			}
		} elseif ($tab === 'general') {
			update_option('live_chats_trust_proxy', isset($_POST['live_chats_trust_proxy']) ? 1 : 0);
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'live-chats') . '</p></div>';
	}

	/**
	 * Save/delete canned responses.
	 */
	public static function handle_canned_save() {
		if (!LiveChats_Auth::user_can('live_chats_manage_flows')) {
			return;
		}
		global $wpdb;

		if (isset($_GET['delete_canned'])) {
			check_admin_referer('live_chats_delete_canned');
			$wpdb->delete($wpdb->lc_canned_responses, array('id' => absint(wp_unslash($_GET['delete_canned']))), array('%d'));
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Canned response deleted.', 'live-chats') . '</p></div>';
			return;
		}

		if (!isset($_POST['live_chats_canned_nonce'])) {
			return;
		}
		check_admin_referer('live_chats_canned', 'live_chats_canned_nonce');

		$shortcut = isset($_POST['shortcut']) ? sanitize_key(wp_unslash($_POST['shortcut'])) : '';
		$title    = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
		$content  = isset($_POST['content']) ? sanitize_textarea_field(wp_unslash($_POST['content'])) : '';

		if ($shortcut === '' || $content === '') {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Shortcut and reply text are required.', 'live-chats') . '</p></div>';
			return;
		}

		$exists = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT id FROM %i WHERE shortcut = %s LIMIT 1',
			$wpdb->lc_canned_responses,
			$shortcut
		));

		if ($exists > 0) {
			$wpdb->update(
				$wpdb->lc_canned_responses,
				array('title' => $title, 'content' => $content),
				array('id' => $exists),
				array('%s', '%s'),
				array('%d')
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Existing canned response updated.', 'live-chats') . '</p></div>';
			return;
		}

		$wpdb->insert(
			$wpdb->lc_canned_responses,
			array(
				'shortcut'   => $shortcut,
				'title'      => $title,
				'content'    => $content,
				'created_at' => current_time('mysql'),
			),
			array('%s', '%s', '%s', '%s')
		);

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Canned response added.', 'live-chats') . '</p></div>';
	}
}
