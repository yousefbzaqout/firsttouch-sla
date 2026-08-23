<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <x-filament::button type="submit">
                Save Changes
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="generateWebsiteApiKey">
                Generate Website API Key
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
