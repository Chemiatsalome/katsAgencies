<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Models\Expense;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class Manager extends Component
{
    use WithFileUploads;

    public const CATEGORIES = [
        'lease_agreement' => 'Lease Agreement',
        'id_document' => 'ID Document',
        'property_document' => 'Property Document',
        'receipt' => 'Receipt',
        'payment_confirmation' => 'Payment Confirmation',
        'maintenance_invoice' => 'Maintenance Invoice',
        'inspection_report' => 'Inspection Report',
        'landlord_document' => 'Landlord Document',
        'other' => 'Other',
    ];

    #[Locked]
    public Model $model;

    public string $category = 'other';

    public $file;

    public function mount(Model $model, string $category = 'other'): void
    {
        $this->authorizeSubject($model);
        $this->model = $model;
        $this->category = $category;
    }

    private function authorizeSubject(Model $model): void
    {
        match (true) {
            $model instanceof Property, $model instanceof House, $model instanceof Tenancy,
            $model instanceof Tenant, $model instanceof Expense, $model instanceof MaintenanceTask => $this->authorize('view', $model),
            default => abort(403),
        };
    }

    public function upload(): void
    {
        $this->authorizeSubject($this->model);
        $this->authorize('create', Document::class);

        $this->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'category' => ['required', 'string'],
        ]);

        $path = $this->file->store('documents', 'public');

        Document::create([
            'documentable_type' => $this->model->getMorphClass(),
            'documentable_id' => $this->model->getKey(),
            'category' => $this->category,
            'original_filename' => $this->file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $this->file->getMimeType(),
            'size' => $this->file->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        $this->reset('file');
        session()->flash('status', 'Document uploaded.');
    }

    public function delete(int $documentId): void
    {
        $document = Document::findOrFail($documentId);
        $this->authorize('delete', $document);
        $document->delete();
    }

    public function render()
    {
        return view('livewire.documents.manager', [
            'documents' => $this->model->documents()->latest()->get(),
            'categoryOptions' => self::CATEGORIES,
        ]);
    }
}
