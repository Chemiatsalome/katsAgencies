<?php

namespace App\Livewire\Messages;

use App\Models\AppNotification;
use App\Models\Block;
use App\Models\House;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    public string $scope = 'tenant';

    public ?int $tenant_id = null;

    public ?int $house_id = null;

    public ?int $property_id = null;

    public ?int $block_id = null;

    public string $subject = '';

    public string $body = '';

    public array $channels = ['in_app'];

    public function updatedScope(): void
    {
        $this->reset(['tenant_id', 'house_id', 'property_id', 'block_id']);
    }

    public function updatedPropertyId(): void
    {
        $this->block_id = null;
        $this->house_id = null;
    }

    public function send(): void
    {
        $rules = [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'channels' => ['required', 'array', 'min:1'],
        ];

        $rules += match ($this->scope) {
            'tenant' => ['tenant_id' => ['required', 'exists:tenants,id']],
            'unit' => ['house_id' => ['required', 'exists:houses,id']],
            'property' => ['property_id' => ['required', 'exists:properties,id']],
            'block' => ['block_id' => ['required', 'exists:blocks,id']],
            default => [],
        };

        $this->validate($rules);

        $recipients = $this->resolveRecipients();

        if ($recipients->isEmpty()) {
            $this->addError('scope', 'No active tenants matched this scope.');

            return;
        }

        $service = app(NotificationService::class);

        foreach ($recipients as $tenant) {
            $service->notify($tenant, 'landlord_message', $this->subject, $this->body, $this->channels);
        }

        $this->reset(['subject', 'body']);
        session()->flash('status', "Message sent to {$recipients->count()} tenant(s).");
    }

    private function resolveRecipients(): Collection
    {
        $landlordId = Auth::id();

        return match ($this->scope) {
            'tenant' => Tenant::whereKey($this->tenant_id)->get(),
            'unit' => Tenant::whereHas('tenancies', fn ($q) => $q->active()->where('house_id', $this->house_id))->get(),
            'property' => Tenant::whereHas('tenancies', fn ($q) => $q->active()->whereHas('house.block', fn ($q2) => $q2->where('property_id', $this->property_id)))->distinct()->get(),
            'block' => Tenant::whereHas('tenancies', fn ($q) => $q->active()->whereHas('house', fn ($q2) => $q2->where('block_id', $this->block_id)))->distinct()->get(),
            'all' => Tenant::whereHas('tenancies', fn ($q) => $q->active()->whereHas('house.block.property', fn ($q2) => $q2->where('landlord_id', $landlordId)))->distinct()->get(),
            default => collect(),
        };
    }

    public function render()
    {
        $landlordId = Auth::id();

        $properties = Property::forLandlord($landlordId)->orderBy('name')->get();

        $history = AppNotification::where('event_type', 'landlord_message')
            ->where('notifiable_type', (new Tenant)->getMorphClass())
            ->whereIn('notifiable_id', Tenant::whereHas('tenancies.house.block.property', fn ($q) => $q->where('landlord_id', $landlordId))->pluck('id'))
            ->with('notifiable')
            ->latest()
            ->limit(50)
            ->get();

        return view('livewire.messages.index', [
            'properties' => $properties,
            'blocks' => $this->property_id ? Block::where('property_id', $this->property_id)->orderBy('name')->get() : collect(),
            'houses' => $this->property_id ? House::whereHas('block', fn ($q) => $q->where('property_id', $this->property_id))->orderBy('house_number')->get() : collect(),
            'tenants' => Tenant::whereHas('tenancies', fn ($q) => $q->active()->whereHas('house.block.property', fn ($q2) => $q2->where('landlord_id', $landlordId)))->distinct()->orderBy('name')->get(),
            'history' => $history,
        ]);
    }
}
