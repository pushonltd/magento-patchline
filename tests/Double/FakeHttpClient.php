<?php

declare(strict_types=1);

namespace Patchline\Tests\Double;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\HttpClient;
use Patchline\Infrastructure\HttpResponse;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $requests = [];

    /** @var array<string, HttpResponse|RuntimeFailure> */
    private array $responses = [];

    public function respond(string $url, int $status, string $body = ''): self
    {
        $this->responses[$url] = new HttpResponse($status, $body, $url);

        return $this;
    }

    public function fail(string $url): self
    {
        $this->responses[$url] = new RuntimeFailure('Cannot reach ' . $url . '.');

        return $this;
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        $response = $this->responses[$url] ?? new HttpResponse(404, '', $url);
        if ($response instanceof RuntimeFailure) {
            throw $response;
        }

        return $response;
    }
}
