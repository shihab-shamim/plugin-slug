<?php
/**
 * Shared HTML rendering helpers, SVG icons, strict KSES, message bubbles, lists, and Customer 360 panel.
 *
 * @package LiveChats
 */

defined('ABSPATH') || exit;

class LiveChats_Render {

	/**
	 * Inline crisp SVG icons (zero external icon font dependency).
	 */
	public static function icon($name, $size = 16) {
		static $paths = array(
			'comment-dots' => 'M12 2C6.5 2 2 5.9 2 10.7c0 2.7 1.4 5.1 3.6 6.7V22l3.3-1.8c1 .2 2 .4 3.1.4 5.5 0 10-3.9 10-8.7S17.5 2 12 2zm-4 10a1.3 1.3 0 1 1 0-2.6A1.3 1.3 0 0 1 8 12zm4 0a1.3 1.3 0 1 1 0-2.6A1.3 1.3 0 0 1 12 12zm4 0a1.3 1.3 0 1 1 0-2.6A1.3 1.3 0 0 1 16 12z',
			'comments'     => 'M20 2H8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h8l4 4V4a2 2 0 0 0-2-2zM4 8H3a1 1 0 0 0-1 1v12l4-4h8a1 1 0 0 0 1-1v-1H8a4 4 0 0 1-4-4V8z',
			'bars'         => 'M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z',
			'send'         => 'M2 21l21-9L2 3v7l15 2-15 2v7z',
			'trash'        => 'M9 3h6l1 1h4v2H4V4h4l1-1zM6 8h12l-1 12a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 8z',
			'close'        => 'M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7l1.4-1.4L10.6 10.6l6.3-6.3z',
			'smile'        => 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm-3.5-9a1.5 1.5 0 1 0-1.5-1.5 1.5 1.5 0 0 0 1.5 1.5zm7 0a1.5 1.5 0 1 0-1.5-1.5 1.5 1.5 0 0 0 1.5 1.5zm-3.5 6a5.5 5.5 0 0 0 4.8-2.8.8.8 0 0 0-.3-1 .8.8 0 0 0-1 .3 4 4 0 0 1-7 0 .8.8 0 0 0-1-.3.8.8 0 0 0-.3 1 5.5 5.5 0 0 0 4.8 2.8z',
			'paperclip'    => 'M16.5 6v11.5a4 4 0 0 1-8 0V5a2.5 2.5 0 0 1 5 0v10.5a1 1 0 0 1-2 0V6H10v9.5a2.5 2.5 0 0 0 5 0V5a4 4 0 0 0-8 0v12.5a5.5 5.5 0 0 0 11 0V6z',
			'file'         => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm4 18H6V4h7v5h5z',
			'home'         => 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
			'volume-up'    => 'M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z',
			'volume-mute'  => 'M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27l4.73 4.73H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z',
			'lock'         => 'M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z',
			'sparkles'     => 'M9 4a1 1 0 0 1 1-1l.7 2.1a3 3 0 0 0 2.2 2.2L15 8l-2.1.7a3 3 0 0 0-2.2 2.2L10 13l-.7-2.1a3 3 0 0 0-2.2-2.2L5 8l2.1-.7a3 3 0 0 0 2.2-2.2L9 4zm10 8a.8.8 0 0 1 .7-.8l1.4-.5-1.4-.5a2.2 2.2 0 0 0-1.5-1.5L18 7.3l-.5 1.4a2.2 2.2 0 0 0-1.5 1.5l-1.4.5 1.4.5a2.2 2.2 0 0 0 1.5 1.5l.5 1.4.5-1.4a2.2 2.2 0 0 0 1.5-1.5z',
			'eye'          => 'M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z',
			'clock'        => 'M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z',
			'bell'         => 'M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z',
			'cog'          => 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 0 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z',
			'mail'         => 'M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z',
			'times'        => 'M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7l1.4-1.4L10.6 10.6l6.3-6.3z',
			'user'         => 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z',
			'check-double' => 'M0.5 12.5l4 4 10-10-1.4-1.4-8.6 8.6-2.6-2.6-1.4 1.4zm6.6 0l4 4 10-10-1.4-1.4-8.6 8.6-2.6-2.6-1.4 1.4z',
		);

		if (!isset($paths[$name])) {
			return '';
		}

		return sprintf(
			'<svg class="lc-icon lc-icon-%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="%3$s"/></svg>',
			esc_attr($name),
			(int) $size,
			esc_attr($paths[$name])
		);
	}

