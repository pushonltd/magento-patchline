<?php

declare(strict_types=1);

namespace Patchline\CrossCheck;

/**
 * One patch the two tools classify differently.
 */
final class Disagreement
{
    /** Adobe's lists do not mention the patch. */
    public const NOT_LISTED = 'not_listed';

    /**
     * @param string $patchline DetectionState value
     * @param string $adobe `applied`, `not_applied`, `unknown` or {@see self::NOT_LISTED}
     */
    public function __construct(
        public readonly string $id,
        public readonly string $patchline,
        public readonly string $adobe,
    ) {
    }

    public function describe(): string
    {
        return sprintf(
            '%s: Patchline detects %s, Adobe patch-status reports %s.',
            $this->id,
            $this->patchline,
            $this->adobe,
        );
    }
}
