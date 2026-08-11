<x-guest-layout>
    <div class="text-center space-y-4">
        <div class="mx-auto h-12 w-12 rounded-full bg-warning/10 text-warning flex items-center justify-center">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <h1 class="text-lg font-semibold text-charcoal">Your landlord application is under review</h1>
        <p class="text-sm text-brand-slate">
            Thanks for signing up with Kats Agencies. An administrator will review your application shortly and activate
            your account. You'll be able to sign in and start listing your properties as soon as it's approved.
        </p>
        <livewire:layout.logout-button />
    </div>
</x-guest-layout>
