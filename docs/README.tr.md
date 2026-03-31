[English](../README.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [中文](README.zh.md) | [Português](README.pt.md) | 🌐 **Türkçe**

# osTicket Push Notifications Eklentisi

osTicket personel paneli için Web Push (PWA) bildirimleri. Bilet olayları için gerçek zamanlı tarayıcı push bildirimleri sunar; e-posta uyarılarından tamamen bağımsız çalışır.

## Özellikler

- **Gerçek zamanlı push bildirimleri**: yeni biletler, yeni mesajlar/yanıtlar, atamalar, transferler, süresi geçmiş biletler
- **E-posta uyarılarından bağımsız** — tüm e-posta uyarıları devre dışı bırakıldığında bile çalışır
- **Personel tercihleri**: olay bazlı geçişler, departman filtresi ve sessiz saatler
- **Yönetici kontrolleri**: ana anahtar, olay bazlı geçişler, özel bildirim simgesi ve VAPID anahtar yönetimi
- **Çok dilli destek** osTicket'ın yerleşik çeviri sistemi kullanılarak
- **Mobil uyumlu** — mobil gezinme çubuğunda zil ve dişli simgeleri
- **Karanlık mod** desteği (osTicketAwesome teması)
- **Service Worker** tabanlı — tarayıcı sekmesi kapalı olduğunda bile çalışır
- **Sıfır bağımlılık** — saf PHP Web Push uygulaması, Composer gerekmez

## Gereksinimler

- osTicket **1.18+**
- `openssl` uzantılı PHP **8.0+**
- HTTPS (Web Push API tarafından zorunlu tutulur)

## Kurulum

1. `push-notifications/` klasörünü `include/plugins/` dizinine kopyalayın
2. Yönetici Paneli'nde **Yönet > Eklentiler > Yeni Eklenti Ekle** yolunu izleyin
3. "Push Notifications" yanındaki **Yükle** düğmesine tıklayın
4. Durumu **Etkin** olarak ayarlayıp kaydedin
5. **Örnekler** sekmesine gidip **Yeni Örnek Ekle**'ye tıklayın
6. Örnek adını ve durumunu **Etkin** olarak ayarlayın
7. **Config** sekmesinde:
   - Bir VAPID Konusu girin (örn. `mailto:admin@example.com`)
   - **Push Bildirimlerini Etkinleştir** seçeneğini işaretleyin
   - İstediğiniz uyarı türlerini etkinleştirin
   - İsteğe bağlı olarak özel bir Bildirim Simgesi URL'si girin
   - Kaydedin — VAPID anahtarları otomatik olarak oluşturulur

## Nasıl Çalışır

### Yönetici Yapılandırması (Yönetici Paneli > Eklentiler > Push Notifications)

| Ayar | Açıklama |
|---|---|
| Push Bildirimlerini Etkinleştir | Ana açma/kapama anahtarı |
| VAPID Konusu | Push servis kimlik doğrulaması için iletişim e-postası |
| VAPID Anahtarları | İlk kayıtta otomatik oluşturulur |
| Yeni Bilet / Mesaj / Atama / Transfer / Süresi Geçmiş Uyarıları | Olay bazlı genel geçişler |
| Bildirim Simgesi URL'si | Push bildirimleri için özel simge/logo (varsayılan için boş bırakın) |

### Personel Tercihleri (Gezinme çubuğundaki zil yanındaki dişli simgesi)

Her personel kendi bildirim tercihlerini özelleştirebilir:

| Ayar | Açıklama |
|---|---|
| Olay geçişleri | Hangi olay türlerinin push bildirimini tetikleyeceğini seçin |
| Departman filtresi | Yalnızca seçili departmanlardan bildirim alın |
| Sessiz saatler | Belirli bir zaman aralığında bildirimleri bastırın (gece yarısını geçen aralıkları destekler) |

### Bildirim Akışı

```
Eklenti ana anahtarı AÇIK mı?
  └─ Eklenti olay geçişi AÇIK mı?
      └─ Personelin push aboneliği var mı?
          └─ Personel olay tercihi AÇIK mı?
              └─ Bilet departmanı personelin departman filtresinde mi? (boş = tümü)
                  └─ Personelin sessiz saatlerinde değil mi?
                      └─ PUSH GÖNDER ✓
```

> **Not:** Push bildirimleri, osTicket'ın e-posta uyarı ayarlarından tamamen bağımsızdır.

## Mimari

| Dosya | Amaç |
|---|---|
| `plugin.php` | Eklenti bildirimi (id, sürüm, ad) |
| `config.php` | Yönetici yapılandırma alanları + VAPID anahtar üretimi + DB tablo oluşturma |
| `class.PushNotificationsPlugin.php` | Bootstrap, sinyal kancaları, AJAX rotaları, varlık enjeksiyonu |
| `class.PushNotificationsAjax.php` | AJAX denetleyicisi (abone ol, abonelikten çık, tercihler, test) |
| `class.PushDispatcher.php` | Alıcı mantığı + tercih filtrelemesi ile bildirim gönderimi |
| `class.WebPush.php` | Saf PHP Web Push gönderici (VAPID + ECDH + AES-128-GCM, Composer gerekmez) |
| `assets/push-notifications.js` | İstemci taraflı zil/dişli arayüzü, tercihler modalı, service worker kaydı |
| `assets/push-notifications.css` | Gezinme simgeleri, modal, geçişler ve karanlık mod stilleri |
| `assets/sw.js` | Push bildirimlerini alıp görüntülemek için service worker |

## Veritabanı Tabloları

- `ost_push_subscription` — personel başına tarayıcı push abonelik uç noktalarını saklar
- `ost_push_preferences` — personel başına bildirim tercihlerini saklar

## Yazar

ChesnoTech

## Lisans

GPL-2.0 (osTicket ile aynı)
