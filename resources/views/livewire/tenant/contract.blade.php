    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">My Contract</h2>
        </x-slot>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

                @if (! $tenancy)
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                        No active tenancy is linked to your account yet.
                    </div>
                @elseif ($documents->isEmpty())
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border p-6 text-brand-slate">
                        Your landlord hasn't uploaded a contract for your tenancy yet.
                    </div>
                @else
                    <div class="bg-white shadow-sm rounded-lg border border-brand-border divide-y divide-gray-100">
                        @foreach ($documents as $document)
                            <div class="px-5 py-4 flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-charcoal">
                                        {{ $document->original_filename }}
                                        @if ($loop->first)
                                            <span class="ml-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-green-50 text-green-700">Current</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-brand-slate">Uploaded {{ $document->created_at->format('d M Y') }}</p>
                                </div>
                                <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="text-teal hover:underline text-xs font-medium">
                                    View / Download
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
