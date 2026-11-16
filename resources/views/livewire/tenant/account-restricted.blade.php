    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Account Restricted</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm rounded-lg border border-warning/40 p-6 space-y-4">
                    <h3 class="text-base font-semibold text-charcoal">
                        {{ $landlordName ? "{$landlordName}'s" : 'Your landlord\'s' }} Kats Agencies account has been suspended
                    </h3>

                    <p class="text-sm text-brand-slate">
                        Access to the tenant portal and platform payment functionality is temporarily unavailable
                        for this property while this restriction is in place.
                    </p>

                    <ul class="text-sm text-brand-slate list-disc pl-5 space-y-1">
                        <li>This does not terminate your tenancy.</li>
                        <li>It does not cancel your rent or any other obligations under your tenancy agreement.</li>
                        <li>Please contact your landlord through your existing communication channels for instructions on how to make payments while this platform restriction remains in place.</li>
                        <li>
                            For questions about this platform restriction itself, you can contact Kats Agencies administration
                            @if ($contactEmail)
                                at <a href="mailto:{{ $contactEmail }}" class="text-teal hover:underline">{{ $contactEmail }}</a>.
                            @else
                                .
                            @endif
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
