<x-filament-panels::page>
    <form wire:submit="simulateWebhook" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-beaker">
            Simulate Webhook
        </x-filament::button>
    </form>
</x-filament-panels::page>
