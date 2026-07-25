<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The vault said no or could not be reached. Callers surface this as an honest
 * 502 — never a fabricated success, never a silently-skipped step.
 */
final class VaultUnreachable extends \RuntimeException
{
}
