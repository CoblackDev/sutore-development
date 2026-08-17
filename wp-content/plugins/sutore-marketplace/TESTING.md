# Sutore Marketplace — Panel Test Rehberi

Bu belge, eklentiyi **Merchant (satıcı)**, **Staff (operasyon)** ve **Admin** panellerinden nasıl test edeceğinizi adım adım anlatır. Son bölümde eski `sutore-uyelik` eklentisine göre pozitif farklar yer alır.

Demo verisi hazırsa aşağıdaki hesaplarla giriş yapın. Tüm demo satıcı / müşteri şifresi: **`password`**

| Hesap                     | Rol                    | Ne için                                                             |
| ------------------------- | ---------------------- | ------------------------------------------------------------------- |
| `demo_seller_verified`    | Satıcı (onaylı)        | Ana satıcı testleri: listing, ön sipariş, kampanya, **müşteri fiyat teklifi**, görev, bildirim, davet kodu / referral ödülü, katalog ürün talebi |
| `demo_seller_referred`    | Satıcı (davet edilen)  | Davet kodu ile kayıt + karşılama komisyon indirimi + ilk satış |
| `demo_seller_sale`        | Satıcı                 | Satış sonrası süreç (onay, kargo, pipeline)                         |
| `demo_seller_normal`      | Satıcı (normal seviye) | Onay bekleyen listing                                               |
| `demo_seller_queued`      | Satıcı                 | Fiyat sırası / kuyruk                                               |
| `demo_seller_banned`      | Satıcı                 | Listing oluşturma yasağı                                            |
| `demo_seller_premium`     | Satıcı                 | Özel komisyon                                                       |
| `demo_customer`           | Müşteri                | Sepet / sipariş; **My offers** (kabul edilmiş kupon + kuyruk teklifi) |
| `demo_customer_youth`     | Müşteri                | Gençlik indirimi; kuyruktaki satıcıya iletilmiş teklif |
| Site admin / shop manager | Staff + Admin          | Manage Products, Manage Orders, Sellers, Catalog requests, wp-admin |

My Account menüsü dil ayarına göre İngilizce veya Türkçe görünebilir. Aşağıda menü adları İngilizce kaynak etiketleriyle yazılmıştır; sitede çeviri açıksa eşdeğer Türkçe etiketi kullanın.

---

## Merchant (Satıcı paneli)

Giriş → **My Account**. Sol menüde satıcıya özel maddeler görünür.

### Menü haritası

| Menü                   | Ne test edilir                                                   |
| ---------------------- | ---------------------------------------------------------------- |
| **My Listings**        | Ürün listeleme, düzenleme, satışa koyma / çıkarma, toplu yükleme, katalog talebi |
| **Pre-order**          | Ön sipariş talepleri, kabul                                      |
| **Campaign offers**    | Kampanya teklifleri kabul / red                                  |
| **Customer offers**    | Müşteri fiyat teklifi kabul / red (kabul = kişiye özel kupon)    |
| **Outlet**             | Outlet penceresi: ürün+beden, sabit asking, pencere başı listing |
| **Merchant exclusive** | Profil, bakiye                                                   |
| **My Tasks**           | Görevler ve ilerleme                                             |
| **Notifications**      | Bildirimler                                                      |
| **Account details**    | Hesap / güvenlik                                                 |

---

### Senaryo M1 — Listing listesi ve durumlar

**Hesap:** `demo_seller_verified`

1. My Account → **My Listings** açın.
2. Sayfanın yüklenmesini bekleyin (kısa spinner sonrası liste gelmeli).
3. Filtre / sıralama varsa farklı durumlara göre deneyin.
4. Satışta olan bir kayda tıklayın (veya detay / düzenle).
5. **Beklenen:** Liste boş değil; durum etiketleri okunaklı; detay açılınca ürün, beden, fiyat bilgileri görünür.

**Ek hesaplar:**

| Hesap                 | Ne görmelisiniz                                                        |
| --------------------- | ---------------------------------------------------------------------- |
| `demo_seller_normal`  | Onay bekleyen (`pending`) listing                                      |
| `demo_seller_premium` | Satışta olmayan (`not for sale`) ve süresi dolmuş (`expired`) örnekler |
| `demo_seller_queued`  | Kuyrukta bekleyen listing (daha ucuz listing kazanmış); **Seed Condition Price Race** üzerinde kutusuz listing de kuyrukta |

---

### Senaryo M1b — Kondisyon fiyat yarışına girmez

**Katalog:** `Seed Condition Price Race` (`SEED-COND`), beden `42 (Seed)`.

Aynı bedende üç listing:

| Hesap | Asking | Kusur | Beklenen sıra |
| --- | --- | --- | --- |
| `demo_seller_premium` | en düşük | Damaged | **1 — satışta** |
| `demo_seller_queued` | orta | No box | kuyruk |
| `demo_seller_verified` | en yüksek | kusursuz | kuyruk |

1. Mağazada ürünü açın, beden **42 (Seed)** seçin.
2. **Beklenen:** Vitrin fiyatı en ucuz (hasarlı) listing’dir; ürün sayfasında **Damaged** rozeti görünür.
3. `demo_seller_verified` ile My Listings’de bu ürünün kuyrukta olduğunu doğrulayın — kusursuz olması fiyatı ezmez.
4. Sepete ekleyince satırda Condition: Damaged görünür.

---

### Senaryo M2 — Yeni listing oluşturma

**Hesap:** `demo_seller_verified`

1. **My Listings** → yeni listing / create aksiyonu.
2. Katalog ürünü seçin, beden ve fiyat girin, koşulları doldurun.
3. Kaydedin.
4. **Beklenen:** Kayıt listede görünür; onaylı satıcıda genelde doğrudan satışa uygun duruma geçer (veya kuyruk kurallarına göre sıraya girer).

**Karşılaştırma:** `demo_seller_normal` ile aynı adımları deneyin → kayıt **onay bekliyor** durumunda kalmalı.  
`demo_seller_banned` ile deneyin → oluşturma engellenmeli / hata mesajı gelmeli.

---

### Senaryo M2b — Renk eksenli variable ürün

**Hesap:** `demo_seller_verified`

**Önkoşul:** Katalogda yalnızca `pa_color` (veya renk) varyasyon ekseni olan bir variable ürün (ayakkabı bedeni yok).

1. **My Listings** → **Add Product**.
2. Renk eksenli ürünü arayıp seçin.
3. Sihirbaz adım 2 başlığının **Color** / **Renk** olduğunu ve renk termlerinin listelendiğini doğrulayın.
4. Bir renk seçip listing’i tamamlayın.
5. **Beklenen:** Listing oluşur; listede doğru renk etiketi görünür; “Select a size” hatası çıkmaz.

