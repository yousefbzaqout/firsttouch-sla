<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AgentStatusToggle extends Component
{
    public bool $isOnline = true;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $this->isOnline = (bool) $user->is_online;
        }
    }

    public function toggleOnline(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isSalesRep()) {
            return;
        }

        $next = ! $this->isOnline;
        $user->update(['is_online' => $next]);
        $this->isOnline = $next;

        Notification::make()
            ->title($next ? 'You are Online' : 'You are Offline')
            ->body($next
                ? 'You will receive new lead assignments.'
                : 'Round-robin and SLA auto-reassignment will skip you.')
            ->success()
            ->send();
    }

    public function render(): View
    {
        return view('livewire.agent-status-toggle');
    }
}
