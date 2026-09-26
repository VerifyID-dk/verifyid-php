<?php
/**
 * Et webhook-endpoint. Peg VerifyID på den her fil (https, og værten på nøglens domæneliste).
 *
 * Verificer FØR du læser noget i kroppen. Svar 2xx med det samme og gør arbejdet bagefter;
 * et langsomt svar udløser et nyt forsøg, og den samme besked kan komme mere end een gang.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VerifyID\Exception\SignatureException;
use VerifyID\Webhook;

$webhook = new Webhook(getenv('VERIFYID_KEY') ?: '');

try {
    $besked = $webhook->verifyFromGlobals();   // læser php://input og headers selv
} catch (SignatureException $e) {
    http_response_code(400);
    exit($e->getMessage());
}

http_response_code(200);   // kvitter først
fastcgi_finish_request();  // (kun PHP-FPM) slip forbindelsen, arbejd videre

switch ($besked['haendelse']) {
    case 'kontrakt.part.underskrevet':
        // $besked['kontrakt'], $besked['part'], $besked['partStatus'], $besked['reference']
        break;
    case 'kontrakt.faerdig':
        // alle har skrevet under: hent PDF'en med $client->contracts()->pdf($besked['kontrakt'])
        break;
    case 'kontrakt.part.afvist':
    case 'kontrakt.annulleret':
        break;
    case 'verifikation.gennemfoert':
        // et KYC-link er færdigt: $besked['verifikation'] er id'et, $besked['reference'] dit eget
        break;
}
