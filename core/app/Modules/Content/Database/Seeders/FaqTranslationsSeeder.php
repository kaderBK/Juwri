<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Database\Seeders;

use Juwri\Modules\Content\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * Translates the six canonical marketing FAQs into every non-English language.
 *
 * English stays as the base rows (translation_lang = 'en' OR NULL); Faq::localized()
 * serves a locale its own set when present, else falls back to English — so this
 * seeder only ADDS per-language sets and never duplicates or blanks anything.
 *
 * Idempotent + non-destructive: each row is upserted by (translation_lang,
 * faq_title), so re-running refreshes the content in place and owner-added FAQs
 * (other titles, other langs, the English base) are never touched.
 *
 * Run on production:  php artisan db:seed --class=FaqTranslationsSeeder
 * Not wired into DatabaseSeeder — it is an explicit, one-command content import.
 *
 * If the English source FAQs are edited, update the array below and re-run.
 */
class FaqTranslationsSeeder extends Seeder
{
    /**
     * Canonical English order (anchors the six entries per language, 0..5):
     *  0 How does the QR menu work?
     *  1 Do my customers need to install an app?
     *  2 Can I take orders and payments from the menu?
     *  3 How long does setup take?
     *  4 Can I offer my menu in more than one language?
     *  5 Is there a free plan?
     */
    public function run(): void
    {
        foreach ($this->translations() as $lang => $items) {
            foreach ($items as [$title, $content]) {
                Faq::updateOrCreate(
                    ['translation_lang' => $lang, 'faq_title' => $title],
                    ['faq_content' => $content, 'active' => 1],
                );
            }
        }
    }

