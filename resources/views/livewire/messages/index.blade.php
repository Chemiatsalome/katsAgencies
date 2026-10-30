    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">Tenant Messages</h2>
        </x-slot>

        <div class="p-6 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-teal/10 border border-teal/30 text-teal px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif

            <form wire:submit="send" class="bg-white shadow-sm rounded-lg border border-brand-border p-6 space-y-5 max-w-2xl">
                <h3 class="text-sm font-semibold text-charcoal">Compose Message</h3>

                <div>
                    <x-input-label value="Send To" />
                    <select wire:model.live="scope" class="mt-1 block w-full rounded-md border-brand-border">
                        <option value="tenant">A Single Tenant</option>
                        <option value="unit">Everyone in a Unit</option>
                        <option value="property">Everyone in a Property</option>
                        <option value="block">Everyone in a Block</option>
                        <option value="all">All My Tenants</option>
                    </select>
                    <x-input-error :messages="$errors->get('scope')" class="mt-1" />
                </div>

                @if ($scope === 'tenant')
                    <div>
                        <x-input-label for="tenant_id" value="Tenant" />
                        <select id="tenant_id" wire:model="tenant_id" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="">Select tenant…</option>
                            @foreach ($tenants as $tenant)
                                <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('tenant_id')" class="mt-1" />
                    </div>
                @elseif ($scope === 'property' || $scope === 'unit' || $scope === 'block')
                    <div>
                        <x-input-label for="property_id" value="Property" />
                        <select id="property_id" wire:model.live="property_id" class="mt-1 block w-full rounded-md border-brand-border">
                            <option value="">Select property…</option>
                            @foreach ($properties as $property)
                                <option value="{{ $property->id }}">{{ $property->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('property_id')" class="mt-1" />
                    </div>

                    @if ($scope === 'unit' && $property_id)
                        <div>
                            <x-input-label for="house_id" value="Unit" />
                            <select id="house_id" wire:model="house_id" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="">Select unit…</option>
                                @foreach ($houses as $house)
                                    <option value="{{ $house->id }}">{{ $house->house_number }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('house_id')" class="mt-1" />
                        </div>
                    @elseif ($scope === 'block' && $property_id)
                        <div>
                            <x-input-label for="block_id" value="Block" />
                            <select id="block_id" wire:model="block_id" class="mt-1 block w-full rounded-md border-brand-border">
                                <option value="">Select block…</option>
                                @foreach ($blocks as $block)
                                    <option value="{{ $block->id }}">{{ $block->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('block_id')" class="mt-1" />
                        </div>
                    @endif
                @endif

                <div>
                    <x-input-label for="subject" value="Subject" />
                    <x-text-input id="subject" type="text" wire:model="subject" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('subject')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="body" value="Message" />
                    <textarea id="body" wire:model="body" rows="4" class="mt-1 block w-full rounded-md border-brand-border"></textarea>
                    <x-input-error :messages="$errors->get('body')" class="mt-1" />
                </div>

                <div>
                    <x-input-label value="Channels" />
                    <div class="mt-1 flex gap-4 text-sm">
                        <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="channels" value="in_app" class="rounded border-brand-border text-navy"> In-App</label>
                        <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="channels" value="email" class="rounded border-brand-border text-navy"> Email</label>
                    </div>
                    <x-input-error :messages="$errors->get('channels')" class="mt-1" />
                </div>

                <x-primary-button type="submit">Send Message</x-primary-button>
            </form>

            <div class="bg-white shadow-sm rounded-lg border border-brand-border overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border">
                    <h3 class="text-sm font-semibold text-charcoal">Recent Messages</h3>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-brand-slate">
                        <tr>
                            <th class="text-left px-5 py-2 font-medium">Subject</th>
                            <th class="text-left px-5 py-2 font-medium">Recipient</th>
                            <th class="text-left px-5 py-2 font-medium">Channel</th>
                            <th class="text-left px-5 py-2 font-medium">Status</th>
                            <th class="text-left px-5 py-2 font-medium">Sent</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($history as $entry)
                            <tr wire:key="message-{{ $entry->id }}" class="cursor-pointer hover:bg-gray-50" wire:click="toggleExpand({{ $entry->id }})">
                                <td class="px-5 py-2.5 font-medium text-charcoal">
                                    {{ $entry->title }}
                                    @if ($entry->related?->replies->isNotEmpty())
                                        <span class="text-xs text-teal ml-1">({{ $entry->related->replies->count() }} reply)</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $entry->notifiable?->name ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ ucfirst($entry->channel) }}</td>
                                <td class="px-5 py-2.5"><x-status-badge :status="$entry->status" /></td>
                                <td class="px-5 py-2.5 text-brand-slate">{{ $entry->created_at->format('d M Y H:i') }}</td>
                            </tr>
                            @if ($expandedMessageId === $entry->id && $entry->related)
                                <tr wire:key="message-thread-{{ $entry->id }}">
                                    <td colspan="5" class="px-5 py-4 bg-gray-50 space-y-3">
                                        <p class="text-sm text-charcoal whitespace-pre-line">{{ $entry->related->body }}</p>

                                        @foreach ($entry->related->replies as $reply)
                                            <div class="text-sm border-t border-gray-200 pt-3">
                                                <span class="font-medium text-charcoal">{{ $reply->user_id === $entry->related->sender_id ? 'You' : $entry->notifiable?->name }}</span>
                                                <span class="text-xs text-brand-slate ml-1">{{ $reply->created_at->format('d M Y H:i') }}</span>
                                                <p class="text-brand-slate whitespace-pre-line">{{ $reply->body }}</p>
                                            </div>
                                        @endforeach

                                        <form wire:submit="reply({{ $entry->id }})" class="pt-3 border-t border-gray-200 space-y-2">
                                            <textarea wire:model="replyBody" rows="2" class="w-full rounded-md border-gray-300 text-sm" placeholder="Reply..."></textarea>
                                            <x-input-error :messages="$errors->get('replyBody')" class="mt-1" />
                                            <button type="submit" class="text-teal hover:underline text-xs font-medium">Send Reply</button>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="5" class="px-5 py-6 text-center text-gray-400">No messages sent yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
