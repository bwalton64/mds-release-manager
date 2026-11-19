jQuery(function ($) {
    if (mdsReleaseBlockEditor || !$('.meta-box-sortables > .postbox[id^="tagsdiv-"]').length) {
        window.tagBox.init();
    }

    if (!mdsReleaseBlockEditor) {
        return;
    }

    $(document).on('input change', '#upc_ean, #release_format', function () {
        var editor = wp.data.select('core/editor');
        var meta = Object.assign({}, editor.getEditedPostAttribute('meta') || {});
        var changes = {};
        if (this.id === 'upc_ean') {
            meta.upc_ean = $(this).val();
            changes.meta = meta;
        } else {
            changes.release_format = $(this).val() ? [Number($(this).val())] : [];
        }
        wp.data.dispatch('core/editor').editPost(changes);
    });

    wp.domReady(function () {
        var editor = wp.data.dispatch('core/editor');
        var panels = editor.removeEditorPanel ? editor : wp.data.dispatch('core/edit-post');

        panels.removeEditorPanel('taxonomy-panel-release_tag');
        panels.removeEditorPanel('taxonomy-panel-release_format');
    });

    wp.hooks.addFilter('editor.preSavePost', 'mds-rm/required-release-fields', function (edits, options) {
        if (options.isAutosave) {
            return edits;
        }
        var upc = $('#upc_ean');
        var format = $('#release_format');
        if (upc.length) {
            edits.meta = Object.assign({}, wp.data.select('core/editor').getEditedPostAttribute('meta') || {}, edits.meta || {}, { upc_ean: upc.val() });
        }
        if (format.length && !format.prop('disabled')) {
            edits.release_format = format.val() ? [Number(format.val())] : [];
        }
        return edits;
    });

    wp.hooks.addAction('editor.savePost', 'mds-rm/flush-release-tags', function (post, options) {
        if (!options.isAutosave) {
            window.tagBox.flushTags($('#release_tag'), false, 1);
        }
    }, 1);
});
