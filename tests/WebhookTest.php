<?php

declare(strict_types=1);

namespace VerifyID\Tests;

use PHPUnit\Framework\TestCase;
use VerifyID\Exception\SignatureException;
use VerifyID\Webhook;

final class WebhookTest extends TestCase
{
    private const NOEGLE = 'min-api-noegle';
    private const KROP = '{"haendelse":"kontrakt.faerdig","kontrakt":"3cd4215e-e189-4bf4-850b-28994b6b4c4c","status":"underskrevet"}';

    /** @return array<string, string> */
    private function headers(int $tid, ?string $signatur = null): array
    {
        return [
            'X-VerifyID-Signatur' => $signatur ?? Webhook::sign(self::KROP, $tid, self::NOEGLE),
            'X-VerifyID-Tid' => (string) $tid,
            'X-VerifyID-Haendelse' => 'kontrakt.faerdig',
        ];
    }

    public function testSignaturenRegnesSomTidPunktumKropMedNoeglen(): void
    {
        /* Regnet i haanden med samme formel som serveren: HMAC-SHA256("1700000000." . krop, noegle) */
        self::assertSame(
            hash_hmac('sha256', '1700000000.' . self::KROP, self::NOEGLE),
            Webhook::sign(self::KROP, 1700000000, self::NOEGLE),
        );
    }

    public function testEnGyldigBeskedGiverKroppenTilbage(): void
    {
        $webhook = new Webhook(self::NOEGLE);
        $tid = 1700000000;

        $besked = $webhook->verify(self::KROP, $this->headers($tid), $tid + 10);

        self::assertSame('kontrakt.faerdig', $besked['haendelse']);
        self::assertSame('underskrevet', $besked['status']);
    }

    public function testHeadersSammenlignesUdenHensynTilStoreOgSmaaBogstaverOgSomLister(): void
    {
        $webhook = new Webhook(self::NOEGLE);
        $tid = 1700000000;
        $headers = [
            'x-verifyid-signatur' => [Webhook::sign(self::KROP, $tid, self::NOEGLE)],
            'X-VERIFYID-TID' => [(string) $tid],
        ];

        self::assertSame('kontrakt.faerdig', $webhook->verify(self::KROP, $headers, $tid)['haendelse']);
    }

    public function testEnForkertNoegleAfvises(): void
    {
        $webhook = new Webhook('en-anden-noegle');
        $tid = 1700000000;

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Signaturen passer ikke');
        $webhook->verify(self::KROP, $this->headers($tid), $tid);
    }

    public function testEnAendretKropAfvises(): void
    {
        $webhook = new Webhook(self::NOEGLE);
        $tid = 1700000000;

        $this->expectException(SignatureException::class);
        $webhook->verify(str_replace('underskrevet', 'afvist', self::KROP), $this->headers($tid), $tid);
    }

    public function testEnGammelBeskedAfvisesOgsaaMedRigtigSignatur(): void
    {
        $webhook = new Webhook(self::NOEGLE);
        $tid = 1700000000;

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('301 sekunder gammel');
        $webhook->verify(self::KROP, $this->headers($tid), $tid + 301);
    }

    public function testGraensenKanSaettesOgGaelderBeggeVeje(): void
    {
        $webhook = new Webhook(self::NOEGLE, 60);
        $tid = 1700000000;

        self::assertIsArray($webhook->verify(self::KROP, $this->headers($tid), $tid + 60));
        self::assertIsArray($webhook->verify(self::KROP, $this->headers($tid), $tid - 60));

        $this->expectException(SignatureException::class);
        $webhook->verify(self::KROP, $this->headers($tid), $tid - 61);
    }

    public function testManglendeHeadersAfvisesFoerNogetRegnes(): void
    {
        $webhook = new Webhook(self::NOEGLE);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('mangler');
        $webhook->verify(self::KROP, ['X-VerifyID-Haendelse' => 'kontrakt.faerdig']);
    }

    public function testEtTidsstempelDerIkkeErEtTalAfvises(): void
    {
        $webhook = new Webhook(self::NOEGLE);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('ikke et tidsstempel');
        $webhook->verify(self::KROP, ['X-VerifyID-Signatur' => 'abc', 'X-VerifyID-Tid' => '17e9']);
    }
}
