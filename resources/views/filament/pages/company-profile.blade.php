<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-start">
            <x-filament::button type="submit">
                Save company profile
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
