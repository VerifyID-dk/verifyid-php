<?php

declare(strict_types=1);

namespace VerifyID;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use VerifyID\Exception\ApiException;
use VerifyID\Exception\ConfigurationException;
use VerifyID\Exception\TransportException;

/**
 * Klienten mod VerifyIDs API.
 *
 * Alle kald går gennem `request()`, som sætter `Authorization: Bearer`, læser
 * svaret som JSON og laver API'ets fejlsvar om til en `ApiException`, hvor
 * `getKode()` er den maskinlæsbare kode fra dokumentationen.
 *
 * Klienten er bundet til PSR-18 og PSR-17, ikke til et bestemt bibliotek. Er
 * Guzzle installeret, bruges den af sig selv; ellers giver du din egen klient
 * og dine egne fabrikker med i `$options`.
 *
 *     $client = new Client('din-noegle');                 // drift
 *     $client = Client::test('din-testnoegle');           // testmiljoe
 *
 *     $client->eid()->...
 *     $client->contracts()->...
 *     $client->webhook()->verify($body, $headers);
 */
final class Client
{
    public const VERSION = '0.1.0';

    /** Driftsmiljoeet. Noeglen kommer fra portalen under API. */
    public const BASE_URL = 'https://kyc.verifyid.dk';

    /** Testmiljoeet. Testnoegler virker kun her og paa endpoints med proevetilstand. */
    public const TEST_BASE_URL = 'https://kyctesting.verifyid.dk';

    private readonly string $baseUrl;
    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requests;
    private readonly StreamFactoryInterface $streams;

    private ?Eid $eid = null;
    private ?Contracts $contracts = null;
    private ?Webhook $webhook = null;

    /**
     * @param string $apiKey  Din API-noegle. Den hoerer paa serveren, aldrig i browseren.
     * @param array{
     *     base_url?: string,
     *     http_client?: ClientInterface,
     *     request_factory?: RequestFactoryInterface,
     *     stream_factory?: StreamFactoryInterface
     * } $options
     */
    public function __construct(private readonly string $apiKey, array $options = [])
    {
        if (trim($apiKey) === '') {
            throw new ConfigurationException('API-noeglen er tom. Hent den i portalen under API.');
        }

        $this->baseUrl = rtrim($options['base_url'] ?? self::BASE_URL, '/');

        [$http, $requests, $streams] = self::resolveHttp($options);
        $this->http = $http;
        $this->requests = $requests;
        $this->streams = $streams;
    }

    /** En klient mod testmiljoeet, kyctesting.verifyid.dk. */
    public static function test(string $apiKey, array $options = []): self
    {
        return new self($apiKey, ['base_url' => self::TEST_BASE_URL] + $options);
    }

    public function eid(): Eid
    {
        return $this->eid ??= new Eid($this);
    }

    public function contracts(): Contracts
    {
        return $this->contracts ??= new Contracts($this);
    }

    /** Verificering af webhooks. De er signeret med den samme noegle som klienten bruger. */
    public function webhook(): Webhook
    {
        return $this->webhook ??= new Webhook($this->apiKey);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Et JSON-kald. Svaret er det afkodede JSON-objekt som array.
     *
     * @param array<string, mixed>|null $json     Kroppen, eller null for et kald uden krop.
     * @param array<string, string>     $headers  Ekstra headers, fx `Idempotency-Key`.
     * @return array<string, mixed>
     *
     * @throws ApiException       naar API'et svarer med en fejl (`ok: false`)
     * @throws TransportException naar kaldet ikke naar frem eller svaret ikke er JSON
     */
    public function request(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $request = $this->requests->createRequest($method, $this->baseUrl . $path);

        foreach ($this->standardHeaders($headers) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream(self::encode($json)));
        }

        $response = $this->send($request);

        return $this->decode($response);
    }

