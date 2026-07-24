/**
 * Omni Webmaster & SEO Suite - Admin JS logic
 */

jQuery(document).ready(function($) {

    // ==========================================
    // 1. Google Translate API key connection test
    // ==========================================
    $('#omni-btn-test-api').on('click', function(e) {
        e.preventDefault();

        var apiKey = $('#omni_slug_api_key').val();
        var $resultSpan = $('#omni-test-api-result');
        var $btn = $(this);

        if (!apiKey) {
            $resultSpan.html('<span class="dashicons dashicons-warning" style="color:#d97706; vertical-align:middle; font-size:18px;"></span> ' + ((window.omniL10n && omniL10n.enterApiKeyFirst) || 'Please enter an API key first.'))
                       .removeClass('success').addClass('error')
                       .css('color', '#d97706');
            return;
        }

        $btn.prop('disabled', true);
        $resultSpan.text((window.omniL10n && omniL10n.testing) || 'Testing connection...').removeClass('success error').css('color', '#6b7280');

        $.ajax({
            url: omniWebmaster.ajax_url,
            type: 'POST',
            data: {
                action: 'omni_test_translation_api',
                api_key: apiKey,
                nonce: omniWebmaster.slug_nonce
            },
            success: function(response) {
                if (response.success) {
                    $resultSpan.text(response.data)
                               .removeClass('error').addClass('success')
                               .css('color', '#059669');
                } else {
                    $resultSpan.text(response.data)
                               .removeClass('success').addClass('error')
                               .css('color', '#dc2626');
                }
            },
            error: function() {
                $resultSpan.text((window.omniL10n && omniL10n.requestError) || 'An error occurred. Please try again.')
                           .removeClass('success').addClass('error')
                           .css('color', '#dc2626');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // ==========================================
    // 2. Batch thumbnail deletion (paged AJAX processing)
    // ==========================================
    var totalImages = 0;
    var processedImages = 0;
    var totalDeletedFiles = 0;
    var currentPage = 1;
    var isProcessing = false;

    $('#omni-btn-delete-thumbs').on('click', function(e) {
        e.preventDefault();

        if (isProcessing) return;

        // Get the selected cleanup mode
        var deleteMode = $('input[name="omni_delete_mode"]:checked').val() || 'disabled_only';
        var confirmMsg = (deleteMode === 'all')
            ? ((window.omniL10n && omniL10n.confirmDeleteAll) || '[Warning] Are you sure you want to delete ALL thumbnails? This removes every image size variant and may cause broken or slow-loading images on the front end. This action cannot be undone!')
            : ((window.omniL10n && omniL10n.confirmDeleteSelected) || 'Are you sure you want to delete the thumbnail sizes checked as disabled? Only the sizes selected for disabling will be deleted; all other sizes are safely kept. This action cannot be undone!');

        if (!confirm(confirmMsg)) {
            return;
        }

        var $btn = $(this);
        var $progressWrapper = $('#omni-cleanup-progress-wrapper');
        var $progressBarFill = $('#omni-cleanup-progress-fill');
        var $statusText = $('#omni-cleanup-status-text');
        var $percentageText = $('#omni-cleanup-percentage');
        var $logConsole = $('#omni-cleanup-log');

        // Reset variables and UI
        totalImages = 0;
        processedImages = 0;
        totalDeletedFiles = 0;
        currentPage = 1;
        isProcessing = true;

        $btn.prop('disabled', true);
        $progressWrapper.slideDown(200);
        $progressBarFill.css('width', '0%');
        $percentageText.text('0%');
        $statusText.text((window.omniL10n && omniL10n.deleting) || 'Batch processing in progress. Please do not close this window...');

        var modeName = (deleteMode === 'all')
            ? ((window.omniL10n && omniL10n.modeAllSizes) || 'clean all sizes')
            : ((window.omniL10n && omniL10n.modeDisabledOnly) || 'clean disabled sizes only');
        var scanStartMsg = ((window.omniL10n && omniL10n.logScanStart) || 'Scanning media library image attachments and starting cleanup (mode: %1$s)...')
            .replace('%1$s', modeName);
        $logConsole.empty().append('<div class="omni-log-info">[Info] ' + scanStartMsg + '</div>');

        // Start the recursive batch requests
        processThumbnailBatch();

        function processThumbnailBatch() {
            $.ajax({
                url: omniWebmaster.ajax_url,
                type: 'POST',
                data: {
                    action: 'omni_delete_thumbnails',
                    page: currentPage,
                    delete_mode: deleteMode,
                    nonce: omniWebmaster.thumb_nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;

                        totalImages = data.total;
                        processedImages += data.processed;
                        totalDeletedFiles += data.deleted;

                        // Calculate the progress percentage
                        var percentage = 100;
                        if (totalImages > 0) {
                            percentage = Math.min(Math.round((processedImages / totalImages) * 100), 100);
                        }

                        // Update the progress bar and status text
                        $progressBarFill.css('width', percentage + '%');
                        $percentageText.text(percentage + '%');
                        $statusText.text(((window.omniL10n && omniL10n.cleanupProgress) || 'Cleaning up: %1$s / %2$s images')
                            .replace('%1$s', processedImages)
                            .replace('%2$s', totalImages));

                        // Write log entries to the console
                        if (data.processed > 0) {
                            var batchDoneMsg = ((window.omniL10n && omniL10n.logBatchDone) || 'Batch %1$s complete: scanned %2$s images, deleted %3$s thumbnail files.')
                                .replace('%1$s', currentPage)
                                .replace('%2$s', data.processed)
                                .replace('%3$s', data.deleted);
                            $logConsole.append('<div class="omni-log-success">[Success] ' + batchDoneMsg + '</div>');
                        } else {
                            var batchEmptyMsg = ((window.omniL10n && omniL10n.logBatchEmpty) || 'Batch %1$s: no thumbnails needed deleting.')
                                .replace('%1$s', currentPage);
                            $logConsole.append('<div class="omni-log-info">[Info] ' + batchEmptyMsg + '</div>');
                        }

                        // Scroll the log to the bottom
                        $logConsole.scrollTop($logConsole[0].scrollHeight);

                        // Check whether processing is finished
                        if (data.finished || processedImages >= totalImages) {
                            completeCleanup();
                        } else {
                            // Delay the next batch by 200ms to avoid overwhelming the server with connections
                            currentPage++;
                            setTimeout(processThumbnailBatch, 200);
                        }
                    } else {
                        var serverErrMsg = ((window.omniL10n && omniL10n.logServerError) || 'Server returned an error: %1$s')
                            .replace('%1$s', response.data);
                        $logConsole.append('<div class="omni-log-warn">[Error] ' + serverErrMsg + '</div>');
                        $logConsole.scrollTop($logConsole[0].scrollHeight);
                        stopWithError();
                    }
                },
                error: function() {
                    $logConsole.append('<div class="omni-log-warn">[Error] ' + ((window.omniL10n && omniL10n.logNetworkError) || 'Network request failed. Please check your server status.') + '</div>');
                    $logConsole.scrollTop($logConsole[0].scrollHeight);
                    stopWithError();
                }
            });
        }

        function completeCleanup() {
            isProcessing = false;
            $btn.prop('disabled', false);
            $statusText.text((window.omniL10n && omniL10n.cleanupComplete) || 'Cleanup complete! All image files have been processed.');
            $progressBarFill.css('width', '100%');
            $percentageText.text('100%');
            var allCompleteMsg = ((window.omniL10n && omniL10n.logAllComplete) || 'All images processed! Scanned %1$s image attachments in total and removed %2$s thumbnail files.')
                .replace('%1$s', processedImages)
                .replace('%2$s', totalDeletedFiles);
            $logConsole.append('<div class="omni-log-info" style="color:#22c55e; font-weight:bold;">[Complete] ' + allCompleteMsg + '</div>');
            $logConsole.scrollTop($logConsole[0].scrollHeight);
        }

        function stopWithError() {
            isProcessing = false;
            $btn.prop('disabled', false);
            $statusText.text((window.omniL10n && omniL10n.cleanupAborted) || 'Cleanup process interrupted');
        }
    });

    // Sync the thumbnail size card background with its checkbox state
    $('.omni-size-card input[type="checkbox"]').on('change', function() {
        var $card = $(this).closest('.omni-size-card');
        if ($(this).is(':checked')) {
            $card.addClass('is-checked');
        } else {
            $card.removeClass('is-checked');
        }
    });

    // ==========================================
    // 3. Monthly post data export
    // ==========================================
    var lastFetchedPosts = [];

    // Live data preview
    $('#omni-btn-preview-export').on('click', function(e) {
        e.preventDefault();
        var month = $('#omni_export_month').val();
        var viewsMeta = $('#omni_export_views_meta').val();
        var $btn = $(this);
        var $status = $('#omni-export-status-message');
        var $previewContainer = $('.omni-export-preview-container');
        var $tableBody = $('#omni-export-preview-table tbody');
        var $copyBtn = $('#omni-btn-copy-clipboard');

        if (!month) {
            showExportStatus((window.omniL10n && omniL10n.selectMonthFirst) || 'Please select a month to export.', 'error');
            return;
        }

        $btn.prop('disabled', true);
        $copyBtn.prop('disabled', true);
        $previewContainer.hide();
        showExportStatus((window.omniL10n && omniL10n.loadingPreview) || 'Loading post data, please wait...', 'info');

        $.ajax({
            url: omniWebmaster.ajax_url,
            type: 'POST',
            data: {
                action: 'omni_preview_posts_data',
                export_month: month,
                views_meta_key: viewsMeta,
                nonce: omniWebmaster.export_nonce
            },
            success: function(response) {
                if (response.success) {
                    lastFetchedPosts = response.data;
                    $tableBody.empty();

                    if (lastFetchedPosts.length === 0) {
                        showExportStatus((window.omniL10n && omniL10n.noPosts) || 'No published posts found for this month.', 'info');
                        return;
                    }

                    lastFetchedPosts.forEach(function(post) {
                        var row = '<tr>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-weight: 500;">' + escapeHtml(post.date) + '</td>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-weight: 600; color: #1e293b;">' + escapeHtml(post.title) + '</td>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9;">' + escapeHtml(post.topics) + '</td>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9;"><a href="' + encodeURI(post.link) + '" target="_blank" style="color: #0f766e; text-decoration: underline;">' + ((window.omniL10n && omniL10n.viewPost) || 'View Post') + '</a></td>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: right; font-weight: 600; color: #0d9488;">' + escapeHtml(post.views.toLocaleString()) + '</td>' +
                            '</tr>';
                        $tableBody.append(row);
                    });

                    $previewContainer.show();
                    $copyBtn.prop('disabled', false);
                    hideExportStatus();
                } else {
                    showExportStatus(((window.omniL10n && omniL10n.loadFailed) || 'Failed to load: %1$s').replace('%1$s', response.data), 'error');
                }
            },
            error: function() {
                showExportStatus((window.omniL10n && omniL10n.previewNetworkError) || 'Network error. Unable to load preview data.', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // Download the CSV file (Excel format)
    $('#omni-btn-download-csv').on('click', function(e) {
        e.preventDefault();
        var month = $('#omni_export_month').val();
        var viewsMeta = $('#omni_export_views_meta').val();

        if (!month) {
            alert((window.omniL10n && omniL10n.selectMonthFirst) || 'Please select a month to export.');
            return;
        }

        var downloadUrl = omniWebmaster.ajax_url.replace('admin-ajax.php', 'admin-post.php') +
            '?action=omni_export_csv' +
            '&export_month=' + encodeURIComponent(month) +
            '&views_meta_key=' + encodeURIComponent(viewsMeta) +
            '&_wpnonce=' + omniWebmaster.export_nonce;

        window.location.href = downloadUrl;
    });

    // One-click copy to clipboard (compatible with Google Sheets & Excel)
    $('#omni-btn-copy-clipboard').on('click', function(e) {
        e.preventDefault();
        if (lastFetchedPosts.length === 0) return;

        var tsv = [
            (window.omniL10n && omniL10n.colDate) || 'Date',
            (window.omniL10n && omniL10n.colTitle) || 'Post Title',
            (window.omniL10n && omniL10n.colTopics) || 'Angle / Topics',
            (window.omniL10n && omniL10n.colLink) || 'Link',
            (window.omniL10n && omniL10n.colViews) || 'Views'
        ].join('\t') + '\r\n';
        lastFetchedPosts.forEach(function(post) {
            tsv += post.date + '\t' + post.title + '\t' + post.topics + '\t' + post.link + '\t' + post.views + '\r\n';
        });

        copyTextToClipboard(tsv, function() {
            alert((window.omniL10n && omniL10n.copiedToClipboard) || 'Post data copied to clipboard! You can paste it directly into Google Sheets or Excel.');
        }, function() {
            alert((window.omniL10n && omniL10n.copyFailed) || 'Copy failed. Please select the table manually and copy it.');
        });
    });

    // Status message controls
    function showExportStatus(message, type) {
        var $status = $('#omni-export-status-message');
        $status.removeClass('omni-export-status-message-info omni-export-status-message-success omni-export-status-message-error')
               .addClass('omni-export-status-message-' + type)
               .text(message)
               .show();
    }

    function hideExportStatus() {
        $('#omni-export-status-message').hide();
    }

    // Helper functions
    function escapeHtml(string) {
        var entityMap = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
            '/': '&#x2F;',
            '`': '&#x60;',
            '=': '&#x3D;'
        };
        return String(string).replace(/[&<>"'`=\/]/g, function (s) {
            return entityMap[s];
        });
    }

    function copyTextToClipboard(text, onSuccess, onError) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onSuccess, function() {
                fallbackCopyText(text, onSuccess, onError);
            });
        } else {
            fallbackCopyText(text, onSuccess, onError);
        }
    }

    function fallbackCopyText(text, onSuccess, onError) {
        var $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(text).select();
        try {
            var successful = document.execCommand('copy');
            if (successful) onSuccess();
            else onError();
        } catch (err) {
            onError();
        }
        $temp.remove();
    }
    // ==========================================
    // 4. One-click oEmbed cache clearing
    // ==========================================
    $('#omni-btn-clear-oembed').on('click', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var $result = $('#omni-oembed-clear-result');

        if (!confirm((window.omniL10n && omniL10n.confirmClearOembed) || 'Are you sure you want to clear all oEmbed preview card caches for the entire site? This forces WordPress to re-fetch embed preview cards on page load (no posts are deleted; only the cache is reset).')) {
            return;
        }

        $btn.prop('disabled', true);
        $result.text((window.omniL10n && omniL10n.clearingCache) || 'Clearing cache...').css('color', '#6b7280').show();

        $.ajax({
            url: omniWebmaster.ajax_url,
            type: 'POST',
            data: {
                action: 'omni_clear_oembed_cache',
                nonce: omniWebmaster.export_nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.text(response.data).css('color', '#059669');
                } else {
                    $result.text(response.data).css('color', '#dc2626');
                }
            },
            error: function() {
                $result.text((window.omniL10n && omniL10n.connectionError) || 'Connection error. Please try again.').css('color', '#dc2626');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // ==========================================
    // Homepage meta tags: og:image media library picker
    // ==========================================
    var ogMediaFrame = null;
    $('#omni-btn-select-og-image').on('click', function(e) {
        e.preventDefault();

        if (typeof wp === 'undefined' || !wp.media) {
            alert((window.omniL10n && omniL10n.mediaLibraryError) || 'Failed to load the media library. Please paste the image URL directly.');
            return;
        }

        // Reuse the same media frame on repeated clicks
        if (ogMediaFrame) {
            ogMediaFrame.open();
            return;
        }

        ogMediaFrame = wp.media({
            title: (window.omniL10n && omniL10n.mediaFrameTitle) || 'Select a social share image (1200 × 630 recommended)',
            button: { text: (window.omniL10n && omniL10n.mediaFrameButton) || 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });

        ogMediaFrame.on('select', function() {
            var attachment = ogMediaFrame.state().get('selection').first().toJSON();
            // Prefer the large size to avoid oversized originals; fall back to the original when no large size exists
            var url = (attachment.sizes && attachment.sizes.large) ? attachment.sizes.large.url : attachment.url;
            $('#omni_og_default_image').val(url);
            $('#omni-og-image-preview').attr('src', url).show();
        });

        ogMediaFrame.open();
    });

    // Sync the preview when the og:image URL is edited manually
    $('#omni_og_default_image').on('change input', function() {
        var url = $(this).val().trim();
        if (url) {
            $('#omni-og-image-preview').attr('src', url).show();
        } else {
            $('#omni-og-image-preview').hide();
        }
    });

    // ==========================================
    // Live character count for the homepage meta description
    // ==========================================
    function updateMetaDescCount() {
        var len = $('#omni_home_meta_description').val().length;
        var $count = $('#omni-meta-desc-count');
        $count.text(len);
        // 90-160 characters is the recommended range; show a warning color outside it
        if (len > 160) {
            $count.css('color', '#dc2626');
        } else if (len >= 90) {
            $count.css('color', '#059669');
        } else {
            $count.css('color', '#6b7280');
        }
    }
    if ($('#omni_home_meta_description').length) {
        updateMetaDescCount();
        $('#omni_home_meta_description').on('input', updateMetaDescCount);
    }
});
