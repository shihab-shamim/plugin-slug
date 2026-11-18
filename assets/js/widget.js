/**
 * Live Chats — Visitor Floating Widget Controller
 */
(function ($) {
	'use strict';

	if (typeof window.liveChatsConfig === 'undefined') {
		return;
	}

	var cfg = window.liveChatsConfig;
	var EMOJIS = ['👋', '😊', '👍', '❤️', '🔥', '🎉', '🙌', '💡', '✨', '🚀', '💯', '🙏', '😃', '🤔', '😍', '👏', '🎯', '⭐', '🤝', '😎', '💬', '📞', '📧', '💼'];
	var TOUCH = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

	var state = {
		currentTab: 'home',
		conversationId: 0,
		lastMessageId: 0,
		fingerprint: '',
		lastRenderedDate: '',
		isTyping: false,
		currentDraft: '',
		typingTimer: null,
		inFlight: false,
		timer: null,
		started: false,
		soundEnabled: true,
		selectedRating: 0,
		pendingAttachment: null,
		forceNew: false,
		pendingSeq: 0,
		retryQueue: {},
		unreadTitleCount: 0,
		originalTitle: document.title,
		drafts: {},
		backoffMs: 2000,
		submittingPrechat: false
	};

	try {
		if (localStorage.getItem('lc_visitor_sound') === '0') {
			state.soundEnabled = false;
		}
	} catch (e) {}

	/* -----------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------- */

	function escHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function linkify(text, markdown) {
		var html = escHtml(text);
		if (markdown) {
			html = html
				.replace(/`([^`\n]+)`/g, '<code>$1</code>')
				.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
		}
		return html
			.replace(/(https?:\/\/[^\s<"']+[^\s<"'.,:;!?)\]}])/g, function (url) {
				return '<a href="' + url + '" target="_blank" rel="noopener noreferrer nofollow">' + url + '</a>';
			})
			.replace(/\n/g, '<br>');
	}

	function initial(name) {
		return name ? String(name).trim().charAt(0).toUpperCase() : '?';
	}

	function formatFileSize(bytes) {
		bytes = parseInt(bytes, 10) || 0;
		var units = ['B', 'KB', 'MB', 'GB'];
		var i = 0;
		while (bytes >= 1024 && i < units.length - 1) {
			bytes /= 1024;
			i++;
		}
		return (i === 0 ? bytes : bytes.toFixed(bytes < 10 ? 1 : 0)) + ' ' + units[i];
	}

	function todayStamp() {
		var d = new Date();
		return d.getFullYear() + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + ('0' + d.getDate()).slice(-2);
	}

	function dateLabel(stamp) {
		var parts = String(stamp || '').split('/');
		if (parts.length !== 3) {
			return stamp || '';
		}
		var day = new Date(+parts[0], +parts[1] - 1, +parts[2]);
		var today = new Date();
		today.setHours(0, 0, 0, 0);
		var diff = Math.round((today - day) / 86400000);
		if (diff === 0) return 'Today';
		if (diff === 1) return 'Yesterday';
		return day.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function getVisitorContext() {
		var ua = navigator.userAgent || '';
		var browser = 'Browser';
		if (ua.indexOf('Edg/') > -1 || ua.indexOf('Edge') > -1) browser = 'Edge';
		else if (ua.indexOf('Firefox') > -1) browser = 'Firefox';
		else if (ua.indexOf('Chrome') > -1) browser = 'Chrome';
		else if (ua.indexOf('Safari') > -1) browser = 'Safari';

		var os = 'OS';
		if (ua.indexOf('Win') > -1) os = 'Windows';
		else if (ua.indexOf('Mac') > -1) os = 'macOS';
		else if (ua.indexOf('Android') > -1) os = 'Android';
		else if (ua.indexOf('iPhone') > -1 || ua.indexOf('iPad') > -1) os = 'iOS';
		else if (ua.indexOf('Linux') > -1) os = 'Linux';

		var timezone = '';
		try {
			timezone = (window.Intl && Intl.DateTimeFormat().resolvedOptions().timeZone) || '';
		} catch (e) {}

		return {
			url: window.location.href,
			title: document.title || '',
			referrer: document.referrer || '',
			browser: browser,
			os: os,
			device: /Mobi|Android|iPhone|iPad/i.test(ua) ? 'Mobile' : 'Desktop',
			locale: navigator.language || '',
			timezone: timezone
		};
	}

	function post(action, data) {
		return $.ajax({
			url: cfg.ajaxUrl,
			method: 'POST',
			cache: false,
			data: $.extend({ action: action, live_chats_nonce: cfg.nonce }, data || {})
		});
	}

	function errorText(response, fallback) {
		if (response && typeof response === 'object' && response.data) {
			if (typeof response.data === 'string') return response.data;
			if (response.data.message) return response.data.message;
		}
		return fallback;
	}

	function failText(xhr, fallback) {
		return errorText(xhr && xhr.responseJSON, fallback);
	}

	/* -----------------------------------------------------------------------
	 * Sound & Notifications
	 * -------------------------------------------------------------------- */

	var audioCtx = null;
	function playChime() {
		if (!state.soundEnabled) return;
		try {
			var AudioContext = window.AudioContext || window.webkitAudioContext;
			if (!AudioContext) return;
			if (!audioCtx) audioCtx = new AudioContext();
			if (audioCtx.state === 'suspended') audioCtx.resume();

			var now = audioCtx.currentTime;
			var osc = audioCtx.createOscillator();
			var gain = audioCtx.createGain();
			osc.type = 'sine';
			osc.frequency.setValueAtTime(587.33, now);
			osc.frequency.exponentialRampToValueAtTime(880, now + 0.12);
			gain.gain.setValueAtTime(0.14, now);
			gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
			osc.connect(gain);
			gain.connect(audioCtx.destination);
			osc.start(now);
			osc.stop(now + 0.35);
		} catch (e) {}
	}

	function requestNotificationPermission() {
		if ('Notification' in window && Notification.permission === 'default') {
			try { Notification.requestPermission(); } catch (e) {}
		}
	}

	function notifyDesktop(title, body) {
		if ('Notification' in window && Notification.permission === 'granted') {
			try { new Notification(title, { body: body || '' }); } catch (e) {}
		}
	}

	function flashTitle() {
		state.unreadTitleCount++;
		document.title = '(' + state.unreadTitleCount + ') New message · ' + state.originalTitle;
	}

	function resetTitle() {
		if (state.unreadTitleCount > 0) {
			state.unreadTitleCount = 0;
			document.title = state.originalTitle;
		}
	}

	/* -----------------------------------------------------------------------
	 * DOM Helpers
	 * -------------------------------------------------------------------- */

	function messagesContainer() {
		return $('#live-chats-widget .lc-thread-container .lc-messages');
	}

	function isPinnedToBottom(container) {
		if (!container.length) return true;
		var n = container[0];
		return n.scrollHeight - n.scrollTop - n.clientHeight < 64;
	}

	function scrollToBottom(container) {
		if (container && container.length) {
			container.scrollTop(container[0].scrollHeight);
		}
	}

	function autogrow(el) {
		if (!el) return;
		el.style.height = 'auto';
		el.style.height = Math.min(el.scrollHeight, 120) + 'px';
		el.style.overflowY = el.scrollHeight > 120 ? 'auto' : 'hidden';
	}

	function resetComposer(el) {
		if (!el) return;
		el.value = '';
		el.style.height = 'auto';
		el.style.overflowY = 'hidden';
	}

	function updateUnreadBadges(count) {
		count = parseInt(count, 10) || 0;
		var label = count > 99 ? '99+' : String(count);
		var $launcherBadge = $('#live-chats-widget .lc-unread-badge');
		var $navBadge = $('#live-chats-widget .lc-nav-badge');
		var isOpen = $('#live-chats-widget .lc-widget-window').hasClass('open');

		if (count > 0) {
			$navBadge.text(label).show();
			if (!isOpen) {
				$launcherBadge.text(label).show();
			} else {
				$launcherBadge.hide();
			}
		} else {
			$launcherBadge.hide();
			$navBadge.hide();
		}
	}

	function updatePresence(isOnline) {
		var $dot = $('#live-chats-widget .lc-status-dot');
		var $txt = $('#lc-presence-text');
		if (isOnline) {
			$dot.removeClass('offline').addClass('online');
			$txt.text('Online • Typically replies in minutes');
		} else {
			$dot.removeClass('online').addClass('offline');
			$txt.text('Away • Leave a message');
		}
	}

	function setTypingDisplay(isTyping, name) {
		var container = messagesContainer();
		if (!container.length) return;
		var el = container.find('.lc-typing-indicator');
		if (!el.length) {
			el = $('<div>', { 'class': 'lc-typing-indicator' })
				.append($('<span>', { 'class': 'lc-typing-label' }))
				.append($('<span>', { 'class': 'lc-typing-dots' })
					.append('<span class="lc-typing-dot"></span><span class="lc-typing-dot"></span><span class="lc-typing-dot"></span>'))
				.appendTo(container);
		}
		if (isTyping) {
			el.find('.lc-typing-label').text((name || 'Support') + ' is typing');
			el.css('display', 'inline-flex');
			scrollToBottom(container);
		} else {
			el.hide();
		}
	}

	function updateSeenReceipt(readThrough) {
		var container = messagesContainer();
		if (!container.length) return;
		container.find('.lc-seen-receipt').remove();

		readThrough = parseInt(readThrough, 10) || 0;
		if (!readThrough) return;

		var lastMine = container.find('.lc-message[data-mine="1"]').last();
		var lastId = lastMine.length ? parseInt(lastMine.attr('data-message-id'), 10) : 0;
		if (lastId > 0 && lastId <= readThrough) {
			var anchor = lastMine.nextAll('.lc-msg-time').first();
			(anchor.length ? anchor : lastMine).after($('<p>', { 'class': 'lc-seen-receipt' }).text('Seen'));
		}
	}

	function lastBubble(container) {
		return container.children('.lc-message, .lc-msg-row-incoming').last();
	}

	function continuesRun(container, row) {
		var last = lastBubble(container);
		if (!last.length || last.nextAll('.lc-date-divider').length) return false;
		var bubble = last.hasClass('lc-msg-row-incoming') ? last.find('.lc-message') : last;
		if (!bubble.length) return false;
		if ((bubble.attr('data-author') || '') !== (row.author || '')) return false;
		if (!!bubble.attr('data-mine') !== !!row.mine) return false;
		return Math.abs((row.ts || 0) - (parseInt(bubble.attr('data-ts'), 10) || 0)) <= 300;
	}

	function appendMessage(row) {
		var container = messagesContainer();
		if (!container.length) return;

		// Deduplicate server messages.
		if (row.id && container.find('.lc-message[data-message-id="' + row.id + '"]').length) {
			return;
		}

		container.find('.lc-empty-thread').remove();
		container.find('.lc-typing-indicator').remove();

		if (row.date && row.date !== state.lastRenderedDate) {
			$('<p>', { 'class': 'lc-date-divider', 'data-date': row.date })
				.text(dateLabel(row.date))
				.appendTo(container);
			state.lastRenderedDate = row.date;
		}

		var extending = continuesRun(container, row);
		if (extending) {
			var prev = lastBubble(container);
			container.children('.lc-msg-time').last().remove();
			prev.find('.lc-msg-avatar').addClass('lc-avatar-spacer').text('');
			(prev.hasClass('lc-msg-row-incoming') ? prev.find('.lc-message') : prev).removeClass('lc-group-end');
		}

		var bubble = $('<div>', {
			'class': 'lc-message' + (row.mine ? '' : ' alt') + (extending ? '' : ' lc-group-start') + ' lc-group-end',
			title: row.time || ''
		})
			.attr('data-message-id', row.id)
			.attr('data-author', row.author || '')
			.attr('data-ts', row.ts || 0)
			.attr('data-mine', row.mine ? '1' : '');

		if (row.msg) {
			bubble.append($('<div>', { 'class': 'lc-message-text' }).html(linkify(row.msg, !row.mine)));
		}

		if (row.attachment_url) {
			if (row.attachment_type === 'image') {
				var imgWrap = $('<div>', { 'class': 'lc-attachment lc-attachment-image' });
				$('<a>', { href: row.attachment_url, target: '_blank', rel: 'noopener noreferrer', 'class': 'lc-img-preview' })
					.append($('<img>', { src: row.attachment_url, alt: row.attachment_name || 'Image' }))
					.appendTo(imgWrap);
				bubble.append(imgWrap);
			} else {
				var fileWrap = $('<div>', { 'class': 'lc-attachment lc-attachment-file' });
				var fileLink = $('<a>', { href: row.attachment_url, target: '_blank', rel: 'noopener noreferrer', download: '', 'class': 'lc-file-link' })
					.append($('<span>', { 'class': 'lc-file-name' }).text('📎 ' + (row.attachment_name || 'Download File')));
				if (row.attachment_size) {
					fileLink.append($('<span>', { 'class': 'lc-file-size' }).text(formatFileSize(row.attachment_size)));
				}
				fileLink.appendTo(fileWrap);
				bubble.append(fileWrap);
			}
		}

		if (row.mine) {
			container.append(bubble);
		} else {
			$('<div>', { 'class': 'lc-msg-row-incoming' })
				.append($('<span>', { 'class': 'lc-msg-avatar' }).text(initial(row.author)))
				.append(bubble)
				.appendTo(container);
		}

		var meta = (row.time || '') + (!row.mine && row.author ? ' · ' + row.author : '');
		$('<p>', { 'class': 'lc-msg-time' + (row.mine ? '' : ' alt') }).text(meta).appendTo(container);
	}

	function syncCursorFromDom() {
		var ids = messagesContainer().find('[data-message-id]').map(function () {
			var v = parseInt(this.getAttribute('data-message-id'), 10);
			return isNaN(v) ? 0 : v;
		}).get();
		state.lastMessageId = ids.length ? Math.max.apply(null, ids) : 0;

		var dates = messagesContainer().find('.lc-date-divider');
		state.lastRenderedDate = dates.length ? (dates.last().attr('data-date') || '') : '';
	}

	/* -----------------------------------------------------------------------
	 * Optimistic Send & Retry
	 * -------------------------------------------------------------------- */

	function appendPendingMessage(clientId, text, att) {
		appendMessage({
			id: clientId,
			msg: text,
			mine: true,
			author: '',
			ts: Math.floor(Date.now() / 1000),
			time: 'Sending…',
			date: todayStamp(),
			attachment_url: att ? att.url : '',
			attachment_type: att ? att.type : '',
			attachment_name: att ? att.name : '',
			attachment_size: att ? att.size : 0
		});
		messagesContainer().find('[data-message-id="' + clientId + '"]').attr('data-pending', '1');
		updateSeenReceipt(0);
		scrollToBottom(messagesContainer());
	}

	function removePendingBubble(clientId) {
		var b = messagesContainer().find('[data-message-id="' + clientId + '"]');
		if (!b.length) return;
		b.next('.lc-msg-time').remove();
		b.remove();
	}

	function markBubbleSent(clientId, serverData) {
		var b = messagesContainer().find('[data-message-id="' + clientId + '"]');
		if (!b.length) return;
		b.removeAttr('data-pending').attr('data-sent', '1');
		if (serverData && serverData.id) {
			b.attr('data-message-id', serverData.id);
			if (serverData.time) {
				b.attr('title', serverData.time);
				b.next('.lc-msg-time').text(serverData.time);
			} else {
				b.next('.lc-msg-time').text('Sent');
			}
			state.lastMessageId = Math.max(state.lastMessageId, parseInt(serverData.id, 10) || 0);
		} else {
			b.next('.lc-msg-time').text('Sent');
		}
	}

	function markBubbleFailed(clientId, msg) {
		var b = messagesContainer().find('[data-message-id="' + clientId + '"]');
		if (!b.length) return;
		b.removeAttr('data-pending').attr('data-failed', '1');
		b.next('.lc-msg-time').html(
			escHtml(msg || 'Failed to send') + ' · <button type="button" class="lc-retry-btn" data-pending-id="' + escHtml(clientId) + '">Retry</button>'
		);
	}

	function submitMessage(form, text, att) {
		var $form = $(form);
		var conversationId = parseInt($form.find('input[name="id"]').val(), 10) || state.conversationId;
		if (!conversationId || (!text && !att)) return;

		var clientId = 'pending-' + (++state.pendingSeq);
		state.retryQueue[clientId] = { form: form, text: text, att: att };
		appendPendingMessage(clientId, text, att);

		post('live_chats_post_message', {
			id: conversationId,
			msg: text,
			attachment_url: att ? att.url : '',
			attachment_type: att ? att.type : '',
			attachment_name: att ? att.name : '',
			attachment_size: att ? att.size : 0
		}).done(function (res) {
			if (res && res.success) {
				delete state.retryQueue[clientId];
				markBubbleSent(clientId, res.data);
				schedulePoll(200);
			} else {
				markBubbleFailed(clientId, errorText(res, 'Could not send message.'));
			}
		}).fail(function (xhr) {
			markBubbleFailed(clientId, failText(xhr, 'Network error. Click retry.'));
		});
	}

	function sendMessage(form) {
		var $form = $(form);
		var input = $form.find('[name="msg"]');
		var text = $.trim(input.val() || '');
		var att = state.pendingAttachment;

		if (!text && !att) return;

		state.isTyping = false;
		state.currentDraft = '';
		resetComposer(input[0]);
		clearAttachmentPreview();
		input.trigger('focus');

		submitMessage(form, text, att);
	}

	/* -----------------------------------------------------------------------
	 * Navigation & Flows
	 * -------------------------------------------------------------------- */

	function populateSavedVisitor() {
		try {
			var n = localStorage.getItem('lc_guest_name');
			var em = localStorage.getItem('lc_guest_email');
			if (n && !$('#lc-login-name').val()) $('#lc-login-name').val(n);
			if (em && !$('#lc-login-email').val()) $('#lc-login-email').val(em);
		} catch (e) {}
	}

	function switchTab(tabName) {
		state.currentTab = tabName;
		var showHome = cfg.widgetMode === 'hub' && parseInt(cfg.showHome, 10) === 1;

		$('#live-chats-widget .lc-tab-view').hide();
		$('#live-chats-widget .lc-bottom-nav .lc-nav-item').removeClass('active');

		if (tabName === 'home' && showHome) {
			$('#live-chats-widget .lc-tab-home').css('display', 'flex');
			$('#live-chats-widget .lc-bottom-nav .lc-nav-item[data-tab="home"]').addClass('active');
			$('#live-chats-widget .lc-header-back-btn').hide();
		} else if (tabName === 'login') {
			$('#live-chats-widget .lc-tab-login').css('display', 'flex');
			populateSavedVisitor();
			$('#live-chats-widget .lc-header-back-btn').toggle(showHome);
		} else if (tabName === 'messages') {
			$('#live-chats-widget .lc-bottom-nav .lc-nav-item[data-tab="messages"]').addClass('active');
			if (state.conversationId) {
				state.currentTab = 'thread';
				$('#live-chats-widget .lc-tab-thread').css('display', 'flex');
				$('#live-chats-widget .lc-header-back-btn').show();
				schedulePoll(0);
			} else {
				loadConversations();
			}
		} else if (tabName === 'conversations') {
			$('#live-chats-widget .lc-bottom-nav .lc-nav-item[data-tab="messages"]').addClass('active');
			loadConversations();
		} else if (tabName === 'thread') {
			$('#live-chats-widget .lc-tab-thread').css('display', 'flex');
			$('#live-chats-widget .lc-bottom-nav .lc-nav-item[data-tab="messages"]').addClass('active');
			$('#live-chats-widget .lc-header-back-btn').show();
			schedulePoll(0);
		}
	}

	function loadConversations() {
		state.currentTab = 'conversations';
		$('#live-chats-widget .lc-conversations-container').html('<div class="lc-loading">Loading conversations…</div>');
		$('#live-chats-widget .lc-tab-view').hide();
		$('#live-chats-widget .lc-tab-conversations').css('display', 'flex');

		post('live_chats_conversations').done(function (res) {
			if (!res || !res.success) {
				switchTab('login');
				return;
			}
			$('#live-chats-widget .lc-conversations-container').html(res.data.html || '');
			$('#live-chats-widget .lc-header-back-btn').toggle(cfg.widgetMode === 'hub' && parseInt(cfg.showHome, 10) === 1);
		}).fail(function () {
			switchTab('login');
		});
	}

	function saveDraft(convId) {
		if (!convId) return;
		var txt = $('#live-chats-widget .lc-composer-form [name="msg"]').val() || '';
		if (txt || state.pendingAttachment) {
			state.drafts[convId] = { text: txt, attachment: state.pendingAttachment };
		} else {
			delete state.drafts[convId];
		}
	}

	function restoreDraft(convId) {
		var d = state.drafts[convId];
		if (!d) return;
		var input = $('#live-chats-widget .lc-composer-form [name="msg"]');
		input.val(d.text || '');
		autogrow(input[0]);
		if (d.attachment) {
			state.pendingAttachment = d.attachment;
			showAttachmentPreview(d.attachment);
		}
	}

	function openConversation(id) {
		id = parseInt(id, 10);
		if (!id) return;

		if (state.conversationId === id && $('#live-chats-widget .lc-thread-container').children().length) {
			switchTab('thread');
			return;
		}

		saveDraft(state.conversationId);
		state.pendingAttachment = null;
		state.conversationId = id;
		state.lastMessageId = 0;
		state.lastRenderedDate = '';

		var $rating = $('#live-chats-widget .lc-rating-card');
		$rating.hide();
		$rating.find('.lc-rating-thanks').hide();
		$rating.find('.lc-star-rating, .lc-feedback-input, .lc-submit-rating-btn').show();
		$rating.find('.lc-star').removeClass('selected');
		state.selectedRating = 0;

		$('#live-chats-widget .lc-thread-container').html('<div class="lc-loading">Loading chat…</div>');
		switchTab('thread');

		post('live_chats_conversation', { conversation_id: id }).done(function (res) {
			if (!res || !res.success || !res.data) {
				switchTab('login');
				return;
			}
			$('#live-chats-widget .lc-thread-container').html(res.data.html || '');
			restoreDraft(id);
			syncCursorFromDom();
			updateSeenReceipt(res.data.read_through || 0);
			scrollToBottom(messagesContainer());
			initEmojiPicker();
			schedulePoll(1500);
		}).fail(function () {
			switchTab('login');
		});
	}

	function findOrCreateConversation(initialMessage, forceNew) {
		var payload = { context: getVisitorContext() };
		if (initialMessage) payload.initial_message = initialMessage;
		if (forceNew || state.forceNew) payload.force_new = 1;

		post('live_chats_find_conversation', payload).done(function (res) {
			if (res && res.success && res.data && res.data.id) {
				state.forceNew = false;
				cfg.hasSession = true;
				openConversation(res.data.id);
			} else {
				switchTab('login');
				$('#lc-prechat-form .lc-form-status').text(errorText(res, 'Please enter your details to start chatting.'));
			}
		}).fail(function (xhr) {
			switchTab('login');
			if (xhr && xhr.status !== 401) {
				$('#lc-prechat-form .lc-form-status').text(failText(xhr, 'Unable to start conversation.'));
			}
		});
	}

	function markChatEnded() {
		var $composer = $('#live-chats-widget .lc-composer-form');
		if ($composer.length) {
			$composer.replaceWith(
				'<div class="lc-session-ended"><p>This chat session has ended.</p>' +
				'<button type="button" class="lc-btn-primary lc-start-new-chat-btn">Start New Conversation</button></div>'
			);
		}
		$('#live-chats-widget .lc-rating-card').slideDown(180);
	}

	/* -----------------------------------------------------------------------
	 * Attachments, Emoji & Lightbox
	 * -------------------------------------------------------------------- */

	function showAttachmentPreview(att) {
		$('#live-chats-widget .lc-composer-preview')
			.html('<span>📎 ' + escHtml(att.name) + '</span><button type="button" class="lc-preview-remove" aria-label="Remove attachment">&times;</button>')
			.show();
	}

	function clearAttachmentPreview() {
		state.pendingAttachment = null;
		$('#live-chats-widget .lc-composer-preview').empty().hide();
		$('#live-chats-widget .lc-file-input').val('');
	}

	function handleFileUpload(file) {
		if (!file) return;
		var fd = new FormData();
		fd.append('action', 'live_chats_upload_attachment');
		fd.append('live_chats_nonce', cfg.nonce);
		fd.append('file', file);

		var $preview = $('#live-chats-widget .lc-composer-preview');
		$preview.html('<span>Uploading ' + escHtml(file.name) + '…</span>').show();

		$.ajax({
			url: cfg.ajaxUrl,
			method: 'POST',
			data: fd,
			processData: false,
			contentType: false
		}).done(function (res) {
			if (res && res.success && res.data) {
				state.pendingAttachment = res.data;
				showAttachmentPreview(res.data);
			} else {
				$preview.hide();
				window.alert(errorText(res, 'Upload failed.'));
			}
		}).fail(function (xhr) {
			$preview.hide();
			window.alert(failText(xhr, 'Upload failed. Please check file type and size.'));
		});
	}

	function initEmojiPicker() {
		var $picker = $('#live-chats-widget .lc-emoji-picker');
		if (!$picker.length || $picker.children().length > 0) return;
		$.each(EMOJIS, function (_, emoji) {
			$('<button>', { type: 'button', 'class': 'lc-emoji-item' }).text(emoji).appendTo($picker);
		});
	}

	function insertAtCursor(field, text) {
		if (!field) return;
		var val = field.value;
		var s = typeof field.selectionStart === 'number' ? field.selectionStart : val.length;
		var e = typeof field.selectionEnd === 'number' ? field.selectionEnd : s;
		field.value = val.slice(0, s) + text + val.slice(e);
		field.selectionStart = field.selectionEnd = s + text.length;
	}

	function openLightbox(src, alt) {
		var $box = $('#live-chats-widget .lc-lightbox');
		if (!$box.length) {
			$box = $('<div>', { 'class': 'lc-lightbox' })
				.append($('<img>', { 'class': 'lc-lightbox-img' }))
				.append($('<button>', { type: 'button', 'class': 'lc-lightbox-close', 'aria-label': 'Close image' }).html('&times;'))
				.appendTo('#live-chats-widget');
		}
		$box.find('.lc-lightbox-img').attr({ src: src, alt: alt || '' });
		$box.addClass('open');
	}

	function closeLightbox() {
		$('#live-chats-widget .lc-lightbox').removeClass('open');
	}

	/* -----------------------------------------------------------------------
	 * Real-Time Adaptive Delta Polling
	 * -------------------------------------------------------------------- */

	function schedulePoll(delay) {
		window.clearTimeout(state.timer);
		state.timer = window.setTimeout(poll, Math.max(0, delay));
	}

	function poll() {
		if (state.inFlight || document.hidden || !cfg.hasSession) {
			return;
		}
		var isOpen = $('#live-chats-widget .lc-widget-window').hasClass('open');
		if (!isOpen && !state.conversationId) {
			return;
		}

		state.inFlight = true;

		post('live_chats_poll', {
			conversation_id: state.conversationId || 0,
			since_id: state.lastMessageId,
			fingerprint: state.fingerprint,
			typing: state.isTyping ? 1 : 0,
			peek: state.isTyping ? state.currentDraft : ''
		}).done(function (res) {
			if (!res || !res.success || !res.data) {
				schedulePoll(15000);
				return;
			}

			var data = res.data;
			state.backoffMs = 2000;

			if (typeof data.operators_online !== 'undefined') {
				updatePresence(data.operators_online);
			}

			if (typeof data.unread_total !== 'undefined') {
				updateUnreadBadges(data.unread_total);
			}

			if (data.list !== null && typeof data.list !== 'undefined' && state.currentTab === 'conversations') {
				$('#live-chats-widget .lc-conversations-container').html(data.list);
			}
			if (data.fingerprint) {
				state.fingerprint = data.fingerprint;
			}

			var container = messagesContainer();
			var pinned = isPinnedToBottom(container);

			if (data.messages && data.messages.length && state.currentTab === 'thread') {
				var hasIncoming = false;
				$.each(data.messages, function (_, row) {
					appendMessage(row);
					if (!row.mine) {
						hasIncoming = true;
						if (document.hidden || !isOpen) {
							notifyDesktop(row.author || cfg.siteName || 'Support', row.msg || 'New attachment');
							flashTitle();
						}
					}
				});
				state.lastMessageId = Math.max(state.lastMessageId, data.last_id || 0);

				if (hasIncoming) {
					playChime();
				}
				if (pinned) {
					scrollToBottom(container);
				}
			}

			if (state.currentTab === 'thread') {
				setTypingDisplay(!!data.typing, data.typing_user || '');
				updateSeenReceipt(data.read_through || 0);
				if (data.state === 'closed') {
					markChatEnded();
				}
			}

			schedulePoll(isOpen ? (data.next_delay || 5000) : 15000);
		}).fail(function () {
			state.backoffMs = Math.min(30000, state.backoffMs * 2);
			schedulePoll(state.backoffMs);
		}).always(function () {
			state.inFlight = false;
		});
	}

	/* -----------------------------------------------------------------------
	 * Widget Open / Close
	 * -------------------------------------------------------------------- */

	function resumeOrShow(fallbackTab) {
		switchTab(fallbackTab);
		if (!cfg.hasSession) return;

		post('live_chats_find_conversation', { context: getVisitorContext(), find_only: 1 }).done(function (res) {
			if (res && res.success && res.data && res.data.id && state.currentTab === fallbackTab) {
				openConversation(res.data.id);
			}
		});
	}

	function openWidget() {
		var $win = $('#live-chats-widget .lc-widget-window');
		var $btn = $('#live-chats-widget .lc-launcher-btn');

		$win.css('display', 'flex').addClass('open');
		$('#live-chats-widget').addClass('is-open');
		$btn.attr('aria-expanded', 'true');
		$btn.find('.lc-launcher-open-icon').hide();
		$btn.find('.lc-launcher-close-icon').show();
		$btn.find('.lc-unread-badge').hide();

		requestNotificationPermission();
		resetTitle();

		if (!state.started) {
			state.started = true;
			var showHome = cfg.widgetMode === 'hub' && parseInt(cfg.showHome, 10) === 1;
			if (state.conversationId) {
				switchTab('thread');
			} else if (showHome) {
				resumeOrShow('home');
			} else {
				resumeOrShow('login');
			}
		} else if (state.conversationId) {
			schedulePoll(0);
		}
	}

	function closeWidget() {
		var $win = $('#live-chats-widget .lc-widget-window');
		var $btn = $('#live-chats-widget .lc-launcher-btn');

		$win.removeClass('open');
		$('#live-chats-widget').removeClass('is-open');
		$btn.attr('aria-expanded', 'false');
		window.setTimeout(function () {
			if (!$win.hasClass('open')) {
				$win.hide();
			}
		}, 200);
		$btn.find('.lc-launcher-open-icon').show();
		$btn.find('.lc-launcher-close-icon').hide();
	}

	/* -----------------------------------------------------------------------
	 * Event Bindings
	 * -------------------------------------------------------------------- */

	$(function () {
		var $root = $('#live-chats-widget');
		if (!$root.length) return;

		if (!state.soundEnabled) {
			$root.find('.lc-sound-toggle-btn').css('opacity', '0.5');
		}

		$root
			.on('click', '.lc-launcher-btn', function (e) {
				e.preventDefault();
				if ($root.find('.lc-widget-window').hasClass('open')) {
					closeWidget();
				} else {
					openWidget();
				}
			})
			.on('click', '.lc-close-widget-btn', function (e) {
				e.preventDefault();
				closeWidget();
			})
			.on('click', '.lc-bottom-nav .lc-nav-item', function () {
				switchTab($(this).data('tab'));
			})
			.on('click', '.lc-header-back-btn', function () {
				var showHome = cfg.widgetMode === 'hub' && parseInt(cfg.showHome, 10) === 1;
				if (state.currentTab === 'thread') {
					switchTab('conversations');
				} else if (showHome) {
					switchTab('home');
				} else {
					switchTab('conversations');
				}
			})
			.on('click', '.lc-start-chat-card-btn', function () {
				if (cfg.hasSession) {
					findOrCreateConversation('', false);
				} else {
					switchTab('login');
				}
			})
			.on('click', '.lc-new-conv-btn, .lc-new-conv-link, .lc-start-new-chat-btn', function (e) {
				e.preventDefault();
				$root.find('.lc-menu-dropdown').prop('hidden', true);
				state.conversationId = 0;
				state.forceNew = true;
				switchTab('login');
				$('#lc-login-msg').val('').trigger('focus');
			})
			.on('submit', '#lc-prechat-form', function (e) {
				e.preventDefault();
				if (state.submittingPrechat) return;

				var $form = $(this);
				var $status = $form.find('.lc-form-status').text('');
				var $submit = $form.find('.lc-prechat-submit');
				var name = $.trim($form.find('input[name="name"]').val() || '');
				var email = $.trim($form.find('input[name="email"]').val() || '');
				var initialMsg = $.trim($form.find('textarea[name="initial_message"]').val() || '');

				if (!name || !email) {
					$status.text('Please enter your name and email address.');
					return;
				}

				try {
					localStorage.setItem('lc_guest_name', name);
					localStorage.setItem('lc_guest_email', email);
				} catch (err) {}

				state.submittingPrechat = true;
				$submit.prop('disabled', true);

				post('live_chats_authenticate', { name: name, email: email }).done(function (res) {
					if (res && res.success) {
						cfg.hasSession = true;
						findOrCreateConversation(initialMsg, state.forceNew);
					} else {
						$status.text(errorText(res, 'Could not start chat session.'));
					}
				}).fail(function (xhr) {
					$status.text(failText(xhr, 'Server error. Please try again.'));
				}).always(function () {
					state.submittingPrechat = false;
					$submit.prop('disabled', false);
				});
			})
			.on('click', '.lc-conv-item', function (e) {
				e.preventDefault();
				openConversation($(this).data('id'));
			})
			.on('click', '.lc-sound-toggle-btn', function () {
				state.soundEnabled = !state.soundEnabled;
				$(this).css('opacity', state.soundEnabled ? '1' : '0.5');
				try { localStorage.setItem('lc_visitor_sound', state.soundEnabled ? '1' : '0'); } catch (e) {}
			})
			.on('click', '.lc-menu-btn', function (e) {
				e.preventDefault();
				var $menu = $(this).siblings('.lc-menu-dropdown');
				var willOpen = $menu.prop('hidden');
				$menu.prop('hidden', !willOpen);
				$(this).attr('aria-expanded', willOpen ? 'true' : 'false');
			})
			.on('click', '.lc-send-transcript-link', function (e) {
				e.preventDefault();
				$root.find('.lc-menu-dropdown').prop('hidden', true);
				if (!state.conversationId) {
					window.alert('Open a conversation first to email its transcript.');
					return;
				}
				post('live_chats_send_transcript', { id: state.conversationId }).done(function (res) {
					window.alert(res && res.success ? 'Transcript sent to your email!' : errorText(res, 'Could not email transcript.'));
				}).fail(function (xhr) {
					window.alert(failText(xhr, 'Could not email transcript.'));
				});
			})
			.on('click', '.lc-end-session-link', function (e) {
				e.preventDefault();
				$root.find('.lc-menu-dropdown').prop('hidden', true);
				$root.find('.lc-confirm-overlay').fadeIn(150);
			})
			.on('click', '.lc-confirm-end-no', function () {
				$root.find('.lc-confirm-overlay').fadeOut(150);
			})
			.on('click', '.lc-confirm-end-yes', function () {
				post('live_chats_end_session').done(function () {
					$root.find('.lc-confirm-overlay').fadeOut(150);
					markChatEnded();
				});
			})
			.on('click', '.lc-emoji-btn', function (e) {
				e.preventDefault();
				$root.find('.lc-emoji-picker').toggle();
			})
			.on('click', '.lc-emoji-item', function () {
				var $input = $root.find('.lc-composer-form [name="msg"]');
				insertAtCursor($input[0], $(this).text());
				autogrow($input[0]);
				$root.find('.lc-emoji-picker').hide();
				$input.trigger('focus');
			})
			.on('change', '.lc-file-input', function () {
				if (this.files && this.files[0]) {
					handleFileUpload(this.files[0]);
				}
			})
			.on('click', '.lc-preview-remove', function () {
				clearAttachmentPreview();
			})
			.on('input', '.lc-composer-form [name="msg"]', function () {
				autogrow(this);
				state.isTyping = true;
				state.currentDraft = $(this).val() || '';
				window.clearTimeout(state.typingTimer);
				state.typingTimer = window.setTimeout(function () {
					state.isTyping = false;
					state.currentDraft = '';
				}, 3500);
			})
			.on('keydown', '.lc-composer-form [name="msg"]', function (e) {
				if (e.key === 'Enter' && !e.shiftKey && !TOUCH) {
					e.preventDefault();
					sendMessage($(this).closest('form')[0]);
				}
			})
			.on('submit', '.lc-composer-form', function (e) {
				e.preventDefault();
				sendMessage(this);
			})
			.on('click', '.lc-retry-btn', function () {
				var clientId = $(this).data('pending-id');
				var entry = state.retryQueue[clientId];
				if (!entry) return;
				delete state.retryQueue[clientId];
				removePendingBubble(clientId);
				submitMessage(entry.form, entry.text, entry.att);
			})
			.on('click', '.lc-star', function () {
				var rating = parseInt($(this).data('rating'), 10);
				state.selectedRating = rating;
				$root.find('.lc-star').each(function (i) {
					$(this).toggleClass('selected', i < rating);
				});
			})
			.on('click', '.lc-rating-close', function () {
				$root.find('.lc-rating-card').slideUp(150);
			})
			.on('click', '.lc-submit-rating-btn', function () {
				if (!state.selectedRating || !state.conversationId) {
					window.alert('Please select a star rating first.');
					return;
				}
				post('live_chats_rate_conversation', {
					id: state.conversationId,
					rating: state.selectedRating,
					feedback: $root.find('.lc-feedback-input').val() || ''
				}).done(function (res) {
					if (res && res.success) {
						$root.find('.lc-star-rating, .lc-feedback-input, .lc-submit-rating-btn').hide();
						$root.find('.lc-rating-thanks').fadeIn(150);
					}
				});
			})
			.on('click', '.lc-attachment-image a', function (e) {
				e.preventDefault();
				openLightbox($(this).attr('href'), $(this).find('img').attr('alt'));
			})
			.on('click', '.lc-lightbox, .lc-lightbox-close', function (e) {
				if (e.target === this) closeLightbox();
			});

		$(document).on('keydown', function (e) {
			if (e.key === 'Escape') {
				closeLightbox();
			}
		});

		$(document).on('visibilitychange', function () {
			if (!document.hidden) {
				resetTitle();
				if (state.conversationId) {
					schedulePoll(0);
				}
			}
		});

		window.addEventListener('online', function () {
			if (state.conversationId) {
				state.backoffMs = 2000;
				schedulePoll(0);
			}
		});

		// Background unread check if visitor already has an active session.
		if (cfg.hasSession) {
			post('live_chats_find_conversation', { context: getVisitorContext(), find_only: 1 }).done(function (res) {
				if (res && res.success && res.data && res.data.id) {
					state.conversationId = parseInt(res.data.id, 10);
					schedulePoll(3000);
				}
			});
		}
	});
}(jQuery));
