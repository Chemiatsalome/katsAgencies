<div class="bg-white shadow-sm rounded-lg border border-brand-border p-5">
    <h3 class="text-sm font-semibold text-brand-slate mb-4">Record Deposit Payment</h3>

    <form wire:submit="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <x-input-label for="deposit_amount_input" value="Amount (KSh)" />
                <x-text-input id="deposit_amount_input" type="number" step="0.01" wire:model="amount" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('amount')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="deposit_date_input" value="Date" />
                <x-text-input id="deposit_date_input" type="date" wire:model="payment_date" class="mt-1 block w-full" />
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <x-input-label for="deposit_method_input" value="Method" />
                <select id="deposit_method_input" wire:model="method" class="mt-1 block w-full rounded-md border-brand-border text-sm">
                    <option value="mpesa">M-Pesa</option>
                    <option value="bank">Bank</option>
                    <option value="cash">Cash</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <x-input-label for="deposit_reference_input" value="Reference No. (optional)" />
                <x-text-input id="deposit_reference_input" type="text" wire:model="reference_number" class="mt-1 block w-full" />
            </div>
        </div>

        <x-primary-button type="submit">Record Deposit</x-primary-button>
    </form>
</div>
