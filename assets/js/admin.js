jQuery(document).ready(function($) {
    'use strict';

    // Handle tab navigation without page reload for a smoother feel
    $('.nav-tab-wrapper a').on('click', function(e) {
        // We still let the default action happen to manage browser history,
        // but you could enhance this to load content via AJAX if preferred.
    });

    // Handle LESS file saving
    $('.save-less-btn').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $container = $button.closest('.less-editor-container');
        var $spinner = $container.find('.spinner');
        var $status = $container.find('.save-status');
        var filename = $button.data('filename');
        var content = $container.find('.less-editor').val();
        
        $status.text('').removeClass('success error');
        $spinner.addClass('is-active');
        $button.prop('disabled', true);

        $.ajax({
            url: lsThemeEditor.ajax_url,
            type: 'POST',
            data: {
                action: 'ls_save_less_file',
                nonce: lsThemeEditor.nonce,
                filename: filename,
                content: content
            },
            success: function(response) {
                if (response.success) {
                    $status.text(response.data.message).addClass('success');
                    // Enable restore button if it was disabled
                    $container.find('.restore-less-btn').prop('disabled', false);
                } else {
                    $status.text(response.data.message).addClass('error');
                }
            },
            error: function() {
                $status.text('An unknown error occurred.').addClass('error');
            },
            complete: function() {
                $spinner.removeClass('is-active');
                $button.prop('disabled', false);
                setTimeout(function() {
                    $status.fadeOut(400, function() {
                        $(this).text('').show();
                    });
                }, 3000);
            }
        });
    });

    // Handle LESS backup restoration
    $('.restore-less-btn').on('click', function(e) {
        e.preventDefault();

        if ( ! confirm( 'Are you sure you want to restore the backup? This will overwrite your current changes in this file.' ) ) {
            return;
        }

        var $button = $(this);
        var $container = $button.closest('.less-editor-container');
        var $spinner = $container.find('.spinner');
        var $status = $container.find('.save-status');
        var $editor = $container.find('.less-editor');
        var filename = $button.data('filename');

        $status.text('').removeClass('success error');
        $spinner.addClass('is-active');
        $button.prop('disabled', true);
        $container.find('.save-less-btn').prop('disabled', true);

        $.ajax({
            url: lsThemeEditor.ajax_url,
            type: 'POST',
            data: {
                action: 'ls_restore_less_backup',
                nonce: lsThemeEditor.nonce,
                filename: filename
            },
            success: function(response) {
                if (response.success) {
                    $status.text(response.data.message).addClass('success');
                    $editor.val(response.data.content);
                } else {
                    $status.text(response.data.message).addClass('error');
                }
            },
            error: function() {
                $status.text('An unknown error occurred.').addClass('error');
            },
            complete: function() {
                $spinner.removeClass('is-active');
                $button.prop('disabled', false);
                $container.find('.save-less-btn').prop('disabled', false);
                 setTimeout(function() {
                    $status.fadeOut(400, function() {
                        $(this).text('').show();
                    });
                }, 3000);
            }
        });
    });
});
