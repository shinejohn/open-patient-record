<?php

declare(strict_types=1);

namespace Opr\Gateway;

use RuntimeException;

/**
 * Commits verified entries into an OPR vault through the PUBLIC API — the Gateway
 * is just another conformant Contributor. It holds a redeemed AccessGrant token
 * with phi:write scope; it never touches vault internals. This is the "same
 * rails" commitment in practice: the migration tool uses the exact API a
 * competitor would.
 *
 * Dependency-free (streams HTTP) so the Gateway library has no framework coupling.
 */
final class VaultClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $vaultId,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $entries commit-ready entries from Verification::signOff
     * @return array{committed: int, chain_head_hash: ?string}
     */
    public function commit(array $entries): array
    {
        $committed = 0;
        $chainHead = null;

        foreach ($entries as $entry) {
            $response = $this->post("/api/vaults/{$this->vaultId}/entries", $entry);
            if (($response['status'] ?? 0) !== 201) {
                throw new RuntimeException(
                    "vault rejected entry ({$response['status']}): ".json_encode($response['body']),
                );
            }
            $committed++;
            // Each commit response carries the new chain hash — the last one is the
            // head. (Reading the vault resource needs a subject token; the Gateway
            // holds only a write grant, so we track the head from commit responses.)
            $chainHead = $response['body']['chain_hash'] ?? $chainHead;
        }

        return ['committed' => $committed, 'chain_head_hash' => $chainHead];
    }

    /** @param array<string, mixed> $body @return array{status: int, body: mixed} */
    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /** @return array{status: int, body: mixed} */
    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /** @return array{status: int, body: mixed} */
    private function request(string $method, string $path, ?array $body): array
    {
        $headers = ['Accept: application/json', "Authorization: Bearer {$this->token}"];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);

        $raw = @file_get_contents(rtrim($this->baseUrl, '/').$path, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#\AHTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $raw === false ? null : json_decode($raw, true)];
    }
}
