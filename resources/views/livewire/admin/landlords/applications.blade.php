    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Landlord Applications</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            <p class="text-sm text-brand-slate max-w-2xl">
                People who registered wanting to list properties on Kats Agencies. Approve to activate their account, or reject to remove the application.
            </p>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Name</th>
                            <th class="text-left px-5 py-2 font-medium">Email</th>
                            <th class="text-left px-5 py-2 font-medium">Applied</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($pending as $applicant)
                            <tr wire:key="applicant-{{ $applicant->id }}">
                                <td class="px-5 py-3 font-medium text-charcoal">{{ $applicant->name }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $applicant->email }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $applicant->created_at->diffForHumans() }}</td>
                                <td class="px-5 py-3 text-right space-x-3">
                                    <button wire:click="approve({{ $applicant->id }})" wire:confirm="Approve {{ $applicant->name }} as a landlord?" class="text-success hover:underline">Approve</button>
                                    <button wire:click="reject({{ $applicant->id }})" wire:confirm="Reject and remove this application?" class="text-danger hover:underline">Reject</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-6 text-center text-gray-400">No pending landlord applications.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $pending->links() }}</div>
        </div>
    </div>
