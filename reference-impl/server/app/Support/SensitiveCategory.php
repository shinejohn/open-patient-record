<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical sensitive-category slugs (spec §3.2). An entry with ANY non-null
 * category — including one not on this list — is excluded from grants unless the
 * grant explicitly includes that exact category. Unknown never means shareable.
 */
final class SensitiveCategory
{
    public const KNOWN = [
        '42_cfr_part_2',
        'mental_health',
        'hiv',
        'reproductive',
        'genetic',
    ];
}
