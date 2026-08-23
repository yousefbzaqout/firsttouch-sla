<x-filament-panels::page>
    <div class="space-y-8">
        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Create API token</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Use Bearer tokens with <code class="text-xs">Authorization: Bearer &lt;token&gt;</code>
                against <code class="text-xs">/api/v1/developer/*</code>.
            </p>

            <form wire:submit="createToken" class="space-y-4">
                {{ $this->form }}

                <x-filament::button type="submit" icon="heroicon-o-plus">
                    Create token
                </x-filament::button>
            </form>

            @if (filled($plainTextToken))
                <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-600 dark:bg-warning-500/10">
                    <p class="mb-2 font-medium">Plain-text token (copy now):</p>
                    <code class="break-all">{{ $plainTextToken }}</code>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Active tokens</h2>

            @forelse ($this->tokens as $token)
                <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-3 text-sm dark:border-white/10">
                    <div>
                        <div class="font-medium">{{ $token->name }}</div>
                        <div class="text-gray-500 dark:text-gray-400">
                            Created {{ $token->created_at }}
                            @if ($token->last_used_at)
                                · Last used {{ $token->last_used_at }}
                            @endif
                        </div>
                    </div>

                    <x-filament::button
                        color="danger"
                        size="sm"
                        wire:click="revokeToken('{{ $token->id }}')"
                        wire:confirm="Revoke this API token?"
                    >
                        Revoke
                    </x-filament::button>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No API tokens yet.</p>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
