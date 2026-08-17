# Sutore Marketplace

Merchant listings, pricing, fulfillment workflow, checkout shipping, campaigns, sourcing, tasks and admin tools.

## Plugin structure

```
includes/
  Bootstrap/          Plugin, Activator, Autoloader
  Shared/             Database, Settings, Domain, Repositories, Hooks
  Modules/
    Listings/         Listing CRUD, selector, campaigns, REST
    Orders/           Seller fulfillment workflow
    Shipping/         Checkout kargo seçimi ve ücretleri
    Contracts/        Checkout sözleşmeleri
    Sourcing/         Ön sipariş servisi
    Tasks/            Görev ilerleme servisi
  Admin/              Tüm admin ekranları (+ Admin/Orders/)
  Frontend/           Merchant account, asset enqueue
```

| Namespace | Path |
|-----------|------|
| `SutoreMarketplace\Bootstrap\` | `includes/Bootstrap/` |
| `SutoreMarketplace\Shared\` | `includes/Shared/` |
| `SutoreMarketplace\Modules\{Module}\` | `includes/Modules/{Module}/` |
| `SutoreMarketplace\Admin\` | `includes/Admin/` |
| `SutoreMarketplace\Frontend\` | `includes/Frontend/` |

Autoloader convention: `class-{kebab-case}.php` under the matching namespace path.

## Merchant account (WooCommerce)

| Endpoint | Menü | URL |
|----------|------|-----|
| `listings` | Listinglerim | `/hesabim/listings/` |
| `sourcing` | Ön Sipariş | `/hesabim/sourcing/` |
| `campaign-offers` | Kampanya teklifleri | `/hesabim/campaign-offers/` |
| `price-offers` | Müşteri teklifleri | `/hesabim/price-offers/` |
| `my-offers` | Tekliflerim (müşteri) | `/hesabim/my-offers/` |
| `tasks` | Görevlerim | `/hesabim/tasks/` |

Visible only for merchant / shop_manager / administrator.

### Ön sipariş (sourcing)

Ayrı talep tablosu / `open|accepted|reserved|fulfilled` durumları yok. Board, siparişe bağlı listing’in `listing_status = pre_order` olmasıdır.

1. **Açılış:** staff Manage Products → `mark_pre_order`, onay süresi dolunca (`confirm_deadline`), veya satıcı iptali → `FulfillmentService::markAsPreOrder()`.
2. **Duyuru:** hedefli SMS (`AskMerchants`) + digest cron (`sutore_marketplace_pre_order_digest_sent_ids` ile tekrar-önleme). Fiyat toleransı `sourcing_price_tolerance_percent` (eski sabit `/1.10` değil).
3. **Kabul:** Confirmed / Super satıcı `POST /sourcing/{id}/accept` — mevcut listing reuse edilirse `asking` talep fiyatına eşitlenir (modal + confirm bunu gösterir) → **anında sipariş swap**. Staff `fulfilled` adımı yok.
4. **İz:** `listing_pre_order`, `pre_order_accepted`, `sourcing_fulfilled` event’leri; webhook `pre_order.accepted`.

Board erişimi seviye + (yaptırım açıksa) puan eşiğine bağlı: Normal satıcı menüyü görmez; Super yeni talepleri hemen görür, Confirmed `sourcing_early_access_hours` sonra.

### Görevler

İlerleme otomatik: listing oluşturma/güncelleme, satış event'leri.

## PDP / cart pricing

**Source of truth:** `listing.asking + hizmet + güvence − platform discount` (live).  
Fee değişiklikleri `pricing_revision` ile cache bust edilir.

## Orders modülü (fulfillment)

`includes/Modules/Orders/` — `SutoreMarketplace\Modules\Orders\`

**Staff UI:** My Account → Manage Products. Sipariş ayarları: Ana Ayarlar → Sipariş Akışı sekmesi

**Akış:** ödeme → admin onay (opsiyonel) → satıcı onay → kargo → doğrulama → müşteriye gönderim → satıcı ödemesi

| Kaynak | Ad |
|--------|-----|
| DB tablosu | `wp_sutore_marketplace_listings` (sale/lojistik alanları listing satırında) |
| Ayar option | `sutore_marketplace_fulfillment_settings` |
| Schema version | `sutore_marketplace_db_version` = 18 |
| Cron | `sutore_marketplace_fulfillment_deadlines` |
| Frontend JS | `SutoreMarketplaceFulfillment`, `SutoreMarketplaceFulfillmentAppend` |
| REST | `GET/POST /wp-json/sutore-marketplace/v1/fulfillments/{id}` (id = variation_id) |

## Shipping modülü (checkout)

`includes/Modules/Shipping/` — `SutoreMarketplace\Modules\Shipping\`

Checkout kargo seçimi, sepet fee, sipariş meta. Ayarlar: Ana Ayarlar → **Kargo** sekmesi.

## Coupons modülü (WooCommerce native)

`includes/Modules/Coupons/` — `SutoreMarketplace\Modules\Coupons\`

**Kupon kuralları:** WooCommerce → Kuponlar → kupon düzenle (Usage restriction)
- Standart WC alanları: kod, yüzde indirim, product brands
- Sutore meta: marka kampanyası, min marka adedi, bildirim önceliği/rengi

**Genel davranış:** Ana Ayarlar → **Kuponlar** (lockout, sepet bildirimi limiti)

Kupon uygulama/kaldırma WooCommerce `wc-ajax=apply_coupon|remove_coupon` kullanır.

## Contracts modülü (checkout sözleşmeleri)

`includes/Modules/Contracts/` — `SutoreMarketplace\Modules\Contracts\`

Ön bilgilendirme formu ve mesafeli satış sözleşmesi: checkout modal, zorunlu onay checkbox, sipariş snapshot meta, müşteri e-postası.

**Ayarlar:** Ana Ayarlar → **Sözleşmeler** (aktif/pasif, checkbox başlığı, şablon sürümü)

**Sipariş meta:** `_sutore_marketplace_contracts_snapshot` (accepted_at, pre_information, distance_sales, version)

**Fiyat kırılımı:** `MarketplacePricing` + `Settings::hizmetBedeli()` / `guvenceBedeli()`

Hook'lar: `sutore_marketplace_queue_position_changed`, `sutore_marketplace_notification_created`, `sutore_marketplace_notification_dispatched`, `sutore_marketplace_notification_push`, `sutore_marketplace_fulfillment_webhook_sent`, `sutore_marketplace_sms_sent`

## REST

Namespace `sutore-marketplace/v1` — listings CRUD, search-parents, sizes, form-context, catalog product requests, customer price offers (`GET/POST /my-offers`, merchant `GET /price-offers` + accept/decline), sourcing, fulfillments, merchant / staff admin routes.
