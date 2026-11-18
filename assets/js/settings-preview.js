/**
 * Live Chats — Real-time Settings Widget Customizer Preview
 */
(function ($) {
	'use strict';

	function hexToRgb(hex) {
		hex = String(hex || '').replace('#', '');
		if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
		if (hex.length !== 6) return [37, 99, 235];
		return [
			parseInt(hex.substr(0, 2), 16) || 0,
			parseInt(hex.substr(2, 2), 16) || 0,
			parseInt(hex.substr(4, 2), 16) || 0
		];
	}

	function adjustBrightness(hex, steps) {
		return '#' + hexToRgb(hex).map(function (c) {
			var s = Math.max(0, Math.min(255, c + steps)).toString(16);
			return s.length === 1 ? '0' + s : s;
		}).join('');
	}

	function contrastColor(hex) {
		var rgb = hexToRgb(hex);
		var lum = 0.2126 * (rgb[0] / 255) + 0.7152 * (rgb[1] / 255) + 0.0722 * (rgb[2] / 255);
		return lum > 0.55 ? '#0f172a' : '#ffffff';
	}

	function updatePreview() {
		var hex = $('#lc-primary-color-hex').val() || '#2563eb';
		if (!/^#[0-9a-f]{6}$/i.test(hex)) {
			hex = $('#lc-primary-color-picker').val() || '#2563eb';
		}

		var light = adjustBrightness(hex, 28);
		var grad = 'linear-gradient(135deg, ' + hex + ' 0%, ' + light + ' 100%)';
		var txt = contrastColor(hex);

		$('#lc-mockup-hdr, #lc-mockup-launcher').css({ background: grad, color: txt });

		var mode = $('input[name="live_chats_widget_mode"]:checked').val() || 'hub';
		var showHome = $('#lc-toggle-home-tab').is(':checked');

		$('.lc-mode-card').removeClass('active');
		$('input[name="live_chats_widget_mode"]:checked').closest('.lc-mode-card').addClass('active');

		if (mode === 'direct') {
			$('#lc-hub-toggles').slideUp(150);
			$('#lc-preview-mode-tag').text('Direct Live Chat');
			$('#lc-mockup-home, #lc-mockup-nav').hide();
			$('#lc-mockup-direct').show();
		} else {
			$('#lc-hub-toggles').slideDown(150);
			$('#lc-preview-mode-tag').text('Self-Service Hub');
			$('#lc-mockup-nav').show();
			$('#lc-mockup-home').toggle(showHome);
			$('#lc-mockup-direct').toggle(!showHome);
		}

		$('#lc-mockup-title').text($('#lc-brand-title-input').val() || 'Support');
		$('#lc-mockup-greeting').text($('#lc-greeting-title-input').val() || 'Hi there 👋');
		$('#lc-mockup-desc').text($('#lc-greeting-desc-input').val() || '');
		$('#lc-mockup-card-title').text($('#lc-action-title-input').val() || 'Send us a message');
		$('#lc-mockup-card-sub').text($('#lc-action-sub-input').val() || '');

		var pos = $('#lc-widget-pos-select').val() || 'right';
		$('#lc-mockup-launcher-row').css('justify-content', pos === 'left' ? 'flex-start' : 'flex-end');

		$('.lc-preset-chip').each(function () {
			$(this).toggleClass('active', String($(this).data('color')).toLowerCase() === hex.toLowerCase());
		});
	}

	$(function () {
		if (!$('#lc-customizer-form').length) return;

		$('input[name="live_chats_widget_mode"], #lc-toggle-home-tab, #lc-widget-pos-select').on('change', updatePreview);
		$('#lc-brand-title-input, #lc-greeting-title-input, #lc-greeting-desc-input, #lc-action-title-input, #lc-action-sub-input').on('input', updatePreview);

		$('#lc-primary-color-picker').on('input change', function () {
			$('#lc-primary-color-hex').val($(this).val());
			updatePreview();
		});

		$('#lc-primary-color-hex').on('input change', function () {
			var v = $(this).val();
			if (/^#[0-9a-f]{6}$/i.test(v)) {
				$('#lc-primary-color-picker').val(v);
				updatePreview();
			}
		});

		$('.lc-preset-chip').on('click', function () {
			var c = $(this).data('color');
			$('#lc-primary-color-picker').val(c);
			$('#lc-primary-color-hex').val(c);
			updatePreview();
		});

		updatePreview();
	});
}(jQuery));