---

### Senaryo M2c — Tek eksen “One Size”

**Hesap:** `demo_seller_verified`

**Önkoşul:** Tek varyasyon ekseni ve tek term (ör. “One Size”) olan variable ürün.

1. **Add Product** → ürünü seçin.
2. Adım 2’de tek seçeneğin **zaten işaretli** olduğunu doğrulayın.
3. **Next** ile devam edin (manuel seçim gerekmez).
4. **Beklenen:** Listing oluşur; adım 2 atlanmaz ama tek tıkla geçilebilir.

---

### Senaryo M2d — Katalogda olmayan ürün talebi (seviye şartlı)

**Hesap:** `demo_seller_verified` (Confirmed). Varsayılan ayar: `catalog_product_request_levels` = `verified,premium`.

1. **My Listings** → **Add Product**.
2. Katalogda olmayan bir SKU arayın (ör. `NOT-IN-CATALOG-001` veya rastgele `ZZZ-MISSING`).
3. **Beklenen:** “ürün bulunamadı” mesajının altında **Request this product** kutusu görünür (SKU/link, beden, kısa not).
4. Formu gönderin.
5. **Beklenen:** Talep admin kuyruğuna düşer; başarı mesajı görünür. Seed zaten `demo_seller_verified` için bekleyen bir talep bırakır.
6. **Notifications:** seed’lenmiş “ürün katalogya eklendi” bildirimine tıklayın → listing oluşturma açılır, `SEED-MARKET` araması dolu gelir.
7. `demo_seller_normal` ile aynı boş aramayı yapın → talep kutusu **görünmez** (New seviye).
8. `demo_seller_banned` ile deneyin → talep kutusu yok (listing oluşturma yasağı).

**Staff:** My Account → **Catalog requests** — bekleyen talepleri **Mark added** (isteğe bağlı WC parent ID) veya **Decline** ile kapatın; satıcıya bildirim gider. Eklenti WooCommerce ürünü oluşturmaz.

---

### Senaryo M3 — Satışa koy / satıştan çıkar

**Hesap:** `demo_seller_verified` (veya satışta olmayan listing’i olan satıcı)

1. Listeden uygun bir kaydı açın.
2. **Remove from sale** / satıştan çıkar aksiyonunu kullanın.
3. Listeye dönüp durumun değiştiğini kontrol edin.
4. Aynı kayıtta **Put on sale** / satışa koy deneyin.
5. **Beklenen:** Durum güncellenir; işlem sonrası liste yenilenir; hata olursa anlaşılır mesaj çıkar.

---

### Senaryo M4 — Toplu (bulk) listing

**Hesap:** `demo_seller_verified`

1. **My Listings** → bulk / CSV akışını açın.
2. Şablon indirin.
3. Birkaç satır doldurup yükleyin / doğrulama adımını geçin.
4. Commit / kaydet.
5. **Beklenen:** Doğrulama hataları satır bazında görünür; başarılı satırlar listing listesine düşer.

---

### Senaryo M5 — Satış onayı ve kargo (satıcı tarafı)

**Hesap:** `demo_seller_sale`

1. **My Listings** (veya satış bildirimi / ilgili kayıt) üzerinden satılmış ürünü bulun.
2. **Sold / satıcı onayı bekleyen** kaydı açın → **Confirm sale** (satışı onayla).
3. Onay sonrası **Ship** / Sutore’a kargola adımına geçin; takip numarası girin.
4. **Beklenen:** Onay ve kargo adımları sırayla açılır; tamamlanan adım geri alınamaz veya yeni aksiyon seti gelir.
5. Daha ilerideki durumdaki kayıtları (ör. müşteriye teslim) açıp satıcı aksiyonlarının kapalı olduğunu kontrol edin.

---

### Senaryo M6 — Ön sipariş (Pre-order)

**Hesap:** `demo_seller_verified` (Confirmed). Seed iki açık board kaydı + bir kabul edilmiş örnek bırakır.

1. My Account → **Pre-order**.
2. **Staff-opened** talebi görün (Seed Samba Pre-order, beden 42). Detayı açın: eşleşen listing fiyatı talep fiyatından farklıdır; metin fiyatın kabulde güncelleneceğini söyler.
3. **Accept** → confirm dialog eski → yeni fiyatı gösterir. Kabul = anında sipariş swap (staff `fulfilled` adımı yok).
4. **Beklenen:** talep board’dan düşer; listing My Listings’te satış pipeline’ına girer.
5. Aynı hesapla **auto-opened** (beden 43, confirm deadline) kaydını **görmeyin** — Confirmed satıcı `sourcing_early_access_hours` (24s) bekler. `demo_seller_premium` ile giriş yapınca bu kayıt görünür.
6. `demo_seller_normal` ile giriş: **Pre-order menüsü yok** (Confirmed+ gerekir).
7. Admin → Events: kabul edilen örnekte `pre_order_accepted` + `sourcing_fulfilled` (Seed Samba Pre-order Fulfilled).

**Staff:** Manage Products → satırda `mark_pre_order` ile elle açılış da denenebilir.

---

### Senaryo M7 — Kampanya teklifleri

**Hesap:** `demo_seller_verified` (bekleyen teklif varsa menüde sayı rozeti de görünebilir)

1. **Campaign offers** açın.
2. Bekleyen teklifi görün.
3. Bir teklifi **Accept**, başka birini (veya aynı senaryoda ikinci teklifi) **Decline** ile deneyin.
4. **Beklenen:** Kabul/red sonrası teklif listeden düşer veya durumu değişir; sayfa hata vermeden yenilenir.

---

### Senaryo M7c — Müşteri fiyat teklifi (satıcı)

**Hesap:** `demo_seller_verified`

1. **Customer offers** açın.
2. Seed Jordan 1 Queue (beden 43) için bekleyen teklifi görün (asking 500, teklif 400).
3. Kartı açın: kamu fiyatının değişmeyeceği metnini okuyun.
4. **Decline** derseniz teklif `demo_seller_queued` hesabına düşer (şelale). **Accept** derseniz kişisel kupon doğar; vitrin asking’i aynı kalır.
5. **Beklenen:** Kampanya teklifleri listesi ile karışmaz; listing kilidi (`campaign_status`) değişmez.

**Hesap:** `demo_seller_queued`

