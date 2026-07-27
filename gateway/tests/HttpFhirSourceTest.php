<?php

declare(strict_types=1);

namespace Opr\Gateway\Tests;

use Opr\Gateway\Sync\HttpFhirSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpFhirSourceTest extends TestCase
{
    public function test_capabilities_parses_capability_statement(): void
    {
        $source = new class ('http://ehr.example', 'tok') extends HttpFhirSource {
            protected function httpGet(string $url): array
            {
                if (str_ends_with($url, '/metadata')) {
                    return ['status' => 200, 'body' => [
                        'resourceType' => 'CapabilityStatement',
                        'rest' => [
                            ['resource' => [
                                ['type' => 'Condition'],
                                ['type' => 'Observation'],
                            ]],
                        ],
                    ]];
                }

                return ['status' => 404, 'body' => null];
            }
        };

        $this->assertSame(['Condition', 'Observation'], $source->capabilities());
    }

    public function test_capabilities_never_throws_on_failure(): void
    {
        $source = new class ('http://ehr.example', 'tok') extends HttpFhirSource {
            protected function httpGet(string $url): array
            {
                return ['status' => 500, 'body' => null];
            }
        };

        $this->assertSame([], $source->capabilities());
    }

    public function test_fetch_includes_last_updated_param_when_since_given(): void
    {
        $seenUrls = [];
        $source = new class ('http://ehr.example', 'tok', 30, $seenUrls) extends HttpFhirSource {
            /** @param list<string> $seenUrls */
            public function __construct(string $baseUrl, string $bearerToken, int $timeoutSeconds, private array &$seenUrls)
            {
                parent::__construct($baseUrl, $bearerToken, $timeoutSeconds);
            }

            protected function httpGet(string $url): array
            {
                $this->seenUrls[] = $url;

                return ['status' => 200, 'body' => ['resourceType' => 'Bundle', 'type' => 'searchset', 'entry' => []]];
            }
        };

        $source->fetch('Condition', '2026-01-01T00:00:00Z');

        $this->assertStringContainsString('_lastUpdated=ge2026-01-01T00%3A00%3A00Z', $seenUrls[0] ?? '');
    }

    public function test_fetch_omits_last_updated_param_when_since_null(): void
    {
        $seenUrls = [];
        $source = new class ('http://ehr.example', 'tok', 30, $seenUrls) extends HttpFhirSource {
            /** @param list<string> $seenUrls */
            public function __construct(string $baseUrl, string $bearerToken, int $timeoutSeconds, private array &$seenUrls)
            {
                parent::__construct($baseUrl, $bearerToken, $timeoutSeconds);
            }

            protected function httpGet(string $url): array
            {
                $this->seenUrls[] = $url;

                return ['status' => 200, 'body' => ['resourceType' => 'Bundle', 'type' => 'searchset', 'entry' => []]];
            }
        };

        $source->fetch('Condition', null);

        $this->assertStringNotContainsString('_lastUpdated', $seenUrls[0] ?? '');
    }

    public function test_fetch_follows_pagination_and_appends_entries(): void
    {
        $source = new class ('http://ehr.example', 'tok') extends HttpFhirSource {
            protected function httpGet(string $url): array
            {
                if (str_contains($url, 'page2')) {
                    return ['status' => 200, 'body' => [
                        'resourceType' => 'Bundle',
                        'type' => 'searchset',
                        'entry' => [
                            ['resource' => ['resourceType' => 'Condition', 'id' => 'c2']],
                        ],
                    ]];
                }

                return ['status' => 200, 'body' => [
                    'resourceType' => 'Bundle',
                    'type' => 'searchset',
                    'entry' => [
                        ['resource' => ['resourceType' => 'Condition', 'id' => 'c1']],
                    ],
                    'link' => [
                        ['relation' => 'next', 'url' => 'http://ehr.example/Condition?page2'],
                    ],
                ]];
            }
        };

        $resources = $source->fetch('Condition', null);

        $this->assertCount(2, $resources);
        $this->assertSame('c1', $resources[0]['id']);
        $this->assertSame('c2', $resources[1]['id']);
    }

    public function test_fetch_throws_on_non_200_status(): void
    {
        $source = new class ('http://ehr.example', 'tok') extends HttpFhirSource {
            protected function httpGet(string $url): array
            {
                return ['status' => 503, 'body' => null];
            }
        };

        $this->expectException(RuntimeException::class);
        $source->fetch('Condition', null);
    }

    public function test_fetch_throws_on_unparseable_body(): void
    {
        $source = new class ('http://ehr.example', 'tok') extends HttpFhirSource {
            protected function httpGet(string $url): array
            {
                return ['status' => 200, 'body' => null];
            }
        };

        $this->expectException(RuntimeException::class);
        $source->fetch('Condition', null);
    }

    public function test_fetch_throws_when_pagination_cap_exceeded(): void
    {
        $source = new class ('http://ehr.example', 'tok') extends HttpFhirSource {
            protected function httpGet(string $url): array
            {
                // Always returns a 'next' link -> infinite pagination.
                return ['status' => 200, 'body' => [
                    'resourceType' => 'Bundle',
                    'type' => 'searchset',
                    'entry' => [
                        ['resource' => ['resourceType' => 'Condition', 'id' => uniqid('c', true)]],
                    ],
                    'link' => [
                        ['relation' => 'next', 'url' => 'http://ehr.example/Condition?next='.uniqid()],
                    ],
                ]];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pagination cap/i');
        $source->fetch('Condition', null);
    }
}
