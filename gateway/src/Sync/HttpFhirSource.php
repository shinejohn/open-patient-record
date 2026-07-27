<?php

declare(strict_types=1);

namespace Opr\Gateway\Sync;

use RuntimeException;

/**
 * Live FhirSource over an incumbent EHR's FHIR R4 API. Dependency-free
 * (streams HTTP), matching VaultClient's pattern — the gateway library ships
 * no HTTP client library.
 *
 * Fails loudly: any non-200 response or unparseable body throws, and pull()
 * (SyncEngine) treats a failed fetch as a failed run — nothing is recorded,
 * the checkpoint is not advanced. capabilities() is the one exception: it
 * never throws, since it is advisory (used to decide what to attempt), not a
 * correctness boundary.
 */
class HttpFhirSource implements FhirSource
{
    /** Defensive cap on pages per fetch() call — a runaway 'next' link must
     * fail loudly rather than loop forever or silently truncate. */
    private const MAX_PAGES = 100;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $bearerToken,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function capabilities(): array
    {
        try {
            $response = $this->httpGet(rtrim($this->baseUrl, '/').'/metadata');
        } catch (\Throwable) {
            return [];
        }

        if (($response['status'] ?? 0) !== 200 || ! is_array($response['body'] ?? null)) {
            return [];
        }

        $types = [];
        foreach ($response['body']['rest'] ?? [] as $rest) {
            foreach ($rest['resource'] ?? [] as $resourceEntry) {
                $type = $resourceEntry['type'] ?? null;
                if (is_string($type) && ! in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    public function fetch(string $type, ?string $since): array
    {
        $url = rtrim($this->baseUrl, '/')."/{$type}";
        if ($since !== null) {
            $url .= '?_lastUpdated=ge'.rawurlencode($since);
        }

        $resources = [];
        $page = 0;

        while ($url !== null) {
            $page++;
            if ($page > self::MAX_PAGES) {
                throw new RuntimeException(
                    "HttpFhirSource: pagination cap ({".self::MAX_PAGES."} pages) exceeded fetching {$type}; refusing to continue (would silently truncate).",
                );
            }

            $response = $this->httpGet($url);
            $status = $response['status'] ?? 0;
            $body = $response['body'] ?? null;

            if ($status !== 200) {
                throw new RuntimeException("HttpFhirSource: GET {$url} returned status {$status}");
            }
            if (! is_array($body)) {
                throw new RuntimeException("HttpFhirSource: GET {$url} returned unparseable body");
            }

            foreach ($body['entry'] ?? [] as $entry) {
                $resource = $entry['resource'] ?? null;
                if (is_array($resource)) {
                    $resources[] = $resource;
                }
            }

            $url = null;
            foreach ($body['link'] ?? [] as $link) {
                if (($link['relation'] ?? null) === 'next' && is_string($link['url'] ?? null)) {
                    $url = $link['url'];
                    break;
                }
            }
        }

        return $resources;
    }

    /**
     * Issues the raw HTTP GET. Extracted so tests can stub network I/O without
     * sockets (an anonymous subclass overrides this and returns canned pages).
     *
     * @return array{status: int, body: mixed}
     */
    protected function httpGet(string $url): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", [
                'Accept: application/fhir+json, application/json',
                "Authorization: Bearer {$this->bearerToken}",
            ]),
            'ignore_errors' => true,
            'timeout' => $this->timeoutSeconds,
        ]]);

        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#\AHTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $raw === false ? null : json_decode($raw, true)];
    }
}
