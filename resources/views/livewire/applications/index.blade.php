    <div>
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-charcoal leading-tight">Applications</h2>
                <a href="{{ route('manage.applications.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-navy text-white text-sm font-medium rounded-md hover:bg-navy-dark">
                    + Log Application
                </a>
            </div>
        </x-slot>

        <div class="p-6 space-y-4">
            <div class="flex gap-2 flex-wrap">
                @foreach (['all' => 'All', 'submitted' => 'Submitted', 'under_review' => 'Under Review', 'more_info_required' => 'More Info Required', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'] as $value => $label)
                    <button wire:click="$set('status', '{{ $value }}')" class="px-3 py-1.5 text-sm rounded-md {{ $status === $value ? 'bg-navy text-white' : 'bg-white text-brand-slate border border-brand-border' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Applicant</th>
                            <th class="text-left px-5 py-2 font-medium">Phone</th>
                            <th class="text-left px-5 py-2 font-medium">Desired Unit</th>
                            <th class="text-left px-5 py-2 font-medium">Applied</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($applications as $application)
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('manage.applications.show', $application) }}'">
                                <td class="px-5 py-3 font-medium text-charcoal">{{ $application->applicant_name }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $application->applicant_phone }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $application->property->name }}{{ $application->house ? ' — '.$application->house->house_number : '' }}</td>
                                <td class="px-5 py-3 text-brand-slate">{{ $application->application_date->format('d M Y') }}</td>
                                <td class="px-5 py-3"><x-status-badge :status="$application->status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-6 text-center text-gray-400">No applications yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-per-page-select :paginator="$applications" />
        </div>
    </div>
