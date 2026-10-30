    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Financial Overview</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="max-w-xs">
                <label class="block text-xs font-medium text-brand-slate mb-1">Property</label>
                <select wire:model.live="propertyId" class="rounded-md border-brand-border text-sm">
                    <option value="all">All Properties</option>
                    @foreach ($properties as $property)
                        <option value="{{ $property->id }}">{{ $property->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <x-stat-tile label="Income (6 months)" value="KSh {{ number_format($totalIncome, 2) }}" tone="good" />
                <x-stat-tile label="Expenses (6 months)" value="KSh {{ number_format($totalExpenses, 2) }}" tone="critical" />
                <x-stat-tile label="Net Cash Flow (6 months)" value="KSh {{ number_format($netCashflow, 2) }}" tone="{{ $netCashflow >= 0 ? 'good' : 'critical' }}" />
                <x-stat-tile label="Current Outstanding" value="KSh {{ number_format($outstanding, 2) }}" tone="{{ $outstanding > 0 ? 'warning' : 'neutral' }}" href="{{ route('manage.arrears.index') }}" />
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border p-5">
                <h3 class="text-sm font-semibold text-charcoal">M-Pesa Collections</h3>

                @if ($directCollectionConfigured)
                    <p class="text-xs text-brand-slate mt-1 mb-4">
                        You're on <strong>Direct Collection</strong>, so tenant M-Pesa payments go straight into your own account.
                        Kats never holds this money, so there's nothing pending disbursement on it.
                    </p>
                @else
                    <p class="text-xs text-brand-slate mt-1 mb-4">
                        Rent tenants pay via M-Pesa lands in Kats Agencies' own paybill, not directly in your account, and we pay it
                        out to you by the 10th of each month. Want it to go straight to your own account instead? Set up
                        <a href="{{ route('manage.settings.index', ['tab' => 'payout']) }}" wire:navigate class="text-teal hover:underline">Direct Collection</a> in Settings.
                    </p>
                @endif

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @if ($allTimeCollectedDirectly > 0)
                        <div>
                            <div class="text-xs text-brand-slate">Collected Directly (all time)</div>
                            <div class="text-lg font-semibold text-charcoal">KSh {{ number_format($allTimeCollectedDirectly, 2) }}</div>
                        </div>
                    @endif
                    <div>
                        <div class="text-xs text-brand-slate">Collected via Kats Paybill (all time)</div>
                        <div class="text-lg font-semibold text-charcoal">KSh {{ number_format($allTimeMpesaCollected, 2) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-brand-slate">Still Held by Kats</div>
                        <div class="text-lg font-semibold {{ $heldPendingDisbursement > 0 ? 'text-warning' : 'text-charcoal' }}">KSh {{ number_format($heldPendingDisbursement, 2) }}</div>
                    </div>
                    @if ($heldPendingDisbursement > 0)
                        <div>
                            <div class="text-xs text-brand-slate">Days to Next Release</div>
                            <div class="text-lg font-semibold text-charcoal">{{ $daysUntilNextRelease === 0 ? 'Today' : $daysUntilNextRelease }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <div
                wire:key="cashflow-chart-{{ $propertyId }}"
                x-data="landlordCashflowChart(@js($months))"
                x-init="init()"
                class="bg-white shadow-sm rounded-lg border border-brand-border p-5"
            >
                <h3 class="text-sm font-semibold text-charcoal mb-4">Income vs Expenses — Last 6 Months</h3>
                <div style="height: 300px">
                    <canvas x-ref="canvas"></canvas>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Month</th>
                            <th class="text-right px-5 py-2 font-medium">Income</th>
                            <th class="text-right px-5 py-2 font-medium">Via M-Pesa</th>
                            <th class="text-right px-5 py-2 font-medium">Expenses</th>
                            <th class="text-right px-5 py-2 font-medium">Net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($months as $month)
                            <tr>
                                <td class="px-5 py-2.5 font-medium text-charcoal">{{ $month['label'] }}</td>
                                <td class="px-5 py-2.5 text-right text-success">KSh {{ number_format($month['income'], 2) }}</td>
                                <td class="px-5 py-2.5 text-right text-brand-slate">KSh {{ number_format($month['mpesa'], 2) }}</td>
                                <td class="px-5 py-2.5 text-right text-danger">KSh {{ number_format($month['expenses'], 2) }}</td>
                                <td class="px-5 py-2.5 text-right font-medium {{ $month['net'] >= 0 ? 'text-success' : 'text-danger' }}">KSh {{ number_format($month['net'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
