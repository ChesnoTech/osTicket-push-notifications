<?php
return array(
    'id'          => 'osticket:push-notifications',
    'version'     => '1.0.0',
    'name'        => 'Push Notifications',
    'author'      => 'ChesnoTech',
    'description' => 'Web Push (PWA) notifications for staff. Mirrors email alerts for ticket events including new tickets, messages, assignments, transfers, and overdue tickets.',
    'url'         => 'https://github.com/ChesnoTech/ost-push-notifications',
    'ost_version' => '1.18',
    'plugin'      => 'class.PushNotificationsPlugin.php:PushNotificationsPlugin',
);
