<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Download;

use Patchline\Download\Credentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Credentials::class)]
final class CredentialsTest extends TestCase
{
    public function test_it_never_reveals_the_secret_when_credentials_are_printed_or_dumped(): void
    {
        $credentials = new Credentials('public-user', 'fake-secret-123');

        $outputs = [
            (string) $credentials,
            var_export($credentials, true),
            print_r($credentials, true),
            json_encode($credentials, JSON_THROW_ON_ERROR),
        ];
        ob_start();
        var_dump($credentials);
        $outputs[] = (string) ob_get_clean();

        foreach ($outputs as $output) {
            self::assertStringNotContainsString('fake-secret-123', $output);
            self::assertStringNotContainsString(base64_encode('public-user:fake-secret-123'), $output);
        }
    }

    public function test_it_builds_a_basic_authorization_header(): void
    {
        self::assertSame(
            'Basic ' . base64_encode('u:p'),
            (new Credentials('u', 'p'))->authorizationHeader(),
        );
    }
}
