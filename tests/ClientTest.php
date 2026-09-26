<?php

declare(strict_types=1);

namespace VerifyID\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use VerifyID\Client;
use VerifyID\Exception\ApiException;
use VerifyID\Exception\ConfigurationException;
use VerifyID\Exception\TransportException;

final class ClientTest extends TestCase
{
    private FakeHttp $http;

    private function client(string $key = 'noegle-123', array $options = []): Client
    {
        $this->http = new FakeHttp();
        $factory = new Psr17Factory();

        return new Client($key, [
            'http_client' => $this->http,
            'request_factory' => $factory,
            'stream_factory' => $factory,
        ] + $options);
    }

    public function testNoeglenSendesSomBearerOgKroppenSomJson(): void
    {
        $client = $this->client();
        $this->http->json(201, ['ok' => true, 'version' => '1', 'kontraktId' => 'k1']);

        $svar = $client->request('POST', '/api/v1/kontrakter', ['skabelonId' => 'æøå', 'parter' => []]);

        $req = $this->http->sidste();
        self::assertSame('Bearer noegle-123', $req->getHeaderLine('Authorization'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertSame('https://kyc.verifyid.dk/api/v1/kontrakter', (string) $req->getUri());
        self::assertStringContainsString('verifyid-php/', $req->getHeaderLine('User-Agent'));
        /* Unicode og skraastreger sendes som de er, ikke som \u-koder */
        self::assertSame('{"skabelonId":"æøå","parter":[]}', (string) $req->getBody());
        self::assertSame('k1', $svar['kontraktId']);
    }

    public function testTestmiljoeetHarSinEgenAdresse(): void
    {
        $this->http = new FakeHttp();
        $factory = new Psr17Factory();
        $client = Client::test('t', ['http_client' => $this->http, 'request_factory' => $factory, 'stream_factory' => $factory]);

        self::assertSame('https://kyctesting.verifyid.dk', $client->getBaseUrl());
    }

    public function testEnFejlFraApietBliverTilApiExceptionMedKode(): void
    {
        $client = $this->client();
        $this->http->json(404, ['ok' => false, 'version' => '1', 'fejl' => ['kode' => 'findes_ikke', 'besked' => 'There is no contract with that id.']]);

        try {
            $client->request('GET', '/api/v1/kontrakter/x');
            self::fail('der blev ikke kastet');
        } catch (ApiException $e) {
            self::assertSame('findes_ikke', $e->getKode());
            self::assertSame(404, $e->getStatus());
            self::assertTrue($e->isNotFound());
            self::assertSame('There is no contract with that id.', $e->getMessage());
        }
    }

    public function testEnFejlUdenJsonKropBliverStadigTilApiException(): void
    {
        $client = $this->client();
        $this->http->svarMed(502, '<html>Bad gateway</html>', ['Content-Type' => 'text/html']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('502');
        $client->request('GET', '/api/v1/kontrakter');
    }

    public function testEtSvarDerIkkeErJsonErEnTransportfejl(): void
    {
        $client = $this->client();
        $this->http->svarMed(200, 'ikke json');

        $this->expectException(TransportException::class);
        $client->request('GET', '/api/v1/kontrakter');
    }

    public function testEnTomNoegleAfvisesMedDetSamme(): void
    {
        $this->expectException(ConfigurationException::class);
        new Client('   ');
    }

    public function testHalvtGivneFabrikkerAfvises(): void
    {
        $this->expectException(ConfigurationException::class);
        new Client('n', ['http_client' => new FakeHttp()]);
    }

    public function testRawGiverBytesOgLaeserFejlSomJson(): void
    {
        $client = $this->client();
        $this->http->svarMed(200, '%PDF-1.7 ...', ['Content-Type' => 'application/pdf']);
        self::assertSame('%PDF-1.7 ...', $client->requestRaw('GET', '/api/v1/kontrakter/k/pdf'));

        $this->http->json(409, ['ok' => false, 'fejl' => ['kode' => 'kontrakt_ikke_faerdig', 'besked' => 'Not yet.']]);
        try {
            $client->requestRaw('GET', '/api/v1/kontrakter/k/pdf');
            self::fail('der blev ikke kastet');
        } catch (ApiException $e) {
            self::assertTrue($e->isContractNotReady());
            self::assertSame(409, $e->getStatus());
        }
    }

    public function testMultipartHarBaadeDataOgFilen(): void
    {
        $client = $this->client();
        $this->http->json(201, ['ok' => true, 'kontraktId' => 'k2']);

        $client->requestMultipart('/api/v1/kontrakter/dokument', 'fil', 'aftale "x".pdf', '%PDF', 'application/pdf', ['parter' => [['navn' => 'A']]]);

        $req = $this->http->sidste();
        $ct = $req->getHeaderLine('Content-Type');
        self::assertStringStartsWith('multipart/form-data; boundary=verifyid-', $ct);
        $boundary = substr($ct, strlen('multipart/form-data; boundary='));
        $body = (string) $req->getBody();
        self::assertStringContainsString('--' . $boundary . "\r\nContent-Disposition: form-data; name=\"data\"", $body);
        self::assertStringContainsString('{"parter":[{"navn":"A"}]}', $body);
        self::assertStringContainsString('name="fil"; filename="aftale x.pdf"', $body);
        self::assertStringContainsString("Content-Type: application/pdf\r\n\r\n%PDF\r\n", $body);
        self::assertStringEndsWith('--' . $boundary . "--\r\n", $body);
    }
}
