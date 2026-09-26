<?php
/**
 * "Log ind med MitID" paa din egen side, i to endpoints.
 *
 *   /login/start   starter et opslag og sender personen til MitID
 *   /login/retur   personen kommer tilbage hertil; vi henter udfaldet med noeglen
 *
 * Koer den lokalt med den indbyggede server og en testnoegle fra kyctesting.verifyid.dk:
 *
 *   VERIFYID_KEY=din-testnoegle php -S localhost:8080 examples/login-med-mitid.php
 *
 * Testnoeglens domaeneliste skal indeholde `localhost` (eller den vaert du bruger),
 * ellers svarer API'et `ingen_domaener` paa redirect_url.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VerifyID\Client;
use VerifyID\Eid;
use VerifyID\Exception\ApiException;

session_start();

$client = Client::test(getenv('VERIFYID_KEY') ?: '');
$retur = 'http://localhost:8080/login/retur';
$sti = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($sti === '/login/start') {
    /* Trin 1: opret opslaget og send personen afsted */
    $lookup = $client->eid()->startLogin($retur, [
        'scope' => 'basis',                 // navn og foedselsdato; brug 'status' hvis du kun skal vide at det er en rigtig person
        'reference' => session_id(),        // dit eget id, kommer med tilbage paa opslaget og i webhooken
    ]);

    $_SESSION['lookup_id'] = $lookup['id'];  // gem id'et, saa retur-siden kun godtager DETTE opslag
    header('Location: ' . $lookup['url'], true, 302);
    exit;
}

if ($sti === '/login/retur') {
    /* Trin 3: adressen beviser intet. Hent udfaldet server-til-server. */
    $id = Eid::lookupIdFromQuery($_GET);

    if ($id === null || $id !== ($_SESSION['lookup_id'] ?? null)) {
        http_response_code(400);
        exit('Ukendt opslag.');
    }

    try {
        $lookup = $client->eid()->result($id);
    } catch (ApiException $e) {
        http_response_code(502);
        exit('VerifyID svarede ' . $e->getKode() . ': ' . $e->getMessage());
    }

    if (!Eid::isCompleted($lookup)) {
        /* afbrudt, udloebet eller stadig paabegyndt */
        exit('Login blev ikke gennemfoert (' . $lookup['status'] . '). <a href="/login/start">Proev igen</a>');
    }

    /* Personen er bekraeftet. Med scope basis staar navn og foedselsdato i result. */
    $_SESSION['bruger'] = $lookup['result'];
    unset($_SESSION['lookup_id']);

    exit('Velkommen, ' . htmlspecialchars($lookup['result']['navn'] ?? 'bekraeftet person'));
}

echo '<a href="/login/start">Log ind med MitID</a>';
