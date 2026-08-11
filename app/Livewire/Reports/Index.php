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

    public function mount(): void
    {
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
        ]);
    }
}
