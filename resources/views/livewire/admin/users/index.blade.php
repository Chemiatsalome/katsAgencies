    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Users &amp; Roles</h2>
        </x-slot>

        <div class="p-6 space-y-4">
            @if (session('status'))
                <div class="bg-teal/10 border border-teal/30 text-charcoal text-sm rounded-md px-4 py-3">
                    {{ session('status') }}
                </div>
            @endif

            @if ($generatedPassword)
                <div class="bg-warning/10 border border-warning/30 text-charcoal text-sm rounded-md px-4 py-3 flex items-center justify-between">
                    <span>New temporary password for this user: <strong class="font-mono">{{ $generatedPassword }}</strong> — share it securely, it won't be shown again.</span>
                    <button wire:click="$set('generatedPassword', null)" class="text-brand-slate hover:text-charcoal">&times;</button>
                </div>
            @endif

            @if ($pendingStatusUserId)
                <form wire:submit="confirmStatusChange" class="bg-white shadow-sm border border-brand-border rounded-md p-4 space-y-2 max-w-xl">
                    <p class="text-sm text-charcoal">
                        Reason for changing this account's status to <strong>{{ ucfirst($pendingStatusValue) }}</strong>:
                    </p>
                    <textarea wire:model="reason" rows="2" class="w-full rounded-md border-brand-border text-sm" placeholder="Why is this account being deactivated or suspended?"></textarea>
                    <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                    <div class="flex gap-3">
                        <button type="submit" class="text-xs font-medium text-danger hover:underline">Confirm</button>
                        <button type="button" wire:click="cancelStatusChange" class="text-xs font-medium text-brand-slate hover:underline">Cancel</button>
                    </div>
                </form>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by name or email…" class="rounded-md border-brand-border text-sm" />
                <select wire:model.live="roleFilter" class="rounded-md border-brand-border text-sm">
                    <option value="all">All roles</option>
                    <option value="admin">Admin</option>
                    <option value="landlord">Landlord</option>
                    <option value="tenant">Tenant</option>
                </select>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-brand-slate sticky top-0">
                            <tr>
                                <th class="text-left px-5 py-2 font-medium">Name</th>
                                <th class="text-left px-5 py-2 font-medium">Email</th>
                                <th class="text-left px-5 py-2 font-medium">Role</th>
                                <th class="text-left px-5 py-2 font-medium">Status</th>
                                <th class="px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($users as $user)
                                <tr wire:key="user-{{ $user->id }}">
                                    <td class="px-5 py-3 font-medium text-charcoal">{{ $user->name }}</td>
                                    <td class="px-5 py-3 text-brand-slate">{{ $user->email }}</td>
                                    <td class="px-5 py-3">
                                        <select wire:change="updateRole({{ $user->id }}, $event.target.value)" wire:confirm="Change {{ $user->name }}'s role? This takes effect immediately." class="rounded-md border-brand-border text-xs py-1">
                                            @foreach (['admin', 'landlord', 'tenant'] as $role)
                                                <option value="{{ $role }}" @selected($user->hasRole($role))>{{ ucfirst($role) }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($user->hasRole('manager'))
                                            <span class="text-xs text-brand-slate" title="Only this manager's landlord can deactivate or remove them.">{{ ucfirst($user->status) }} (landlord-controlled)</span>
                                        @else
                                            <select wire:change="startStatusChange({{ $user->id }}, $event.target.value)" wire:confirm="Change {{ $user->name }}'s status?" class="rounded-md border-brand-border text-xs py-1">
                                                @foreach (['active', 'inactive', 'suspended'] as $status)
                                                    <option value="{{ $status }}" @selected($user->status === $status)>{{ ucfirst($status) }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <button wire:click="resetPassword({{ $user->id }})" wire:confirm="Reset this user's password?" class="text-brand-slate hover:text-navy">Reset Password</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-6 text-center text-gray-400">No users found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>{{ $users->links() }}</div>
        </div>
    </div>
