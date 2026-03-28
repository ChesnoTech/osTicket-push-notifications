/**
 * Push Notifications Plugin - Client-Side Logic
 *
 * Handles service worker registration, push subscription management,
 * the bell toggle UI, and agent preferences modal.
 *
 * @author  ChesnoTech
 * @version 1.1.0
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
            var scopeUrl = swUrl.replace(/\/[^/]*$/, '/');

            return navigator.serviceWorker.register(swUrl, {
                scope: scopeUrl
            }).then(function(reg) {
                PushUI.swRegistration = reg;
                return reg;
            });
        },

        // SVG icons
        BELL_SVG: '<svg class="push-bell-svg" style="width:18px;height:18px" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>'
            + '</svg>',

        BELL_OFF_SVG: '<svg class="push-bell-svg" style="width:18px;height:18px" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/>'
            + '</svg>',

        // Mobile SVGs (24x24 with padding to match mobile nav icons)
        MOBILE_BELL_SVG: '<svg style="width:24px;height:24px;padding:18px;float:right;margin-right:1px;" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>'
            + '</svg>',

        MOBILE_BELL_OFF_SVG: '<svg style="width:24px;height:24px;padding:18px;float:right;margin-right:1px;" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/>'
            + '</svg>',

        MOBILE_GEAR_SVG: '<svg style="width:20px;height:20px;padding:20px 8px 20px 0;float:right;" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 00-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1115.6 12 3.61 3.61 0 0112 15.6z"/>'
            + '</svg>',

        GEAR_SVG: '<svg class="push-gear-svg" viewBox="0 0 24 24">'
            + '<path fill="currentColor" d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 00-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1115.6 12 3.61 3.61 0 0112 15.6z"/>'
            + '</svg>',

        injectToggle: function() {
            // Build bell + gear wrapper
            var $wrapper = $('<span id="push-notify-wrapper" class="push-notify-wrapper"></span>');
            var $toggle = $(
                '<a href="#" id="push-notify-toggle" class="push-notify-toggle no-pjax" title="Push Notifications">'
                + this.BELL_OFF_SVG
                + '</a>'
            );
            var $gear = $(
                '<a href="#" id="push-notify-gear" class="push-notify-gear no-pjax" title="Notification Preferences">'
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
                    '<a href="#" id="push-notify-toggle-mobile" class="mobile-nav push-notify-toggle no-pjax" title="Push Notifications">'
                    + PushUI.MOBILE_BELL_OFF_SVG + '</a>'
                );
                var $mobileGear = $(
                    '<a href="#" id="push-notify-gear-mobile" class="mobile-nav push-notify-gear-mobile no-pjax" title="Notification Preferences">'
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
                if (sub) {
                    $toggle.html(PushUI.BELL_SVG)
                           .addClass('push-active')
                           .attr('title', 'Push Notifications (enabled)');
                    $mobile.html(PushUI.MOBILE_BELL_SVG)
                           .addClass('push-active')
                           .attr('title', 'Push Notifications (enabled)');
                    $gear.show();
                    $mobileGear.show();
                } else {
                    $toggle.html(PushUI.BELL_OFF_SVG)
                           .removeClass('push-active')
                           .attr('title', 'Push Notifications (disabled)');
                    $mobile.html(PushUI.MOBILE_BELL_OFF_SVG)
                           .removeClass('push-active')
                           .attr('title', 'Push Notifications (disabled)');
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
                PushUI.showToast('Push notifications enabled');
            }).catch(function(err) {
                console.error('[PushNotifications] Subscribe error:', err);
                if (Notification.permission === 'denied') {
                    PushUI.showToast('Push notifications blocked by browser. Check site permissions.');
                } else {
                    PushUI.showToast('Failed to enable push notifications');
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
                PushUI.showToast('Push notifications disabled');
            }).catch(function(err) {
                console.error('[PushNotifications] Unsubscribe error:', err);
            });
        },

        // ============================================================
        // Preferences Modal
        // ============================================================

        openPreferencesModal: function() {
            // Remove existing modal
            $('#push-prefs-overlay').remove();

            // Show loading state
            var $overlay = $('<div id="push-prefs-overlay" class="push-prefs-overlay"></div>');
            var $modal = $('<div class="push-prefs-modal"></div>');
            $modal.html('<div class="push-prefs-loading">Loading preferences...</div>');
            $overlay.append($modal);
            $('body').append($overlay);

            // Close on overlay click
            $overlay.on('click', function(e) {
                if (e.target === this)
                    PushUI.closePreferencesModal();
            });

            // Fetch preferences
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
                $modal.html('<div class="push-prefs-loading">Failed to load preferences.</div>');
            });
        },

        renderPreferencesModal: function($modal, prefs, depts) {
            var html = '<div class="push-prefs-header">'
                + '<h3>Notification Preferences</h3>'
                + '<a href="#" class="push-prefs-close" title="Close">&times;</a>'
                + '</div>'
                + '<div class="push-prefs-body">';

            // Section 1: Event toggles
            html += '<div class="push-prefs-section">'
                + '<h4>Event Types</h4>'
                + '<p class="push-prefs-hint">Choose which events trigger push notifications.</p>';

            var events = [
                { key: 'event_new_ticket',  label: 'New Ticket' },
                { key: 'event_new_message', label: 'New Message / Reply' },
                { key: 'event_assignment',  label: 'Ticket Assignment' },
                { key: 'event_transfer',    label: 'Ticket Transfer' },
                { key: 'event_overdue',     label: 'Overdue Ticket' }
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
            html += '<div class="push-prefs-section">'
                + '<h4>Departments</h4>'
                + '<p class="push-prefs-hint">Select departments to receive notifications for. Leave all unchecked to receive from all your departments.</p>';

            if (depts.length === 0) {
                html += '<p class="push-prefs-hint" style="font-style:italic">No departments available.</p>';
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
            html += '<div class="push-prefs-section">'
                + '<h4>Quiet Hours</h4>'
                + '<p class="push-prefs-hint">Suppress push notifications during these hours. Leave empty to receive notifications 24/7.</p>'
                + '<div class="push-prefs-quiet">'
                + '<label>From <input type="time" name="quiet_start" value="' + (prefs.quiet_start || '') + '"></label>'
                + '<label>To <input type="time" name="quiet_end" value="' + (prefs.quiet_end || '') + '"></label>'
                + '<a href="#" class="push-prefs-quiet-clear">Clear</a>'
                + '</div>'
                + '</div>';

            html += '</div>'; // end .push-prefs-body

            // Footer
            html += '<div class="push-prefs-footer">'
                + '<button type="button" class="push-prefs-btn push-prefs-btn-cancel">Cancel</button>'
                + '<button type="button" class="push-prefs-btn push-prefs-btn-save">Save</button>'
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

            // Event toggles
            var eventKeys = ['event_new_ticket', 'event_new_message', 'event_assignment',
                             'event_transfer', 'event_overdue'];
            for (var i = 0; i < eventKeys.length; i++) {
                data[eventKeys[i]] = $modal.find('input[name="' + eventKeys[i] + '"]').is(':checked') ? 1 : 0;
            }

            // Department IDs
            var deptIds = [];
            $modal.find('input[name="dept_id"]:checked').each(function() {
                deptIds.push(parseInt($(this).val(), 10));
            });
            data.dept_ids = deptIds;

            // Quiet hours
            data.quiet_start = $modal.find('input[name="quiet_start"]').val() || '';
            data.quiet_end = $modal.find('input[name="quiet_end"]').val() || '';

            var $saveBtn = $modal.find('.push-prefs-btn-save');
            $saveBtn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: config.preferencesUrl,
                method: 'POST',
                contentType: 'application/json',
                headers: { 'X-CSRFToken': config.csrfToken },
                data: JSON.stringify(data)
            }).then(function() {
                PushUI.prefsCache = data;
                PushUI.closePreferencesModal();
                PushUI.showToast('Notification preferences saved');
            }).catch(function(err) {
                console.error('[PushNotifications] Save preferences error:', err);
                $saveBtn.prop('disabled', false).text('Save');
                PushUI.showToast('Failed to save preferences');
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
                $toggle.attr('title',
                    'Add this site to your Home Screen to enable push notifications on iOS');
                $toggle.on('click.ios-hint', function(e) {
                    e.preventDefault();
                    PushUI.showToast(
                        'To enable push notifications on iOS, tap the Share button '
                        + 'and select "Add to Home Screen", then open the app from there.'
                    );
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
