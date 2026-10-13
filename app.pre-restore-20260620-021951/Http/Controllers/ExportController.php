<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ContainerInvoiceExport;
use App\Exports\OmsetExport;
use App\Exports\BulkPackagingListExport;
use App\Exports\PackagingListExport;
use App\Exports\InvoiceExport;
use App\Models\Container;
use App\Models\Invoice;
use App\Models\Marking;
use App\Support\InvoiceConsolidation;
use App\Support\InvoicePdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportController extends Controller
{
    public function invoicePdf(Invoice $invoice): Response
    {
        $this->authorizeInvoiceDownload($invoice);

        InvoiceConsolidation::repairDraftConsolidationForInvoice($invoice);
        $invoice = $invoice->fresh();
        $pdfData = InvoicePdfData::forInvoice($invoice);

        $pdf = Pdf::loadView('exports.invoice-pdf', $pdfData)
            ->setPaper('a4', 'landscape');

        return $pdf->download("invoice-{$invoice->invoice_no}.pdf");
    }

    public function invoiceExcel(Invoice $invoice): BinaryFileResponse
    {
        $this->authorizeInvoiceDownload($invoice);

        return Excel::download(new InvoiceExport($invoice), "invoice-{$invoice->invoice_no}.xlsx");
    }

    public function containerInvoicePdf(Container $container): Response
    {
        $this->authorizeInvoiceDownload();

        $pdfData = InvoicePdfData::forContainerGrouped($container);

        if ($pdfData['sections'] === []) {
            abort(404, __('messages.no_barang_for_marking'));
        }

        $pdf = Pdf::loadView('exports.invoice-pdf', $pdfData)
            ->setPaper('a4', 'landscape');

        return $pdf->download("invoice-all-{$container->partai}.pdf");
    }

    public function containerMarkingInvoicePdf(Request $request, Container $container): Response
    {
        $this->authorizeInvoiceDownload();

        $data = $request->validate([
            'marking' => 'nullable|string|max:255',
        ]);

        $container->load('barangs.customer', 'port');

        $marking = $data['marking'] ?? null;
        $barangs = $container->barangs
            ->when(
                $marking === null || $marking === '',
                fn (Collection $items) => $items->filter(fn ($barang) => blank($barang->marking)),
                fn (Collection $items) => $items->where('marking', $marking),
            )
            ->values();

        if ($barangs->isEmpty()) {
            abort(404, __('messages.no_barang_for_marking'));
        }

        $markingLabel = $marking ?: 'Tanpa Marking';

        $pdfData = InvoicePdfData::forBarangs($barangs, [
            'partai' => $container->partai,
            'document_no' => $container->partai.'-'.str($markingLabel)->slug()->toString(),
        ]);
        $pdf = Pdf::loadView('exports.invoice-pdf', $pdfData)
            ->setPaper('a4', 'landscape');

        $slug = str($markingLabel)->slug()->toString();

        return $pdf->download("invoice-{$container->partai}-{$slug}.pdf");
    }

    public function containerInvoiceExcel(Container $container): BinaryFileResponse
    {
        $this->authorizeInvoiceDownload();

        return Excel::download(new ContainerInvoiceExport($container), "invoice-all-{$container->partai}.xlsx");
    }

    public function packingListPdf(Container $container): Response
    {
        abort_unless(auth()->user()?->can('export packing list'), 403);

        $container->load('barangs.dimensions', 'port');

        $pdf = Pdf::loadView('exports.packing-list-pdf', compact('container'));

        return $pdf->download("packing-list-{$container->partai}.pdf");
    }

    public function packingListExcel(Container $container): StreamedResponse
    {
        abort_unless(auth()->user()?->can('export packing list'), 403);

        $container->load([
            'barangs.products' => static fn ($query) => $query->orderBy('sort_order'),
            'barangs.customer',
            'marking',
            'port',
        ]);

        return (new PackagingListExport($container))->download();
    }

    public function packingListBulkExcel(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()?->can('export packing list'), 403);

        $data = $request->validate([
            'container_ids' => 'nullable|array',
            'container_ids.*' => 'integer|exists:containers,id',
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:30',
        ]);

        $query = Container::query()
            ->with([
                'barangs.products' => static fn ($query) => $query->orderBy('sort_order')->with('parent'),
                'barangs.customer',
                'marking',
                'port',
            ])
            ->orderBy('partai');

        if (! empty($data['container_ids'])) {
            $query->whereIn('id', $data['container_ids']);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['search'])) {
            $term = '%' . trim((string) $data['search']) . '%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('partai', 'like', $term)
                    ->orWhere('container_no', 'like', $term)
                    ->orWhere('no_bl', 'like', $term)
                    ->orWhereHas('creator', static fn ($creator) => $creator->where('name', 'like', $term));
            });
        }

        $containers = $query->get();
        abort_if($containers->isEmpty(), 404, __('common.no_data'));

        return (new BulkPackagingListExport($containers))->download();
    }

    // ── Omset Report ──

    public function omsetIndex(Request $request): View
    {
        abort_unless(auth()->user()?->can('export omset'), 403);

        $periodType = $request->get('period', 'bulanan');
        $year = (int) $request->get('year', now()->year);
        $month = $request->get('month') ? (int) $request->get('month') : null;
        $week = $request->get('week') ? (int) $request->get('week') : null;

        return view('exports.omset-report', compact(
            'periodType', 'year', 'month', 'week',
        ));
    }

    public function omsetData(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('export omset'), 403);

        $periodType = $request->get('period', 'bulanan');
        $year = (int) $request->get('year', now()->year);
        $month = $request->filled('month') ? (int) $request->get('month') : null;
        $week = $request->filled('week') ? (int) $request->get('week') : null;

        [$rows, $totals, $periodLabel, $rangeLabel] = $this->buildOmsetData($periodType, $year, $month, $week);

        $exportQuery = array_filter([
            'period' => $periodType,
            'year' => $year,
            'month' => $month,
            'week' => $week,
        ], static fn ($value) => $value !== null && $value !== '');

        return response()->json([
            'html' => view('exports.partials.omset-results', [
                'rows' => $rows,
                'totals' => $totals,
                'periodLabel' => $periodLabel,
                'rangeLabel' => $rangeLabel,
                'exportQuery' => $exportQuery,
            ])->render(),
            'row_count' => $rows->count(),
        ]);
    }

    public function omsetExcel(Request $request): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('export omset'), 403);

        $periodType = $request->get('period', 'bulanan');
        $year = (int) $request->get('year', now()->year);
        $month = $request->get('month') ? (int) $request->get('month') : null;
        $week = $request->get('week') ? (int) $request->get('week') : null;

        [$rows, $totals, $periodLabel, $rangeLabel] = $this->buildOmsetData($periodType, $year, $month, $week);

        $filename = 'omset-' . $periodType . '-' . $year . ($month ? '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) : '') . '.xlsx';

        return Excel::download(new OmsetExport($rows, $totals, $periodLabel), $filename);
    }

    public function omsetPdf(Request $request): Response
    {
        abort_unless(auth()->user()?->can('export omset'), 403);

        $periodType = $request->get('period', 'bulanan');
        $year = (int) $request->get('year', now()->year);
        $month = $request->get('month') ? (int) $request->get('month') : null;
        $week = $request->get('week') ? (int) $request->get('week') : null;

        [$rows, $totals, $periodLabel, $rangeLabel] = $this->buildOmsetData($periodType, $year, $month, $week);

        $filename = 'omset-' . $periodType . '-' . $year . ($month ? '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) : '') . '.pdf';

        $pdf = Pdf::loadView('exports.omset-report-pdf', compact('rows', 'totals', 'periodLabel', 'rangeLabel'))
            ->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    /**
     * @return array{0: Collection, 1: array, 2: string, 3: string}
     */
    private function buildOmsetData(string $periodType, int $year, ?int $month, ?int $week): array
    {
        $periodLabel = match ($periodType) {
            'mingguan' => 'Mingguan',
            'bulanan' => 'Bulanan',
            'tahunan' => 'Tahunan',
            default => 'Bulanan',
        };

        $query = Invoice::where('status', 'paid')
            ->whereYear('created_at', $year);

        if ($periodType === 'mingguan' && $month) {
            $query->whereMonth('created_at', $month);
        } elseif ($periodType === 'bulanan' && $month) {
            $query->whereMonth('created_at', $month);
        }

        $user = auth()->user();
        if (Marking::userHasTeamMarkingScope($user)) {
            Marking::restrictInvoicesQuery($query, $user);
        }

        $invoiceIds = $query->pluck('id');

        $periodFormat = match ($periodType) {
            'mingguan' => "CONCAT('Minggu ', WEEK(invoices.created_at, 1))",
            'bulanan' => "DATE_FORMAT(invoices.created_at, '%Y-%m')",
            'tahunan' => "YEAR(invoices.created_at)",
            default => "DATE_FORMAT(invoices.created_at, '%Y-%m')",
        };

        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('barangs', 'invoice_items.barang_id', '=', 'barangs.id')
            ->whereIn('invoices.id', $invoiceIds)
            ->select(
                DB::raw("{$periodFormat} as period"),
                DB::raw("COALESCE(barangs.marking, 'Tanpa Marking') as marking"),
                DB::raw('COUNT(DISTINCT barangs.id) as shipment_count'),
                DB::raw('SUM(invoice_items.cbm) as total_cbm'),
                DB::raw('SUM(barangs.total_gross_weight) as total_gw'),
                DB::raw('SUM(invoice_items.line_total) as omset'),
            )
            ->groupBy('period', 'marking')
            ->orderBy('period')
            ->orderByDesc('omset')
            ->get()
            ->map(fn ($r) => [
                'period' => (string) $r->period,
                'marking' => $r->marking,
                'shipment_count' => (int) $r->shipment_count,
                'total_cbm' => (float) $r->total_cbm,
                'total_gw' => (float) $r->total_gw,
                'omset' => (float) $r->omset,
            ]);

        $totals = [
            'shipment_count' => $rows->sum('shipment_count'),
            'total_cbm' => $rows->sum('total_cbm'),
            'total_gw' => $rows->sum('total_gw'),
            'omset' => $rows->sum('omset'),
        ];

        $rangeLabel = match ($periodType) {
            'mingguan' => $month
                ? Carbon::create($year, $month)->translatedFormat('F Y')
                : (string) $year,
            'bulanan' => $month
                ? Carbon::create($year, $month)->translatedFormat('F Y')
                : "Tahun {$year}",
            'tahunan' => "Tahun {$year}",
            default => "Tahun {$year}",
        };

        return [$rows, $totals, $periodLabel, $rangeLabel];
    }

    private function authorizeInvoiceDownload(?Invoice $invoice = null): void
    {
        abort_unless(auth()->user()?->canAny(['view invoice', 'export invoice']), 403);

        if ($invoice !== null) {
            abort_unless(Marking::invoiceVisibleToUser($invoice), 403);
        }
    }
}