	/**
	 * Echo an escaped icon.
	 */
	public static function icon_e($name, $size = 16) {
		echo self::kses(self::icon($name, $size)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Allowed HTML tags and attributes for Live Chats output.
	 */
	public static function kses_allowed() {
		static $allowed = null;
		if ($allowed !== null) {
			return $allowed;
		}

		$allowed = wp_kses_allowed_html('post');
		$common  = array(
			'class'         => true,
			'id'            => true,
			'style'         => true,
			'title'         => true,
			'role'          => true,
			'hidden'        => true,
			'tabindex'      => true,
			'aria-label'    => true,
			'aria-hidden'   => true,
			'aria-expanded' => true,
			'aria-current'  => true,
			'aria-pressed'  => true,
			'aria-controls' => true,
			'aria-live'     => true,
			'data-*'        => true,
		);

		$extra = array(
			'svg'      => array('width' => true, 'height' => true, 'viewbox' => true, 'fill' => true, 'focusable' => true, 'xmlns' => true),
			'path'     => array('d' => true, 'fill' => true),
			'form'     => array('method' => true, 'action' => true, 'autocomplete' => true, 'novalidate' => true, 'enctype' => true),
			'input'    => array('type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'maxlength' => true, 'required' => true, 'readonly' => true, 'disabled' => true, 'checked' => true, 'accept' => true, 'autocomplete' => true, 'pattern' => true, 'multiple' => true),
			'textarea' => array('name' => true, 'rows' => true, 'cols' => true, 'placeholder' => true, 'maxlength' => true, 'required' => true, 'readonly' => true, 'disabled' => true, 'autocomplete' => true),
			'select'   => array('name' => true, 'required' => true, 'disabled' => true, 'multiple' => true),
			'option'   => array('value' => true, 'selected' => true, 'disabled' => true),
			'button'   => array('type' => true, 'name' => true, 'value' => true, 'disabled' => true, 'form' => true),
			'label'    => array('for' => true),
			'a'        => array('href' => true, 'target' => true, 'rel' => true, 'download' => true),
			'img'      => array('src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true, 'decoding' => true),
			'nav'      => array(),
			'aside'    => array(),
			'header'   => array(),
		);

		foreach ($extra as $tag => $attrs) {
			$allowed[$tag] = array_merge(isset($allowed[$tag]) ? $allowed[$tag] : array(), $attrs);
		}
		foreach ($allowed as $tag => $attrs) {
			$allowed[$tag] = array_merge(is_array($attrs) ? $attrs : array(), $common);
		}

		return $allowed;
	}

	/**
	 * Escape HTML using strict Live Chats allowlist.
	 */
	public static function kses($html) {
		return wp_kses((string) $html, self::kses_allowed());
	}

	/**
	 * Deterministic avatar color from name string.
	 */
	public static function avatar_color($string) {
		$colors = array(
			'#2563eb', '#059669', '#7c3aed', '#db2777', '#ea580c',
			'#0891b2', '#4f46e5', '#0d9488', '#d97706', '#dc2626',
			'#334155', '#6366f1',
		);
		$index = hexdec(substr(sha1((string) $string), 0, 7)) % count($colors);
		return $colors[$index];
	}

	/**
	 * Single uppercase initial.
	 */
	public static function initial($name) {
		$name = trim((string) $name);
		return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
	}

	/**
	 * Country badge (2-letter ISO code pill, clean with zero missing-image risk).
	 */
	public static function country_badge($code) {
		$code = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $code));
		if (strlen($code) !== 2) {
			return '<span class="lc-country-pill lc-country-unknown" title="' . esc_attr__('Unknown location', 'live-chats') . '">—</span>';
		}
		return '<span class="lc-country-pill" title="' . esc_attr($code) . '">' . esc_html($code) . '</span>';
	}

	/**
	 * Truncated single-line preview.
	 */
	public static function preview($text, $length = 60) {
		$text = trim(preg_replace('/\s+/', ' ', (string) $text));
		return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
	}

	/**
	 * Safe HTML rendering for message text (escapes HTML first, supports bold/code for staff, linkifies URLs).
	 */
	public static function message_body_html($text, $markdown = false) {
		$escaped = esc_html((string) $text);
		if ($markdown) {
			$escaped = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $escaped);
			$escaped = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $escaped);
		}
		$html = make_clickable(nl2br($escaped));
		return preg_replace('/<a\s+(href="[^"]*")[^>]*>/i', '<a target="_blank" rel="noopener noreferrer nofollow" $1>', $html);
	}

	/**
	 * Short date/time for conversation list rows.
	 */
	public static function short_date($mysql_date) {
		if (!$mysql_date) {
			return '';
		}
		return mysql2date('Y/m/d', $mysql_date) === current_time('Y/m/d')
			? mysql2date('H:i', $mysql_date)
			: mysql2date('M j', $mysql_date);
	}

	/**
	 * Human-readable date divider label ("Today", "Yesterday", "Nov 18, 2026").
	 */
	public static function date_divider_label($ymd) {
		$ymd = (string) $ymd;
		if ($ymd === wp_date('Y/m/d')) {
			return __('Today', 'live-chats');
		}
		if ($ymd === wp_date('Y/m/d', time() - DAY_IN_SECONDS)) {
			return __('Yesterday', 'live-chats');
		}
		$ts = strtotime(str_replace('/', '-', $ymd));
		return $ts ? date_i18n(_x('M j, Y', 'chat date divider', 'live-chats'), $ts) : $ymd;
	}

	/**
	 * Determine if two consecutive messages should be visually grouped.
	 */
	public static function messages_grouped($prev, $curr) {
		if (!$prev || !$curr) {
			return false;
		}
		$prev_type = !empty($prev->msg_type) ? $prev->msg_type : 'message';
		$curr_type = !empty($curr->msg_type) ? $curr->msg_type : 'message';
		if ($prev_type !== 'message' || $curr_type !== 'message') {
			return false;
		}
		if ((int) $prev->account_id !== (int) $curr->account_id) {
			return false;
		}
		$gap = abs((int) mysql2date('U', $curr->submit_date) - (int) mysql2date('U', $prev->submit_date));
		return $gap <= (5 * MINUTE_IN_SECONDS);
	}

	/**
	 * Render complete message thread markup.
	 */
	public static function messages($messages, array $names, $viewer_account_id, array $operator_ids = array(), $style = 'guest', $read_through = 0) {
		$output        = '<div class="lc-messages" data-read-through="' . (int) $read_through . '">';
		$previous_date = '';
		$messages      = array_values((array) $messages);
		$total         = count($messages);

		if ($total === 0) {
			$output .= '<div class="lc-empty-thread"><p>' . esc_html__('Send a message to start the conversation.', 'live-chats') . '</p></div>';
		}

		foreach ($messages as $idx => $message) {
			$row = LiveChats_Data::format_message($message, $names, $viewer_account_id, $operator_ids);
			if ($style !== 'admin' && !$row['mine']) {
				$row['author'] = LiveChats_Data::visitor_agent_name($row['author'], (int) $message->account_id);
			}

			$prev    = $idx > 0 ? $messages[$idx - 1] : null;
			$next    = ($idx + 1) < $total ? $messages[$idx + 1] : null;
			$new_day = ($row['date'] !== $previous_date);

			if ($new_day) {
				$output       .= '<p class="lc-date-divider" data-date="' . esc_attr($row['date']) . '">' . esc_html(self::date_divider_label($row['date'])) . '</p>';
				$previous_date = $row['date'];
			}

			$starts_group = $new_day || !self::messages_grouped($prev, $message);
			$ends_group   = !self::messages_grouped($message, $next)
				|| ($next && mysql2date('Y/m/d', $next->submit_date) !== $row['date']);

			$output .= self::message_row($row, $style, $starts_group, $ends_group);
		}

		$output .= '</div>';
		return $output;
	}

