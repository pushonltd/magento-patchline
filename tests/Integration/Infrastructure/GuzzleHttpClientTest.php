<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Infrastructure;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\GuzzleHttpClient;
use Patchline\Infrastructure\HttpResponse;
use Patchline\PackageVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GuzzleHttpClient::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(PackageVersion::class)]
final class GuzzleHttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$port = self::freePort();
        $command = [
            PHP_BINARY, '-S', '127.0.0.1:' . self::$port,
            dirname(__DIR__, 2) . '/Fixtures/http/router.php',
        ];
        $null = ['file', '/dev/null', 'w'];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes);
        self::assertIsResource($process);
        self::$server = $process;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        self::fail('Test HTTP server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server === null) {
            return;
        }

        proc_terminate(self::$server);
        proc_close(self::$server);
        self::$server = null;
    }

    public function test_it_returns_status_body_and_final_url_for_an_http_get(): void
    {
        $url = $this->url('/ok');

        $response = (new GuzzleHttpClient())->get($url);

        self::assertSame(200, $response->status);
        self::assertSame('hello', $response->body);
        self::assertSame($url, $response->finalUrl);
    }

    public function test_it_returns_the_body_of_a_401_response_instead_of_failing(): void
    {
        $response = (new GuzzleHttpClient())->get($this->url('/unauthorized'));

        self::assertSame(401, $response->status);
        self::assertSame('denied', $response->body);
    }

    public function test_it_sends_the_patchline_user_agent(): void
    {
        $response = (new GuzzleHttpClient())->get($this->url('/echo-ua'));

        self::assertSame('patchline/' . PackageVersion::current(), $response->body);
    }

    public function test_it_sends_the_given_headers(): void
    {
        $response = (new GuzzleHttpClient())->get($this->url('/echo-auth'), ['Authorization' => 'Basic abc']);

        self::assertSame('Basic abc', $response->body);
    }

    public function test_it_keeps_the_authorization_header_on_a_redirect_within_the_same_host(): void
    {
        $response = (new GuzzleHttpClient())->get($this->url('/redirect-same-host'), ['Authorization' => 'Basic abc']);

        self::assertSame('Basic abc', $response->body);
        self::assertSame($this->url('/echo-auth'), $response->finalUrl);
    }

    public function test_it_drops_the_authorization_header_when_a_redirect_leaves_the_original_host(): void
    {
        $response = (new GuzzleHttpClient())->get($this->url('/redirect-other-host'), ['Authorization' => 'Basic abc']);

        self::assertSame('none', $response->body);
        self::assertStringStartsWith('http://localhost:', $response->finalUrl);
    }

    public function test_it_gives_up_after_too_many_redirects(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('/redirect-loop');

        (new GuzzleHttpClient())->get($this->url('/redirect-loop'));
    }

    public function test_it_throws_a_runtime_failure_naming_the_url_when_the_connection_fails(): void
    {
        $url = 'http://127.0.0.1:' . self::freePort() . '/registry.json';

        try {
            (new GuzzleHttpClient(2))->get($url, ['Authorization' => 'Basic secret']);
            self::fail('Expected RuntimeFailure');
        } catch (RuntimeFailure $e) {
            self::assertStringContainsString($url, $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
