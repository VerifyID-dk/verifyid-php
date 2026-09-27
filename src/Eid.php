<?php

declare(strict_types=1);

namespace VerifyID;

use VerifyID\Exception\ApiException;
use VerifyID\Exception\TransportException;

/**
 * eID-verifikation: "log ind med MitID" på din egen side.
 *
 * Forløbet er tre skridt, og din server står for det første og det sidste:
 *
 *   1. `startLogin()` opretter et opslag og giver en URL. Send personen derhen.
 *   2. Personen bekræfter hos MitID og kommer tilbage til din `redirect_url`
 *      med `?lookup_id=<id>` på adressen.
 *   3. `result()` henter udfaldet server-til-server med din nøgle. Adressen
 *      i browseren beviser ingenting; det gør kun svaret fra API'et.
 *
 * Hvor meget du får at vide, afgøres af `scope`:
 *
 *   status      kun at personen ER bekræftet, ingen felter
 *   alder_over  om personen er over `age_limit`, ikke hvor gammel
 *   alder       fødselsdato
 *   basis       navn og fødselsdato
 *   fuld        alt udbyderen giver
 *
 * Se tabellen i API-dokumentationen. Bed kun om det du skal bruge.
 */
final class Eid
{
    /** Navnet på parameteren personen kommer tilbage med på din redirect_url. */
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
     * @param string $redirectUrl  Hvor personen lander bagefter. Skal være en absolut https-adresse uden #fragment.
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
     * Opretter et opslag med præcis den krop du selv bygger. `startLogin()` er den nemme vej.
     *
     * @param array<string, mixed> $payload  `method` og `scope` er påkrævede.
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
     * Læser opslagets id af adressen personen kom tilbage på. Null når det mangler.
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
     *                               result (null indtil gennemført, og altid null ved scope `status`),
     *                               reason, created_at, expires_at, completed_at.
     *
     * @throws ApiException  `findes_ikke` når id'et ikke er dit eller ikke findes.
     * @throws TransportException
     */
    public function result(string $lookupId): array
    {
        $svar = $this->client->request('GET', '/api/v1/eid/' . rawurlencode($lookupId));

        return is_array($svar['lookup'] ?? null) ? $svar['lookup'] : [];
    }

    /** Sandt når personen er bekræftet. Det er det ENE der betyder "logget ind". */
    public static function isCompleted(array $lookup): bool
    {
        return ($lookup['status'] ?? null) === self::STATUS_COMPLETED;
    }

    /** Sandt når personen fortrød, eller udbyderen sagde nej. Ikke en fejl hos dig. */
    public static function isCancelled(array $lookup): bool
    {
        return ($lookup['status'] ?? null) === self::STATUS_CANCELLED;
    }
}
