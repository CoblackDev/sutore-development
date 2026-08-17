<?php

return <<<'HTML'
<div>
<p align="justify"><strong>İşbu Ön Bilgilendirme Formu, aşağıda bilgileri verilen Satıcı ile akdedilecek Mesafeli Satış Sözleşmesi'nin kurulmasından önce<a href="https://www.mevzuat.gov.tr/MevzuatMetin/1.5.6502.pdf" target="_blank" rel="noopener"> 6502 Sayılı Tüketicinin Korunması Hakkında Kanun</a> ve <a href="https://www.resmigazete.gov.tr/eskiler/2014/11/20141127-6.htm" target="_blank" rel="noopener">Mesafeli Sözleşmeler Yönetmeliği</a> kapsamında satıcı tarafından yapılması gereken bilgilendirmeyi içermektedir.</strong></p>
<p>İşbu Ön Bilgilendirme Formu, aşağıda bilgileri verilen Satıcı ile akdedilecek Mesafeli Satış Sözleşmesi'nin eki ve ayrılmaz bir parçasıdır.</p>
<h3>1. Satıcı Bilgileri</h3>
<p>Adı ve soyadı: [satici-isim]<p>
<p>İkametgâhının bulunduğu il: [satici-il]<p>
<p><u>Satıcı'nın merkezi adresi ve onaylanmış telefon numarası aracı hizmet sağlayıcı olan sutore'de bulunmaktadır.</u></p>
<h3>2. Sözleşme Konusu Malın Temel Nitelikleri ve Ödeme Bilgileri</h3>
<div id="urunListesi" class="tg-wrap">
<table class="tg">
<tr><th class="tg-hgcj">Ürün Adı</th><th class="tg-hgcj">Ürün Fiyatı</th><th class="tg-hgcj">Hizmet Bedeli (KDV Dahil)</th><th class="tg-hgcj">Güvence Bedeli (KDV Dahil)</th><th class="tg-hgcj">Toplam Fiyat</th></tr>
[satici-urunler]
</table>
</div>

<p><strong>Ödeme Şekli ve Planı: </strong>Kredi Kartı</p>

<p><strong>Teslimat Adresi: </strong><span class="shipping-address">[kargo-adres]</span></p>

<p><strong>Teslim Edilecek Kişi: </strong><span class="billing-name">[kargo-isim]</span></p>
<p>Sözleşme Alıcı tarafından elektronik ortamda onaylanmakla yürürlüğe girmiş olup Alıcı Üye Ödeme ve Üyelik onayı sonrası Satıcı tarafından yapılacak yazılı bildirimle üyelik haklarından faydalanmaya başlayacaktır. Alıcı'nın Satıcı'dan satın almış olduğu Mal, Alıcı'nın sipariş formunda ve işbu Sözleşme'de belirtmiş olduğu adrese ve belirtilen yetkili kişi/kişilere teslim edilmesiyle ifa edilmiş olur. Sözleşme konusu Ürün(ler), ilan sayfasında taahhüt edilen sürede ve her hâlükârda en çok yasal 30 (otuz) gün içinde teslim edilecektir.</p>

<h3>3. Cayma Hakkı ve İade</h3>
<p><strong><u>Cayma Hakkının uygulanması yalnızca taraflardan birinin ticari faaliyette bulunmak amacıyla hareket ettiği durumlarda geçerli olacak olup bu sıfata sahip olmayan iki gerçek kişi arasında gerçekleşen işlemlerde Tüketici Mevzuatı ve ona bağlı cayma hakkı/iade hükümleri uygulanmayacaktır.</u></strong></p>

<p><strong><u>Alıcı; sutore.com üzerinde tacir, esnaf, tedarikçi veya ticari satıcı olmayan sadece kendisine ait bir ürünü mesleki/ticari amaç gütmeden satan satıcılar olduğunu; mesleki/ticari amaçla hareket etmeyen satıcıların/sağlayıcıların</u></strong><strong> <u><a href="https://www.mevzuat.gov.tr/MevzuatMetin/1.5.6502.pdf">6502 sayılı Tüketicinin Korunması Hakkındaki Kanun</a></u> <u>kapsamına girmediklerini ve bu sebeple söz konusu satıcılar karşısında cayma hakkını kullanamayacağını kabul, beyan ve taahhüt eder.</u></strong></p>

<p>Alıcı (Üye) malın ya da hizmetin kendisine tesliminden itibaren, hiçbir gerekçe göstermeksizin 14 (ondört) gün içinde cayma hakkını kullanarak malı/hizmeti iade edebilir. Alıcı Üye, cayma hakkı kullanım bildirimini sutore.com üzerinden belirtilen şekilde Satıcı'ya ulaştırır. Satıcı, Alıcı'nın cayma beyanının kendisine ulaşmasından itibaren 14 (ondört) gün içinde Mal/Hizmet bedelini iade etmekle yükümlüdür. Hizmetin iade alınamaması için haklı sebeplerin varlığı halinde Satıcı, sözleşmedeki ifa süresi dolmadan Alıcı'ya eşit kalite ve fiyatta Mal/Hizmet tedarik edebilir. Satıcı mal/hizmetin ifasının imkânsızlaştığını düşünüyorsa bu durumu Sözleşme'nin ifa süresi dolmadan Alıcı'ya bildirir. Bu durumda Satıcı, ödenen bedeli ve varsa belgeleri 14 (ondört) gün içinde Alıcı'ya iade eder. Cayma hakkı kapsamında iade edilmek istenen ürün, işbu Formda belirtilmesi halinde anlaşmalı kargo şirketince gönderilmemesi halinde iade kargo bedelinden Alıcı sorumlu olacaktır.</p>

