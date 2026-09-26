<?php
/**
 * Send en kontrakt til underskrift fra en skabelon, og hent PDF'en naar den er faerdig.
 *
 *   VERIFYID_KEY=din-testnoegle php examples/kontrakt.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VerifyID\Client;
use VerifyID\Exception\ApiException;

$client = Client::test(getenv('VERIFYID_KEY') ?: '');

/* Hvilke skabeloner har jeg, og hvilke variabler skal de have? */
foreach ($client->contracts()->templates() as $skabelon) {
    $noegler = array_column($skabelon['variabler'], 'noegle');
    echo $skabelon['skabelonId'], '  ', $skabelon['navn'], '  variabler: ', implode(', ', $noegler), PHP_EOL;
}

$skabelonId = $argv[1] ?? null;
if ($skabelonId === null) {
    echo PHP_EOL, 'Giv et skabelon-id som argument for at sende den.', PHP_EOL;
    exit;
}

/* Send den. Idempotency-Key goer et genforsoeg ufarligt: samme noegle = samme kontrakt. */
$kontrakt = $client->contracts()->createFromTemplate([
    'skabelonId' => $skabelonId,
    'parter' => [
        ['navn' => 'Anna Berg', 'email' => 'anna@eksempel.dk', 'rolle' => 'underskriver'],
    ],
    'variabler' => ['virksomhed' => 'Eksempel ApS'],
    'levering' => 'link',                       // 'email' hvis VerifyID skal sende invitationen
    'reference' => 'ordre-7',
    'webhook' => 'https://din-side.dk/verifyid/webhook',  // skal vaere https og paa noeglens domaeneliste
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

/* Den underskrevne PDF findes foerst naar alle har skrevet under; foer det svarer API'et 409. */
try {
    file_put_contents('underskrevet.pdf', $client->contracts()->pdf($kontrakt['kontraktId']));
    echo 'PDF gemt som underskrevet.pdf', PHP_EOL;
} catch (ApiException $e) {
    if ($e->isContractNotReady()) {
        echo 'Ikke faerdig endnu: ', $e->getMessage(), PHP_EOL;   // vent paa kontrakt.faerdig paa webhooken
    } else {
        throw $e;
    }
}
