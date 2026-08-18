# Sutore Marketplace — Panel Test Rehberi

Bu belge eklentiyi **satıcı**, **müşteri**, **staff** ve **admin** panellerinden nasıl tıklayarak doğrulayacağınızı anlatır.

Nasıl çalıştığı (durumlar, fiyat, REST, cron): **[FLOWS.md](FLOWS.md)**. Mimari özet: **[README.md](README.md)**.

Demo veri:

```bash
docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tools/seed-scenarios.php --force
```

Tüm demo satıcı / müşteri şifresi: **`password`**

| Hesap | Rol | Ne için |
| --- | --- | --- |
| `demo_seller_verified` | Satıcı (Confirmed) | Listing, ön sipariş, kampanya, müşteri teklifi, görev, bildirim, davet kodu, katalog talebi |
| `demo_seller_referred` | Satıcı (davet edilen) | Karşılama komisyon indirimi + ilk satış |
| `demo_seller_sale` | Satıcı | Satış pipeline (onay, kargo, hak ediş) |
| `demo_seller_normal` | Satıcı (New) | `pending` listing; Pre-order menüsü yok; katalog talebi yok |
| `demo_seller_queued` | Satıcı | Kuyruk + iletilmiş müşteri teklifi |
| `demo_seller_banned` | Satıcı | `listing_create_ban` |
| `demo_seller_premium` | Satıcı (Super) | Özel komisyon, erken ön sipariş, `not_sale` / `expired`, canlı outlet |
| `demo_customer` | Müşteri | Sepet, checkout, My offers, bildirim |
| `demo_customer_youth` | Müşteri | Gençlik indirimi; şelale teklif |
| Site admin / shop_manager | Staff + Admin | Manage Products / Orders / Sellers / Catalog requests + wp-admin |

Menü adları İngilizce msgid ile yazılır. Site dili Türkçe ise eşdeğer çeviriyi kullanın: **My products → Ürünlerim**, **Opportunities → Fırsatlar**.

---

## İçindekiler

