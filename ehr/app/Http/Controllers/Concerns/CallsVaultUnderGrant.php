<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Patient;
use App\Services\VaultBridge;
use App\Services\VaultRejected;

/**
 * Derived vault tokens expire ~30 minutes after redemption; the durable
 * authority is the GRANT. Every controller call under a patient's grant goes
 * through here: on 401/403 the grant secret is re-redeemed for a fresh token
 * (persisted) and the call retried exactly once. A revoked/expired/exhausted
 * grant still fails — the patient's kill switch must stick.
 */
trait CallsVaultUnderGrant
{
    /**
     * @template T
     * @param \Closure(string): T $call receives a valid grant token
     * @return T
     */
    protected function underGrant(VaultBridge $vault, Patient $patient, \Closure $call)
    {
        try {
            return $call($patient->grant_token);
        } catch (VaultRejected $e) {
            if (! in_array($e->status, [401, 403], true) || $patient->grant_otp === null) {
                throw $e;
            }

            $fresh = $vault->redeemGrant($patient->grant_pseudo_id, $patient->grant_otp);
            $patient->update(['grant_token' => $fresh]);

            return $call($fresh);
        }
    }
}