	/**
	 * Render one message bubble, note, or system event.
	 */
	public static function message_row(array $row, $style = 'guest', $starts_group = true, $ends_group = true) {
		if ($row['msg_type'] === 'system') {
			if ($style !== 'admin') {
				return '';
			}
			return '<div class="lc-message-system" data-message-id="' . esc_attr($row['id']) . '"><span class="lc-system-text">'
				. esc_html($row['msg']) . ' · ' . esc_html($row['time']) . '</span></div>';
		}

		if ($row['msg_type'] === 'note') {
			if ($style !== 'admin') {
				return '';
			}
			return '<div class="lc-message lc-message-note" data-message-id="' . esc_attr($row['id']) . '" data-author="' . esc_attr($row['author']) . '" data-ts="' . esc_attr($row['ts']) . '">'
				. '<div class="lc-note-header">' . self::icon('lock', 13) . ' <span>' . esc_html__('Private Note', 'live-chats') . '</span> · <span class="lc-note-author">' . esc_html($row['author']) . '</span> · <span class="lc-note-time">' . esc_html($row['time']) . '</span></div>'
				. '<div class="lc-note-body">' . self::message_body_html($row['msg'], true) . '</div>'
				. '</div>';
		}

		$own   = ($style === 'admin') ? $row['staff'] : $row['mine'];
		$alt   = $own ? '' : ' alt';
		$group = ($starts_group ? ' lc-group-start' : '') . ($ends_group ? ' lc-group-end' : '');

		$attachment_html = '';
		if (!empty($row['attachment_url'])) {
			if ($row['attachment_type'] === 'image') {
				$attachment_html = '<div class="lc-attachment lc-attachment-image">'
					. '<a href="' . esc_url($row['attachment_url']) . '" target="_blank" rel="noopener noreferrer" class="lc-img-preview">'
					. '<img src="' . esc_url($row['attachment_url']) . '" alt="' . esc_attr($row['attachment_name']) . '" loading="lazy">'
					. '</a></div>';
			} else {
				$size_html       = !empty($row['attachment_size']) ? '<span class="lc-file-size">' . esc_html(size_format((int) $row['attachment_size'])) . '</span>' : '';
				$attachment_html = '<div class="lc-attachment lc-attachment-file">'
					. '<a href="' . esc_url($row['attachment_url']) . '" target="_blank" rel="noopener noreferrer" download class="lc-file-link">'
					. '<span class="lc-file-icon">' . self::icon('file', 16) . '</span>'
					. '<span class="lc-file-meta"><span class="lc-file-name">' . esc_html($row['attachment_name'] ?: __('Attachment', 'live-chats')) . '</span>' . $size_html . '</span>'
					. '</a></div>';
			}
		}

		$is_operator_authored = ($style === 'admin') ? !empty($row['staff']) : !$row['mine'];
		$body_html            = $row['msg'] !== '' ? '<div class="lc-message-text">' . self::message_body_html($row['msg'], $is_operator_authored) . '</div>' : '';

		$output = '';
		if ($style === 'admin') {
			$output .= '<div class="lc-message' . $alt . $group . '" data-message-id="' . esc_attr($row['id']) . '"'
				. ' data-author="' . esc_attr($row['author']) . '" data-ts="' . esc_attr($row['ts']) . '"'
				. ' data-staff="' . ($row['staff'] ? '1' : '') . '" data-mine="' . ($own ? '1' : '') . '" title="' . esc_attr($row['time']) . '">';
			$output .= $ends_group
				? '<span class="lc-msg-avatar" style="background:' . esc_attr(self::avatar_color($row['author'])) . ';">' . esc_html(self::initial($row['author'])) . '</span>'
				: '<span class="lc-msg-avatar lc-avatar-spacer" aria-hidden="true"></span>';
			$output .= '<div class="lc-bubble-content">' . $body_html . $attachment_html . '</div></div>';
		} else {
			if (!$own) {
				$output .= '<div class="lc-msg-row-incoming">';
				$output .= $ends_group
					? '<span class="lc-msg-avatar" style="background:' . esc_attr(self::avatar_color($row['author'])) . ';">' . esc_html(self::initial($row['author'])) . '</span>'
					: '<span class="lc-msg-avatar lc-avatar-spacer" aria-hidden="true"></span>';
			}

			$output .= '<div class="lc-message' . $alt . $group . '" data-message-id="' . esc_attr($row['id']) . '"'
				. ' data-author="' . esc_attr($row['author']) . '" data-ts="' . esc_attr($row['ts']) . '"'
				. ' data-mine="' . ($own ? '1' : '') . '" title="' . esc_attr($row['time']) . '">'
				. $body_html . $attachment_html . '</div>';

			if (!$own) {
				$output .= '</div>';
			}
		}

		if ($ends_group) {
			$output .= '<p class="lc-msg-time' . $alt . '">' . esc_html($row['time']);
			if ($row['author'] !== '' && ($style === 'admin' || !$own)) {
				$output .= ' · ' . esc_html($row['author']);
			}
			$output .= '</p>';
		}

		return $output;
	}

	/**
	 * Render visitor message composer (or closed session banner).
	 */
	public static function guest_composer($conversation_id, $is_open) {
		if (!$is_open) {
			return '<div class="lc-session-ended"><p>' . esc_html__('This chat session has ended.', 'live-chats') . '</p>'
				. '<button type="button" class="lc-btn-primary lc-start-new-chat-btn">' . esc_html__('Start New Conversation', 'live-chats') . '</button></div>';
		}

		return '<form method="post" class="lc-composer-form" autocomplete="off">'
			. '<div class="lc-composer-preview" style="display:none;"></div>'
			. '<div class="lc-composer-inner">'
			. '<button type="button" class="lc-composer-btn lc-emoji-btn" title="' . esc_attr__('Add Emoji', 'live-chats') . '" aria-label="' . esc_attr__('Add Emoji', 'live-chats') . '">' . self::icon('smile', 18) . '</button>'
			. '<label class="lc-composer-btn lc-attach-btn" title="' . esc_attr__('Attach File', 'live-chats') . '" aria-label="' . esc_attr__('Attach File', 'live-chats') . '">'
			. '<input type="file" class="lc-file-input" style="display:none;" accept="image/*,.pdf,.doc,.docx,.txt">'
			. self::icon('paperclip', 18)
			. '</label>'
			. '<textarea name="msg" class="lc-msg-input" rows="1" placeholder="' . esc_attr__('Type your message…', 'live-chats') . '" maxlength="' . esc_attr(LIVE_CHATS_MAX_MESSAGE) . '"></textarea>'
			. '<input type="hidden" name="id" value="' . esc_attr($conversation_id) . '">'
			. '<button type="submit" class="lc-send-btn" aria-label="' . esc_attr__('Send message', 'live-chats') . '">' . self::icon('send', 18) . '</button>'
			. '</div>'
			. '<div class="lc-emoji-picker" style="display:none;"></div>'
			. '</form>';
	}

