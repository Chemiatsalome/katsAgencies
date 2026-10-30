    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Permissions</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <p class="text-sm text-brand-slate max-w-2xl">
                Controls what each role can access beyond the built-in Admin/Landlord/Tenant scoping. The Admin role always has full access and can't be edited here. Manager permissions aren't set here — each landlord delegates those individually per manager from their own Managers screen, since granting one at the role level would apply to every manager on the platform at once.
            </p>

            @foreach ($groups as $groupName => $permissions)
                <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                    <div class="px-5 py-3 border-b border-brand-border bg-gray-50">
                        <h3 class="text-sm font-semibold text-charcoal">{{ $groupName }}</h3>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-brand-slate">
                                <th class="px-5 py-2 font-medium">Permission</th>
                                @foreach ($roles as $role)
                                    <th class="px-5 py-2 font-medium text-center capitalize">{{ $role }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($permissions as $permission)
                                <tr wire:key="perm-{{ $permission }}">
                                    <td class="px-5 py-2.5 text-charcoal">{{ str($permission)->before('.')->headline() }}, {{ str($permission)->after('.')->headline() }}</td>
                                    @foreach ($roles as $role)
                                        <td class="px-5 py-2.5 text-center">
                                            @if ($role === 'admin')
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-navy-light text-navy" title="Admin always has full access">
                                                    <x-nav-icon name="check" class="h-3.5 w-3.5" />
                                                </span>
                                            @else
                                                <button
                                                    wire:click="toggle('{{ $role }}', '{{ $permission }}')"
                                                    class="inline-flex h-5 w-5 items-center justify-center rounded border {{ $matrix[$role][$permission] ?? false ? 'bg-teal border-teal text-white' : 'border-brand-border text-transparent hover:border-teal' }}"
                                                ><x-nav-icon name="check" class="h-3.5 w-3.5" /></button>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
