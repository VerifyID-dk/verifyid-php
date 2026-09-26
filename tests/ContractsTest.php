<?php

declare(strict_types=1);

namespace VerifyID\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use VerifyID\Client;

final class ContractsTest extends TestCase
{
    private FakeHttp $http;

    private function client(): Client
    {
        $this->http = new FakeHttp();
        $factory = new Psr17Factory();

        return new Client('k', ['http_client' => $this->http, 'request_factory' => $factory, 'stream_factory' => $factory]);
    }

    public function testCreateFromTemplateSenderIdempotencyKey(): void
    {
        $client = $this->client();
        $this->http->json(201, ['ok' => true, 'kontraktId' => 'k1', 'parter' => [['partId' => 'p1', 'url' => 'https://kyc.verifyid.dk/underskriv/t']]]);

        $svar = $client->contracts()->createFromTemplate([
            'skabelonId' => 's1',
            'parter' => [['navn' => 'Anna Berg', 'email' => 'anna@eksempel.dk']],
            'levering' => 'link',
        ], 'ordre-7-kontrakt');

        $req = $this->http->sidste();
        self::assertSame('ordre-7-kontrakt', $req->getHeaderLine('Idempotency-Key'));
        self::assertSame('/api/v1/kontrakter', $req->getUri()->getPath());
        self::assertSame('k1', $svar['kontraktId']);
        self::assertSame('https://kyc.verifyid.dk/underskriv/t', $svar['parter'][0]['url']);
    }

    public function testUdenNoegleSendesIngenIdempotencyHeader(): void
    {
        $client = $this->client();
        $this->http->json(201, ['ok' => true]);

        $client->contracts()->createFromTemplate(['skabelonId' => 's1', 'parter' => [['navn' => 'A']]]);

        self::assertFalse($this->http->sidste()->hasHeader('Idempotency-Key'));
    }

    public function testEnForLangIdempotencyKeyAfvisesFoerKaldet(): void
    {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);
        $client->contracts()->createFromTemplate(['skabelonId' => 's1', 'parter' => []], str_repeat('a', 201));
        self::assertSame([], $this->http->sendt);
    }

    public function testGetPdfCancelOgSkabelonerRammerDeRigtigeStier(): void
    {
        $client = $this->client();
        $this->http
            ->json(200, ['ok' => true, 'kontraktId' => 'k1', 'status' => 'afventer'])
            ->svarMed(200, '%PDF-1.7', ['Content-Type' => 'application/pdf'])
            ->json(200, ['ok' => true, 'status' => 'annulleret'])
            ->json(200, ['ok' => true, 'skabeloner' => [['skabelonId' => 's1', 'navn' => 'Databehandleraftale']]])
            ->json(200, ['ok' => true, 'skabelonId' => 's1', 'variabler' => []]);

        self::assertSame('afventer', $client->contracts()->get('k1')['status']);
        self::assertSame('GET', $this->http->sidste()->getMethod());
        self::assertSame('/api/v1/kontrakter/k1', $this->http->sidste()->getUri()->getPath());

        self::assertSame('%PDF-1.7', $client->contracts()->pdf('k1'));
        self::assertSame('/api/v1/kontrakter/k1/pdf', $this->http->sidste()->getUri()->getPath());
        self::assertSame('application/pdf', $this->http->sidste()->getHeaderLine('Accept'));

        self::assertSame('annulleret', $client->contracts()->cancel('k1')['status']);
        self::assertSame('POST', $this->http->sidste()->getMethod());
        self::assertSame('/api/v1/kontrakter/k1/annuller', $this->http->sidste()->getUri()->getPath());

        $skabeloner = $client->contracts()->templates();
        self::assertCount(1, $skabeloner);
        self::assertSame('Databehandleraftale', $skabeloner[0]['navn']);

        self::assertSame('s1', $client->contracts()->template('s1')['skabelonId']);
        self::assertSame('/api/v1/kontrakt-skabeloner/s1', $this->http->sidste()->getUri()->getPath());
    }

    public function testCreateFromDocumentErMultipartMedIdempotencyKey(): void
    {
        $client = $this->client();
        $this->http->json(201, ['ok' => true, 'kontraktId' => 'k3']);

        $svar = $client->contracts()->createFromDocument('aftale.pdf', '%PDF-1.7', ['parter' => [['navn' => 'A']]], 'noegle-1');

        $req = $this->http->sidste();
        self::assertSame('k3', $svar['kontraktId']);
        self::assertSame('/api/v1/kontrakter/dokument', $req->getUri()->getPath());
        self::assertSame('noegle-1', $req->getHeaderLine('Idempotency-Key'));
        self::assertStringStartsWith('multipart/form-data; boundary=', $req->getHeaderLine('Content-Type'));
        self::assertStringContainsString('filename="aftale.pdf"', (string) $req->getBody());
    }
}
