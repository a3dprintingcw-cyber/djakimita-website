<?php
// Terms & conditions and privacy policy. English and Dutch; Papiamentu shows the Dutch text for now.
// DRAFT: to be approved by Deals With Discounts (and their lawyer) before launch.

const LEGAL_UPDATED = '2026-10-23';

function translations_legal(): array
{
    return [
        'en' => [
            'lg.terms' => 'Terms & conditions',
            'lg.privacy' => 'Privacy policy',
            'lg.updated' => 'Last updated: {date}',
            'lg.draft' => 'Draft: this text still has to be approved by Deals With Discounts.',
            'lg.paponly' => '',
        ],
        'nl' => [
            'lg.terms' => 'Algemene voorwaarden',
            'lg.privacy' => 'Privacybeleid',
            'lg.updated' => 'Laatst bijgewerkt: {date}',
            'lg.draft' => 'Concept: deze tekst moet nog goedgekeurd worden door Deals With Discounts.',
            'lg.paponly' => '',
        ],
        'pap' => [
            'lg.terms' => 'Tèrmino i kondishon',
            'lg.privacy' => 'Maneho di privasidat',
            'lg.updated' => 'Último kambio: {date}',
            'lg.draft' => 'Konsepto: e teksto aki mester wòrdu aprobá ainda pa Deals With Discounts.',
            'lg.paponly' => 'E teksto aki ta na Hulandes pa awor. Un version na Papiamentu ta bini.',
        ],
    ];
}

