<?php

declare(strict_types=1);

namespace Opr\Gateway\Parser;

/**
 * Deterministic sensitive-category classification (build plan §G0.3, spec §3.2).
 * Keyword/code lists only in G0 — AI-assisted classification is G1 and gated by a
 * BAA. Conservative by construction: when in doubt, tag sensitive. An unknown or
 * ambiguous entry is never silently treated as shareable downstream.
 */
final class SensitiveClassifier
{
    /** @var array<string, list<string>> category => lowercase substrings */
    private const KEYWORDS = [
        '42_cfr_part_2' => ['substance use', 'substance abuse', 'opioid use disorder', 'buprenorphine', 'methadone', 'naltrexone', 'alcohol use disorder', 'suboxone'],
        'mental_health' => ['psychotherapy', 'major depressive', 'bipolar', 'schizophrenia', 'ptsd', 'anxiety disorder', 'psychiatric'],
        'hiv' => ['hiv', 'aids', 'antiretroviral', 'human immunodeficiency'],
        'reproductive' => ['pregnan', 'contracept', 'abortion', 'sti ', 'sexually transmitted', 'gynecolog'],
        'genetic' => ['genetic test', 'brca', 'genomic', 'hereditary', 'karyotype'],
    ];

    /** @param array<string, mixed> $resource */
    public static function classify(string $domain, array $resource): ?string
    {
        $haystack = strtolower(self::textOf($resource));
        if ($haystack === '') {
            return null;
        }

        foreach (self::KEYWORDS as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $resource */
    public static function classifyText(string $text): ?string
    {
        return self::classify('', ['code' => ['text' => $text]]);
    }

    /** @param array<string, mixed> $resource */
    private static function textOf(array $resource): string
    {
        $parts = [];
        array_walk_recursive($resource, function ($value, $key) use (&$parts): void {
            if (is_string($value) && in_array($key, ['text', 'display', 'value'], true)) {
                $parts[] = $value;
            }
        });

        return implode(' ', $parts);
    }
}
