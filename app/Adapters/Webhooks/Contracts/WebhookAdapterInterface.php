<?php

declare(strict_types=1);

namespace App\Adapters\Webhooks\Contracts;

use App\DTOs\LeadData;
use Illuminate\Http\Request;

interface WebhookAdapterInterface
{
    public function validateSignature(Request $request, string $secret): bool;

    public function extractLeadData(Request $request, string $tenantId): LeadData;
}
