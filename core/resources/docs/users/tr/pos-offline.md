---
title: İnternet kesildiğinde satış yapma (çevrimdışı POS)
audience: owner
group: Taking orders
updated: 2026-10-12
---

# İnternet kesildiğinde satış yapma (çevrimdışı POS)

Kasanız, internet veya sunucuya ulaşılamadığında sınırlı bir süre satış yapmaya devam eder ve bağlantı geri geldiğinde
her şeyi panelinize gönderir. Bu sayfa nelerin hâlâ çalıştığını, nelerin çalışmadığını ve sınırları açıklar.

## Çevrimdışıyken neler çalışır

- **Satışları kasa başına işleme** kasanızın en son indirdiği menüden: ürünler, seçenekler, varyantlar, vergiler,
  servis ücreti ve personelinizin verebileceği indirimler.
- **Nakit ve manuel ödemeler** (örneğin kendinizin onayladığı bir banka havalesi), para üstü kasada hesaplanır.
- **Fişler** kasadan yazdırılır; ülkenizin gerektirdiği yerlerde QR kodu dahil.
- **İptal etme** bu kasada, bu vardiyada yapılan ve henüz eşitlenmemiş bir satışı (mağazanız istiyorsa yönetici PIN'i ile).
- **Çekmeceyi açma ve kapatma**, para girişleri ve çıkışları ile bu kasanın vardiya özeti.
- **Kart ödemeleri** daha sonrası için kasada saklanır: yalnızca kart okuyucu uygulaması olan bir kasada, yalnızca
  aşağıdaki kart sınırları içinde ve yalnızca ödeme sağlayıcınız bu özelliği mağazanız için onayladıktan sonra. O zamana
  kadar çevrimdışı kart ödemeleri kapalıdır ve kasa başka bir ödeme yolu ister.

Bağlantı geri geldiğinde kasa her satışı, ödemeyi ve iptali sırayla ve birer kez panelinize gönderir. Kasa yeniden başlasa
veya bağlantı ortasında kesilse bile bir satış asla iki kez oluşturulmaz.

## Süre sınırları

| Sınır | Varsayılan | Azami |
|---|---|---|
| Bir kasanın sunucuya bağlanmadan satış yapabileceği süre | 12 saat | 24 saat |
| Gönderilemeyen bir satışın kasada saklandığı süre | 7 gün | 7 gün |

- Çevrimdışı süre tükendikten sonra kasa **yeni** satışları reddeder ve "devam etmek için bağlanın" gösterir. Elindekileri
  yine de gönderir ve fişleri yine yazdırır. Saatler, kasanın sunucuya en son ulaştığı andan itibaren sayılır.
- Bir satış 7 gün sonra hâlâ bekliyorsa kasa yeni satışları durdurur ve bir yönetici uyarısı gösterir. Gönderilmeden önce
  hiçbir şey silinmez.
- Mağaza sahipleri POS ayarlarından daha kısa bir çevrimdışı süre seçebilir; platformun azamisi 24 saattir.

## Kart sınırları

Çevrimdışı kart ödemeleri, sağlayıcı bunları işleyene kadar kart okuyucu uygulaması tarafından kasada tutulur. Kasa bu
sınırları kart okunmadan **önce** kontrol eder:

| Sınır | Değer |
|---|---|
| Tek bir kart ödemesi | en fazla SAR 250 |
| Henüz gönderilmemiş tüm kart ödemeleri, kasa başına | en fazla SAR 2,500 |

Mağazanız için daha düşük sınırlar belirleyebilirsiniz, daha yüksek değil. Bir sınırın üzerinde kasa "bağlantı gerekiyor"
der ve başka bir ödeme yolu seçersiniz.

**Riski mağaza taşır.** Çevrimdışı bir kart ödemesi satış anında kontrol edilemez. Kart daha sonra reddedilirse satış
kayıtlarınızda kalır ve o siparişte, vardiya raporunda ve **Çevrimdışı istisnalar** listesinde **çevrimdışı satıştan sonra
kart reddedildi** olarak gösterilir; tutar, saat, personel ve kasa ile birlikte. Juwri müşteriye sizin adınıza yeniden ücret
yansıtmaz. Parayı başka bir yolla (nakit, havale) tahsil ederseniz bir yönetici bunu "başka yoldan tahsil edildi" olarak
işaretler; bu, düzeltici bir ödeme ekler ve reddedileni asla silmez. Kasa ve Juwri hiçbir zaman kart numaralarını saklamaz.

## Tarayıcı kasa yalnızca nakit

Bir web tarayıcısında çalışan kasa (kart okuyucu uygulaması değil) çevrimdışıyken **yalnızca nakit ve manuel ödemelerle**
satış yapabilir. Kart okuyucu uygulaması yoktur, bu yüzden orada çevrimdışı kart ödemeleri kullanılamaz.

Bir tarayıcı, cihazın depolama alanı azaldığında veya tarayıcı sıfırlandığında kendi sakladığı verileri de temizleyebilir.
Satışlarınızı korumak için kasa tarayıcıdan verilerini saklamasını ister ve bununla ilgili bir uyarı gösterir. Bir tarayıcı
kasası uzun süre çevrimdışı kalabilecekse bağlantı geri gelir gelmez satışlarını gönderin ve yoğun şubeler için tablet
uygulamasını tercih edin.

## Personeliniz ne görecek

- Bekleyen satış sayısını ve kalan çevrimdışı süreyi gösteren "Çevrimdışı" başlıklı bir şerit.
- Fiyatlandırma, vergi, yuvarlama ve fişler çevrimiçiyken olduğu gibi çalışır. Kasa çevrimdışıyken panelde bir fiyat
  değiştirilmişse satış, müşterinin gördüğü ve ödediği fiyatı korur.
- Eşitlemeden sonra vardiya için kısa bir **Çevrimdışı istisnalar** listesi, bir yöneticinin bakması gereken her şeyi
  gösterir.

## İnternet gerektiren şeyler (istisnalar)

Bunlar çevrimdışıyken çalışmaz. Denediğinizde kasa bunu size söyler:

- Çevrimiçi kart ödemeleri ve ödeme bağlantıları, cüzdan veya saklanan bakiye ödemeleri, hediye kartları (satma veya
  kullanma), bağlılık puanı kullanımı (satış eşitlendiğinde puanlar yine kazanılır), sunucu gerektiren kuponlar (tek
  kullanımlık veya müşteriye özel kodlar).
- Bu vardiyada bu kasada yapılmayan satışların iadeleri, her türlü kart iadesi ve gönderildikten sonra bir kart ödemesinin
  iptali.
- Müşteri arama (bir satışta yazılan ad ve telefon sorun değil) ve müşteri hesapları oluşturma.
- Gelen çevrimiçi ve teslimat uygulaması siparişleri, kurye gönderimi, diğer cihazlardaki mutfak ekranı, bir masayı başka
  bir kasa ile taşıma veya birleştirme, rezervasyonlar ve bekleme listesi.
- Fiyatları, ürünleri, vergileri, personeli veya ayarları değiştirme, hiç bağlanmamış bir kasayı açma ve en son indirilen
  listede olmayan bir personeli oturum açtırma.
- Bu kasanın vardiya özetinin ötesindeki raporlar (gün sonu raporu ancak satışlar eşitlendikten sonra kesinleşir).
- Devlet onayı gerektiren vergi faturaları (örneğin Suudi Arabistan'da işletmeden işletmeye faturalar). Basitleştirilmiş
  müşteri fişleri kasanın kendisinde imzalanır.
- Çevrimdışı süre veya 7 günlük sınır dolduktan sonra satış yapma.

## İki şey farklı olduğunda

- **Stok:** Kasa çevrimdışıyken bir ürünü sıfır stok altında satabilir. Satış eşitlendiğinde kabul edilir ve ürün sizin
  için bir **fazla satış** raporuna girer. "Asla fazla satma" olarak işaretlenen ürünler kasada sıfırda bloke edilir.
- **Aynı masa iki kasada:** İki satış da ayrı siparişler olarak tutulur ve işaretlenir.
- **Bu kasa çevrimdışıyken başka bir cihazdan kapatılan bir vardiya:** Geç kalan satışlar doğru vardiyaya eklenir ve
  raporu "geç satışlar" bölümüyle yeniden düzenlenir.

## Bir kasa kaybolur veya çalınırsa

Juwri hesabınızı yöneten kişiden kasanın **yetkisini iptal etmesini** isteyin. İnternete ulaştığı ilk seferde,
gönderebildiği tüm satışları gönderdikten sonra elinde tuttuğu her şeyi siler. İnternete hiç ulaşamazsa, yetkisi iptal
edildikten 3 gün sonra bir sonraki başlangıcında verilerini kendi kendine siler. Kasada saklanan her şey şifrelidir ve
anahtar cihazdan hiç ayrılmaz.

## İlgili sayfalar

- [Satış noktası (POS)](../pos.md) — kasa, ödemeler ve çekmece.
- [Personel hesapları](../staff.md) — roller, PIN'ler ve kişi başına izinler.
- [Siparişleri yönetme](../orders.md) — eşitlenen çevrimdışı satışların göründüğü yer.