1. **Customer offers** — `demo_customer_youth` teklifi iletilmiş olmalı (verified decline seed’i).
2. Kabul = o satıcının varyasyonuna kupon; kuyruktaki ürün müşteri için satın alınabilir hale gelir.

---

### Senaryo M7b — Outlet

**Hesaplar:** `demo_seller_verified` (bekleyen opt-in), `demo_seller_premium` (canlı listing), `demo_seller_queued` (henüz katılmamış)

Katalog: `Seed Dunk Outlet` (`SEED-OUTLET`). Canlı pencere fiyatı (varsayılan 25 TL adım): müşteri **4.000 TL**, satıcı asking **3.600 TL**. Asking komisyon öncesi fiyattır; payout’ta komisyon ayrıca kesilir.

1. `demo_seller_verified` → **Outlet**: **Seed Outlet Upcoming** satırında “Waiting for window” görünür; **Cancel** ile vazgeçilebilir. Listing henüz oluşmamıştır.
2. `demo_seller_premium` → **Outlet**: **Seed Outlet Live** satırında “On sale”; **My Listings**’de aynı ürün asking 3.600, `expire_at` pencere sonu. Mağazada `SEED-OUTLET` beden **42 (Seed)** müşteri fiyatı 4.000.
3. `demo_seller_queued` → **Outlet**: açık kalemlere **Join at this asking**. Upcoming’de listing pencereye kadar oluşmaz; Live’da hemen listing doğar.
4. Admin → **Sutore Marketplace → Outlet**: draft pencereyi **Publish**; Live pencereyi **End** → satılmayan outlet listing `expired`.
5. **Beklenen:** Kampanya teklifleri listesi değişmez. Outlet listing’inde fiyat/süre/silme kilitlidir. Pencere bitince restore yok, listing düşer.

---

### Senaryo M8 — Profil, görevler, bildirimler

**Hesap:** `demo_seller_verified`

1. **Merchant exclusive** → profil bilgilerini görüntüleyin / güncelleyin; bakiyeyi kontrol edin.
2. **My Tasks** → görev kartları ve ilerleme görünür mü bakın.
3. **Notifications** → bildirim listesi; birini okundu işaretleyin; hepsini okundu deneyin.
4. **Account details** → iletişim bilgisi / şifre güncelleme ekranının açıldığını doğrulayın (OTP açıksa kod adımını da deneyin).
5. **Beklenen:** Sayfalar spinner sonrası dolu gelir; kayıtlar kaybolmaz; okundu sayacı düşer.

### Senaryo M8c — Hesap silme (aktif satış / payout)

Hesap silme, satıcının hâlâ işi olduğu satışlarda bloke olur. Teslim edilmiş **ve** payout’u `paid` olan kayıtlar ile chargeback silmeyi kilitlemez (kayıtlar durur).

1. `demo_seller_sale` → **Account details** → hesap silme: **Beklenen:** Red. Bu hesapta `payment`→`shipped` arası satışlar var.
2. Staff → **Manage Products** → açık `pre_order` satırı olan satıcıda silme: **Beklenen:** “pre-order still waiting” mesajı.
3. Yalnız teslim edilmiş ama payout’u ödenmemiş satış bırakın: **Beklenen:** Silme “payouts for delivered sales” ile bloke.
4. `sale_delivered_to_customer` payout’u seed’de `paid`; chargeback kayıtları silmeyi tek başına kilitlemez. Market listing’ler (publish/queued) silinir, kuyruk yenilenir; teslim/chargeback satırları ve payout/event izi kalır.

### Senaryo M8b — Referral (davet kodu)

**Davet eden:** `demo_seller_verified`  
**Davet edilen:** `demo_seller_referred`

1. `demo_seller_verified` → **Merchant exclusive**: davet kodu ve kopyalanabilir link görünür; komisyon satırında referral indirimi (points off) aktiftir.
2. Aynı hesap → **Notifications**: “Referral reward unlocked” bildirimi vardır (davet edilenin ilk `sold` satışı).
3. `demo_seller_referred` → **Merchant exclusive**: komisyon indirimi (karşılama / source=referral) görünür.
4. Staff → **Sellers** → `demo_seller_verified`: Invite code dolu; Commission tablosunda `Referral` kaynağı; Activity’de reward event.
5. Staff → **Sellers** → `demo_seller_referred`: Referred by = `demo_seller_verified`; Commission’da welcome override.
6. **Beklenen:** Ayrı cüzdan/puan yok; yalnızca süreli commission override + event + bildirim. Kendi kodunu kullanma kayıt formunda reddedilir.

---

## Staff (Operasyon paneli)

Staff menüleri My Account içinde görünür. Giriş: **admin** veya `manage_woocommerce` yetkili hesap.

### Menü haritası

| Menü                | Ne test edilir                                      |
| ------------------- | --------------------------------------------------- |
| **Manage Products** | Satış sonrası ürün / fulfillment işlemleri          |
| **Manage Orders**   | WooCommerce sipariş listesi, durum, listing bağlama |
| **Sellers**         | Satıcı listesi, seviye, komisyon, kısıtlar          |
| **Catalog requests** | Katalogda olmayan ürün talepleri (fulfill / decline) |

---

### Senaryo S1 — Manage Products (liste ve filtre)

1. My Account → **Manage Products**.
2. Listenin yüklendiğini kontrol edin.
3. Durum / arama / deadline filtrelerini deneyin.
4. Süresi dolmuş onay veya kargo uyarısı olan satırları (badge / highlight) bulun.
5. **Beklenen:** Satış pipeline’ındaki farklı durumlar listede görünür; filtre sonuç değiştirir; deadline’ı geçmiş kayıtlar dikkat çeker.

Demo veride özellikle bakılacak örnekler: ödeme onayı bekleyen, satıcı onayı bekleyen (confirm süresi dolmuş), kargo süresi dolmuş, teslim edilmiş, chargeback.

---

### Senaryo S2 — Ödeme onayı ve staff aksiyonları

1. **Manage Products** içinde ödeme onayı bekleyen kaydı açın.
2. Staff **payment confirm** aksiyonunu uygulayın.
3. Sonraki durumdaki bir kayıtta hub’a geliş, doğrulama, kargoya hazır, müşteriye kargo gibi staff adımlarını sırayla deneyin (her adımda not isteniyorsa kısa not yazın).
4. Gerekirse **swap** (listing değiştirme) aday listesini açıp uygun kaydı seçin.
5. Birden fazla satır seçip **bulk** aksiyon deneyin.
6. **Beklenen:** Her aksiyon sonrası durum güncellenir; yetkisiz / yanlış durumdaki aksiyonlar buton olarak gelmez veya hata verir; bulk özet mesajı gelir.

