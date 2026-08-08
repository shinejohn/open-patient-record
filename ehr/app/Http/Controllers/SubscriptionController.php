<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** F4 remainder: DPC recurring billing plans. Owner-gated CRUD. */
final class SubscriptionController
{
    public function index(Request $request): JsonResponse
    {
        $subscriptions = Subscription::query()
            ->where('practice_id', $request->user()->practice_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Subscription $s): array => $this->present($s));

        return response()->json(['subscriptions' => $subscriptions]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid'],
            'plan_name' => ['required', 'string', 'max:255'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'interval' => ['nullable', 'in:monthly'],
            'anchor_day' => ['nullable', 'integer', 'min:1', 'max:28'],
        ]);

        $practiceId = $request->user()->practice_id;
        if (! Patient::query()->where('practice_id', $practiceId)->whereKey($data['patient_id'])->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $subscription = Subscription::query()->create([
            'practice_id' => $practiceId,
            'patient_id' => $data['patient_id'],
            'plan_name' => $data['plan_name'],
            'amount_cents' => $data['amount_cents'],
            'interval' => $data['interval'] ?? 'monthly',
            'anchor_day' => $data['anchor_day'] ?? 1,
            'active' => true,
        ]);

        return response()->json($this->present($subscription), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'plan_name' => ['sometimes', 'string', 'max:255'],
            'amount_cents' => ['sometimes', 'integer', 'min:1'],
            'anchor_day' => ['sometimes', 'integer', 'min:1', 'max:28'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $subscription = Subscription::query()
            ->where('practice_id', $request->user()->practice_id)
            ->whereKey($id)
            ->first();
        if ($subscription === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $subscription->update($data);

        return response()->json($this->present($subscription));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $subscription = Subscription::query()
            ->where('practice_id', $request->user()->practice_id)
            ->whereKey($id)
            ->first();
        if ($subscription === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $subscription->delete();

        return response()->json(['id' => $id, 'deleted' => true]);
    }

    /** @return array{id: string, patient_id: string, plan_name: string, amount_cents: int, interval: string, anchor_day: int, active: bool} */
    private function present(Subscription $s): array
    {
        return [
            'id' => $s->id,
            'patient_id' => $s->patient_id,
            'plan_name' => $s->plan_name,
            'amount_cents' => (int) $s->amount_cents,
            'interval' => $s->interval,
            'anchor_day' => (int) $s->anchor_day,
            'active' => (bool) $s->active,
        ];
    }
}
