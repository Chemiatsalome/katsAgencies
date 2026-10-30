<x-guest-layout>
    <div class="text-center space-y-4">
        <div class="mx-auto h-12 w-12 rounded-full bg-warning/10 text-warning flex items-center justify-center">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <h1 class="text-lg font-semibold text-charcoal">Your account isn't active yet</h1>
        <p class="text-sm text-brand-slate">
            If you just signed up, an administrator will review your application shortly and activate your account —
            you'll be able to sign in and start listing your properties as soon as it's approved. If your account was
            previously active, it may have been deactivated by an administrator.
        </p>
        @php $contact = \App\Models\Setting::get('org_contact_email'); @endphp
        @if ($contact)
            <p class="text-sm text-brand-slate">
                Questions? Contact your administrator at
                <a href="mailto:{{ $contact }}" class="text-teal hover:underline">{{ $contact }}</a>.
            </p>
        @endif
        <livewire:layout.logout-button />

        <p class="text-center text-sm mt-2">
            <a href="{{ route('home') }}" wire:navigate class="text-brand-slate hover:text-charcoal">← {{ __('Back to home') }}</a>
        </p>
    </div>
</x-guest-layout>