---

### Senaryo S3 — Manage Orders

1. My Account → **Manage Orders**.
2. Sipariş listesinin geldiğini görün; completed / cancelled örnekleri filtreleyin.
3. Marketplace satırı olan bir siparişi açın.
4. Sipariş durumunu değiştirin (tekil).
5. Listeden birden fazla sipariş seçip toplu durum değişimi deneyin.
6. Varsa **attach listing** / aday seçimi ile sipariş satırına listing bağlayın.
7. **Beklenen:** Detayda müşteri, kalemler ve bağlı listing bilgisi görünür; durum değişimi kaydedilir; attach sonrası satır güncellenir.
8. **Attach kuralı:** `processing` / `completed` siparişe bağlama → listing `sold` + satıcıya “satıldı” SMS. `pending` / `on-hold` → `payment`, satıldı SMS’i yok (ödeme onayına kadar).
9. WooCommerce siparişi **cancelled:** `payment` / `sold` / `confirmed` / `pre_order` listing’ler `order_detached` olur. `shipped_to_sutore` ve sonrası değişmez; admin SMS (`order_cancelled_open_fulfillment`) gider.

### Senaryo S3b — Pre-order kapatma (kaynak bulunamadı)

1. **Manage Products** → `pre_order` satırı açın (seed sourcing / staff `mark_pre_order`).
2. **Could not be sourced** aksiyonunu not ile uygulayın.
3. **Beklenen:** Listing `order_detached`; sipariş kalemi iade/kaldırılır (ödenmişse WC refund kaydı); müşteri SMS `pre_order_unsourced_customer`. Başka açık kalem yoksa sipariş `cancelled` olur.

---

### Senaryo S4 — Sellers (satıcı yönetimi)

1. My Account → **Sellers**.
2. Demo satıcıları listede bulun (`demo_seller_*`).
3. `demo_seller_premium` detayını açın → özel komisyon (ör. %7.5) görünmeli.
4. `demo_seller_referred` detayında Referred by = `demo_seller_verified` ve referral commission override görünmeli.
5. `demo_seller_banned` detayında listing oluşturma yasağını görün.
6. Bir satıcıda seviye / status değişimi, komisyon override ekleme-kaldırma, restriction ekleme deneyin.
7. **Beklenen:** Detay sayfası dolu gelir; değişiklikler kaydedilir; satıcı tarafında (ör. ban sonrası create) etki görünür.

---

### Senaryo S5 — Catalog requests (katalog ürün talebi)

1. My Account → **Catalog requests** (bekleyen sayı rozeti görünebilir).
2. Seed: `demo_seller_verified` / `NOT-IN-CATALOG-001` ve `demo_seller_premium` link talebi **Pending** listede.
3. Bir talebi **Mark added** ile kapatın (isteğe bağlı parent product ID; ürünü WP Admin’de sizin eklemeniz gerekir — eklenti katalog oluşturmaz).
4. Diğerini **Decline** edin (isteğe bağlı not).
5. İlgili satıcıyla **Notifications** açın.
6. **Beklenen:** Fulfill sonrası “ürün eklendi, listing açabilirsiniz” bildirimi; decline sonrası red bildirimi. Kuyruk durumu `Added to catalog` / `Declined` olur.

---

## Admin (wp-admin)

Giriş: WordPress yönetim paneli → sol menü **Sutore Marketplace**.

### Menü haritası

| Menü                | Ne test edilir                                                                                 |
| ------------------- | ---------------------------------------------------------------------------------------------- |
| **Settings**        | Fiyat, listing, operasyon, SMS, e-Arşiv fatura, sipariş akışı, kargo, kupon, sözleşme, kampanya varsayılanları |
| **Campaigns**       | Kampanya oluşturma / yayınlama / bitirme                                                       |
| **Outlet**          | Outlet penceresi: ürün+beden kalemi, satıcı opt-in, pencere başı listing / sonda expire          |
| **Tasks & Rewards** | Görev tanımları                                                                                |
| **Events**          | Sistem olay kayıtları                                                                          |

---

### Senaryo A1 — Settings sekmeleri

1. **Sutore Marketplace → Settings** açın.
2. Her sekmeyi gezinin: Pricing, Listing, Operations, SMS, Invoices, Order Flow, Shipping, Coupons, Contracts, Campaigns.
3. Order Flow içinde alt sekmeleri (deadlines, notifications, cargo, templates vb.) kontrol edin.
4. **Notifications:** satıcı olaylarında Panel / SMS kutuları vardır (push yok; app gelince aynı matris). Üst listedeki SMS olayları müşteri/admin/operasyon içindir.
5. Güvenli bir değeri değiştirip kaydedin; sayfayı yenileyip değerin kaldığını doğrulayın.
6. **Beklenen:** Tüm sekmeler açılır; kayıt başarılı mesajı gelir; yanlış değerde doğrulama hatası gösterilir.

---

### Senaryo A6 — Paraşüt e-Arşiv fatura

**Ayar:** WooCommerce → Marketplace → Settings → **Invoices**. Varsayılan kapalıdır; seed Paraşüt’e gitmez.

1. Company ID, Client ID, Client secret, username, password ve KDV %20 kaydedin; **Enable invoicing** işaretleyin.
2. Çok satıcılı bir sepeti ödeyip satışı `sold` yapın (gerekirse Order Flow’da admin payment confirm). **Müşteri faturası henüz kesilmez.**
3. Her kalan kalemi hub’da **Verified** yapın (veya iptal/unsourced/hub reject ile siparişten düşün). Açık `payment`/`sold`/`confirmed`/`shipped_to_sutore`/`arrived_to_sutore`/`pre_order` kalem varken fatura bekler.
4. **Beklenen:** Sipariş için **tek** müşteri satış faturası kuyruğa girer; yalnızca **kalan** ürünler kalem olur (Hizmet Bedeli + Güvence Bedeli). Düşen ürün faturalanmaz. 0 TL satır yazılmaz. Gençlik indirimi hizmet → güvence → komisyon sırasıyla, kesim anındaki kalan kalemlere bölünür. PDF müşteri **billing** e-postasına gider; panelde listing satırından **View invoice** ile açılır (direkt uploads URL’si yoktur). **İade / credit faturası kesilmez** — teslim sonrası chargeback mevcut e-Arşiv’i iptal etmez.
5. Manage Products → ilgili satışı **Mark as Paid to Seller**.
6. **Beklenen:** Satıcı komisyon faturası listing başına kuyruğa girer (kalem adı **Komisyon Bedeli**); PDF satıcı **profil e-postasına** gider. Satış ve payout, fatura hatasında durmaz — detayda Invoice error görünür, cron yeniden dener.
7. Export CSV’de müşteri/satıcı fatura no, tarih ve durum kolonları dolu veya kuyruk/hata durumunu gösterir.

