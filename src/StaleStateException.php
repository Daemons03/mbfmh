<?php

declare(strict_types=1);

namespace FermeTranquille;

use RuntimeException;

/**
 * The state was written by someone else (another tab/device) since it was read.
 */
final class StaleStateException extends RuntimeException
{
}
