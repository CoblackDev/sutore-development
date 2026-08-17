<?php

return <<<'HTML'
<div>
<h3>1. Taraflar</h3>

<h4>1.1. Satıcı:</h4>

<p>Adı ve soyadı: [satici-isim]</p>

<p>İkametgâhının bulunduğu il: [satici-il]</p>

<p><strong><u>Satıcı'nın merkezi adresi ve onaylanmış telefon numarası aracı hizmet sağlayıcı olan sutore'de bulunmaktadır.</u></strong></p>

<h4>1.2. Alıcı:</h4>

<p>Adı ve soyadı: <span class="billing-name">[kargo-isim]</span></p>

<p>Adresi: <span class="shipping-address">[kargo-adres]</span></p>

<p>Telefon: <span class="billing-phone">[telefon]</span></p>

<p>E-posta: <span class="billing-email">[eposta]</span></p>

<p><strong><u>sutore Elektronik Hizmetler ve Ticaret Anonim Şirketi, Aracı Hizmet Sağlayıcı Sıfatına istinaden işbu Sözleşmeye hiçbir şekilde taraf olmadığı gibi Alıcı ve Satıcı arasındaki satış ilişkisine dair herhangi bir sorumluluğu da olmayacaktır.</u></strong></p>

<p><strong><u>İşbu Satış ilişkisine dair tahsilat işlemi de sutore Elektronik Hizmetler ve Ticaret Anonim Şirketi'nin anlaşmalı olduğu lisanslı bir ödeme hizmeti sağlayıcısı olan iyzico Teknoloji Ödeme ve Elektronik Para Hizmetleri A.Ş tarafından sağlanacak, kredi kartı hesap ekstresinde tahsilatı yapan kurum olarak bu kurumun ismi görünecektir.</u></strong></p>

<h3>2. Tanımlar</h3>

<p><strong>Sözleşme:</strong> İşbu Mesafeli Satış Sözleşmesi'ni,</p>

<p><strong>Ürün:</strong> Satıcı tarafından, bütün hukuki/cezai sorumluluk Satıcı'ya ait olmak şartıyla, Alıcı'ya satılmakta olan ve ayrıntıları işbu Sözleşme'nin 4. maddesinde belirtilen ürünü veya ürünleri,</p>

<p><strong>Aracı Hizmet Sağlayıcı:</strong> MERSİS numarası 0784069145100001 olan, Maslak Mah. AOS 55. Sk. 42 Maslak B Blok No: 4 İç Kapı No: 542 Sarıyer/İstanbul adresinde bulunan sutore Elektronik Hizmetler ve Ticaret Anonim Şirketi'ni,</p>

<p><strong>Site:</strong> Aracı Hizmet Sağlayıcı'ya ait sutore.com alan adlı internet sitesini,

<p><strong>Üyelik Sözleşmesi:</strong> Alıcı ve Satıcı tarafından Site'ye üyelik aşamasında okunup kabul edilmiş olan <a href="http://sutore.com/uyelik-sozlesmesi/" target="_blank" rel="noopener">sutore Üyelik Sözleşmesi</a>'ni,</p>

<p><strong>Ön Bilgilendirme Formu:</strong> Alıcı tarafından işbu Sözleşme'nin görüntülenmesinden önce okunup onaylanan Ön Bilgilendirme Sözleşmesi'ni ifade etmektedir.</p>

<h3>3. Sözleşmenin Konusu ve Kapsamı</h3>