---

### Senaryo A2 — Kampanya yönetimi

1. **Sutore Marketplace → Campaigns**.
2. Yeni kampanya oluşturun (indirim tipi, hedef, tarih).
3. Preview / teklif listesini kontrol edin.
4. **Publish** edin → satıcı panelinde **Campaign offers** altında teklif görünmeli (`demo_seller_verified` ile doğrulayın).
5. Kampanyayı **End** ile bitirin.
6. **Beklenen:** Yayın sonrası satıcıya teklif düşer; bitince yeni teklif üretilmez / aktif kampanya kapanır.

---

### Senaryo A2b — Outlet penceresi

1. **Sutore Marketplace → Outlet**.
2. Seed’de **Seed Outlet Draft** (yayınlanmamış), **Seed Outlet Upcoming** (scheduled + bekleyen opt-in), **Seed Outlet Live** (açık + canlı listing) görünür.
3. Draft’ı **Publish** edin (başlangıç gelecekteyse scheduled kalır). Yeni kalem: parent product ID (`SEED-OUTLET`) + size term ID + müşteri satış + satıcı asking.
4. Live pencereyi **End** edin.
5. **Beklenen:** Satıcı **Outlet** sekmesinde kalemler REST ile dolar; End sonrası satılmayan listing expire olur, kampanya asking restore’u çalışmaz.

---

### Senaryo A3 — Tasks & Events

1. **Tasks & Rewards** → görev tanımlarını listeleyin / düzenleyin.
2. Satıcı **My Tasks** ekranında tanımın yansıdığını kontrol edin.
3. **Events** sayfasını açın; listing / merchant işlemlerinden sonra yeni event satırları gelmeli.
4. **Beklenen:** Tanımlar kaybolmaz; event listesi zaman damgalı ve filtrelenebilir.

---

### Senaryo A4 — WooCommerce yan yüzeyler (kısa)

1. WC ürün düzenleme ekranında Sutore Marketplace alanlarının göründüğünü kontrol edin.
2. Kupon düzenlemede Sutore ile ilgili meta / kuralların olduğunu kontrol edin.
3. Gerekirse bir test kuponuyla checkout’ta kuralı doğrulayın (`demo_customer`).

---

### Senaryo A5 — Hizmet / güvence bedeli değişimi (kritik)

Eski eklentide bu değişiklik tüm ürün fiyatlarını elle güncellemeyi gerektirirdi; yenide gerekmez.

1. Satıştaki bir ürünün vitrin / sepet fiyatını not edin (`demo_customer` veya gizli pencere).
2. Satıcı panelinde aynı listing’in **asking** tutarını not edin (`demo_seller_verified` → My Listings).
3. **Settings → Pricing** içinde hizmet bedeli ve/veya güvence oranını değiştirip kaydedin.
4. Aynı ürün sayfasını / sepeti yenileyin → müşteri toplamı yeni ücrete göre değişmeli.
5. Satıcı listing’indeki asking **aynı kalmalı** (toplu ürün güncellemesi yok).
6. İsterseniz eski bir sipariş detayındaki ücret kırılımının değişmediğini kontrol edin (sipariş anlığı korunur).
7. Test bitince ücretleri eski değerlere geri alın.

---

## Müşteri ile çapraz kontrol (kısa)

**Hesap:** `demo_customer`

1. Satıştaki bir ürünü sepete ekleyip checkout’a gidin.
2. Kargo seçimi ve toplamın güncellendiğini görün.
3. Sözleşme onay kutusu / modal varsa işaretlemeden ilerlemeyi deneyin → engellenmeli.
4. Sipariş sonrası hesap → sipariş detayında ürün durum etiketini okuyun.
5. **Beklenen:** Fiyat ve kargo tutarlı; sözleşmeler zorunluysa atlanamaz; durum metni müşteri dilinde anlaşılır.

### Senaryo C2 — Gençlik indirimi (TC + doğum yılı)

**Ayar:** WooCommerce → Marketplace → Settings → **Operations** — Enable youth discount, Maximum age 26, Discount percent 20. Seeder bunları açar.

**Hesap:** `demo_customer_youth` (şifre `password`)

1. Mağazada **Seed Dunk Low Market**, beden **43 (Seed)** ürününü sepete ekleyin.
2. Sepet / checkout toplamında **Youth discount** satırı görünmeli (kupon kodu yok; negatif fee).
3. Satır tutarı (asking + hizmet + güvence) düşmemeli; indirim ayrı satırda, tavan hizmet + güvence + komisyon.
4. `demo_customer` ile aynı ürünü sepete ekleyin → gençlik satırı **olmamalı**.
5. Staff sipariş / satıcı payout: satıcı neti `asking − komisyon` (gençlik indirimi satıcı netine yansımaz).

### Senaryo C3 — Müşteri fiyat teklifi

**Ayar:** Settings → Campaigns → Customer price offers açık (seeder açar). Bid, satıcı **asking**’ine göredir.

**Hesap:** `demo_customer` (şifre `password`)

1. My Account → **My offers**.
2. Seed Dunk Low Market beden 43 için **accepted** kayıt ve kupon kodu görünür (asking 750, teklif 550). **Add to cart** kuponu uygular. PDP vitrin fiyatı asking’den düşmez.
3. Seed Jordan 1 Queue beden 43 için **pending** teklif vardır (asking 500, teklif 400); Cancel denenebilir.
4. Mağazada başka bir satıştaki üründe beden seçip **Make an offer** (asking’in altında, adım katı, min %70).
5. **Beklenen:** Teklif satıcı **Customer offers** kuyruğuna düşer; herkese açık sale yazılmaz.

**Hesap:** `demo_customer_youth`

1. **My offers** — kuyruktaki satıcıya iletilmiş pending teklif (verified decline sonrası).

---

## Önerilen test sırası (yarım gün)

1. Admin Settings’e hızlı bakış (A1)
2. Hizmet / güvence bedeli değişimi (A5) — eski eklentiye göre kritik fark
3. Merchant listing listesi + oluşturma (M1, M2)
4. Staff Manage Products filtre + bir pipeline aksiyonu (S1, S2)
5. Merchant satış onayı / kargo (M5)
6. Pre-order accept (M6)
7. Admin kampanya publish → Merchant teklif (A2 + M7)
8. Staff Manage Orders + Sellers (S3, S4)
9. Bildirim / görev / profil (M8)
10. Müşteri checkout (çapraz)

