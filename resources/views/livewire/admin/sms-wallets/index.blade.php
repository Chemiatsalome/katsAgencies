    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">SMS Wallets</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <p class="text-sm text-brand-slate max-w-2xl">
                SMS rent reminders are an optional, self-serve add-on — a landlord tops up their own prepaid balance
                via Kats' own M-Pesa paybill, charged at
                <span class="font-medium text-charcoal">KSh {{ number_format($rate, 2) }} per message</span>
                (set in <a href="{{ route('admin.settings.index', ['tab' => 'payments']) }}" wire:navigate class="text-teal hover:underline">Settings → Payments</a>).
                This view is read-only — a balance only ever changes via a real top-up or a real SMS sent.
            </p>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Landlord</th>
                            <th class="text-left px-5 py-2 font-medium">SMS Reminders</th>
                            <th class="text-right px-5 py-2 font-medium">Balance</th>
                            <th class="text-right px-5 py-2 font-medium">Topped Up (All Time)</th>
                            <th class="text-right px-5 py-2 font-medium">Messages Sent</th>
                            <th class="text-left px-5 py-2 font-medium">Last Activity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($landlords as $row)
                            <tr>
                                <td class="px-5 py-2.5 text-charcoal font-medium">{{ $row->landlord->name }}</td>
                                <td class="px-5 py-2.5">
                                    @if ($row->smsEnabled)
                                        <x-status-badge status="active" />
                                    @else
                                        <span class="text-xs text-brand-slate">Not enabled</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-right font-medium {{ $row->smsEnabled && $row->balance < $rate ? 'text-warning' : 'text-charcoal' }}">
                                    KSh {{ number_format($row->balance, 2) }}
                                </td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($row->totalToppedUp, 2) }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">{{ number_format($row->messagesSent) }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $row->lastActivity?->format('d M Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>

            {{ $landlords->links() }}
        </div>
    </div>
