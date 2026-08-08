<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F4 remainder: patient statements. Money is integer cents, never floats —
 * the same law as the billing slice this reads from.
 */
final class StatementController
{
    public function show(Request $request, string $patientId): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $practiceId = $request->user()->practice_id;
        $patient = Patient::query()->where('practice_id', $practiceId)->whereKey($patientId)->first();
        if ($patient === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        $invoices = DB::table('invoices')
            ->where('practice_id', $practiceId)
            ->where('patient_id', $patientId)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get(['id', 'total_cents', 'status', 'created_at']);

        $invoiceIds = $invoices->pluck('id')->all();
        $payments = $invoiceIds === [] ? collect() : DB::table('payments')
            ->whereIn('invoice_id', $invoiceIds)
            ->whereBetween('received_at', [$from, $to])
            ->orderBy('received_at')
            ->get(['id', 'invoice_id', 'amount_cents', 'method', 'received_at']);

        $balanceCents = (int) $invoices->sum('total_cents') - (int) $payments->sum('amount_cents');

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->csv($patientId, $data['from'], $data['to'], $invoices, $payments, $balanceCents);
        }

        return response()->json([
            'patient_id' => $patientId,
            'from' => $data['from'],
            'to' => $data['to'],
            'invoices' => $invoices->map(static fn (object $i): array => [
                'id' => $i->id, 'total_cents' => (int) $i->total_cents, 'status' => $i->status,
                'created_at' => $i->created_at,
            ])->values(),
            'payments' => $payments->map(static fn (object $p): array => [
                'id' => $p->id, 'invoice_id' => $p->invoice_id, 'amount_cents' => (int) $p->amount_cents,
                'method' => $p->method, 'received_at' => $p->received_at,
            ])->values(),
            'balance_cents' => $balanceCents,
        ]);
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $invoices
     * @param \Illuminate\Support\Collection<int, object> $payments
     */
    private function csv(string $patientId, string $from, string $to, $invoices, $payments, int $balanceCents): StreamedResponse
    {
        $filename = "statement-{$patientId}-{$from}-to-{$to}.csv";

        return response()->streamDownload(function () use ($invoices, $payments, $balanceCents): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['type', 'id', 'date', 'amount_cents', 'description']);
            foreach ($invoices as $invoice) {
                fputcsv($out, ['invoice', $invoice->id, $invoice->created_at, $invoice->total_cents, "invoice ({$invoice->status})"]);
            }
            foreach ($payments as $payment) {
                fputcsv($out, ['payment', $payment->id, $payment->received_at, -$payment->amount_cents, "payment ({$payment->method})"]);
            }
            fputcsv($out, ['balance', '', '', $balanceCents, 'balance']);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
