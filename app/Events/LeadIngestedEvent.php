<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadIngestedEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Lead $lead,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.'.$this->lead->tenant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'LeadIngestedEvent';
    }

    /**
     * @return array{lead_id: string, name: string, source: string, created_at: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id' => (string) $this->lead->id,
            'name' => $this->lead->name,
            'source' => $this->lead->source->value,
            'created_at' => $this->lead->created_at?->toIso8601String(),
        ];
    }
}
