jQuery(document).ready(function($){
    $('.mds-shortcode-copy').on('click', function(e) {
        e.preventDefault();
        var $el = $(this);
        var shortcode = $el.text();
        navigator.clipboard.writeText(shortcode).then(function() {
            $el.text('Copied!');
            setTimeout(function() { $el.text(shortcode); }, 2000);
        });
    });

    var audioUploader;

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

});
