<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-filament::section>
                <x-slot name="heading">Current Credit Balance</x-slot>
                <p class="text-3xl font-bold tracking-tight">{{ number_format($this->creditsBalance) }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">AI inferences remaining</p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Active Plan</x-slot>
                <p class="text-2xl font-semibold capitalize">{{ str_replace('_', ' ', $this->planType) }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Subscription:
                    <span class="font-medium capitalize">{{ str_replace('_', ' ', $this->subscriptionStatus) }}</span>
                </p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">AI Routing</x-slot>
                <p class="text-2xl font-semibold">{{ str_replace('_', ' ', $this->aiRoutingMode) }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Switches to human-only when credits hit zero</p>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">Credit Packages</x-slot>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->packages() as $package)
                    <x-filament::button
                        color="primary"
                        wire:click="purchaseCredits({{ $package['credits'] }}, {{ $package['amount'] }})"
                    >
                        {{ $package['label'] }} — ${{ number_format($package['amount'], 2) }}
                    </x-filament::button>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Transaction History</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="px-2 py-2 font-medium">When</th>
                            <th class="px-2 py-2 font-medium">Type</th>
                            <th class="px-2 py-2 font-medium">Description</th>
                            <th class="px-2 py-2 font-medium">Credits</th>
                            <th class="px-2 py-2 font-medium">Amount</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->transactions as $tx)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="px-2 py-2 whitespace-nowrap">{{ $tx['created_at'] }}</td>
                                <td class="px-2 py-2 capitalize">{{ str_replace('_', ' ', $tx['type']) }}</td>
                                <td class="px-2 py-2">{{ $tx['description'] ?? '—' }}</td>
                                <td class="px-2 py-2 font-medium">{{ $tx['credits_added'] > 0 ? '+' : '' }}{{ $tx['credits_added'] }}</td>
                                <td class="px-2 py-2">${{ number_format((float) $tx['amount'], 2) }}</td>
                                <td class="px-2 py-2 capitalize">{{ $tx['status'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-2 py-6 text-center text-gray-500 dark:text-gray-400">
                                    No billing transactions yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
