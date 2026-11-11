jQuery(document).ready(function($){
    var mediaUploader, audioUploader, artistUploader;
    $("#mds_upload_image_btn").click(function(e) {
        e.preventDefault();
        if (mediaUploader) { mediaUploader.open(); return; }
        mediaUploader = wp.media({ title: "Choose Image", multiple: false });
        mediaUploader.on("select", function() {
            var attachment = mediaUploader.state().get("selection").first().toJSON();
            $("#featured_image_url").val(attachment.url);
            $("#featured_image_preview_img").attr('src', attachment.url);
        });
        mediaUploader.open();
    });
    
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
});