<p>Cayma hakkı aşağıdaki hallerde kullanılamaz: a) Fiyatı finansal piyasalardaki dalgalanmalara bağlı olarak değişen ve satıcının kontrolünde olmayan mal veya hizmetlere ilişkin sözleşmelerde (Ziynet, altın ve gümüş kategorisindeki ürünler) b) Tüketicinin istekleri veya açıkça onun kişisel ihtiyaçları doğrultusunda hazırlanan, niteliği itibariyle geri gönderilmeye elverişli olmayan ve çabuk bozulma tehlikesi olan veya son kullanma tarihi geçme ihtimali olan malların teslimine ilişkin sözleşmelerde c) Tesliminden sonra ambalaj, bant, mühür, paket gibi koruyucu unsurları açılmış olan mallardan; iadesi sağlık ve hijyen açısından uygun olmayanların teslimine ilişkin sözleşmelerde d) Tesliminden sonra başka ürünlerle karışan ve doğası gereği ayrıştırılması mümkün olmayan mallara ilişkin sözleşmelerde e)Tüketici tarafından ambalaj, bant, mühür, paket gibi koruruyucu unsurları açılmış olması şartıyla maddi ortamda sunulan kitap, ses veya görüntü kayıtlarına, yazılım programlarına ve bilgisayar sarf malzemelerine ilişkin sözleşmelerde f) Abonelik sözleşmesi kapsamında sağlananlar dışında gazete, dergi gibi süreli yayınların teslimine ilişkin sözleşmelerde g) Belirli bir tarihte veya dönemde yapılması gereken, konaklama, eşya taşıma, araba kiralama, yiyecek-içecek tedariki ve eğlence veya dinlenme amacıyla yapılan boş zamanın değerlendirilmesine ilişkin sözleşmelerde h) Bahis ve piyangoya ilişkin hizmetlerin ifasına ilişkin sözleşmelerde ı) Cayma hakkı süresi sona ermeden önce, tüketicinin onayı ile ifasına başlanan hizmetlere ilişkin sözleşmelerde i) Elektronik ortamda anında ifa edilen hizmetler ile tüketiciye anında teslim edilen gayri maddi mallara ilişkin sözleşmelerde ve sözleşmeye konu Mal/Hizmet'in Mesafeli Sözleşmeler Yönetmeliği'nin uygulama alanı dışında bırakılmış olan (satıcının düzenli teslimatları ile alıcının meskenine teslim edilen gıda maddelerinin, içeceklerin ya da diğer günlük tüketim maddeleri ile seyahat, konaklama, lokantacılık, eğlence sektörü gibi alanlarda hizmetler) Mal/Hizmet türlerinden müteşekkil olması halinde Alıcı ve Satıcı arasındaki hukuki ilişkiye Mesafeli Sözleşmeler Yönetmeliği hükümleri uygulanamaması sebebiyle cayma hakkı kullanılamayacaktır. Tatil kategorisinde satışa sunulan bu tür Mal/Hizmetlerin iptal ve iade şartları Satıcı uygulama ve kurallarına tabidir.</p>

<h3>4. Uyuşmazlıkların Çözümü</h3>

<p><a href="https://www.mevzuat.gov.tr/MevzuatMetin/1.5.6502.pdf">6502 sayılı Tüketicin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği</a> kapsamında satılan mal veya hizmete ilişkin sorumluluk bizzat satıcıya aittir. Bununla birlikte Alıcı, satın alınan mal ve hizmetlerle ilgili şikayetlerini Aracı Hizmet Sağlayıcı konumundaki sutore Elektronik Hizmetler ve Ticaret Anonim Şirketi'ne doğrudan veya sutore.com üzerinden iletmesi halinde, Şirket sorunun çözülmesi için mümkün olan tüm desteği sağlayacaktır.</p>

<p>İşbu Sözleşmenin uygulanmasından kaynaklanacak ihtilaflarda; Gümrük ve Ticaret Bakanlığı'nca ilan edilen değere kadar Alıcı'nın malı satın aldığı veya ikametgahının bulunduğu yerdeki Tüketici Sorunları Hakem Heyetleri, söz konusu değeri aşan ihtilaflar ile ilgili olarak ise Alıcı'nın veya Satıcı'nın ikametgahının bulunduğu yerdeki Tüketici Mahkemeleri yetkilidir.</p>

</div>

HTML;
