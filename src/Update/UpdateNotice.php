<?php

declare(strict_types=1);

namespace Patchline\Update;

use Patchline\Cli\Output\GithubFormatter;
use Patchline\Cli\OutputFormat;

/**
 * Renders the "a newer version is available" message with the update command that fits the installation.
 */
final class UpdateNotice
{
    private const INSTALL_SCRIPT = 'curl -fsSL '
        . 'https://github.com/pushonltd/magento-patchline/releases/latest/download/install.sh | bash';

    /**
     * @param string|null $pharPath absolute path of the running PHAR, null for a Composer install
     * @param bool $devRequirement whether a Composer install lists patchline in require-dev
     */
    public static function render(
        string $current,
        string $latest,
        ?string $pharPath,
        string $cwd,
        bool $devRequirement,
        OutputFormat $format,
    ): string {
        $message = sprintf(
            'patchline %s is available (you have %s). Update with: %s',
            $latest,
            $current,
            self::command($latest, $pharPath, $cwd, $devRequirement),
        );

        if ($format === OutputFormat::Github) {
            return GithubFormatter::command('notice', ['title' => 'Patchline update'], $message) . "\n";
        }

        return $message . "\n";
    }

    private static function command(string $latest, ?string $pharPath, string $cwd, bool $devRequirement): string
    {
        if ($pharPath === null) {
            return 'composer require ' . ($devRequirement ? '--dev ' : '')
                . 'pushonltd/magento-patchline:^' . $latest;
        }
        if ($pharPath === rtrim($cwd, '/') . '/patchline.phar') {
            return self::INSTALL_SCRIPT;
        }

        return self::INSTALL_SCRIPT . ' -s -- --path=' . escapeshellarg($pharPath);
    }
}
