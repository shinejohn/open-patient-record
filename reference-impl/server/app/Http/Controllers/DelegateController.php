<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesGrantTokens;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultDelegate;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delegate management. Owner-only by design: the authority to decide who acts for
 * the patient never delegates itself. Everything a delegate DOES (reads, grants,
 * exports) is audited under the delegate's own identity.
 */
final class DelegateController
{
    use ResolvesGrantTokens;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request, Vault $vault): JsonResponse
    {
        $this->assertSubject($request, $vault); // delegates may SEE the list

        return response()->json([
            'delegates' => $vault->delegates()->whereNull('revoked_at')->get()->map(fn (VaultDelegate $d) => [
                'id' => $d->id,
                'delegate_user_id' => $d->delegate_user_id,
                'name' => $d->delegate->name,
                'role' => $d->role,
                'since' => $d->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request, Vault $vault): JsonResponse
    {
        $this->assertOwner($request, $vault);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:'.implode(',', VaultDelegate::ROLES)],
        ]);

        $delegateUser = User::query()->where('email', $data['email'])->first();
        if ($delegateUser === null) {
            return response()->json(['error' => 'unknown_user'], 422);
        }
        if ($delegateUser->id === $vault->subject_user_id) {
            return response()->json(['error' => 'cannot_delegate_to_self'], 422);
        }
        if ($vault->delegates()->where('delegate_user_id', $delegateUser->id)->whereNull('revoked_at')->exists()) {
            return response()->json(['error' => 'already_delegated'], 409);
        }

        $delegate = VaultDelegate::query()->create([
            'vault_id' => $vault->id,
            'delegate_user_id' => $delegateUser->id,
            'role' => $data['role'],
            'added_by' => $request->user()->id,
        ]);

        $this->audit->record($vault, 'delegate.added', actor: $request->user(), context: [
            'delegate_user_id' => $delegateUser->id,
            'role' => $data['role'],
        ]);

        return response()->json(['id' => $delegate->id, 'role' => $delegate->role], 201);
    }

    public function revoke(Request $request, Vault $vault, VaultDelegate $delegate): JsonResponse
    {
        $this->assertOwner($request, $vault);

        if ($delegate->vault_id !== $vault->id) {
            abort(404);
        }

        if ($delegate->revoked_at === null) {
            $delegate->forceFill(['revoked_at' => now()])->save();
            $this->audit->record($vault, 'delegate.revoked', actor: $request->user(), context: [
                'delegate_user_id' => $delegate->delegate_user_id,
            ]);
        }

        return response()->json(['revoked' => true]);
    }
}
