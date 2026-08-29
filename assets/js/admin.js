(function ($) {
    'use strict';

    var SS = SitesSaver;

    // ---------- NOTIFICATIONS (toasts + dialogs) ----------
    //
    // Everything here replaces the native alert() / confirm() / prompt()
    // calls this file used to make. Native dialogs were a poor fit:
    //   - they block the JS thread, so in-flight progress modals freeze
    //   - they can't be styled, so they break the plugin's visual language
    //   - Chrome suppresses them outright in cross-origin iframes, which
    //     silently swallowed confirmations for users embedding wp-admin
    //   - the text can't be translated through WordPress's i18n pipeline
    //
    // The replacements are async (callback-based) rather than blocking, so
    // every former `if (!confirm(...)) return;` guard had to become a
    // continuation. See the call sites below.

    var ssNotify = (function () {

        function esc(str) {
            return $('<div/>').text(str == null ? '' : String(str)).html();
        }

        // Body scroll-lock is shared between the progress modal, the
        // restore-complete modal, and dialogs. Deriving it from what is
        // actually on screen avoids the classic refcount drift where one
        // layer closes and unlocks scrolling for a layer still open.
        function syncScrollLock() {
            var open = $('.sitessaver-modal-backdrop:visible, .ss-dialog-backdrop').length > 0;
            $('body').toggleClass('ss-modal-open', open);
        }

        // ---- Toasts ----

        var ICONS = {
            success: 'ri-checkbox-circle-fill',
            error:   'ri-error-warning-fill',
            warning: 'ri-alert-fill',
            info:    'ri-information-fill'
        };

        var DEFAULT_TITLES = {
            success: 'Success',
            error:   'Error',
            warning: 'Warning',
            info:    'Notice'
        };

        function stack() {
            var $s = $('#ss-toast-stack');
            if (!$s.length) {
                $s = $('<div id="ss-toast-stack" class="ss-toast-stack" role="region" aria-label="Notifications" aria-live="polite"></div>');
                $('body').append($s);
            }
            return $s;
        }

        function dismiss($toast) {
            if (!$toast.length || $toast.hasClass('is-leaving')) return;
            $toast.addClass('is-leaving');
            setTimeout(function () { $toast.remove(); }, 240);
        }

        /**
         * @param {Object} opts
         *   type      success|error|warning|info   (default info)
         *   title     heading; falls back to a per-type default
         *   message   body text
         *   html      set true when `message` is trusted markup (download links)
         *   duration  ms before auto-dismiss; 0 keeps it until dismissed
         */
        function toast(opts) {
            opts = opts || {};
            var type = ICONS[opts.type] ? opts.type : 'info';
            var title = opts.title !== undefined ? opts.title : DEFAULT_TITLES[type];
            // Errors stay longer — they usually carry text worth reading.
            var duration = opts.duration !== undefined
                ? opts.duration
                : (type === 'error' ? 9000 : 5000);

            var body = opts.html ? (opts.message || '') : esc(opts.message || '');

            var $toast = $(
                '<div class="ss-toast ss-toast-' + type + '" role="' + (type === 'error' ? 'alert' : 'status') + '">' +
                    '<span class="ss-toast-icon"><i class="' + ICONS[type] + '"></i></span>' +
                    '<div class="ss-toast-body">' +
                        (title ? '<strong class="ss-toast-title">' + esc(title) + '</strong>' : '') +
                        '<span class="ss-toast-message">' + body + '</span>' +
                    '</div>' +
                    '<button type="button" class="ss-toast-close" aria-label="Dismiss notification">' +
                        '<i class="ri-close-line"></i>' +
                    '</button>' +
                '</div>'
            );

            if (duration > 0) {
                $toast.append(
                    $('<span class="ss-toast-timer" aria-hidden="true"></span>')
                        .css('animation-duration', duration + 'ms')
                );
            }

            $toast.on('click', '.ss-toast-close', function () { dismiss($toast); });

            stack().append($toast);

            if (duration > 0) {
                // Hovering pauses the countdown so a long message stays
                // readable; the CSS bar is paused in lockstep.
                var remaining = duration;
                var startedAt = Date.now();
                var timer = setTimeout(function () { dismiss($toast); }, remaining);

                $toast.on('mouseenter', function () {
                    clearTimeout(timer);
                    remaining -= (Date.now() - startedAt);
                    $toast.addClass('is-paused');
                });

                $toast.on('mouseleave', function () {
                    if (remaining <= 0) { dismiss($toast); return; }
                    startedAt = Date.now();
                    $toast.removeClass('is-paused');
                    timer = setTimeout(function () { dismiss($toast); }, remaining);
                });
            }

            return $toast;
        }

        // ---- Dialogs (confirm / prompt / alert) ----

        var DIALOG_ICONS = {
            danger:  'ri-error-warning-line',
            warning: 'ri-alert-line',
            success: 'ri-checkbox-circle-line',
            primary: 'ri-question-line'
        };

        /**
         * Modal dialog. Async by design — pass onConfirm / onCancel.
         *
         * @param {Object} o
         *   title, message, html
         *   tone         danger|warning|success|primary (styles icon + button)
         *   confirmText, cancelText  (cancelText null hides the cancel button)
         *   input        {label, value, placeholder, required} to render a prompt
         *   onConfirm(value), onCancel()
         */
        function dialog(o) {
            o = o || {};
            var tone = DIALOG_ICONS[o.tone] ? o.tone : 'primary';
            var hasInput = !!o.input;
            var showCancel = o.cancelText !== null;
            var settled = false;

            var $prev = $(document.activeElement);

            var msg = o.html ? (o.message || '') : esc(o.message || '');

            var html =
                '<div class="ss-dialog-backdrop">' +
                    '<div class="ss-dialog ss-dialog-' + tone + '" role="dialog" aria-modal="true" aria-labelledby="ss-dialog-title">' +
                        '<div class="ss-dialog-head">' +
                            '<div class="ss-dialog-icon"><i class="' + DIALOG_ICONS[tone] + '"></i></div>' +
                            '<h2 class="ss-dialog-title" id="ss-dialog-title">' + esc(o.title || 'Please confirm') + '</h2>' +
                        '</div>' +
                        (msg ? '<p class="ss-dialog-message">' + msg + '</p>' : '') +
                        (hasInput
                            ? '<div class="ss-dialog-field">' +
                                  (o.input.label ? '<label for="ss-dialog-input">' + esc(o.input.label) + '</label>' : '') +
                                  '<input type="text" id="ss-dialog-input" value="' + esc(o.input.value || '') + '"' +
                                      ' placeholder="' + esc(o.input.placeholder || '') + '" />' +
                              '</div>'
                            : '') +
                        '<div class="ss-dialog-actions">' +
                            (showCancel
                                ? '<button type="button" class="btn btn-outline ss-dialog-cancel">' + esc(o.cancelText || 'Cancel') + '</button>'
                                : '') +
                            '<button type="button" class="btn ' +
                                (tone === 'danger' ? 'btn-danger' : 'btn-primary') +
                                ' ss-dialog-confirm">' + esc(o.confirmText || 'Confirm') + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';

            var $el = $(html);
            $('body').append($el);
            syncScrollLock();

            function close() {
                $el.remove();
                $(document).off('keydown.ssDialog');
                syncScrollLock();
                // Return focus to whatever opened the dialog, so keyboard
                // users are not dumped back at the top of the document.
                if ($prev && $prev.length && document.contains($prev[0])) {
                    try { $prev.trigger('focus'); } catch (e) {}
                }
            }

            function cancel() {
                if (settled) return;
                settled = true;
                close();
                if (o.onCancel) o.onCancel();
            }

            // Named `accept`, not `confirm`: a local function called confirm()
            // shadows window.confirm inside this closure, which is exactly the
            // thing this module exists to stop anyone calling by accident.
            function accept() {
                if (settled) return;
                var value = hasInput ? $el.find('#ss-dialog-input').val() : true;
                if (hasInput && o.input.required && !$.trim(String(value))) {
                    $el.find('#ss-dialog-input').trigger('focus');
                    return;
                }
                settled = true;
                close();
                if (o.onConfirm) o.onConfirm(value);
            }

            $el.on('click', '.ss-dialog-confirm', accept);
            $el.on('click', '.ss-dialog-cancel', cancel);

            // Click on the backdrop (never the panel) dismisses.
            $el.on('click', function (e) {
                if (e.target === $el[0]) cancel();
            });

            $(document).on('keydown.ssDialog', function (e) {
                if (e.key === 'Escape') {
                    cancel();
                } else if (e.key === 'Enter' && hasInput && $(e.target).is('#ss-dialog-input')) {
                    e.preventDefault();
                    accept();
                } else if (e.key === 'Tab') {
                    // Keep focus inside the dialog while it is open.
                    var $f = $el.find('button, input').filter(':visible');
                    if (!$f.length) return;
                    var first = $f[0];
                    var last  = $f[$f.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            });

            // Focus the input for prompts, otherwise the safe (cancel) button
            // for destructive dialogs so Enter never destroys anything.
            setTimeout(function () {
                if (hasInput) {
                    $el.find('#ss-dialog-input').trigger('focus').trigger('select');
                } else if (tone === 'danger' && showCancel) {
                    $el.find('.ss-dialog-cancel').trigger('focus');
                } else {
                    $el.find('.ss-dialog-confirm').trigger('focus');
                }
            }, 50);

            return { close: cancel };
        }

        return {
            toast:   toast,
            success: function (message, opts) { return toast($.extend({ type: 'success', message: message }, opts || {})); },
            error:   function (message, opts) { return toast($.extend({ type: 'error',   message: message }, opts || {})); },
            warning: function (message, opts) { return toast($.extend({ type: 'warning', message: message }, opts || {})); },
            info:    function (message, opts) { return toast($.extend({ type: 'info',    message: message }, opts || {})); },
            confirm: dialog,
            prompt:  function (o) {
                return dialog($.extend({}, o, { input: o.input || { label: o.label, value: o.value, placeholder: o.placeholder } }));
            },
            alert: function (o) {
                return dialog($.extend({ cancelText: null, confirmText: 'OK' }, o));
            },
            syncScrollLock: syncScrollLock
        };
    })();

    // Exposed so other SitesSaver scripts (and debugging) can reuse it.
    window.ssNotify = ssNotify;

    // Several actions (save schedule, delete backup, rename label) must reload
    // the page so the server-rendered table and cron status stay truthful. A
    // toast fired right before location.reload() would be destroyed with the
    // document, so park it in sessionStorage and replay it on the way back.
    var SS_FLASH_KEY = 'sitessaver_flash';

    function ssFlash(type, message, title) {
        try {
            sessionStorage.setItem(SS_FLASH_KEY, JSON.stringify({
                type: type, message: message, title: title
            }));
        } catch (e) {
            // Private-mode Safari and some hardened setups throw on write.
            // A missing confirmation toast is not worth breaking the action.
        }
    }

    function ssReplayFlash() {
        var raw;
        try {
            raw = sessionStorage.getItem(SS_FLASH_KEY);
            if (raw) sessionStorage.removeItem(SS_FLASH_KEY);
        } catch (e) {
            return;
        }
        if (!raw) return;

        try {
            var f = JSON.parse(raw);
            ssNotify.toast({ type: f.type, message: f.message, title: f.title });
        } catch (e) { /* malformed entry — ignore */ }
    }

    $(ssReplayFlash);

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
        ssNotify.syncScrollLock();

        $('#sitessaver-finalize-btn').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            $btn.html('<i class="ri-loader-4-line ri-spin"></i> Logging out...');

            // NOTE: we deliberately do NOT try to clear the auth cookies from
            // JavaScript. WordPress sets them HttpOnly (see pluggable.php,
            // `setcookie( LOGGED_IN_COOKIE, ..., true )`), so document.cookie
            // cannot touch the only cookies that actually matter -- the sweep
            // that used to live here silently did nothing. The logout is
            // forced server-side instead: the finalize URL carries `reauth=1`,
            // which makes wp-login.php call wp_clear_auth_cookie() and show
            // the login prompt even when the existing session is still valid
            // (which it is whenever the backup came from this same site, since
            // the auth salts are unchanged).

            // Direct navigation -- no AJAX. If for any reason the server-built
            // URL isn't available (e.g. a modal rendered by an older build),
            // fall back to wp-login.php.
            var target = ssFinalizeUrl;
            if (!target) {
                // Root-relative on purpose: an absolute URL built from the
                // restored siteurl can point at a different host than the one
                // the browser is on, and WordPress's wp_validate_redirect()
                // then discards it and sends the user to the dashboard.
                target = '/wp-login.php?reauth=1';
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
        },

        setIndeterminate: function (label) {
            var $p = this.$el.find('.sitessaver-progress');
            $p.find('.sitessaver-progress-fill').addClass('indeterminate').css('width', '100%');
            $p.find('.step-label').text(label);
            $p.find('.step-pct').text('');
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
                // Recompute the lock after the fade finishes rather than
                // dropping it unconditionally: the restore flow opens the
                // restore-complete modal while this one is closing, and an
                // unconditional removeClass left that modal scrollable.
                this.$el.fadeOut(200, function () {
                    ssNotify.syncScrollLock();
                });
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
            var gdriveJob   = res.gdrive_job_id;
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

                var step = steps[currentStep];

                // A step flagged `poll` runs a long server-side transfer whose
                // real progress is reported separately. Show the START of its
                // range and let the poller fill the rest, instead of jumping
                // straight to the end percentage and freezing there.
                if (step.poll === 'gdrive') {
                    ssModal.disableCancel('Uploading to Drive…');
                    ssModal.setProgress(step.from || 0, step.label);
                    startGdrivePolling(step);
                } else {
                    ssModal.setProgress(step.pct, step.label);
                }

                ajax('sitessaver_export_step', { uid: uid, step_index: currentStep },
                    function (stepRes) {
                        stopGdrivePolling();
                        if (stepRes.success) {
                            currentStep++;
                            runNextStep();
                        } else {
                            handleExportError(stepRes);
                        }
                    },
                    function (err) {
                        stopGdrivePolling();
                        handleExportError(err);
                    }
                );
            }

            var gdrivePoll = null;

            function startGdrivePolling(step) {
                gdrivePoll = pollGdriveInto(ssModal, gdriveJob, step, gdrivePoll);
            }

            function stopGdrivePolling() {
                gdrivePoll = stopPoll(gdrivePoll);
            }

            function handleExportError(err) {
                stopGdrivePolling();
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


    // ---- Live Google Drive upload progress ----
    //
    // A Drive upload runs inside ONE long export-step request, so the browser
    // gets nothing back until it finishes. GDrive::upload() records byte
    // progress in a transient as each chunk is accepted; polling that is what
    // makes the bar advance during the upload instead of sitting at 100%,
    // which is what it did when the upload was folded into a step already
    // declared complete.
    //
    // Shared by the fresh-export and resume-export flows so they cannot drift.

    function pollGdriveInto(modal, jobId, step, existing) {
        if (!jobId || existing) return existing;

        var from = step.from || 0;
        var to   = step.pct;
        var span = Math.max(0, to - from);
        var last = from;
        var handle;

        handle = setInterval(function () {
            ajax('sitessaver_get_gdrive_upload_status', { job_id: jobId }, function (jobRes) {
                var uploaded = Number(jobRes.progress);
                if (!isFinite(uploaded)) return;

                // Map the upload's own 0-100 onto this step's slice of the bar.
                var overall = Math.round(from + (span * (uploaded / 100)));

                // Never go backwards: a retried chunk can re-report a lower
                // offset, and a bar that jumps back looks broken.
                if (overall < last) return;
                last = overall;

                var label = step.label;
                if (uploaded > 0 && uploaded < 100) {
                    label = 'Uploading to Google Drive... (' + uploaded + '%)';
                }

                modal.setProgress(Math.min(overall, to), label);
            });
        }, 1500);

        return handle;
    }

    function stopPoll(handle) {
        if (handle) clearInterval(handle);
        return null;
    }


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
            ssNotify.error('Only ZIP files are accepted. Pick the .zip archive produced by an export.', {
                title: 'Unsupported file'
            });
            // Clear the picker so re-choosing the same bad file still fires
            // a change event and the user sees the message again.
            $('#sitessaver-import-file').val('');
            return;
        }

        ssNotify.confirm({
            tone: 'danger',
            title: 'Restore this backup?',
            message: SS.strings.confirmRestore,
            confirmText: 'Yes, restore',
            cancelText: 'Cancel',
            onCancel: function () {
                $('#sitessaver-import-file').val('');
            },
            onConfirm: function () {
                startImportUpload(file);
            }
        });
    }

    function startImportUpload(file) {
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

        ssNotify.confirm({
            tone: 'danger',
            title: 'Restore this backup?',
            message: SS.strings.confirmRestore,
            confirmText: 'Yes, restore',
            cancelText: 'Cancel',
            onConfirm: function () { doRestore(file, $form); }
        });
    });

    function doRestore(file, $form) {
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
    }


    // ---------- LABELS ----------

    $(document).on('click', '.sitessaver-label-btn', function () {
        var file    = $(this).data('file');
        var current = $.trim($(this).closest('tr').find('.cell-label').text() || '');
        // The cell renders an em dash as the empty-state placeholder; never
        // seed that into the input as if it were a real label.
        if (current === '\u2014') current = '';

        ssNotify.prompt({
            title: 'Label this backup',
            message: 'Give this backup a short name so it is easy to recognise later.',
            confirmText: 'Save label',
            input: {
                label: 'Label',
                value: current,
                placeholder: 'e.g. Before theme update'
            },
            onConfirm: function (label) {
                ajax('sitessaver_add_label', { file: file, label: label }, function () {
                    // Reload so the table reflects the stored label. The toast
                    // is shown by the post-reload flash, see ssFlash below.
                    ssFlash('success', 'Label saved.');
                    location.reload();
                }, function (err) {
                    ssNotify.error(err.message || SS.strings.error);
                });
            }
        });
    });


    // ---------- DELETE ----------

    $(document).on('click', '.sitessaver-delete-btn', function () {
        var file = $(this).data('file');

        ssNotify.confirm({
            tone: 'danger',
            title: 'Delete this backup?',
            message: 'The backup file "' + file + '" will be permanently removed from this server. This cannot be undone.',
            confirmText: 'Delete backup',
            cancelText: 'Keep it',
            onConfirm: function () {
                ajax('sitessaver_delete_backup', { file: file }, function () {
                    ssFlash('success', 'Backup deleted.');
                    location.reload();
                }, function (err) {
                    ssNotify.error(err.message || SS.strings.error);
                });
            }
        });
    });


    // ---------- SCHEDULE ----------

    // Keep the card's selected styling in step with its checkbox.
    $(document).on('change', '.ss-frequency-option input[type=checkbox]', function () {
        $(this).closest('.ss-frequency-option').toggleClass('is-selected', this.checked);
    });

    $(document).on('submit', '#sitessaver-schedule-form', function (e) {
        e.preventDefault();
        var $form = $(this);

        var enabled = $form.find('[name=enabled]').is(':checked');

        // Multiple frequencies may be active at once, so collect them all.
        var frequencies = $form.find('[name="frequencies[]"]:checked').map(function () {
            return this.value;
        }).get();

        if (enabled && frequencies.length === 0) {
            ssNotify.warning('Choose at least one backup frequency before enabling the schedule.', {
                title: 'No frequency selected'
            });
            return;
        }

        var data = {
            enabled: enabled ? 1 : 0,
            // jQuery serialises an array under this key as frequencies[]=...,
            // which is what the PHP handler reads.
            frequencies: frequencies,
            retention: $form.find('[name=retention]').val(),
            include_db: $form.find('[name=include_db]').is(':checked') ? 1 : 0,
            include_media: $form.find('[name=include_media]').is(':checked') ? 1 : 0,
            include_plugins: $form.find('[name=include_plugins]').is(':checked') ? 1 : 0,
            include_themes: $form.find('[name=include_themes]').is(':checked') ? 1 : 0,
            storage_local: $form.find('[name=storage_local]').is(':checked') ? 1 : 0,
            storage_gdrive: $form.find('[name=storage_gdrive]').is(':checked') ? 1 : 0,
            notify_email: $form.find('[name=notify_email]').val()
        };

        var $btn = $form.find('button[type=submit]').prop('disabled', true);

        ajax('sitessaver_save_schedule', data, function (res) {
            // Reload so "Next run in ...", the per-frequency last-run lines,
            // and the cron health banners reflect what was just written.
            ssFlash('success', res.message || SS.strings.done);
            location.reload();
        }, function (err) {
            $btn.prop('disabled', false);
            ssNotify.error(err.message || SS.strings.error);
        });
    });


    // ---------- SERVER CRON PANEL ----------

    $(document).on('click', '.ss-copy-btn', function () {
        var $btn    = $(this);
        var $target = $($btn.data('copy-target'));
        if (!$target.length) return;

        var text = $target.val();

        function done() {
            var original = $btn.html();
            $btn.html('<i class="ri-checkbox-circle-line"></i> Copied');
            setTimeout(function () { $btn.html(original); }, 1600);
            ssNotify.success('Copied to clipboard.', { duration: 2500, title: null });
        }

        function fallback() {
            // execCommand is deprecated but remains the only option on plain
            // HTTP admin panels, where navigator.clipboard is undefined.
            $target.trigger('focus');
            $target[0].setSelectionRange(0, text.length);
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            if (ok) {
                done();
            } else {
                ssNotify.warning('Could not copy automatically. Select the text and copy it manually.');
            }
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, fallback);
        } else {
            fallback();
        }
    });

    $(document).on('click', '#sitessaver-regenerate-cron-key', function () {
        var $btn = $(this);

        ssNotify.confirm({
            tone: 'warning',
            title: 'Generate a new trigger URL?',
            message: 'The current URL stops working immediately. Any server cron or uptime monitor using it must be updated with the new URL, or your scheduled backups will stop running.',
            confirmText: 'Generate new URL',
            cancelText: 'Cancel',
            onConfirm: function () {
                $btn.prop('disabled', true);
                ajax('sitessaver_regenerate_cron_key', {}, function (res) {
                    ssFlash('success', res.message || 'New trigger URL generated.');
                    location.reload();
                }, function (err) {
                    $btn.prop('disabled', false);
                    ssNotify.error(err.message || SS.strings.error);
                });
            }
        });
    });

    $(document).on('click', '#sitessaver-run-schedule-now', function () {
        var $btn = $(this);

        ssNotify.confirm({
            title: 'Run a backup now?',
            message: 'This runs the scheduled backup immediately using the settings saved above, so you can confirm the schedule works without waiting for the next run.',
            confirmText: 'Run backup',
            cancelText: 'Cancel',
            onConfirm: function () {
                $btn.prop('disabled', true);

                ssModal.open({
                    title:      'Running scheduled backup',
                    subtitle:   'Creating a backup with your saved schedule settings.',
                    caution:    'Do not close this tab while the backup is being created.',
                    cancelable: false
                });
                ssModal.setIndeterminate('Backing up your site...');

                ajax('sitessaver_run_schedule_now', {}, function (res) {
                    ssModal.done();
                    setTimeout(function () {
                        ssModal.close();
                        $btn.prop('disabled', false);
                        ssFlash('success', res.message || SS.strings.done);
                        location.reload();
                    }, 800);
                }, function (err) {
                    ssModal.close();
                    $btn.prop('disabled', false);
                    ssNotify.error(err.message || SS.strings.error, { title: 'Backup failed' });
                });
            }
        });
    });


    // ---------- SETTINGS ----------

    $(document).on('submit', '#sitessaver-settings-form', function (e) {
        e.preventDefault();
        var $btn = $(this).find('button[type=submit]').prop('disabled', true);

        ajax('sitessaver_save_settings', { gdrive_folder_id: $(this).find('[name=gdrive_folder_id]').val() }, function (res) {
            $btn.prop('disabled', false);
            // No reload needed — nothing else on this page derives from the
            // folder ID, so a toast is less disruptive than a full refresh.
            ssNotify.success(res.message || SS.strings.done);
        }, function (err) {
            $btn.prop('disabled', false);
            ssNotify.error(err.message || SS.strings.error);
        });
    });


    // ---------- GOOGLE DRIVE ----------

    $(document).on('click', '#sitessaver-gdrive-disconnect', function () {
        ssNotify.confirm({
            tone: 'warning',
            title: 'Disconnect Google Drive?',
            message: 'SitesSaver will stop uploading backups to Drive. Backups already stored there are not deleted, and you can reconnect at any time.',
            confirmText: 'Disconnect',
            cancelText: 'Stay connected',
            onConfirm: function () {
                ajax('sitessaver_gdrive_disconnect', {}, function () {
                    ssFlash('success', 'Google Drive disconnected.');
                    location.reload();
                }, function (err) {
                    ssNotify.error(err.message || SS.strings.error);
                });
            }
        });
    });

    $(document).on('click', '.sitessaver-gdrive-upload-btn', function () {
        var file = $(this).data('file');
        var $btn = $(this);
        var jobId = generateUploadId();
        var $wrap = $('#sitessaver-backups-page'); // Main container for progress UI
        
        if (!$wrap.length) $wrap = $('.sitessaver-wrap');

        $btn.prop('disabled', true).addClass('loading');
        
        updateProgress($wrap, 0, 'Preparing upload...');

        // Declared before the request so the completion handlers can stop the
        // poller below. Both callbacks fire asynchronously, well after the
        // assignment, so the reference is always live by then.
        var pollInterval;

        // Start the upload process (async on server)
        ajax('sitessaver_gdrive_upload', { file: file, job_id: jobId }, function (res) {
            // This will only return when the WHOLE upload is finished
            clearInterval(pollInterval);
            hideProgress($wrap);
            ssNotify.success(res.message || 'Backup uploaded to Google Drive.', { title: 'Upload complete' });
            $btn.prop('disabled', false).removeClass('loading');
        }, function (err) {
            clearInterval(pollInterval);
            hideProgress($wrap);
            ssNotify.error(err.message || SS.strings.error, { title: 'Upload failed' });
            $btn.prop('disabled', false).removeClass('loading');
        });

        // Start polling for progress
        pollInterval = setInterval(function() {
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
            ssFlash('success', res.message || 'Backup downloaded from Google Drive.');
            location.reload();
        }, function (err) {
            ssNotify.error(err.message || SS.strings.error, { title: 'Download failed' });
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.sitessaver-gdrive-restore-btn', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        var name = $btn.data('name') || 'this backup';

        ssNotify.confirm({
            tone: 'danger',
            title: 'Restore from Google Drive?',
            message: 'SitesSaver will download "' + name + '" and overwrite your current site with it. This cannot be undone.',
            confirmText: 'Yes, restore',
            cancelText: 'Cancel',
            onConfirm: function () {
                $btn.prop('disabled', true);

                // The whole operation (download + extract + DB import + file
                // restore) runs in one request and cannot be interrupted, so
                // use the blocking progress modal rather than the inline bar
                // that used to sit behind other page content.
                ssModal.open({
                    title:      'Restoring from Google Drive',
                    subtitle:   'Downloading the backup and restoring your site. Please wait.',
                    caution:    'Do not close this tab. Interrupting the restore may leave your site in a broken state.',
                    cancelable: false
                });
                ssModal.setIndeterminate('Downloading and restoring...');

                ajax('sitessaver_gdrive_restore', { file_id: id }, function (res) {
                    ssModal.done();
                    setTimeout(function () {
                        ssModal.close();
                        showRestoreCompleteModal(res);
                    }, 800);
                }, function (err) {
                    ssModal.close();
                    ssNotify.error(err.message || SS.strings.error, { title: 'Restore failed' });
                    $btn.prop('disabled', false);
                });
            }
        });
    });

    $(document).on('click', '.sitessaver-gdrive-delete-btn', function () {
        var id = $(this).data('id');

        ssNotify.confirm({
            tone: 'danger',
            title: 'Delete from Google Drive?',
            message: 'This backup will be permanently removed from your Google Drive. This cannot be undone.',
            confirmText: 'Delete backup',
            cancelText: 'Keep it',
            onConfirm: function () {
                ajax('sitessaver_gdrive_delete', { file_id: id }, function () {
                    ssNotify.success('Backup deleted from Google Drive.');
                    $('#sitessaver-gdrive-refresh').trigger('click');
                }, function (err) {
                    ssNotify.error(err.message || SS.strings.error);
                });
            }
        });
    });



    // ---------- ACTIVE-EXPORT DETECTION (opt-in resume, never auto-run) ----------

    function runExportLoop($form, uid, steps, startStep, gdriveJob) {
        var currentStep = startStep;
        var $btn        = $('#sitessaver-export-btn');
        var cancelled   = false;
        var gdrivePoll  = null;
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

            if (step.poll === 'gdrive') {
                ssModal.disableCancel('Uploading to Drive…');
                ssModal.setProgress(step.from || 0, step.label);
                gdrivePoll = pollGdriveInto(ssModal, gdriveJob, step, gdrivePoll);
            } else {
                ssModal.setProgress(step.pct, step.label);
            }

            ajax('sitessaver_export_step', { uid: uid, step_index: currentStep }, function (stepRes) {
                gdrivePoll = stopPoll(gdrivePoll);
                if (stepRes.success) {
                    currentStep++;
                    runNextStep();
                } else {
                    ssModal.close();
                    showResult($form, stepRes.message || SS.strings.error, true);
                    $btn.prop('disabled', false);
                }
            }, function (err) {
                gdrivePoll = stopPoll(gdrivePoll);
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
                runExportLoop($form, uid, steps, currentStep, res.gdrive_job_id);
            });

            $form.on('click', '.ss-discard-btn', function () {
                ssNotify.confirm({
                    tone: 'danger',
                    title: 'Discard this export?',
                    message: 'The partially written backup will be deleted and you will need to start a new export.',
                    confirmText: 'Discard export',
                    cancelText: 'Keep it',
                    onConfirm: function () {
                        ajax('sitessaver_cancel_export', { uid: uid }, function () {
                            $('.ss-resume-banner').remove();
                            $('#sitessaver-export-btn').prop('disabled', false);
                            ssNotify.info('Export discarded.');
                        }, function (err) {
                            ssNotify.error(err.message || SS.strings.error);
                        });
                    }
                });
            });
        });
    }

    checkActiveExport();

})(jQuery);
