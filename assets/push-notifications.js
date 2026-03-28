/**
 * Push Notifications Plugin - Client-Side Logic
 *
 * Handles service worker registration, push subscription management,
 * the bell toggle UI, and agent preferences modal.
 *
 * @author  ChesnoTech
 * @version 1.2.0
 */

(function($) {
    'use strict';

    var config = window.__PUSH_CONFIG;
    if (!config || !config.vapidPublicKey)
        return;

    // Feature detection
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.log('[PushNotifications] Browser does not support push notifications');
        return;
    }

    // i18n helper — falls back to key if string not found
    var S = function(key) {
        if (config.strings && key in config.strings)
            return config.strings[key];
        return key;
    };

    var PushUI = {
        swRegistration: null,
        prefsCache: null,
        deptsCache: null,

        init: function() {
            this.injectToggle();
            this.registerServiceWorker().then(function() {
                PushUI.updateToggleState();
            }).catch(function(err) {
                console.warn('[PushNotifications] SW registration failed:', err);
            });
        },

        registerServiceWorker: function() {
            var swUrl = config.swUrl;
            // Scope must be /scp/ (not the SW file's parent path)
            // The SW file sends Service-Worker-Allowed: /scp/ header to permit this
            var scopeUrl = swUrl.replace(/\/ajax\.php\/.*$/, '/');

            return navigator.serviceWorker.register(swUrl, {
                scope: scopeUrl
            }).then(function(reg) {
                PushUI.swRegistration = reg;
                return reg;
            });
        },

        // Desktop SVGs — bell filled (enabled) vs bell-with-slash (disabled)
        BELL_SVG: '<svg class="push-bell-svg" style="width:18px;height:18px" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>'
            + '</svg>',

        // Material Design "notifications_off" — bell with diagonal slash
        BELL_OFF_SVG: '<svg class="push-bell-svg" style="width:18px;height:18px" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M20 18.69L7.84 6.14 5.27 3.49 4 4.76l2.8 2.8v.01c-.52.99-.8 2.16-.8 3.43v5l-2 2v1h13.73l2 2L21 19.97l-1-1.28zM12 22c1.11 0 2-.89 2-2h-4c0 1.11.89 2 2 2zm6-7.32V11c0-3.08-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68c-.15.03-.29.08-.42.12-.1.03-.2.07-.3.11h-.01c-.01 0-.01 0-.02.01-.23.09-.46.2-.68.31 0 0 0 0-.01.01L18 14.68z"/>'
            + '</svg>',

        // Mobile SVGs (24x24 with padding to match mobile nav icons)
        MOBILE_BELL_SVG: '<svg style="width:24px;height:24px;padding:18px;float:right;margin-right:1px;" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>'
            + '</svg>',

        MOBILE_BELL_OFF_SVG: '<svg style="width:24px;height:24px;padding:18px;float:right;margin-right:1px;" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M20 18.69L7.84 6.14 5.27 3.49 4 4.76l2.8 2.8v.01c-.52.99-.8 2.16-.8 3.43v5l-2 2v1h13.73l2 2L21 19.97l-1-1.28zM12 22c1.11 0 2-.89 2-2h-4c0 1.11.89 2 2 2zm6-7.32V11c0-3.08-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68c-.15.03-.29.08-.42.12-.1.03-.2.07-.3.11h-.01c-.01 0-.01 0-.02.01-.23.09-.46.2-.68.31 0 0 0 0-.01.01L18 14.68z"/>'
            + '</svg>',

        MOBILE_GEAR_SVG: '<svg style="width:20px;height:20px;padding:20px 8px 20px 0;float:right;" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 00-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1115.6 12 3.61 3.61 0 0112 15.6z"/>'
            + '</svg>',

        GEAR_SVG: '<svg class="push-gear-svg" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 00-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1115.6 12 3.61 3.61 0 0112 15.6z"/>'
            + '</svg>',

        injectToggle: function() {
            var titleText = S('pushNotifications');

            // Build bell + gear wrapper
            var $wrapper = $('<span id="push-notify-wrapper" class="push-notify-wrapper"></span>');
            var $toggle = $(
                '<a href="#" id="push-notify-toggle" class="push-notify-toggle no-pjax" title="' + titleText + '">'
                + this.BELL_OFF_SVG
                + '</a>'
            );
            var $gear = $(
                '<a href="#" id="push-notify-gear" class="push-notify-gear no-pjax" title="' + S('notifPreferences') + '">'
                + this.GEAR_SVG
                + '</a>'
            );
            $wrapper.append($toggle).append($gear);

            // Desktop: insert before the dark-mode or logout link in #nav
            var $darkMode = $('#dark-mode-link');
            var $logout = $('#logout, a[href*="logout.php"]').first();
            if ($darkMode.length) {
                $wrapper.insertBefore($darkMode);
            } else if ($logout.length) {
                $wrapper.insertBefore($logout);
            } else {
                var $nav = $('#nav');
                if ($nav.length)
                    $nav.append($wrapper);
            }

            // Mobile: bell + gear in #right-buttons
            var $rightButtons = $('#right-buttons');
            if ($rightButtons.length && !$rightButtons.find('#push-notify-toggle-mobile').length) {
                var $mobileBell = $(
                    '<a href="#" id="push-notify-toggle-mobile" class="mobile-nav push-notify-toggle no-pjax" title="' + titleText + '">'
                    + PushUI.MOBILE_BELL_OFF_SVG + '</a>'
                );
                var $mobileGear = $(
                    '<a href="#" id="push-notify-gear-mobile" class="mobile-nav push-notify-gear-mobile no-pjax" title="' + S('notifPreferences') + '">'
                    + PushUI.MOBILE_GEAR_SVG + '</a>'
                );
                // Gear first (float:right), then bell — renders as: bell | gear from left
                $rightButtons.prepend($mobileGear);
                $rightButtons.prepend($mobileBell);

                $mobileBell.on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    PushUI.toggleSubscription();
                });

                $mobileGear.on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    PushUI.openPreferencesModal();
                });
            }

            $toggle.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                PushUI.toggleSubscription();
            });

            $gear.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                PushUI.openPreferencesModal();
            });

            // iOS standalone hint
            PushUI.checkiOSHint();
        },

        updateToggleState: function() {
            if (!PushUI.swRegistration)
                return;

            PushUI.swRegistration.pushManager.getSubscription().then(function(sub) {
                var $toggle = $('#push-notify-toggle');
                var $mobile = $('#push-notify-toggle-mobile');
                var $gear = $('#push-notify-gear');
                var $mobileGear = $('#push-notify-gear-mobile');
                var titleBase = S('pushNotifications');
                if (sub) {
                    $toggle.html(PushUI.BELL_SVG)
                           .addClass('push-active')
                           .attr('title', titleBase + ' (' + S('enabled') + ')');
                    $mobile.html(PushUI.MOBILE_BELL_SVG)
                           .addClass('push-active')
                           .attr('title', titleBase + ' (' + S('enabled') + ')');
                    $gear.show();
                    $mobileGear.show();
                } else {
                    $toggle.html(PushUI.BELL_OFF_SVG)
                           .removeClass('push-active')
                           .attr('title', titleBase + ' (' + S('disabled') + ')');
                    $mobile.html(PushUI.MOBILE_BELL_OFF_SVG)
                           .removeClass('push-active')
                           .attr('title', titleBase + ' (' + S('disabled') + ')');
                    $gear.hide();
                    $mobileGear.hide();
                }
            });
        },

        toggleSubscription: function() {
            if (!PushUI.swRegistration) {
                console.warn('[PushNotifications] Service worker not registered');
                return;
            }

            PushUI.swRegistration.pushManager.getSubscription().then(function(sub) {
                if (sub) {
                    PushUI.unsubscribe(sub);
                } else {
                    PushUI.subscribe();
                }
            });
        },

        subscribe: function() {
            var applicationServerKey = PushUI.urlBase64ToUint8Array(config.vapidPublicKey);

            PushUI.swRegistration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey
            }).then(function(sub) {
                var p256dh = sub.getKey('p256dh');
                var auth = sub.getKey('auth');

                return $.ajax({
                    url: config.subscribeUrl,
                    method: 'POST',
                    contentType: 'application/json',
                    headers: { 'X-CSRFToken': config.csrfToken },
                    data: JSON.stringify({
                        endpoint: sub.endpoint,
                        keys: {
                            p256dh: PushUI.arrayBufferToBase64Url(p256dh),
                            auth: PushUI.arrayBufferToBase64Url(auth)
                        },
                        encoding: 'aes128gcm'
                    })
                });
            }).then(function() {
                PushUI.updateToggleState();
                PushUI.showToast(S('pushEnabled'));
            }).catch(function(err) {
                console.error('[PushNotifications] Subscribe error:', err);
                if (Notification.permission === 'denied') {
                    PushUI.showToast(S('pushBlocked'));
                } else {
                    PushUI.showToast(S('pushFailed'));
                }
            });
        },

        unsubscribe: function(sub) {
            var endpoint = sub.endpoint;
            sub.unsubscribe().then(function() {
                return $.ajax({
                    url: config.unsubscribeUrl,
                    method: 'POST',
                    contentType: 'application/json',
                    headers: { 'X-CSRFToken': config.csrfToken },
                    data: JSON.stringify({ endpoint: endpoint })
                });
            }).then(function() {
                PushUI.updateToggleState();
                PushUI.showToast(S('pushDisabled'));
            }).catch(function(err) {
                console.error('[PushNotifications] Unsubscribe error:', err);
            });
        },

        // ============================================================
        // Preferences Modal
        // ============================================================

        openPreferencesModal: function() {
            $('#push-prefs-overlay').remove();

            var $overlay = $('<div id="push-prefs-overlay" class="push-prefs-overlay"></div>');
            var $modal = $('<div class="push-prefs-modal"></div>');
            $modal.html('<div class="push-prefs-loading">' + S('loading') + '</div>');
            $overlay.append($modal);
            $('body').append($overlay);

            $overlay.on('click', function(e) {
                if (e.target === this)
                    PushUI.closePreferencesModal();
            });

            $.ajax({
                url: config.preferencesUrl,
                method: 'GET',
                headers: { 'X-CSRFToken': config.csrfToken }
            }).then(function(resp) {
                var data = typeof resp === 'string' ? JSON.parse(resp) : resp;
                PushUI.prefsCache = data.preferences;
                PushUI.deptsCache = data.departments;
                PushUI.renderPreferencesModal($modal, data.preferences, data.departments);
            }).catch(function(err) {
                console.error('[PushNotifications] Preferences load error:', err);
                $modal.html('<div class="push-prefs-loading">' + S('loadFailed') + '</div>');
            });
        },

        renderPreferencesModal: function($modal, prefs, depts) {
            var html = '<div class="push-prefs-header">'
                + '<h3>' + S('prefTitle') + '</h3>'
                + '<a href="#" class="push-prefs-close" title="' + S('prefClose') + '">&times;</a>'
                + '</div>'
                + '<div class="push-prefs-body">';

            // Section 1: Event toggles
            var eventHint = S('eventTypesHint');
            html += '<div class="push-prefs-section">'
                + '<h4>' + S('eventTypes') + '</h4>'
                + (eventHint ? '<p class="push-prefs-hint">' + eventHint + '</p>' : '');

            var events = [
                { key: 'event_new_ticket',  label: S('newTicket') },
                { key: 'event_new_message', label: S('newMessage') },
                { key: 'event_assignment',  label: S('ticketAssignment') },
                { key: 'event_transfer',    label: S('ticketTransfer') },
                { key: 'event_overdue',     label: S('overdueTicket') }
            ];

            for (var i = 0; i < events.length; i++) {
                var ev = events[i];
                var checked = prefs[ev.key] ? ' checked' : '';
                html += '<label class="push-prefs-toggle">'
                    + '<input type="checkbox" name="' + ev.key + '"' + checked + '>'
                    + '<span class="push-prefs-toggle-slider"></span>'
                    + '<span class="push-prefs-toggle-label">' + ev.label + '</span>'
                    + '</label>';
            }
            html += '</div>';

            // Section 2: Department filter
            var deptHint = S('departmentsHint');
            html += '<div class="push-prefs-section">'
                + '<h4>' + S('departments') + '</h4>'
                + (deptHint ? '<p class="push-prefs-hint">' + deptHint + '</p>' : '');

            if (depts.length === 0) {
                html += '<p class="push-prefs-hint" style="font-style:italic">' + S('noDepartments') + '</p>';
            } else {
                var selectedDepts = prefs.dept_ids || [];
                for (var d = 0; d < depts.length; d++) {
                    var dept = depts[d];
                    var dChecked = '';
                    for (var s = 0; s < selectedDepts.length; s++) {
                        if (String(selectedDepts[s]) === String(dept.id)) {
                            dChecked = ' checked';
                            break;
                        }
                    }
                    html += '<label class="push-prefs-toggle">'
                        + '<input type="checkbox" name="dept_id" value="' + dept.id + '"' + dChecked + '>'
                        + '<span class="push-prefs-toggle-slider"></span>'
                        + '<span class="push-prefs-toggle-label">' + $('<span>').text(dept.name).html() + '</span>'
                        + '</label>';
                }
            }
            html += '</div>';

            // Section 3: Quiet hours
            var quietHint = S('quietHoursHint');
            html += '<div class="push-prefs-section">'
                + '<h4>' + S('quietHours') + '</h4>'
                + (quietHint ? '<p class="push-prefs-hint">' + quietHint + '</p>' : '')
                + '<div class="push-prefs-quiet">'
                + '<label>' + S('from') + ' <input type="time" name="quiet_start" value="' + (prefs.quiet_start || '') + '"></label>'
                + '<label>' + S('to') + ' <input type="time" name="quiet_end" value="' + (prefs.quiet_end || '') + '"></label>'
                + '<a href="#" class="push-prefs-quiet-clear">' + S('clear') + '</a>'
                + '</div>'
                + '</div>';

            html += '</div>'; // end .push-prefs-body

            // Footer
            html += '<div class="push-prefs-footer">'
                + '<button type="button" class="push-prefs-btn push-prefs-btn-cancel">' + S('cancel') + '</button>'
                + '<button type="button" class="push-prefs-btn push-prefs-btn-save">' + S('save') + '</button>'
                + '</div>';

            $modal.html(html);

            // Bind events
            $modal.find('.push-prefs-close').on('click', function(e) {
                e.preventDefault();
                PushUI.closePreferencesModal();
            });
            $modal.find('.push-prefs-btn-cancel').on('click', function() {
                PushUI.closePreferencesModal();
            });
            $modal.find('.push-prefs-btn-save').on('click', function() {
                PushUI.savePreferences($modal);
            });
            $modal.find('.push-prefs-quiet-clear').on('click', function(e) {
                e.preventDefault();
                $modal.find('input[name="quiet_start"]').val('');
                $modal.find('input[name="quiet_end"]').val('');
            });
        },

        savePreferences: function($modal) {
            var data = {};

            var eventKeys = ['event_new_ticket', 'event_new_message', 'event_assignment',
                             'event_transfer', 'event_overdue'];
            for (var i = 0; i < eventKeys.length; i++) {
                data[eventKeys[i]] = $modal.find('input[name="' + eventKeys[i] + '"]').is(':checked') ? 1 : 0;
            }

            var deptIds = [];
            $modal.find('input[name="dept_id"]:checked').each(function() {
                deptIds.push(parseInt($(this).val(), 10));
            });
            data.dept_ids = deptIds;

            data.quiet_start = $modal.find('input[name="quiet_start"]').val() || '';
            data.quiet_end = $modal.find('input[name="quiet_end"]').val() || '';

            var $saveBtn = $modal.find('.push-prefs-btn-save');
            $saveBtn.prop('disabled', true).text(S('saving'));

            $.ajax({
                url: config.preferencesUrl,
                method: 'POST',
                contentType: 'application/json',
                headers: { 'X-CSRFToken': config.csrfToken },
                data: JSON.stringify(data)
            }).then(function() {
                PushUI.prefsCache = data;
                PushUI.closePreferencesModal();
                PushUI.showToast(S('prefsSaved'));
            }).catch(function(err) {
                console.error('[PushNotifications] Save preferences error:', err);
                $saveBtn.prop('disabled', false).text(S('save'));
                PushUI.showToast(S('prefsSaveFailed'));
            });
        },

        closePreferencesModal: function() {
            var $overlay = $('#push-prefs-overlay');
            $overlay.addClass('push-prefs-overlay-hide');
            setTimeout(function() { $overlay.remove(); }, 200);
        },

        // ============================================================
        // iOS hint
        // ============================================================

        checkiOSHint: function() {
            var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            if (!isIOS)
                return;

            var isStandalone = window.navigator.standalone === true
                || window.matchMedia('(display-mode: standalone)').matches;

            if (!isStandalone) {
                var $toggle = $('#push-notify-toggle');
                $toggle.on('click.ios-hint', function(e) {
                    e.preventDefault();
                    PushUI.showToast(S('iosHint'));
                });
            }
        },

        showToast: function(message) {
            $('.push-notify-toast').remove();

            var $toast = $('<div class="push-notify-toast"></div>').text(message);
            $('body').append($toast);

            setTimeout(function() {
                $toast.addClass('push-notify-toast-hide');
                setTimeout(function() { $toast.remove(); }, 300);
            }, 4000);
        },

        // --- Utility functions ---

        urlBase64ToUint8Array: function(base64String) {
            var padding = '='.repeat((4 - base64String.length % 4) % 4);
            var base64 = (base64String + padding)
                .replace(/-/g, '+')
                .replace(/_/g, '/');
            var rawData = window.atob(base64);
            var outputArray = new Uint8Array(rawData.length);
            for (var i = 0; i < rawData.length; ++i)
                outputArray[i] = rawData.charCodeAt(i);
            return outputArray;
        },

        arrayBufferToBase64Url: function(buffer) {
            var bytes = new Uint8Array(buffer);
            var binary = '';
            for (var i = 0; i < bytes.byteLength; i++)
                binary += String.fromCharCode(bytes[i]);
            return window.btoa(binary)
                .replace(/\+/g, '-')
                .replace(/\//g, '_')
                .replace(/=+$/, '');
        }
    };

    // Initialize on DOM ready
    $(function() {
        PushUI.init();
    });

})(jQuery);