<p>İşbu Sözleşme'nin konusu, Alıcı'nın Site üzerinden elektronik ortamda siparişini yaptığı aşağıda nitelikleri ve satış fiyatı belirtilen Ürün'ü Satıcı'dan satın alması ve bütün hukuki/cezai sorumluluk Satıcı'ya ait olmak şartıyla Ürün'ün Satıcı tarafından Alıcı'ya teslim edilmesi ile ilgili olarak <a href="https://www.mevzuat.gov.tr/MevzuatMetin/1.5.6502.pdf" target="_blank" rel="noopener">6502 Sayılı Tüketicinin Korunması Hakkında Kanun</a> ve <a href="https://www.resmigazete.gov.tr/eskiler/2014/11/20141127-6.htm" target="_blank" rel="noopener">29188 Nolu Mesafeli Sözleşmeler Yönetmeliği</a> gereğince tarafların karşılıklı hak ve yükümlülüklerinin saptanmasıdır. İşbu sözleşmenin akdedilmesi tarafların akdetmiş oldukları Üyelik Sözleşmesi'nin hükümlerinin ifasını engellemeyecek olup taraflar, söz konusu Hizmet'in sunumunda işbu sözleşmede belirtilen esasların geçerli olacağını kabul ve beyan ederler.</p>

<h3>4. Sözleşme Konusu Malın Temel Nitelikleri ve Ödeme Bilgileri</h3>

<div id="urunListesi" class="tg-wrap">
<table class="tg">
<tr><th class="tg-hgcj">Ürün Adı</th><th class="tg-hgcj">Ürün Fiyatı</th><th class="tg-hgcj">Hizmet Bedeli (KDV Dahil)</th><th class="tg-hgcj">Güvence Bedeli (KDV Dahil)</th><th class="tg-hgcj">Toplam Fiyat</th></tr>
[satici-urunler]
</table>
</div>

<p><strong>Ödeme Şekli ve Planı:</strong> Kredi Kartı</p>

<p><u>Ödeme hizmeti sağlayıcısından kaynaklanan masraflar (vade farkı ve POS kesintisi de dahil) doğrudan Alıcı ve Satıcı'nın sorumluluğundadır ve bu masraflar, sutore tarafından Alıcı ve Satıcı'ya bire bir yansıtılacaktır.</u></p>

<p><strong>Teslimat Adresi:</strong> <span class="shipping-address">[kargo-adres]</span></p>

<p><strong>Teslim Edilecek Kişi:</strong> <span class="billing-name">[kargo-isim]</span></p>

<p>Sözleşme Alıcı tarafından elektronik ortamda onaylanmakla yürürlüğe girmiş olup Alıcı Üye Ödeme ve Üyelik onayı sonrası Satıcı tarafından yapılacak yazılı bildirimle üyelik haklarından faydalanmaya başlayacaktır. Alıcı'nın Satıcı'dan satın almış olduğu Mal, Alıcı'nın sipariş formunda ve işbu Sözleşme'de belirtmiş olduğu adrese ve belirtilen yetkili kişi/kişilere teslim edilmesiyle ifa edilmiş olur. Sözleşme konusu Ürün(ler), ilan sayfasında taahhüt edilen sürede ve her hâlükârda en çok yasal 30 (otuz) gün içinde teslim edilecektir.</p>

<p>Şüpheye mahal vermemek adına, işbu sözleşmeye konu Ürün(lerin) teslimatı için sözleşmenin ve ön bilgilendirme formunun elektronik ortamda teyit edilmiş olması ve satış bedelinin Alıcı'nın tercih ettiği ödeme şekli ile ödenmesi şarttır. Herhangi bir nedenle ürün bedeli ödenmez veya banka kayıtlarında iptal edilirse Satıcı ürünün teslimi yükümlülüğünden kurtulmuş kabul edilir.</p>

<h3>5. Aracı Hizmet Sağlayıcı</h3>

<p>Alıcı; Aracı Hizmet Sağlayıcı'ya ait Site'nin satıcı/sağlayıcı bir internet sitesi olmadığını ve işbu Sözleşme'de taraf olarak yer almadığını; Sözleşme'ye taraf olan satıcının yukarda 1 numaralı maddede bilgileri yazmakta olan Satıcı olduğunu; Ürün, Ürün'ün teslimi ve satış sonrası bedel iadesine ilişkin talep ve şikayetlerinde tek muhatabının Satıcı olduğunu kabul ve beyan eder.</p>

