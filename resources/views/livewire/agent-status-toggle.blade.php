<div
    @class([
        'fi-agent-status-toggle flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm',
        'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $isOnline,
        'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => ! $isOnline,
    ])
>
    <span class="font-medium whitespace-nowrap">
        {{ $isOnline ? 'Online' : 'Offline' }}
    </span>

    <button
        type="button"
        wire:click="toggleOnline"
        wire:loading.attr="disabled"
        role="switch"
        aria-checked="{{ $isOnline ? 'true' : 'false' }}"
        aria-label="Toggle online status"
        @class([
            'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
            'bg-success-600' => $isOnline,
            'bg-gray-300 dark:bg-gray-600' => ! $isOnline,
        ])
    >
        <span
            @class([
                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                'translate-x-5' => $isOnline,
                'translate-x-0' => ! $isOnline,
            ])
        ></span>
    </button>
</div>
