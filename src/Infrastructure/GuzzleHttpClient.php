<?php

declare(strict_types=1);

namespace Patchline\Infrastructure;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Patchline\Exception\RuntimeFailure;
use Patchline\PackageVersion;

final class GuzzleHttpClient implements HttpClient
{
    private const MAX_REDIRECTS = 5;
    private const REDIRECT_HISTORY_HEADER = 'X-Guzzle-Redirect-History';

    private readonly Client $client;

    public function __construct(int $timeoutSeconds = 10)
    {
        $this->client = new Client([
            RequestOptions::TIMEOUT => $timeoutSeconds,
            RequestOptions::CONNECT_TIMEOUT => $timeoutSeconds,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => self::MAX_REDIRECTS,
                'track_redirects' => true,
                'referer' => false,
            ],
        ]);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        try {
            $response = $this->client->request('GET', $url, [
                RequestOptions::HEADERS => ['User-Agent' => 'patchline/' . PackageVersion::current()] + $headers,
            ]);
        } catch (TransferException) {
            throw new RuntimeFailure(sprintf(
                'Cannot reach %s. Check your network connection and the URL.',
                self::display($url),
            ));
        }

        $history = $response->getHeader(self::REDIRECT_HISTORY_HEADER);

        return new HttpResponse(
            $response->getStatusCode(),
            (string) $response->getBody(),
            $history === [] ? $url : $history[array_key_last($history)],
        );
    }

    private static function display(string $url): string
    {
        return preg_replace('#//[^/@]*@#', '//', $url) ?? $url;
    }
}
