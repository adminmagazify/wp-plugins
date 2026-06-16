# wp-distributor (WordPress Plugin)

Her WooCommerce sitesine kurulan plugin. Merkez panelden push edilen ürünleri otomatik WooCommerce'e yazar. Site sahibi hangi kategorilerde ürün satacağını seçer.

## Kurulum

1. `wp-distributor.php` içindeki `WPD_CENTRAL_URL` sabitini kendi merkez API adresinizle değiştirin:
   ```php
   define('WPD_CENTRAL_URL', 'https://merkez.siteniz.com');
   ```
2. `wp-distributor` klasörünü `.zip` yapın.
3. WordPress → Eklentiler → Yeni Ekle → Eklenti Yükle ile yükleyip etkinleştirin.
4. Etkinleştirme anında plugin **otomatik olarak merkeze kaydolur** ve API anahtarlarını alır.
5. Sol menüde **Ürün Dağıtım** sayfasından satmak istediğiniz kategorileri seçin.

> WooCommerce kurulu ve aktif olmalıdır.

## Nasıl Çalışır

```
Merkez Panel  ──"Tüm Sitelere Gönder"──►  POST /wp-json/wp-distributor/v1/sync
                                            (X-Central-Key + X-Central-Secret ile doğrulanır)
                                                    │
                                                    ▼
                                          Plugin ürünleri WooCommerce'e yazar
                                          (SKU: central-{id} → günceller, çoğaltmaz)
```

- **Otomatik kayıt:** Aktivasyonda merkeze domain + site bilgisi gönderilir, apiKey/secret alınır.
- **Kategori seçimi:** Site sahibi seçer → merkeze kaydedilir → sadece o kategorilerin ürünleri gelir.
- **Idempotent görseller:** Ana foto ve galeri yalnızca kaynak URL değiştiğinde yeniden indirilir.
- **Güvenlik:** REST endpoint `hash_equals` ile sabit-zamanlı anahtar doğrulaması yapar.

## Dosyalar

| Dosya | Görev |
|-------|-------|
| `wp-distributor.php` | Ana dosya, hook'lar, merkez URL sabiti |
| `includes/class-api-client.php` | Merkez API'ye istekler (kayıt, kategori) |
| `includes/class-rest-endpoint.php` | `/sync` endpoint'i + güvenlik doğrulaması |
| `includes/class-product-sync.php` | WooCommerce ürün oluştur/güncelle + görseller |
| `includes/class-admin.php` | Admin menüsü ve form işlemleri |
| `includes/class-updater.php` | GitHub release tabanlı otomatik güncelleme |
| `admin/views/settings.php` | Kategori seçim arayüzü |

## Otomatik Güncelleme (GitHub Release)

Plugin, GitHub'daki `adminmagazify/wp-plugins` repo'sunu kontrol eder. Yeni bir
sürüm yayınladığınızda **tüm sitelerde** WordPress Eklentiler sayfasında
"güncelleme var" görünür (otomatik güncelleme açıksa kendiliğinden güncellenir).

### Yeni sürüm yayınlama adımları

1. Kod değişikliğini yap.
2. `wp-distributor.php` içindeki **iki** sürüm numarasını yükselt:
   - Başlıktaki `* Version: 1.1.4`
   - `define('WPD_VERSION', '1.1.4');`
3. Plugin'i zip'le (forward-slash yapı şart — `build-zip.ps1` script'i bunu yapar).
4. Değişiklikleri commit + push et.
5. GitHub'da **yeni bir Release** oluştur:
   - **Tag:** `wp-distributor-1.1.4`  ← `wp-distributor-` öneki + sürüm (ZORUNLU)
   - **Asset olarak `wp-distributor.zip`'i yükle** (ZORUNLU — updater bu dosyayı arar)
6. Birkaç saat içinde (veya site "Güncellemeleri kontrol et" deyince) tüm siteler görür.

> Önek (`wp-distributor-`) ve asset adı (`wp-distributor.zip`) sabittir; aynı
> repo'da ileride başka plugin'ler de barındırabilmek için bu konvansiyon kullanılır.
> Updater ayarları: `includes/class-updater.php`.