	/**
	 * Render visitor conversations list HTML.
	 */
	public static function guest_conversations($account_id) {
		$conversations = LiveChats_Engine::visitor_conversations($account_id);

		$output  = '<div class="lc-conversations-list">';
		$output .= '<button type="button" class="lc-new-conv-btn">+ ' . esc_html__('Start New Chat', 'live-chats') . '</button>';

		if (empty($conversations)) {
			$output .= '<div class="lc-empty-list"><p>' . esc_html__('No conversations yet. Click above to start chatting!', 'live-chats') . '</p></div>';
			$output .= '</div>';
			return $output;
		}

		$headed = array();
		foreach ($conversations as $conv) {
			$is_mine      = ((int) $conv['account_sender_id'] === (int) $account_id);
			$contact_name = $is_mine
				? LiveChats_Data::visitor_agent_name($conv['receiver_name'], (int) $conv['account_receiver_id'])
				: $conv['sender_name'];
			$unread       = (int) $conv['unread_count'];
			$heading      = ($conv['state'] === 'open')
				? __('Active Conversation', 'live-chats')
				: __('Past Conversations', 'live-chats');

			if (!isset($headed[$heading])) {
				$output          .= '<div class="lc-list-heading">' . esc_html($heading) . '</div>';
				$headed[$heading] = true;
			}

			$preview_text = $conv['msg'] !== '' && $conv['msg'] !== null
				? self::preview($conv['msg'])
				: (!empty($conv['attachment_name']) ? '📎 ' . $conv['attachment_name'] : __('No messages yet', 'live-chats'));

			$output .= '<a href="#" class="lc-conv-item' . ($unread ? ' is-unread' : '') . '" data-id="' . esc_attr($conv['id']) . '">';
			$output .= '<div class="lc-conv-avatar" style="background:' . esc_attr(self::avatar_color($contact_name)) . ';">' . esc_html(self::initial($contact_name)) . '</div>';
			$output .= '<div class="lc-conv-details">';
			$output .= '<div class="lc-conv-title">' . esc_html($contact_name) . '</div>';
			$output .= '<div class="lc-conv-msg">' . esc_html($preview_text) . '</div>';
			$output .= '</div>';
			$output .= '<div class="lc-conv-meta">';
			$output .= '<span class="lc-conv-date">' . esc_html(self::short_date($conv['msg_date'] ?: $conv['submit_date'])) . '</span>';
			if ($unread) {
				$output .= '<span class="lc-conv-unread">' . esc_html($unread > 99 ? '99+' : $unread) . '</span>';
			}
			$output .= '</div></a>';
		}

		$output .= '</div>';
		return $output;
	}

