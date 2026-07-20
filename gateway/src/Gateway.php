<?php

declare(strict_types=1);

namespace Opr\Gateway;

use Opr\Gateway\Parser\CcdaParser;
use Opr\Gateway\Parser\FhirBundleParser;
use Opr\Gateway\Parser\Parser;
use RuntimeException;

/**
 * Gateway G0 entry point: classify a document, dispatch to the deterministic
 * parser, return candidates + completeness accounting. Unknown/unsupported
 * documents (PDFs, scans, free text) are honestly reported as needing manual
 * entry — G0 fabricates nothing (build plan §G0.2, AI extraction is G1).
 */
final class Gateway
{
    /** @var list<Parser> */
    private array $parsers;

    public function __construct(?array $parsers = null)
    {
        $this->parsers = $parsers ?? [new FhirBundleParser(), new CcdaParser()];
    }

    public function ingest(string $content): IngestionResult
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($content)) {
                return $parser->parse($content);
            }
        }

        // Honest boundary: a document we can't deterministically parse is a work
        // item, not a silent success and not a fabricated extraction.
        $result = new IngestionResult();
        $result->classification = 'needs-manual-entry';
        $result->sourceSha256 = hash('sha256', $content);

        return $result;
    }

    public function ingestFile(string $path): IngestionResult
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("cannot read {$path}");
        }

        return $this->ingest($content);
    }
}
