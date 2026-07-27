<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E3: the honest transmission ledger. Channels are print and fax — the two
 * things a Phase-1 practice can actually do. There is NO electronic channel
 * and NO 'sent' status, because no rail exists: refusing is honest, faking is
 * the C13 bug. When Surescripts/lab/clearinghouse rails arrive (Phase 2),
 * they arrive as new channels with their own real semantics.
 */
final class TransmissionController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid'],
            'channel' => ['required', 'in:print,fax'],
            'kind' => ['required', 'in:prescription,referral,records'],
            'summary' => ['required', 'string', 'max:4000'],
        ]);

        $practiceId = $request->user()->practice_id;
        if (! Patient::query()->where('practice_id', $practiceId)->whereKey($data['patient_id'])->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $id = (string) Str::uuid();
        DB::table('transmissions')->insert([
            'id' => $id, 'practice_id' => $practiceId, 'patient_id' => $data['patient_id'],
            'channel' => $data['channel'], 'kind' => $data['kind'],
            'summary' => Crypt::encryptString($data['summary']),
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'status' => 'queued'], 201);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $tx = DB::table('transmissions')
            ->where('practice_id', $request->user()->practice_id)
            ->where('id', $id)->first();
        if ($tx === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // print -> printed (a human printed it); fax -> fax_queued (handed to a
        // fax path whose delivery is not observable here). Never 'sent'.
        $status = $tx->channel === 'print' ? 'printed' : 'fax_queued';
        DB::table('transmissions')->where('id', $id)
            ->update(['status' => $status, 'completed_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id, 'status' => $status]);
    }
}