    /**
     * Et kald hvor svaret er en fil, fx den underskrevne PDF. Bytesene kommer tilbage som streng.
     *
     * @param array<string, string> $headers
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function requestRaw(string $method, string $path, array $headers = []): string
    {
        $request = $this->requests->createRequest($method, $this->baseUrl . $path);

        foreach ($this->standardHeaders($headers) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->send($request);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 200 && $status < 300) {
            return $body;
        }

        /* En fejl kommer altid som JSON, ogsaa paa en filrute */
        throw $this->apiException($status, $body);
    }

    /**
     * Et multipart-kald: en fil plus et JSON-felt `data`. Bruges til dokumenter til underskrift.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function requestMultipart(
        string $path,
        string $fileField,
        string $fileName,
        string $fileBytes,
        string $mimeType,
        array $data,
        array $headers = [],
    ): array {
        $boundary = 'verifyid-' . bin2hex(random_bytes(16));
        $eol = "\r\n";

        $body = '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="data"' . $eol
            . 'Content-Type: application/json' . $eol . $eol
            . self::encode($data) . $eol
            . '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="' . $fileField . '"; filename="' . self::safeFileName($fileName) . '"' . $eol
            . 'Content-Type: ' . $mimeType . $eol . $eol
            . $fileBytes . $eol
            . '--' . $boundary . '--' . $eol;

        $request = $this->requests->createRequest('POST', $this->baseUrl . $path);

        foreach ($this->standardHeaders($headers) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request = $request
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
            ->withBody($this->streams->createStream($body));

        return $this->decode($this->send($request));
    }

    /** @param array<string, string> $extra */
    private function standardHeaders(array $extra): array
    {
        /* De givne headers vinder over standarderne, saa fx Accept kan skiftes til application/pdf */
        return $extra + [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'verifyid-php/' . self::VERSION . ' php/' . PHP_VERSION,
        ];
    }

    private function send(\Psr\Http\Message\RequestInterface $request): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(
                'Kaldet til VerifyID kunne ikke gennemfoeres: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw $this->apiException($status, $body);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new TransportException(
                'VerifyID svarede ' . $status . ', men kroppen er ikke JSON: ' . substr($body, 0, 120),
            );
        }

        return $decoded;
    }

    private function apiException(int $status, string $body): ApiException
    {
        $decoded = json_decode($body, true);
        $fejl = is_array($decoded) && is_array($decoded['fejl'] ?? null) ? $decoded['fejl'] : [];

        return new ApiException(
            kode: (string) ($fejl['kode'] ?? 'ukendt'),
            besked: (string) ($fejl['besked'] ?? ('VerifyID svarede ' . $status . ' uden en fejlbesked.')),
            status: $status,
            version: is_array($decoded) ? (string) ($decoded['version'] ?? '') : '',
        );
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function safeFileName(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '', $name);
    }

    /**
     * Finder HTTP-klient og fabrikker: dem du gav, ellers Guzzle hvis den findes.
     *
     * @param array<string, mixed> $options
     * @return array{0: ClientInterface, 1: RequestFactoryInterface, 2: StreamFactoryInterface}
     */
    private static function resolveHttp(array $options): array
    {
        $http = $options['http_client'] ?? null;
        $requests = $options['request_factory'] ?? null;
        $streams = $options['stream_factory'] ?? null;

        if ($http instanceof ClientInterface
            && $requests instanceof RequestFactoryInterface
            && $streams instanceof StreamFactoryInterface) {
            return [$http, $requests, $streams];
        }

        if (($http !== null || $requests !== null || $streams !== null)) {
            throw new ConfigurationException(
                'Giv enten alle tre (http_client, request_factory, stream_factory) eller ingen af dem.',
            );
        }

        if (class_exists(\GuzzleHttp\Client::class) && class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $factory = new \GuzzleHttp\Psr7\HttpFactory();

            return [new \GuzzleHttp\Client(['http_errors' => false, 'timeout' => 30]), $factory, $factory];
        }

        throw new ConfigurationException(
            'Ingen HTTP-klient. Installer Guzzle (composer require guzzlehttp/guzzle), '
            . 'eller giv en PSR-18-klient og PSR-17-fabrikker med i Client-options.',
        );
    }
}
