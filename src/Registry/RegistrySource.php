<?php

declare(strict_types=1);

namespace Patchline\Registry;

enum RegistrySource
{
    case Remote;
    case Cache;
    case StaleCache;
    case File;
}
