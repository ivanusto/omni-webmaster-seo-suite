/**
 * Omni Webmaster & SEO Suite - Admin JS logic
 */

jQuery(document).ready(function($) {
    
    // ==========================================
    // 1. Google Translate API Key 測試連線
    // ==========================================
    $('#omni-btn-test-api').on('click', function(e) {
        e.preventDefault();
        
        var apiKey = $('#omni_slug_api_key').val();
        var $resultSpan = $('#omni-test-api-result');
        var $btn = $(this);
        
        if (!apiKey) {
            $resultSpan.html('<span class="dashicons dashicons-warning" style="color:#d97706; vertical-align:middle; font-size:18px;"></span> 請先輸入 API 金鑰。')
                       .removeClass('success').addClass('error')
                       .css('color', '#d97706');
            return;
        }
        
        $btn.prop('disabled', true);
        $resultSpan.text(omniWebmaster.txt_testing).removeClass('success error').css('color', '#6b7280');
        
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
                $resultSpan.text(omniWebmaster.txt_error)
                           .removeClass('success').addClass('error')
                           .css('color', '#dc2626');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // ==========================================
    // 2. 批次縮圖刪除功能 (AJAX 分頁處理)
    // ==========================================
    var totalImages = 0;
    var processedImages = 0;
    var totalDeletedFiles = 0;
    var currentPage = 1;
    var isProcessing = false;

    $('#omni-btn-delete-thumbs').on('click', function(e) {
        e.preventDefault();
        
        if (isProcessing) return;

        // 取得選擇的清理模式
        var deleteMode = $('input[name="omni_delete_mode"]:checked').val() || 'disabled_only';
        var confirmMsg = (deleteMode === 'all') ? omniWebmaster.txt_confirm_delete_all : omniWebmaster.txt_confirm_delete_selected;
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        var $btn = $(this);
        var $progressWrapper = $('#omni-cleanup-progress-wrapper');
        var $progressBarFill = $('#omni-cleanup-progress-fill');
        var $statusText = $('#omni-cleanup-status-text');
        var $percentageText = $('#omni-cleanup-percentage');
        var $logConsole = $('#omni-cleanup-log');
        
        // 重設變數與 UI
        totalImages = 0;
        processedImages = 0;
        totalDeletedFiles = 0;
        currentPage = 1;
        isProcessing = true;
        
        $btn.prop('disabled', true);
        $progressWrapper.slideDown(200);
        $progressBarFill.css('width', '0%');
        $percentageText.text('0%');
        $statusText.text(omniWebmaster.txt_deleting);

        var modeName = (deleteMode === 'all') ? '清理所有尺寸' : '僅清理停用尺寸';
        $logConsole.empty().append('<div class="omni-log-info">[資訊] 開始掃描媒體庫圖片附件並進行清理（模式：' + modeName + '）...</div>');
        
        // 開始進行批次遞迴請求
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
                        
                        // 計算進度百分比
                        var percentage = 100;
                        if (totalImages > 0) {
                            percentage = Math.min(Math.round((processedImages / totalImages) * 100), 100);
                        }
                        
                        // 更新進度條與狀態文字
                        $progressBarFill.css('width', percentage + '%');
                        $percentageText.text(percentage + '%');
                        $statusText.text('正在清理中: ' + processedImages + ' / ' + totalImages + ' 張圖片');
                        
                        // 紀錄日誌到控制台
                        if (data.processed > 0) {
                            $logConsole.append('<div class="omni-log-success">[成功] 批次 ' + currentPage + ' 處理完成：掃描 ' + data.processed + ' 張，共刪除 ' + data.deleted + ' 個縮圖檔案。</div>');
                        } else {
                            $logConsole.append('<div class="omni-log-info">[資訊] 批次 ' + currentPage + '：無縮圖需要刪除。</div>');
                        }
                        
                        // 滾動日誌到底部
                        $logConsole.scrollTop($logConsole[0].scrollHeight);
                        
                        // 判斷是否已經處理完畢
                        if (data.finished || processedImages >= totalImages) {
                            completeCleanup();
                        } else {
                            // 延遲 200 毫秒發送下一批，防止對伺服器造成瞬間連線過載
                            currentPage++;
                            setTimeout(processThumbnailBatch, 200);
                        }
                    } else {
                        $logConsole.append('<div class="omni-log-warn">[錯誤] 伺服器回傳失敗：' + response.data + '</div>');
                        $logConsole.scrollTop($logConsole[0].scrollHeight);
                        stopWithError();
                    }
                },
                error: function() {
                    $logConsole.append('<div class="omni-log-warn">[錯誤] 網路請求連線失敗，請檢查您的伺服器狀態。</div>');
                    $logConsole.scrollTop($logConsole[0].scrollHeight);
                    stopWithError();
                }
            });
        }
        
        function completeCleanup() {
            isProcessing = false;
            $btn.prop('disabled', false);
            $statusText.text(omniWebmaster.txt_complete);
            $progressBarFill.css('width', '100%');
            $percentageText.text('100%');
            $logConsole.append('<div class="omni-log-info" style="color:#22c55e; font-weight:bold;">[清理完畢] 全部圖片處理完成！總共掃描 ' + processedImages + ' 張圖片附件，累計清除了 ' + totalDeletedFiles + ' 個縮圖實體檔案。</div>');
            $logConsole.scrollTop($logConsole[0].scrollHeight);
        }
        
        function stopWithError() {
            isProcessing = false;
            $btn.prop('disabled', false);
            $statusText.text('清理程序中斷');
        }
    });

    // 縮圖尺寸勾選卡片背景效果連動
    $('.omni-size-card input[type="checkbox"]').on('change', function() {
        var $card = $(this).closest('.omni-size-card');
        if ($(this).is(':checked')) {
            $card.addClass('is-checked');
        } else {
            $card.removeClass('is-checked');
        }
    });

    // ==========================================
    // 3. 文章數據月報匯出功能
    // ==========================================
    var lastFetchedPosts = [];

    // 即時預覽數據
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
            showExportStatus('請選擇要匯出的月份。', 'error');
            return;
        }

        $btn.prop('disabled', true);
        $copyBtn.prop('disabled', true);
        $previewContainer.hide();
        showExportStatus(omniWebmaster.txt_loading_preview, 'info');

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
                        showExportStatus(omniWebmaster.txt_no_posts, 'info');
                        return;
                    }

                    lastFetchedPosts.forEach(function(post) {
                        var row = '<tr>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-weight: 500;">' + escapeHtml(post.date) + '</td>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-weight: 600; color: #1e293b;">' + escapeHtml(post.title) + '</td>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9;">' + escapeHtml(post.topics) + '</td>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9;"><a href="' + encodeURI(post.link) + '" target="_blank" style="color: #0f766e; text-decoration: underline;">查看文章</a></td>' +
                            '<td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: right; font-weight: 600; color: #0d9488;">' + escapeHtml(post.views.toLocaleString()) + '</td>' +
                            '</tr>';
                        $tableBody.append(row);
                    });

                    $previewContainer.show();
                    $copyBtn.prop('disabled', false);
                    hideExportStatus();
                } else {
                    showExportStatus('載入失敗：' + response.data, 'error');
                }
            },
            error: function() {
                showExportStatus('網路錯誤，無法載入預覽數據。', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // 下載 CSV 檔案 (Excel 格式)
    $('#omni-btn-download-csv').on('click', function(e) {
        e.preventDefault();
        var month = $('#omni_export_month').val();
        var viewsMeta = $('#omni_export_views_meta').val();

        if (!month) {
            alert('請選擇要匯出的月份。');
            return;
        }

        var downloadUrl = omniWebmaster.ajax_url.replace('admin-ajax.php', 'admin-post.php') + 
            '?action=omni_export_csv' +
            '&export_month=' + encodeURIComponent(month) +
            '&views_meta_key=' + encodeURIComponent(viewsMeta) +
            '&_wpnonce=' + omniWebmaster.export_nonce;

        window.location.href = downloadUrl;
    });

    // 一鍵複製到剪貼簿 (相容 Google Sheets & Excel)
    $('#omni-btn-copy-clipboard').on('click', function(e) {
        e.preventDefault();
        if (lastFetchedPosts.length === 0) return;

        var tsv = '日期\t文章標題\t切角/主題\t連結\t瀏覽量\r\n';
        lastFetchedPosts.forEach(function(post) {
            tsv += post.date + '\t' + post.title + '\t' + post.topics + '\t' + post.link + '\t' + post.views + '\r\n';
        });

        copyTextToClipboard(tsv, function() {
            alert('文章數據已複製到剪貼簿！可直接貼上至 Google 試算表或 Excel 中。');
        }, function() {
            alert('複製失敗，請手動選取表格複製。');
        });
    });

    // 狀態訊息控制
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
    // 4. 一鍵清除 oEmbed 快取
    // ==========================================
    $('#omni-btn-clear-oembed').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $result = $('#omni-oembed-clear-result');
        
        if (!confirm('您確定要清除全站所有的 oEmbed 預覽卡片快取嗎？這會強制 WordPress 在頁面加載時重新抓取嵌入內容的預覽卡片（此動作不會刪除任何文章，僅重設快取）。')) {
            return;
        }
        
        $btn.prop('disabled', true);
        $result.text('正在清理快取中...').css('color', '#6b7280').show();
        
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
                $result.text('連線錯誤，請重試。').css('color', '#dc2626');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // ==========================================
    // 首頁 Meta 標籤：og:image 媒體庫選取器
    // ==========================================
    var ogMediaFrame = null;
    $('#omni-btn-select-og-image').on('click', function(e) {
        e.preventDefault();

        if (typeof wp === 'undefined' || !wp.media) {
            alert('媒體庫載入失敗，請直接貼上圖片網址。');
            return;
        }

        // 重複點擊時重用同一個 media frame
        if (ogMediaFrame) {
            ogMediaFrame.open();
            return;
        }

        ogMediaFrame = wp.media({
            title: '選擇社群分享圖（建議 1200 × 630）',
            button: { text: '使用這張圖片' },
            library: { type: 'image' },
            multiple: false
        });

        ogMediaFrame.on('select', function() {
            var attachment = ogMediaFrame.state().get('selection').first().toJSON();
            // 優先使用 large 尺寸避免原圖過大，無 large 時退回原圖
            var url = (attachment.sizes && attachment.sizes.large) ? attachment.sizes.large.url : attachment.url;
            $('#omni_og_default_image').val(url);
            $('#omni-og-image-preview').attr('src', url).show();
        });

        ogMediaFrame.open();
    });

    // og:image 網址手動修改時同步預覽
    $('#omni_og_default_image').on('change input', function() {
        var url = $(this).val().trim();
        if (url) {
            $('#omni-og-image-preview').attr('src', url).show();
        } else {
            $('#omni-og-image-preview').hide();
        }
    });

    // ==========================================
    // 首頁 Meta Description 字數即時計算
    // ==========================================
    function updateMetaDescCount() {
        var len = $('#omni_home_meta_description').val().length;
        var $count = $('#omni-meta-desc-count');
        $count.text(len);
        // 90-160 字元為建議區間，超出顯示警告色
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
