<?php
/**
 * Send en kontrakt til underskrift fra en skabelon, og hent PDF'en når den er færdig.
 *
 *   VERIFYID_KEY=din-testnøgle php examples/kontrakt.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VerifyID\Client;
use VerifyID\Exception\ApiException;

$client = Client::test(getenv('VERIFYID_KEY') ?: '');

/* Hvilke skabeloner har jeg, og hvilke variabler skal de have? */
foreach ($client->contracts()->templates() as $skabelon) {
    $felter = array_column($skabelon['variabler'], 'nøgle');
    echo $skabelon['skabelonId'], '  ', $skabelon['navn'], '  variabler: ', implode(', ', $felter), PHP_EOL;
}

$skabelonId = $argv[1] ?? null;
if ($skabelonId === null) {
    echo PHP_EOL, 'Giv et skabelon-id som argument for at sende den.', PHP_EOL;
    exit;
}

/* Send den. Idempotency-Key gør et genforsøg ufarligt: samme nøgle = samme kontrakt. */
$kontrakt = $client->contracts()->createFromTemplate([
    'skabelonId' => $skabelonId,
    'parter' => [
        ['navn' => 'Anna Berg', 'email' => 'anna@eksempel.dk', 'rolle' => 'underskriver'],
    ],
    'variabler' => ['virksomhed' => 'Eksempel ApS'],
    'levering' => 'link',                       // 'email' hvis VerifyID skal sende invitationen
    'reference' => 'ordre-7',
    'webhook' => 'https://din-side.dk/verifyid/webhook',  // skal være https og på nøglens domæneliste
], 'ordre-7-kontrakt');

echo PHP_EOL, 'Kontrakt ', $kontrakt['kontraktId'], ' (', $kontrakt['status'], ')', PHP_EOL;
foreach ($kontrakt['parter'] as $part) {
    echo '  ', $part['navn'], ': ', $part['url'], PHP_EOL;   // send linket til parten selv
}

/* Egen PDF i stedet for en skabelon: */
// $kontrakt = $client->contracts()->createFromDocument('aftale.pdf', file_get_contents('aftale.pdf'), [
//     'parter' => [['navn' => 'Anna Berg', 'email' => 'anna@eksempel.dk']],
//     'levering' => 'email',
// ]);

/* Den underskrevne PDF findes først når alle har skrevet under; før det svarer API'et 409. */
try {
    file_put_contents('underskrevet.pdf', $client->contracts()->pdf($kontrakt['kontraktId']));
    echo 'PDF gemt som underskrevet.pdf', PHP_EOL;
} catch (ApiException $e) {
    if ($e->isContractNotReady()) {
        echo 'Ikke færdig endnu: ', $e->getMessage(), PHP_EOL;   // vent på kontrakt.faerdig på webhooken
    } else {
        throw $e;
    }
}
