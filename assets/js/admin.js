(function ($) {
    'use strict';

    var SS = SitesSaver;

    // ---------- HELPERS ----------


    function ajax(action, data, onSuccess, onError) {
        data.action = action;
        data.nonce = SS.nonce;

        $.ajax({
            url: SS.ajaxUrl,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    if (onSuccess) onSuccess(res.data);
                } else {
                    if (onError) onError(res.data || { message: SS.strings.error });
                }
            },
            error: function (xhr, status, error) {
                if (onError) onError({ message: status === 'timeout' ? 'Server timed out. Processes may still be running in background.' : SS.strings.error });
            },
            timeout: 300000 // 5 minutes
        });
    }

    function updateProgress($wrap, pct, text) {
        var $p = $wrap.find('.sitessaver-progress');
        $p.show();
        $p.find('.sitessaver-progress-fill').removeClass('indeterminate').css('width', pct + '%');
        $p.find('.step-label').text(text);
        $p.find('.step-pct').text(pct + '%');
    }

    function hideProgress($wrap) {
        var $p = $wrap.find('.sitessaver-progress');
        $p.find('.sitessaver-progress-fill').removeClass('indeterminate').css('width', '0%');
        $p.hide();
    }

    function showResult($wrap, msg, isError) {
        var $r = $wrap.find('.sitessaver-result');
        $r.show();
        var $card = $r.find('.ss-result-card');
        $card.removeClass('success error').addClass(isError ? 'error' : 'success');
        $card.find('i').attr('class', isError ? 'ri-error-warning-fill' : 'ri-checkbox-circle-fill');
        $r.find('.sitessaver-result-text').html(msg); // Allow HTML for links
    }


    // ---------- EXPORT ----------

    $(document).on('submit', '#sitessaver-export-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $('#sitessaver-export-btn');

        $btn.prop('disabled', true);
        $form.find('.sitessaver-result').hide();
        updateProgress($form, 0, SS.strings.exporting);
        
        var data = {
            include_db: $form.find('[name=include_db]').is(':checked') ? 1 : 0,
            include_media: $form.find('[name=include_media]').is(':checked') ? 1 : 0,
            include_plugins: $form.find('[name=include_plugins]').is(':checked') ? 1 : 0,
            include_themes: $form.find('[name=include_themes]').is(':checked') ? 1 : 0
        };

        ajax('sitessaver_export', data, function (res) {
            var steps = res.steps;
            var uid = res.status.uid;
            var currentStep = 0;

            function runNextStep() {
                if (currentStep >= steps.length) {
                    ajax('sitessaver_get_export_status', { uid: uid }, function(finalRes) {
                        hideProgress($form);
                        var result = finalRes.status.result;
                        var dlUrl = SS.ajaxUrl + '?action=sitessaver_download_backup&file=' + encodeURIComponent(result.file) + '&nonce=' + SS.downloadNonce;
                        var msg = SS.strings.done + ' — ' + result.file + ' (' + result.size + ') <br><br>';
                        msg += '<a href="' + dlUrl + '" class="ss-download-link" target="_blank"><i class="ri-download-2-line"></i> Click here to download your backup</a>';
                        showResult($form, msg, false);
                        $btn.prop('disabled', false);
                    });
                    return;
                }

                var step = steps[currentStep];
                updateProgress($form, step.pct, step.label);

                ajax('sitessaver_export_step', { uid: uid, step_index: currentStep }, 
                    function (stepRes) {
                        if (stepRes.success) {
                            currentStep++;
                            runNextStep();
                        } else {
                            handleError(stepRes);
                        }
                    },
                    handleError
                );
            }

            function handleError(err) {
                hideProgress($form);
                showResult($form, err.message || SS.strings.error, true);
                $btn.prop('disabled', false);
            }

            runNextStep();

        }, function (err) {
            showResult($form, err.message || SS.strings.error, true);
            $btn.prop('disabled', false);
        });
    });


    // ---------- IMPORT (upload) ----------

    var $dropZone = $('#sitessaver-drop-zone');
    if ($dropZone.length) {
        $dropZone.on('dragover', function(e) {
            e.preventDefault();
            $(this).css({ 'border-color': 'var(--ss-primary)', 'background': 'rgba(var(--ss-primary-rgb), 0.05)' });
        });

        $dropZone.on('dragleave drop', function(e) {
            e.preventDefault();
            $(this).css({ 'border-color': 'var(--ss-border)', 'background': 'transparent' });
        });

        $dropZone.on('drop', function(e) {
            var files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                handleImportFile(files[0]);
            }
        });

        $dropZone.on('click', function(e) {
            if ($(e.target).closest('button').length) return;
            $('#sitessaver-import-file').click();
        });
    }

    $('#sitessaver-import-file').on('change', function () {
        if (this.files.length > 0) {
            handleImportFile(this.files[0]);
        }
    });

    function generateUploadId() {
        var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        var id = '';
        for (var i = 0; i < 16; i++) {
            id += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return id;
    }

    function handleImportFile(file) {
        if (!file.name.toLowerCase().endsWith('.zip')) {
            alert('Only ZIP files are accepted.');
            return;
        }

        if (!confirm(SS.strings.confirmRestore)) {
            return;
        }

        var $form = $('.sitessaver-wrap');
        var chunkSize = 2 * 1024 * 1024;
        var totalChunks = Math.ceil(file.size / chunkSize);
        var uploadId = generateUploadId();
        var currentChunk = 0;
        var assembledFile = '';

        $form.find('.sitessaver-result').hide();
        updateProgress($form, 0, SS.strings.importing + ' (0%)');

        function sendNextChunk() {
            if (currentChunk >= totalChunks) {
                startRestoration(assembledFile);
                return;
            }

            var start = currentChunk * chunkSize;
            var end = Math.min(start + chunkSize, file.size);
            var blob = file.slice(start, end);
            var formData = new FormData();
            formData.append('action', 'sitessaver_upload_chunk');
            formData.append('nonce', SS.nonce);
            formData.append('chunk', blob);
            formData.append('chunk_index', currentChunk);
            formData.append('total_chunks', totalChunks);
            formData.append('filename', file.name);
            formData.append('upload_id', uploadId);

            $.ajax({
                url: SS.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (res.success) {
                        if (res.data && res.data.assembled_file) {
                            assembledFile = res.data.assembled_file;
                        }
                        currentChunk++;
                        var pct = Math.round((currentChunk / totalChunks) * 100);
                        updateProgress($form, pct, SS.strings.importing + ' (' + pct + '%)');
                        sendNextChunk();
                    } else {
                        onUploadError(res.data ? res.data.message : SS.strings.error);
                    }
                },
                error: function () { onUploadError(SS.strings.error); }
            });
        }

        function onUploadError(msg) {
            ajax('sitessaver_cleanup_chunks', { upload_id: uploadId });
            hideProgress($form);
            showResult($form, msg, true);
        }

        function startRestoration(filename) {
            updateProgress($form, 100, SS.strings.restoring);
            $form.find('.sitessaver-progress-fill').addClass('indeterminate');

            ajax('sitessaver_import', { file: filename },
                function (res) {
                    hideProgress($form);
                    showResult($form, res.message || SS.strings.done, false);
                },
                function (err) {
                    hideProgress($form);
                    showResult($form, err.message || SS.strings.error, true);
                }
            );
        }

        sendNextChunk();
    }


    // ---------- RESTORE (from existing backup) ----------

    $(document).on('click', '.sitessaver-restore-btn', function () {
        var file = $(this).data('file');
        if (!confirm(SS.strings.confirmRestore)) return;

        var $form = $('.sitessaver-wrap'); // Fallback container
        updateProgress($form, 0, SS.strings.restoring);
        $form.find('.sitessaver-progress-fill').addClass('indeterminate');

        ajax('sitessaver_import', { file: file },
            function (res) {
                hideProgress($form);
                alert(res.message || SS.strings.done);
                location.reload();
            },
            function (err) {
                hideProgress($form);
                alert(err.message || SS.strings.error);
            }
        );
    });


    // ---------- LABELS ----------

    $(document).on('click', '.sitessaver-label-btn', function () {
        var file = $(this).data('file');
        var label = prompt('Enter a label for this backup:');
        if (label === null) return;

        ajax('sitessaver_add_label', { file: file, label: label }, function () {
            location.reload();
        }, function (err) {
            alert(err.message || SS.strings.error);
        });
    });


    // ---------- DELETE ----------

    $(document).on('click', '.sitessaver-delete-btn', function () {
        var file = $(this).data('file');
        if (!confirm('Are you sure you want to delete this backup?')) return;

        ajax('sitessaver_delete_backup', { file: file }, function () {
            location.reload();
        }, function (err) {
            alert(err.message || SS.strings.error);
        });
    });


    // ---------- SCHEDULE ----------

    $(document).on('submit', '#sitessaver-schedule-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var data = {
            enabled: $form.find('[name=enabled]').is(':checked') ? 1 : 0,
            frequency: $form.find('[name=frequency]').val(),
            retention: $form.find('[name=retention]').val(),
            include_db: $form.find('[name=include_db]').is(':checked') ? 1 : 0,
            include_media: $form.find('[name=include_media]').is(':checked') ? 1 : 0,
            include_plugins: $form.find('[name=include_plugins]').is(':checked') ? 1 : 0,
            include_themes: $form.find('[name=include_themes]').is(':checked') ? 1 : 0,
            storage_local: $form.find('[name=storage_local]').is(':checked') ? 1 : 0,
            storage_gdrive: $form.find('[name=storage_gdrive]').is(':checked') ? 1 : 0,
            notify_email: $form.find('[name=notify_email]').val()
        };

        ajax('sitessaver_save_schedule', data, function (res) {
            alert(res.message || SS.strings.done);
            location.reload();
        }, function (err) {
            alert(err.message || SS.strings.error);
        });
    });


    // ---------- SETTINGS ----------

    $(document).on('submit', '#sitessaver-settings-form', function (e) {
        e.preventDefault();
        ajax('sitessaver_save_settings', { gdrive_folder_id: $(this).find('[name=gdrive_folder_id]').val() }, function (res) {
            alert(res.message || SS.strings.done);
            location.reload();
        }, function (err) {
            alert(err.message || SS.strings.error);
        });
    });


    // ---------- GOOGLE DRIVE ----------

    $(document).on('click', '#sitessaver-gdrive-disconnect', function () {
        if (!confirm('Disconnect Google Drive?')) return;
        ajax('sitessaver_gdrive_disconnect', {}, function () { location.reload(); });
    });

    $(document).on('click', '.sitessaver-gdrive-upload-btn', function () {
        var file = $(this).data('file');
        var $btn = $(this);
        var jobId = generateUploadId();
        var $wrap = $('#sitessaver-backups-page'); // Main container for progress UI
        
        if (!$wrap.length) $wrap = $('.sitessaver-wrap');

        $btn.prop('disabled', true).addClass('loading');
        
        updateProgress($wrap, 0, 'Preparing upload...');

        // Start the upload process (async on server)
        ajax('sitessaver_gdrive_upload', { file: file, job_id: jobId }, function (res) {
            // This will only return when the WHOLE upload is finished
            hideProgress($wrap);
            alert(res.message || 'Uploaded!');
            $btn.prop('disabled', false).removeClass('loading');
        }, function (err) {
            hideProgress($wrap);
            alert(err.message || SS.strings.error);
            $btn.prop('disabled', false).removeClass('loading');
        });

        // Start polling for progress
        var pollInterval = setInterval(function() {
            ajax('sitessaver_get_gdrive_upload_status', { job_id: jobId }, function(res) {
                if (res.progress !== undefined) {
                    updateProgress($wrap, res.progress, 'Uploading to Drive: ' + res.progress + '%');
                    if (res.status === 'completed' || res.status === 'failed') {
                        clearInterval(pollInterval);
                        setTimeout(function() {
                            hideProgress($wrap);
                        }, 1000);
                    }
                }
            });
        }, 2000);
    });

    $(document).on('click', '#sitessaver-gdrive-refresh', function () {
        var $container = $('#sitessaver-gdrive-files');
        $container.html('<p style="padding: 24px; text-align: center;">Loading...</p>');

        ajax('sitessaver_gdrive_list', {}, function (res) {
            if (!res.files || res.files.length === 0) {
                $container.html('<p style="padding: 24px; text-align: center;">No backups found on Google Drive.</p>');
                return;
            }

            var html = '<table class="ss-table">';
            html += '<thead><tr><th>File</th><th>Size</th><th>Created</th><th style="text-align:right;">Actions</th></tr></thead><tbody>';
            res.files.forEach(function (f) {
                html += '<tr>';
                html += '<td><div class="cell-filename"><i class="ri-file-zip-line"></i> ' + f.name + '</div></td>';
                html += '<td class="cell-meta">' + f.size + '</td>';
                html += '<td class="cell-meta">' + f.created + '</td>';
                html += '<td><div class="action-btns" style="justify-content:flex-end;">';
                html += '<button type="button" class="btn-icon sitessaver-gdrive-restore-btn" data-id="' + f.id + '" data-name="' + f.name + '" title="Restore from Drive"><i class="ri-history-line"></i></button>';
                html += '<button type="button" class="btn-icon sitessaver-gdrive-dl-btn" data-id="' + f.id + '" title="Download"><i class="ri-download-cloud-2-line"></i></button>';
                html += '<button type="button" class="btn-icon danger sitessaver-gdrive-delete-btn" data-id="' + f.id + '" title="Delete from Drive"><i class="ri-delete-bin-line"></i></button>';
                html += '</div></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';

            $container.html(html);
        }, function (err) {
            $container.html('<p style="padding: 24px; color: var(--ss-danger); text-align: center;">' + (err.message || SS.strings.error) + '</p>');
        });
    });

    $(document).on('click', '.sitessaver-gdrive-dl-btn', function () {
        var id = $(this).data('id');
        var $btn = $(this);
        $btn.prop('disabled', true);

        ajax('sitessaver_gdrive_download', { file_id: id }, function (res) {
            alert(res.message || 'Downloaded!');
            location.reload();
        }, function (err) {
            alert(err.message || SS.strings.error);
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.sitessaver-gdrive-restore-btn', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        var name = $btn.data('name') || 'this backup';

        if (!confirm('Restore ' + name + ' from Google Drive? Your current site will be overwritten.')) return;

        $btn.prop('disabled', true);

        // Show indeterminate progress — operation may take a while
        // (download + extract + DB import + file restore).
        var $progress = $('.sitessaver-progress').first();
        $progress.show().find('.step-label').text('Restoring from Google Drive...');
        $progress.find('.sitessaver-progress-fill').addClass('indeterminate');
        $progress.find('.step-pct').text('');

        ajax('sitessaver_gdrive_restore', { file_id: id }, function (res) {
            $progress.hide().find('.sitessaver-progress-fill').removeClass('indeterminate');
            alert(res.message || 'Restored!');
            location.reload();
        }, function (err) {
            $progress.hide().find('.sitessaver-progress-fill').removeClass('indeterminate');
            alert(err.message || SS.strings.error);
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.sitessaver-gdrive-delete-btn', function () {
        var id = $(this).data('id');
        if (!confirm('Are you sure you want to delete this backup from Google Drive?')) return;

        ajax('sitessaver_gdrive_delete', { file_id: id }, function () {
            $('#sitessaver-gdrive-refresh').trigger('click');
        }, function (err) {
            alert(err.message || SS.strings.error);
        });
    });



    // ---------- AUTO RESUME ----------

    function checkActiveExport() {
        var $form = $('#sitessaver-export-form');
        if (!$form.length) return;

        ajax('sitessaver_get_export_status', {}, function (res) {
            if (res.status && res.status.status === 'running') {
                var steps = res.steps;
                var uid = res.status.uid;
                var currentStep = res.status.step_index;
                var $btn = $('#sitessaver-export-btn');

                $btn.prop('disabled', true);
                
                function runNextStep() {
                    if (currentStep >= steps.length) {
                        ajax('sitessaver_get_export_status', { uid: uid }, function(finalRes) {
                            hideProgress($form);
                            var result = finalRes.status.result;
                            var dlUrl = SS.ajaxUrl + '?action=sitessaver_download_backup&file=' + encodeURIComponent(result.file) + '&nonce=' + SS.downloadNonce;
                            var msg = SS.strings.done + ' — ' + result.file + ' (' + result.size + ') <br><br>';
                            msg += '<a href="' + dlUrl + '" class="ss-download-link" target="_blank"><i class="ri-download-2-line"></i> Click here to download your backup</a>';
                            showResult($form, msg, false);
                            $btn.prop('disabled', false);
                        });
                        return;
                    }

                    var step = steps[currentStep];
                    updateProgress($form, step.pct, step.label);

                    ajax('sitessaver_export_step', { uid: uid, step_index: currentStep }, function (stepRes) {
                        if (stepRes.success) {
                            currentStep++;
                            runNextStep();
                        } else {
                            hideProgress($form);
                            showResult($form, stepRes.message || SS.strings.error, true);
                            $btn.prop('disabled', false);
                        }
                    }, function (err) {
                        hideProgress($form);
                        showResult($form, err.message || SS.strings.error, true);
                        $btn.prop('disabled', false);
                    });
                }
                runNextStep();
            }
        });
    }

    checkActiveExport();

})(jQuery);