	/**
	 * Render operator sidebar conversation list HTML.
	 */
	public static function admin_conversations(array $account, $filter = 'all') {
		$operator_id   = (int) $account['id'];
		$conversations = LiveChats_Engine::operator_conversations($operator_id, LIVE_CHATS_LIST_LIMIT, $filter);
		$can_queue     = LiveChats_Auth::user_can('live_chats_reply');
		$can_assign    = LiveChats_Auth::user_can('live_chats_assign');

		$output = '<div class="lc-admin-conversations"><div class="lc-admin-scrollable">';

		if (empty($conversations)) {
			$empty_labels = array(
				'unread' => __('No unread conversations.', 'live-chats'),
				'mine'   => __('No active conversations assigned to you.', 'live-chats'),
				'queued' => __('No queued conversations waiting.', 'live-chats'),
				'closed' => __('No closed conversations.', 'live-chats'),
			);
			$label   = isset($empty_labels[$filter]) ? $empty_labels[$filter] : __('No conversations yet.', 'live-chats');
			$output .= '<div class="lc-no-conversations"><p>' . esc_html($label) . '</p></div></div></div>';
			return $output;
		}

		$buckets = array('unread' => '', 'mine' => '', 'queued' => '', 'others' => '', 'closed' => '');

		foreach ($conversations as $conv) {
			$is_mine      = ((int) $conv['account_sender_id'] === $operator_id);
			$contact_name = $is_mine ? $conv['receiver_name'] : $conv['sender_name'];
			$country      = $is_mine ? $conv['receiver_country'] : $conv['sender_country'];
			$last_seen    = $is_mine ? $conv['receiver_last_seen'] : $conv['sender_last_seen'];
			$is_online    = !empty($last_seen) && (time() - (int) mysql2date('U', $last_seen)) <= (3 * MINUTE_IN_SECONDS);
			$unread       = (int) $conv['unread_count'];

			$is_closed   = ($conv['state'] !== 'open');
			$assigned_id = (int) $conv['assigned_id'];
			$is_joined   = ((int) $conv['is_available'] === 3);
			$mine_now    = ($assigned_id === $operator_id || (int) $conv['is_available'] === 0 || $is_joined);
			$is_queued   = (!$assigned_id && !$is_closed);

			$custom_fields = json_decode((string) $conv['custom_fields'], true);
			$tags          = (is_array($custom_fields) && !empty($custom_fields['tags'])) ? (array) $custom_fields['tags'] : array();
			$tags_attr     = esc_attr(implode(' ', array_map('strtolower', $tags)));

			$item  = '<div class="lc-admin-conv-item' . ($unread ? ' is-unread' : '') . ($is_closed ? ' is-closed' : '') . '"'
				. ' data-id="' . esc_attr($conv['id']) . '"'
				. ' data-state="' . esc_attr($conv['state']) . '"'
				. ' data-is-closed="' . ($is_closed ? '1' : '0') . '"'
				. ' data-mine="' . ($mine_now ? '1' : '0') . '"'
				. ' data-queued="' . ($is_queued ? '1' : '0') . '"'
				. ' data-unread="' . esc_attr($unread) . '"'
				. ' data-tags="' . $tags_attr . '"'
				. ' data-name="' . esc_attr($contact_name) . '">';
			$item .= '<div class="lc-admin-conv-avatar" style="background:' . esc_attr(self::avatar_color($contact_name)) . ';">'
				. esc_html(self::initial($contact_name))
				. '<span class="lc-online-dot ' . ($is_online ? 'is-online' : 'is-offline') . '" title="' . esc_attr($is_online ? __('Online', 'live-chats') : __('Offline', 'live-chats')) . '"></span>'
				. '</div>';
			$item .= '<div class="lc-admin-conv-body">';
			$item .= '<div class="lc-admin-conv-top"><span class="lc-contact-name">' . esc_html($contact_name) . '</span><span class="lc-chat-ref">#' . esc_html($conv['id']) . '</span></div>';

			$prefix = (!empty($conv['msg_type']) && $conv['msg_type'] === 'note') ? '[Note] ' : '';
			$msg_tx = ($conv['msg'] !== '' && $conv['msg'] !== null)
				? $conv['msg']
				: (!empty($conv['attachment_name']) ? '📎 ' . $conv['attachment_name'] : __('New conversation', 'live-chats'));
			$item .= '<div class="lc-admin-conv-preview">' . esc_html($prefix . self::preview($msg_tx, 52)) . '</div>';

			$badges = array();
			if ($is_closed) {
				$badges[] = '<span class="lc-row-badge lc-badge-closed">' . esc_html__('Closed', 'live-chats') . '</span>';
			} elseif ($is_queued) {
				$badges[] = '<span class="lc-row-badge lc-badge-queued">' . esc_html__('Queued', 'live-chats') . '</span>';
			} elseif ($assigned_id) {
				$badges[] = $mine_now
					? '<span class="lc-row-badge lc-badge-mine">' . esc_html__('You', 'live-chats') . '</span>'
					/* translators: %s: assigned agent name */
					: '<span class="lc-row-badge">' . esc_html(sprintf(__('with %s', 'live-chats'), $conv['assigned_name'])) . '</span>';
			}
			if ($country !== '') {
				$badges[] = '<span class="lc-row-badge lc-badge-country">' . esc_html(strtoupper($country)) . '</span>';
			}
			foreach (array_slice($tags, 0, 2) as $t) {
				$badges[] = '<span class="lc-row-badge lc-badge-tag">' . esc_html($t) . '</span>';
			}
			if ($badges) {
				$item .= '<div class="lc-row-meta">' . implode('', $badges) . '</div>';
			}

			$item .= '</div>';
			$item .= '<div class="lc-admin-conv-right">';
			$item .= '<span class="lc-admin-conv-date">' . esc_html(self::short_date($conv['msg_date'] ?: $conv['submit_date'])) . '</span>';
			if ($unread) {
				$item .= '<span class="lc-admin-unread-pill">' . esc_html($unread > 99 ? '99+' : $unread) . '</span>';
			}
			$item .= '</div></div>';

			if ($filter === 'unread') {
				$buckets['unread'] .= $item;
			} elseif ($is_closed) {
				$buckets['closed'] .= $item;
			} elseif ($mine_now) {
				$buckets['mine'] .= $item;
			} elseif ($is_queued) {
				$buckets['queued'] .= $item;
			} elseif ($can_assign) {
				$buckets['others'] .= $item;
			}
		}

		if ($filter === 'unread' && $buckets['unread'] !== '') {
			$output .= '<div class="lc-section-heading lc-heading-unread">' . self::icon('bell', 13) . ' ' . esc_html__('Unread Messages', 'live-chats') . '</div>' . $buckets['unread'];
		} elseif ($filter === 'mine' && $buckets['mine'] !== '') {
			$output .= '<div class="lc-section-heading">' . self::icon('comments', 13) . ' ' . esc_html__('My Active Chats', 'live-chats') . '</div>' . $buckets['mine'];
		} elseif ($filter === 'queued' && $buckets['queued'] !== '') {
			$output .= '<div class="lc-section-heading lc-heading-queued">' . self::icon('clock', 13) . ' ' . esc_html__('Queued / Unassigned', 'live-chats') . '</div>' . $buckets['queued'];
		} elseif ($filter === 'closed' && $buckets['closed'] !== '') {
			$output .= '<div class="lc-section-heading">' . self::icon('check-double', 13) . ' ' . esc_html__('Closed Conversations', 'live-chats') . '</div>' . $buckets['closed'];
		} else {
			if ($buckets['mine'] !== '') {
				$output .= '<div class="lc-section-heading">' . self::icon('comments', 13) . ' ' . esc_html__('My Active Chats', 'live-chats') . '</div>' . $buckets['mine'];
			}
			if ($buckets['queued'] !== '' && $can_queue) {
				$output .= '<div class="lc-section-heading lc-heading-queued">' . self::icon('clock', 13) . ' ' . esc_html__('Queued / Unassigned', 'live-chats') . '</div>' . $buckets['queued'];
			}
			if ($buckets['others'] !== '') {
				$output .= '<div class="lc-section-heading">' . self::icon('user', 13) . ' ' . esc_html__('Other Agents', 'live-chats') . '</div>' . $buckets['others'];
			}
			if ($buckets['closed'] !== '') {
				$output .= '<div class="lc-section-heading">' . self::icon('check-double', 13) . ' ' . esc_html__('Closed Conversations', 'live-chats') . '</div>' . $buckets['closed'];
			}
		}

		$output .= '</div></div>';
		return $output;
	}

