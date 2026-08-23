<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentLedgerType;
use App\Enums\PaymentStatus;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $transaction_id
 * @property string $driver
 * @property PaymentLedgerType $type
 * @property string|null $description
 * @property string $amount
 * @property string $currency
 * @property int $credits_added
 * @property PaymentStatus $status
 * @property array<string, mixed>|null $metadata
 */
class PaymentTransaction extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'transaction_id',
        'driver',
        'type',
        'description',
        'amount',
        'currency',
        'credits_added',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentLedgerType::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'credits_added' => 'integer',
            'metadata' => 'array',
        ];
    }
}
