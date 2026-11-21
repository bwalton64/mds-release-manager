jQuery(document).ready(function($) {
    var changes = 0;
    var savedChanges = 0;
    $('#mds-settings-form').on('input change', ':input', function() { changes++; });
    $(window).on('beforeunload', function(event) {
        if (changes !== savedChanges) {
            event.preventDefault();
            event.originalEvent.returnValue = '';
        }
    });

    // Save Settings Form
    $('#mds-settings-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('.mds-save-settings-btn');
        var submittedChanges = changes;
        $btn.prop('disabled', true).text(mds_rm_data.saving);
        $.ajax({
            url: mds_rm_data.rest_url,
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
            data: new FormData($('#mds-settings-form')[0]),
            processData: false,
            contentType: false,
            success: function(res) {
                $btn.prop('disabled', false).text(mds_rm_data.save_settings);
                if (res.success) {
                    savedChanges = submittedChanges;
                    $('.mds-tab-pane.active .mds-save-status').fadeIn().delay(3000).fadeOut();
                    if (changes !== savedChanges) alert(mds_rm_data.unsaved_after_save);
                } else {
                    alert(mds_rm_data.save_error);
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).text(mds_rm_data.save_settings);
                alert(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : mds_rm_data.save_error);
            }
        });
    });

    

    // Manual Sync Handler
    $('#mds-run-manual-sync-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $status = $('#mds-manual-sync-status');
        $btn.prop('disabled', true).text('Syncing...');
        $status.hide();

        var sync_url = mds_rm_data.rest_url.replace('/settings', '/manual-sync');

        $.ajax({
            url: sync_url,
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
            success: function(res) {
                $btn.prop('disabled', false).text('Run Manual Sync');
                if (res.success) {
                    $status.text(res.message).fadeIn();
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).text('Run Manual Sync');
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Sync encountered an error. Check if you are on the Hub site.';
                alert(msg);
            }
        });
    });

    

    

    // Toggle Bandcamp Template Field
    function toggleBandcampTemplate() {
        if ($('#mds_cb_bandcamp').is(':checked')) {
            $('#mds_bandcamp_template').prop('disabled', false).css('opacity', '1');
        } else {
            $('#mds_bandcamp_template').prop('disabled', true).css('opacity', '0.5');
        }
    }
    $('.mds-audio-player-cb').on('change', toggleBandcampTemplate);
    if ($('.mds-audio-player-cb').length) {
        toggleBandcampTemplate();
    }

    // Initialize Color Pickers, Icon Pickers & Sortable Provider Rows
    $('.mds-color-picker').wpColorPicker();
    if ($.fn.iconpicker) {
        $('.mds-icon-input').iconpicker({ placement: 'bottomRight', hideOnSelect: true, animation: false });
    }
    if ($.fn.sortable) {
        $('#mds_providers_repeater').sortable({
            handle: '.dashicons-menu',
            axis: 'y',
            opacity: 0.85,
            update: function() { $('#mds-rm-block-defaults').trigger('change'); }
        });
    }

    $('#mds_add_provider_btn').on('click', function(e) {
        e.preventDefault();
        var row = '<div class="mds-provider-row mds-provider-row-new">' +
            '<span class="dashicons dashicons-menu"></span>' +
            '<input type="text" name="mds_rm_providers[name][]" value="" />' +
            '<div class="mds-icon-input-wrapper"><input type="text" name="mds_rm_providers[icon][]" value="" placeholder="fa-solid fa-link" class="mds-icon-input" /></div>' +
            '<input type="text" name="mds_rm_providers[color][]" value="" class="mds-color-picker-dynamic" />' +
            '<button type="button" class="button mds-remove-provider"></button></div>';
        var $row = $(row);
        $row.find('input[name="mds_rm_providers[name][]"]').attr('placeholder', mds_rm_data.provider_name);
        $row.find('.mds-color-picker-dynamic').attr('placeholder', mds_rm_data.hex_color);
        $row.find('.mds-remove-provider').text(mds_rm_data.remove);
        $('#mds_providers_repeater').append($row);
        $('#mds-rm-block-defaults').trigger('change');
        $row.find('.mds-color-picker-dynamic').wpColorPicker();
        if ($.fn.iconpicker) {
            $row.find('.mds-icon-input').iconpicker({ placement: 'bottomRight', hideOnSelect: true, animation: false });
        }
    });

    $(document).on('click', '.mds-remove-provider', function(e) {
        e.preventDefault();
        $(this).closest('.mds-provider-row').remove();
        $('#mds-rm-block-defaults').trigger('change');
    });
});