<p>Satıcı; Ürün'ün tedarik edilmesi, Alıcı'ya teslim edilmesi ve bunlarla sınırlı olmamak üzere Sözleşme ile belirtilen bütün hususlarda Alıcı karşısında münhasıran sorumlu olduğunu; Aracı Hizmet Sağlayıcı'nın Ürün'e, Ürün'ün teslimine ve satış sonrası bedel iadesine ilişkin hiçbir sorumluluğunun bulunmadığını; Aracı Hizmet Sağlayıcı ile arasında herhangi bir ortaklık ilişkisi bulunmadığını kabul, beyan ve taahhüt eder.</p>

<p><strong>Ancak sutore, Üye menfaatlerini korumak maksadıyla Orijinallik ve Kalite Kontrol Merkezi'nde uzmanlar tarafından gerektiğinde yapay zeka destekli olarak yapacağı incelemeler sonrasında ürünün orijinal olduğunu ve ilanda belirtildiği gibi teslim edileceğini hizmet ve güvence bedeli karşılığı olarak taahhüt eder.</strong></p>

<p>Alıcı ve Satıcı; Aracı Hizmet Sağlayıcı'nın Site üzerinde yayınlanan/ilan edilen Ürün ile ilgili olarak satıcı, tedarikçi, imalatçı-ithalatçı, bayi veya acente sıfatının bulunmadığını; Ürün'ün Alıcı'ya tedariki/teslimi ile ilgili her türlü eylemin Satıcı tarafından gerçekleştirileceğini; Alıcı'nın Ürün ile ilgili her türlü talebinin muhatabının Satıcı olduğunu; Alıcı'nın cayma hakkını Satıcı'ya karşı kullanacağını; Ürün'ün ayıplı olup olmaması, yasaklı ürünlerden olup olmaması, kaçak olup olmaması, niteliği de dahil ancak bunlarla sınırlı olmamak üzere, Ürün ile ilgili hiçbir konu hakkında Aracı Hizmet Sağlayıcı'nın bilgi sahibi olmadığını/olması gerekmediğini ve bunları taahhüt ve garanti etmek yükümlülüğü bulunmadığını; Ürün'e ilişkin faturanın Satıcı tarafından kesileceğini; bu nedenlerle Aracı Hizmet Sağlayıcı'nın <a href="https://www.mevzuat.gov.tr/MevzuatMetin/1.5.6563.pdf" target="_blank" rel="noopener">6563 sayılı Elektronik Ticaretin Düzenlenmesi Hakkında Kanun</a>, <a href="https://www.mevzuat.gov.tr/MevzuatMetin/1.5.6502.pdf" target="_blank" rel="noopener">6502 sayılı Tüketicinin Korunması Hakkındaki Kanun</a>, <a href="https://www.resmigazete.gov.tr/eskiler/2015/08/20150826-10.htm" target="_blank" rel="noopener">Elektronik Ticarette Hizmet Sağlayıcı ve Aracı Hizmet Sağlayıcılar Hakkında Yönetmelik</a> ve <a href="https://www.resmigazete.gov.tr/eskiler/2014/11/20141127-6.htm" target="_blank" rel="noopener">Mesafeli Sözleşmeler Yönetmeliği</a> hükümleri dahil fakat bunlarla sınırlı olmaksızın hiçbir yasal düzenleme uyarınca herhangi bir hukuki/cezai sorumluluğunun bulunmadığını kabul, beyan ve taahhüt ederler.</p>

<p>Ürün ve Ürün'e yönelik her türlü dava, şikâyet ve talepler karşısında Satıcı tek başına sorumlu olacaktır.</p>

<p><strong>Aracı Hizmet Sağlayıcı sadece işbu Sözleşme'ye konu satışın gerçekleşmesi için elektronik ticaret ortamı sunmakta ve Alıcı ile Satıcı arasındaki güvenli tahsilat sürecini yönetmekte olup Ürün'ün satışını gerçekleştirmemekte, Alıcı'ya Ürün'e yönelik Hizmet ve Güvence bedeli karşılığı sunacağı hizmetler dışında hiçbir garanti veya taahhütte bulunmamaktadır.</strong></p>

