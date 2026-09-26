<?php

declare(strict_types=1);

namespace VerifyID\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use VerifyID\Client;
use VerifyID\Eid;

final class EidTest extends TestCase
{
    private FakeHttp $http;

    private function client(): Client
    {
        $this->http = new FakeHttp();
        $factory = new Psr17Factory();

        return new Client('k', ['http_client' => $this->http, 'request_factory' => $factory, 'stream_factory' => $factory]);
    }

    public function testStartLoginSenderMitidBasisOgRedirectOgGiverLookupTilbage(): void
    {
        $client = $this->client();
        $this->http->json(201, ['ok' => true, 'version' => '1', 'lookup' => [
            'id' => '11111111-1111-4111-8111-111111111111',
            'url' => 'https://kyc.verifyid.dk/eid/start/abc',
            'expires_at' => '2026-09-26T10:00:00Z',
            'scope' => 'basis',
            'method' => 'mitid',
        ]]);

        $lookup = $client->eid()->startLogin('https://kunde.dk/login/retur', ['reference' => 'ordre-7', 'cancel_url' => '']);

        $krop = json_decode((string) $this->http->sidste()->getBody(), true);
        self::assertSame('POST', $this->http->sidste()->getMethod());
        self::assertSame('/api/v1/eid', $this->http->sidste()->getUri()->getPath());
        self::assertSame([
            'method' => 'mitid',
            'scope' => 'basis',
            'redirect_url' => 'https://kunde.dk/login/retur',
            'reference' => 'ordre-7',
        ], $krop, 'tom cancel_url sendes ikke med');
        self::assertSame('https://kyc.verifyid.dk/eid/start/abc', $lookup['url']);
    }

    public function testAgeLimitOgAndetScopeKommerMed(): void
    {
        $client = $this->client();
        $this->http->json(201, ['ok' => true, 'lookup' => ['id' => 'x', 'url' => 'u']]);

        $client->eid()->startLogin('https://kunde.dk/r', ['scope' => 'alder_over', 'age_limit' => 18]);

        $krop = json_decode((string) $this->http->sidste()->getBody(), true);
        self::assertSame('alder_over', $krop['scope']);
        self::assertSame(18, $krop['age_limit']);
    }

    public function testLookupIdLaesesAfAdressen(): void
    {
        self::assertSame('abc', Eid::lookupIdFromQuery(['lookup_id' => 'abc']));
        self::assertNull(Eid::lookupIdFromQuery(['lookup_id' => '']));
        self::assertNull(Eid::lookupIdFromQuery([]));
        self::assertNull(Eid::lookupIdFromQuery(['lookup_id' => ['ikke', 'en', 'streng']]));
    }

    public function testResultHenterOpslagetOgKenderUdfaldet(): void
    {
        $client = $this->client();
        $this->http->json(200, ['ok' => true, 'lookup' => [
            'id' => 'abc', 'status' => 'gennemfoert', 'scope' => 'basis',
            'result' => ['navn' => 'Anna Berg', 'foedselsdato' => '1990-01-01'],
        ]]);

        $lookup = $client->eid()->result('abc');

        self::assertSame('/api/v1/eid/abc', $this->http->sidste()->getUri()->getPath());
        self::assertTrue(Eid::isCompleted($lookup));
        self::assertFalse(Eid::isCancelled($lookup));
        self::assertSame('Anna Berg', $lookup['result']['navn']);

        self::assertTrue(Eid::isCancelled(['status' => 'afbrudt']));
        self::assertFalse(Eid::isCompleted(['status' => 'paabegyndt']));
    }

    public function testIdetUrlKodesIStien(): void
    {
        $client = $this->client();
        $this->http->json(200, ['ok' => true, 'lookup' => []]);

        $client->eid()->result('../x y');

        self::assertSame('/api/v1/eid/..%2Fx%20y', $this->http->sidste()->getUri()->getPath());
    }
}
