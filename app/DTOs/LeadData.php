<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\LeadSource;

readonly class LeadData
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $tenantId,
        public LeadSource $source,
        public string $externalLeadId,
        public string $name,
        public string $phone,
        public ?string $email,
        public ?string $campaignId,
        public ?string $formId,
        public array $rawPayload,
    ) {}
}