	/**
	 * Render operator center thread + right Customer 360 panel.
	 */
	public static function admin_conversation($conversation, $messages, array $names, array $account, array $assignments, array $operator_ids, $is_available, $read_through = 0) {
		$operator_id  = (int) $account['id'];
		$cid          = (int) $conversation->id;
		$perms        = array(
			'claim'  => LiveChats_Auth::operator_can('claim', $cid, $account),
			'reply'  => LiveChats_Auth::operator_can('reply', $cid, $account),
			'note'   => LiveChats_Auth::operator_can('note', $cid, $account),
			'manage' => LiveChats_Auth::operator_can('manage', $cid, $account),
			'close'  => LiveChats_Auth::operator_can('close', $cid, $account),
			'delete' => LiveChats_Auth::operator_can('delete', $cid, $account),
		);
		$chat_team    = LiveChats_Engine::conversation_team($cid);
		$is_sender    = ($operator_id === (int) $conversation->account_sender_id);
		$contact_name = $is_sender ? $conversation->receiver_name : $conversation->sender_name;
		$contact_ctry = $is_sender ? $conversation->receiver_country : $conversation->sender_country;
		$last_seen    = $is_sender ? $conversation->receiver_last_seen : $conversation->sender_last_seen;
		$is_online    = !empty($last_seen) && (time() - (int) mysql2date('U', $last_seen)) <= (3 * MINUTE_IN_SECONDS);

		$output = '<div class="lc-thread-columns"><div class="lc-thread-main">';

		// Thread header bar.
		$output .= '<div class="lc-thread-header">';
		$output .= '<div class="lc-thread-user">';
		$output .= '<div class="lc-thread-avatar" style="background:' . esc_attr(self::avatar_color($contact_name)) . ';">' . esc_html(self::initial($contact_name)) . '</div>';
		$output .= '<div class="lc-thread-meta">';
		$output .= '<span class="lc-thread-name">' . esc_html($contact_name) . '</span>';
		$output .= '<span class="lc-state-badge lc-state-' . esc_attr($is_available === 1 && $conversation->state === 'open' ? 'queued' : $conversation->state) . '">'
			. esc_html($is_available === 1 && $conversation->state === 'open' ? __('Queued', 'live-chats') : ucfirst($conversation->state)) . '</span>';
		$output .= '<span class="lc-visitor-presence ' . ($is_online ? 'is-online' : 'is-offline') . '" id="lc-visitor-presence">'
			. esc_html($is_online ? __('Visitor Online', 'live-chats') : __('Visitor Offline', 'live-chats')) . '</span>';
		if ($chat_team) {
			$agent_labels = array();
			foreach ($chat_team as $member) {
				$lbl            = ((int) $member['id'] === $operator_id) ? __('You', 'live-chats') : $member['full_name'];
				$agent_labels[] = ((int) $member['state'] === 0) ? '<strong>' . esc_html($lbl) . '</strong>' : esc_html($lbl);
			}
			$output .= '<span class="lc-chat-agents">' . self::icon('user', 12) . ' ' . implode(', ', $agent_labels) . '</span>';
		}
		$output .= '</div></div>';

		$output .= '<div class="lc-thread-actions">';
		$output .= '<button type="button" class="lc-hdr-btn lc-toggle-details-btn active" title="' . esc_attr__('Toggle Customer Details', 'live-chats') . '">' . self::icon('user', 14) . ' <span>' . esc_html__('Details', 'live-chats') . '</span></button>';
		if ($perms['manage']) {
			$output .= '<button type="button" class="lc-hdr-btn lc-email-transcript-btn" title="' . esc_attr__('Email Transcript to Visitor', 'live-chats') . '" data-id="' . esc_attr($cid) . '">' . self::icon('mail', 15) . '</button>';
		}
		if ($perms['close']) {
			$output .= '<button type="button" class="lc-hdr-btn lc-hdr-danger lc-close-conv-btn" title="' . esc_attr__('End Conversation', 'live-chats') . '" data-id="' . esc_attr($cid) . '">' . self::icon('times', 14) . ' <span>' . esc_html__('End Chat', 'live-chats') . '</span></button>';
		}
		$output .= '</div></div>';

		// If unclaimed & open, show claim banner while still allowing preview of visitor's messages!
		if ((int) $is_available === 1 && $conversation->state === 'open') {
			$output .= '<div class="lc-claim-banner">';
			$output .= '<div class="lc-claim-info"><strong>' . esc_html__('Queued Conversation', 'live-chats') . '</strong> — '
				. esc_html__('Accept this conversation to claim ownership and reply to the visitor.', 'live-chats') . '</div>';
			if ($perms['claim']) {
				$output .= '<button type="button" class="lc-btn-primary lc-claim-self-btn" data-conversation-id="' . esc_attr($cid) . '">' . esc_html__('Accept & Chat', 'live-chats') . '</button>';
			}
			$output .= '</div>';
		}

		// Live sneak-peek bar.
		$output .= '<div class="lc-sneak-peek" id="lc-sneak-peek" style="display:none;">'
			. '<span class="lc-sneak-icon">' . self::icon('eye', 14) . '</span>'
			. '<span class="lc-sneak-label">' . esc_html__('Visitor is typing:', 'live-chats') . '</span> '
			. '<span class="lc-sneak-text" id="lc-sneak-text"></span>'
			. '</div>';

		// Message thread.
		$output .= self::messages($messages, $names, $operator_id, $operator_ids, 'admin', $read_through);

		// Smart suggestions strip.
		if ($conversation->state === 'open' && $perms['reply']) {
			$output .= '<div class="lc-smart-suggestions" id="lc-smart-suggestions" data-id="' . esc_attr($cid) . '" style="display:none;">'
				. '<div class="lc-smart-hdr">' . self::icon('sparkles', 13) . ' <span>' . esc_html__('Quick Replies', 'live-chats') . '</span></div>'
				. '<div class="lc-suggestion-chips" id="lc-suggestion-chips"></div>'
				. '</div>';
		}

		// Composer.
		$composer_mode = $perms['reply'] ? 'both' : ($perms['note'] ? 'note' : 'none');
		$output       .= self::admin_composer($cid, $conversation->state === 'open', $composer_mode);
		$output       .= '</div>';

		// Right pane: Customer 360 details.
		$output .= self::profile_panel($conversation, $is_sender, $perms, $contact_ctry);
		$output .= '</div>';

		return $output;
	}

