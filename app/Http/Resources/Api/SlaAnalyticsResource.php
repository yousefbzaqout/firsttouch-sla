<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlaAnalyticsResource extends JsonResource
{
    /**
     * @return array{
     *     compliance_rate: float,
     *     average_response_seconds: float|null,
     *     total_breaches: int,
     *     total_leads: int,
     *     met_count: int
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{
         *     compliance_rate: float,
         *     average_response_seconds: float|null,
         *     total_breaches: int,
         *     total_leads: int,
         *     met_count: int
         * } $data
         */
        $data = is_array($this->resource) ? $this->resource : [
            'compliance_rate' => 0.0,
            'average_response_seconds' => null,
            'total_breaches' => 0,
            'total_leads' => 0,
            'met_count' => 0,
        ];

        return [
            'compliance_rate' => round($data['compliance_rate'], 2),
            'average_response_seconds' => $data['average_response_seconds'] !== null
                ? round($data['average_response_seconds'], 2)
                : null,
            'total_breaches' => $data['total_breaches'],
            'total_leads' => $data['total_leads'],
            'met_count' => $data['met_count'],
        ];
    }
}
