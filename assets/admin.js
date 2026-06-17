jQuery(function ($) {
    'use strict';

    const $postType = $('#wptcg_post_type');
    const $imageMode = $('#wptcg_featured_image_mode');
    const $selectedImageSettings = $('#wptcg-selected-image-settings');
    const $thumbnailWarning = $('#wptcg-thumbnail-warning');
    const $imageId = $('#wptcg_featured_image_id');
    const $preview = $('#wptcg-image-preview');
    let mediaFrame;

    function updatePostTypeOptions() {
        const postType = $postType.val();
        const supportsThumbnail = String($postType.find(':selected').data('supports-thumbnail')) === '1';

        $('.wptcg-taxonomy-panel').hide();
        $('.wptcg-taxonomy-panel[data-post-type="' + postType + '"]').show();
        $thumbnailWarning.toggle(!supportsThumbnail);
    }

    function updateImageOptions() {
        $selectedImageSettings.toggle($imageMode.val() === 'selected');
    }

    $('#wptcg-select-image').on('click', function (event) {
        event.preventDefault();

        if (mediaFrame) {
            mediaFrame.open();
            return;
        }

        mediaFrame = wp.media({
            title: wptcgAdmin.frameTitle,
            button: {
                text: wptcgAdmin.buttonLabel
            },
            library: {
                type: 'image'
            },
            multiple: false
        });

        mediaFrame.on('select', function () {
            const attachment = mediaFrame.state().get('selection').first().toJSON();
            const previewUrl = attachment.sizes && attachment.sizes.medium
                ? attachment.sizes.medium.url
                : attachment.url;

            $imageId.val(attachment.id);
            $preview.html($('<img>', {
                src: previewUrl,
                alt: attachment.alt || attachment.title || ''
            }));
        });

        mediaFrame.open();
    });

    $('#wptcg-remove-image').on('click', function (event) {
        event.preventDefault();
        $imageId.val('');
        $preview.empty();
    });

    $postType.on('change', updatePostTypeOptions);
    $imageMode.on('change', updateImageOptions);

    updatePostTypeOptions();
    updateImageOptions();
});