/** @return array<int, array{0:string,1:array<int,string>}> sections of [heading, paragraphs] */
function legal_text(string $page, string $lang): array
{
    $lang = $lang === 'en' ? 'en' : 'nl';
    $m = card_months();
    $t = [];

    $t['terms']['en'] = [
        ['1. Who we are', [
            "Dj'aki mi ta! is run by Deals With Discounts (\"we\", \"us\"). Contact: contact@djakimita.com.",
            '[Company details to add: legal name, Chamber of Commerce number and address.]',
        ]],
        ['2. The card', [
            "The Dj'aki mi ta! card is a digital discount card. It gives the people named on the card discounts at participating partners in Curaçao.",
            "A card is valid for {$m} months from the day of purchase. The end date is shown in your account and in the app.",
            'A card is personal. Only the people named on it may use it.',
        ]],
        ['3. Who can get a card', [
            'Cards are only for people born in Curaçao. For a family card (up to 5 people), at least one parent must be born in Curaçao.',
            'The buyer must be 18 or older. Cards for children and teens are bought by a parent or guardian.',
        ]],
        ['4. The one-time check', [
            'After buying, each card holder sends a selfie and a photo of their passport page once, so we can check the place of birth. We delete the passport photo straight after the check.',
            'If the check cannot be completed, or shows the holder was not born in Curaçao, we can refuse or block the card for that person.',
        ]],
        ['5. Using the card', [
            'At a partner, the card holder scans the partner\'s QR code with the app. The app shows a screen with the holder\'s photo and name. The partner may still ask for an ID.',
            'Each partner decides its own discount. Discounts and partners can change. A discount has no cash value and cannot be combined with other offers unless the partner says so.',
        ]],
        ['6. Prices and payment', [
            'Prices are shown on the website in euro or US dollar. Payment is handled by Stripe. We never see or store your full card details.',
        ]],
        ['7. No refunds', [
            'Once bought, a card cannot be cancelled or refunded, except where the law says otherwise.',
        ]],
        ['8. Misuse', [
            'Sharing a card with people not named on it, giving false information or misusing the app can lead to the card or account being blocked, without a refund.',
        ]],
        ['9. Liability', [
            'Partners are responsible for their own products and services. We are not liable for the quality, availability or safety of what partners offer.',
        ]],
        ['10. Changes', [
            'We can change these terms. The version on this page always applies, with the date it was last updated.',
        ]],
        ['11. Law', [
            'These terms are governed by the laws of Curaçao. Disputes go to the competent court in Curaçao.',
        ]],
    ];

    $t['terms']['nl'] = [
        ['1. Wie wij zijn', [
            "Dj'aki mi ta! wordt gerund door Deals With Discounts (\"wij\", \"ons\"). Contact: contact@djakimita.com.",
            '[Bedrijfsgegevens aanvullen: juridische naam, KvK-nummer en adres.]',
        ]],
        ['2. De kaart', [
            "De Dj'aki mi ta! kaart is een digitale kortingskaart. De mensen die op de kaart staan krijgen korting bij deelnemende partners op Curaçao.",
            "Een kaart is {$m} maanden geldig vanaf de dag van aankoop. De einddatum staat in je account en in de app.",
            'Een kaart is persoonlijk. Alleen de mensen die erop staan mogen hem gebruiken.',
        ]],
        ['3. Wie een kaart kan krijgen', [
            'Kaarten zijn alleen voor mensen die op Curaçao geboren zijn. Voor een gezinskaart (maximaal 5 personen) moet minstens één ouder op Curaçao geboren zijn.',
            'De koper moet 18 jaar of ouder zijn. Kaarten voor kinderen en tieners worden gekocht door een ouder of voogd.',
        ]],
        ['4. De eenmalige controle', [
            'Na aankoop stuurt elke kaarthouder één keer een selfie en een foto van de paspoortpagina, zodat wij de geboorteplaats kunnen controleren. De paspoortfoto verwijderen wij direct na de controle.',
            'Als de controle niet lukt, of laat zien dat de houder niet op Curaçao geboren is, kunnen wij de kaart voor die persoon weigeren of blokkeren.',
        ]],
        ['5. De kaart gebruiken', [
            'Bij een partner scant de kaarthouder de QR-code van de partner met de app. De app toont een scherm met de foto en naam van de houder. De partner mag alsnog om een ID vragen.',
            'Elke partner bepaalt zijn eigen korting. Kortingen en partners kunnen veranderen. Een korting heeft geen geldwaarde en is niet te combineren met andere acties, tenzij de partner dat aangeeft.',
        ]],
        ['6. Prijzen en betaling', [
            'Prijzen staan op de website in euro of Amerikaanse dollar. De betaling loopt via Stripe. Wij zien en bewaren nooit je volledige kaartgegevens.',
        ]],
        ['7. Geen terugbetaling', [
            'Een gekochte kaart kan niet geannuleerd of terugbetaald worden, behalve als de wet anders bepaalt.',
        ]],
        ['8. Misbruik', [
            'Een kaart delen met mensen die er niet op staan, onjuiste gegevens geven of de app misbruiken kan leiden tot blokkering van de kaart of het account, zonder terugbetaling.',
        ]],
        ['9. Aansprakelijkheid', [
            'Partners zijn verantwoordelijk voor hun eigen producten en diensten. Wij zijn niet aansprakelijk voor de kwaliteit, beschikbaarheid of veiligheid van wat partners aanbieden.',
        ]],
        ['10. Wijzigingen', [
            'Wij kunnen deze voorwaarden aanpassen. De versie op deze pagina geldt altijd, met de datum van de laatste wijziging.',
        ]],
        ['11. Recht', [
            'Op deze voorwaarden is het recht van Curaçao van toepassing. Geschillen gaan naar de bevoegde rechter op Curaçao.',
        ]],
    ];

    $t['privacy']['en'] = [
        ['1. Who is responsible', [
            "Deals With Discounts, the company behind Dj'aki mi ta!, is responsible for your personal data. Questions or requests: contact@djakimita.com.",
        ]],
        ['2. What we collect', [
            'Your account: name, email address, and your phone number if you order an eSIM.',
            'Card holders: name and date of birth of each person on a card.',
            'The one-time check: a selfie and a photo of the passport page of each card holder.',
            'Orders: what you bought, when, and the payment status. Stripe processes the payment; we never receive your full card number.',
            'Use of the card: which partner you scanned and when.',
            'Website: your IP address in our server logs, and two small cookies (one to keep you logged in during your visit, one to remember your language). We do not use advertising or tracking cookies.',
        ]],
        ['3. Why we use it', [
            'To sell and activate your card, check that card holders were born in Curaçao, show the right photo and name at partners, send emails about your order and account, help you when you contact us, prevent misuse, and keep the records the law requires.',
        ]],
        ['4. The passport photo', [
            'The passport photo is only used for the one-time check of the place of birth. It is stored outside the public website, only staff can see it, and it is deleted as soon as the check is done. We keep only the result (verified or not).',
            'The selfie is kept while your card or account exists, because the partner sees it on the screen when you scan.',
        ]],
        ['5. Who we share it with', [
            'Stripe (payments), Brevo (sending emails) and our hosting provider. They only process data for us.',
            'Flow, only if you order an eSIM: your phone number and email.',
            'Partners: when you scan, the partner sees your photo, name and that your card is valid on your screen. Partners do not receive your other data.',
            'Authorities, only when the law requires it. We never sell your data.',
        ]],
        ['6. How long we keep it', [
            'Passport photo: deleted right after the check.',
            'Account, card holders and selfie: until you delete your account.',
            'Payment records: as long as tax law requires, without your name once your account is deleted.',
            'Server logs: only for a short time, for security.',
        ]],
        ['7. Your rights', [
            'You can ask to see, correct or delete your data, and object to how we use it. You can delete your account yourself under My account, then Delete my account. For anything else, email contact@djakimita.com. We answer within one month.',
            'You can also file a complaint with the data protection authority in your country.',
        ]],
        ['8. Security', [
            'The website only works over HTTPS. Passwords are stored encrypted (hashed), photos are kept outside the public website, and only staff with their own login can see verification photos.',
        ]],
        ['9. Changes', [
            'If we change this policy, the new version appears on this page with a new date.',
        ]],
    ];

    $t['privacy']['nl'] = [
        ['1. Wie is verantwoordelijk', [
            "Deals With Discounts, het bedrijf achter Dj'aki mi ta!, is verantwoordelijk voor je persoonsgegevens. Vragen of verzoeken: contact@djakimita.com.",
        ]],
        ['2. Wat wij verzamelen', [
            'Je account: naam, e-mailadres, en je telefoonnummer als je een eSIM bestelt.',
            'Kaarthouders: naam en geboortedatum van elke persoon op een kaart.',
            'De eenmalige controle: een selfie en een foto van de paspoortpagina van elke kaarthouder.',
            'Bestellingen: wat je kocht, wanneer, en de betaalstatus. Stripe verwerkt de betaling; wij ontvangen nooit je volledige kaartnummer.',
            'Gebruik van de kaart: welke partner je scande en wanneer.',
            'Website: je IP-adres in onze serverlogs, en twee kleine cookies (één om je ingelogd te houden tijdens je bezoek, één om je taal te onthouden). Wij gebruiken geen advertentie- of trackingcookies.',
        ]],
        ['3. Waarom wij het gebruiken', [
            'Om je kaart te verkopen en te activeren, te controleren dat kaarthouders op Curaçao geboren zijn, de juiste foto en naam te tonen bij partners, e-mails te sturen over je bestelling en account, je te helpen als je contact opneemt, misbruik te voorkomen, en de administratie te bewaren die de wet vraagt.',
        ]],
        ['4. De paspoortfoto', [
            'De paspoortfoto wordt alleen gebruikt voor de eenmalige controle van de geboorteplaats. Hij staat buiten de openbare website, alleen medewerkers kunnen hem zien, en hij wordt verwijderd zodra de controle klaar is. We bewaren alleen de uitkomst (geverifieerd of niet).',
            'De selfie bewaren we zolang je kaart of account bestaat, omdat de partner hem op het scherm ziet als je scant.',
        ]],
        ['5. Met wie wij het delen', [
            'Stripe (betalingen), Brevo (e-mails versturen) en onze hostingpartij. Zij verwerken gegevens alleen voor ons.',
            'Flow, alleen als je een eSIM bestelt: je telefoonnummer en e-mailadres.',
            'Partners: als je scant, ziet de partner op jouw scherm je foto, naam en dat je kaart geldig is. Partners krijgen je andere gegevens niet.',
            'Overheid, alleen als de wet dat verplicht. Wij verkopen je gegevens nooit.',
        ]],
        ['6. Hoe lang wij het bewaren', [
            'Paspoortfoto: direct na de controle verwijderd.',
            'Account, kaarthouders en selfie: tot je je account verwijdert.',
            'Betaalgegevens: zolang de belastingwet vraagt, zonder je naam zodra je account verwijderd is.',
            'Serverlogs: alleen korte tijd, voor beveiliging.',
        ]],
        ['7. Jouw rechten', [
            'Je kunt vragen om je gegevens in te zien, te corrigeren of te verwijderen, en bezwaar maken tegen hoe wij ze gebruiken. Je account verwijder je zelf onder Mijn account, dan Mijn account verwijderen. Voor al het andere: mail contact@djakimita.com. We antwoorden binnen een maand.',
            'Je kunt ook een klacht indienen bij de toezichthouder voor gegevensbescherming in je land.',
        ]],
        ['8. Beveiliging', [
            'De website werkt alleen via HTTPS. Wachtwoorden worden versleuteld (gehasht) opgeslagen, foto\'s staan buiten de openbare website, en alleen medewerkers met een eigen login kunnen verificatiefoto\'s zien.',
        ]],
        ['9. Wijzigingen', [
            'Als we dit beleid aanpassen, staat de nieuwe versie op deze pagina met een nieuwe datum.',
        ]],
    ];

    return $t[$page][$lang] ?? [];
}

