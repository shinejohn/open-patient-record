<?php

declare(strict_types=1);

namespace Opr\Gateway\Parser;

use Opr\Gateway\IngestionResult;

interface Parser
{
    /** True if this parser recognizes the document. */
    public function supports(string $content): bool;

    public function parse(string $content): IngestionResult;

    public function classification(): string;
}