    /**
     * @return array<string, array<int, array{0:string,1:string}>>
     */
    private function translations(): array
    {
        return [
            'ar' => [
                ['كيف تعمل قائمة الـ QR؟', 'كل طاولة تحصل على رمز QR خاص بها. يمسحه الضيوف بكاميرا هواتفهم فتُفتح قائمتك بعلامتك التجارية فورًا في المتصفح — دون أي تطبيق للتنزيل.'],
                ['هل يحتاج عملائي إلى تثبيت تطبيق؟', 'لا. تُفتح القائمة في أي متصفح هاتف بمجرد مسح رمز الـ QR.'],
                ['هل يمكنني استقبال الطلبات والمدفوعات من القائمة؟', 'نعم. يطلب الضيوف مباشرة من القائمة ويدفعون نقدًا أو بالبطاقة أو عبر الإنترنت. تحتفظ بكل قرش — دون أي عمولة على أي طلب.'],
                ['كم يستغرق الإعداد؟', 'معظم المطاعم تنطلق في اليوم نفسه. أضف أصنافك وأسعارك وصورك (أو استورد ملف CSV)، اختر قالبًا، وانشر.'],
                ['هل يمكنني تقديم قائمتي بأكثر من لغة؟', 'نعم. ترجم قائمتك بالكامل إلى عدة لغات بنقرة واحدة، ليقرأها كل ضيف بلغته.'],
                ['هل توجد خطة مجانية؟', 'نعم — ابدأ مجانًا دون بطاقة ائتمان، وترقَّ متى احتجت المزيد.'],
            ],
            'bg' => [
                ['Как работи QR менюто?', 'Всяка маса получава свой QR код. Гостите го сканират с камерата на телефона си и вашето брандирано меню се отваря веднага в браузъра — без приложение за изтегляне.'],
                ['Трябва ли клиентите ми да инсталират приложение?', 'Не. Менюто се отваря във всеки телефонен браузър в момента, в който сканират QR кода.'],
                ['Мога ли да приемам поръчки и плащания от менюто?', 'Да. Гостите поръчват директно от менюто и плащат в брой, с карта или онлайн. Запазвате всяка стотинка — без комисиона за нито една поръчка.'],
                ['Колко време отнема настройката?', 'Повечето ресторанти стартират същия следобед. Добавете артикулите, цените и снимките си (или импортирайте CSV), изберете шаблон и публикувайте.'],
                ['Мога ли да предлагам менюто си на повече от един език?', 'Да. Преведете цялото си меню на няколко езика с едно кликване, така че всеки гост да го чете на своя език.'],
                ['Има ли безплатен план?', 'Да — започнете безплатно без кредитна карта и надградете, когато имате нужда от повече.'],
            ],
            'bn' => [
                ['QR মেনু কীভাবে কাজ করে?', 'প্রতিটি টেবিল তার নিজস্ব QR কোড পায়। অতিথিরা ফোনের ক্যামেরা দিয়ে স্ক্যান করলেই আপনার ব্র্যান্ডেড মেনু সঙ্গে সঙ্গে ব্রাউজারে খুলে যায় — কোনো অ্যাপ ডাউনলোড লাগে না।'],
                ['আমার গ্রাহকদের কি অ্যাপ ইনস্টল করতে হবে?', 'না। QR কোড স্ক্যান করার সঙ্গে সঙ্গেই মেনু যেকোনো ফোন ব্রাউজারে খোলে।'],
                ['আমি কি মেনু থেকে অর্ডার ও পেমেন্ট নিতে পারি?', 'হ্যাঁ। অতিথিরা সরাসরি মেনু থেকে অর্ডার করেন এবং নগদ, কার্ড বা অনলাইনে পরিশোধ করেন। আপনি প্রতিটি পয়সা রাখেন — কোনো অর্ডারে কোনো কমিশন নেই।'],
                ['সেটআপে কত সময় লাগে?', 'বেশিরভাগ রেস্তোরাঁ সেই বিকেলেই চালু হয়। আপনার আইটেম, দাম ও ছবি যোগ করুন (বা CSV ইমপোর্ট করুন), একটি টেমপ্লেট বেছে নিন, এবং প্রকাশ করুন।'],
                ['আমি কি একাধিক ভাষায় আমার মেনু দিতে পারি?', 'হ্যাঁ। এক ক্লিকে আপনার পুরো মেনু একাধিক ভাষায় অনুবাদ করুন, যাতে প্রতিটি অতিথি নিজের ভাষায় পড়তে পারেন।'],
                ['কোনো ফ্রি প্ল্যান আছে কি?', 'হ্যাঁ — ক্রেডিট কার্ড ছাড়াই ফ্রিতে শুরু করুন, এবং প্রয়োজন হলে যেকোনো সময় আপগ্রেড করুন।'],
            ],
            'de' => [
                ['Wie funktioniert die QR-Speisekarte?', 'Jeder Tisch erhält seinen eigenen QR-Code. Gäste scannen ihn mit der Handykamera und deine gebrandete Speisekarte öffnet sich sofort im Browser — ohne App-Download.'],
                ['Müssen meine Gäste eine App installieren?', 'Nein. Die Speisekarte öffnet sich in jedem Handy-Browser, sobald der QR-Code gescannt wird.'],
                ['Kann ich Bestellungen und Zahlungen über die Karte annehmen?', 'Ja. Gäste bestellen direkt aus der Karte und zahlen bar, mit Karte oder online. Du behältst jeden Cent — keine Provision auf eine einzige Bestellung.'],
                ['Wie lange dauert die Einrichtung?', 'Die meisten Restaurants gehen noch am selben Nachmittag live. Artikel, Preise und Fotos hinzufügen (oder CSV importieren), Vorlage wählen und veröffentlichen.'],
                ['Kann ich meine Karte in mehreren Sprachen anbieten?', 'Ja. Übersetze deine ganze Speisekarte mit einem Klick in mehrere Sprachen, damit jeder Gast sie in seiner eigenen Sprache liest.'],
                ['Gibt es einen kostenlosen Tarif?', 'Ja — starte kostenlos ohne Kreditkarte und upgrade, wann immer du mehr brauchst.'],
            ],
            'es' => [
                ['¿Cómo funciona el menú QR?', 'Cada mesa tiene su propio código QR. Los comensales lo escanean con la cámara del móvil y tu menú con tu marca se abre al instante en el navegador, sin descargar ninguna app.'],
                ['¿Mis clientes necesitan instalar una app?', 'No. El menú se abre en cualquier navegador móvil en cuanto escanean el código QR.'],
                ['¿Puedo recibir pedidos y pagos desde el menú?', 'Sí. Los comensales piden directamente desde el menú y pagan en efectivo, con tarjeta o en línea. Te quedas con cada céntimo, sin comisión en ningún pedido.'],
                ['¿Cuánto tarda la configuración?', 'La mayoría de los restaurantes se ponen en marcha esa misma tarde. Añade tus platos, precios y fotos (o importa un CSV), elige una plantilla y publica.'],
                ['¿Puedo ofrecer mi menú en más de un idioma?', 'Sí. Traduce todo tu menú a varios idiomas con un clic, para que cada cliente lo lea en el suyo.'],
                ['¿Hay un plan gratuito?', 'Sí: empieza gratis sin tarjeta de crédito y mejora tu plan cuando necesites más.'],
            ],
            'fr' => [
                ['Comment fonctionne le menu QR ?', "Chaque table reçoit son propre QR code. Les clients le scannent avec l'appareil photo de leur téléphone et votre menu à votre image s'ouvre instantanément dans le navigateur — sans application à télécharger."],
                ['Mes clients doivent-ils installer une application ?', 'Non. Le menu s\'ouvre dans n\'importe quel navigateur mobile dès qu\'ils scannent le QR code.'],
                ['Puis-je prendre les commandes et les paiements depuis le menu ?', 'Oui. Les clients commandent directement depuis le menu et paient en espèces, par carte ou en ligne. Vous gardez chaque centime — aucune commission sur la moindre commande.'],
                ['Combien de temps prend la configuration ?', 'La plupart des restaurants sont en ligne le jour même. Ajoutez vos plats, prix et photos (ou importez un CSV), choisissez un modèle et publiez.'],
                ['Puis-je proposer mon menu en plusieurs langues ?', 'Oui. Traduisez tout votre menu en plusieurs langues en un clic, pour que chaque client le lise dans la sienne.'],
                ['Existe-t-il une offre gratuite ?', 'Oui — commencez gratuitement sans carte bancaire, et passez à une offre supérieure quand vous en avez besoin.'],
            ],
            'he' => [
                ['איך תפריט ה-QR עובד?', 'כל שולחן מקבל קוד QR משלו. הסועדים סורקים אותו במצלמת הטלפון והתפריט הממותג שלכם נפתח מיד בדפדפן — בלי אפליקציה להורדה.'],
                ['האם הלקוחות שלי צריכים להתקין אפליקציה?', 'לא. התפריט נפתח בכל דפדפן טלפון ברגע שסורקים את קוד ה-QR.'],
                ['אפשר לקבל הזמנות ותשלומים מהתפריט?', 'כן. הסועדים מזמינים ישירות מהתפריט ומשלמים במזומן, בכרטיס או אונליין. אתם שומרים על כל אגורה — בלי עמלה על אף הזמנה.'],
                ['כמה זמן לוקח ההקמה?', 'רוב המסעדות עולות לאוויר עוד באותו אחר צהריים. הוסיפו פריטים, מחירים ותמונות (או ייבאו CSV), בחרו תבנית ופרסמו.'],
                ['אפשר להציע את התפריט ביותr משפה אחת?', 'כן. תרגמו את כל התפריט לכמה שפות בלחיצה אחת, כך שכל אורח יקרא אותו בשפתו.'],
                ['האם יש תוכנית חינמית?', 'כן — התחילו בחינם ללא כרטיס אשראי, ושדרגו מתי שתצטרכו יותר.'],
            ],
            'hi' => [
                ['QR मेन्यू कैसे काम करता है?', 'हर टेबल को अपना QR कोड मिलता है। ग्राहक इसे फ़ोन कैमरे से स्कैन करते हैं और आपका ब्रांडेड मेन्यू तुरंत ब्राउज़र में खुल जाता है — कोई ऐप डाउनलोड नहीं।'],
                ['क्या मेरे ग्राहकों को ऐप इंस्टॉल करना होगा?', 'नहीं। QR कोड स्कैन करते ही मेन्यू किसी भी फ़ोन ब्राउज़र में खुल जाता है।'],
                ['क्या मैं मेन्यू से ऑर्डर और भुगतान ले सकता/सकती हूँ?', 'हाँ। ग्राहक सीधे मेन्यू से ऑर्डर करते हैं और नकद, कार्ड या ऑनलाइन भुगतान करते हैं। आप हर पैसा रखते हैं — किसी भी ऑर्डर पर कोई कमीशन नहीं।'],
                ['सेटअप में कितना समय लगता है?', 'ज़्यादातर रेस्टोरेंट उसी दोपहर लाइव हो जाते हैं। अपने आइटम, दाम और फ़ोटो जोड़ें (या CSV इंपोर्ट करें), टेम्पलेट चुनें और पब्लिश करें।'],
                ['क्या मैं अपना मेन्यू एक से अधिक भाषाओं में दे सकता/सकती हूँ?', 'हाँ। एक क्लिक में अपना पूरा मेन्यू कई भाषाओं में अनुवाद करें, ताकि हर मेहमान इसे अपनी भाषा में पढ़े।'],
                ['क्या कोई मुफ़्त प्लान है?', 'हाँ — बिना क्रेडिट कार्ड के मुफ़्त शुरू करें, और जब ज़रूरत हो तब अपग्रेड करें।'],
            ],
            'it' => [
                ['Come funziona il menu QR?', 'Ogni tavolo ha il proprio codice QR. I clienti lo scansionano con la fotocamera del telefono e il tuo menu personalizzato si apre subito nel browser, senza scaricare alcuna app.'],
                ['I miei clienti devono installare un\'app?', 'No. Il menu si apre in qualsiasi browser del telefono appena scansionano il codice QR.'],
                ['Posso ricevere ordini e pagamenti dal menu?', 'Sì. I clienti ordinano direttamente dal menu e pagano in contanti, con carta o online. Tieni ogni centesimo, senza commissioni su nessun ordine.'],
                ['Quanto tempo richiede la configurazione?', 'La maggior parte dei ristoranti va online lo stesso pomeriggio. Aggiungi piatti, prezzi e foto (o importa un CSV), scegli un modello e pubblica.'],
                ['Posso offrire il menu in più lingue?', 'Sì. Traduci tutto il menu in più lingue con un clic, così ogni ospite lo legge nella propria.'],
                ['Esiste un piano gratuito?', 'Sì: inizia gratis senza carta di credito e passa a un piano superiore quando ti serve.'],
            ],
            'ja' => [
                ['QRメニューはどう動きますか？', '各テーブルに専用のQRコードが付きます。お客様がスマホのカメラで読み取ると、あなたのブランドのメニューがブラウザですぐに開きます。アプリのダウンロードは不要です。'],
                ['お客様はアプリをインストールする必要がありますか？', 'いいえ。QRコードを読み取った瞬間に、どのスマホのブラウザでもメニューが開きます。'],
                ['メニューから注文と支払いを受けられますか？', 'はい。お客様はメニューから直接注文し、現金・カード・オンラインで支払えます。手数料は一切かからず、売上はすべてあなたのものです。'],
                ['設定にはどれくらいかかりますか？', 'ほとんどの店舗が同じ日の午後に公開できます。商品・価格・写真を追加（またはCSVをインポート）し、テンプレートを選んで公開するだけです。'],
                ['メニューを複数の言語で提供できますか？', 'はい。ワンクリックでメニュー全体を複数の言語に翻訳でき、どのお客様も自分の言語で読めます。'],
                ['無料プランはありますか？', 'はい。クレジットカード不要で無料で始められ、必要になればいつでもアップグレードできます。'],
            ],
            'pl' => [
                ['Jak działa menu QR?', 'Każdy stolik ma własny kod QR. Goście skanują go aparatem telefonu, a Twoje markowe menu otwiera się natychmiast w przeglądarce — bez pobierania aplikacji.'],
                ['Czy moi klienci muszą instalować aplikację?', 'Nie. Menu otwiera się w każdej przeglądarce telefonu w momencie zeskanowania kodu QR.'],
                ['Czy mogę przyjmować zamówienia i płatności z menu?', 'Tak. Goście zamawiają bezpośrednio z menu i płacą gotówką, kartą lub online. Zatrzymujesz każdy grosz — bez prowizji od żadnego zamówienia.'],
                ['Ile trwa konfiguracja?', 'Większość restauracji rusza tego samego popołudnia. Dodaj pozycje, ceny i zdjęcia (lub zaimportuj CSV), wybierz szablon i opublikuj.'],
                ['Czy mogę udostępnić menu w więcej niż jednym języku?', 'Tak. Przetłumacz całe menu na wiele języków jednym kliknięciem, aby każdy gość czytał je w swoim języku.'],
                ['Czy jest plan darmowy?', 'Tak — zacznij za darmo bez karty kredytowej i przejdź na wyższy plan, gdy potrzebujesz więcej.'],
            ],
            'ro' => [
                ['Cum funcționează meniul QR?', 'Fiecare masă are propriul cod QR. Clienții îl scanează cu camera telefonului, iar meniul tău personalizat se deschide instantaneu în browser — fără nicio aplicație de descărcat.'],
                ['Clienții mei trebuie să instaleze o aplicație?', 'Nu. Meniul se deschide în orice browser de telefon în momentul în care scanează codul QR.'],
                ['Pot primi comenzi și plăți din meniu?', 'Da. Clienții comandă direct din meniu și plătesc cu numerar, card sau online. Păstrezi fiecare bănuț — fără comision la nicio comandă.'],
                ['Cât durează configurarea?', 'Majoritatea restaurantelor pornesc în aceeași după-amiază. Adaugă produsele, prețurile și pozele (sau importă un CSV), alege un șablon și publică.'],
                ['Pot oferi meniul în mai multe limbi?', 'Da. Traduce tot meniul în mai multe limbi cu un singur clic, astfel încât fiecare client să îl citească în limba lui.'],
                ['Există un plan gratuit?', 'Da — începe gratuit, fără card bancar, și treci la un plan superior când ai nevoie de mai mult.'],
            ],
            'ru' => [
                ['Как работает QR-меню?', 'У каждого столика свой QR-код. Гости сканируют его камерой телефона, и ваше фирменное меню мгновенно открывается в браузере — без установки приложения.'],
                ['Нужно ли моим клиентам устанавливать приложение?', 'Нет. Меню открывается в любом мобильном браузере сразу после сканирования QR-кода.'],
                ['Могу ли я принимать заказы и оплату прямо из меню?', 'Да. Гости заказывают прямо из меню и платят наличными, картой или онлайн. Вы оставляете себе всё до копейки — никакой комиссии ни с одного заказа.'],
                ['Сколько занимает настройка?', 'Большинство ресторанов запускаются в тот же день. Добавьте позиции, цены и фото (или импортируйте CSV), выберите шаблон и опубликуйте.'],
                ['Можно ли предлагать меню более чем на одном языке?', 'Да. Переведите всё меню на несколько языков одним кликом, чтобы каждый гость читал его на своём языке.'],
                ['Есть ли бесплатный тариф?', 'Да — начните бесплатно без банковской карты и переходите на платный тариф, когда понадобится больше.'],
            ],
            'sv' => [
                ['Hur fungerar QR-menyn?', 'Varje bord får sin egen QR-kod. Gästerna skannar den med mobilkameran och din varumärkta meny öppnas direkt i webbläsaren — ingen app att ladda ner.'],
                ['Måste mina kunder installera en app?', 'Nej. Menyn öppnas i valfri mobilwebbläsare så snart de skannar QR-koden.'],
                ['Kan jag ta emot beställningar och betalningar från menyn?', 'Ja. Gästerna beställer direkt från menyn och betalar kontant, med kort eller online. Du behåller varenda krona — ingen provision på en enda beställning.'],
                ['Hur lång tid tar det att komma igång?', 'De flesta restauranger är igång samma eftermiddag. Lägg till rätter, priser och bilder (eller importera en CSV), välj en mall och publicera.'],
                ['Kan jag erbjuda menyn på fler än ett språk?', 'Ja. Översätt hela menyn till flera språk med ett klick, så att varje gäst läser den på sitt eget språk.'],
                ['Finns det ett gratisabonnemang?', 'Ja — börja gratis utan kreditkort och uppgradera när du behöver mer.'],
            ],
            'th' => [
                ['เมนู QR ทำงานอย่างไร?', 'ทุกโต๊ะมีคิวอาร์โค้ดของตัวเอง ลูกค้าสแกนด้วยกล้องมือถือ แล้วเมนูแบรนด์ของคุณจะเปิดขึ้นทันทีในเบราว์เซอร์ — ไม่ต้องดาวน์โหลดแอป'],
                ['ลูกค้าของฉันต้องติดตั้งแอปไหม?', 'ไม่ต้อง เมนูจะเปิดในเบราว์เซอร์มือถือใดก็ได้ทันทีที่สแกนคิวอาร์โค้ด'],
                ['ฉันรับออร์เดอร์และชำระเงินจากเมนูได้ไหม?', 'ได้ ลูกค้าสั่งจากเมนูได้โดยตรงและชำระด้วยเงินสด บัตร หรือออนไลน์ คุณเก็บทุกบาททุกสตางค์ — ไม่มีค่าคอมมิชชันแม้แต่ออร์เดอร์เดียว'],
                ['การตั้งค่าใช้เวลานานแค่ไหน?', 'ร้านส่วนใหญ่เปิดใช้งานได้ในบ่ายวันเดียวกัน เพิ่มรายการ ราคา และรูปภาพ (หรือนำเข้าไฟล์ CSV) เลือกเทมเพลต แล้วเผยแพร่'],
                ['ฉันเสนอเมนูได้มากกว่าหนึ่งภาษาไหม?', 'ได้ แปลเมนูทั้งหมดเป็นหลายภาษาได้ในคลิกเดียว เพื่อให้ลูกค้าทุกคนอ่านในภาษาของตัวเอง'],
                ['มีแพ็กเกจฟรีไหม?', 'มี — เริ่มฟรีโดยไม่ต้องใช้บัตรเครดิต และอัปเกรดเมื่อคุณต้องการมากขึ้น'],
            ],
            'tr' => [
                ['QR menü nasıl çalışır?', 'Her masaya kendi QR kodu verilir. Misafirler telefon kamerasıyla tarar ve markalı menünüz tarayıcıda anında açılır — indirilecek uygulama yok.'],
                ['Müşterilerimin uygulama yüklemesi gerekiyor mu?', 'Hayır. QR kodu taradıkları anda menü her telefon tarayıcısında açılır.'],
                ['Menüden sipariş ve ödeme alabilir miyim?', 'Evet. Misafirler doğrudan menüden sipariş verir; nakit, kart veya online öder. Her kuruşu siz alırsınız — tek bir siparişte bile komisyon yok.'],
                ['Kurulum ne kadar sürer?', 'Çoğu restoran aynı öğleden sonra yayına girer. Ürünlerinizi, fiyatlarınızı ve fotoğraflarınızı ekleyin (veya CSV içe aktarın), bir şablon seçin ve yayınlayın.'],
                ['Menümü birden fazla dilde sunabilir miyim?', 'Evet. Tüm menünüzü tek tıkla birden çok dile çevirin; böylece her misafir kendi dilinde okur.'],
                ['Ücretsiz plan var mı?', 'Evet — kredi kartı gerekmeden ücretsiz başlayın ve daha fazlasına ihtiyaç duydukça yükseltin.'],
            ],
            'ur' => [
                ['QR مینیو کیسے کام کرتا ہے؟', 'ہر میز کو اپنا QR کوڈ ملتا ہے۔ مہمان اسے فون کیمرے سے اسکین کرتے ہیں اور آپ کا برانڈڈ مینیو فوراً براؤزر میں کھل جاتا ہے — کوئی ایپ ڈاؤن لوڈ نہیں۔'],
                ['کیا میرے گاہکوں کو ایپ انسٹال کرنی ہوگی؟', 'نہیں۔ QR کوڈ اسکین کرتے ہی مینیو کسی بھی فون براؤزر میں کھل جاتا ہے۔'],
                ['کیا میں مینیو سے آرڈر اور ادائیگیاں لے سکتا ہوں؟', 'جی ہاں۔ مہمان براہِ راست مینیو سے آرڈر کرتے ہیں اور نقد، کارڈ یا آن لائن ادائیگی کرتے ہیں۔ آپ ہر پیسہ رکھتے ہیں — کسی بھی آرڈر پر کوئی کمیشن نہیں۔'],
                ['سیٹ اپ میں کتنا وقت لگتا ہے؟', 'زیادہ تر ریستوران اسی دوپہر لائیو ہو جاتے ہیں۔ اپنی اشیاء، قیمتیں اور تصاویر شامل کریں (یا CSV امپورٹ کریں)، ٹیمپلیٹ منتخب کریں اور شائع کریں۔'],
                ['کیا میں اپنا مینیو ایک سے زیادہ زبانوں میں پیش کر سکتا ہوں؟', 'جی ہاں۔ ایک کلک میں اپنا پورا مینیو کئی زبانوں میں ترجمہ کریں، تاکہ ہر مہمان اسے اپنی زبان میں پڑھے۔'],
                ['کیا کوئی مفت پلان ہے؟', 'جی ہاں — بغیر کریڈٹ کارڈ کے مفت شروع کریں، اور جب زیادہ ضرورت ہو تو اپ گریڈ کریں۔'],
            ],
            'vi' => [
                ['Menu QR hoạt động thế nào?', 'Mỗi bàn có mã QR riêng. Thực khách quét bằng camera điện thoại và menu thương hiệu của bạn mở ngay trên trình duyệt — không cần tải ứng dụng.'],
                ['Khách của tôi có cần cài ứng dụng không?', 'Không. Menu mở trên mọi trình duyệt điện thoại ngay khi quét mã QR.'],
                ['Tôi có thể nhận đơn và thanh toán từ menu không?', 'Có. Thực khách đặt trực tiếp từ menu và thanh toán bằng tiền mặt, thẻ hoặc trực tuyến. Bạn giữ trọn từng đồng — không mất hoa hồng cho bất kỳ đơn nào.'],
                ['Thiết lập mất bao lâu?', 'Hầu hết nhà hàng lên sóng ngay trong buổi chiều đó. Thêm món, giá và ảnh (hoặc nhập CSV), chọn mẫu và xuất bản.'],
                ['Tôi có thể cung cấp menu bằng nhiều ngôn ngữ không?', 'Có. Dịch toàn bộ menu sang nhiều ngôn ngữ chỉ với một cú nhấp, để mỗi khách đọc bằng ngôn ngữ của mình.'],
                ['Có gói miễn phí không?', 'Có — bắt đầu miễn phí không cần thẻ tín dụng, và nâng cấp bất cứ khi nào bạn cần thêm.'],
            ],
            'zh' => [
                ['二维码菜单如何运作？', '每张桌子都有专属二维码。顾客用手机相机扫描，你的品牌菜单便立即在浏览器中打开——无需下载任何应用。'],
                ['我的顾客需要安装应用吗？', '不需要。扫描二维码的那一刻，菜单就会在任意手机浏览器中打开。'],
                ['我能通过菜单接单和收款吗？', '可以。顾客直接在菜单上下单，用现金、银行卡或在线支付。每一分钱都归你——任何订单都不收佣金。'],
                ['设置需要多长时间？', '大多数餐厅当天下午即可上线。添加菜品、价格和照片（或导入 CSV），选择模板，然后发布即可。'],
                ['我可以用多种语言提供菜单吗？', '可以。一键将整份菜单翻译成多种语言，让每位顾客都能用自己的语言阅读。'],
                ['有免费方案吗？', '有——无需信用卡即可免费开始，需要更多功能时随时升级。'],
            ],
        ];
    }
}
