<?php

declare(strict_types=1);

namespace Opr\Gateway;

/**
 * One extracted candidate clinical fact, ready for human verification before it
 * becomes a vault entry. Mirrors the gateway_candidates model in the build plan:
 * carries its source provenance, proposed FHIR payload, terminology coding and
 * how that coding was derived, and a confidence route that decides how it may be
 * reviewed.
 */
final class Candidate
{
    public const DOMAIN_MEDICATION = 'medication';
    public const DOMAIN_ALLERGY = 'allergy';
    public const DOMAIN_PROBLEM = 'problem';
    public const DOMAIN_IMMUNIZATION = 'immunization';
    public const DOMAIN_RESULT = 'result';

    // How the terminology coding was obtained (never "ai-suggested" in G0).
    public const CODING_SOURCE = 'source-coded';   // code present in the source, validated
    public const CODING_CROSSWALK = 'crosswalk';   // deterministically crosswalked (e.g. NDC→RxNorm)
    public const CODING_STRING = 'string-match';   // exact local terminology match
    public const CODING_NONE = 'uncoded';          // text only — never force-coded

    // Confidence route governs how a candidate may be reviewed (build plan §G0.4).
    public const ROUTE_DETERMINISTIC = 'deterministic'; // batch-verifiable
    public const ROUTE_UNRESOLVED = 'unresolved';       // needs manual entry / attention

    public string $disposition = 'pending'; // pending | accepted | edited | rejected

    /**
     * @param array<string, mixed> $payload proposed FHIR R4 resource
     * @param array<string, mixed> $provenance source doc + span + method
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $resourceType,
        public array $payload,
        public readonly string $codingSource,
        public readonly string $route,
        public readonly array $provenance,
        public readonly ?string $sensitiveCategory = null,
        public readonly string $verificationTier = 'unverified-import',
    ) {
    }

    public function isDeterministic(): bool
    {
        return $this->route === self::ROUTE_DETERMINISTIC;
    }
}
