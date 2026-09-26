# verifyid-php

PHP-klient til [VerifyID](https://kyc.verifyid.dk). Den daekker det tre ting: MitID-login paa din egen side, kontrakter til underskrift, og verificering af webhooks.

Kraever PHP 8.1 og en PSR-18-klient. Har du Guzzle, bliver den brugt. Der er ingen binding til et framework.

## Installation

```bash
composer require verifyid/verifyid-php guzzlehttp/guzzle
```

Guzzle er valgfri. Uden den giver du din egen PSR-18-klient og PSR-17-fabrikker med, se [Egen HTTP-klient](#egen-http-klient).

## Foer du starter

1. Lav en API-noegle i portalen under **API**. Den hoerer paa serveren, ikke i browseren og ikke i git.
2. Skriv dine domaener paa noeglens **domaeneliste**. Vaerten i `redirect_url`, `cancel_url` og `webhook` skal staa der. Er listen tom, er ingen adresser tilladt, og API'et svarer `ingen_domaener`.
3. Test paa **kyctesting.verifyid.dk** med en testnoegle (`Client::test(...)`). Drift er **kyc.verifyid.dk** (`new Client(...)`). En testnoegle virker ikke i drift.

```php
use VerifyID\Client;

$client = new Client(getenv('VERIFYID_KEY'));      // drift
$client = Client::test(getenv('VERIFYID_KEY'));    // testmiljoe
```

## Log ind med MitID

Tre skridt. Din server laver det foerste og det sidste, det midterste sker hos MitID.

```php
use VerifyID\Eid;

// 1. Start: opret et opslag og send personen afsted
$lookup = $client->eid()->startLogin('https://din-side.dk/login/retur', [
    'scope' => 'basis',            // se tabellen under scope
    'reference' => $sessionId,     // dit eget id, kommer med tilbage
]);
$_SESSION['lookup_id'] = $lookup['id'];
header('Location: ' . $lookup['url']);
exit;
```

```php
// 3. Retur: personen lander paa din redirect_url med ?lookup_id=...
$id = Eid::lookupIdFromQuery($_GET);
if ($id === null || $id !== $_SESSION['lookup_id']) {
    // ikke det opslag vi startede
}

$lookup = $client->eid()->result($id);   // server-til-server, med din noegle

if (Eid::isCompleted($lookup)) {
    $navn = $lookup['result']['navn'];   // med scope basis
} elseif (Eid::isCancelled($lookup)) {
    // personen fortroed, eller MitID sagde nej
}
```

Adressen i browseren beviser ikke noget. Det goer kun svaret fra `result()`, som kraever din noegle. Gem opslagets id i sessionen ved start og godtag kun det samme id ved retur. Ellers kan en person aflevere et andet opslag, som en anden har gennemfoert.

### scope

| scope | du faar |
|---|---|
| `status` | kun at personen ER bekraeftet, ingen felter |
| `alder_over` | om personen er over `age_limit` (sendes med), ikke hvor gammel |
| `alder` | foedselsdato |
| `basis` | navn og foedselsdato |
| `fuld` | alt udbyderen giver |

`method` er `mitid` som standard.

Der er ingen webhook paa eID-opslag. Du henter udfaldet med `result()` naar personen er tilbage.

### status paa et opslag

`paabegyndt` -> `gennemfoert` | `afbrudt` | `udloebet`. `result` er `null` indtil opslaget er gennemfoert, og altid `null` ved scope `status`.

Et komplet endpoint staar i [examples/login-med-mitid.php](examples/login-med-mitid.php).

## Kontrakter

To veje ind: en skabelon fra portalen, eller din egen PDF.

```php
// Skabeloner med deres felter: variabler[] har noegle, navn, type, paakraevet
$skabeloner = $client->contracts()->templates();

$kontrakt = $client->contracts()->createFromTemplate([
    'skabelonId' => 'ab12...',
    'parter' => [
        ['navn' => 'Anna Berg', 'email' => 'anna@eksempel.dk', 'rolle' => 'underskriver'],
    ],
    'variabler' => ['virksomhed' => 'Eksempel ApS'],
    'levering' => 'link',        // du sender selv linkene; 'email' saa sender VerifyID
    'webhook' => 'https://din-side.dk/verifyid/webhook',
    'reference' => 'ordre-7',
], 'ordre-7-kontrakt');          // Idempotency-Key, valgfri

foreach ($kontrakt['parter'] as $part) {
    $part['url'];                // underskriftslinket til den part
}
```

```php
// Egen PDF
$kontrakt = $client->contracts()->createFromDocument('aftale.pdf', $bytes, [
    'parter' => [['navn' => 'Anna Berg', 'email' => 'anna@eksempel.dk']],
    'levering' => 'email',
]);
```

```php
$client->contracts()->get($kontraktId);        // status, parter[].status, parter[].underskrevetNavn
$client->contracts()->pdf($kontraktId);        // den underskrevne PDF som bytes
$client->contracts()->cancel($kontraktId);     // traek aftalen tilbage
```

PDF'en findes foerst naar alle har skrevet under. Indtil da kaster `pdf()` en `ApiException` med koden `kontrakt_ikke_faerdig` (`$e->isContractNotReady()`). Vent paa `kontrakt.faerdig` paa webhooken i stedet for at spoerge igen og igen.

`Idempotency-Key` er det andet argument, hoejst 200 tegn. Samme kald med samme noegle giver samme svar og ikke en kontrakt mere. Brug den naar et kald kan blive sendt to gange, fx fra en koe.

Roller: `underskriver` (standard), `godkender`, `observatoer`. Mindst en part skal vaere underskriver. Se [examples/kontrakt.php](examples/kontrakt.php).

## Webhooks

Hver besked er signeret med din API-noegle. Verificer FOER du laeser kroppen.

```php
use VerifyID\Webhook;
use VerifyID\Exception\SignatureException;

try {
    $besked = $client->webhook()->verifyFromGlobals();
} catch (SignatureException $e) {
    http_response_code(400);
    exit;
}

http_response_code(200);           // kvitter foerst, arbejd bagefter

switch ($besked['haendelse']) {
    case 'kontrakt.part.underskrevet':
    case 'kontrakt.part.afvist':
    case 'kontrakt.faerdig':
    case 'kontrakt.annulleret':
}
```

Har du kroppen og headers i haanden (fx i et framework), brug `verify($rawBody, $headers)`. Kroppen skal vaere den raa streng. Signaturen er `HMAC-SHA256(tid . "." . krop, noegle)` over bytes, saa en JSON du selv har kodet igen, passer ikke.

Headers: `X-VerifyID-Signatur` (hex), `X-VerifyID-Tid` (unix-sekunder), `X-VerifyID-Haendelse`. Beskeder aeldre end 300 sekunder afvises; graensen saettes med `new Webhook($key, $sekunder)`.

Haendelser paa kontrakter: `kontrakt.part.underskrevet`, `kontrakt.part.afvist`, `kontrakt.godkendt` (kun med en godkender), `kontrakt.faerdig`, `kontrakt.annulleret`. KYC-links sender `verifikation.gennemfoert`, `verifikation.afvist` og `verifikation.udloebet` med feltet `verifikation`.

Den samme besked kan komme mere end en gang. Brug kontrakt-id og haendelse som noegle og spring gentagelser over. VerifyID proever fem gange med stigende mellemrum naar dit svar ikke er 2xx. Webhook-adressen skal vaere https og paa noeglens domaeneliste.

`Webhook::sign($rawBody, $tid, $key)` er offentlig, saa du kan lave en gyldig besked i dine egne proever.

## Fejl

Alt der gaar galt er en exception under `VerifyID\Exception\VerifyIDException`:

| exception | hvornaar |
|---|---|
| `ApiException` | API'et svarede med en fejl. `getKode()` er den maskinlaesbare kode, `getStatus()` HTTP-status. |
| `TransportException` | kaldet naaede ikke frem, eller svaret var ikke JSON |
| `SignatureException` | en webhook kunne ikke verificeres |
| `ConfigurationException` | tom noegle, eller ingen HTTP-klient |

Koder du kan moede: `ugyldig_noegle`, `ingen_domaener`, `ugyldig_krop`, `ukendt_skabelon`, `findes_ikke` (`isNotFound()`), `kontrakt_ikke_faerdig` (`isContractNotReady()`), `for_mange_kald` (`isRateLimited()`), `modul_mangler`, `noegle_genbrugt`. Den fulde liste og hvert felt staar i API-dokumentationen paa `https://kyc.verifyid.dk/api-dokumentation`.

```php
try {
    $client->contracts()->createFromTemplate($data);
} catch (ApiException $e) {
    $e->getKode();      // 'ukendt_skabelon'
    $e->getMessage();   // beskeden fra API'et
}
```

## Egen HTTP-klient

Klienten kraever en PSR-18-klient og PSR-17-fabrikker. Uden Guzzle, eller hvis du vil styre timeouts og proxy selv, giver du alle tre med:

```php
$client = new Client($key, [
    'http_client' => $psr18,
    'request_factory' => $psr17,
    'stream_factory' => $psr17,
]);
```

Symfony: `symfony/http-client` + `nyholm/psr7`. Laravel: Guzzle er der allerede. Fabrikkerne fra `nyholm/psr7` (`Psr17Factory`) og `guzzlehttp/psr7` (`HttpFactory`) daekker begge roller.

## Udvikling

```bash
composer install
vendor/bin/phpunit
```

Proeverne bruger en falsk PSR-18-klient og gaar ikke paa nettet. De tjekker headers, stier og kroppe paa det der sendes, og at fejlsvar og signaturer behandles som dokumentationen siger.

## Licens

MIT. Se [LICENSE](LICENSE).
