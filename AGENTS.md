# Sutore Marketplace — Agent Rehberi

Bu depo, Docker üzerinde çalışan bir WordPress/WooCommerce geliştirme ortamıdır. Asıl geliştirme hedefi:

`wp-content/plugins/sutore-marketplace/`

## Ne iş yapar?

Çok satıcılı marketplace eklentisi: merchant listing'leri, fiyatlandırma, sipariş fulfillment akışı, kargo, kuponlar, sözleşmeler, kampanyalar, görevler ve satıcı yönetimi.

## Teknoloji

- PHP 8.1+, `declare(strict_types=1)` (sınıf dosyaları)
- WordPress 6+, WooCommerce 8+
- Namespace: `SutoreMarketplace\`
- PSR-4 autoload: `includes/Bootstrap/class-autoloader.php`

## HTTP / API disiplini (zorunlu)

Bundan sonraki geliştirmeler **REST-first** yapılır. Ayrıntı: `.cursor/rules/sutore-marketplace-api.mdc`.

| Katman | Kullanım |
|--------|----------|
| `/wc/store/v1/*` | Müşteri sepet / checkout / ürün (Store API; gerekirse ExtendSchema) |
| `/wc/v3/*` | WC ürün, sipariş, kupon, shipping (gerekirse `register_rest_field`) |
| `/wp/v2/*` | Kullanıcı, medya |
| `/sutore-marketplace/v1/*` | Plugin domain (aşağıdaki route tablosu) |

### Plugin REST (`sutore-marketplace/v1`)

| Alan | Route’lar |
|------|-----------|
| Listings | `GET/POST /listings`, `GET/PUT/DELETE /listings/{id}`, `search-parents`, `sizes/{id}`, `form-context`, `POST /admin/imported-products` |
| Fulfillments | `GET /fulfillments`, `GET /fulfillments/{id}`, `POST …/confirm`, `…/ship`, `…/actions` (staff) |
| Merchants | `GET/PUT /merchant/profile`, `GET /merchant/districts`, `GET /merchant/balance`, notifications* |
| Otp / Account | `GET /otp/config`, `POST /otp/request`, `PUT /account/details`, `PUT /account/password`, `DELETE /account` |
| Sourcing | `GET /sourcing`, `GET /sourcing/{id}`, `POST /sourcing/{id}/accept` |
| Tasks | `GET /tasks/dashboard` |
| Admin / Staff | `GET/PUT /admin/merchants`, `GET /admin/merchants/{id}`, `POST /admin/merchants/status`, `POST /admin/merchants/{id}/commission-override`, `DELETE /admin/merchants/commission-overrides/{id}`, `/admin/restrictions*`, `POST /admin/sourcing*`, `/admin/campaigns*`, `/admin/listings*`, `/admin/tasks*` |

My Account / admin UI `SutoreMarketplace.api()` veya staff REST client ile bu route’lara gider. `admin-ajax.php` kullanılmaz.

**Staff My Account:** `manage-products` (fulfillments), `merchants` (satıcı listesi/detay — shell + REST). Admin Sellers sayfası yok.

**UI deseni:** PHP şablon = shell (başlık, geri linki, spinner); liste/detay JS ile REST’ten yüklenir. Sunucu tarafında Presenter → şablon SSR yok.

Checkout kargo / toplam: WooCommerce classic `update_checkout` + Blocks **Store API** (`/wc/store/v1/cart`).

## Giriş noktaları

| Dosya | Rol |
|---|---|
| `sutore-marketplace.php` | Plugin bootstrap, sabitler |
| `includes/Bootstrap/class-plugin.php` | Modül boot sırası |
| `includes/Shared/Database/class-schema.php` | Özel tablolar, migration |
| `includes/Shared/Settings/class-settings.php` | Genel ayarlar |
| `includes/Admin/class-settings-page.php` | Admin ayarlar UI (tab'lar) |
| `includes/Frontend/class-assets.php` | Merchant My Account CSS/JS |
| `includes/Modules/*/Rest/` | Modül REST controllers (tek HTTP API) |

## Modüller (`class-module.php` → `boot()`)

| Modül | Sorumluluk |
|---|---|
| **Listings** | Listing CRUD, REST, kampanyalar, olaylar; **tek lineer ürün durumu** (`ListingStatus`) |
| **Tasks** | Satıcı görevleri, ilerleme, ödüller |
| **Sourcing** | Ön sipariş talepleri (merchant My Account) |
| **Merchants** | Profil, bildirimler, payout, kısıtlar, staff satıcı listesi/detay, `merchant_events` |
| **Otp** | SMS OTP, hesap güvenlik / silme REST |
| **Orders** | Sale/lojistik alanları listing satırında (`confirm_deadline_at`, `cargo_deadline_at`, `merchant_shipment_code`…); ayrı fulfillments tablosu yok. `FulfillmentRepository` listings üzerinde facade (id = listing_id). SMS, webhook; staff UI: Manage Products |
| **Shipping** | Checkout kargo, Store API / classic `update_checkout` hook'ları |
| **Coupons** | Kupon kilidi, checkout UI |
| **Contracts** | Checkout sözleşmeleri |

`Plugin::boot()` yalnızca modülleri ve paylaşılan hook'ları kaydeder.

## Ürün durumu (tek lineer) ve sale/lojistik

`listings.listing_status` = tek SoT (`ListingStatus`). Satış öncesi + satış sonrası pipeline aynı enum. Anahtarlar eski eklentiyle hizalı (`publish`, `not_sale`, `payment`, `sold`, `shipped`, …). Listing `paid` yok — payout ayrı.

**Ayrı `fulfillments` tablosu yok.** Sale / lojistik alanları (`confirm_deadline_at`, `seller_confirmed_at`, `cargo_deadline_at`, `merchant_shipped_at`, `merchant_shipment_code`, `sutore_shipment_code`, `merchant_snapshot`, `confirm_notice_sent`, `confirm_punished`, `cargo_notice_sent`, `cargo_expired_flag`, `delivered_at`, `return_window_ends_at`, `notes`) `listings` satırında yaşar. `FulfillmentRepository` yalnızca listings üzerine ince facade’dır — okuma yaptığında `id = listing_id`, `fulfillment_status = listing_status` hidrasyonu yapar. Ayrı fulfillment id yoktur; REST route’larındaki `{id}` = listing id.

Payout ayrı: `merchant_payout_lines.payout_status`. Payout satırı yalnızca `listing_id` ile bağlanır (`UNIQUE KEY listing_id`, `fulfillment_id` kolonu yoktur).

## Katmanlar (her modülde)

```
Rest/          → tek HTTP API (WP REST, permission_callback)
Services/      → iş mantığı
Repositories/  → DB erişimi ($wpdb->prepare)
Settings/      → modül ayar okuma
Domain/        → enum, value object, sabitler
Admin/         → modül admin sayfaları
Hooks/         → WC/WP/Store API hook bağlantıları
```

**Listings\Domain** — `Listing`, `ListingPolicy`, `ProductCodeLookup` vb.  
**Shared\Domain** — çapraz: `MarketplacePricing`, `MerchantLevels`  
**Shared** — Schema, Settings, Cart/Pdp/Cron hook'ları

## Asset yükleme

| Alan | Desen |
|---|---|
| Merchant My Account | `Frontend\Assets::enqueue{Endpoint}()` |
| Checkout (kargo, kupon, sözleşme) | İlgili modülün `registerAssets()` / `enqueue*()` |
| Görev UI | `merchant-profile` sayfası + `marketplace-merchant-profile.js` |

## Ayarlar

- Genel: `Shared\Settings\Settings` + `Admin\SettingsPage` tab'ları
- Modül: `Modules\{Modul}\Settings\` + ana sayfada ilgili tab (ör. `orders`)

## Güvenlik

- REST: `permission_callback` (ownership / `ListingPolicy` / `AdminMenu::CAP`)
- Admin capability: `AdminMenu::CAP` (`manage_woocommerce`)
- `wp_ajax_*` yok

## Multilanguage (zorunlu)

Kaynak dil (msgid): **English**. Tüm UI string’leri `sutore-marketplace` text domain ile çevrilebilir olmalıdır. Türkçe: `languages/sutore-marketplace-tr_TR.po`. Ayrıntı: `.cursor/rules/sutore-marketplace-i18n.mdc`.

## Yeni özellik checklist

1. Doğru modülü seç veya `class-module.php` oluştur.
2. Service + Repository katmanına ayır.
3. **HTTP: Rest controller** (`permission_callback`); mümkünse Store/WC native genişlet.
4. Ağır veri → `Schema` + modül `Repositories/`.
5. Ayar → Settings sınıfı + admin tab.
6. Asset → sayfa/modül bazlı enqueue; JS yalnızca REST; şablon shell + spinner.
7. **i18n:** UI / hata / JS `i18n` string’leri `__()` + domain; hardcoded yok.
8. `Module::boot()` içinde Rest kaydı.

## Geliştirme ortamı

```bash
docker compose up -d
```

## Kurallar

`.cursor/rules/sutore-marketplace-*.mdc` — özellikle `core.mdc` (WP/WC yerleşik API + tarih/saat), `api.mdc` (REST), `i18n.mdc` (multilanguage), `no-legacy.mdc` (geriye dönük uyumluluk yok; geliştirme aşaması).
