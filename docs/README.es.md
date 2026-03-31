[English](../README.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | 🌐 **Español** | [Français](README.fr.md) | [Deutsch](README.de.md) | [中文](README.zh.md) | [Português](README.pt.md) | [Türkçe](README.tr.md)

# Plugin de Notificaciones Push para osTicket

Notificaciones Web Push (PWA) para el panel de personal de osTicket. Entrega notificaciones push en tiempo real para eventos de tickets, completamente independiente de las alertas por correo electrónico.

## Características

- **Notificaciones push en tiempo real** para: nuevos tickets, nuevos mensajes/respuestas, asignaciones, transferencias, tickets vencidos
- **Independiente de las alertas por correo electrónico** — funciona incluso cuando todas las alertas de correo están desactivadas
- **Preferencias por agente** con controles por evento, filtrado por departamento y horas de silencio
- **Controles de administración** con interruptor maestro, controles por evento, icono de notificación personalizado y gestión de claves VAPID
- **Soporte multiidioma** usando el sistema de traducción integrado de osTicket
- **Diseño adaptable para móvil** con iconos de campana y engranaje en la barra de navegación móvil
- **Compatible con modo oscuro** (tema osTicketAwesome)
- **Basado en Service Worker** — funciona incluso cuando la pestaña del navegador está cerrada
- **Sin dependencias externas** — implementación PHP pura de Web Push, no requiere Composer

## Requisitos

- osTicket **1.18+**
- PHP **8.0+** con la extensión `openssl`
- HTTPS (requerido por la API Web Push)

## Instalación

1. Copiar la carpeta `push-notifications/` a `include/plugins/`
2. En el Panel de Administración, ir a **Gestionar > Plugins > Agregar Nuevo Plugin**
3. Hacer clic en **Instalar** junto a "Push Notifications"
4. Establecer el estado en **Activo** y guardar
5. Ir a la pestaña **Instancias**, hacer clic en **Agregar Nueva Instancia**
6. Establecer el nombre de la instancia y el estado en **Habilitado**
7. En la pestaña **Config**:
   - Ingresar un VAPID Subject (p. ej., `mailto:admin@example.com`)
   - Marcar **Enable Push Notifications**
   - Habilitar los tipos de alerta deseados
   - Opcionalmente, establecer una URL de icono de notificación personalizado
   - Guardar — las claves VAPID se generan automáticamente

## Cómo Funciona

### Configuración de Administración (Panel de Administración > Plugins > Push Notifications)

| Configuración | Descripción |
|---|---|
| Enable Push Notifications | Interruptor maestro de activación/desactivación |
| VAPID Subject | Correo de contacto para la identificación del servicio push |
| VAPID Keys | Se generan automáticamente al guardar por primera vez |
| New Ticket / Message / Assignment / Transfer / Overdue Alerts | Controles globales por evento |
| Notification Icon URL | Icono/logotipo personalizado para las notificaciones push (dejar vacío para el valor predeterminado) |

### Preferencias del Agente (Icono de engranaje junto a la campana en la barra de navegación)

Cada agente puede personalizar sus propias preferencias de notificación:

| Configuración | Descripción |
|---|---|
| Controles de evento | Elegir qué tipos de eventos activan las notificaciones push |
| Filtro de departamento | Recibir notificaciones solo de los departamentos seleccionados |
| Horas de silencio | Suprimir notificaciones durante un rango horario (admite franjas nocturnas) |

### Flujo de Notificaciones

```
¿Interruptor maestro del plugin ACTIVADO?
  └─ ¿Control de evento del plugin ACTIVADO?
      └─ ¿El agente tiene suscripción push?
          └─ ¿Preferencia de evento del agente ACTIVADA?
              └─ ¿El departamento del ticket está en el filtro de departamentos del agente? (vacío = todos)
                  └─ ¿No está en las horas de silencio del agente?
                      └─ ENVIAR PUSH ✓
```

> **Nota:** Las notificaciones push son completamente independientes de la configuración de alertas por correo electrónico de osTicket. Puedes desactivar todas las alertas de correo y el push seguirá funcionando.

## Arquitectura

| Archivo | Propósito |
|---|---|
| `plugin.php` | Manifiesto del plugin (id, versión, nombre) |
| `config.php` | Campos de configuración del administrador + generación de claves VAPID + creación de tablas en BD |
| `class.PushNotificationsPlugin.php` | Bootstrap, señales de eventos, rutas AJAX, inyección de recursos |
| `class.PushNotificationsAjax.php` | Controlador AJAX (suscribir, desuscribir, preferencias, prueba) |
| `class.PushDispatcher.php` | Despacho de notificaciones con lógica de destinatarios + filtrado de preferencias |
| `class.WebPush.php` | Emisor PHP puro de Web Push (VAPID + ECDH + AES-128-GCM, sin Composer) |
| `assets/push-notifications.js` | Interfaz cliente de campana/engranaje, modal de preferencias, registro del service worker |
| `assets/push-notifications.css` | Estilos para iconos de navegación, modal, controles, modo oscuro |
| `assets/sw.js` | Service worker para recibir y mostrar notificaciones push |

## Tablas de Base de Datos

- `ost_push_subscription` — almacena los endpoints de suscripción push del navegador por agente
- `ost_push_preferences` — almacena las preferencias de notificación por agente

## Autor

ChesnoTech

## Licencia

GPL-2.0 (igual que osTicket)
