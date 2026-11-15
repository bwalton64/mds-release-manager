jQuery(document).ready(function($) {
    $('#mds-settings-tabs a').on('click', function(e) {
        e.preventDefault();
        $('#mds-settings-tabs a').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        var target = $(this).attr('href');
        $('.mds-tab-pane').removeClass('active');
        $(target).addClass('active');
        if (history.pushState) {
            history.pushState(null, null, target);
        } else {
            location.hash = target;
        }

        
    });

    if (window.location.hash && $(window.location.hash).length) {
        $('#mds-settings-tabs a[href="' + window.location.hash + '"]').click();
    }

    // Save Settings Form
    $('#mds-settings-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('.mds-save-settings-btn');
        $btn.prop('disabled', true).text('Saving...');
        $.ajax({
            url: mds_rm_data.rest_url,
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
            data: new FormData($('#mds-settings-form')[0]),
            processData: false,
            contentType: false,
            success: function(res) {
                $btn.prop('disabled', false).text('Save Settings');
                if (res.success) {
                    $('.mds-tab-pane.active .mds-save-status').fadeIn().delay(3000).fadeOut();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Save Settings');
                alert('Error saving settings.');
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
            opacity: 0.85
        });
    }

    $('#mds_add_provider_btn').on('click', function(e) {
        e.preventDefault();
        var row = '<div class="mds-provider-row" style="display:flex; gap:10px; align-items:center; margin-bottom:10px; background:#f9f9f9; padding:10px; border:1px solid #ddd;">' +
            '<span class="dashicons dashicons-menu" style="cursor:move; color:#999;"></span>' +
            '<input type="text" name="mds_rm_providers[name][]" value="" placeholder="Provider Name" />' +
            '<div class="mds-icon-input-wrapper"><input type="text" name="mds_rm_providers[icon][]" value="" placeholder="fa-solid fa-link" class="mds-icon-input" /></div>' +
            '<input type="text" name="mds_rm_providers[color][]" value="" placeholder="Hex Color" class="mds-color-picker-dynamic" />' +
            '<button type="button" class="button mds-remove-provider" style="color:#d63638; border-color:#d63638;">Remove</button></div>';
        var $row = $(row);
        $('#mds_providers_repeater').append($row);
        $row.find('.mds-color-picker-dynamic').wpColorPicker();
        if ($.fn.iconpicker) {
            $row.find('.mds-icon-input').iconpicker({ placement: 'bottomRight', hideOnSelect: true, animation: false });
        }
    });

    $(document).on('click', '.mds-remove-provider', function(e) {
        e.preventDefault();
        $(this).closest('.mds-provider-row').remove();
    });
});
