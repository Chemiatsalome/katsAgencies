<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Models\Expense;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $category = 'all';

    #[Url]
    public int $perPage = 25;

    public function delete(int $documentId): void
    {
        $document = Document::findOrFail($documentId);
        $this->authorize('delete', $document);
        $document->delete();
    }

    public function render()
    {
        $landlordId = Auth::id();

        $query = Document::whereHasMorph(
            'documentable',
            [Property::class, House::class, Tenancy::class, Tenant::class, Expense::class, MaintenanceTask::class],
            function ($q, $type) use ($landlordId) {
                match ($type) {
                    Property::class => $q->where('landlord_id', $landlordId),
                    House::class => $q->whereHas('block.property', fn ($q2) => $q2->where('landlord_id', $landlordId)),
                    Tenancy::class => $q->whereHas('house.block.property', fn ($q2) => $q2->where('landlord_id', $landlordId)),
                    Tenant::class => $q->whereHas('tenancies.house.block.property', fn ($q2) => $q2->where('landlord_id', $landlordId)),
                    Expense::class, MaintenanceTask::class => $q->whereHas('property', fn ($q2) => $q2->where('landlord_id', $landlordId)),
                    default => $q->whereRaw('1 = 0'),
                };
            }
        )->with('documentable');

        if ($this->category !== 'all') {
            $query->where('category', $this->category);
        }

        return view('livewire.documents.index', [
            'documents' => $query->latest()->paginate($this->perPage),
            'categoryOptions' => \App\Livewire\Documents\Manager::CATEGORIES,
        ]);
    }
}
