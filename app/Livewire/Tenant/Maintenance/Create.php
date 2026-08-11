<?php

namespace App\Livewire\Tenant\Maintenance;

use App\Models\Complaint;
use App\Models\Document;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.tenant')]
class Create extends Component
{
    use WithFileUploads;

    public const CATEGORIES = ['plumbing', 'electrical', 'water', 'security', 'structural', 'cleaning', 'appliance', 'other'];

    public string $category = 'other';

    public string $subject = '';

    public string $description = '';

    public string $priority = 'normal';

    public $photo;

    public function mount(): void
    {
        $this->authorize('create', Complaint::class);
    }

    public function submit(): void
    {
        $this->authorize('create', Complaint::class);

        $this->validate([
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'priority' => ['required', 'in:low,normal,urgent'],
            'photo' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $tenant = Auth::user()->tenant;
        $tenancy = $tenant?->tenancies()->active()->with('house.block.property')->first();
        abort_if(! $tenancy, 404, 'No active tenancy is linked to your account.');

        $priorityMap = ['low' => 'low', 'normal' => 'medium', 'urgent' => 'high'];

        $complaint = Complaint::create([
            'tenant_id' => $tenant->id,
            'house_id' => $tenancy->house_id,
            'property_id' => $tenancy->house->block->property_id,
            'category' => $this->category,
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $priorityMap[$this->priority],
            'status' => 'submitted',
            'submitted_by' => Auth::id(),
        ]);

        if ($this->photo) {
            $path = $this->photo->store('documents', 'public');

            Document::create([
                'documentable_type' => $complaint->getMorphClass(),
                'documentable_id' => $complaint->id,
                'category' => 'other',
                'original_filename' => $this->photo->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $this->photo->getMimeType(),
                'size' => $this->photo->getSize(),
                'uploaded_by' => Auth::id(),
            ]);
        }

        app(NotificationService::class)->notify(
            $tenancy->house->block->property->landlord,
            'complaint_submitted',
            'New Maintenance Request',
            "{$tenant->name} ({$tenancy->house->house_number}) reported: {$this->subject}",
            ['in_app'],
            $complaint,
        );

        session()->flash('status', 'Your request has been submitted.');
        $this->redirectRoute('tenant.maintenance.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.tenant.maintenance.create');
    }
}
