<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SlaWarningBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        public readonly int $thresholdPercent,
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
        return 'SlaWarningBroadcastEvent';
    }

    /**
     * @return array{
     *     lead_id: string,
     *     assigned_user_name: string,
     *     threshold_percent: int,
     *     time_remaining: int
     * }
     */
    public function broadcastWith(): array
    {
        $deadline = $this->lead->sla_deadline;
        $timeRemaining = 0;

        if ($deadline !== null) {
            $timeRemaining = max(0, $deadline->getTimestamp() - now()->getTimestamp());
        }

        $this->lead->loadMissing('assignedUser');
        $assignee = $this->lead->assignedUser;

        return [
            'lead_id' => (string) $this->lead->id,
            'assigned_user_name' => $assignee !== null ? $assignee->name : 'Unassigned',
            'threshold_percent' => $this->thresholdPercent,
            'time_remaining' => $timeRemaining,
        ];
    }
}