function render_legal(string $page): void
{
    $title = t($page === 'terms' ? 'lg.terms' : 'lg.privacy');
    page_header($title . " · Dj'aki mi ta!", $page);
    $sections = legal_text($page, current_lang());
    ?>
<section class="page-head tint">
  <div class="wrap narrow">
    <h1><?= e($title) ?></h1>
    <p class="lead"><?= e(t('lg.updated', ['date' => date('d-m-Y', strtotime(LEGAL_UPDATED))])) ?></p>
  </div>
</section>
<section class="section pt-sm">
  <div class="wrap narrow legal">
    <?php if (config('preview_pass')): ?><p class="notice"><?= e(t('lg.draft')) ?></p><?php endif; ?>
    <?php if (t('lg.paponly') !== '' && t('lg.paponly') !== 'lg.paponly'): ?><p class="hint"><?= e(t('lg.paponly')) ?></p><?php endif; ?>
    <?php foreach ($sections as [$h, $paras]): ?>
      <h2 class="h3"><?= e($h) ?></h2>
      <?php foreach ($paras as $p): ?>
        <p<?= str_starts_with($p, '[') ? ' class="todo"' : '' ?>><?= str_replace('contact@djakimita.com', '<a href="mailto:contact@djakimita.com">contact@djakimita.com</a>', e($p)) ?></p>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</section>
<?php
    page_footer();
}