---

## Yeni vs eski — pozitif farklar

Karşılaştırma: **eski** `sutore-uyelik` ↔ **yeni** `sutore-marketplace`.  
Her maddede önce eski davranış, sonra yeni davranış anlatılır.

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
- **Yeni:** Seviyeler sadeleştirildi (ör. Normal / Confirmed / Premium) ve her seviyenin komisyon oranı Settings’ten yönetilir. Komisyon **asking üzerinden** hesaplanır; satıcı neti = asking − komisyon. Staff **Sellers** ekranından süreli komisyon override verebilir; görev ödülleri de override üretebilir. Demo’da premium satıcıda özel oran hazır gelir.

### 6. Kampanya indiriminin mantığı

- **Eski:** Kampanya indirimi ürün meta’sına yazılıyordu; isimlendirme hatalı (`campaing`) ve merkezi kampanya yönetimi zayıftı. İndirimin asking’e mi ücretlere mi gittiği paneller arası net değildi.
- **Yeni:** Admin **Campaigns** ile kampanya oluşturur / yayınlar / bitirir; satıcı **Campaign offers** ile kabul / red eder. Platform indirimi tasarım olarak ücret tarafında (hizmet/güvence) yönetilebilir; satıcının asking’inin altına inilmez. Kampanya durumu listing durumundan ayrıdır.

### 7. Listing süresi (expire)

- **Eski:** Yayınlanınca `expire_date` çoğu zaman koda gömülü ~45 güne yazılıyordu; cron süresi dolanları `expired` yapıyordu. Süreyi iş kuralı olarak paneden yönetmek zordu.
- **Yeni:** Süre **Settings → Listing** içinden gün cinsinden ayarlanır; oluşturma / satışa koyma `expire_at` üretir; saatlik kontrol süresi dolanları `expired` yapar. Satışa bağlanınca süreyi dondurma gibi kurallar ayarlanabilir.

### 8. Genel yapı

- **Eski:** Tüm iş tek eklentide dağınık PHP dosyalarına yayılmıştı. `index.php`, `functions.php`, `sutore.php` gibi çok büyük dosyalar hem menüyü, hem SMS’i, hem satış durumunu, hem paneli yönetiyordu. Sınıf / modül ayrımı yoktu; bir yeri değiştirmek başka yeri bozma riski taşıyordu.
- **Yeni:** İş alanları ayrı modüllere bölündü (Listings, Orders, Merchants, Sourcing, Tasks, Shipping, Coupons, Contracts, OTP…). Panel, iş kuralı ve veri erişimi ayrı katmanlarda. Bir özellik üzerinde çalışırken diğer alanlar daha net izole.

### 9. Panelin veri çekme biçimi

- **Eski:** Satıcı veya staff bir butona basınca `admin-ajax.php` çağrılıyordu. Sunucu çoğu zaman HTML parçası döndürüyor, sayfa içine enjekte ediliyordu. Standart bir API sözleşmesi yoktu; “hangi alan gelecek?” sorusunun cevabı her Ajax aksiyonuna göre değişiyordu.
- **Yeni:** Paneller önce iskeleti (başlık, menü, spinner) gösterir; liste ve detay verisi REST API’den JSON olarak gelir. Aynı veri hem ekranda hem ileride başka istemcide tutarlı kullanılabilir. Test ederken Network’te net resource’lar görürsünüz; HTML fragment avı yoktur.

### 10. Satıcı My Account deneyimi

- **Eski:** Listing listeleri sunucuda PHP ile render edilirdi (`inc/loops` vb.). Filtre/detay için ayrı Ajax’lar HTML dönerdi. Sayfa yenileme ve parça güncelleme karışıktı; hata mesajları tutarsız olabilirdi.
- **Yeni:** **My Listings**, **Pre-order**, **Campaign offers**, **Merchant exclusive**, **My Tasks**, **Notifications** menüleri net. Sayfa açılınca liste API’den dolar; oluşturma / onay / kargo gibi işlemler aynı panel dilinde ilerler. Test checklist’i menü menü yazılabilir. Toplu CSV listing yükleme de aynı panelden yürür.

### 11. Staff paneli

- **Eski:** Operasyon ekranları My Account altında anlaşılmaz / gizlenmiş URL’lerle açılıyordu (ör. uzun rastgele slug’lar). “Güvenlik için gizledik” yaklaşımı hem bulmayı zorlaştırıyor hem gerçek yetkilendirme yerine geçmiyordu. Ürün listesi SSR tablo + Ajax status modal karışımıydı.
- **Yeni:** Staff için üç açık menü var: **Manage Products**, **Manage Orders**, **Sellers**. Yetki WooCommerce `manage_woocommerce` ile kontrol edilir. Ürün fulfillment, sipariş yönetimi ve satıcı yönetimi ayrı ekranlarda test edilir; URL’yi ezberlemek gerekmez.

### 12. Ürün / listing durumu

- **Eski:** Listing durumu WooCommerce varyasyonunun `post_status` alanına yazılıyordu (`payment`, `sold`, `shipped-to-sutore`, `paid`…). Bu, WC’nin normal ürün yayın semantiğini bozuyordu. Aynı dosya setinde sipariş status’leri de karışık kayıtlıydı. Etiketler Türkçe hardcoded; bazı switch’lerde aynı status iki kez işleniyordu.
- **Yeni:** Durum tek lineer zincirdedir ve marketplace’in kendi `listing_status` alanında tutulur (`pending` → `publish` → … → `payment` → `sold` → … → `delivered_to_customer` / `chargeback`). Satış öncesi ve satış sonrası aynı dilde okunur. Panelde gördüğünüz etiket ile sistemdeki durum bire bir uyumludur. Ürün durumuna `paid` gömülmez (ödeme ayrıdır).

### 13. Satış sonrası süreç (fulfillment)

- **Eski:** Onay süresi, kargo süresi, takip no, satıcı anlık bilgisi gibi her şey ürün meta alanlarına dağılmıştı. “Fulfillment” diye ayrı bir kavram yoktu; her şey yine aynı varyasyon post’unun status + meta yığınıydı. Staff ve satıcı aksiyonları dağınık Ajax fonksiyonlarına bağlıydı.
- **Yeni:** Lojistik alanlar listing kaydının parçasıdır; staff **Manage Products** üzerinden pipeline’ı ilerletir, satıcı kendi listing’inden onay / kargo yapar. Deadline, tracking, not alanları aynı akışta görünür. Testte “hangi ekrandan hangi adım?” sorusu nettir.

