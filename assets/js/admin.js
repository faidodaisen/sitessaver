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

    // ---------- RESTORE-COMPLETE MODAL ----------
    // Mirrors the All-in-One WP Migration UX: after a successful restore we
    // force a logout + permalinks-save round-trip so the new plugin set
    // boots cleanly and rewrite rules are flushed.

    // Finalize redirect URL built server-side by Import::build_finalize_redirect_url().
    // Points at wp-login.php with redirect_to=<permalinks?sitessaver_finalize=TOKEN>.
    // Why we navigate there instead of calling an AJAX finalize:
    //   The browser's auth cookie was issued by the PRE-restore DB. ANY
    //   server-side action that relies on current-user context (AJAX nonce,
    //   capability checks, wp_logout action hooks from third-party plugins,
    //   even some Cloudflare/WAF rules) can fail because user context is
    //   effectively undefined. Navigating straight to wp-login.php sidesteps
    //   all of it — the user re-authenticates with the backup's credentials,
    //   and the deferred finalization runs under the fresh session when the
    //   browser lands on options-permalink.php?sitessaver_finalize=TOKEN.
    var ssFinalizeUrl = '';

    function showRestoreCompleteModal(payload) {
        // Accept either the old string-token form or the new payload object
        // { finalize_token, finalize_url } for forward/backward compat.
        if (typeof payload === 'object' && payload) {
            if (typeof payload.finalize_url === 'string' && payload.finalize_url) {
                ssFinalizeUrl = payload.finalize_url;
            }
        }
        // Don't stack modals.
        if ($('#sitessaver-restore-modal').length) return;

        var html = ''
            + '<div id="sitessaver-restore-modal" class="sitessaver-modal-backdrop">'
            +   '<div class="sitessaver-modal" role="dialog" aria-modal="true" aria-labelledby="sitessaver-modal-title">'
            +     '<div class="sitessaver-modal-icon"><i class="ri-checkbox-circle-fill"></i></div>'
            +     '<h2 id="sitessaver-modal-title">Restore complete</h2>'
            +     '<p class="sitessaver-modal-lead">Your site has been restored. To finish, we need to reload WordPress with the restored plugins and theme:</p>'
            +     '<ol class="sitessaver-modal-steps">'
            +       '<li><strong>You will be logged out</strong> automatically.</li>'
            +       '<li><strong>Log in again</strong> using the restored site\u2019s credentials.</li>'
            +       '<li>You\u2019ll land on <em>Settings \u2192 Permalinks</em>. Click <strong>Save Changes</strong> <u>twice</u> to flush rewrite rules.</li>'
            +     '</ol>'
            +     '<div class="sitessaver-modal-actions">'
            +       '<button type="button" class="btn btn-primary" id="sitessaver-finalize-btn">'
            +         '<i class="ri-logout-box-r-line"></i> Finish & log out'
            +       '</button>'
            +     '</div>'
            +     '<p class="sitessaver-modal-footnote">Do not close this tab until you\u2019ve completed the two permalinks saves.</p>'
            +   '</div>'
            + '</div>';

        $('body').append(html);

        $('#sitessaver-finalize-btn').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            $btn.html('<i class="ri-loader-4-line ri-spin"></i> Logging out...');

            // Clear WordPress auth cookies on the client. Why: the cookies
            // were signed by the PRE-restore auth salts and are now garbage
            // as far as the restored DB is concerned. Clearing them client-
            // side prevents wp-login.php from attempting to validate them
            // and hitting any middleware that chokes on bad cookies.
            //
            // We expire every cookie whose name starts with
            // `wordpress_logged_in_` or `wordpress_sec_` or `wordpress_`
            // on both the current host and any parent domain.
            var hostParts = window.location.hostname.split('.');
            var domainVariants = [''];
            for (var i = 0; i < hostParts.length - 1; i++) {
                domainVariants.push(hostParts.slice(i).join('.'));
            }
            var cookies = document.cookie ? document.cookie.split('; ') : [];
            for (var c = 0; c < cookies.length; c++) {
                var name = cookies[c].split('=')[0];
                if (name.indexOf('wordpress') === 0 || name.indexOf('wp-') === 0 || name.indexOf('wp_') === 0) {
                    for (var d = 0; d < domainVariants.length; d++) {
                        var domainAttr = domainVariants[d] ? '; domain=' + domainVariants[d] : '';
                        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + domainAttr;
                        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/wp-admin' + domainAttr;
                    }
                }
            }

            // Direct navigation — no AJAX. If for any reason the server-built
            // URL isn't available (e.g. viewing a restore-complete modal
            // rendered by an older plugin build), fall back to wp-login.php
            // relative to the current host so the user can at least re-auth.
            var target = ssFinalizeUrl;
            if (!target) {
                // Last-ditch fallback: go to the site's login page. User will
                // then need to manually navigate to Settings > Permalinks.
                target = window.location.protocol + '//' + window.location.host + '/wp-login.php';
            }
            window.location.href = target;
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
        $r.find('.sitessaver-result-text').html(msg);
    }

    // ---------- PROGRESS MODAL ----------

    var ssModal = {
        $el: null,
        cancelable: true,
        onCancel: null,

        build: function () {
            if ($('#ss-progress-modal').length) return;
            var html =
                '<div id="ss-progress-modal" class="sitessaver-modal-backdrop" style="display:none;">' +
                  '<div class="sitessaver-modal" role="dialog" aria-modal="true">' +
                    '<div class="ss-progress-modal-icon" id="ss-pm-icon"><i class="ri-loader-4-line ri-spin"></i></div>' +
                    '<h2 id="ss-pm-title"></h2>' +
                    '<p class="ss-progress-modal-subtitle" id="ss-pm-subtitle"></p>' +
                    '<div class="ss-progress-modal-bar-wrap">' +
                      '<div class="sitessaver-progress" style="display:block;">' +
                        '<div class="sitessaver-progress-text">' +
                          '<span class="step-label"></span>' +
                          '<span class="step-pct">0%</span>' +
                        '</div>' +
                        '<div class="sitessaver-progress-bar"><div class="sitessaver-progress-fill"></div></div>' +
                      '</div>' +
                    '</div>' +
                    '<p class="ss-progress-modal-step" id="ss-pm-step"></p>' +
                    '<div class="ss-progress-modal-caution">' +
                      '<i class="ri-alert-line"></i>' +
                      '<span id="ss-pm-caution"></span>' +
                    '</div>' +
                    '<div class="ss-cancel-confirm" id="ss-cancel-confirm">' +
                      '<p><i class="ri-error-warning-line"></i> Are you sure you want to cancel?</p>' +
                      '<div class="ss-cancel-confirm-actions">' +
                        '<button type="button" class="btn btn-outline" id="ss-cancel-no">No, continue</button>' +
                        '<button type="button" class="btn" style="background:var(--ss-danger);color:#fff;border-color:var(--ss-danger);" id="ss-cancel-yes">Yes, cancel</button>' +
                      '</div>' +
                    '</div>' +
                    '<div class="ss-progress-modal-actions">' +
                      '<button type="button" class="btn btn-outline" id="ss-pm-cancel-btn"><i class="ri-close-line"></i> Cancel</button>' +
                    '</div>' +
                  '</div>' +
                '</div>';
            $('body').append(html);
            this.$el = $('#ss-progress-modal');

            var self = this;
            $(document).on('click', '#ss-pm-cancel-btn', function () {
                if (!self.cancelable) return;
                $('#ss-cancel-confirm').slideDown(150);
                $(this).prop('disabled', true);
            });
            $(document).on('click', '#ss-cancel-no', function () {
                $('#ss-cancel-confirm').slideUp(150);
                $('#ss-pm-cancel-btn').prop('disabled', false);
            });
            $(document).on('click', '#ss-cancel-yes', function () {
                if (self.onCancel) self.onCancel();
            });
        },

        open: function (opts) {
            this.build();
            this.cancelable = opts.cancelable !== false;
            this.onCancel   = opts.onCancel || null;

            $('#ss-pm-icon').attr('class', 'ss-progress-modal-icon' + (opts.warning ? ' is-warning' : ''));
            $('#ss-pm-icon i').attr('class', 'ri-loader-4-line ri-spin');
            $('#ss-pm-title').text(opts.title || '');
            $('#ss-pm-subtitle').text(opts.subtitle || '');
            $('#ss-pm-caution').text(opts.caution || 'Do not close this tab or navigate away while the operation is running.');
            $('#ss-pm-step').text('');
            $('#ss-cancel-confirm').hide();
            $('#ss-pm-cancel-btn').prop('disabled', false).toggle(!!this.cancelable);
            this.setProgress(0, '');
            this.$el.fadeIn(200);
            $('body').addClass('ss-modal-open');
        },

        setProgress: function (pct, label) {
            var $p = this.$el.find('.sitessaver-progress');
            $p.find('.sitessaver-progress-fill').removeClass('indeterminate').css('width', pct + '%');
            $p.find('.step-label').text(label);
            $p.find('.step-pct').text(pct + '%');
            $('#ss-pm-step').text(label);
        },

        setIndeterminate: function (label) {
            var $p = this.$el.find('.sitessaver-progress');
            $p.find('.sitessaver-progress-fill').addClass('indeterminate').css('width', '100%');
            $p.find('.step-label').text(label);
            $p.find('.step-pct').text('');
            $('#ss-pm-step').text(label);
        },

        disableCancel: function (reason) {
            this.cancelable = false;
            var $btn = $('#ss-pm-cancel-btn');
            $btn.prop('disabled', true);
            if (reason) $btn.text(reason);
            $('#ss-cancel-confirm').hide();
        },

        done: function (iconClass) {
            $('#ss-pm-icon i').attr('class', iconClass || 'ri-checkbox-circle-fill').css('color', 'var(--ss-success)');
            $('#ss-pm-icon').removeClass('is-warning');
            $('#ss-pm-cancel-btn').hide();
            $('#ss-cancel-confirm').hide();
        },

        close: function () {
            if (this.$el) {
                this.$el.fadeOut(200);
                $('body').removeClass('ss-modal-open');
            }
        }
    };


    // ---------- EXPORT ----------

    $(document).on('submit', '#sitessaver-export-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $('#sitessaver-export-btn');

        $btn.prop('disabled', true);
        $form.find('.sitessaver-result').hide();

        var destination = $form.find('[name=export_destination]:checked').val() || 'local';
        var cancelled   = false;

        var cautionText = destination === 'local'
            ? 'Do not close this tab while the backup is being created.'
            : 'Do not close this tab. The backup will be uploaded to Google Drive after it is created.';

        ssModal.open({
            title:    'Exporting Site',
            subtitle: 'Your site backup is being created. This may take a few minutes.',
            caution:  cautionText,
            cancelable: true,
            onCancel: function () {
                cancelled = true;
                ssModal.disableCancel('Cancelling...');
            }
        });

        var data = {
            include_db:          $form.find('[name=include_db]').is(':checked') ? 1 : 0,
            include_media:       $form.find('[name=include_media]').is(':checked') ? 1 : 0,
            include_plugins:     $form.find('[name=include_plugins]').is(':checked') ? 1 : 0,
            include_themes:      $form.find('[name=include_themes]').is(':checked') ? 1 : 0,
            export_destination:  destination
        };

        ajax('sitessaver_export', data, function (res) {
            var steps       = res.steps;
            var uid         = res.status.uid;
            var currentStep = 0;

            function runNextStep() {
                if (cancelled) {
                    ajax('sitessaver_cancel_export', { uid: uid }, function () {
                        ssModal.close();
                        showResult($form, 'Export cancelled.', true);
                        $btn.prop('disabled', false);
                    });
                    return;
                }

                if (currentStep >= steps.length) {
                    ajax('sitessaver_get_export_status', { uid: uid }, function (finalRes) {
                        ssModal.done();
                        setTimeout(function () {
                            ssModal.close();
                            var result = finalRes.status.result;
                            var dest   = result.destination || 'local';
                            var msg    = '';

                            if ((dest === 'local' || dest === 'both') && result.file) {
                                var dlUrl = SS.ajaxUrl + '?action=sitessaver_download_backup&file=' + encodeURIComponent(result.file) + '&nonce=' + SS.downloadNonce;
                                msg += SS.strings.done + ' — ' + result.file + ' (' + result.size + ')<br><br>';
                                msg += '<a href="' + dlUrl + '" class="ss-download-link" target="_blank"><i class="ri-download-2-line"></i> Download backup</a>';
                            }

                            if ((dest === 'gdrive' || dest === 'both') && result.gdrive) {
                                if (msg) msg += '<br><br>';
                                if (result.gdrive.success) {
                                    msg += '<i class="ri-google-line"></i> ' + (result.gdrive.message || 'Uploaded to Google Drive.');
                                    if (result.gdrive_folder_url) {
                                        msg += ' <a href="' + result.gdrive_folder_url + '" target="_blank" rel="noopener"><i class="ri-external-link-line"></i> Open Drive folder</a>';
                                    }
                                } else {
                                    msg += '<span style="color:var(--ss-danger)"><i class="ri-error-warning-line"></i> Google Drive upload failed: ' + (result.gdrive.message || 'Unknown error') + '</span>';
                                }
                            }

                            if (!msg) msg = SS.strings.done;
                            showResult($form, msg, false);
                            $btn.prop('disabled', false);
                        }, 800);
                    });
                    return;
                }

                var step  = steps[currentStep];
                var label = step.label;
                if (step.id === 'finalize' && (destination === 'gdrive' || destination === 'both')) {
                    label = 'Uploading to Google Drive...';
                    ssModal.disableCancel('Uploading to Drive…');
                }
                ssModal.setProgress(step.pct, label);

                ajax('sitessaver_export_step', { uid: uid, step_index: currentStep },
                    function (stepRes) {
                        if (stepRes.success) {
                            currentStep++;
                            runNextStep();
                        } else {
                            handleExportError(stepRes);
                        }
                    },
                    handleExportError
                );
            }

            function handleExportError(err) {
                ssModal.close();
                showResult($form, err.message || SS.strings.error, true);
                $btn.prop('disabled', false);
            }

            runNextStep();

        }, function (err) {
            ssModal.close();
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

        var $form       = $('.sitessaver-wrap');
        var chunkSize   = 2 * 1024 * 1024;
        var totalChunks = Math.ceil(file.size / chunkSize);
        var uploadId    = generateUploadId();
        var currentChunk = 0;
        var assembledFile = '';
        var cancelled   = false;
        var activeXhr   = null;

        $form.find('.sitessaver-result').hide();

        ssModal.open({
            title:    'Importing Backup',
            subtitle: 'Uploading your backup file. Please wait.',
            caution:  'Do not close this tab or navigate away. Cancelling during upload will discard the file.',
            cancelable: true,
            onCancel: function () {
                cancelled = true;
                if (activeXhr) activeXhr.abort();
                ajax('sitessaver_cleanup_chunks', { upload_id: uploadId });
                ssModal.close();
                showResult($form, 'Import cancelled.', true);
            }
        });

        ssModal.setProgress(0, 'Uploading (0%)');

        function sendNextChunk() {
            if (cancelled) return;

            if (currentChunk >= totalChunks) {
                startRestoration(assembledFile);
                return;
            }

            var start    = currentChunk * chunkSize;
            var end      = Math.min(start + chunkSize, file.size);
            var blob     = file.slice(start, end);
            var formData = new FormData();
            formData.append('action', 'sitessaver_upload_chunk');
            formData.append('nonce', SS.nonce);
            formData.append('chunk', blob);
            formData.append('chunk_index', currentChunk);
            formData.append('total_chunks', totalChunks);
            formData.append('filename', file.name);
            formData.append('upload_id', uploadId);

            activeXhr = $.ajax({
                url: SS.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (cancelled) return;
                    if (res.success) {
                        if (res.data && res.data.assembled_file) {
                            assembledFile = res.data.assembled_file;
                        }
                        currentChunk++;
                        var pct = Math.round((currentChunk / totalChunks) * 100);
                        ssModal.setProgress(pct, 'Uploading (' + pct + '%)');
                        sendNextChunk();
                    } else {
                        onUploadError(res.data ? res.data.message : SS.strings.error);
                    }
                },
                error: function (xhr, status) {
                    if (cancelled || status === 'abort') return;
                    onUploadError(SS.strings.error);
                }
            });
        }

        function onUploadError(msg) {
            ajax('sitessaver_cleanup_chunks', { upload_id: uploadId });
            ssModal.close();
            showResult($form, msg, true);
        }

        function startRestoration(filename) {
            // Restore phase — cannot cancel, warn user clearly
            ssModal.disableCancel('Restoring — cannot cancel');
            $('#ss-pm-title').text('Restoring Site');
            $('#ss-pm-subtitle').text('Database and files are being restored. This cannot be interrupted.');
            $('#ss-pm-caution').text('Do not close this tab. Interrupting the restore may leave your site in a broken state.');
            ssModal.setIndeterminate('Restoring database and files...');

            ajax('sitessaver_import', { file: filename },
                function (res) {
                    ssModal.done();
                    setTimeout(function () {
                        ssModal.close();
                        showResult($form, res.message || SS.strings.done, false);
                        showRestoreCompleteModal(res);
                    }, 800);
                },
                function (err) {
                    ssModal.close();
                    showResult($form, err.message || SS.strings.error, true);
                }
            );
        }

        sendNextChunk();
    }


    // ---------- RESTORE (from existing backup) ----------

    $(document).on('click', '.sitessaver-restore-btn', function () {
        var file  = $(this).data('file');
        var $form = $('.sitessaver-wrap');
        if (!confirm(SS.strings.confirmRestore)) return;

        ssModal.open({
            title:      'Restoring Site',
            subtitle:   'Database and files are being restored. Please wait.',
            caution:    'Do not close this tab. Interrupting the restore may leave your site in a broken state.',
            cancelable: false
        });
        ssModal.setIndeterminate('Restoring database and files...');

        ajax('sitessaver_import', { file: file },
            function (res) {
                ssModal.done();
                setTimeout(function () {
                    ssModal.close();
                    showRestoreCompleteModal(res);
                }, 800);
            },
            function (err) {
                ssModal.close();
                showResult($form, err.message || SS.strings.error, true);
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
            showRestoreCompleteModal(res);
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



    // ---------- ACTIVE-EXPORT DETECTION (opt-in resume, never auto-run) ----------

    function runExportLoop($form, uid, steps, startStep) {
        var currentStep = startStep;
        var $btn        = $('#sitessaver-export-btn');
        var cancelled   = false;
        $btn.prop('disabled', true);

        ssModal.open({
            title:      'Resuming Export',
            subtitle:   'Continuing your site backup from where it left off.',
            caution:    'Do not close this tab while the backup is being created.',
            cancelable: true,
            onCancel: function () {
                cancelled = true;
                ssModal.disableCancel('Cancelling...');
            }
        });

        function runNextStep() {
            if (cancelled) {
                ajax('sitessaver_cancel_export', { uid: uid }, function () {
                    ssModal.close();
                    $('.ss-resume-banner').remove();
                    showResult($form, 'Export cancelled.', true);
                    $btn.prop('disabled', false);
                });
                return;
            }

            if (currentStep >= steps.length) {
                ajax('sitessaver_get_export_status', { uid: uid }, function (finalRes) {
                    ssModal.done();
                    setTimeout(function () {
                        ssModal.close();
                        var result = (finalRes.status && finalRes.status.result) || {};
                        var msg = '';
                        if (result.file) {
                            var dlUrl = SS.ajaxUrl + '?action=sitessaver_download_backup&file=' + encodeURIComponent(result.file) + '&nonce=' + SS.downloadNonce;
                            msg = SS.strings.done + ' — ' + result.file + ' (' + result.size + ') <br><br>';
                            msg += '<a href="' + dlUrl + '" class="ss-download-link" target="_blank"><i class="ri-download-2-line"></i> Click here to download your backup</a>';
                        } else {
                            msg = SS.strings.done;
                        }
                        showResult($form, msg, false);
                        $('.ss-resume-banner').remove();
                        $btn.prop('disabled', false);
                    }, 800);
                });
                return;
            }

            var step = steps[currentStep];
            ssModal.setProgress(step.pct, step.label);

            ajax('sitessaver_export_step', { uid: uid, step_index: currentStep }, function (stepRes) {
                if (stepRes.success) {
                    currentStep++;
                    runNextStep();
                } else {
                    ssModal.close();
                    showResult($form, stepRes.message || SS.strings.error, true);
                    $btn.prop('disabled', false);
                }
            }, function (err) {
                ssModal.close();
                showResult($form, err.message || SS.strings.error, true);
                $btn.prop('disabled', false);
            });
        }

        runNextStep();
    }

    function checkActiveExport() {
        var $form = $('#sitessaver-export-form');
        if (!$form.length) return;

        ajax('sitessaver_get_export_status', {}, function (res) {
            if (!res || !res.status || res.status.status !== 'running') return;

            var steps       = res.steps;
            var uid         = res.status.uid;
            var currentStep = res.status.step_index;
            var stepLabel   = (steps[currentStep] && steps[currentStep].label) || 'in progress';
            var pct         = (steps[currentStep] && steps[currentStep].pct) || 0;

            // Show a banner above the form. User picks Resume or Discard —
            // we never auto-run the export. Previously an orphaned scheduled
            // export would silently resume on page load.
            var banner =
                '<div class="ss-resume-banner ss-result-card" style="background:#fef7e0;border:1px solid #fde293;color:#9a6400;align-items:flex-start;">' +
                    '<i class="ri-time-line" style="font-size:24px;"></i>' +
                    '<div style="flex:1;">' +
                        '<strong style="display:block;margin-bottom:4px;">An export is already in progress</strong>' +
                        '<span>Step: ' + stepLabel + ' (' + pct + '%). Resume or discard it before starting a new export.</span>' +
                        '<div style="margin-top:12px;display:flex;gap:8px;">' +
                            '<button type="button" class="btn btn-primary ss-resume-btn">Resume</button>' +
                            '<button type="button" class="btn btn-outline ss-discard-btn" style="color:var(--ss-danger);border-color:var(--ss-danger);">Discard</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';

            $form.prepend(banner);
            $('#sitessaver-export-btn').prop('disabled', true);

            $form.on('click', '.ss-resume-btn', function () {
                $('.ss-resume-banner').remove();
                runExportLoop($form, uid, steps, currentStep);
            });

            $form.on('click', '.ss-discard-btn', function () {
                if (!confirm('Discard this export? The partial backup will be deleted.')) return;
                ajax('sitessaver_cancel_export', { uid: uid }, function () {
                    $('.ss-resume-banner').remove();
                    $('#sitessaver-export-btn').prop('disabled', false);
                }, function (err) {
                    alert(err.message || SS.strings.error);
                });
            });
        });
    }

    checkActiveExport();

})(jQuery);
