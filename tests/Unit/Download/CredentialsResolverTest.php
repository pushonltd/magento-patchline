<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Download;

use Patchline\Download\Credentials;
use Patchline\Download\CredentialsResolver;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CredentialsResolver::class)]
#[UsesClass(Credentials::class)]
#[UsesClass(LocalFilesystem::class)]
final class CredentialsResolverTest extends TestCase
{
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function test_it_reads_credentials_from_composer_auth_before_auth_json_files(): void
    {
        $this->authFile('root/auth.json', 'file-user');

        $credentials = $this->resolve(['COMPOSER_AUTH' => $this->json('env-user')]);

        self::assertSame('Basic ' . base64_encode('env-user:fake-pass'), $credentials?->authorizationHeader());
    }

    public function test_it_falls_back_through_the_auth_json_locations_in_order(): void
    {
        $this->authFile('home/.config/composer/auth.json', 'xdg-user');
        $env = ['HOME' => $this->dir->path('home')];
        self::assertSame('Basic ' . base64_encode('xdg-user:fake-pass'), $this->resolve($env)?->authorizationHeader());

        $this->authFile('home/.composer/auth.json', 'dot-user');
        self::assertSame('Basic ' . base64_encode('dot-user:fake-pass'), $this->resolve($env)?->authorizationHeader());

        $this->authFile('ch/auth.json', 'ch-user');
        $env['COMPOSER_HOME'] = $this->dir->path('ch');
        self::assertSame('Basic ' . base64_encode('ch-user:fake-pass'), $this->resolve($env)?->authorizationHeader());

        $this->authFile('root/auth.json', 'root-user');
        self::assertSame('Basic ' . base64_encode('root-user:fake-pass'), $this->resolve($env)?->authorizationHeader());
    }

    public function test_it_skips_sources_without_repo_magento_com_credentials(): void
    {
        $this->authFile('root/auth.json', 'x', 'github.com');
        file_put_contents($this->dir->path('root/other'), '');

        self::assertNull($this->resolve(['COMPOSER_AUTH' => 'not json']));
    }

    /**
     * @param array<string, string> $env
     */
    private function resolve(array $env): ?\Patchline\Download\Credentials
    {
        if (!is_dir($this->dir->path('root'))) {
            mkdir($this->dir->path('root'));
        }

        return (new CredentialsResolver(new LocalFilesystem()))->resolve($this->dir->path('root'), $env);
    }

    private function authFile(string $relative, string $user, string $host = 'repo.magento.com'): void
    {
        $path = $this->dir->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $this->json($user, $host));
    }

    private function json(string $user, string $host = 'repo.magento.com'): string
    {
        return json_encode(
            ['http-basic' => [$host => ['username' => $user, 'password' => 'fake-pass']]],
            JSON_THROW_ON_ERROR,
        );
    }
}