### 14. Satıcı ödemesi (payout)

- **Eski:** Satıcıya ödeme yapıldığında ürün durumu `paid` oluyordu. Yani “ürün nerede?” ile “satıcıya para ödendi mi?” aynı zincirde karışıyordu. IBAN / ödeme bilgisi ürün metalarına yazılıyordu; ayrı bir ödeme defteri yoktu. Net tutar da çoğu zaman “ürün fiyatından ücretleri geri çıkar + komisyon” ile anlık hesaplanıyordu; ücret ayarı değişince net de bozulabilirdi.
- **Yeni:** Payout ayrı kayıttır (`pending` / `paid` / `reversed`). Lojistik durumdan bağımsız izlenir. Chargeback’te payout reverse edilebilir. Asking + komisyon modeli neti üretir; ücret ayarı değişince geçmiş payout satırları bozulmaz.

### 15. Ön sipariş (sourcing / pre-order)

- **Eski:** Ön sipariş yine bir `pre-order` ürün status’üydü. Stok / iptal sonrası diğer satıcılara SMS ile “bu beden lazım” duyurusu (`ask_to_merchant`) yapılırdı; satıcı paneli SSR listelerle ilerlerdi. Kabul / rezervasyon status ve meta’ya gömülüydü. SMS içinde güvence oranı bazen sabitte (`/1.10`) varsayılırdı — ayar %10 değilse fiyat metni yanlış olurdu.
- **Yeni:** Satıcıda ayrı **Pre-order** paneli vardır: açık talepler listelenir, detay açılır, eşleşen listing ile kabul edilir. Durum `pre_order` olarak market dilinde tutulur; board + accept ayrı test senaryosudur (M6). Fiyat metinleri canlı pricing modeline bağlıdır.

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
- **Yeni:** Tek kural: listing kimliği = WooCommerce **variation id**. Panel deep link’leri, staff aksiyonları, satıcı listesi hep aynı id’yi kullanır. Ayrı auto-increment “listing_id” yoktur; testte “hangi id?” karışıklığı azalır.

### 20. Dil / çeviri

- **Eski:** Ekran metinlerinin çoğu doğrudan Türkçe yazılmıştı. Text domain bazen `woocommerce`, bazen boş, bazen hatalıydı. İngilizce site / başka dil açmak gerçekçi değildi.
- **Yeni:** Kaynak metinler İngilizce; `sutore-marketplace` domain’i ve TR çeviri dosyası vardır. Panel testinde site dilini değiştirince menü ve mesajların çevrildiğini kontrol edebilirsiniz.

### 21. Güvenlik ve yetki

- **Eski:** Çok sayıda `wp_ajax` / bazen `nopriv` aksiyon vardı. Yetki kontrolleri aksiyon aksiyon dağınıktı. Staff URL’lerini gizlemek yetkilendirme sanılıyordu. Formlar HTML dönünce XSS / tutarsız escape riski artıyordu.
- **Yeni:** Marketplace işlemleri REST üzerinden yetki callback’i ile korunur (giriş, ownership, staff capability). Staff menüsü herkese açık değildir; satıcı yalnız kendi listing’inde işlem yapar. Panel testi sırasında yetkisiz hesapla menünün gelmemesi de doğrulanmalıdır.

### 22. Eklenti dağınıklığı

- **Eski:** Üyelik / merchant paneli `sutore-uyelik` iken kupon, kargo, sözleşme gibi parçalar ayrı old-plugin’lerde yaşardı. Bir checkout bug’ı için üç eklentiye bakmak gerekirdi; sürümler birbirinden kopardı.
- **Yeni:** Shipping, Coupons, Contracts, OTP, Tasks aynı `sutore-marketplace` içinde. Admin Settings sekmeleri tek menüden yönetilir. Checkout + satıcı + staff testi tek ürün gibi ele alınır.

### 23. Ayarlar ve SMS şablonları

- **Eski:** Hizmet/güvence option’ları vardı ama birçok davranış (expire süresi, SMS metinleri, uyarı cümleleri) koda gömülü Türkçe string’lerdi. Netgsm çağrıları ve mesaj gövdeleri fonksiyonların içindeydi; operasyon “metni panelden değiştir” diyemezdi.
- **Yeni:** **Settings** altında Pricing, Listing, Operations, SMS, Invoices, Order Flow, Shipping, Coupons, Contracts, Campaigns sekmeleri vardır. SMS / order-flow şablonları ve deadline’lar paneden yönetilir. Ücret değişimi yalnızca Pricing’den yapılır; toplu ürün güncellemesi gerekmez.

### 24. Bildirim ve görevler

- **Eski:** Ağırlık SMS ve ad-hoc uyarılardaydı. Satıcıya panel içi bildirim merkezi / görev-ödül ekranı olgun bir ürün parçası değildi.
- **Yeni:** Satıcıda **Notifications** ve **My Tasks** menüleri vardır; admin’de görev tanımları yönetilir. Satıcı olayları tek `dispatch` üzerinden panel ve/veya SMS gider (Order Flow → Notifications kanal matrisi). Seed sonrası bildirim ve görev ilerlemesi panelden tıklanarak doğrulanır.

### 25. Test edilebilirlik

- **Eski:** Hazır “tüm durumları dolduran” demo seti yoktu. Testçi elle ürün yaratıp status’leri meta/post_status ile zorlamak zorundaydı. Ücret değişince regresyonu doğrulamak için pratikte tüm ürünleri yeniden fiyatlamak gerekirdi.
- **Yeni:** Demo senaryo seti satıcı seviyeleri, market durumları, satış pipeline’ı, pre-order, kampanya, payout, staff sipariş örneklerini hazırlar. Bu rehberdeki M/S/A senaryoları o veri üzerine yazılmıştır. Ücret değişikliğini test etmek için Settings’i değiştirip vitrin/sepete bakmak yeterlidir.

### 26. Bakım ve evrim

- **Eski:** Yazım hatalı meta isimleri (`campaing`, `adress`), comment-out SMS yolları, ölü switch kolları kodda birikirdi. “Eski kayıt bozulmasın” gerekçesiyle ikinci yollar kalırdı.
- **Yeni:** Geliştirme aşamasında legacy / alias / çift format bilinçli olarak tutulmaz. İsim değişince tüm panel + API + seed birlikte güncellenir. Testçi “eski status adı da geçerli mi?” diye bakmak zorunda kalmaz — tek doğru model vardır.

