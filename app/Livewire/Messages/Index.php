<?php

namespace App\Livewire\Messages;

use App\Models\AppNotification;
use App\Models\Block;
use App\Models\House;
use App\Models\Message;
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

    public ?int $expandedMessageId = null;

    public string $replyBody = '';

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
            'body' => ['required', 'string', 'max:2000'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['in:in_app,email'],
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
        $sender = Auth::user();

        foreach ($recipients as $tenant) {
            // One Message row per recipient, even when a single compose
            // fans out to many tenants at once — each gets their own
            // independently replyable thread rather than all sharing one
            // record, since their replies (if any) will genuinely differ.
            $message = Message::create([
                'sender_id' => $sender->id,
                'tenant_id' => $tenant->id,
                'scope' => $this->scope,
                'subject' => $this->subject,
                'body' => $this->body,
            ]);

            $service->notify($tenant, 'landlord_message', $this->subject, $this->body, $this->channels, $message);
        }

        $this->reset(['subject', 'body']);
        session()->flash('status', "Message sent to {$recipients->count()} tenant(s).");
    }

    public function toggleExpand(int $announcementId): void
    {
        $this->expandedMessageId = $this->expandedMessageId === $announcementId ? null : $announcementId;
        $this->replyBody = '';
    }

    public function reply(int $announcementId): void
    {
        $announcement = AppNotification::where('event_type', 'landlord_message')
            ->where('related_type', Message::class)
            ->findOrFail($announcementId);

        $message = $announcement->related;

        abort_unless($message && Message::accessibleBy(Auth::user())->whereKey($message->id)->exists(), 403);

        $this->validate(['replyBody' => ['required', 'string', 'max:2000']]);

        app(\App\Services\MessageService::class)->reply($message, Auth::user(), $this->replyBody);

        $this->replyBody = '';
        session()->flash('status', 'Reply sent.');
    }

    private function resolveRecipients(): Collection
    {
        $user = Auth::user();

        return match ($this->scope) {
            // Scoped defensively even for a single named tenant/unit — a
            // manager (or landlord) shouldn't be able to message someone
            // outside their assigned properties by supplying an arbitrary id.
            'tenant' => Tenant::whereKey($this->tenant_id)->accessibleBy($user)->get(),
            'unit' => Tenant::whereHas('tenancies', fn ($q) => $q->active()->where('house_id', $this->house_id))->accessibleBy($user)->get(),
            'property' => Tenant::whereHas('tenancies', fn ($q) => $q->active()->whereHas('house.block', fn ($q2) => $q2->where('property_id', $this->property_id)))->accessibleBy($user)->distinct()->get(),
            'block' => Tenant::whereHas('tenancies', fn ($q) => $q->active()->whereHas('house', fn ($q2) => $q2->where('block_id', $this->block_id)))->accessibleBy($user)->distinct()->get(),
            'all' => Tenant::whereHas('tenancies', fn ($q) => $q->active())->accessibleBy($user)->distinct()->get(),
            default => collect(),
        };
    }

    public function render()
    {
        $user = Auth::user();

        $properties = Property::accessibleBy($user)->orderBy('name')->get();

        $history = AppNotification::where('event_type', 'landlord_message')
            ->where('notifiable_type', (new Tenant)->getMorphClass())
            ->whereIn('notifiable_id', Tenant::accessibleBy($user)->pluck('id'))
            ->with('notifiable', 'related.replies.user')
            ->latest()
            ->limit(50)
            ->get();

        return view('livewire.messages.index', [
            'properties' => $properties,
            'blocks' => $this->property_id ? Block::where('property_id', $this->property_id)->accessibleBy($user)->orderBy('name')->get() : collect(),
            'houses' => $this->property_id ? House::whereHas('block', fn ($q) => $q->where('property_id', $this->property_id))->accessibleBy($user)->orderBy('house_number')->get() : collect(),
            'tenants' => Tenant::whereHas('tenancies', fn ($q) => $q->active())->accessibleBy($user)->distinct()->orderBy('name')->get(),
            'history' => $history,
        ]);
    }
}
