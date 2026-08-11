<?php

namespace App\Livewire\Arrears;

use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Services\NotificationService;
use Illuminate\Pagination\LengthAwarePaginator;
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
    public int $perPage = 25;

    public bool $showNoteForm = false;

    public ?int $noteTenancyId = null;

    public string $noteText = '';

    public function sendReminder(int $tenancyId): void
    {
        $tenancy = Tenancy::with(['tenant', 'house.block.property'])->findOrFail($tenancyId);
        $this->authorize('update', $tenancy);

        $outstanding = $tenancy->rentCharges()->outstanding()->get()->sum(fn (RentCharge $c) => $c->amount_expected - $c->amount_paid);

        app(NotificationService::class)->notify(
            $tenancy->tenant,
            'rent_reminder',
            'Rent Payment Reminder',
            "This is a reminder that your rent for {$tenancy->house->house_number} at {$tenancy->house->block->property->name} is overdue by KSh ".number_format($outstanding, 2).'. Please make payment as soon as possible.',
            ['in_app', 'email'],
            $tenancy
        );

        session()->flash('status', "Reminder sent to {$tenancy->tenant->name}.");
    }

    public function openNoteForm(int $tenancyId): void
    {
        $this->authorize('update', Tenancy::findOrFail($tenancyId));
        $this->noteTenancyId = $tenancyId;
        $this->noteText = '';
        $this->showNoteForm = true;
    }

    public function addNote(): void
    {
        $this->validate(['noteText' => ['required', 'string', 'max:1000']]);

        $tenancy = Tenancy::with('tenant')->findOrFail($this->noteTenancyId);
        $this->authorize('update', $tenancy);

        $tenant = $tenancy->tenant;
        $stamp = now()->format('d M Y H:i');
        $entry = "[{$stamp}] Arrears note: {$this->noteText}";
        $tenant->update(['notes' => trim(($tenant->notes ? $tenant->notes."\n" : '').$entry)]);

        $this->showNoteForm = false;
        session()->flash('status', 'Note added to tenant record.');
    }

    public function render()
    {
        $user = Auth::user();

        $tenancyIds = RentCharge::outstanding()->distinct()->pluck('tenancy_id');

        $query = Tenancy::whereIn('id', $tenancyIds)->with(['tenant', 'house.block.property']);

        if (! $user->hasRole('admin')) {
            $query->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $user->id));
        }

        $tenancies = $query->get()->map(function (Tenancy $tenancy) {
            $outstanding = $tenancy->rentCharges()->outstanding()->orderBy('due_date')->get();

            return (object) [
                'tenancy' => $tenancy,
                'arrears' => $outstanding->sum(fn (RentCharge $c) => $c->amount_expected - $c->amount_paid),
                'oldestDueDate' => $outstanding->first()?->due_date,
                'daysOverdue' => $outstanding->first()?->due_date?->isPast()
                    ? (int) now()->diffInDays($outstanding->first()->due_date, absolute: true)
                    : 0,
            ];
        })->sortByDesc('arrears')->values();

        $page = $this->getPage();
        $rows = new LengthAwarePaginator(
            $tenancies->forPage($page, $this->perPage)->values(),
            $tenancies->count(),
            $this->perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return view('livewire.arrears.index', [
            'rows' => $rows,
            'totalArrears' => $tenancies->sum('arrears'),
        ]);
    }
}
