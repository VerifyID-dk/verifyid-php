<?php

declare(strict_types=1);

namespace VerifyID\Tests;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * En PSR-18-klient der svarer med det man har lagt i kø, og husker hvad den fik.
 * Prøverne måler på REQUESTS: headers, sti, krop. Ingen netværk.
 */
final class FakeHttp implements ClientInterface
{
    /** @var list<ResponseInterface> */
    private array $svar = [];

    /** @var list<RequestInterface> */
    public array $sendt = [];

    /** @param array<string, string> $headers */
    public function svarMed(int $status, string $body, array $headers = ['Content-Type' => 'application/json']): self
    {
        $this->svar[] = new Response($status, $headers, $body);

        return $this;
    }

    /** @param array<string, mixed> $json */
    public function json(int $status, array $json): self
    {
        return $this->svarMed($status, json_encode($json, JSON_THROW_ON_ERROR));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sendt[] = $request;

        if ($this->svar === []) {
            throw new \LogicException('FakeHttp har ikke flere svar i kø: ' . $request->getMethod() . ' ' . $request->getUri());
        }

        return array_shift($this->svar);
    }

    public function sidste(): RequestInterface
    {
        return $this->sendt[count($this->sendt) - 1];
    }
}
