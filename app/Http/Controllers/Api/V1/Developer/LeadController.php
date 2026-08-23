<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\LeadStatus;
use App\Http\Resources\Api\LeadApiResource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = Lead::withoutGlobalScopes()
            ->with('assignedUser')
            ->where('tenant_id', $user->tenant_id)
            ->latest('created_at');

        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $parsed = LeadStatus::tryFrom($status);

            if ($parsed !== null) {
                $query->where('status', $parsed);
            }
        }

        $perPage = min(100, max(1, (int) $request->integer('per_page', 15)));

        return LeadApiResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, string $id): LeadApiResource|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $lead = Lead::withoutGlobalScopes()
            ->with('assignedUser')
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($id)
            ->first();

        if ($lead === null) {
            return response()->json(['message' => 'Lead not found.'], 404);
        }

        return new LeadApiResource($lead);
    }
}
