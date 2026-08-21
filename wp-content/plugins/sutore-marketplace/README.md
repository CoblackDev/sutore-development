# Sutore Marketplace

WooCommerce çok satıcılı marketplace eklentisi: satıcı listing’leri, canlı fiyat, kuyruk, kampanya, outlet, müşteri fiyat teklifi, satış/lojistik pipeline, kargo, kupon, sözleşme, ön sipariş, görevler, hak ediş ve e-Arşiv fatura.

| Belge | İçerik |
|---|---|
| **[FLOWS.md](FLOWS.md)** | Tüm ürün ve sipariş akışları (durumlar, fiyat, REST, cron, tablolar) |
| **[TESTING.md](TESTING.md)** | Panel test senaryoları ve demo hesaplar |
| `languages/` | UI çevirisi — msgid İngilizce, text domain `sutore-marketplace` |

Gereksinim: WordPress 6+, WooCommerce 8+, PHP 8.1+. Namespace: `SutoreMarketplace\`. Autoload: `includes/Bootstrap/class-autoloader.php` (`class-{kebab-case}.php`).

HTTP yüzeyi REST-first’tür (`admin-ajax` yok). My Account / staff sayfaları kabuk + spinner; veri JS ile `sutore-marketplace/v1` üzerinden gelir.

---

## Kimlik (kısa)

Listing satırının PK’si WooCommerce **varyasyon ID**’sidir (`listings.variation_id`). REST `{id}` (listings, fulfillments, sourcing) ve payout satırı aynı değeri kullanır. Ayrı fulfillments tablosu yoktur; sale/lojistik kolonları listing satırındadır.

Tek ürün durumu: `listings.listing_status` (`ListingStatus`). Kampanya (`campaign_status`) ve payout (`payout_status`) ayrıdır.

Ayrıntı: [FLOWS.md — kimlik ve durumlar](FLOWS.md#1-kimlik-ve-roller).

---

## Dizin yapısı

```
includes/
  Bootstrap/          Plugin, Activator, Autoloader
  Shared/             Schema, Settings, Pricing, PDP/cart/cron hook’ları, SMS
  Modules/
    Listings/         CRUD, kuyruk, kampanya, outlet, müşteri teklifi, katalog talebi
    Orders/           Satış/lojistik facade, SMS, webhook, staff fulfillments
    Sourcing/         Ön sipariş board
    Merchants/        Profil, bildirim, payout, kısıt, staff satıcı
    Tasks/            Görev / ödül
    Shipping/         Checkout kargo
    Coupons/          Lockout + marka kampanya kuponu
    Contracts/        Checkout sözleşmeleri
    Invoices/         Paraşüt e-Arşiv
    Otp/              SMS OTP, hesap güvenlik / silme
  Admin/              Ayarlar, Campaigns, Outlet, Tasks, Events
  Frontend/           My Account endpoint + asset enqueue
