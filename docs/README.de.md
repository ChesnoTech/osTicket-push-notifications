[English](../README.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [Español](README.es.md) | [Français](README.fr.md) | 🌐 **Deutsch** | [中文](README.zh.md) | [Português](README.pt.md) | [Türkçe](README.tr.md)

# osTicket Push-Benachrichtigungs-Plugin

Web-Push-Benachrichtigungen (PWA) für das osTicket-Mitarbeiterfeld. Liefert Echtzeit-Browser-Push-Benachrichtigungen für Ticket-Ereignisse – vollständig unabhängig von E-Mail-Benachrichtigungen.

## Funktionen

- **Echtzeit-Push-Benachrichtigungen** für: neue Tickets, neue Nachrichten/Antworten, Zuweisungen, Weiterleitungen, überfällige Tickets
- **Unabhängig von E-Mail-Benachrichtigungen** – funktioniert auch, wenn alle E-Mail-Benachrichtigungen deaktiviert sind
- **Mitarbeitereinstellungen** mit ereignisbezogenen Schaltern, abteilungsbasierter Filterung und Ruhezeiten
- **Administratorsteuerung** mit Hauptschalter, ereignisbezogenen Schaltern, benutzerdefiniertem Benachrichtigungssymbol und VAPID-Schlüsselverwaltung
- **Mehrsprachige Unterstützung** über das integrierte Übersetzungssystem von osTicket
- **Mobilgeräteoptimiert** mit Glocken- und Zahnradsymbol in der mobilen Navigationsleiste
- **Dunkelmodus-kompatibel** (osTicketAwesome-Theme)
- **Service-Worker**-basiert – funktioniert auch, wenn der Browser-Tab geschlossen ist
- **Keine Abhängigkeiten** – reine PHP-Web-Push-Implementierung, kein Composer erforderlich

## Voraussetzungen

- osTicket **1.18+**
- PHP **8.0+** mit `openssl`-Erweiterung
- HTTPS (erforderlich durch die Web-Push-API)

## Installation

1. Kopieren Sie den Ordner `push-notifications/` nach `include/plugins/`
2. Gehen Sie im Adminbereich zu **Verwalten > Plugins > Neues Plugin hinzufügen**
3. Klicken Sie neben „Push Notifications" auf **Installieren**
4. Setzen Sie den Status auf **Aktiv** und speichern Sie
5. Wechseln Sie zur Registerkarte **Instanzen** und klicken Sie auf **Neue Instanz hinzufügen**
6. Geben Sie einen Instanznamen ein und setzen Sie den Status auf **Aktiviert**
7. Nehmen Sie in der Registerkarte **Konfiguration** folgende Einstellungen vor:
   - Geben Sie ein VAPID-Betreff ein (z. B. `mailto:admin@example.com`)
   - Aktivieren Sie **Push-Benachrichtigungen aktivieren**
   - Aktivieren Sie die gewünschten Benachrichtigungstypen
   - Legen Sie optional eine benutzerdefinierte URL für das Benachrichtigungssymbol fest
   - Speichern – VAPID-Schlüssel werden automatisch generiert

## Funktionsweise

### Administratorkonfiguration

| Einstellung | Beschreibung |
|---|---|
| Push-Benachrichtigungen aktivieren | Hauptschalter ein/aus |
| VAPID-Betreff | Kontakt-E-Mail zur Identifikation des Push-Diensts |
| VAPID-Schlüssel | Werden beim ersten Speichern automatisch generiert |
| Benachrichtigungsschalter | Ereignisbezogene globale Schalter |
| URL des Benachrichtigungssymbols | Benutzerdefiniertes Symbol/Logo (leer lassen für Standard) |

### Mitarbeitereinstellungen

| Einstellung | Beschreibung |
|---|---|
| Ereignisschalter | Auswahl, welche Ereignistypen Push-Benachrichtigungen auslösen |
| Abteilungsfilter | Nur Benachrichtigungen von ausgewählten Abteilungen empfangen |
| Ruhezeiten | Benachrichtigungen in einem bestimmten Zeitraum unterdrücken |

### Ablauf einer Benachrichtigung

```
Plugin-Hauptschalter EIN?
  └─ Plugin-Ereignisschalter EIN?
      └─ Mitarbeiter hat Push-Abonnement?
          └─ Mitarbeiter-Ereigniseinstellung EIN?
              └─ Ticket-Abteilung im Abteilungsfilter des Mitarbeiters? (leer = alle)
                  └─ Nicht in den Ruhezeiten des Mitarbeiters?
                      └─ PUSH SENDEN ✓
```

> **Hinweis:** Push-Benachrichtigungen sind vollständig unabhängig von den E-Mail-Benachrichtigungseinstellungen von osTicket.

## Architektur

| Datei | Zweck |
|---|---|
| `plugin.php` | Plugin-Manifest |
| `config.php` | Administratorkonfiguration + VAPID-Schlüssel + DB-Tabellen |
| `class.PushNotificationsPlugin.php` | Bootstrap, Signale, AJAX, Assets |
| `class.PushNotificationsAjax.php` | AJAX-Controller |
| `class.PushDispatcher.php` | Versand und Filterung von Benachrichtigungen |
| `class.WebPush.php` | Reiner PHP-Web-Push-Sender |
| `assets/push-notifications.js` | Client-seitige Benutzeroberfläche |
| `assets/push-notifications.css` | Stile |
| `assets/sw.js` | Service Worker |

## Datenbanktabellen

- `ost_push_subscription` — Push-Abonnement-Endpunkte je Mitarbeiter
- `ost_push_preferences` — Benachrichtigungseinstellungen je Mitarbeiter

## Autor

ChesnoTech

## Lizenz

GPL-2.0 (wie osTicket)
