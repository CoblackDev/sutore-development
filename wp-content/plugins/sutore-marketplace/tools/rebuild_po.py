#!/usr/bin/env python3
"""Rebuild tr_TR.po/.mo from TR→EN map + SMS + extra pairs."""
from __future__ import annotations

import json
import re
import struct
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOOLS = ROOT / "tools"
LANG = ROOT / "languages"

# English msgid -> Turkish msgstr extras not covered by original map (or replacements)
EXTRA: dict[str, str] = {
    " — %s (estimated delivery)": " — %s (tahmini teslimat)",
    "Leave empty to use the translated default (“Contracts”).": "Çevrilen varsayılanı kullanmak için boş bırakın (“Sözleşmeler”).",
    "Dear {customer_name}, your sutore.com order {order_id} has been received.": "Sayın {customer_name}, {order_id} numaralı sutore.com siparişiniz işleme alınmıştır.",
    "{product} sold for {price} TL. Order: {order_id} Shipping: {shipment_type}": "{product} ürünü {price} TL'ye satıldı. Sipariş: {order_id} Kargo: {shipment_type}",
    "Your {product} sold for {price} TL. Please confirm the sale within {confirm_hours} hours.": "{product} ürününüz {price} TL'ye satılmıştır. {confirm_hours} saat içinde satışınızı onaylamanız gerekmektedir.",
    "If you do not confirm your {product} sale within {confirm_hours} hours, it will be suspended.": "{product} satışınızı {confirm_hours} saat içinde onaylamazsanız askıya alınacaktır.",
    "Please ship {product} to our hub within {cargo_hours} hours.": "{product} ürününüzü {cargo_hours} saat içinde merkezimize göndermeniz gerekmektedir.",
    "The shipping deadline for {product} has passed. Contact us to avoid suspension.": "{product} kargo süresi doldu. Satışın askıya alınmaması için iletişime geçin.",
    "The seller confirmed {product} on your order {order_id}.": "{order_id} numaralı siparişinizdeki {product} satışı satıcı tarafından onaylanmıştır.",
    "You confirmed your {product} sale. Ship via Yurtici ({yurtici_code}) within {cargo_hours} hours.": "{product} satışınızı onayladınız. {cargo_hours} saat içinde Yurtiçi ({yurtici_code}) ile gönderin.",
    "WARNING: {product} was sold internationally. Ship with invoice or order screenshot.": "UYARI: {product} yurt dışına satılmıştır. Fatura veya sipariş ekran görüntüsüyle gönderin.",
    "WARNING: Express sale {product} — do not ship via courier; contact us.": "UYARI: Express satış {product} — kargo ile göndermeyin, iletişime geçin.",
    "{product} from order {order_id} was shipped to our hub for verification.": "{order_id} siparişinizdeki {product} doğrulama için merkezimize gönderildi.",
    "{product} was shipped to our hub. Track: http://yurti.ci/{track_code}": "{product} merkezimize gönderildi. Takip: http://yurti.ci/{track_code}",
    "{product} from order {order_id} arrived at our verification hub.": "{order_id} siparişinizdeki {product} kontrol merkezimize ulaştı.",
    "{product} sold for {price} TL arrived at our hub and is being inspected.": "{price} TL'ye satılan {product} merkezimize ulaştı, inceleniyor.",
    "{product} from order {order_id} was verified and is being shipped to you.": "{order_id} siparişinizdeki {product} doğrulandı, size gönderiliyor.",
    "{product} sold for {price} TL was verified. Your payout is being processed.": "{price} TL'ye satılan {product} doğrulandı. Ödemeniz işleme alınıyor.",
    "{product} from order {order_id} was shipped to you. Track: http://yurti.ci/{track_code}": "{order_id} siparişinizdeki {product} size gönderildi. Takip: http://yurti.ci/{track_code}",
    "Your payout for {product} sold at {price} TL has been completed.": "{price} TL'ye satılan {product} için ödemeniz gerçekleştirildi.",
    "Alternative sourcing is being considered for {product} on order {order_id}.": "{order_id} siparişinizdeki {product} için alternatif temin değerlendiriliyor.",
    "Your {product} sale was suspended because it was not confirmed.": "{product} satışınız onaylanmadığı için askıya alındı.",
    "Urgent sourcing request for {product}. Check the panel if you have a matching listing.": "{product} için acil temin talebi. Uygun listinginiz varsa panelden kontrol edin.",
    "Your order {order_id} is complete. Thank you.": "{order_id} numaralı siparişiniz tamamlanmıştır. Teşekkür ederiz.",
    "A refund for your order {order_id} has been processed.": "{order_id} numaralı siparişinize ilişkin ücret iadeniz gerçekleştirilmiştir.",
    "Deliver your product in a double box to Yurtici Kargo (%2$s) within %1$d hours.": "Ürününüzü %1$d saat içinde çift kutu ile Yurtiçi Kargo'ya (%2$s) teslim edin.",
    "Deliver your product in a double box to Yurtici Kargo (%s) within %d hours.": "Ürününüzü %d saat içinde çift kutu ile Yurtiçi Kargo'ya (%s) teslim edin.",
    "Draft": "Taslak",
    "None": "Yok",
    "Offer": "Teklif",
    "Manage Listing": "Listing Yönet",
    "Sale / fulfillment": "Satış / fulfillment",
    "This listing is in an order flow and cannot be edited.": "Bu listing sipariş akışında olduğu için düzenlenemez.",
    "View offer": "Teklifi gör",
    "Pre-order offer": "Ön sipariş teklifi",
    "Pre-order not found.": "Ön sipariş bulunamadı.",
    "You have accepted this pre-order.": "Bu ön siparişi kabul ettiniz.",
    "Matching listing": "Eşleşen listing",
    "Activity history": "Aktivite geçmişi",
    "All logged interventions for this listing from creation through fulfillment.": "Bu listing için oluşturmadan fulfillment’a kadar kaydedilen tüm müdahaleler.",
    "No activity recorded yet.": "Henüz kayıtlı aktivite yok.",
    "Actor": "Aktör",
    "system": "sistem",
    "This product is not linked to an order.": "Bu ürün bir siparişe bağlı değil.",
    "No further actions are available for this product status.": "Bu ürün durumu için başka işlem yok.",
    "This product cannot be put back on sale in its current status.": "Bu ürün mevcut durumunda tekrar satışa çıkarılamaz.",
    "Seller payment details (at sale)": "Satıcı ödeme bilgileri (satış anı)",
    "Frozen from the seller profile when this sale was created. Later profile changes do not update this record.": "Satış oluşturulurken satıcı profilinden dondurulur. Sonraki profil değişiklikleri bu kaydı güncellemez.",
    "No payment details were recorded at sale time.": "Satış anında ödeme bilgisi kaydedilmemiş.",
    "Account holder": "Hesap sahibi",
    "City / district": "Şehir / ilçe",
    "Recorded at": "Kayıt zamanı",
    "Actions": "İşlemler",
    "Product details": "Ürün bilgileri",
    "Overview of this sale: product, status, order, seller, and payout state.": "Bu satışın özeti: ürün, durum, sipariş, satıcı ve ödeme durumu.",
    "Fulfillment status": "Fulfillment durumu",
    "Listing status": "Listing durumu",
    "Shipping details": "Kargo bilgileri",
    "Tracking numbers for seller-to-Sutore and Sutore-to-customer shipments.": "Satıcı→Sutore ve Sutore→müşteri kargo takip numaraları.",
    "Seller shipping tracking number": "Satıcı kargo takip no",
    "Sutore shipping tracking number": "Sutore kargo takip no",
    "Listing not found.": "Listing bulunamadı.",
    "Tracking to Sutore": "Sutore’ye takip",
    "Tracking to customer": "Müşteriye takip",
    "Sold at": "Satış tarihi",
    "Delivered to customer": "Müşteriye teslim",
    "Payout status": "Ödeme durumu",
    "Paid at": "Ödeme tarihi",
    "Sale position": "Satış sırası",
    "Currently #1 for sale": "Şu an satışta (#1)",
    "Shipping alert": "Kargo uyarısı",
    "The shipping deadline has passed. Contact Sutore to avoid suspension.": "Kargo süresi doldu. Askıya alınmamak için Sutore ile iletişime geçin.",
    "Sale cancelled": "Satış iptal",
    "This sale was cancelled.": "Bu satış iptal edildi.",
    "Sale refunded": "Satış iade edildi",
    "This sale was refunded.": "Bu satış iade edildi.",
    "Sale suspended": "Satış askıda",
    "This sale was suspended.": "Bu satış askıya alındı.",
    "In sale / fulfillment": "Satış / fulfillment’da",
    "Sale ended": "Satış bitti",
    "Youth discount": "Gençlik indirimi",
    "Youth discount (%s%%)": "Gençlik indirimi (%%%s)",
    "Enable youth discount": "Gençlik indirimini aç",
    "Maximum age": "Azami yaş",
    "Discount percent": "İndirim oranı",
    "Year of birth": "Doğum yılı",
    "YYYY": "YYYY",
    "Used with your national ID to apply the youth discount.": "Gençlik indirimini uygulamak için kimlik numaranızla birlikte kullanılır.",
    "First and last name are required for the youth discount.": "Gençlik indirimi için ad ve soyad zorunludur.",
    "Youth discount is unavailable while TC verification is set to manual approval.": "TC doğrulama manuel onaydayken gençlik indirimi kullanılamaz.",
    "Customers younger than this age qualify. Age is current year minus verified birth year.": "Bu yaşın altındaki müşteriler yararlanır. Yaş, içinde bulunulan yıl eksi doğrulanmış doğum yılıdır.",
    "Verified customers below the maximum age see an automatic cart fee (not a coupon). Seller asking and seller net (asking minus commission) stay unchanged. The discount is capped by remaining service fee + guarantee fee + commission.": "Azami yaşın altındaki doğrulanmış müşteriler sepette otomatik bir ücret satırı görür (kupon değil). Satıcı asking’i ve satıcı neti (asking eksi komisyon) değişmez. İndirim kalan hizmet bedeli + güvence bedeli + komisyon ile tavanlanır.",
}