assets/               Sayfa bazlı CSS/JS
templates/            Shell şablonları
tools/                Seed, i18n (`sync_po.py`, `audit_i18n.py`)
languages/            .po / .mo
```

`Plugin::boot()` modülleri ve paylaşılan hook’ları kaydeder; şema sürümü gerideyse `Schema::install()` çalışır (`Schema::VERSION` = 104). Request path yalnızca hızlı/idempotent adımları (dbDelta + hafif option remap) yapar. Ağır ALTER’lar için: `wp sutore-marketplace schema-upgrade`. MySQL 8’de elle ALTER yazarken `bigint(20)` display-width kullanmayın — dbDelta CREATE zaten bare `bigint` kullanır.

---

## My Account

### Satıcı

| Endpoint | Menü (msgid) |
|---|---|
| `listings` | My products |
| `sourcing` | Pre-order (Confirmed / Super) |
| `campaign-offers` | Campaign offers |
| `price-offers` | Customer offers |
| `outlet` | Outlet |
| `merchant-area` | Merchant exclusive |
| `balance` | My Balance |
| `tasks` | Opportunities |
| `notifications` | Notifications |

### Müşteri

`my-offers`, `notifications`.

### Staff (`manage_woocommerce`)

`manage-products`, `manage-orders`, `merchants`, `catalog-product-requests`.

---

## REST

Namespace `sutore-marketplace/v1`. Müşteri sepet/checkout: WooCommerce Store API (`/wc/store/v1/*`). Tam tablo: [FLOWS.md §17](FLOWS.md#17-rest-yüzeyi).

Özet:

- Listings: `GET/POST /listings`, `GET/PUT/DELETE /listings/{id}`, `search-parents`, `sizes/{id}`, `form-context`, bulk, catalog requests
- Offers: müşteri `/my-offers*`; satıcı `/price-offers*`, `/campaign-offers*`
- Outlet: `/outlet*`, staff `/admin/outlet-windows*`
- Fulfillments: `/fulfillments/{id}/confirm|ship|cancel|actions` (`{id}` = `variation_id`)
- Sourcing: `/sourcing`, `/sourcing/{id}/accept`
- Merchant: `/merchant/profile`, `/merchant/balance`, `/notifications*`, `/tasks/dashboard`
- Account: `/otp/*`, `/account/details|password`, `DELETE /account`
- Staff: `/admin/merchants*`, `/admin/orders*`, `/admin/campaigns*`, `/admin/catalog-product-requests*`
- Invoices: `GET /invoices/{id}/pdf`

---

## Akış özeti

Detay ve diyagramlar [FLOWS.md](FLOWS.md) içindedir.

| Akış | Tek cümle |
|---|---|
| Listing | Create → kuyruk (en düşük asking kazanır) → `publish` veya `pending` / `queued` |
| Fiyat | `asking + hizmet + güvence − kampanya waiver`; outlet’te `customer_sale` |
| Checkout | WC ödeme → listing `payment` veya `sold` → hub pipeline |
| Hub | confirm → Sutore’a kargo → arrived → verified (payout) → müşteriye kargo → teslim |
| Ön sipariş | `pre_order` board; kabul = anında sipariş swap |
| Kampanya | Admin teklif fan-out; kabul asking’i düşürür |
| Outlet | Pencere kalemi + satıcı opt-in; bitince expire (restore yok) |
| Müşteri teklifi | Kişisel kupon; vitrin fiyatı değişmez; red = sonraki satıcıya şelale |
| Fatura | Müşteri: sipariş kapanınca hizmet+güvence; satıcı: payout `paid` olunca komisyon. İade faturası yok |

---

## Ayarlar (wp-admin)

**Sutore Marketplace → Settings:** Pricing, Products, Behavior, Operations, SMS, Invoices, Order Flow, Shipping, Coupons, Contracts, Campaigns.

Kardeş sayfalar: Campaigns, Outlet, Tasks & Rewards, Events.

SMS / İYS: NetGSM kullanıcı, şifre, başlık, `netgsm_brand_code`. Simülasyon veya boş brand code ile İYS API çağrılmaz.

---

## Geliştirme

```bash
docker compose up -d
```

Demo seed: `tools/seed-scenarios.php` (`--force`). i18n senkron: `tools/sync_po.py`. Panel checklist: [TESTING.md](TESTING.md).

Manuel cron (WP-CLI, Docker):

```bash
docker compose exec -T wordpress wp --allow-root sutore-marketplace cron list
docker compose exec -T wordpress wp --allow-root sutore-marketplace cron run-all
```

PHPCS (yerel; CI yok): WordPress Coding Standards kurulu ortamda:

```bash
composer require --dev wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer
vendor/bin/phpcs -q --standard=phpcs.xml.dist
```

Kurallar: `.cursor/rules/sutore-marketplace-*.mdc` (REST, i18n, legacy yok, demo seeder).
