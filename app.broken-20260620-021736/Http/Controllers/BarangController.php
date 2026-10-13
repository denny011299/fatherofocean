<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\BarangDimension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MasterBranch;
use App\Models\MasterCbmType;
use App\Models\MasterClassification;
use App\Models\MasterCountUnit;
use App\Models\MasterDepartment;
use App\Models\MasterPacking;
use App\Models\City;
use App\Models\Marking;
use App\Models\MasterPackagingType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

final class BarangController extends Controller
{
    public function index(Request $request): View
    {
        $customers = User::role('customer')->get();
        $departments = MasterDepartment::active()->orderBy('name')->get();
        $branches = MasterBranch::active()->orderBy('code')->get();
        $destinations = City::active()->orderBy('name')->get();
        $packagingTypes = MasterPackagingType::active()->orderBy('name')->get();
        $cbmTypes = MasterCbmType::active()->orderBy('name')->get();
        $classifications = MasterClassification::active()->orderBy('name')->get();
        $countUnits = MasterCountUnit::active()->orderBy('name')->get();
        $markings = Marking::forDropdown()->get(['id', 'code', 'customer_name', 'user_id']);

        return view('barang.index', compact(
            'customers', 'departments',
            'branches', 'destinations', 'packagingTypes',
            'cbmTypes', 'classifications', 'countUnits', 'markings'
        ));
    }

    public function data(): JsonResponse
    {
        $barangs = Barang::with('supplier:id,name')
            ->latest()
            ->get()
            ->map(fn ($b) => [
                'id'                 => $b->id,
                'no_index'           => $b->no_index,
                'no_po'              => $b->no_po,
                'marking'            => $b->marking,
                'warehouse_origin'   => $b->warehouse_origin,
                'ctns'               => $b->ctns,
                'total_cbm'          => $b->total_cbm,
                'total_gross_weight' => $b->total_gross_weight,
                'supplier_name'      => $b->supplier?->name ?? $b->supplier_chinese ?? '-',
                'no_resi'            => $b->no_resi ?? [],
                'status'             => $b->status,
                'raw_status'         => $b->status,
                'status_badge'       => $b->status_badge,
                'has_invoice'        => (bool) $b->invoice_file,
            ]);

        return response()->json($barangs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'no_index' => 'required|string|max:50|unique:barangs',
            'no_po' => 'nullable|string|max:50',
            'marking' => 'nullable|string|max:100',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'supplier_chinese' => 'nullable|string|max:100',
            'customer_name' => 'required|string|max:150',
            'user_id' => 'nullable|exists:users,id',
            'warehouse_origin' => 'required|string|max:50',
            'tanggal_masuk' => 'nullable|date',
            'ctns' => 'nullable|integer|min:0',
            'pcs' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'material' => 'nullable|string|max:100',
            'form' => 'nullable|string|max:100',
            'material_desc' => 'nullable|string',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_nominal_standar' => 'nullable|numeric|min:0',
            'cbm_type' => 'nullable|string|max:20',
            'rate' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'price_per_cbm' => 'nullable|numeric|min:0',
            'destination' => 'nullable|string|max:100',
            'other' => 'nullable|string|max:100',
            'packing_id' => 'nullable|exists:master_packings,id',
            'no_resi' => 'nullable|string',
            'notes' => 'nullable|string',
            'notes_id' => 'nullable|string',
            'dimensions' => 'nullable|array',
            'dimensions.*.item_name' => 'nullable|string|max:100',
            'dimensions.*.ctn_no' => 'nullable|string|max:30',
            'dimensions.*.qty_ctn' => 'nullable|integer|min:0',
            'dimensions.*.panjang' => 'nullable|numeric|min:0',
            'dimensions.*.lebar' => 'nullable|numeric|min:0',
            'dimensions.*.tinggi' => 'nullable|numeric|min:0',
            'dimensions.*.ctn_count' => 'nullable|integer|min:1',
            'dimensions.*.qty' => 'nullable|integer|min:0',
            'dimensions.*.count_by' => 'nullable|string|max:20',
            'dimensions.*.gw_per_ctn' => 'nullable|numeric|min:0',
            'dimensions.*.gross_weight' => 'nullable|numeric|min:0',
        ]);

        if (isset($data['no_resi']) && is_string($data['no_resi'])) {
            $decoded = json_decode($data['no_resi'], true);
            $data['no_resi'] = is_array($decoded) ? $decoded : [];
        }

        $dimensions = $data['dimensions'] ?? [];
        unset($data['dimensions']);

        $data['status'] = 'inbound';
        $data['created_by'] = auth()->id();

        if (!empty($data['marking']) && empty($data['user_id'])) {
            $marking = Marking::where('code', $data['marking'])->first();
            if ($marking) {
                $data['user_id'] = $marking->user_id;
            }
        }

        $result = DB::transaction(function () use ($data, $dimensions) {
            $barang = Barang::create($data);

            if (!empty($dimensions)) {
                foreach ($dimensions as $dim) {
                    $barang->dimensions()->create($dim);
                }
                $barang->recalculateTotals();
            }

            $invoice = $this->createInvoiceForBarang($barang);

            return ['barang' => $barang, 'invoice' => $invoice];
        });

        $barang = $result['barang'];
        $invoice = $result['invoice'];

        ActivityLog::record('Barang created', $barang);
        if ($invoice) {
            ActivityLog::record('Invoice generated', $invoice);
        }

