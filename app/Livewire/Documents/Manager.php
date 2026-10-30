<?php

namespace App\Livewire\Documents;

use App\Models\DepositDeduction;
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
        'deposit_deduction_evidence' => 'Deposit Deduction Evidence',
        'tenancy_notice_evidence' => 'Tenancy Notice Evidence',
        'change_request_evidence' => 'Change Request Evidence',
        'payout_evidence' => 'Payout Evidence',
        'other' => 'Other',
    ];

    /**
     * What this generic uploader offers — everything except Lease
     * Agreement, which used to be pickable here too and created a Document
     * with no actual Contract behind it (no version/signing tracking, not
     * reachable from the Contracts overview). A lease agreement now only
     * ever comes from the Contract section's "Generate" or "Upload My Own",
     * which create a real Contract row alongside the file. CATEGORIES
     * itself stays the full list, since existing lease_agreement documents
     * still need their label looked up wherever they're displayed.
     */
    public const UPLOADABLE_CATEGORIES = [
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

    /**
     * Named save(), not upload() — "upload" collides with Livewire's own
     * reserved client-side $wire.upload file-upload helper. A server action
     * method named upload() causes wire:submit="upload" to resolve to that
     * JS-internal helper instead of calling this PHP method, which then
     * crashes trying to read a filename off the (undefined) arguments a
     * form-submit event actually passes it. Confirmed via reproduction:
     * this exact form silently failed on every submit until renamed.
     */
    public function save(): void
    {
        $this->authorizeSubject($this->model);
        $this->authorize('create', Document::class);

        $this->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'category' => ['required', 'string', 'in:'.implode(',', array_keys(self::UPLOADABLE_CATEGORIES))],
        ]);

        $path = $this->file->store('documents', 'local');

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

        // Mirrors Documents\Index::delete() — this widget is the other
        // place a lease agreement's file can be reached from (embedded on
        // the Tenancy page), and Document::booted() backstops this either
        // way, but a silent no-op here would look like a bug.
        if ($document->contract()->exists()) {
            $this->addError('delete', 'Lease agreements can\'t be deleted — generate a new version instead, which keeps this one on record.');

            return;
        }

        $document->delete();
    }

    public function render()
    {
        return view('livewire.documents.manager', [
            'documents' => $this->model->documents()->latest()->get(),
            'categoryOptions' => self::CATEGORIES,
            'uploadableCategoryOptions' => self::UPLOADABLE_CATEGORIES,
        ]);
    }
}
