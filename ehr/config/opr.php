<?php

declare(strict_types=1);

return [
    /*
     * Base URL of the OPR vault server this EHR is a client of. The EHR talks
     * to the vault ONLY over its public HTTP API — the same-rails commitment:
     * no shared database, no private surface.
     */
    'vault_base_url' => env('OPR_VAULT_BASE_URL', 'http://localhost:8000'),

    'http_timeout_seconds' => (int) env('OPR_HTTP_TIMEOUT', 15),
];
