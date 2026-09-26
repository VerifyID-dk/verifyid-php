<?php

declare(strict_types=1);

namespace VerifyID\Exception;

/**
 * API'et svarede med en fejl.
 *
 * `getKode()` er den maskinlæsbare kode fra dokumentationen, fx
 * `findes_ikke`, `kontrakt_ikke_faerdig`, `for_mange_kald`, `ingen_domaener`,
 * `noegle_genbrugt`. `getMessage()` er beskeden skrevet til den der læser
 * loggen. `getStatus()` er HTTP-statussen.
 */
final class ApiException extends VerifyIDException
{
    public function __construct(
        private readonly string $kode,
        string $besked,
        private readonly int $status,
        private readonly string $version = '',
    ) {
        parent::__construct($besked, $status);
    }

    public function getKode(): string
    {
        return $this->kode;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /** Ukendt id, eller et der ikke er dit. Svaret er det samme, med vilje. */
    public function isNotFound(): bool
    {
        return $this->kode === 'findes_ikke';
    }

    /** Du kaldte for tit. Vent og prøv igen. */
    public function isRateLimited(): bool
    {
        return $this->kode === 'for_mange_kald';
    }

    /** Aftalen er ikke færdig endnu, eller bliver det aldrig. Beskeden siger hvilket. */
    public function isContractNotReady(): bool
    {
        return $this->kode === 'kontrakt_ikke_faerdig';
    }
}
