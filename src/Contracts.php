<?php

declare(strict_types=1);

namespace VerifyID;

use VerifyID\Exception\ApiException;
use VerifyID\Exception\TransportException;

/**
 * Kontrakter til digital underskrift.
 *
 * To veje ind: en skabelon fra portalen (`createFromTemplate`) eller din egen
 * PDF (`createFromDocument`). Begge giver et kontrakt-id og parterne tilbage;
 * med `levering: link` faar du underskriftslinkene selv og sender dem, med
 * `levering: email` sender vi.
 *
 * Udfaldet kommer paa webhooken (`kontrakt.part.underskrevet`,
 * `kontrakt.part.afvist`, `kontrakt.faerdig`, ...), og den faerdige PDF med
 * underskriftssiden hentes med `pdf()`. Se `Webhook` for signaturen.
 */
final class Contracts
{
    public const STATUS_PENDING = 'afventer';
    public const STATUS_SIGNED = 'underskrevet';
    public const STATUS_DECLINED = 'afvist';
    public const STATUS_CANCELLED = 'annulleret';
    public const STATUS_EXPIRED = 'udloebet';

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Sender en skabelon til underskrift.
     *
     * @param array{
     *     skabelonId: string,
     *     parter: list<array{navn: string, email?: string, rolle?: string, partsnavn?: string, metode?: string, sprog?: string}>,
     *     variabler?: array<string, string|int|float>,
     *     levering?: 'link'|'email',
     *     udloeberDage?: int,
     *     titel?: string,
     *     webhook?: string,
     *     reference?: string
     * } $data
     * @param string|null $idempotencyKey  Din egen noegle for kaldet, hoejst 200 tegn. Sender du det samme
     *                                     kald igen med den samme noegle, faar du det samme svar og ikke
     *                                     en kontrakt mere. Brug den ved genforsoeg.
     * @return array<string, mixed>  kontraktId, titel, status, udloeber, reference, parter[], sendtTil
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function createFromTemplate(array $data, ?string $idempotencyKey = null): array
    {
        return $this->client->request('POST', '/api/v1/kontrakter', $data, self::idempotency($idempotencyKey));
    }

    /**
     * Sender dit eget dokument (PDF) til underskrift.
     *
     * @param string               $fileName   Filnavnet, fx `aftale.pdf`.
     * @param string               $fileBytes  Filens indhold.
     * @param array<string, mixed> $data       `parter` er paakraevet; `levering`, `udloeberDage`, `titel`,
     *                                         `webhook`, `reference` som paa skabelonen.
     * @return array<string, mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function createFromDocument(
        string $fileName,
        string $fileBytes,
        array $data,
        ?string $idempotencyKey = null,
        string $mimeType = 'application/pdf',
    ): array {
        return $this->client->requestMultipart(
            '/api/v1/kontrakter/dokument',
            'fil',
            $fileName,
            $fileBytes,
            $mimeType,
            $data,
            self::idempotency($idempotencyKey),
        );
    }

    /**
     * Status paa en kontrakt: hvem har skrevet under, og med hvilket navn.
     *
     * `parter[].underskrevetNavn` er navnet eID bekraeftede, og det er det der
     * staar paa underskriften i PDF'en. `parter[].navn` er det du sendte.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException  `findes_ikke` naar kontrakten ikke er din.
     * @throws TransportException
     */
    public function get(string $contractId): array
    {
        return $this->client->request('GET', '/api/v1/kontrakter/' . rawurlencode($contractId));
    }

    /**
     * Den underskrevne PDF med underskriftssiden. Findes foerst naar alle har skrevet under.
     *
     * @return string  PDF-bytes.
     *
     * @throws ApiException  `kontrakt_ikke_faerdig` mens der mangler underskrifter, eller naar aftalen er
     *                       afvist eller udloebet og aldrig bliver faerdig. Beskeden siger hvilket.
     * @throws TransportException
     */
    public function pdf(string $contractId): string
    {
        return $this->client->requestRaw('GET', '/api/v1/kontrakter/' . rawurlencode($contractId) . '/pdf', [
            'Accept' => 'application/pdf',
        ]);
    }

    /**
     * Traekker en aftale tilbage. Parterne kan ikke skrive under bagefter, og webhooken faar `kontrakt.annulleret`.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function cancel(string $contractId): array
    {
        return $this->client->request('POST', '/api/v1/kontrakter/' . rawurlencode($contractId) . '/annuller', []);
    }

    /**
     * De skabeloner du kan sende: dine egne og VerifyIDs.
     *
     * @return list<array<string, mixed>>  skabelonId, navn, beskrivelse, ejer, status, fristDage, variabler[], eksempel
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function templates(): array
    {
        $svar = $this->client->request('GET', '/api/v1/kontrakt-skabeloner');

        return is_array($svar['skabeloner'] ?? null) ? array_values($svar['skabeloner']) : [];
    }

    /**
     * En skabelons felter, saa du ved hvad `variabler` skal indeholde.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function template(string $templateId): array
    {
        return $this->client->request('GET', '/api/v1/kontrakt-skabeloner/' . rawurlencode($templateId));
    }

    /** @return array<string, string> */
    private static function idempotency(?string $key): array
    {
        if ($key === null || $key === '') {
            return [];
        }

        if (strlen($key) > 200) {
            throw new \InvalidArgumentException('Idempotency-Key maa hoejst vaere 200 tegn.');
        }

        return ['Idempotency-Key' => $key];
    }
}