def po_escape(s: str) -> str:
    return (
        s.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
        .replace("\t", "\\t")
    )


def unescape_po(s: str) -> str:
    out = []
    i = 0
    while i < len(s):
        if s[i] == "\\" and i + 1 < len(s):
            n = s[i + 1]
            mapping = {"n": "\n", "t": "\t", '"': '"', "\\": "\\"}
            out.append(mapping.get(n, n))
            i += 2
        else:
            out.append(s[i])
            i += 1
    return "".join(out)


def write_mo(entries: list[tuple[str, str]], mo_path: Path) -> None:
    ordered = [("", "")] + [(e, t) for e, t in sorted(entries) if e]
    # Fix header
    header = (
        'Project-Id-Version: Sutore Marketplace\n'
        'Language: tr_TR\n'
        'MIME-Version: 1.0\n'
        'Content-Type: text/plain; charset=UTF-8\n'
        'Content-Transfer-Encoding: 8bit\n'
        'X-Domain: sutore-marketplace\n'
    )
    pairs = [(b"", header.encode("utf-8"))]
    for msgid, msgstr in ordered[1:]:
        pairs.append((msgid.encode("utf-8"), msgstr.encode("utf-8")))

    kcount = len(pairs)
    key_table_offset = 28
    val_table_offset = key_table_offset + 8 * kcount
    strings_offset = val_table_offset + 8 * kcount

    key_blob = bytearray()
    val_blob = bytearray()
    key_meta = []
    val_meta = []
    for k, v in pairs:
        key_meta.append((len(k), strings_offset + len(key_blob)))
        key_blob += k + b"\0"
    val_base = strings_offset + len(key_blob)
    for k, v in pairs:
        val_meta.append((len(v), val_base + len(val_blob)))
        val_blob += v + b"\0"

    out = bytearray()
    out += struct.pack("<Iiiiiii", 0x950412DE, 0, kcount, key_table_offset, val_table_offset, 0, 0)
    for length, offset in key_meta:
        out += struct.pack("<II", length, offset)
    for length, offset in val_meta:
        out += struct.pack("<II", length, offset)
    out += key_blob
    out += val_blob
    mo_path.write_bytes(bytes(out))


