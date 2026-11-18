/**
 * Live Chats — Operator 3-Pane Live Inbox Controller
 */
(function ($) {
	'use strict';

	if (typeof window.liveChatsAdminConfig === 'undefined') {
		return;
	}

	var cfg = window.liveChatsAdminConfig;
	var EMOJIS = ['👋', '😊', '👍', '❤️', '🔥', '🎉', '🙌', '💡', '✨', '🚀', '💯', '🙏', '😃', '🤔', '😍', '👏', '🎯', '⭐', '🤝', '😎', '💬', '📞', '📧', '💼'];
	var TOUCH = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
	var LAST_CONV_KEY = 'lc_admin_last_conversation';
	var SOUND_KEY = 'lc_admin_sound';

	var state = {
		conversationId: 0,
		lastMessageId: 0,
		renderGen: 0,
		fingerprint: '',
		lastRenderedDate: '',
		pendingDeleteId: 0,
		isTyping: false,
		typingTimer: null,
		inFlight: false,
		timer: null,
		soundEnabled: true,
		currentFilter: 'all',
		cannedResponses: [],
		pendingAttachment: null,
		pendingSeq: 0,
		retryQueue: {},
		drafts: {},
		backoffMs: 2000
	};

	try {
		if (localStorage.getItem(SOUND_KEY) === '0') {
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
		if (parts.length !== 3) return stamp || '';
		var day = new Date(+parts[0], +parts[1] - 1, +parts[2]);
		var today = new Date();
		today.setHours(0, 0, 0, 0);
		var diff = Math.round((today - day) / 86400000);
		if (diff === 0) return 'Today';
		if (diff === 1) return 'Yesterday';
		return day.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function post(action, data) {
		return $.ajax({
			url: cfg.ajaxUrl,
			method: 'POST',
			cache: false,
			data: $.extend({ action: action, live_chats_nonce: cfg.nonce }, data || {})
		});
	}

	function errorText(res, fallback) {
		if (res && typeof res === 'object' && res.data) {
			if (typeof res.data === 'string') return res.data;
			if (res.data.message) return res.data.message;
		}
		return fallback;
	}

	function failText(xhr, fallback) {
		return errorText(xhr && xhr.responseJSON, fallback);
	}

	/* -----------------------------------------------------------------------
	 * Sound & Notifications
	 * -------------------------------------------------------------------- */

	function renderSoundButton() {
		var $btn = $('.lc-inbox-sound-btn');
		$btn.toggleClass('is-muted', !state.soundEnabled)
			.attr('aria-pressed', state.soundEnabled ? 'true' : 'false')
			.attr('title', $btn.data(state.soundEnabled ? 'title-on' : 'title-off') || '');
	}

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
			osc.frequency.setValueAtTime(523.25, now);
			osc.frequency.exponentialRampToValueAtTime(783.99, now + 0.1);
			gain.gain.setValueAtTime(0.18, now);
			gain.gain.exponentialRampToValueAtTime(0.001, now + 0.38);
			osc.connect(gain);
			gain.connect(audioCtx.destination);
			osc.start(now);
			osc.stop(now + 0.38);
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

	/* -----------------------------------------------------------------------
	 * Message Thread Helpers
	 * -------------------------------------------------------------------- */

	function messagesContainer() {
		return $('#lc-admin-thread-pane .lc-messages');
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
		el.style.height = Math.min(el.scrollHeight, 140) + 'px';
		el.style.overflowY = el.scrollHeight > 140 ? 'auto' : 'hidden';
	}

	function resetComposer(el) {
		if (!el) return;
		el.value = '';
		el.style.height = 'auto';
		el.style.overflowY = 'hidden';
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

	function updateSeenReceipt(readThrough) {
		var container = messagesContainer();
		if (!container.length) return;
		container.find('.lc-seen-receipt').remove();

		readThrough = parseInt(readThrough, 10) || 0;
		if (!readThrough) return;

		var lastStaff = container.find('.lc-message[data-staff="1"], .lc-message[data-mine="1"]').last();
		var lastId = lastStaff.length ? parseInt(lastStaff.attr('data-message-id'), 10) : 0;
		if (lastId > 0 && lastId <= readThrough) {
			var anchor = lastStaff.nextAll('.lc-msg-time').first();
			(anchor.length ? anchor : lastStaff).after($('<p>', { 'class': 'lc-seen-receipt' }).text('Seen by visitor'));
		}
	}

	function setTypingDisplay(isTyping, name) {
		var container = messagesContainer();
		if (!container.length) return;
		var el = container.find('.lc-typing-indicator');
		if (!el.length) {
			el = $('<div>', { 'class': 'lc-typing-indicator alt' })
				.append($('<span>', { 'class': 'lc-typing-label' }))
				.append($('<span>', { 'class': 'lc-typing-dots' })
					.append('<span class="lc-typing-dot"></span><span class="lc-typing-dot"></span><span class="lc-typing-dot"></span>'))
				.appendTo(container);
		}
		if (isTyping) {
			el.find('.lc-typing-label').text((name || 'Visitor') + ' is typing');
			el.css('display', 'inline-flex');
			scrollToBottom(container);
		} else {
			el.hide();
		}
	}

	function updateSneakPeek(peekText) {
		var $box = $('#lc-sneak-peek');
		var $txt = $('#lc-sneak-text');
		if (!$box.length) return;
		if (peekText && $.trim(peekText).length > 0) {
			$txt.text(peekText);
			$box.slideDown(120);
		} else {
			$box.slideUp(120);
		}
	}

	function appendMessage(row) {
		var container = messagesContainer();
		if (!container.length) return;

		if (row.id && container.find('[data-message-id="' + row.id + '"]').length) {
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

		if (row.msg_type === 'system') {
			$('<div>', { 'class': 'lc-message-system', 'data-message-id': row.id })
				.append($('<span>', { 'class': 'lc-system-text' }).text((row.msg || '') + (row.time ? ' · ' + row.time : '')))
				.appendTo(container);
			return;
		}

		if (row.msg_type === 'note') {
			var $note = $('<div>', {
				'class': 'lc-message lc-message-note',
				'data-message-id': row.id,
				'data-author': row.author || '',
				'data-ts': row.ts || 0
			});
			$note.append(
				$('<div>', { 'class': 'lc-note-header' })
					.html('🔒 <span>Private Note</span> · <span class="lc-note-author">' + escHtml(row.author || 'Staff') + '</span> · <span class="lc-note-time">' + escHtml(row.time || '') + '</span>')
			);
			$note.append($('<div>', { 'class': 'lc-note-body' }).html(linkify(row.msg, true)));
			container.append($note);
			return;
		}

		var alt = row.staff ? '' : ' alt';
		var last = container.children('.lc-message').last();
		var extending = last.length
			&& !last.hasClass('lc-message-note')
			&& !last.nextAll('.lc-date-divider').length
			&& (last.attr('data-author') || '') === (row.author || '')
			&& !!last.attr('data-staff') === !!row.staff
			&& Math.abs((row.ts || 0) - (parseInt(last.attr('data-ts'), 10) || 0)) <= 300;

		if (extending) {
			container.children('.lc-msg-time').last().remove();
			last.removeClass('lc-group-end').find('.lc-msg-avatar').addClass('lc-avatar-spacer').text('');
		}

		var $bubble = $('<div>', {
			'class': 'lc-message' + alt + (extending ? '' : ' lc-group-start') + ' lc-group-end',
			title: row.time || ''
		})
			.attr('data-message-id', row.id)
			.attr('data-author', row.author || '')
			.attr('data-ts', row.ts || 0)
			.attr('data-staff', row.staff ? '1' : '')
			.attr('data-mine', row.staff ? '1' : '');

		$bubble.append($('<span>', { 'class': 'lc-msg-avatar' }).text(initial(row.author)));

		var $content = $('<div>', { 'class': 'lc-bubble-content' });
		if (row.msg) {
			$content.append($('<div>', { 'class': 'lc-message-text' }).html(linkify(row.msg, row.staff)));
		}

		if (row.attachment_url) {
			if (row.attachment_type === 'image') {
				var $img = $('<div>', { 'class': 'lc-attachment lc-attachment-image' });
				$('<a>', { href: row.attachment_url, target: '_blank', rel: 'noopener noreferrer', 'class': 'lc-img-preview' })
					.append($('<img>', { src: row.attachment_url, alt: row.attachment_name || 'Image' }))
					.appendTo($img);
				$content.append($img);
			} else {
				var $file = $('<div>', { 'class': 'lc-attachment lc-attachment-file' });
				var $link = $('<a>', { href: row.attachment_url, target: '_blank', rel: 'noopener noreferrer', download: '', 'class': 'lc-file-link' })
					.append($('<span>', { 'class': 'lc-file-name' }).text('📎 ' + (row.attachment_name || 'Attachment')));
				if (row.attachment_size) {
					$link.append($('<span>', { 'class': 'lc-file-size' }).text(formatFileSize(row.attachment_size)));
				}
				$link.appendTo($file);
				$content.append($file);
			}
		}

		$bubble.append($content);
		container.append($bubble);

		var meta = (row.time || '') + (row.author ? ' · ' + row.author : '');
		$('<p>', { 'class': 'lc-msg-time' + alt }).text(meta).appendTo(container);
	}

	/* -----------------------------------------------------------------------
	 * Optimistic Send & Retry
	 * -------------------------------------------------------------------- */

	function removePendingBubble(clientId) {
		var $b = messagesContainer().find('[data-message-id="' + clientId + '"]');
		if (!$b.length) return;
		$b.next('.lc-msg-time').remove();
		$b.remove();
	}

	function submitMessage(form, text, att, isNote) {
		var $form = $(form);
		var conversationId = parseInt($form.find('input[name="id"]').val(), 10) || state.conversationId;
		if (!conversationId || (!text && !att)) return;

		var clientId = 'pending-' + (++state.pendingSeq);
		state.retryQueue[clientId] = { form: form, text: text, att: att, note: !!isNote };

		appendMessage({
			id: clientId,
			msg: text,
			msg_type: isNote ? 'note' : 'message',
			staff: true,
			author: $('#lc-admin-name').text() || 'Agent',
			ts: Math.floor(Date.now() / 1000),
			time: 'Sending…',
			date: todayStamp(),
			attachment_url: att ? att.url : '',
			attachment_type: att ? att.type : '',
			attachment_name: att ? att.name : '',
			attachment_size: att ? att.size : 0
		});
		messagesContainer().find('[data-message-id="' + clientId + '"]').attr('data-pending', '1');
		if (!isNote) updateSeenReceipt(0);
		scrollToBottom(messagesContainer());

		post('live_chats_admin_post_message', {
			id: conversationId,
			msg: text,
			msg_type: isNote ? 'note' : 'message',
			attachment_url: att ? att.url : '',
			attachment_type: att ? att.type : '',
			attachment_name: att ? att.name : '',
			attachment_size: att ? att.size : 0
		}).done(function (res) {
			var $b = messagesContainer().find('[data-message-id="' + clientId + '"]');
			if (res && res.success && res.data) {
				delete state.retryQueue[clientId];
				$b.removeAttr('data-pending').attr('data-sent', '1');
				if (res.data.id) {
					$b.attr('data-message-id', res.data.id);
					state.lastMessageId = Math.max(state.lastMessageId, parseInt(res.data.id, 10) || 0);
				}
				if (isNote) {
					$b.find('.lc-note-time').text(res.data.time || 'Sent');
				} else {
					var author = $b.attr('data-author') || '';
					$b.next('.lc-msg-time').text((res.data.time || 'Sent') + (author ? ' · ' + author : ''));
				}
				schedulePoll(200);
			} else {
				markFailed($b, clientId, isNote, errorText(res, 'Failed to send.'));
			}
		}).fail(function (xhr) {
			var $b = messagesContainer().find('[data-message-id="' + clientId + '"]');
			markFailed($b, clientId, isNote, failText(xhr, 'Failed to send.'));
		});
	}

	function markFailed($b, clientId, isNote, msg) {
		$b.removeAttr('data-pending').attr('data-failed', '1');
		var retryHtml = escHtml(msg) + ' · <button type="button" class="lc-retry-btn" data-pending-id="' + escHtml(clientId) + '">Retry</button>';
		if (isNote) {
			$b.find('.lc-note-time').text('Failed');
			$b.find('.lc-note-body').after($('<div>', { 'class': 'lc-note-error' }).html(retryHtml));
		} else {
			$b.next('.lc-msg-time').html(retryHtml);
		}
	}

	function sendMessage(form) {
		var $form = $(form);
		var $input = $form.find('[name="msg"]');
		var msgType = $form.find('input[name="msg_type"]').val() || 'message';
		var text = $.trim($input.val() || '');
		var att = state.pendingAttachment;

		if (!text && !att) return;

		state.isTyping = false;
		resetComposer($input[0]);
		clearAttachmentPreview();
		hideCannedAutocomplete();
		$input.trigger('focus');

		submitMessage(form, text, att, msgType === 'note');
	}

	/* -----------------------------------------------------------------------
	 * Conversations List, Filtering, & Thread Opening
	 * -------------------------------------------------------------------- */

	function filterUsers() {
		var query = $.trim($('#lc-conv-search').val() || '').toLowerCase();
		var headings = '.lc-section-heading';
		var matches = 0;
		var unreadTotal = 0;

		$('#lc-admin-conversations-pane .lc-admin-conv-item').each(function () {
			var $el = $(this);
			var text = $el.text().toLowerCase();
			var tags = ($el.attr('data-tags') || '').toLowerCase();
			var unreadCount = parseInt($el.attr('data-unread'), 10) || 0;
			var isUnread = $el.hasClass('is-unread') || unreadCount > 0;
			var isClosed = $el.attr('data-is-closed') === '1';
			var isMine = $el.attr('data-mine') === '1';
			var isQueued = $el.attr('data-queued') === '1';

			if (isUnread) unreadTotal++;

			var searchHit = !query || text.indexOf(query) !== -1 || tags.indexOf(query) !== -1;
			var tabHit = true;
			if (state.currentFilter === 'unread') tabHit = isUnread;
			else if (state.currentFilter === 'mine') tabHit = isMine && !isClosed;
			else if (state.currentFilter === 'queued') tabHit = isQueued && !isClosed;
			else if (state.currentFilter === 'closed') tabHit = isClosed;

			var show = searchHit && tabHit;
			this.style.display = show ? '' : 'none';
			if (show) matches++;
		});

		var $badge = $('#lc-unread-tab-count');
		if (unreadTotal > 0) {
			$badge.text(unreadTotal).show();
		} else {
			$badge.hide();
		}

		$('#lc-admin-conversations-pane ' + headings).each(function () {
			var visible = $(this).nextUntil(headings, '.lc-admin-conv-item').filter(function () {
				return this.style.display !== 'none';
			});
			this.style.display = visible.length ? '' : 'none';
		});

		$('.lc-filter-empty').remove();
		if (!matches && $('#lc-admin-conversations-pane .lc-admin-conv-item').length) {
			$('<div>', { 'class': 'lc-no-conversations lc-filter-empty' })
				.append($('<p>').text(query ? 'No conversations match "' + $('#lc-conv-search').val() + '".' : 'No conversations in this filter.'))
				.appendTo('#lc-admin-conversations-pane .lc-admin-scrollable');
		}
	}

	function loadConversations() {
		return post('live_chats_admin_conversations', { filter: state.currentFilter }).done(function (res) {
			if (!res || !res.success || !res.data) return;
			$('#lc-admin-conversations-pane').html(res.data.html || '');
			if (state.conversationId) {
				$('.lc-admin-conv-item[data-id="' + state.conversationId + '"]').addClass('active');
			}
			state.fingerprint = '';
			filterUsers();
			schedulePoll(1500);
		});
	}

	function saveDraft(convId) {
		if (!convId) return;
		var txt = $('#lc-admin-composer-input').val() || '';
		if (txt || state.pendingAttachment) {
			state.drafts[convId] = { text: txt, attachment: state.pendingAttachment };
		} else {
			delete state.drafts[convId];
		}
	}

	function restoreDraft(convId) {
		var d = state.drafts[convId];
		if (!d) return;
		var $input = $('#lc-admin-composer-input');
		$input.val(d.text || '');
		autogrow($input[0]);
		if (d.attachment) {
			state.pendingAttachment = d.attachment;
			showAttachmentPreview(d.attachment);
		}
	}

	function applyDetailsState() {
		var saved = null;
		try { saved = localStorage.getItem('lc_details_open'); } catch (e) {}
		var isOpen = saved === null ? window.innerWidth > 1100 : saved !== '0';
		$('.lc-profile-panel').toggleClass('is-hidden', !isOpen);
		$('.lc-toggle-details-btn').toggleClass('active', isOpen);
	}

	function loadSmartSuggestions(cid) {
		if (!cid) return;
		post('live_chats_admin_suggestions', { conversation_id: cid }).done(function (res) {
			if (!res || !res.success || !res.data || !res.data.suggestions) return;
			var $chips = $('#lc-suggestion-chips').empty();
			$.each(res.data.suggestions, function (_, s) {
				$('<button>', {
					type: 'button',
					'class': 'lc-suggestion-chip',
					'data-text': s.text,
					title: s.text
				}).text(s.title).appendTo($chips);
			});
			if (res.data.suggestions.length) {
				$('#lc-smart-suggestions').slideDown(140);
			}
		});
	}

	function openConversation(id, force) {
		id = parseInt(id, 10);
		if (!id) return;
		if (state.conversationId === id && !force) return;

		saveDraft(state.conversationId);
		state.pendingAttachment = null;
		state.conversationId = id;
		state.lastMessageId = 0;
		state.renderGen++;
		state.lastRenderedDate = '';

		try { localStorage.setItem(LAST_CONV_KEY, String(id)); } catch (e) {}

		$('.lc-admin-conv-item').removeClass('active');
		$('.lc-admin-conv-item[data-id="' + id + '"]').addClass('active');

		return post('live_chats_admin_conversation', { conversation_id: id }).done(function (res) {
			if (!res || !res.success || !res.data) {
				$('#lc-sidebar-status').text(errorText(res, 'Unable to open conversation.'));
				return;
			}
			$('#lc-admin-thread-pane').html(res.data.html || '');
			restoreDraft(id);
			syncCursorFromDom();
			updateSeenReceipt(res.data.read_through || 0);
			scrollToBottom(messagesContainer());
			initEmojiPicker();
			loadSmartSuggestions(id);
			applyDetailsState();
			schedulePoll(0);
		});
	}

	function resumeLastConversation() {
		var saved = 0;
		try { saved = parseInt(localStorage.getItem(LAST_CONV_KEY), 10) || 0; } catch (e) {}
		if (saved && $('.lc-admin-conv-item[data-id="' + saved + '"]').length) {
			openConversation(saved);
		}
	}

	/* -----------------------------------------------------------------------
	 * Canned Responses, Attachments, Emoji, Tags, Lightbox
	 * -------------------------------------------------------------------- */

	function loadCannedResponses() {
		$.ajax({
			url: cfg.restUrl + 'operator/canned-responses',
			method: 'GET',
			beforeSend: function (xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cfg.restNonce);
			}
		}).done(function (res) {
			if (res && res.items) {
				state.cannedResponses = res.items;
			}
		});
	}

	function handleCannedInput(val) {
		if (!val || val.indexOf('/') !== 0) {
			hideCannedAutocomplete();
			return;
		}
		var q = val.slice(1).toLowerCase();
		var matches = $.grep(state.cannedResponses, function (item) {
			return (item.shortcut || '').toLowerCase().indexOf(q) !== -1 || (item.title || '').toLowerCase().indexOf(q) !== -1;
		});
		if (!matches.length) {
			hideCannedAutocomplete();
			return;
		}
		var $menu = $('#lc-canned-autocomplete').empty();
		$.each(matches, function (_, item) {
			$('<div>', { 'class': 'lc-canned-item', 'data-content': item.content })
				.append($('<div>', { 'class': 'lc-canned-shortcut' }).text('/' + item.shortcut + ' — ' + item.title))
				.append($('<div>', { 'class': 'lc-canned-preview' }).text(item.content))
				.appendTo($menu);
		});
		$menu.show();
	}

	function hideCannedAutocomplete() {
		$('#lc-canned-autocomplete').empty().hide();
	}

	function showAttachmentPreview(att) {
		$('#live-chats-admin .lc-composer-preview')
			.html('<span>📎 ' + escHtml(att.name) + '</span><button type="button" class="lc-preview-remove" aria-label="Remove">&times;</button>')
			.show();
	}

	function clearAttachmentPreview() {
		state.pendingAttachment = null;
		$('#live-chats-admin .lc-composer-preview').empty().hide();
		$('#live-chats-admin .lc-file-input').val('');
	}

	function handleFileUpload(file) {
		if (!file) return;
		var fd = new FormData();
		fd.append('action', 'live_chats_upload_attachment');
		fd.append('live_chats_nonce', cfg.nonce);
		fd.append('file', file);

		var $preview = $('#live-chats-admin .lc-composer-preview');
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
			window.alert(failText(xhr, 'Upload failed.'));
		});
	}

	function initEmojiPicker() {
		var $picker = $('#live-chats-admin .lc-emoji-picker');
		if (!$picker.length || $picker.children().length > 0) return;
		$.each(EMOJIS, function (_, emoji) {
			$('<button>', { type: 'button', 'class': 'lc-emoji-item' }).text(emoji).appendTo($picker);
		});
	}

	function insertAtCursor(field, text) {
		if (!field) return;
		var v = field.value;
		var s = typeof field.selectionStart === 'number' ? field.selectionStart : v.length;
		var e = typeof field.selectionEnd === 'number' ? field.selectionEnd : s;
		field.value = v.slice(0, s) + text + v.slice(e);
		field.selectionStart = field.selectionEnd = s + text.length;
	}

	function getCurrentTags() {
		var tags = [];
		$('#lc-tags-list .lc-tag-pill').each(function () {
			var t = $(this).attr('data-tag');
			if (t) tags.push(t);
		});
		return tags;
	}

	function saveTags(tags) {
		if (!state.conversationId) return;
		post('live_chats_admin_tags', {
			conversation_id: state.conversationId,
			tags: tags
		}).done(function (res) {
			if (res && res.success) {
				loadConversations();
			}
		});
	}

	function addTagToDOM(tagName) {
		tagName = $.trim(tagName || '');
		if (!tagName) return;
		var current = getCurrentTags();
		if (current.indexOf(tagName) !== -1) return;
		current.push(tagName);

		$('<span>', { 'class': 'lc-tag-pill', 'data-tag': tagName })
			.text(tagName)
			.append($('<button>', { type: 'button', 'class': 'lc-tag-remove', 'aria-label': 'Remove' }).html('&times;'))
			.appendTo('#lc-tags-list');
		$('#lc-new-tag-input').val('');
		saveTags(current);
	}

	function openLightbox(src, alt) {
		var $box = $('#live-chats-admin .lc-lightbox');
		if (!$box.length) {
			$box = $('<div>', { 'class': 'lc-lightbox' })
				.append($('<img>', { 'class': 'lc-lightbox-img' }))
				.append($('<button>', { type: 'button', 'class': 'lc-lightbox-close', 'aria-label': 'Close' }).html('&times;'))
				.appendTo('#live-chats-admin');
		}
		$box.find('.lc-lightbox-img').attr({ src: src, alt: alt || '' });
		$box.addClass('open');
	}

	function closeLightbox() {
		$('#live-chats-admin .lc-lightbox').removeClass('open');
	}

	/* -----------------------------------------------------------------------
	 * Adaptive Delta Polling
	 * -------------------------------------------------------------------- */

	function schedulePoll(delay) {
		window.clearTimeout(state.timer);
		state.timer = window.setTimeout(poll, Math.max(0, delay));
	}

	function poll() {
		if (state.inFlight || document.hidden) return;
		state.inFlight = true;
		var gen = state.renderGen;

		post('live_chats_admin_poll', {
			conversation_id: state.conversationId || 0,
			since_id: state.lastMessageId,
			fingerprint: state.fingerprint,
			typing: state.isTyping ? 1 : 0,
			filter: state.currentFilter
		}).done(function (res) {
			if (!res || !res.success || !res.data) {
				schedulePoll(15000);
				return;
			}
			var data = res.data;
			state.backoffMs = 2000;

			if (data.list !== null && typeof data.list !== 'undefined') {
				$('#lc-admin-conversations-pane').html(data.list);
				if (state.conversationId) {
					$('.lc-admin-conv-item[data-id="' + state.conversationId + '"]').addClass('active');
				}
				filterUsers();
			}
			state.fingerprint = data.fingerprint || '';

			if (gen !== state.renderGen) {
				schedulePoll(0);
				return;
			}

			var $presence = $('#lc-visitor-presence');
			if ($presence.length && typeof data.visitor_online !== 'undefined') {
				$presence
					.toggleClass('is-online', !!data.visitor_online)
					.toggleClass('is-offline', !data.visitor_online)
					.text(data.visitor_online ? 'Visitor Online' : 'Visitor Offline');
			}

			var container = messagesContainer();
			var pinned = isPinnedToBottom(container);

			if (data.messages && data.messages.length) {
				var hasIncoming = false;
				$.each(data.messages, function (_, row) {
					appendMessage(row);
					if (!row.staff && row.msg_type === 'message') {
						hasIncoming = true;
						if (document.hidden) {
							notifyDesktop('Live Chats: ' + (row.author || 'Visitor'), row.msg || 'New attachment');
						}
					}
				});
				state.lastMessageId = Math.max(state.lastMessageId, data.last_id || 0);
				if (hasIncoming) playChime();
				if (pinned) scrollToBottom(container);
			}

			setTypingDisplay(!!data.typing, data.typing_user || '');
			updateSneakPeek(data.sneak_peek || '');
			updateSeenReceipt(data.read_through || 0);

			schedulePoll(data.next_delay || 5000);
		}).fail(function () {
			state.backoffMs = Math.min(30000, state.backoffMs * 2);
			schedulePoll(state.backoffMs);
		}).always(function () {
			state.inFlight = false;
		});
	}

	/* -----------------------------------------------------------------------
	 * Init & Event Bindings
	 * -------------------------------------------------------------------- */

	$(function () {
		var $admin = $('#live-chats-admin');
		if (!$admin.length) return;

		requestNotificationPermission();
		loadCannedResponses();
		renderSoundButton();

		post('live_chats_admin_session').done(function (res) {
			if (!res || !res.success || !res.data) {
				$('#lc-sidebar-status').text(errorText(res, 'Unable to initialize inbox.'));
				return;
			}
			$('#lc-admin-name').text(res.data.full_name || '');
			$('#lc-admin-role').text(res.data.role || '');
			$('#lc-admin-avatar').text(initial(res.data.full_name));
			loadConversations().done(resumeLastConversation);
		}).fail(function () {
			$('#lc-sidebar-status').text('Error connecting to server.');
		});

		$admin
			.on('click', '.lc-admin-conv-item', function (e) {
				e.preventDefault();
				openConversation($(this).data('id'));
			})
			.on('click', '.lc-inbox-sound-btn', function () {
				state.soundEnabled = !state.soundEnabled;
				try { localStorage.setItem(SOUND_KEY, state.soundEnabled ? '1' : '0'); } catch (e) {}
				renderSoundButton();
			})
			.on('click', '.lc-filter-tab', function (e) {
				e.preventDefault();
				$('.lc-filter-tab').removeClass('active');
				$(this).addClass('active');
				state.currentFilter = $(this).data('filter') || 'all';
				filterUsers();
				state.fingerprint = '';
				loadConversations();
			})
			.on('input', '#lc-conv-search', filterUsers)
			.on('click', '.lc-composer-tab', function (e) {
				e.preventDefault();
				$('.lc-composer-tab').removeClass('active');
				$(this).addClass('active');
				var mode = $(this).data('mode');
				$('#lc-composer-msg-type').val(mode);
				var $input = $('#lc-admin-composer-input');
				if (mode === 'note') {
					$input.attr('placeholder', 'Write a private internal note (staff only)…');
					$('.lc-admin-composer-form .lc-send-btn').addClass('is-note');
				} else {
					$input.attr('placeholder', 'Type a reply (type / for canned responses)…');
					$('.lc-admin-composer-form .lc-send-btn').removeClass('is-note');
				}
				$input.trigger('focus');
			})
			.on('click', '.lc-suggestion-chip', function () {
				var $input = $('#lc-admin-composer-input');
				$input.val($(this).data('text')).trigger('focus');
				autogrow($input[0]);
			})
			.on('click', '.lc-claim-self-btn', function () {
				var $btn = $(this).prop('disabled', true);
				var cid = $btn.data('conversation-id');
				post('live_chats_admin_claim', { conversation_id: cid }).done(function (res) {
					if (!res || !res.success) {
						window.alert(errorText(res, 'Could not accept chat.'));
					}
					openConversation(cid, true);
					loadConversations();
				}).fail(function (xhr) {
					window.alert(failText(xhr, 'Could not accept chat.'));
					$btn.prop('disabled', false);
				});
			})
			.on('click', '.lc-toggle-details-btn', function () {
				var $panel = $('.lc-profile-panel');
				var willOpen = $panel.hasClass('is-hidden');
				$panel.toggleClass('is-hidden', !willOpen);
				$(this).toggleClass('active', willOpen);
				try { localStorage.setItem('lc_details_open', willOpen ? '1' : '0'); } catch (e) {}
			})
			.on('click', '.lc-profile-close-btn', function () {
				$('.lc-profile-panel').addClass('is-hidden');
				$('.lc-toggle-details-btn').removeClass('active');
				try { localStorage.setItem('lc_details_open', '0'); } catch (e) {}
			})
			.on('click', '#lc-add-tag-btn', function () {
				addTagToDOM($('#lc-new-tag-input').val());
			})
			.on('keydown', '#lc-new-tag-input', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					addTagToDOM($(this).val());
				}
			})
			.on('click', '.lc-quick-tag-btn', function () {
				addTagToDOM($(this).data('tag'));
				$(this).remove();
			})
			.on('click', '.lc-tag-remove', function () {
				$(this).closest('.lc-tag-pill').remove();
				saveTags(getCurrentTags());
			})
			.on('input', '#lc-admin-composer-input', function () {
				autogrow(this);
				handleCannedInput($(this).val());
				state.isTyping = true;
				window.clearTimeout(state.typingTimer);
				state.typingTimer = window.setTimeout(function () {
					state.isTyping = false;
				}, 3500);
			})
			.on('keydown', '#lc-admin-composer-input', function (e) {
				if (e.key === 'Enter' && !e.shiftKey && !TOUCH && !$('#lc-canned-autocomplete:visible').length) {
					e.preventDefault();
					sendMessage($(this).closest('form')[0]);
				}
			})
			.on('click', '.lc-canned-item', function () {
				var $input = $('#lc-admin-composer-input');
				$input.val($(this).data('content')).trigger('focus');
				autogrow($input[0]);
				hideCannedAutocomplete();
			})
			.on('click', '.lc-emoji-btn', function (e) {
				e.preventDefault();
				$admin.find('.lc-emoji-picker').toggle();
			})
			.on('click', '.lc-emoji-item', function () {
				var $input = $('#lc-admin-composer-input');
				insertAtCursor($input[0], $(this).text());
				autogrow($input[0]);
				$admin.find('.lc-emoji-picker').hide();
				$input.trigger('focus');
			})
			.on('change', '.lc-file-input', function () {
				if (this.files && this.files[0]) handleFileUpload(this.files[0]);
			})
			.on('click', '.lc-preview-remove', clearAttachmentPreview)
			.on('submit', '.lc-admin-composer-form', function (e) {
				e.preventDefault();
				sendMessage(this);
			})
			.on('click', '.lc-retry-btn', function () {
				var clientId = $(this).data('pending-id');
				var entry = state.retryQueue[clientId];
				if (!entry) return;
				delete state.retryQueue[clientId];
				removePendingBubble(clientId);
				submitMessage(entry.form, entry.text, entry.att, entry.note);
			})
			.on('click', '.lc-email-transcript-btn', function () {
				var id = $(this).data('id');
				post('live_chats_admin_transcript', { id: id }).done(function (res) {
					window.alert(res && res.success ? 'Transcript emailed to visitor!' : errorText(res, 'Failed to email transcript.'));
				}).fail(function (xhr) {
					window.alert(failText(xhr, 'Failed to email transcript.'));
				});
			})
			.on('click', '.lc-close-conv-btn', function () {
				var id = $(this).data('id');
				if (!window.confirm('End this conversation?')) return;
				post('live_chats_admin_close', { conversation_id: id }).done(function (res) {
					if (res && res.success) {
						openConversation(id, true);
						loadConversations();
					} else {
						window.alert(errorText(res, 'Could not close conversation.'));
					}
				});
			})
			.on('click', '.lc-delete-chat-btn', function () {
				state.pendingDeleteId = parseInt($(this).data('id'), 10);
				$('#lc-delete-confirm-modal').prop('hidden', false).fadeIn(140);
			})
			.on('click', '#lc-confirm-delete-no', function () {
				state.pendingDeleteId = 0;
				$('#lc-delete-confirm-modal').fadeOut(140);
			})
			.on('click', '#lc-confirm-delete-yes', function () {
				if (!state.pendingDeleteId) return;
				post('live_chats_admin_delete', { conversation_id: state.pendingDeleteId }).done(function (res) {
					$('#lc-delete-confirm-modal').fadeOut(140);
					if (res && res.success) {
						state.pendingDeleteId = 0;
						state.conversationId = 0;
						state.lastMessageId = 0;
						$('#lc-admin-thread-pane').html('<div class="lc-inbox-empty"><h3>Conversation deleted</h3><p>Select another conversation from the sidebar.</p></div>');
						loadConversations();
					} else {
						$('#lc-delete-status').text(errorText(res, 'Delete failed.'));
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
			if (e.key === 'Escape') closeLightbox();
		});

		$(document).on('visibilitychange', function () {
			if (!document.hidden) schedulePoll(0);
		});

		window.addEventListener('online', function () {
			state.backoffMs = 2000;
			schedulePoll(0);
		});
	});
}(jQuery));