<p><strong>ALICI; ÜRÜN'Ü ARACI HİZMET SAĞLAYICI'DAN SATIN ALMADIĞINI, BU SEBEPLE ÜRÜN'ÜN AYIPLI ÇIKMASINDAN VEYA CAYMA HAKKINDAN KAYNAKLANAN BEDEL İADESİ TALEPLERİ DAHİL OLMAK ÜZERE ÜRÜN İLE İLGİLİ HER TÜRLÜ TALEBİNİ SATICI'YA YÖNELTECEĞİNİ BİLDİĞİNİ KABUL VE BEYAN EDER.</strong></p>

<h3>6. Alıcının Beyan ve Taahhütleri</h3>

<p>Alıcı; sutore.com'da yer alan sözleşme konusu ürünün/hizmetin temel nitelikleri, satış fiyatı ve ödeme şekli ile teslimat ve kargo bedeline ilişkin olarak Satıcı tarafından yüklenen ön bilgileri okuyup bilgi sahibi olduğunu ve elektronik ortamda gerekli teyidi verdiğini ve verdiği siparişin ödeme yükümlülüğü anlamına geldiğinin bilincinde olduğunu kabul ve beyan eder.</p>

<p>Alıcılar, Tüketici sıfatıyla talep ve şikayetlerini yukarıda yer alan Satıcı iletişim bilgilerine ve/veya sutore.com'un sağladığı kanallarla ulaştırabilirler. Alıcı; işbu Sözleşme'yi ve Ön Bilgilendirme Formunu elektronik ortamda teyit etmekle mesafeli sözleşmelerin akdinden önce Satıcı tarafından tüketiciye verilmesi gereken adres, siparişi verilen ürünlere/hizmetlere ait temel özellikler, ürünlerin vergiler dahil fiyatı, ödeme ve teslimat ve teslimat fiyatı bilgilerini de doğru ve eksiksiz olarak edindiğini teyit etmiş olur.</p>

<p>Mal/Hizmet'in tesliminden sonra Alıcı'ya ait kredi kartının Alıcı'nın kusurundan kaynaklanmayan bir şekilde yetkisiz kişilerce haksız veya hukuka aykırı olarak kullanılması nedeni ile ilgili banka veya finans kuruluşunun Mal/Hizmet bedelini Satıcı'ya ödememesi halinde, Alıcı kendisine teslim edilmiş olması kaydıyla Mal/Hizmet'i 3 (üç) gün içinde Satıcı'ya iade etmekle yükümlüdür.</p>

<h3>7. Satıcının Beyan ve Taahhütleri</h3>

<p>Satıcı; Sözleşme konusu Mal/Hizmet'in Tüketici Mevzuatına uygun olarak sağlam, eksiksiz, siparişte belirtilen niteliklere uygun Alıcı'ya teslim edilmesinden sorumludur. Satıcı, mücbir sebepler veya nakliyeyi engelleyen olağanüstü durumlar nedeni ile sözleşme konusu ürünü süresi içinde teslim edemez ise durumu öğrendiği tarihten itibaren 3 (üç) gün içinde Alıcı'ya bildirmekle yükümlüdür. Sözleşme konusu Mal/Hizmet, Alıcı'dan başka bir kişiye teslim edilecek ise teslim edilecek kişinin teslimatı kabul etmemesinden Satıcı sorumlu tutulamaz.</p>

<h3>8. Cayma Hakkı ve İade</h3>

<p><strong><u>Cayma Hakkının uygulanması yalnızca taraflardan birinin ticari faaliyette bulunmak amacıyla hareket ettiği durumlarda geçerli olacak olup bu sıfata sahip olmayan iki gerçek kişi arasında gerçekleşen işlemlerde Tüketici Mevzuatı ve ona bağlı cayma hakkı/iade hükümleri uygulanmayacaktır.</u></strong></p>

<p><strong><u>Alıcı; sutore.com üzerinde tacir, esnaf, tedarikçi veya ticari satıcı olmayan sadece kendisine ait bir ürünü mesleki/ticari amaç gütmeden satan satıcılar olduğunu; mesleki/ticari amaçla hareket etmeyen satıcıların/sağlayıcıların</u></strong><strong> <u><a href="https://www.mevzuat.gov.tr/MevzuatMetin/1.5.6502.pdf">6502 sayılı Tüketicinin Korunması Hakkındaki Kanun</a></u> <u>kapsamına girmediklerini ve bu sebeple söz konusu satıcılar karşısında cayma hakkını kullanamayacağını kabul, beyan ve taahhüt eder.</u></strong></p>

