<?php

declare(strict_types=1);

namespace VerifyID;

use VerifyID\Exception\ApiException;
use VerifyID\Exception\TransportException;

/**
 * eID-verifikation: "log ind med MitID" paa din egen side.
 *
 * Forloebet er tre skridt, og din server staar for det foerste og det sidste:
 *
 *   1. `startLogin()` opretter et opslag og giver en URL. Send personen derhen.
 *   2. Personen bekraefter hos MitID og kommer tilbage til din `redirect_url`
 *      med `?lookup_id=<id>` paa adressen.
 *   3. `result()` henter udfaldet server-til-server med din noegle. Adressen
 *      i browseren beviser ingenting; det goer kun svaret fra API'et.
 *
 * Hvor meget du faar at vide, afgoeres af `scope`:
 *
 *   status      kun at personen ER bekraeftet, ingen felter
 *   alder_over  om personen er over `age_limit`, ikke hvor gammel
 *   alder       foedselsdato
 *   basis       navn og foedselsdato
 *   fuld        alt udbyderen giver
 *
 * Se tabellen i API-dokumentationen. Bed kun om det du skal bruge.
 */
final class Eid
{
    /** Navnet paa parameteren personen kommer tilbage med paa din redirect_url. */
    public const LOOKUP_ID_PARAMETER = 'lookup_id';

    public const STATUS_STARTED = 'paabegyndt';
    public const STATUS_COMPLETED = 'gennemfoert';
    public const STATUS_CANCELLED = 'afbrudt';
    public const STATUS_EXPIRED = 'udloebet';

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Starter et login: opretter et eID-opslag og giver adressen personen skal sendes til.
     *
     * @param string $redirectUrl  Hvor personen lander bagefter. Vaerten skal staa paa noeglens domaeneliste.
     * @param array{
     *     method?: string,
     *     scope?: string,
     *     age_limit?: int,
     *     cancel_url?: string,
     *     reference?: string
     * } $options  `method` er `mitid` som standard, `scope` er `basis`.
     * @return array{id: string, url: string, expires_at: string, scope: string, method: string, reference?: string}
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function startLogin(string $redirectUrl, array $options = []): array
    {
        $payload = [
            'method' => $options['method'] ?? 'mitid',
            'scope' => $options['scope'] ?? 'basis',
            'redirect_url' => $redirectUrl,
        ];

        foreach (['age_limit', 'cancel_url', 'reference'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null && $options[$key] !== '') {
                $payload[$key] = $options[$key];
            }
        }

        return $this->start($payload);
    }

    /**
     * Opretter et opslag med praecis den krop du selv bygger. `startLogin()` er den nemme vej.
     *
     * @param array<string, mixed> $payload  `method` og `scope` er paakraevede.
     * @return array<string, mixed>  Feltet `lookup` fra svaret: id, url, expires_at, scope, method, reference.
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function start(array $payload): array
    {
        $svar = $this->client->request('POST', '/api/v1/eid', $payload);

        return is_array($svar['lookup'] ?? null) ? $svar['lookup'] : [];
    }

    /**
     * Laeser opslagets id af adressen personen kom tilbage paa. Null naar det mangler.
     *
     * @param array<string, mixed> $query  Typisk `$_GET`.
     */
    public static function lookupIdFromQuery(array $query): ?string
    {
        $id = $query[self::LOOKUP_ID_PARAMETER] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Henter udfaldet af et opslag.
     *
     * @return array<string, mixed>  Feltet `lookup`: id, status, method, scope, age_limit, reference,
     *                               result (null indtil gennemfoert, og altid null ved scope `status`),
     *                               reason, created_at, expires_at, completed_at.
     *
     * @throws ApiException  `findes_ikke` naar id'et ikke er dit eller ikke findes.
     * @throws TransportException
     */
    public function result(string $lookupId): array
    {
        $svar = $this->client->request('GET', '/api/v1/eid/' . rawurlencode($lookupId));

        return is_array($svar['lookup'] ?? null) ? $svar['lookup'] : [];
    }

    /** Sandt naar personen er bekraeftet. Det er det ENE der betyder "logget ind". */
    public static function isCompleted(array $lookup): bool
    {
        return ($lookup['status'] ?? null) === self::STATUS_COMPLETED;
    }

    /** Sandt naar personen fortroed, eller udbyderen sagde nej. Ikke en fejl hos dig. */
    public static function isCancelled(array $lookup): bool
    {
        return ($lookup['status'] ?? null) === self::STATUS_CANCELLED;
    }
}
