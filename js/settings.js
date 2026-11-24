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

    

    // Manual Purge Cache Now Handler
    $('#mds-purge-cache-now-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $status = $('#mds-purge-cache-status');
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Purging...');
        $status.text('').hide();

        var purgeUrl = mds_rm_data.rest_url.replace('/settings', '/purge-cache');
        $.ajax({
            url: purgeUrl,
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
            success: function(res) {
                $btn.prop('disabled', false).text(originalText);
                var msg = res && res.message ? res.message : 'Cache purge completed.';
                $status.css('color', '#007017').text(msg).fadeIn();
                if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
                    window.wp.a11y.speak(msg, 'polite');
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).text(originalText);
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Failed to purge cache.';
                $status.css('color', '#d63638').text(msg).fadeIn();
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

        var sync_url = mds_rm_data.rest_url.replace('/settings', '/sync-network');
        var job_id = '';

        function runSyncBatch() {
            $.ajax({
                url: sync_url,
                method: 'POST',
                data: { job_id: job_id },
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
                success: function(res) {
                    if (res.done === false && res.job_id) {
                        job_id = res.job_id;
                        $status.text(res.message).show();
                        window.setTimeout(runSyncBatch, 50);
                        return;
                    }

                    $btn.prop('disabled', false).text('Run Manual Sync');
                    var message = res.message || 'Synchronization finished.';
                    if (res.errors && res.errors.length) {
                        message += ' ' + res.errors.slice(0, 3).join(' ');
                    }
                    $status.text(message).fadeIn();
                },
                error: function(xhr) {
                    $btn.prop('disabled', false).text('Run Manual Sync');
                    var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Sync encountered an error. Check if you are on the Hub site.';
                    $status.text('Sync stopped. Run it again to resume.').show();
                    alert(msg);
                }
            });
        }

        runSyncBatch();
    });

});
