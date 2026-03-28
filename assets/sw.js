/**
 * Push Notifications Plugin - Service Worker
 *
 * Handles push events and notification clicks for osTicket staff panel.
 *
 * @author  ChesnoTech
 * @version 1.0.0
 */

self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function(event) {
    if (!event.data)
        return;

    var data;
    try {
        data = event.data.json();
    } catch (e) {
        // Fallback for plain text
        data = {
            title: 'osTicket',
            body: event.data.text()
        };
    }

    var title = data.title || 'osTicket Notification';
    var options = {
        body: data.body || '',
        icon: data.icon || '/scp/images/ost-logo.png',
        badge: data.badge || '/scp/images/ost-logo.png',
        tag: data.tag || 'ost-notification',
        renotify: true,
        requireInteraction: false,
        data: data.data || {}
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    var url = event.notification.data && event.notification.data.url
        ? event.notification.data.url
        : '/scp/';

    // Make absolute URL if relative
    if (url.charAt(0) === '/') {
        url = self.location.origin + url;
    }

    event.waitUntil(
        self.clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        }).then(function(clientList) {
            // Try to focus an existing staff panel tab
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if (client.url.indexOf('/scp/') !== -1 && 'focus' in client) {
                    return client.focus().then(function(c) {
                        if ('navigate' in c)
                            return c.navigate(url);
                    });
                }
            }
            // No existing tab found — open a new one
            if (self.clients.openWindow)
                return self.clients.openWindow(url);
        })
    );
});