---

## Özet

Eski `sutore-uyelik` dönemi marketplace’i “çalışan ama kırılgan” bir operasyon haline getirmişti: fiyat ürüne gömülüyordu, durum WooCommerce’in yayın alanına yazılıyordu, staff menüleri gizleniyordu, kupon/kargo/sözleşme ayrı eklentilerde yaşıyordu. Yeni `sutore-marketplace` aynı işi — satıcı listing’i, fiyat yarışı, satış sonrası lojistik, satıcı ödemesi, kampanya, ön sipariş, staff operasyonu — **tek ürün, net paneller ve tutarlı kurallar** altında toplar. Aşağıdaki kazanımlar, eski modele göre yalnızca “biraz daha düzenli” değil; günlük operasyon, test ve büyümeyi kökten kolaylaştırır.

### Operasyon artık ücret değişiminde kilitlenmiyor

Eskide hizmet bedeli veya güvence oranı değişince pratikte **tüm ürün fiyatlarını yeniden yazmak** gerekirdi; aksi halde vitrin eski ücrete kalır, satıcı ekranındaki asking / net kazanç ise yeni ayarla ters düşerdi. Bu hem zaman kaybı hem hata kaynağıydı. Yenide listing’de yalnız satıcının **asking**’i durur; hizmet ve güvence **Settings → Pricing**’den canlı hesaplanır. Admin ücreti değiştirir → ürün sayfası ve sepet yeni toplamı gösterir → satıcının asking’ine dokunulmaz → eski siparişlerin ücret kırılımı snapshot ile korunur. Tek başına bu fark, eski eklentinin en pahalı operasyonel acısını ortadan kaldırır.

### Her rol kendi panelinden, kaybolmadan işini bitirir

Eskide satıcı SSR listeler + Ajax HTML ile ilerlerdi; staff ise anlaşılmaz URL’lerin ardında ürün tablosu güncellerdi. Yenide:

- **Satıcı:** My Listings, Pre-order, Campaign offers, Outlet, Merchant exclusive, My Tasks, Notifications
- **Staff:** Manage Products, Manage Orders, Sellers, Catalog requests
- **Admin:** Settings, Campaigns, Tasks & Rewards, Events

Menü isimleri işi anlatır. Listing oluşturma, satış onayı, kargo, teklif kabulü, sipariş durumu, satıcı kısıtı — hepsi tıklanabilir bir yolda. “Bu ekran nerede?” sorusu operasyonu yavaşlatmaz.

### Durum dili tek: ürün nerede, para ödendi mi ayrı

Eskide `payment` / `sold` / `shipped` / hatta `paid` aynı `post_status` zincirindeydi; “ürün lojistikte mi, satıcıya ödeme yapıldı mı?” sorusu birbirine karışırdı. Yenide tek lineer **listing durumu** satış öncesi ve sonrası pipeline’ı anlatır; **payout** ayrı kayıttır (`pending` / `paid` / `reversed`). Chargeback lojistiği bozmadan ödemeyi ters çevirebilirsiniz. Staff Manage Products ile ürünü, Sellers / payout ile ödemeyi ayrı doğrular — muhasebe ile depo aynı düğmeye mahkûm değildir.

### Fiyat yarışı, kondisyon ve kuyruk gerçek bir market gibi

Eski modelde kazanan çoğu zaman brüt WC fiyatına ve `draft` kuyruğuna bağlıydı; ücret gömülünce yarış da bozulabilirdi. Yenide aynı ürün + beden için **publish / queued** açıkça ayrılır; sıralama en düşük asking, eşitlikte daha eski listing. Kusur müşteriye rozet olarak gösterilir, sırayı değiştirmez. Satıcı “sıradayım”ı panelden görür; staff kazananı ve bekleyenleri aynı dilde okur.

### Kampanya, ön sipariş, görev ve bildirim ürünleşti

Eskide kampanya ürün meta’sı + yazım hatalı Ajax’tı; ön sipariş status + SMS duyurusuydu; görev/bildirim paneli olgun değildi. Yenide:

- Admin kampanyayı oluşturur / yayınlar / bitirir; satıcı teklifi kabul veya reddeder
- Pre-order board’dan talep görülür ve eşleşen listing ile kabul edilir
- Bildirim merkezi ve görev-ödül ekranı satıcıyı paneli terk etmeden bilgilendirir

Bunlar “ekstra script” değil; test checklist’ine yazılacak birinci sınıf özelliklerdir.

### Tek eklenti, tek ayar yüzeyi, çok dil

Kargo, kupon, sözleşme, OTP ve görevler ayrı old-plugin’lerde dağılmaz; hepsi Sutore Marketplace Settings altında. SMS metinleri ve deadline’lar koddan değil panelden değişir. Kaynak dil İngilizce, Türkçe çeviri kataloğu vardır — eski hardcoded TR + bozuk text domain döneminin aksine site dili değiştirilebilir.

### Güvenlik ve bakım bilinçli tasarlandı

Eski Ajax yığını + gizli URL “güvenliği” yerine yenide REST yetkisi, ownership ve staff capability vardır. Legacy alias / çift format biriktirilmez; tek doğru model testçiye de geliştiriciye de aynı cevabı verir. Demo seed ile tüm durumlar yeniden kurulur — eski dünyada elle status zorlamak yerine bu rehberdeki senaryolar tekrarlanır.

### Sonuç: fark “kozmetik” değil, ölçek farkı

Eski eklenti marketplace’i **ürün meta’sına ve Ajax’a yaslanmış bir monolit** olarak ayakta tutuyordu; çalışıyordu ama her ücret değişimi, her yeni status, her staff işi ve her ek özellik maliyeti katlanıyordu. Yeni eklenti:

1. Ücreti üründen ayırıp ayara bağladı
2. Durumu tek zincire, ödemeyi ayrı deftere aldı
3. Satıcı / staff / admin işlerini okunur menülere taşıdı
4. Kampanya, ön sipariş, kuyruk, kondisyon, görev ve bildirimi ürünleştirdi
5. Kupon–kargo–sözleşmeyi tek çatıda birleştirdi
6. Testi demo veri + panel senaryolarıyla tekrarlanabilir kıldı

Bu yüzden olumlu yönler eski eklentiye göre yalnızca “daha fazla madde” değil; **daha az operasyonel yangın, daha hızlı panel testi, daha güvenli büyüme** demektir. Yukarıdaki M / S / A senaryolarını dolaşmak, bu farkın kağıtta değil ekranda da hissedildiğini doğrulamanın en kısa yoludur.
