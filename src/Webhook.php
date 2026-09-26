<?php

declare(strict_types=1);

namespace VerifyID;

use VerifyID\Exception\SignatureException;

/**
 * Verificering af webhooks fra VerifyID.
 *
 * Hver besked er signeret med DIN API-noegle. Der er ingen anden hemmelighed
 * at udveksle, og en anden konto kan ikke verificere en besked der ikke er
 * deres. Tre headers:
 *
 *   X-VerifyID-Signatur   HMAC-SHA256 som hex
 *   X-VerifyID-Tid        Unix-tidsstempel i sekunder, en del af det signerede
 *   X-VerifyID-Haendelse  haendelsen, den samme som `haendelse` i kroppen
 *
 * Signaturen regnes som
 *
 *     HMAC-SHA256(tid . "." . krop, din-noegle)
 *
 * over den RAA krop, byte for byte. Parser du JSON foerst og signerer det igen,
 * passer signaturen ikke: feltraekkefoelge og mellemrum er en del af det
 * signerede. Laes derfor `php://input` og giv den ubehandlet videre.
 *
 * Beskeder aeldre end 300 sekunder afvises. Uden den graense kan en opsnappet
 * besked sendes igen senere. Den samme besked KAN komme mere end een gang (fx
 * hvis dit 2xx ikke naaede os), saa brug id'et og haendelsen sammen som noegle
 * og spring en gentagelse over.
 *
 * Svar 2xx med det samme, og goer arbejdet bagefter. Et langsomt svar udloeser
 * et nyt forsoeg; vi proever fem gange med stigende mellemrum.
 */
final class Webhook
{
    public const HEADER_SIGNATURE = 'X-VerifyID-Signatur';
    public const HEADER_TIMESTAMP = 'X-VerifyID-Tid';
    public const HEADER_EVENT = 'X-VerifyID-Haendelse';

    /** Saa gammel maa en besked vaere, i sekunder. Det samme tal som API-dokumentationen. */
    public const DEFAULT_TOLERANCE = 300;

    public function __construct(
        private readonly string $apiKey,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE,
    ) {
    }

    /**
     * Verificerer en besked og giver kroppen tilbage som array.
     *
     * @param string                $rawBody  Den raa krop, fx `file_get_contents('php://input')`.
     * @param array<string, mixed>  $headers  Headers med navn => vaerdi. Navne sammenlignes uden
     *                                        hensyn til store og smaa bogstaver, og en vaerdi maa
     *                                        vaere en liste (som PSR-7 giver den).
     * @param int|null              $now      Nuvaerende tid i sekunder. Kun til proever.
     * @return array<string, mixed>  Kroppen afkodet. `haendelse` siger hvad der skete.
     *
     * @throws SignatureException  naar en header mangler, tiden er for gammel, eller signaturen ikke passer.
     */
    public function verify(string $rawBody, array $headers, ?int $now = null): array
    {
        $signature = self::header($headers, self::HEADER_SIGNATURE);
        $timestamp = self::header($headers, self::HEADER_TIMESTAMP);

        if ($signature === null || $timestamp === null) {
            throw new SignatureException(
                'Beskeden mangler ' . self::HEADER_SIGNATURE . ' eller ' . self::HEADER_TIMESTAMP . '.',
            );
        }

        if (!ctype_digit($timestamp)) {
            throw new SignatureException(self::HEADER_TIMESTAMP . ' er ikke et tidsstempel: ' . $timestamp);
        }

        $tid = (int) $timestamp;
        $nu = $now ?? time();

        if (abs($nu - $tid) > $this->toleranceSeconds) {
            throw new SignatureException(
                'Beskeden er ' . abs($nu - $tid) . ' sekunder gammel; graensen er ' . $this->toleranceSeconds . '.',
            );
        }

        $expected = self::sign($rawBody, $tid, $this->apiKey);

        if (!hash_equals($expected, strtolower(trim($signature)))) {
            throw new SignatureException('Signaturen passer ikke. Er det den rigtige noegle, og er kroppen raa?');
        }

        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            throw new SignatureException('Signaturen passer, men kroppen er ikke JSON.');
        }

        return $decoded;
    }

    /**
     * Den nemme vej i et almindeligt PHP-endpoint: laeser `php://input` og request-headers selv.
     *
     * @return array<string, mixed>
     *
     * @throws SignatureException
     */
    public function verifyFromGlobals(): array
    {
        $raw = file_get_contents('php://input');
        $headers = function_exists('getallheaders') ? getallheaders() : self::headersFromServer($_SERVER);

        return $this->verify($raw === false ? '' : $raw, is_array($headers) ? $headers : []);
    }

    /** Regner signaturen ud. Offentlig, saa du kan lave en gyldig besked i dine egne proever. */
    public static function sign(string $rawBody, int $timestamp, string $apiKey): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $apiKey);
    }

    /** @param array<string, mixed> $headers */
    private static function header(array $headers, string $name): ?string
    {
        $wanted = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $wanted) {
                continue;
            }

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    /**
     * Headers fra `$_SERVER` (HTTP_X_VERIFYID_TID -> X-Verifyid-Tid) naar `getallheaders` ikke findes.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_scalar($value)) {
                $navn = str_replace('_', '-', substr((string) $key, 5));
                $headers[$navn] = (string) $value;
            }
        }

        return $headers;
    }
}
