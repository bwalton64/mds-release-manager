jQuery(document).ready(function ($) {
	var speak = (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function')
		? window.wp.a11y.speak
		: function () {};

	// Shortcode copy helper
	$('.mds-shortcode-copy').on('click', function (e) {
		e.preventDefault();
		var $el = $(this);
		var shortcode = $el.text();

		function showCopyError(error) {
			console.error('Could not copy shortcode:', error);
			$el.text('Copy failed');
			setTimeout(function () { $el.text(shortcode); }, 2000);
		}

		try {
			if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
				throw new Error('Clipboard API is unavailable.');
			}
			navigator.clipboard.writeText(shortcode).then(function () {
				$el.text('Copied!');
				speak('Shortcode copied to clipboard.', 'polite');
				setTimeout(function () { $el.text(shortcode); }, 2000);
			}).catch(showCopyError);
		} catch (error) {
			showCopyError(error);
		}
	});

	// Live UPC/EAN digit counter & format badge
	function updateUpcBadge() {
		var $input = $('#upc_ean');
		var $badge = $('#mds-upc-badge');
		if (!$input.length || !$badge.length) {
			return;
		}
		var digits = String($input.val() || '').replace(/\D+/g, '');
		var len = digits.length;
		$badge.removeClass('is-valid is-warn');
		if (len === 0) {
			$badge.text('');
		} else if (len === 12) {
			$badge.text('12 digits (UPC-A)').addClass('is-valid');
		} else if (len === 13) {
			$badge.text('13 digits (EAN-13)').addClass('is-valid');
		} else {
			$badge.text(len + (len === 1 ? ' digit' : ' digits')).addClass('is-warn');
		}
	}
	$('#upc_ean').on('input change', updateUpcBadge);
	updateUpcBadge();

	// Audio preview uploader + inline player sync
	var audioUploader;
	function syncAudioPreview() {
		var url = String($('#audio_preview_url').val() || '').trim();
		var $wrap = $('#mds-audio-player-preview');
		var $audio = $('#mds-audio-preview-el');
		var $clear = $('#mds_clear_audio_btn');
		if (!$wrap.length) {
			return;
		}
		if (url && /^https?:\/\//i.test(url)) {
			$audio.attr('src', url);
			$wrap.prop('hidden', false);
			$clear.prop('hidden', false);
		} else {
			$audio.attr('src', '');
			$wrap.prop('hidden', true);
			$clear.prop('hidden', url === '');
		}
	}

	$('#mds_upload_audio_btn').on('click', function (e) {
		e.preventDefault();
		if (audioUploader) {
			audioUploader.open();
			return;
		}
		audioUploader = wp.media({ title: 'Choose Audio Preview', library: { type: 'audio' }, multiple: false });
		audioUploader.on('select', function () {
			var attachment = audioUploader.state().get('selection').first().toJSON();
			$('#audio_preview_url').val(attachment.url).trigger('change');
			speak('Audio preview selected.', 'polite');
		});
		audioUploader.open();
	});

	$('#mds_clear_audio_btn').on('click', function (e) {
		e.preventDefault();
		$('#audio_preview_url').val('').trigger('change').focus();
		speak('Audio preview cleared.', 'polite');
	});

	$('#audio_preview_url').on('input change', syncAudioPreview);

	// Sync inline "Test URL" anchor buttons for both Streaming and Buy Now rows
	function syncTestUrlButton($input) {
		var val = String($input.val() || '').trim();
		var $btn = $input.siblings('.mds-test-url-btn');
		if (!$btn.length) {
			return;
		}
		if (val && /^https?:\/\//i.test(val)) {
			$btn.attr('href', val).prop('hidden', false);
		} else {
			$btn.attr('href', '#').prop('hidden', true);
		}
	}
	$(document).on('input change', 'input[name="mds_streaming_url[]"], input[name="mds_buy_now_url[]"]', function () {
		syncTestUrlButton($(this));
		updateRoutingPreview();
	});

	// Streaming links repeater
	function updateStreamingEmptyState() {
		var count = $('#mds_streaming_links_wrapper .mds-streaming-row').length;
		$('#mds-streaming-empty').prop('hidden', count > 0);
	}

	$('#mds_add_streaming_row').on('click', function (e) {
		e.preventDefault();
		var row = '<div class="mds-streaming-row">' +
			'<select name="mds_streaming_provider[]" aria-label="Streaming provider">' + (window.mds_global_providers_html || '') + '</select>' +
			'<input type="url" name="mds_streaming_url[]" value="" placeholder="https://..." aria-label="Streaming URL" />' +
			'<a class="button button-secondary mds-test-url-btn" href="#" target="_blank" rel="noopener noreferrer" hidden aria-label="Open streaming URL in a new tab">' +
			'<span class="dashicons dashicons-external" aria-hidden="true"></span><span class="screen-reader-text">Test link</span></a>' +
			'<button type="button" class="button mds-remove-row" aria-label="Remove streaming link">Remove</button>' +
			'</div>';
		var $row = $(row);
		$('#mds_streaming_links_wrapper').append($row);
		updateStreamingEmptyState();
		$row.find('select').focus();
		speak('Streaming link row added.', 'polite');
	});

	$(document).on('click', '.mds-remove-row', function (e) {
		e.preventDefault();
		$(this).closest('.mds-streaming-row').remove();
		updateStreamingEmptyState();
		speak('Streaming link removed.', 'polite');
	});

	// Buy Now multi-format repeater
	function reindexBuyNowPrimary() {
		var $rows = $('#mds_buy_now_links_wrapper .mds-buy-now-row');
		var hasChecked = false;
		$rows.each(function (index) {
			var $row = $(this);
			var $radio = $row.find('input[name="mds_buy_now_primary"]');
			$radio.val(String(index));
			var checked = $radio.prop('checked');
			if (checked) {
				hasChecked = true;
			}
			$row.toggleClass('is-primary', checked);
		});
		if (!hasChecked && $rows.length) {
			$rows.first().find('input[name="mds_buy_now_primary"]').prop('checked', true);
			$rows.first().addClass('is-primary');
		}
		updateRoutingPreview();
	}

	$(document).on('change', 'input[name="mds_buy_now_primary"]', reindexBuyNowPrimary);

	$('#mds_add_buy_now_row').on('click', function (e) {
		e.preventDefault();
		var tpl = document.getElementById('mds_buy_now_row_template');
		if (!tpl) {
			return;
		}
		var index = $('#mds_buy_now_links_wrapper .mds-buy-now-row').length;
		var html = tpl.innerHTML.replace(/__INDEX__/g, String(index));
		var $row = $(html);
		$('#mds_buy_now_links_wrapper').append($row);
		reindexBuyNowPrimary();
		$row.find('select').focus();
		speak('Media format row added.', 'polite');
	});

	$(document).on('click', '.mds-remove-buy-now-row', function (e) {
		e.preventDefault();
		$(this).closest('.mds-buy-now-row').remove();
		reindexBuyNowPrimary();
		speak('Media format row removed.', 'polite');
	});

	// Live Page Routing destination preview
	function getPrimaryBuyNowUrl() {
		var url = '';
		$('#mds_buy_now_links_wrapper .mds-buy-now-row').each(function () {
			if ($(this).find('input[name="mds_buy_now_primary"]').prop('checked')) {
				url = String($(this).find('input[name="mds_buy_now_url[]"]').val() || '').trim();
			}
		});
		if (!url) {
			url = String($('#mds_buy_now_links_wrapper input[name="mds_buy_now_url[]"]').first().val() || '').trim();
		}
		return url;
	}

	function updateRoutingPreview() {
		var $select = $('#mds_release_routing_mode');
		var $label = $('#mds-routing-resolved-label');
		var $status = $('#mds-routing-destination-status');
		if (!$select.length || !$label.length) {
			return;
		}
		var mode = String($select.val() || 'inherit');
		$('#mds_release_routing_url_wrap').prop('hidden', mode !== 'custom_url');

		var artistHome = String($select.closest('.mds-editor-box').data('artist-homepage') || '').trim();
		if (mode === 'fallback') {
			$label.text('Release Fallback Page');
		} else if (mode === 'custom_url') {
			var customUrl = String($('#mds_release_routing_url').val() || '').trim();
			$label.text(customUrl ? 'Custom URL (' + customUrl + ')' : 'Custom URL (enter a valid URL above)');
		} else if (mode === 'buy_now') {
			var buyNow = getPrimaryBuyNowUrl();
			$label.text(buyNow ? 'Primary Buy Now URL (' + buyNow + ')' : 'Release Fallback Page (no Buy Now URL configured)');
		} else if (mode === 'artist_website') {
			$label.text(artistHome ? 'Artist Website (' + artistHome + ')' : 'Release Fallback Page (Artist has no website configured)');
		} else if (mode === 'inherit') {
			$label.text($status.data('initial-label') || 'Inherit Artist Policy');
		} else {
			$label.text($select.find('option:selected').text());
		}
	}

	$('#mds_release_routing_mode').on('change', updateRoutingPreview);
	$('#mds_release_routing_url').on('input change', updateRoutingPreview);
});
