<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Developer;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApiTokenController
{
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->canManageTenantSettings()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $token = $user->createToken((string) $validated['name'], ['developer:*']);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'name' => $validated['name'],
        ], 201);
    }

    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->canManageTenantSettings()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $deleted = $user->tokens()->whereKey($tokenId)->delete();

        if ($deleted === 0) {
            throw ValidationException::withMessages([
                'token' => 'Token not found.',
            ]);
        }

        return response()->json(['message' => 'Token revoked.']);
    }
}
