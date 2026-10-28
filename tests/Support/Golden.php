<?php

declare(strict_types=1);

namespace Patchline\Tests\Support;

use RuntimeException;

/**
 * Golden files under tests/Fixtures/output, compared byte for byte.
 * Run with PATCHLINE_UPDATE_GOLDEN=1 to rewrite them, then review the diff.
 */
final class Golden
{
    /** Returns the expected content of golden file $name; rewrites it from $actual when updating. */
    public static function expected(string $name, string $actual): string
    {
        $path = __DIR__ . '/../Fixtures/output/' . $name;
        if (getenv('PATCHLINE_UPDATE_GOLDEN') === '1') {
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
                throw new RuntimeException('Cannot create ' . dirname($path));
            }
            file_put_contents($path, $actual);

            return $actual;
        }
        if (!is_file($path)) {
            throw new RuntimeException(
                sprintf('Golden file %s is missing. Run with PATCHLINE_UPDATE_GOLDEN=1.', $name),
            );
        }

        return (string) file_get_contents($path);
    }
}
