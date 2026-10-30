<?php

namespace App\Livewire\Reports;

use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
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
    public string $type = 'rent_collection';

    #[Url]
    public string $year = '';

    #[Url]
    public string $month = '';

    #[Url]
    public int $perPage = 25;

    // Report types whose underlying query actually filters by year/month.
    // The rest (arrears/cash_flow/property_performance/occupancy/vacancy/
    // contract_expiry/deposits) always show current-state or trailing-12-
    // month data regardless of these controls — showing the Year/Month
    // selectors for them would silently do nothing and mislead the landlord
    // into thinking they changed what they're looking at.
    public const DATE_FILTERABLE_TYPES = ['rent_collection', 'income', 'expenses', 'maintenance_cost', 'payment_history'];

    public function mount(): void
    {
        // No manager permission unlocks this page — financial reports stay
        // landlord-only regardless of what else a manager has been granted.
        abort_unless(Auth::user()->hasRole('landlord'), 403);

        if ($this->year === '') {
            $this->year = (string) now()->year;
        }
    }

    private function build(): array
    {
        return app(ReportService::class)->generate($this->type, Auth::id(), [
            'year' => (int) $this->year,
            'month' => $this->month !== '' ? (int) $this->month : null,
        ]);
    }

    public function exportCsv()
    {
        $report = $this->build();
        $filename = $this->type.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $report['columns']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename);
    }

    public function exportPdf()
    {
        $report = $this->build();
        $title = $this->available()[$this->type] ?? 'Report';

        $pdf = Pdf::loadView('reports.pdf', ['report' => $report, 'title' => $title]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->type.'-'.now()->format('Y-m-d').'.pdf'
        );
    }

    private function available(): array
    {
        return app(ReportService::class)->available();
    }

    public function render()
    {
        $report = $this->build();

        $page = $this->getPage();
        $rows = collect($report['rows']);
        $paginatedRows = new LengthAwarePaginator(
            $rows->forPage($page, $this->perPage)->values(),
            $rows->count(),
            $this->perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return view('livewire.reports.index', [
            'types' => $this->available(),
            'report' => $report,
            'rows' => $paginatedRows,
            'isDateFilterable' => in_array($this->type, self::DATE_FILTERABLE_TYPES, true),
        ]);
    }
}