1. [Durum cep kartı](#durum-cep-kartı)
2. [Satıcı](#satıcı)
3. [Müşteri](#müşteri)
4. [Staff](#staff)
5. [Admin](#admin)
6. [Önerilen sıra](#önerilen-test-sırası)
7. [Yeni vs eski](#yeni-vs-eski--pozitif-farklar)

FLOWS karşılığı: [listing](FLOWS.md#3-pazar-listing-oluşturma-ve-kuyruk) · [fiyat](FLOWS.md#4-vitrin-ve-sepet-fiyatı) · [kampanya](FLOWS.md#5-kampanya) · [outlet](FLOWS.md#6-outlet) · [teklif](FLOWS.md#7-müşteri-fiyat-teklifi) · [katalog](FLOWS.md#8-katalog-ürün-talebi) · [checkout](FLOWS.md#9-checkout) · [pipeline](FLOWS.md#10-satış--lojistik-pipeline) · [ön sipariş](FLOWS.md#11-ön-sipariş-sourcing) · [payout / fatura](FLOWS.md#12-payout-ve-e-arşiv-fatura)

---

## Durum cep kartı

Tek SoT: `listings.listing_status`. Listing id = WooCommerce `variation_id` (fulfillment id aynı). Kampanya ve payout ayrı alanlardır.

| Key | Panelde beklenen | Relist? |
| --- | --- | --- |
| `pending` | Pending approval | satıştan çıkarılır |
| `publish` | For sale | satıştan çıkarılır |
| `queued` | In queue | satıştan çıkarılır |
| `expired` | Expired | **Put on sale** |
| `not_sale` | Not for sale | **Put on sale** |
| `order_detached` | Detached / Could not be sourced | **hayır** — yeni listing |
| `pre_order` | Pre-order board | kilitli |
| `payment` → `delivered_to_customer` | hub pipeline | kilitli |
| `chargeback` | Refunded (müşteri: Returned) | **Put on sale** |

Payout `pending/paid/reversed` listing status değildir. Ayrıntı: [FLOWS.md §2](FLOWS.md#2-tek-lineer-ürün-durumu).

Müşteri sipariş etiketleri iç key’den farklıdır: `payment` / `sold` / `pre_order` → “Pending Seller Confirmation”.

---

## Satıcı

Giriş → **My Account**.

| Menü (msgid) | Endpoint | Ne test edilir |
| --- | --- | --- |
| **My products** | `listings` | Liste, oluşturma, satışa koy/çıkar, bulk, confirm/ship, katalog talebi |
| **Pre-order** | `sourcing` | Ön sipariş kabul (Confirmed / Super) |
| **Campaign offers** | `campaign-offers` | Kampanya teklifi kabul / red |
| **Customer offers** | `price-offers` | Müşteri fiyat teklifi; kabul = kişisel kupon |
| **Outlet** | `outlet` | Pencere opt-in / iptal |
| **Merchant exclusive** | `merchant-area` | Profil, seviye, skor, davet kodu (hak ediş yok) |
| **My Balance** | `balance` | Komisyon + hak ediş |
| **Opportunities** | `tasks` | Görev kartları |
| **Notifications** | `notifications` | Panel bildirimi |
| **Account details** | WC native | OTP, silme, pazarlama rızası |

Sayfalar spinner sonrası REST ile dolar (`admin-ajax` yok). Network’te `sutore-marketplace/v1` resource’ları görünür.

---

### M1 — Listing listesi ve durumlar

**Hesap:** `demo_seller_verified`

1. **My products** açın; spinner sonrası liste gelsin.
2. Filtre / sıralama varsa durumlara göre deneyin.
3. Satıştaki kaydı açın: ürün, beden, **fiyat (asking)**.
4. **Beklenen:** Liste boş değil; durum etiketleri `ListingStatus` ile uyumlu.

| Hesap | Ne görmelisiniz |
| --- | --- |
| `demo_seller_normal` | `pending` (New seviye auto-activate değil) |
| `demo_seller_premium` | `not_sale` ve `expired`; ayrıca `SEED-DETACH` → `order_detached` (**Put on sale yok**) |
| `demo_seller_queued` | Kuyruk; **Seed Condition Price Race** üzerinde kutusuz listing kuyrukta |
| `demo_seller_sale` | Pipeline: `payment` … `delivered_to_customer`, `chargeback` |

---

### M1b — Kondisyon fiyat yarışına girmez

**Katalog:** `Seed Condition Price Race` (`SEED-COND`), beden `42 (Seed)`.

| Hesap | Asking | Kusur | Sıra |
| --- | --- | --- | --- |
| `demo_seller_premium` | en düşük | Damaged | **1 — satışta** |
| `demo_seller_queued` | orta | No box | kuyruk |
| `demo_seller_verified` | en yüksek | kusursuz | kuyruk |

1. Mağazada beden **42 (Seed)** seçin.
2. **Beklenen:** Vitrin en ucuz (hasarlı); **Damaged** rozeti. Kusursuz listing sırayı ezmez.
3. Sepette Condition: Damaged.

Akış: [FLOWS.md §3 kuyruk](FLOWS.md#kuyruk-fiyat-yarışı).

---

### M2 — Yeni listing

**Hesap:** `demo_seller_verified`

1. **My products** → Add Product.
2. Katalog seçin, beden + asking + kusur + süre (2 / 7 / 30 / 45 / 60; seviye tavanı).
3. Kaydedin.
4. **Beklenen:** Confirmed/Super kazanan genelde `publish`; rakip varsa `queued`. New (`demo_seller_normal`) kazansa bile `pending` kalır.
5. `demo_seller_banned` → oluşturma reddedilir.

Foto yüklenmez; görsel katalog parent’tandır.

---

### M2b — Renk ekseni

**Hesap:** `demo_seller_verified` · katalog `Seed Tee Color Axis` (`SEED-COLOR`)

1. Add Product → renk ürününü seçin.
2. Adım 2 başlığı **Color** / **Renk**; “Select a size” yok.
3. **Beklenen:** Listing oluşur; listede renk etiketi doğru.

---

### M2c — One Size

**Hesap:** `demo_seller_verified` · `Seed Cap One Size` (`SEED-ONESIZE`)

1. Adım 2’de tek seçenek **işaretli**; Next yeterli.
2. **Beklenen:** Adım atlanmaz ama tek tıkla geçilir.

---

### M2d — Katalog talebi (seviye şartlı)

**Hesap:** `demo_seller_verified`. Ayar: `catalog_product_request_levels` = `verified,premium`.

1. Add Product → katalogda olmayan SKU (`NOT-IN-CATALOG-001` / `ZZZ-MISSING`).
2. **Request this product** arama kutusunun altında; modal ayrı (arama kapanmaz).
3. Gönderin → toast; staff kuyruğuna düşer. Seed’de verified için bekleyen talep zaten var.
4. **Notifications:** “ürün katalogya eklendi” → listing formu `SEED-MARKET` dolu.
5. `demo_seller_normal` / `demo_seller_banned` → buton **yok**.

Staff: [S5](#s5--catalog-requests). Akış: [FLOWS.md §8](FLOWS.md#8-katalog-ürün-talebi).

---

### M3 — Satışa koy / çıkar

**Hesap:** `demo_seller_verified` veya `demo_seller_premium` (`not_sale` / `expired`)

1. **Remove from sale** → `not_sale`; kuyruk yeniden.
2. **Put on sale** → `pending` sonra selector (`publish` veya `queued`).
3. `expired` ve `chargeback` da relistable.
4. `order_detached` (`SEED-DETACH`, premium): Put on sale **yok**.
5. Canlı outlet listing’de çıkar/sil/fiyat **kilitli** ([M9](#m9--outlet)).

---

### M4 — Toplu listing

**Hesap:** `demo_seller_verified`

1. Bulk / CSV → şablon indir → validate → commit.
2. **Beklenen:** Satır bazlı hata; başarılı satırlar listeye düşer.

---

### M5 — Satış onayı ve kargo

**Hesap:** `demo_seller_sale` · [FLOWS.md §10](FLOWS.md#10-satış--lojistik-pipeline)

1. **My products** → `sold` → **Confirm sale**.
2. **Ship** + takip no (varsayılan `^\d{12}$`, 12 hane).
3. **Beklenen:** Adımlar sırayla; geri alınamaz. `shipped_to_sutore` ve sonrası satıcı aksiyonu yok.
4. Seed’de `sale_sold` confirm süresi dolmuş; `sale_confirmed` kargo süresi dolmuş (badge).

İptal (`sold` → `pre_order`): kayıt varsa **Cancel** → board’a düşer (staff `fulfilled` adımı yok).

---

### M6 — Ön sipariş

**Hesap:** `demo_seller_verified` · [FLOWS.md §11](FLOWS.md#11-ön-sipariş-sourcing)

1. **Pre-order** → Staff-opened (Seed Samba Pre-order, beden 42). Asking ≠ talep fiyatı; accept dialog eski → yeni fiyatı gösterir.
2. **Accept** = anında sipariş swap. Board’dan düşer; **My products**’ta pipeline.
3. Auto-opened (beden 43) Confirmed’a **görünmez** (`sourcing_early_access_hours` = 24s). `demo_seller_premium` görür.
4. `demo_seller_normal`: menü **yok**.
5. Admin → Events: `pre_order_accepted` + `sourcing_fulfilled` (Seed Samba Pre-order Fulfilled).

Staff elle açılış: Manage Products → `mark_pre_order`.

---

### M7 — Kampanya teklifleri

**Hesap:** `demo_seller_verified` · [FLOWS.md §5](FLOWS.md#5-kampanya)

1. **Campaign offers** (rozette sayı olabilir).
2. Birini **Accept**, birini **Decline**.
3. **Beklenen:** Kabul asking’i düşürür, `campaign_status=active`, kuyruk yeniden. Red → `none`. Müşteri teklifi / outlet listesi karışmaz.
4. Bekleyen teklif varken listing düzenleme kilitli; aktif kampanyada asking artırılamaz.

Admin tarafı: [A2](#a2--kampanya).

---

### M7b — Satıcı kendi kampanyası

**Hesap:** `demo_seller_verified` · satıştaki (`publish`) listing

1. Listing detayında kampanya başlat (yüzde + gün) varsa deneyin.
2. **Beklenen:** Teklif otomatik kabul; `campaign_status=active`. Yoksa Admin Campaigns’ten publish yeter ([A2](#a2--kampanya)).

---

### M8 — Müşteri fiyat teklifi (satıcı)

**Hesap:** `demo_seller_verified` · [FLOWS.md §7](FLOWS.md#7-müşteri-fiyat-teklifi)

1. **Customer offers** → Seed Jordan 1 Queue beden 43 (asking 500, teklif 400).
2. Kart: kamu fiyatı değişmez.
3. **Decline** → şelale `demo_seller_queued`. **Accept** → kişisel kupon; vitrin asking aynı; `campaign_status` değişmez.

**Hesap:** `demo_seller_queued`

1. İletilmiş `demo_customer_youth` teklifi.
2. Kabul = kuyruktaki varyasyon o müşteri için satın alınabilir.

Müşteri tarafı: [C3](#c3--müşteri-fiyat-teklifi).

---

### M9 — Outlet

**Hesaplar:** verified (pending opt-in), premium (canlı), queued (katılmamış) · [FLOWS.md §6](FLOWS.md#6-outlet)

Katalog `Seed Dunk Outlet` (`SEED-OUTLET`). Canlı: müşteri **4.000 TL**, satıcı asking **3.600 TL** (komisyon ödemede kesilir; müşteri fiyatı `customer_sale`, asking+fee değil).

1. verified → **Seed Outlet Upcoming**: “Waiting for window”; **Cancel** mümkün; listing yok.
2. premium → **Seed Outlet Live**: “On sale”; **My products** asking 3.600, `expire_at` pencere sonu. Mağaza beden 42 = 4.000. Fiyat/süre/silme kilitli.
3. queued → **Join at this price** / **Bu fiyatla katıl**. Upcoming’de listing pencereye kadar yok; Live’da hemen oluşur.
4. Admin End → satılmayan `expired`; asking restore **yok**.

---

### M10 — Profil, fırsatlar, bildirim, OTP

**Hesap:** `demo_seller_verified`

1. **Merchant exclusive** — profil, seviye, skor, davet kodu. Komisyon / hak ediş **yok**.
2. **My Balance** — komisyon + pending/paid.
3. **Opportunities** — görev kartları.
4. **Notifications** — okundu / tümünü okundu; sayaç düşer.
5. **Account details** — OTP açıksa kod. Pazarlama kutusu: İYS brand code dolu ve simülasyon kapalıysa kapatınca RET, açınca ONAY.

---

### M10b — Referral

**Davet eden:** `demo_seller_verified` · **Davet edilen:** `demo_seller_referred`

1. Exclusive: davet kodu + link.
2. verified **My Balance**: referral points-off. **Notifications**: “Referral reward unlocked” (davet edilenin ilk `sold`).
3. referred **My Balance**: welcome override (`source=referral`).
4. Staff **Sellers**: Invite code / Referred by / Commission tablosu.
5. **Beklenen:** Ayrı cüzdan yok. Kendi kodunu kullanma kayıtta reddedilir.

---

### M10c — Hesap silme

Bloke: `payment`…`shipped` açık satış, açık `pre_order`, teslim + payout henüz `paid` değil. Chargeback ve paid teslim kilitlemez.

1. `demo_seller_sale` → silme **red**.
2. Açık `pre_order` satıcısı → “pre-order still waiting”.
3. Teslim + unpaid payout → “payouts for delivered sales”.
4. Başarı: market listing silinir, kuyruk yenilenir; teslim/chargeback + event/payout izi kalır; İYS RET (brand code + simülasyon kapalı).

---

### M10d — Bakiye

**Hesap:** `demo_seller_sale`

1. **My Balance**: seviye komisyonu; pending / paid toplam + adet.
2. `payout_scheduled_future` gelecek Çarşamba; `payout_paid_ref` = **paid**, `SEED-EFT-WED-001`.
3. Hak ediş bildirimi bu sayfayı açar (Exclusive değil).
4. Payout satırı **verified** olunca doğar; `paid` staff `mark_payout_paid` ile.

---

## Müşteri

---

### C1 — Checkout

**Hesap:** `demo_customer` · [FLOWS.md §9](FLOWS.md#9-checkout)

1. Satıştaki ürünü sepete ekleyin (PDP adet kutusu yok / 1).
2. Kargo seçimi toplamı günceller (classic `update_checkout` veya Store API).
3. Sözleşme checkbox’sız ilerleme **engellenir**.
4. Sipariş sonrası durum **müşteri etiketi** (`customerLabel`); `sold` ≠ “Sold” metni.
5. Kalem meta’da asking / hizmet / güvence anlığı durur (sonradan Settings değişse bile).

---

### C2 — Gençlik indirimi

**Ayar:** Settings → **Operations** — Enable youth discount, max age 26, %20. Seeder açar.

**Hesap:** `demo_customer_youth`

1. **Seed Dunk Low Market** beden **43 (Seed)** sepete.
2. **Youth discount** ayrı negatif fee; asking+hizmet+güvence satırı düşmez. Tavan: hizmet + güvence + komisyon.
3. `demo_customer` aynı üründe satır **yok**.
4. Satıcı neti `asking − komisyon`; gençlik satıcıya yansımaz.

---

### C3 — Müşteri fiyat teklifi

**Ayar:** Settings → Campaigns → Customer price offers. Auto-decline 48s; kupon TTL 48s (seeder). Bid, **asking**’e göredir (min %70, adım katı, asking ve üzeri yok).

**Hesap:** `demo_customer`

1. **My offers**: Seed Dunk Low Market 43 **accepted** + kupon (asking 750, teklif 550). Ürün adına tık → PDP. **Add to cart** kuponu uygular. Vitrin düşmez.
2. Seed Jordan 1 Queue 43 **pending** (500 / 400); Cancel → satıcı Accept edemez.
3. Seed Cap One Size **declined**; Seed Tee Color Axis **cancelled**.
4. Başka `publish` üründe **Make an offer**. Aynı bedende ikinci teklif = **Offer pending**.
5. **Notifications**: kabul → My offers; red / şelale metni.

**Hesap:** `demo_customer_youth`

1. My offers: kuyruğa iletilmiş pending.
2. Notifications: “sent to the next seller”.

---

### C4 — Adet ve sepet tavanı

1. PDP’de adet 1’e kilitli.
2. Sepette `cart_max_quantity` (varsayılan 8) aşılmamalı.

---

## Staff

Yetki: `manage_woocommerce`. Menüler My Account’ta.

| Menü | Endpoint |
| --- | --- |
| **Manage Products** | `manage-products` |
| **Manage Orders** | `manage-orders` |
| **Sellers** | `merchants` |
| **Catalog requests** | `catalog-product-requests` |

Aksiyonlar duruma göre gelir (`ListingStatus::staffCapabilities`). Yanlış durumdaki buton görünmez veya hata verir. Not zorunlu: detach, close pre-order, mark not for sale, remove from sale, chargeback.

---

### S1 — Manage Products liste

1. Durum / arama / deadline filtreleri; yellow / red zone.
2. Seed: `payment`, confirm süresi dolmuş `sold`, kargo süresi dolmuş `confirmed`, teslim, chargeback, `pre_order`, `order_detached`.

---

### S2 — Pipeline aksiyonları

[FLOWS.md mutlu yol](FLOWS.md#mutlu-yol-varsayılan-admin-ödeme-onayı-açık)

1. `payment` → **confirm_payment** → `sold`.
2. Satıcı confirm/ship sonrası: **arrived** → **verified** (payout `pending` doğar) → **ready_to_ship** → **shipped_to_customer** (`sutore_shipment_code`) → **delivered**.
3. Tüm kalemler teslim → WC sipariş `completed`.
4. Bulk: swap / müşteri kargo / pre_order / chargeback **yok**.
5. CSV export (`?export=csv`): fatura kolonları.

---

### S2b — Swap, detach, hub reject, chargeback

1. **Swap** (`payment`/`sold`): aday listesi; fiyat farkı sipariş meta.
2. **Detach** (`payment`/`sold`/`confirmed`) + not → `order_detached`. Relist **yok**.
3. **Hub reject** (`shipped_to_sutore` / `arrived_to_sutore`) → `not_sale`, payout reverse; relistable.
4. **Chargeback** (`arrived_to_sutore` ve sonrası) → `chargeback`, payout reverse; relistable. WC `refunded` otomatik chargeback **yapmaz**.
5. Seed `SEED-DETACH` zaten `order_detached`; `sale_chargeback` chargeback örneği.

---

### S3 — Manage Orders

1. Liste + completed / cancelled filtre.
2. Tekil ve bulk status.
3. **Attach:** `processing`/`completed` → listing `sold` + satıcı SMS; `pending`/`on-hold` → `payment` (satıldı SMS yok).
4. WC **cancelled:** `payment`/`sold`/`confirmed`/`pre_order` → `order_detached`. `shipped_to_sutore`+ durur; admin SMS `order_cancelled_open_fulfillment`.

---

### S3b — Pre-order kapatma

1. `pre_order` → **Could not be sourced** + not.
2. Listing `order_detached`; ödenmişse WC refund; SMS `pre_order_unsourced_customer`. Başka açık kalem yoksa sipariş `cancelled`.

---

### S4 — Sellers

1. `demo_seller_premium` özel komisyon (ör. %7.5).
2. `demo_seller_referred` Referred by = verified + welcome override.
3. `demo_seller_banned` `listing_create_ban`.
4. Seviye, override ekle/sil, restriction. New → Confirmed auto-activate ve Pre-order menüsünü etkiler.

---

### S5 — Catalog requests

1. Pending: verified `NOT-IN-CATALOG-001`, premium link talebi.
2. **Mark added** (isteğe bağlı parent ara; ürünü WP Admin’de siz eklersiniz).
3. **Decline** + not.
4. Satıcı Notifications: fulfill / reject.

---

### S6 — Pending listing onayı

1. `demo_seller_normal` `pending` kazananı Manage Products → **approve** → `publish` (auto-activate kapalı seviye).

---

## Admin

wp-admin → **Sutore Marketplace**.

| Menü | Ne |
| --- | --- |
| **Settings** | Pricing, Products, Behavior, Operations, SMS, Invoices, Order Flow, Shipping, Coupons, Contracts, Campaigns |
| **Campaigns** | Oluştur / preview / publish / end |
| **Outlet** | Pencere + kalem |
| **Tasks & Rewards** | Görev tanımları |
| **Events** | `listing_events` / merchant events |

---

### A1 — Settings

1. Tüm sekmeler açılsın. **Products** (eski “Listing” adı değil).
2. Order Flow: deadline, SMS (müşteri/admin), Notifications matrisi (satıcı Panel/SMS; push yok).
3. SMS: Netgsm + **IYS brand code**. Boş brand / simülasyon → İYS API yok. Otomatik: `docker compose exec -T wordpress php wp-content/plugins/sutore-marketplace/tests/run.php IysConsent`
4. Campaigns sekmesi: teklif auto-decline saati ≠ kupon TTL (seeder ikisi 48).
5. Kaydet / yenile / doğrulama hatası.

---

### A2 — Kampanya

1. Yeni kampanya; ürün daraltması ad/SKU araması (virgüllü ID yok).
2. Preview → **Publish** → satıcı **Campaign offers**.
3. **End** → asking restore + cooldown; yeni teklif yok.

---

### A2b — Outlet penceresi

1. Seed: Draft, Upcoming (scheduled + opt-in), Live.
2. Draft **Publish** (gelecek start → scheduled). Kalem: `SEED-OUTLET` + beden + `customer_sale` + `seller_net`.
3. Live **End** → expire, restore yok.

---

### A3 — Tasks & Events

1. Tasks & Rewards tanımları → satıcı **Opportunities**.
2. Events: listing/merchant işleminden sonra satır; filtre + zaman damgası.

---

### A4 — WooCommerce yan yüzeyler

1. Ürün düzenlemede Sutore alanları.
2. Kupon: marka kampanyası meta; checkout’ta `demo_customer` ile kural.
3. Kupon lockout: 5 hatalı deneme → 15 dk kilit (Settings → Coupons).

---

### A5 — Hizmet / güvence canlı fiyat

1. Vitrin/sepet fiyatını not edin; satıcı asking’ini not edin.
2. Settings → **Pricing** hizmet ve/veya güvence değiştirin.
3. Vitrin yeni toplam; asking **aynı**. Eski sipariş kırılımı değişmez.
4. Test bitince eski değerlere alın.

---

### A6 — Paraşüt e-Arşiv

Settings → **Invoices**. Seed kapalı; Paraşüt’e gitmez. [FLOWS.md §12](FLOWS.md#12-payout-ve-e-arşiv-fatura)

1. Kimlik bilgileri + KDV %20 + Enable.
2. Ödeme / `sold` → müşteri faturası **yok**.
3. Kalan kalemler `verified` (veya düşenler siparişten çıkmış) ve açık `invoiceOpen` yok.
4. **Tek** müşteri e-Arşiv: Hizmet + Güvence; 0 TL yok; gençlik hizmet→güvence→komisyon. PDF billing e-posta; **View invoice** (uploads URL yok). Credit/iade faturası **yok**.
5. **Mark as Paid to Seller** → komisyon faturası listing başına; PDF satıcı profil e-postası. Hata satışı durdurmaz; cron retry.
6. Export CSV fatura kolonları.

---

## Önerilen test sırası

FLOWS bölümleriyle aynı sıra:

1. A1 Settings + A5 canlı fiyat
2. M1 / M1b / M2 listesi, kuyruk, oluşturma
3. M2d + S5 katalog
4. C1 / C2 / C4 checkout, gençlik, adet
5. C3 + M8 müşteri teklifi
6. M7 + A2 kampanya
7. M9 + A2b outlet
8. S1 / S2 / M5 satış pipeline
9. M6 + S3b ön sipariş
10. S3 / S4 / S6 sipariş + satıcı + pending approve
11. M10 / M10d / A6 bakiye + fatura
12. M10c hesap silme

Yarım gün yetmezse 1–8 zorunlu; 9–12 ikinci tur.

---

## Yeni vs eski — pozitif farklar

Karşılaştırma: **eski** `sutore-uyelik` ↔ **yeni** `sutore-marketplace`.

### 1. Hizmet bedeli / güvence bedeli (fiyatlandırma)

- **Eski:** Satıcı “istediğim tutar”ı (asking) girince sistem **o anda** geçerli hizmet bedeli + güvence bedelini ekleyip sonucu WooCommerce ürün fiyatına **yazıyordu**. Formül kabaca: `müşteri fiyatı = asking + hizmet_bedeli + (asking × güvence_bedeli %)`. Bu toplam ürünün `_price` alanına gömülüyordu. Admin sonradan hizmet veya güvence bedelini değiştirdiğinde **mevcut tüm listing’lerin fiyatı kendiliğinden güncellenmiyordu**; mağaza eski ücrete göre kalıyordu. Satıcı ekranında asking geri hesaplanırken ise **yeni** ayarlar kullanıldığı için rakamlar tutmaz, net kazanç yanlış görünürdü. Pratikte her ücret değişiminde toplu ürün güncellemesi / tek tek fiyat düzeltmesi gerekirdi — eklenti bunu otomatik yapmıyordu.
- **Yeni:** Listing’de yalnızca satıcının **asking** tutarı saklanır. Hizmet bedeli (sabit TL) ve güvence bedeli (% asking) **Settings → Pricing** içinden okunur; ürün sayfası, sepet ve checkout’ta müşteri fiyatı **canlı hesaplanır**. Admin ücretleri değiştirdiğinde listing’lere dokunmaya gerek yoktur; vitrin ve sepet yeni ücrete göre güncellenir. Satıcının girdiği asking ile müşterinin gördüğü toplam her zaman aynı kuraldan üretilir.

### 2. Siparişte ücret anlığı (snapshot)

- **Eski:** Ücretler ürüne gömülü olduğu için “o satış anında hizmet/güvence neydi?” sorusu ayrıca izlenmezdi; sonra ayar değişince geçmiş mantık da bulanıklaşırdı.
- **Yeni:** Sipariş oluşunca satırda asking / hizmet / güvence **o anki değerlerle** saklanır. Sonradan Settings’te ücret değişse bile eski siparişin kırılımı bozulmaz; yeni listing’ler yeni ücreti kullanır.

### 3. Fiyat yarışı ve kuyruk

- **Eski:** Aynı ürün + beden için yalnızca bir varyasyon `publish` (satışta) olur, diğerleri `draft` (“satış sırasında”) beklerdi. Kazanan genelde daha düşük **brüt** WC fiyatına göre seçilirdi. Ücret değişimi ürünleri güncellemeden yapılınca yarış sırası da bozulabilirdi. Stok ve attribute lookup satırları her geçişte elle yeniden yazılıyordu.
- **Yeni:** Aynı parent + beden için açık **kuyruk** vardır: kazanan `publish`, diğerleri `queued`. Sıralama **en düşük asking**, eşitlikte **daha eski listing**. Kusur bayrakları yarışa girmez; müşteri ürün sayfasında winner’ın kusur rozetlerini görür. Satıcı panelinde kuyruk durumu görünür; ücret değişince asking’ler aynı kaldığı için yarış mantığı ücret gömülmesine bağlı değildir.

### 4. Ürün kondisyonu (kusurlar)

- **Eski:** `no_box`, `damaged`, `missing`, `tried_product` gibi bayraklar ürün meta’sındaydı; yarışta kısmen kullanılıyordu ama yapılandırılmış bir “kondisyon skoru” modeli yoktu.
- **Yeni:** Kusurlar listing koşulları olarak tutulur (kutu yok, kutu hasarlı, eksik aksesuar, hasarlı). Beyan satıcı/staff panelinde ve müşteri ürün sayfasında rozet olarak görünür; kuyruk sırasını değiştirmez.

### 5. Komisyon ve satıcı seviyeleri

- **Eski:** Komisyon `merchant_level` (0–7) ile sabit basamaklıydı; seviye hem komisyonu hem “direkt yayınlansın mı / pending mi” kararını etkiliyordu. Geçici özel komisyon / görev ödülü override’ı olgun bir ürün özelliği değildi.
- **Yeni:** Seviyeler sadeleştirildi (New / Confirmed / Super) ve her seviyenin komisyon oranı Settings’ten yönetilir. Komisyon **asking üzerinden** hesaplanır; satıcı neti = asking − komisyon. Staff **Sellers** ekranından süreli komisyon override verebilir; görev ödülleri de override üretebilir. Demo’da premium satıcıda özel oran hazır gelir.

### 6. Kampanya indiriminin mantığı

- **Eski:** Kampanya indirimi ürün meta’sına yazılıyordu; isimlendirme hatalı (`campaing`) ve merkezi kampanya yönetimi zayıftı. İndirimin asking’e mi ücretlere mi gittiği paneller arası net değildi.
- **Yeni:** Admin **Campaigns** ile kampanya oluşturur / yayınlar / bitirir; satıcı **Campaign offers** ile kabul / red eder. Platform indirimi tasarım olarak ücret tarafında (hizmet/güvence) yönetilebilir; satıcının asking’inin altına inilmez. Kampanya durumu listing durumundan ayrıdır. Müşteri fiyat teklifi ve outlet ayrı akışlardır.

### 7. Listing süresi (expire)

- **Eski:** Yayınlanınca `expire_date` çoğu zaman koda gömülü ~45 güne yazılıyordu; cron süresi dolanları `expired` yapıyordu. Süreyi iş kuralı olarak paneden yönetmek zordu.
- **Yeni:** Süre **Settings → Products** içinden gün cinsinden ayarlanır (2 / 7 / 30 / 45 / 60, seviye tavanı); oluşturma / satışa koyma `expire_at` üretir; saatlik kontrol süresi dolanları `expired` yapar.

### 8. Genel yapı

- **Eski:** Tüm iş tek eklentide dağınık PHP dosyalarına yayılmıştı. `index.php`, `functions.php`, `sutore.php` gibi çok büyük dosyalar hem menüyü, hem SMS’i, hem satış durumunu, hem paneli yönetiyordu. Sınıf / modül ayrımı yoktu; bir yeri değiştirmek başka yeri bozma riski taşıyordu.
- **Yeni:** İş alanları ayrı modüllere bölündü (Listings, Orders, Merchants, Sourcing, Tasks, Shipping, Coupons, Contracts, Invoices, OTP…). Panel, iş kuralı ve veri erişimi ayrı katmanlarda. Bir özellik üzerinde çalışırken diğer alanlar daha net izole.

### 9. Panelin veri çekme biçimi

- **Eski:** Satıcı veya staff bir butona basınca `admin-ajax.php` çağrılıyordu. Sunucu çoğu zaman HTML parçası döndürüyor, sayfa içine enjekte ediliyordu. Standart bir API sözleşmesi yoktu; “hangi alan gelecek?” sorusunun cevabı her Ajax aksiyonuna göre değişiyordu.
- **Yeni:** Paneller önce iskeleti (başlık, menü, spinner) gösterir; liste ve detay verisi REST API’den JSON olarak gelir. Aynı veri hem ekranda hem ileride başka istemcide tutarlı kullanılabilir. Test ederken Network’te net resource’lar görürsünüz; HTML fragment avı yoktur.

### 10. Satıcı My Account deneyimi

- **Eski:** Listing listeleri sunucuda PHP ile render edilirdi (`inc/loops` vb.). Filtre/detay için ayrı Ajax’lar HTML dönerdi. Sayfa yenileme ve parça güncelleme karışıktı; hata mesajları tutarsız olabilirdi.
- **Yeni:** **My products**, **Pre-order**, **Campaign offers**, **Customer offers**, **Outlet**, **Merchant exclusive**, **My Balance**, **Opportunities**, **Notifications** menüleri net. Sayfa açılınca liste API’den dolar; oluşturma / onay / kargo gibi işlemler aynı panel dilinde ilerler. Test checklist’i menü menü yazılabilir. Toplu CSV listing yükleme de aynı panelden yürür.

### 11. Staff paneli

- **Eski:** Operasyon ekranları My Account altında anlaşılmaz / gizlenmiş URL’lerle açılıyordu (ör. uzun rastgele slug’lar). “Güvenlik için gizledik” yaklaşımı hem bulmayı zorlaştırıyor hem gerçek yetkilendirme yerine geçmiyordu. Ürün listesi SSR tablo + Ajax status modal karışımıydı.
- **Yeni:** Staff için dört açık menü var: **Manage Products**, **Manage Orders**, **Sellers**, **Catalog requests**. Yetki WooCommerce `manage_woocommerce` ile kontrol edilir. Ürün pipeline, sipariş, satıcı ve katalog talebi ayrı ekranlarda test edilir; URL’yi ezberlemek gerekmez.

### 12. Ürün / listing durumu

- **Eski:** Listing durumu WooCommerce varyasyonunun `post_status` alanına yazılıyordu (`payment`, `sold`, `shipped-to-sutore`, `paid`…). Bu, WC’nin normal ürün yayın semantiğini bozuyordu. Aynı dosya setinde sipariş status’leri de karışık kayıtlıydı. Etiketler Türkçe hardcoded; bazı switch’lerde aynı status iki kez işleniyordu.
- **Yeni:** Durum tek lineer zincirdedir ve marketplace’in kendi `listing_status` alanında tutulur (`pending` → `publish` → … → `payment` → `sold` → … → `delivered_to_customer` / `chargeback`). Satış öncesi ve satış sonrası aynı dilde okunur. Ürün durumuna `paid` gömülmez (ödeme ayrıdır). `order_detached` relistable değildir.

### 13. Satış sonrası süreç (fulfillment)

- **Eski:** Onay süresi, kargo süresi, takip no, satıcı anlık bilgisi gibi her şey ürün meta alanlarına dağılmıştı. “Fulfillment” diye ayrı bir kavram yoktu; her şey yine aynı varyasyon post’unun status + meta yığınıydı. Staff ve satıcı aksiyonları dağınık Ajax fonksiyonlarına bağlıydı.
- **Yeni:** Lojistik alanlar listing kaydının parçasıdır (ayrı fulfillments tablosu yok; id = `variation_id`); staff **Manage Products** üzerinden pipeline’ı ilerletir, satıcı kendi listing’inden onay / kargo yapar. Deadline, tracking, not alanları aynı akışta görünür. Testte “hangi ekrandan hangi adım?” sorusu nettir.

### 14. Satıcı ödemesi (payout)

- **Eski:** Satıcıya ödeme yapıldığında ürün durumu `paid` oluyordu. Yani “ürün nerede?” ile “satıcıya para ödendi mi?” aynı zincirde karışıyordu. IBAN / ödeme bilgisi ürün metalarına yazılıyordu; ayrı bir ödeme defteri yoktu. Net tutar da çoğu zaman “ürün fiyatından ücretleri geri çıkar + komisyon” ile anlık hesaplanıyordu; ücret ayarı değişince net de bozulabilirdi.
- **Yeni:** Payout ayrı kayıttır (`pending` / `paid` / `reversed`), `variation_id` ile bağlanır. Lojistik durumdan bağımsız izlenir; satır **verified** olunca doğar. Chargeback’te payout reverse edilebilir. Asking + komisyon modeli neti üretir; ücret ayarı değişince geçmiş payout satırları bozulmaz.

### 15. Ön sipariş (sourcing / pre-order)

- **Eski:** Ön sipariş yine bir `pre-order` ürün status’üydü. Stok / iptal sonrası diğer satıcılara SMS ile “bu beden lazım” duyurusu (`ask_to_merchant`) yapılırdı; satıcı paneli SSR listelerle ilerlerdi. Kabul / rezervasyon status ve meta’ya gömülüydü. SMS içinde güvence oranı bazen sabitte (`/1.10`) varsayılırdı — ayar %10 değilse fiyat metni yanlış olurdu.
- **Yeni:** Satıcıda ayrı **Pre-order** paneli vardır: açık talepler listelenir, detay açılır, eşleşen listing ile kabul edilir (anında swap). Durum `pre_order` olarak market dilinde tutulur; board + accept ayrı test senaryosudur (M6). Fiyat metinleri canlı pricing modeline bağlıdır. New seviye menüyü görmez; Super hemen, Confirmed gecikmeli görür.

### 16. Sipariş yönetimi

- **Eski:** Operasyon ağırlıklı olarak ürün status’ünü ilerletmeye odaklıydı. Sipariş tarafı WooCommerce klasik ekranlara ve dağınık Ajax’lara kalıyordu; “siparişe listing bağla / toplu status” gibi staff işleri tek panelde toplanmamıştı.
- **Yeni:** **Manage Orders** ile sipariş listesi, detay, tekil / toplu durum değişimi ve satıra listing bağlama staff My Account içinden yapılır. Marketplace sipariş operasyonu ürün ekranından koparılıp sipariş ekranına da taşınmıştır.

### 17. Satıcı yönetimi

- **Eski:** Vendor listesi yine gizlenmiş My Account endpoint’inde SSR tabloydu. Seviye, yasak, komisyon gibi kontroller user meta ve dağınık form/Ajax’larla yürüyordu; aktivite geçmişi zayıftı.
- **Yeni:** **Sellers** menüsünde satıcı listesi / detay, status, komisyon override, restriction (ör. listing create ban) ve aktivite izi vardır. Demo’da premium komisyon ve banned satıcı hazır gelir; panelden etkisi hemen doğrulanır.

### 18. Verinin nerede durduğu

- **Eski:** Marketplace’e özel tablo yoktu. Listing, koşul, kampanya, kargo, satıcı anlık bilgisi — hepsi WC `product_variation` post + `postmeta` / `usermeta` içindeydi. Raporlama ve temizlik zordu; bir meta’nın anlamı kodun içine gömülüydü.
- **Yeni:** Listing, koşullar, kampanya teklifi, bildirim, payout, görev ilerlemesi gibi domain verileri kendi tablolarında tutulur; WooCommerce ürün / sipariş ile bağ `variation_id` / order id üzerinden kurulur. Panel performansı ve tutarlılık için daha uygun; test seed’i tüm senaryoyu yeniden kurabilir.

### 19. Listing kimliği

- **Eski:** Pratikte listing = varyasyon post id idi, ama bu kural yazılı bir domain modeli değildi. Her şey “ürün id” gibi davranırdı; status post status olduğu için kavramlar bulanıktı.
- **Yeni:** Tek kural: listing kimliği = WooCommerce **variation id**. Panel deep link’leri, staff aksiyonları, satıcı listesi, payout hep aynı id’yi kullanır. Ayrı auto-increment “listing_id” yoktur; testte “hangi id?” karışıklığı azalır.

### 20. Dil / çeviri

- **Eski:** Ekran metinlerinin çoğu doğrudan Türkçe yazılmıştı. Text domain bazen `woocommerce`, bazen boş, bazen hatalıydı. İngilizce site / başka dil açmak gerçekçi değildi.
- **Yeni:** Kaynak metinler İngilizce; `sutore-marketplace` domain’i ve TR çeviri dosyası vardır. Panel testinde site dilini değiştirince menü ve mesajların çevrildiğini kontrol edebilirsiniz.

### 21. Güvenlik ve yetki

- **Eski:** Çok sayıda `wp_ajax` / bazen `nopriv` aksiyon vardı. Yetki kontrolleri aksiyon aksiyon dağınıktı. Staff URL’lerini gizlemek yetkilendirme sanılıyordu. Formlar HTML dönünce XSS / tutarsız escape riski artıyordu.
- **Yeni:** Marketplace işlemleri REST üzerinden yetki callback’i ile korunur (giriş, ownership, staff capability). Staff menüsü herkese açık değildir; satıcı yalnız kendi listing’inde işlem yapar. Panel testi sırasında yetkisiz hesapla menünün gelmemesi de doğrulanmalıdır.

### 22. Eklenti dağınıklığı

- **Eski:** Üyelik / merchant paneli `sutore-uyelik` iken kupon, kargo, sözleşme gibi parçalar ayrı old-plugin’lerde yaşardı. Bir checkout bug’ı için üç eklentiye bakmak gerekirdi; sürümler birbirinden kopardı.
- **Yeni:** Shipping, Coupons, Contracts, OTP, Tasks, Invoices aynı `sutore-marketplace` içinde. Admin Settings sekmeleri tek menüden yönetilir. Checkout + satıcı + staff testi tek ürün gibi ele alınır.

### 23. Ayarlar ve SMS şablonları

- **Eski:** Hizmet/güvence option’ları vardı ama birçok davranış (expire süresi, SMS metinleri, uyarı cümleleri) koda gömülü Türkçe string’lerdi. Netgsm çağrıları ve mesaj gövdeleri fonksiyonların içindeydi; operasyon “metni panelden değiştir” diyemezdi.
- **Yeni:** **Settings** altında Pricing, Products, Operations, SMS, Invoices, Order Flow, Shipping, Coupons, Contracts, Campaigns sekmeleri vardır. SMS / order-flow şablonları ve deadline’lar paneden yönetilir. Ücret değişimi yalnızca Pricing’den yapılır; toplu ürün güncellemesi gerekmez.

### 24. Bildirim ve görevler

- **Eski:** Ağırlık SMS ve ad-hoc uyarılardaydı. Satıcıya panel içi bildirim merkezi / görev-ödül ekranı olgun bir ürün parçası değildi.
- **Yeni:** Satıcıda **Notifications** ve **Opportunities** menüleri vardır; admin’de görev tanımları yönetilir. Satıcı olayları tek `dispatch` üzerinden panel ve/veya SMS gider (Order Flow → Notifications kanal matrisi). Seed sonrası bildirim ve görev ilerlemesi panelden tıklanarak doğrulanır.

### 25. Test edilebilirlik

- **Eski:** Hazır “tüm durumları dolduran” demo seti yoktu. Testçi elle ürün yaratıp status’leri meta/post_status ile zorlamak zorundaydı. Ücret değişince regresyonu doğrulamak için pratikte tüm ürünleri yeniden fiyatlamak gerekirdi.
- **Yeni:** Demo senaryo seti satıcı seviyeleri, market durumları, satış pipeline’ı, pre-order, kampanya, outlet, müşteri teklifi, payout, staff sipariş örneklerini hazırlar. Bu rehberdeki M/C/S/A senaryoları o veri üzerine yazılmıştır. Ücret değişikliğini test etmek için Settings’i değiştirip vitrin/sepete bakmak yeterlidir. Davranışın “neden”i [FLOWS.md](FLOWS.md) içindedir.

### 26. Bakım ve evrim

- **Eski:** Yazım hatalı meta isimleri (`campaing`, `adress`), comment-out SMS yolları, ölü switch kolları kodda birikirdi. “Eski kayıt bozulmasın” gerekçesiyle ikinci yollar kalırdı.
- **Yeni:** Geliştirme aşamasında legacy / alias / çift format bilinçli olarak tutulmaz. İsim değişince tüm panel + API + seed birlikte güncellenir. Testçi “eski status adı da geçerli mi?” diye bakmak zorunda kalmaz — tek doğru model vardır.

---

## Özet

Eski `sutore-uyelik` dönemi marketplace’i “çalışan ama kırılgan” bir operasyon haline getirmişti: fiyat ürüne gömülüyordu, durum WooCommerce’in yayın alanına yazılıyordu, staff menüleri gizleniyordu, kupon/kargo/sözleşme ayrı eklentilerde yaşıyordu. Yeni `sutore-marketplace` aynı işi — satıcı listing’i, fiyat yarışı, satış sonrası lojistik, satıcı ödemesi, kampanya, ön sipariş, staff operasyonu — **tek ürün, net paneller ve tutarlı kurallar** altında toplar.

### Operasyon artık ücret değişiminde kilitlenmiyor

Eskide hizmet bedeli veya güvence oranı değişince pratikte **tüm ürün fiyatlarını yeniden yazmak** gerekirdi. Yenide listing’de yalnız satıcının **asking**’i durur; hizmet ve güvence **Settings → Pricing**’den canlı hesaplanır. Admin ücreti değiştirir → ürün sayfası ve sepet yeni toplamı gösterir → satıcının asking’ine dokunulmaz → eski siparişlerin ücret kırılımı snapshot ile korunur.

### Her rol kendi panelinden, kaybolmadan işini bitirir

- **Satıcı:** My products, Pre-order, Campaign offers, Customer offers, Outlet, Merchant exclusive, My Balance, Opportunities, Notifications
- **Müşteri:** My offers, Notifications, checkout
- **Staff:** Manage Products, Manage Orders, Sellers, Catalog requests
- **Admin:** Settings, Campaigns, Outlet, Tasks & Rewards, Events

### Durum dili tek: ürün nerede, para ödendi mi ayrı

Tek lineer **listing durumu** satış öncesi ve sonrası pipeline’ı anlatır; **payout** ayrı kayıttır (`pending` / `paid` / `reversed`). Chargeback lojistiği bozmadan ödemeyi ters çevirebilir. `order_detached` yeniden satışa konmaz.

### Fiyat yarışı, kondisyon ve kuyruk gerçek bir market gibi

Aynı ürün + beden için **publish / queued** açıkça ayrılır; sıralama en düşük asking, eşitlikte daha eski listing. Kusur müşteriye rozet olarak gösterilir, sırayı değiştirmez.

### Kampanya, outlet, müşteri teklifi, ön sipariş, görev ve bildirim ürünleşti

Bunlar “ekstra script” değil; bu rehberdeki M/C/S/A maddeleri ve [FLOWS.md](FLOWS.md) birinci sınıf özelliklerdir.

### Tek eklenti, tek ayar yüzeyi, çok dil

Kargo, kupon, sözleşme, OTP, fatura ve görevler Settings altında. Kaynak dil İngilizce, Türkçe çeviri kataloğu vardır.

### Sonuç

M / C / S / A senaryolarını dolaşmak farkın ekranda hissedildiğini doğrular; bir adım “neden böyle?” diye takılırsa [FLOWS.md](FLOWS.md) ilgili bölüme bakın.
