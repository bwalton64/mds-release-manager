jQuery(document).ready(function($) {
    $('#mds-settings-tabs a').on('click', function(e) {
        e.preventDefault();
        $('#mds-settings-tabs a').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        var target = $(this).attr('href');
        $('.mds-tab-pane').removeClass('active');
        $(target).addClass('active');
        if(history.pushState) history.pushState(null, null, target);
        else location.hash = target;
        
        // Refresh CodeMirror when tab is shown to prevent sizing bugs
        if (target === '#tab-templates' && window.mds_editor_instance) {
            window.mds_editor_instance.refresh();
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
            url: mds_rm_data.rest_url, method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
            data: new FormData($('#mds-settings-form')[0]),
            processData: false, contentType: false,
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
    
    // Migration Script Handler
    $('#mds-run-migration-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $status = $('#mds-migration-status');
        $btn.prop('disabled', true).text('Migrating...');
        $status.hide();
        
        $.ajax({
            url: mds_rm_data.migrate_url, method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
            success: function(res) {
                $btn.prop('disabled', false).text('Sync Local Data to Global Tables');
                if (res.success) {
                    $status.text(res.message).fadeIn();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Sync Local Data to Global Tables');
                alert('Migration encountered an error.');
            }
        });
    });

    // Initialize CodeMirror and store globally
    window.mds_editor_instance = null;
    if (typeof wp !== 'undefined' && wp.codeEditor && typeof mds_cm_settings !== 'undefined') {
        window.mds_editor_instance = wp.codeEditor.initialize($('#mds_template_content'), mds_cm_settings).codemirror;
    }

    // Handle Template Selection via AJAX
    $('#mds_template_file').on('change', function(e) {
        var file = $(this).val();
        $.ajax({
            url: mds_rm_data.templates_url + '?file=' + encodeURIComponent(file), 
            method: 'GET',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
            success: function(res) {
                if (res.success) {
                    if (window.mds_editor_instance) {
                        window.mds_editor_instance.setValue(res.content);
                        window.mds_editor_instance.clearHistory();
                    } else {
                        $('#mds_template_content').val(res.content);
                    }
                }
            }
        });
    });
    
    // Trigger file load initially
    $('#mds_template_file').trigger('change');

    // Handle Template Save via AJAX
    $('#mds-template-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#mds-save-template-btn');
        $btn.prop('disabled', true).text('Saving...');
        
        var content = $('#mds_template_content').val();
        if (window.mds_editor_instance) {
            content = window.mds_editor_instance.getValue();
        }
        
        var data = {
            file: $('#mds_template_file').val(),
            content: content
        };

        $.ajax({
            url: mds_rm_data.templates_url, method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
            data: data,
            success: function(res) {
                $btn.prop('disabled', false).text('Update File');
                if (res.success) $('#mds-template-status').fadeIn().delay(3000).fadeOut();
            },
            error: function() {
                $btn.prop('disabled', false).text('Update File');
                alert('Error saving template.');
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
    if ($('.mds-audio-player-cb').length) { toggleBandcampTemplate(); }

    $('.mds-color-picker').wpColorPicker();
    if ($.fn.iconpicker) $('.mds-icon-input').iconpicker({ placement: 'bottomRight', hideOnSelect: true, animation: false });

    $('#mds_add_provider_btn').on('click', function(e) {
        e.preventDefault();
        var row = '<div class="mds-provider-row" style="display:flex; gap:10px; align-items:center; margin-bottom:10px; background:#f9f9f9; padding:10px; border:1px solid #ddd;">' +
            '<span class="dashicons dashicons-menu" style="cursor:move; color:#999;"></span>' +
            '<input type="text" name="mds_rm_providers[name][]" value="" placeholder="Provider Name" />' +
            '<div class="mds-icon-input-wrapper"><input type="text" name="mds_rm_providers[icon][]" value="" placeholder="fa-solid fa-link" class="mds-icon-input" /></div>' +
            '<input type="text" name="mds_rm_providers[color][]" value="#000000" class="mds-color-picker-dynamic" />' +
            '<button type="button" class="button mds-remove-provider" style="color:#d63638; border-color:#d63638;">Remove</button></div>';
        var $row = $(row);
        $('#mds_providers_repeater').append($row);
        $row.find('.mds-color-picker-dynamic').wpColorPicker();
        if ($.fn.iconpicker) $row.find('.mds-icon-input').iconpicker({ placement: 'bottomRight', hideOnSelect: true, animation: false });
    });

    $(document).on('click', '.mds-remove-provider', function(e){ e.preventDefault(); $(this).closest('.mds-provider-row').remove(); });
});