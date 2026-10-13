<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ContainerInvoiceExport;
use App\Exports\PackagingListExport;
use App\Exports\InvoiceExport;
use App\Models\Container;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ExportController extends Controller
{
    public function invoicePdf(Invoice $invoice): Response
    {
        $invoice->load('items.barang', 'customer');

        $pdf = Pdf::loadView('exports.invoice-pdf', compact('invoice'));

        return $pdf->download("invoice-{$invoice->invoice_no}.pdf");
    }

    public function invoiceExcel(Invoice $invoice): BinaryFileResponse
    {
        return Excel::download(new InvoiceExport($invoice), "invoice-{$invoice->invoice_no}.xlsx");
    }

    public function containerInvoicePdf(Container $container): Response
    {
        $container->load('barangs.customer', 'vessel');

        $pdf = Pdf::loadView('exports.container-invoice-pdf', compact('container'));

        return $pdf->download("invoice-all-{$container->code}.pdf");
    }

    public function containerMarkingInvoicePdf(Request $request, Container $container): Response
    {
        $data = $request->validate([
            'marking' => 'nullable|string|max:255',
        ]);

        $container->load('barangs.customer', 'vessel');

        $marking = $data['marking'] ?? null;
        $barangs = $container->barangs
            ->when(
                $marking === null || $marking === '',
                fn (Collection $items) => $items->filter(fn ($barang) => blank($barang->marking)),
                fn (Collection $items) => $items->where('marking', $marking),
            )
            ->values();

        if ($barangs->isEmpty()) {
            abort(404, 'Tidak ada barang untuk marking ini di container.');
        }

        $container->setRelation('barangs', $barangs);
        $markingLabel = $marking ?: 'Tanpa Marking';

        $pdf = Pdf::loadView('exports.container-invoice-pdf', [
            'container' => $container,
            'markingLabel' => $markingLabel,
        ]);

        $slug = str($markingLabel)->slug()->toString();

        return $pdf->download("invoice-{$container->code}-{$slug}.pdf");
    }

    public function containerInvoiceExcel(Container $container): BinaryFileResponse
    {
        return Excel::download(new ContainerInvoiceExport($container), "invoice-all-{$container->code}.xlsx");
    }

    public function packingListPdf(Container $container): Response
    {
        $container->load('barangs.dimensions', 'vessel');

        $pdf = Pdf::loadView('exports.packing-list-pdf', compact('container'));

        return $pdf->download("packing-list-{$container->code}.pdf");
    }

    public function packingListExcel(Container $container): BinaryFileResponse
    {
        return Excel::download(new PackagingListExport($container), "packing-list-{$container->code}.xlsx");
    }

    // ── Omset Report ──

    public function omsetIndex(Request $request): View
    {
        $periodType = $request->get('period', 'bulanan');
        $year = (int) $request->get('year', now()->year);
        $month = $request->get('month') ? (int) $request->get('month') : null;
        $week = $request->get('week') ? (int) $request->get('week') : null;

        $rows = collect();
        $totals = ['shipment_count' => 0, 'total_cbm' => 0, 'total_gw' => 0, 'omset' => 0];
        $periodLabel = '';
        $rangeLabel = '';

        if ($request->has('period')) {
            [$rows, $totals, $periodLabel, $rangeLabel] = $this->buildOmsetData($periodType, $year, $month, $week);
        }

        return view('exports.omset-report', compact(
            'rows', 'totals', 'periodType', 'periodLabel', 'rangeLabel',
            'year', 'month', 'week',
        ));
    }

    public function omsetExcel(Request $request): BinaryFileResponse
    {
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
}