<p>Alıcı (Üye) malın ya da hizmetin kendisine tesliminden itibaren, hiçbir gerekçe göstermeksizin 14 (ondört) gün içinde cayma hakkını kullanarak malı/hizmeti iade edebilir. Alıcı Üye, cayma hakkı kullanım bildirimini sutore.com üzerinden belirtilen şekilde Satıcı'ya ulaştırır. Satıcı, Alıcı'nın cayma beyanının kendisine ulaşmasından itibaren 14 (ondört) gün içinde Mal/Hizmet bedelini iade etmekle yükümlüdür. Hizmetin iade alınamaması için haklı sebeplerin varlığı halinde Satıcı, sözleşmedeki ifa süresi dolmadan Alıcı'ya eşit kalite ve fiyatta Mal/Hizmet tedarik edebilir. Satıcı mal/hizmetin ifasının imkânsızlaştığını düşünüyorsa bu durumu Sözleşme'nin ifa süresi dolmadan Alıcı'ya bildirir. Bu durumda Satıcı, ödenen bedeli ve varsa belgeleri 14 (ondört) gün içinde Alıcı'ya iade eder. Cayma hakkı kapsamında iade edilmek istenen ürün, işbu Formda belirtilmesi halinde anlaşmalı kargo şirketince gönderilmemesi halinde iade kargo bedelinden Alıcı sorumlu olacaktır.</p>

<p>Cayma hakkı aşağıdaki hallerde kullanılamaz: a) Fiyatı finansal piyasalardaki dalgalanmalara bağlı olarak değişen ve satıcının kontrolünde olmayan mal veya hizmetlere ilişkin sözleşmelerde (Ziynet, altın ve gümüş kategorisindeki ürünler) b) Tüketicinin istekleri veya açıkça onun kişisel ihtiyaçları doğrultusunda hazırlanan, niteliği itibariyle geri gönderilmeye elverişli olmayan ve çabuk bozulma tehlikesi olan veya son kullanma tarihi geçme ihtimali olan malların teslimine ilişkin sözleşmelerde c) Tesliminden sonra ambalaj, bant, mühür, paket gibi koruyucu unsurları açılmış olan mallardan; iadesi sağlık ve hijyen açısından uygun olmayanların teslimine ilişkin sözleşmelerde d) Tesliminden sonra başka ürünlerle karışan ve doğası gereği ayrıştırılması mümkün olmayan mallara ilişkin sözleşmelerde e)Tüketici tarafından ambalaj, bant, mühür, paket gibi koruruyucu unsurları açılmış olması şartıyla maddi ortamda sunulan kitap, ses veya görüntü kayıtlarına, yazılım programlarına ve bilgisayar sarf malzemelerine ilişkin sözleşmelerde f) Abonelik sözleşmesi kapsamında sağlananlar dışında gazete, dergi gibi süreli yayınların teslimine ilişkin sözleşmelerde g) Belirli bir tarihte veya dönemde yapılması gereken, konaklama, eşya taşıma, araba kiralama, yiyecek-içecek tedariki ve eğlence veya dinlenme amacıyla yapılan boş zamanın değerlendirilmesine ilişkin sözleşmelerde h) Bahis ve piyangoya ilişkin hizmetlerin ifasına ilişkin sözleşmelerde ı) Cayma hakkı süresi sona ermeden önce, tüketicinin onayı ile ifasına başlanan hizmetlere ilişkin sözleşmelerde i) Elektronik ortamda anında ifa edilen hizmetler ile tüketiciye anında teslim edilen gayri maddi mallara ilişkin sözleşmelerde ve sözleşmeye konu Mal/Hizmet'in Mesafeli Sözleşmeler Yönetmeliği'nin uygulama alanı dışında bırakılmış olan (satıcının düzenli teslimatları ile alıcının meskenine teslim edilen gıda maddelerinin, içeceklerin ya da diğer günlük tüketim maddeleri ile seyahat, konaklama, lokantacılık, eğlence sektörü gibi alanlarda hizmetler) Mal/Hizmet türlerinden müteşekkil olması halinde Alıcı ve Satıcı arasındaki hukuki ilişkiye Mesafeli Sözleşmeler Yönetmeliği hükümleri uygulanamaması sebebiyle cayma hakkı kullanılamayacaktır. Tatil kategorisinde satışa sunulan bu tür Mal/Hizmetlerin iptal ve iade şartları Satıcı uygulama ve kurallarına tabidir.</p>

