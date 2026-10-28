<?php

declare(strict_types=1);

namespace Patchline\Build;

use RuntimeException;

/**
 * Builds dist/patchline.phar with Box from a clean staging copy that only has the runtime dependencies.
 *
 * Staging keeps phpunit, phpstan and friends out of the PHAR whatever the working copy has installed, and resolves
 * the libraries as Composer would for PHP 8.1, so the PHAR runs on the lowest supported PHP.
 */
final class PharBuild
{
    private const PHAR = 'patchline.phar';

    public function __construct(private readonly string $root)
    {
    }

    public static function normaliseVersion(string $version): string
    {
        $version = ltrim(trim($version), 'v');
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/', $version) !== 1) {
            throw new RuntimeException(sprintf('"%s" is not a usable version string.', $version));
        }

        return $version;
    }

    public function describeVersion(): string
    {
        $described = shell_exec('git -C ' . escapeshellarg($this->root) . ' describe --tags --always 2>/dev/null');

        return is_string($described) && trim($described) !== '' ? trim($described) : '0.0.0-dev';
    }

    /**
     * @return string the path of the PHAR
     */
    public function build(string $version, string $outputDirectory): string
    {
        $version = self::normaliseVersion($version);
        $stage = sys_get_temp_dir() . '/patchline-phar-' . bin2hex(random_bytes(6));

        try {
            return $this->buildFrom($stage, $version, $outputDirectory);
        } finally {
            $this->removeTree($stage);
        }
    }

    private function buildFrom(string $stage, string $version, string $outputDirectory): string
    {
        $this->stage($stage, $version);
        $this->run(
            'composer update --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader',
            $stage,
        );
        $this->run(
            sprintf(
                '%s -d phar.readonly=0 %s compile --no-interaction --config=box.json',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($this->root . '/tools/box/vendor/bin/box'),
            ),
            $stage,
        );

        return $this->publish($stage . '/dist/' . self::PHAR, $outputDirectory);
    }

    private function stage(string $stage, string $version): void
    {
        $this->copyTree($this->root . '/src', $stage . '/src');
        $this->copyFile($this->root . '/bin/patchline', $stage . '/bin/patchline');
        $this->copyFile($this->root . '/box.json.dist', $stage . '/box.json');
        $this->write($stage . '/composer.json', $this->runtimeComposerJson());
        $versionFile = $stage . '/src/PackageVersion.php';
        $this->write($versionFile, $this->withVersion($versionFile, $version));
    }

    private function runtimeComposerJson(): string
    {
        $contents = file_get_contents($this->root . '/composer.json');
        $composer = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($composer) || !isset($composer['require'], $composer['autoload'])) {
            throw new RuntimeException('composer.json has no "require" and "autoload" sections.');
        }

        return json_encode([
            'name' => $composer['name'] ?? 'pushonltd/magento-patchline',
            'require' => $composer['require'],
            'autoload' => $composer['autoload'],
            'config' => ['sort-packages' => true, 'platform' => ['php' => '8.1.0']],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function withVersion(string $file, string $version): string
    {
        $code = file_get_contents($file);
        $stamped = is_string($code)
            ? preg_replace("/(const VERSION = )'[^']*';/", "\${1}'" . $version . "';", $code, 1, $count)
            : null;
        if ($stamped === null || $count !== 1) {
            throw new RuntimeException($file . ' has no single-line VERSION constant to replace.');
        }

        return $stamped;
    }

    private function publish(string $phar, string $outputDirectory): string
    {
        if (!is_file($phar)) {
            throw new RuntimeException('Box did not produce ' . $phar);
        }
        $target = $outputDirectory . '/' . self::PHAR;
        $this->ensureDirectory($outputDirectory);
        if (!copy($phar, $target) || !chmod($target, 0755)) {
            throw new RuntimeException('Cannot write ' . $target);
        }
        $this->write($target . '.sha256', hash_file('sha256', $target) . '  ' . self::PHAR . "\n");

        return $target;
    }

    private function run(string $command, string $directory): void
    {
        passthru('cd ' . escapeshellarg($directory) . ' && ' . $command . ' 1>&2', $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf('"%s" failed with exit code %d.', $command, $exitCode));
        }
    }

    private function copyTree(string $from, string $to): void
    {
        $this->ensureDirectory($to);
        foreach (array_diff((array) scandir($from), ['.', '..']) as $entry) {
            is_dir($from . '/' . $entry)
                ? $this->copyTree($from . '/' . $entry, $to . '/' . $entry)
                : $this->copyFile($from . '/' . $entry, $to . '/' . $entry);
        }
    }

    private function copyFile(string $from, string $to): void
    {
        $this->ensureDirectory(dirname($to));
        if (!copy($from, $to)) {
            throw new RuntimeException('Cannot copy ' . $from);
        }
    }

    private function write(string $path, string $contents): void
    {
        $this->ensureDirectory(dirname($path));
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Cannot write ' . $path);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
