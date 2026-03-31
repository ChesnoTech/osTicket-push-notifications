[English](../README.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [Español](README.es.md) | 🌐 **Français** | [Deutsch](README.de.md) | [中文](README.zh.md) | [Português](README.pt.md) | [Türkçe](README.tr.md)

# Plugin de notifications push pour osTicket

Notifications Web Push (PWA) pour le panneau du personnel osTicket. Envoie des notifications push en temps réel dans le navigateur pour les événements liés aux tickets, de manière totalement indépendante des alertes par e-mail.

## Fonctionnalités

- **Notifications push en temps réel** pour : nouveaux tickets, nouveaux messages/réponses, attributions, transferts, tickets en retard
- **Indépendant des alertes par e-mail** — fonctionne même lorsque toutes les alertes e-mail sont désactivées
- **Préférences par agent** avec options par événement, filtrage par département et heures silencieuses
- **Contrôles administrateur** avec interrupteur principal, options par événement, icône de notification personnalisée et gestion des clés VAPID
- **Prise en charge multilingue** grâce au système de traduction intégré d'osTicket
- **Responsive mobile** avec icônes cloche + engrenage dans la barre de navigation mobile
- **Compatible mode sombre** (thème osTicketAwesome)
- **Basé sur un Service Worker** — fonctionne même lorsque l'onglet du navigateur est fermé
- **Zéro dépendance** — implémentation Web Push en PHP pur, Composer non requis

## Prérequis

- osTicket **1.18+**
- PHP **8.0+** avec l'extension `openssl`
- HTTPS (requis par l'API Web Push)

## Installation

1. Copiez le dossier `push-notifications/` dans `include/plugins/`
2. Dans le Panneau d'administration, allez dans **Gérer > Plugins > Ajouter un nouveau plugin**
3. Cliquez sur **Installer** à côté de « Push Notifications »
4. Définissez le statut sur **Actif** et enregistrez
5. Allez dans l'onglet **Instances**, cliquez sur **Ajouter une nouvelle instance**
6. Définissez le nom de l'instance, statut sur **Activé**
7. Dans l'onglet **Config** :
   - Saisissez un sujet VAPID (ex. : `mailto:admin@example.com`)
   - Cochez **Activer les notifications push**
   - Activez les types d'alertes souhaités
   - Définissez éventuellement une URL d'icône de notification personnalisée
   - Enregistrez — les clés VAPID sont générées automatiquement

## Fonctionnement

### Configuration administrateur

| Paramètre | Description |
|---|---|
| Activer les notifications push | Interrupteur principal activé/désactivé |
| Sujet VAPID | E-mail de contact pour l'identification du service push |
| Clés VAPID | Générées automatiquement lors du premier enregistrement |
| Options d'alertes | Options globales par événement |
| URL de l'icône de notification | Icône/logo personnalisé (laisser vide pour la valeur par défaut) |

### Préférences agent

| Paramètre | Description |
|---|---|
| Options d'événements | Choisir quels types d'événements déclenchent les notifications push |
| Filtre par département | Recevoir uniquement les notifications des départements sélectionnés |
| Heures silencieuses | Supprimer les notifications pendant une plage horaire définie |

### Flux de notification

```
Interrupteur principal du plugin activé ?
  └─ Option d'événement du plugin activée ?
      └─ L'agent a un abonnement push ?
          └─ Préférence d'événement de l'agent activée ?
              └─ Département du ticket dans le filtre de l'agent ? (vide = tous)
                  └─ Pas dans les heures silencieuses de l'agent ?
                      └─ ENVOI DU PUSH ✓
```

> **Remarque :** Les notifications push sont totalement indépendantes des paramètres d'alertes e-mail d'osTicket.

## Architecture

| Fichier | Rôle |
|---|---|
| `plugin.php` | Manifeste du plugin |
| `config.php` | Configuration admin + clés VAPID + tables DB |
| `class.PushNotificationsPlugin.php` | Bootstrap, signaux, AJAX, ressources |
| `class.PushNotificationsAjax.php` | Contrôleur AJAX |
| `class.PushDispatcher.php` | Envoi et filtrage des notifications |
| `class.WebPush.php` | Expéditeur Web Push en PHP pur |
| `assets/push-notifications.js` | Interface côté client |
| `assets/push-notifications.css` | Styles |
| `assets/sw.js` | Service worker |

## Tables de base de données

- `ost_push_subscription` — points de terminaison d'abonnement push par agent
- `ost_push_preferences` — préférences de notification par agent

## Auteur

ChesnoTech

## Licence

GPL-2.0 (identique à osTicket)