<h3>9. Uyuşmazlıkların Çözümü</h3>

<p><a href="https://www.mevzuat.gov.tr/MevzuatMetin/1.5.6502.pdf">6502 sayılı Tüketicin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği</a> kapsamında satılan mal veya hizmete ilişkin sorumluluk bizzat satıcıya aittir. Bununla birlikte Alıcı, satın alınan mal ve hizmetlerle ilgili şikayetlerini Aracı Hizmet Sağlayıcı konumundaki sutore Elektronik Hizmetler ve Ticaret Anonim Şirketi'ne doğrudan veya sutore.com üzerinden iletmesi halinde, Şirket sorunun çözülmesi için mümkün olan tüm desteği sağlayacaktır.</p>

<p>İşbu Sözleşmenin uygulanmasından kaynaklanacak ihtilaflarda; Gümrük ve Ticaret Bakanlığı'nca ilan edilen değere kadar Alıcı'nın malı satın aldığı veya ikametgahının bulunduğu yerdeki Tüketici Sorunları Hakem Heyetleri, söz konusu değeri aşan ihtilaflar ile ilgili olarak ise Alıcı'nın veya Satıcı'nın ikametgahının bulunduğu yerdeki Tüketici Mahkemeleri yetkilidir.</p>

<h3>10. Temerrüt Hali ve Hukuki Sonuçları</h3>

<p>Alıcı'nın kredi kartı ile yapmış olduğu işlemlerde temerrüde düşmesi halinde kart sahibi bankanın kendisi ile yapmış olduğu kredi kartı sözleşmesi çerçevesinde faiz ödeyecek ve bankaya karşı sorumlu olacaktır. Bu durumda ilgili banka hukuki yollara başvurabilir; doğacak masrafları ve vekâlet ücretini Alıcı'dan talep edebilir ve her koşulda Alıcı'nın borcundan dolayı temerrüde düşmesi halinde, Alıcı'nın borcu gecikmeli ifasından dolayı Satıcı'nın uğradığı zarar ve ziyandan Alıcı sorumlu olacaktır.</p>

<h3>11. Bildirimler ve Delil Sözleşmesi</h3>

<p>İşbu Sözleşme tahtında Taraflar arasında yapılacak her türlü yazışma, mevzuatta sayılan zorunlu haller dışında, e-mail aracılığıyla yapılacaktır. Alıcı, işbu Sözleşme'den doğabilecek ihtilaflarda sutore Elektronik Hizmetler ve Ticaret Anonim Şirketi'nin resmî defter ve ticari kayıtlarıyla kendi veri tabanında, sunucularında tuttuğu elektronik bilgilerin ve bilgisayar kayıtlarının bağlayıcı, kesin ve münhasır delil teşkil edeceğini; bu maddenin <a href="https://www.mevzuat.gov.tr/MevzuatMetin/1.5.6100.pdf">6100 Sayılı Hukuk Muhakemeleri Kanunu</a>'nun 193. maddesi anlamında delil sözleşmesi niteliğinde olduğunu kabul, beyan ve taahhüt eder.</p>

<p><strong>11 (onbir) maddeden ibaret bu Sözleşme, Taraflarca okunarak Alıcı tarafından elektronik ortamda onaylanmak suretiyle akdedilmiş ve derhal yürürlüğe girmiştir.</strong></p>
</div>

HTML;
