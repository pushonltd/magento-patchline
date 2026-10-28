<?php

declare(strict_types=1);

namespace Patchline\Infrastructure;

interface HttpClient
{
    /**
     * @param array<string, string> $headers
     *
     * @throws \Patchline\Exception\RuntimeFailure when the server cannot be reached
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
