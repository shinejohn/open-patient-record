<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Encounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E3: fee schedule, superbill invoices from signed encounters, payments.
 * Money is integer cents. An unknown CPT code refuses the whole invoice —
 * a silently zero-priced line is a billing error wearing a default value.
 */
final class BillingController
{
    public function storeFeeItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cpt_code' => ['required', 'string', 'max:8'],
            'description' => ['required', 'string', 'max:255'],
            'price_cents' => ['required', 'integer', 'min:0'],
        ]);

        $id = (string) Str::uuid();
        DB::table('fee_schedule_items')->updateOrInsert(
            ['practice_id' => $request->user()->practice_id, 'cpt_code' => $data['cpt_code']],
            ['id' => $id, 'description' => $data['description'], 'price_cents' => $data['price_cents'],
             'created_at' => now(), 'updated_at' => now()],
        );

        return response()->json(['cpt_code' => $data['cpt_code'], 'price_cents' => $data['price_cents']], 201);
    }

    public function storeInvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'encounter_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.cpt_code' => ['required', 'string', 'max:8'],
            'lines.*.icd10_code' => ['nullable', 'string', 'max:10'],
        ]);

        $practiceId = $request->user()->practice_id;
        $encounter = Encounter::query()->where('practice_id', $practiceId)->whereKey($data['encounter_id'])->first();
        if ($encounter === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if ($encounter->status !== 'signed') {
            return response()->json([
                'error' => 'encounter_not_signed',
                'message' => 'Superbills are generated from signed encounters only.',
            ], 422);
        }

        // Price every line from the fee schedule BEFORE creating anything.
        $fees = DB::table('fee_schedule_items')->where('practice_id', $practiceId)
            ->whereIn('cpt_code', array_column($data['lines'], 'cpt_code'))
            ->get()->keyBy('cpt_code');

        $lines = [];
        foreach ($data['lines'] as $line) {
            $fee = $fees->get($line['cpt_code']);
            if ($fee === null) {
                return response()->json([
                    'error' => 'unknown_cpt_code',
                    'message' => "CPT {$line['cpt_code']} is not on this practice's fee schedule; nothing was invoiced.",
                ], 422);
            }
            $lines[] = [
                'id' => (string) Str::uuid(),
                'cpt_code' => $line['cpt_code'],
                'icd10_code' => $line['icd10_code'] ?? null,
                'description' => $fee->description,
                'price_cents' => (int) $fee->price_cents,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        $total = array_sum(array_column($lines, 'price_cents'));

        $invoiceId = (string) Str::uuid();
        DB::transaction(function () use ($invoiceId, $practiceId, $encounter, $total, &$lines): void {
            DB::table('invoices')->insert([
                'id' => $invoiceId, 'practice_id' => $practiceId,
                'patient_id' => $encounter->patient_id, 'encounter_id' => $encounter->id,
                'status' => 'open', 'total_cents' => $total,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as &$line) {
                $line['invoice_id'] = $invoiceId;
            }
            DB::table('invoice_lines')->insert($lines);
        });

        return response()->json([
            'id' => $invoiceId,
            'total_cents' => $total,
            'status' => 'open',
            'lines' => array_map(static fn (array $l): array => [
                'cpt_code' => $l['cpt_code'], 'icd10_code' => $l['icd10_code'],
                'description' => $l['description'], 'price_cents' => $l['price_cents'],
            ], $lines),
        ], 201);
    }

    public function storePayment(Request $request, string $invoiceId): JsonResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:cash,card,check,other'],
        ]);

        if (! Str::isUuid($invoiceId)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return DB::transaction(function () use ($request, $invoiceId, $data): JsonResponse {
            $invoice = DB::table('invoices')
                ->where('practice_id', $request->user()->practice_id)
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->first();
            if ($invoice === null) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $paid = (int) DB::table('payments')->where('invoice_id', $invoiceId)->sum('amount_cents');
            if ($paid + $data['amount_cents'] > $invoice->total_cents) {
                return response()->json([
                    'error' => 'overpayment',
                    'message' => 'Payment exceeds the open balance; record a corrected amount.',
                ], 422);
            }

            DB::table('payments')->insert([
                'id' => (string) Str::uuid(), 'invoice_id' => $invoiceId,
                'amount_cents' => $data['amount_cents'], 'method' => $data['method'],
                'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $status = ($paid + $data['amount_cents'] === (int) $invoice->total_cents) ? 'paid' : 'open';
            DB::table('invoices')->where('id', $invoiceId)->update(['status' => $status, 'updated_at' => now()]);

            return response()->json(['invoice_status' => $status], 201);
        });
    }
}