def main() -> None:
    tr_to_en = json.loads((TOOLS / "_i18n_map_merged.json").read_text(encoding="utf-8"))
    en_to_tr: dict[str, str] = {}
    for tr, en in tr_to_en.items():
        if en != tr:
            en_to_tr[en] = tr
    en_to_tr.update(EXTRA)

    # Drop retired ETA msgids if present.
    en_to_tr.pop(" -  %s (estimated delivery)", None)
    en_to_tr.pop(" -  %s (tahmini teslimat)", None)

    lines = [
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Sutore Marketplace\\n"',
        '"Language: tr_TR\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"X-Domain: sutore-marketplace\\n"',
        "",
    ]
    for en, tr in sorted(en_to_tr.items(), key=lambda kv: kv[0]):
        lines.append(f'msgid "{po_escape(en)}"')
        lines.append(f'msgstr "{po_escape(tr)}"')
        lines.append("")

    LANG.mkdir(exist_ok=True)
    po_path = LANG / "sutore-marketplace-tr_TR.po"
    po_path.write_text("\n".join(lines), encoding="utf-8")
    write_mo(list(en_to_tr.items()), LANG / "sutore-marketplace-tr_TR.mo")
    print(f"entries={len(en_to_tr)} -> {po_path}")


if __name__ == "__main__":
    main()