	/**
	 * Render operator reply / private note composer.
	 */
	public static function admin_composer($conversation_id, $is_open, $mode = 'both') {
		if (!$is_open) {
			return '<div class="lc-session-ended"><p>' . esc_html__('This conversation is closed.', 'live-chats') . '</p></div>';
		}
		if ($mode === 'none') {
			return '<div class="lc-session-ended"><p>' . esc_html__('Read-only view.', 'live-chats') . '</p></div>';
		}

		$note_only = ($mode === 'note');
		$output    = '<div class="lc-admin-composer-wrap">';
		$output   .= '<div class="lc-composer-tabs">';
		if (!$note_only) {
			$output .= '<button type="button" class="lc-composer-tab active" data-mode="message">' . self::icon('send', 13) . ' ' . esc_html__('Public Reply', 'live-chats') . '</button>';
		}
		$output .= '<button type="button" class="lc-composer-tab lc-tab-note' . ($note_only ? ' active' : '') . '" data-mode="note">' . self::icon('lock', 13) . ' ' . esc_html__('Private Note (Staff Only)', 'live-chats') . '</button>';
		$output .= '</div>';

		$output .= '<form method="post" class="lc-composer-form lc-admin-composer-form" autocomplete="off">';
		$output .= '<div class="lc-composer-preview" style="display:none;"></div>';
		$output .= '<div class="lc-composer-inner">';
		$output .= '<button type="button" class="lc-composer-btn lc-emoji-btn" title="' . esc_attr__('Add Emoji', 'live-chats') . '">' . self::icon('smile', 18) . '</button>';
		$output .= '<label class="lc-composer-btn lc-attach-btn" title="' . esc_attr__('Attach File', 'live-chats') . '">';
		$output .= '<input type="file" class="lc-file-input" style="display:none;" accept="image/*,.pdf,.doc,.docx,.txt">';
		$output .= self::icon('paperclip', 18) . '</label>';
		$output .= '<textarea name="msg" id="lc-admin-composer-input" class="lc-msg-input" rows="1" placeholder="' . esc_attr__('Type a reply (type / for canned responses)…', 'live-chats') . '" maxlength="' . esc_attr(LIVE_CHATS_MAX_MESSAGE) . '"></textarea>';
		$output .= '<input type="hidden" name="id" value="' . esc_attr($conversation_id) . '">';
		$output .= '<input type="hidden" name="msg_type" id="lc-composer-msg-type" value="' . ($note_only ? 'note' : 'message') . '">';
		$output .= '<button type="submit" class="lc-send-btn" aria-label="' . esc_attr__('Send', 'live-chats') . '">' . self::icon('send', 18) . '</button>';
		$output .= '</div>';
		$output .= '<div class="lc-emoji-picker" style="display:none;"></div>';
		$output .= '</form></div>';

		return $output;
	}

	/**
	 * Render Customer 360 profile & details sidebar panel.
	 */
	public static function profile_panel($conversation, $is_sender, array $perms, $country) {
		$name        = $is_sender ? $conversation->receiver_name : $conversation->sender_name;
		$email       = $is_sender ? $conversation->receiver_email : $conversation->sender_email;
		$ip          = $is_sender ? $conversation->receiver_ip : $conversation->sender_ip;
		$wp_user_id  = $is_sender ? (int) ($conversation->receiver_wp_user_id ?? 0) : (int) ($conversation->sender_wp_user_id ?? 0);
		$rating      = isset($conversation->rating) ? (int) $conversation->rating : 0;
		$feedback    = isset($conversation->feedback) ? (string) $conversation->feedback : '';
		$total_chats = isset($conversation->total_chats) ? (int) $conversation->total_chats : 1;

		$custom_fields = json_decode((string) $conversation->custom_fields, true);
		$context       = (is_array($custom_fields) && !empty($custom_fields['context'])) ? (array) $custom_fields['context'] : array();
		$tags          = (is_array($custom_fields) && !empty($custom_fields['tags'])) ? (array) $custom_fields['tags'] : array();
		$std_tags      = LiveChats_Data::standard_tags();

		$output  = '<aside class="lc-profile-panel">';
		$output .= '<div class="lc-profile-hdr"><span>' . esc_html__('Customer Details', 'live-chats') . '</span>'
			. '<button type="button" class="lc-profile-close-btn" aria-label="' . esc_attr__('Hide details', 'live-chats') . '">&times;</button></div>';

		$output .= '<div class="lc-profile-top">';
		$output .= '<div class="lc-profile-avatar" style="background:' . esc_attr(self::avatar_color($name)) . ';">' . esc_html(self::initial($name)) . '</div>';
		$output .= '<div class="lc-profile-ident"><strong>' . esc_html($name) . '</strong><span>#' . esc_html($conversation->id) . ' · ' . ($wp_user_id > 0 ? esc_html__('WP User', 'live-chats') : esc_html__('Guest', 'live-chats')) . '</span></div>';
		if (!empty($perms['delete'])) {
			$output .= '<button type="button" class="lc-delete-chat-btn" data-id="' . esc_attr($conversation->id) . '" title="' . esc_attr__('Delete conversation', 'live-chats') . '">' . self::icon('trash', 14) . '</button>';
		}
		$output .= '</div>';

		if (!empty($perms['delete'])) {
			$output .= '<div id="lc-delete-confirm-modal" class="lc-confirm-modal" hidden><div class="lc-confirm-box">'
				. '<p>' . esc_html__('Permanently delete this conversation and all messages?', 'live-chats') . '</p>'
				. '<div class="lc-confirm-actions">'
				. '<button type="button" id="lc-confirm-delete-yes" class="lc-btn-danger">' . esc_html__('Delete', 'live-chats') . '</button>'
				. '<button type="button" id="lc-confirm-delete-no" class="lc-btn-secondary">' . esc_html__('Cancel', 'live-chats') . '</button>'
				. '</div><div id="lc-delete-status" role="status"></div></div></div>';
		}

		$output .= '<div class="lc-profile-body">';
		$output .= '<div class="lc-profile-sec-title">' . esc_html__('Visitor Info', 'live-chats') . '</div>';
		$output .= '<div class="lc-kv"><span class="lc-kv-label">' . esc_html__('Email', 'live-chats') . '</span><span class="lc-kv-val">' . ($email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '—') . '</span></div>';

		if ($wp_user_id > 0) {
			$edit_url = get_edit_user_link($wp_user_id);
			$output  .= '<div class="lc-kv"><span class="lc-kv-label">' . esc_html__('WP Account', 'live-chats') . '</span><span class="lc-kv-val">'
				. ($edit_url ? '<a href="' . esc_url($edit_url) . '" target="_blank" rel="noopener noreferrer">#' . esc_html($wp_user_id) . '</a>' : '#' . esc_html($wp_user_id))
				. '</span></div>';
		}

		$output .= '<div class="lc-kv"><span class="lc-kv-label">' . esc_html__('IP Address', 'live-chats') . '</span><span class="lc-kv-val">' . esc_html($ip ?: '—') . '</span></div>';
		$output .= '<div class="lc-kv"><span class="lc-kv-label">' . esc_html__('Location', 'live-chats') . '</span><span class="lc-kv-val">' . self::country_badge($country) . '</span></div>';

		if (!empty($conversation->submit_date)) {
			$started = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($conversation->submit_date));
			$output .= '<div class="lc-kv"><span class="lc-kv-label">' . esc_html__('Started', 'live-chats') . '</span><span class="lc-kv-val">' . esc_html($started) . '</span></div>';
		}

