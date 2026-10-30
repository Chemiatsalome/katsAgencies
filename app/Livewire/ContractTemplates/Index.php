<?php

namespace App\Livewire\ContractTemplates;

use App\Models\ContractTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', ContractTemplate::class);
    }

    public function setDefault(int $templateId): void
    {
        $template = ContractTemplate::findOrFail($templateId);
        $this->authorize('update', $template);

        // Scoped to the same property (or the landlord-wide fallback slot
        // when this template has none) — see ContractTemplates\Form::save()
        // for why a flat landlord-wide unset would be wrong here.
        ContractTemplate::where('landlord_id', $template->landlord_id)
            ->where(fn ($q) => $template->property_id ? $q->where('property_id', $template->property_id) : $q->whereNull('property_id'))
            ->where('id', '!=', $template->id)
            ->update(['is_default' => false]);

        $template->update(['is_default' => true]);
    }

    public function toggleActive(int $templateId): void
    {
        $template = ContractTemplate::findOrFail($templateId);
        $this->authorize('update', $template);

        $template->update(['is_active' => ! $template->is_active]);
    }

    public function render()
    {
        return view('livewire.contract-templates.index', [
            'templates' => ContractTemplate::accessibleBy(Auth::user())
                ->with('property')
                ->withCount('contracts')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