        $message = 'Barang berhasil ditambahkan.';
        if ($invoice) {
            $message .= ' Invoice ' . $invoice->invoice_no . ' juga telah dibuat.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $barang->load('dimensions'),
            'invoice' => $invoice,
        ]);
    }

    public function show(Barang $barang): JsonResponse
    {
        return response()->json($barang->load('customer', 'dimensions', 'trackingStatuses'));
    }

    public function update(Request $request, Barang $barang): JsonResponse
    {
        $old = $barang->toArray();

        $data = $request->validate([
            'no_po' => 'nullable|string|max:50',
            'marking' => 'nullable|string|max:100',
            'supplier_chinese' => 'nullable|string|max:100',
            'user_id' => 'nullable|exists:users,id',
            'warehouse_origin' => 'required|string|max:50',
            'tanggal_masuk' => 'nullable|date',
            'ctns' => 'nullable|integer|min:0',
            'pcs' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'material' => 'nullable|string|max:100',
            'form' => 'nullable|string|max:100',
            'material_desc' => 'nullable|string',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_nominal_standar' => 'nullable|numeric|min:0',
            'insurance_nominal_custom' => 'nullable|numeric|min:0',
            'cbm_type' => 'nullable|string|max:20',
            'rate' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'price_per_cbm' => 'nullable|numeric|min:0',
            'destination' => 'nullable|string|max:100',
            'other' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'notes_id' => 'nullable|string',
        ]);

        $barang->update($data);
        ActivityLog::record('Barang updated', $barang, $old, $barang->fresh()->toArray());

        return response()->json(['success' => true, 'message' => 'Barang berhasil diperbarui.']);
    }

    public function destroy(Barang $barang): JsonResponse
    {
        DB::transaction(function () use ($barang) {
            $invoiceIds = $barang->invoiceItems()->pluck('invoice_id')->unique();

            ActivityLog::record('Barang deleted', $barang);
            $barang->invoiceItems()->delete();
            $barang->containers()->detach();
            $barang->delete();

            foreach ($invoiceIds as $invId) {
                $inv = Invoice::find($invId);
                if (!$inv) continue;
                $inv->recalculate();
                if ($inv->items()->count() === 0) {
                    $inv->delete();
                }
            }
        });

        return response()->json(['success' => true, 'message' => 'Barang berhasil dihapus.']);
    }

    public function updateDimensions(Request $request, Barang $barang): JsonResponse
    {
        $data = $request->validate(
            [
                'dimensions' => 'required|array',
                'dimensions.*.id' => 'nullable|exists:barang_dimensions,id',
                'dimensions.*.item_name' => 'nullable|string|max:100',
                'dimensions.*.ctn_no' => 'nullable|string|max:30',
                'dimensions.*.qty_ctn' => 'integer|min:0',
                'dimensions.*.panjang' => 'numeric|min:0',
                'dimensions.*.lebar' => 'numeric|min:0',
                'dimensions.*.tinggi' => 'numeric|min:0',
                'dimensions.*.ctn_count' => 'integer|min:1',
                'dimensions.*.qty' => 'integer|min:0',
                'dimensions.*.count_by' => 'nullable|string|max:20',
                'dimensions.*.gw_per_ctn' => 'numeric|min:0',
                'dimensions.*.gross_weight' => 'numeric|min:0',
            ],
            $this->barangValidationMessages(),
            $this->barangValidationAttributes()
        );

        DB::transaction(function () use ($data, $barang) {
            $keepIds = [];
            foreach ($data['dimensions'] as $dim) {
                if (!empty($dim['id'])) {
                    $existing = $barang->dimensions()->find($dim['id']);
                    if (!$existing) continue;
                    $existing->update($dim);
                    $keepIds[] = $dim['id'];
                } else {
                    $created = $barang->dimensions()->create($dim);
                    $keepIds[] = $created->id;
                }
            }

            $barang->dimensions()->whereNotIn('id', $keepIds)->delete();
            $barang->recalculateTotals();
        });

        $barang->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Dimensi berhasil diperbarui.',
            'total_cbm' => $barang->total_cbm,
            'total_gw' => $barang->total_gross_weight,
        ]);
    }

    public function lookupIndex(Request $request): JsonResponse
    {
        $barang = Barang::with('dimensions')
            ->where('no_index', $request->get('no_index'))
            ->first();

        if (!$barang) {
            return response()->json(['found' => false]);
        }

        return response()->json(['found' => true, 'data' => $barang]);
    }

    private function createInvoiceForBarang(Barang $barang): ?Invoice
    {
        if (!$barang->user_id || $barang->invoiceItems()->exists()) {
            return null;
        }

        $pricePerCbm = (float) ($barang->price_per_cbm ?? 0);
        $insuranceAmount = (float) $barang->insurance_value * ((float) $barang->insurance_rate / 100);
        $due = \App\Support\InvoiceDueResolver::resolveForBarang($barang);

        $invoice = Invoice::create([
            'invoice_no' => Invoice::generateNumber(),
            'user_id' => $barang->user_id,
            'price_per_cbm' => $pricePerCbm,
            'status' => \App\Support\InvoiceDueResolver::statusForResolved($due, 'draft'),
            'due_date' => $due['due_date'],
            'created_by' => auth()->id(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'barang_id' => $barang->id,
            'cbm' => $barang->total_cbm,
            'price_per_cbm' => $pricePerCbm,
            'insurance' => $insuranceAmount,
            'extra' => (float) ($barang->extra_charge ?? 0),
        ]);

        $invoice->recalculate();

        return $invoice;
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(
            ['file' => 'required|mimes:xlsx,xls,csv|max:10240'],
            [],
            $this->barangValidationAttributes()
        );

        try {
            Excel::import(new \App\Imports\BarangImport(), $request->file('file'));

            return response()->json(['success' => true, 'message' => 'Data preload berhasil diimport.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Gagal import: ' . $e->getMessage()], 422);
        }
    }
}