		/* translators: %d: conversation count */
		$history = sprintf(_n('%d conversation', '%d conversations', $total_chats, 'live-chats'), $total_chats);
		$output .= '<div class="lc-kv"><span class="lc-kv-label">' . esc_html__('History', 'live-chats') . '</span><span class="lc-kv-val">' . esc_html($history) . '</span></div>';

		if (!empty($context)) {
			$output .= '<div class="lc-profile-sec-title">' . esc_html__('Browsing Context', 'live-chats') . '</div>';
			if (!empty($context['title']) || !empty($context['url'])) {
				$ptitle  = !empty($context['title']) ? $context['title'] : $context['url'];
				$purl    = !empty($context['url']) ? $context['url'] : '#';
				$output .= '<div class="lc-kv"><span class="lc-kv-label">' . esc_html__('Page', 'live-chats') . '</span><span class="lc-kv-val"><a href="' . esc_url($purl) . '" target="_blank" rel="noopener noreferrer">' . esc_html(self::preview($ptitle, 34)) . '</a></span></div>';
			}
			if (!empty($context['referrer'])) {
				$output .= '<div class="lc-kv"><span class="lc-kv-label">' . esc_html__('Referrer', 'live-chats') . '</span><span class="lc-kv-val"><a href="' . esc_url($context['referrer']) . '" target="_blank" rel="noopener noreferrer">' . esc_html(self::preview($context['referrer'], 34)) . '</a></span></div>';
			}
			if (!empty($context['browser']) || !empty($context['os'])) {
				$dev     = trim(($context['browser'] ?? '') . ' / ' . ($context['os'] ?? '') . ' (' . ($context['device'] ?? '') . ')');
				$output .= '<div class="lc-kv"><span class="lc-kv-label">' . esc_html__('Device', 'live-chats') . '</span><span class="lc-kv-val">' . esc_html($dev) . '</span></div>';
			}
		}

		$output .= '<div class="lc-profile-sec-title">' . esc_html__('Tags', 'live-chats') . '</div>';
		$output .= '<div class="lc-tags-wrap" id="lc-tags-container" data-id="' . esc_attr($conversation->id) . '">';
		$output .= '<div class="lc-tags-list" id="lc-tags-list">';
		foreach ($tags as $tag) {
			$color   = isset($std_tags[$tag]) ? $std_tags[$tag] : '#6b7280';
			$output .= '<span class="lc-tag-pill" style="border-left:3px solid ' . esc_attr($color) . ';" data-tag="' . esc_attr($tag) . '">'
				. esc_html($tag)
				. (!empty($perms['manage']) ? '<button type="button" class="lc-tag-remove" aria-label="' . esc_attr__('Remove tag', 'live-chats') . '">&times;</button>' : '')
				. '</span>';
		}
		$output .= '</div>';

		if (!empty($perms['manage'])) {
			$output .= '<div class="lc-quick-tags">';
			foreach ($std_tags as $stag => $scolor) {
				if (!in_array($stag, $tags, true)) {
					$output .= '<button type="button" class="lc-quick-tag-btn" data-tag="' . esc_attr($stag) . '" style="border-color:' . esc_attr($scolor) . ';color:' . esc_attr($scolor) . ';">+' . esc_html($stag) . '</button>';
				}
			}
			$output .= '</div>';
			$output .= '<div class="lc-tag-add-row"><input type="text" id="lc-new-tag-input" placeholder="' . esc_attr__('Add tag…', 'live-chats') . '" maxlength="30">'
				. '<button type="button" id="lc-add-tag-btn" class="lc-btn-small">' . esc_html__('Add', 'live-chats') . '</button></div>';
		}
		$output .= '</div>';

		if ($rating > 0) {
			$output .= '<div class="lc-profile-sec-title">' . esc_html__('Customer Rating', 'live-chats') . '</div>';
			$output .= '<div class="lc-rating-summary">' . str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) . ' (' . $rating . '/5)';
			if ($feedback !== '') {
				$output .= '<p class="lc-rating-quote">"' . esc_html($feedback) . '"</p>';
			}
			$output .= '</div>';
		}

		$output .= '<div class="lc-profile-actions">';
		if (!empty($perms['manage'])) {
			$output .= '<button type="button" class="lc-btn-secondary lc-email-transcript-btn" data-id="' . esc_attr($conversation->id) . '">' . esc_html__('Email Transcript', 'live-chats') . '</button>';
		}
		if (!empty($perms['close'])) {
			$output .= '<button type="button" class="lc-btn-danger lc-close-conv-btn" data-id="' . esc_attr($conversation->id) . '">' . esc_html__('End Conversation', 'live-chats') . '</button>';
		}
		$output .= '</div></div></aside>';

		return $output;
	}
}
