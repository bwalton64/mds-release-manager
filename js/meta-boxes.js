jQuery(document).ready(function($){
    var mediaUploader, audioUploader, artistUploader;
    $("#mds_upload_artist_image_btn").click(function(e) {
        e.preventDefault();
        if (artistUploader) { artistUploader.open(); return; }
        artistUploader = wp.media({ title: "Choose Artist Image", button: { text: "Choose Image" }, multiple: false });
        artistUploader.on("select", function() {
            var attachment = artistUploader.state().get("selection").first().toJSON();
            $("#artist_featured_image").val(attachment.url);
            $("#artist_featured_image_preview_img").attr('src', attachment.url);
        });
        artistUploader.open();
    });

    $("#mds_upload_audio_btn").click(function(e) {
        e.preventDefault();
        if (audioUploader) { audioUploader.open(); return; }
        audioUploader = wp.media({ title: "Choose Audio Preview", library: { type: 'audio' }, multiple: false });
        audioUploader.on("select", function() {
            var attachment = audioUploader.state().get("selection").first().toJSON();
            $("#audio_preview_url").val(attachment.url);
        });
        audioUploader.open();
    });

    $("#mds_add_streaming_row").on("click", function(e) {
        e.preventDefault();
        var row = '<div class="mds-streaming-row"><select name="mds_streaming_provider[]">' + mds_global_providers_html + '</select>' +
                  '<input type="url" name="mds_streaming_url[]" value="" placeholder="https://..." />' +
                  '<button type="button" class="button mds-remove-row">Remove</button></div>';
        $("#mds_streaming_links_wrapper").append(row);
    });

    $(document).on("click", ".mds-remove-row", function(e) { e.preventDefault(); $(this).closest(".mds-streaming-row").remove(); });

    // WooCommerce Cross-Site Linker
    if ( $.fn.selectWoo || $.fn.select2 ) {
        var selectMethod = $.fn.selectWoo ? 'selectWoo' : 'select2';
        $('.mds-wc-select2').each(function() {
            var $el = $(this);
            var storeId = $el.data('store-id');
            $el[selectMethod]({
                ajax: {
                    url: mds_rm_data.wc_search_url,
                    dataType: 'json',
                    delay: 250,
                    beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
                    data: function(params) {
                        return { q: params.term, store_id: storeId };
                    },
                    processResults: function(data) {
                        return { results: data.results };
                    }
                },
                minimumInputLength: 3
            });
        });
    }

    $('.mds-wc-generate-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var storeId = $btn.data('store-id');
        var postId = $btn.data('post-id');
        var $status = $btn.siblings('.mds-wc-generate-status');
        
        $btn.prop('disabled', true).text('Generating...');
        $status.hide();
        
        $.ajax({
            url: mds_rm_data.wc_generate_url,
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', mds_rm_data.nonce); },
            data: { store_id: storeId, release_id: postId },
            success: function(res) {
                if ( res.success ) {
                    $status.text('Product Created! (Click standard WP Update button to save mapping)').fadeIn();
                    var $select = $btn.siblings('.mds-wc-select2');
                    var option = new Option(res.product_title + ' (ID: ' + res.product_id + ')', res.product_id, true, true);
                    $select.append(option).trigger('change');
                    $btn.hide();
                } else {
                    $btn.prop('disabled', false).text('Generate New');
                    alert('Error creating product: ' + (res.message || 'Unknown error'));
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).text('Generate New');
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Error communicating with store.';
                alert(msg);
            }
        });
    });
});
