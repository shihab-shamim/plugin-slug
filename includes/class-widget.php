<?php
/**
 * Frontend floating Live Chat widget template renderer.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

class LiveChats_Widget {

	/**
	 * Render the floating Live Chats widget HTML once per page.
	 */
	public static function render() {
		static $rendered = false;
		if ($rendered) {
			return '';
		}
		$rendered = true;

		$brand_title    = LiveChats_Data::decode_emoji(get_option('live_chats_brand_title', get_bloginfo('name') . ' ' . __('Support', 'live-chats')));
		$greeting_title = LiveChats_Data::decode_emoji(get_option('live_chats_greeting_title', __('Hi there 👋', 'live-chats')));
		$greeting_desc  = LiveChats_Data::decode_emoji(get_option('live_chats_greeting_desc', __('How can we help you today? Our team typically replies in minutes.', 'live-chats')));
		$widget_pos     = get_option('live_chats_widget_position', 'right');
		$widget_mode    = get_option('live_chats_widget_mode', 'hub');
		$show_home_tab  = (int) get_option('live_chats_show_home_tab', 1);

		$is_online     = LiveChats_Data::operators_online_count() > 0;
		$action_title  = LiveChats_Data::decode_emoji(get_option('live_chats_home_action_title', __('Send us a message', 'live-chats')));
		$action_sub    = LiveChats_Data::decode_emoji(get_option('live_chats_home_action_sub', $is_online ? __('We reply in minutes ⚡', 'live-chats') : __('Leave a message for our team', 'live-chats')));
		$offline_msg   = LiveChats_Data::decode_emoji(get_option('live_chats_offline_message', __('Leave your message and email below, and our team will get back to you shortly.', 'live-chats')));
		$presence_text = $is_online ? __('Online • Typically replies in minutes', 'live-chats') : __('Away • Leave a message', 'live-chats');

		$prechat_show_msg    = (int) get_option('live_chats_prechat_show_message', 1);
		$prechat_require_msg = (int) get_option('live_chats_prechat_require_message', 0);
		$prechat_title       = LiveChats_Data::decode_emoji(get_option('live_chats_prechat_intro_title', __('Start a conversation', 'live-chats')));
		$prechat_text        = LiveChats_Data::decode_emoji(get_option('live_chats_prechat_intro_text', __('Tell us who you are so an agent can help you right away.', 'live-chats')));

		$wp_user_name  = '';
		$wp_user_email = '';
		if (is_user_logged_in()) {
			$u             = wp_get_current_user();
			$wp_user_name  = (string) ($u->display_name ?: $u->user_login);
			$wp_user_email = (string) $u->user_email;
		}

		ob_start();
		?>
		<div class="live-chats-root lc-position-<?php echo esc_attr($widget_pos); ?> lc-mode-<?php echo esc_attr($widget_mode); ?>"
			 id="live-chats-widget"
			 data-widget-mode="<?php echo esc_attr($widget_mode); ?>"
			 data-show-home="<?php echo esc_attr($show_home_tab); ?>">

			<button type="button" class="lc-launcher-btn" aria-label="<?php esc_attr_e('Open live chat', 'live-chats'); ?>" aria-expanded="false">
				<span class="lc-launcher-open-icon"><?php LiveChats_Render::icon_e('comment-dots', 24); ?></span>
				<span class="lc-launcher-close-icon" style="display:none;"><?php LiveChats_Render::icon_e('close', 20); ?></span>
				<span class="lc-unread-badge" style="display:none;">0</span>
			</button>

			<div class="lc-widget-window" style="display:none;" role="dialog" aria-label="<?php echo esc_attr($brand_title); ?>">

				<div class="lc-widget-header">
					<button type="button" class="lc-header-back-btn" aria-label="<?php esc_attr_e('Back', 'live-chats'); ?>" style="display:none;">&lsaquo;</button>
					<div class="lc-header-team">
						<div class="lc-avatar-badge">
							<?php LiveChats_Render::icon_e('comments', 18); ?>
							<span class="lc-status-dot <?php echo $is_online ? 'online' : 'offline'; ?>"></span>
						</div>
						<div class="lc-header-text">
							<div class="lc-header-title"><?php echo esc_html($brand_title); ?></div>
							<div class="lc-header-subtitle">
								<span id="lc-presence-text"><?php echo esc_html($presence_text); ?></span>
							</div>
						</div>
					</div>
					<div class="lc-header-actions">
						<button type="button" class="lc-hdr-icon-btn lc-sound-toggle-btn" title="<?php esc_attr_e('Toggle sound notifications', 'live-chats'); ?>" aria-label="<?php esc_attr_e('Toggle sound notifications', 'live-chats'); ?>">
							<?php LiveChats_Render::icon_e('volume-up', 16); ?>
						</button>
						<div class="lc-menu-wrap">
							<button type="button" class="lc-hdr-icon-btn lc-menu-btn" aria-label="<?php esc_attr_e('Chat options', 'live-chats'); ?>" aria-expanded="false">
								<?php LiveChats_Render::icon_e('bars', 16); ?>
							</button>
							<ul class="lc-menu-dropdown" hidden>
								<li><a href="#" class="lc-new-conv-link"><?php esc_html_e('Start New Chat', 'live-chats'); ?></a></li>
								<li><a href="#" class="lc-send-transcript-link"><?php esc_html_e('Email Transcript', 'live-chats'); ?></a></li>
								<li><a href="#" class="lc-end-session-link"><?php esc_html_e('End Chat Session', 'live-chats'); ?></a></li>
							</ul>
						</div>
						<button type="button" class="lc-hdr-icon-btn lc-close-widget-btn" aria-label="<?php esc_attr_e('Minimize chat', 'live-chats'); ?>">&times;</button>
					</div>
				</div>

				<div class="lc-confirm-overlay" style="display:none;">
					<div class="lc-confirm-card">
						<h4><?php esc_html_e('End Chat Session?', 'live-chats'); ?></h4>
						<p><?php esc_html_e('Are you sure you want to end this conversation? You can still review your chat history or start a new chat anytime.', 'live-chats'); ?></p>
						<div class="lc-confirm-btns">
							<button type="button" class="lc-confirm-end-yes lc-btn-danger"><?php esc_html_e('Yes, End Chat', 'live-chats'); ?></button>
							<button type="button" class="lc-confirm-end-no lc-btn-secondary"><?php esc_html_e('Cancel', 'live-chats'); ?></button>
						</div>
					</div>
				</div>

				<div class="lc-widget-body">
					<?php if ($show_home_tab && $widget_mode === 'hub') : ?>
					<div class="lc-tab-view lc-tab-home" style="display:flex;">
						<div class="lc-home-hero">
							<h3><?php echo esc_html($greeting_title); ?></h3>
							<p><?php echo esc_html($greeting_desc); ?></p>
						</div>

						<?php if (!$is_online) : ?>
						<div class="lc-offline-banner">
							<div class="lc-offline-text">
								<strong><?php esc_html_e('Team is currently offline', 'live-chats'); ?></strong>
								<p><?php echo esc_html($offline_msg); ?></p>
							</div>
						</div>
						<?php endif; ?>

						<div class="lc-home-actions">
							<button type="button" class="lc-start-chat-card-btn">
								<span class="lc-card-icon"><?php LiveChats_Render::icon_e('send', 18); ?></span>
								<span class="lc-card-text">
									<span class="lc-card-title"><?php echo esc_html($action_title); ?></span>
									<span class="lc-card-sub"><?php echo esc_html($action_sub); ?></span>
								</span>
								<span class="lc-card-arrow">&rsaquo;</span>
							</button>
						</div>
					</div>
					<?php endif; ?>

					<div class="lc-tab-view lc-tab-login" style="display:none;">
						<div class="lc-prechat-intro">
							<h2><?php echo esc_html($prechat_title); ?></h2>
							<p><?php echo esc_html($prechat_text); ?></p>
						</div>

						<form id="lc-prechat-form" method="post" autocomplete="on">
							<div class="lc-form-group">
								<label class="lc-form-label" for="lc-login-name"><?php esc_html_e('Your Name', 'live-chats'); ?> <span class="lc-req">*</span></label>
								<input type="text" id="lc-login-name" name="name" value="<?php echo esc_attr($wp_user_name); ?>" placeholder="<?php esc_attr_e('Jane Doe', 'live-chats'); ?>" maxlength="100" required>
							</div>

							<div class="lc-form-group">
								<label class="lc-form-label" for="lc-login-email"><?php esc_html_e('Email Address', 'live-chats'); ?> <span class="lc-req">*</span></label>
								<input type="email" id="lc-login-email" name="email" value="<?php echo esc_attr($wp_user_email); ?>" placeholder="<?php esc_attr_e('jane@example.com', 'live-chats'); ?>" maxlength="191" required>
								<span class="lc-field-hint"><?php esc_html_e('So we can follow up if you leave the page.', 'live-chats'); ?></span>
							</div>

							<?php if ($prechat_show_msg) : ?>
							<div class="lc-form-group">
								<label class="lc-form-label" for="lc-login-msg">
									<?php esc_html_e('How can we help you?', 'live-chats'); ?>
									<?php if ($prechat_require_msg) : ?><span class="lc-req">*</span><?php endif; ?>
								</label>
								<textarea id="lc-login-msg" name="initial_message" rows="3" maxlength="1000" placeholder="<?php esc_attr_e('Write your message here…', 'live-chats'); ?>" <?php echo $prechat_require_msg ? 'required' : ''; ?>></textarea>
							</div>
							<?php endif; ?>

							<div class="lc-form-status" role="status" aria-live="polite"></div>
							<button type="submit" class="lc-btn-primary lc-prechat-submit">
								<?php echo $is_online ? esc_html__('Start Live Chat', 'live-chats') : esc_html__('Send Message', 'live-chats'); ?>
							</button>
						</form>
					</div>

					<div class="lc-tab-view lc-tab-conversations" style="display:none;">
						<div class="lc-conversations-container"></div>
					</div>

					<div class="lc-tab-view lc-tab-thread" style="display:none;">
						<div class="lc-thread-container"></div>

						<div class="lc-rating-card" style="display:none;">
							<button type="button" class="lc-rating-close" aria-label="<?php esc_attr_e('Close rating', 'live-chats'); ?>">&times;</button>
							<h4><?php esc_html_e('How was our support?', 'live-chats'); ?></h4>
							<p><?php esc_html_e('Your feedback helps us improve.', 'live-chats'); ?></p>
							<div class="lc-star-rating" role="radiogroup" aria-label="<?php esc_attr_e('Rating', 'live-chats'); ?>">
								<button type="button" class="lc-star" data-rating="1" aria-label="1 star">★</button>
								<button type="button" class="lc-star" data-rating="2" aria-label="2 stars">★</button>
								<button type="button" class="lc-star" data-rating="3" aria-label="3 stars">★</button>
								<button type="button" class="lc-star" data-rating="4" aria-label="4 stars">★</button>
								<button type="button" class="lc-star" data-rating="5" aria-label="5 stars">★</button>
							</div>
							<textarea class="lc-feedback-input" placeholder="<?php esc_attr_e('Any additional comments? (Optional)', 'live-chats'); ?>" maxlength="500"></textarea>
							<button type="button" class="lc-submit-rating-btn lc-btn-primary"><?php esc_html_e('Submit Feedback', 'live-chats'); ?></button>
							<div class="lc-rating-thanks" style="display:none;">
								<p><?php esc_html_e('Thank you for your feedback!', 'live-chats'); ?></p>
								<button type="button" class="lc-start-new-chat-btn lc-btn-primary"><?php esc_html_e('Start New Conversation', 'live-chats'); ?></button>
							</div>
						</div>
					</div>
				</div>

				<?php if ($widget_mode === 'hub' && $show_home_tab) : ?>
				<div class="lc-bottom-nav">
					<button type="button" class="lc-nav-item active" data-tab="home">
						<span class="lc-nav-icon"><?php LiveChats_Render::icon_e('home', 19); ?></span>
						<span class="lc-nav-label"><?php esc_html_e('Home', 'live-chats'); ?></span>
					</button>
					<button type="button" class="lc-nav-item" data-tab="messages">
						<span class="lc-nav-icon"><?php LiveChats_Render::icon_e('comment-dots', 19); ?></span>
						<span class="lc-nav-label"><?php esc_html_e('Messages', 'live-chats'); ?></span>
						<span class="lc-nav-badge" style="display:none;">0</span>
					</button>
				</div>
				<?php endif; ?>

			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
