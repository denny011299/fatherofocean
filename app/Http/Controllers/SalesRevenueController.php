<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SalesRevenueExport;
use App\Models\Barang;
use App\Models\Marking;
use App\Models\SalesCommission;
use App\Models\SalesMarkingPayout;
use App\Models\User;
use App\Support\CompanyRevenueSettings;
use App\Support\PartaiFilterOptions;
use App\Support\SalesCommissionSettings;
use App\Support\SalesIncentiveService;
use App\Support\SalesMarkingCommissionDetail;
use App\Support\SalesRevenueCalculator;
use App\Support\SalesSalaryAggregator;
use App\Support\ShipmentEarningsSettlement;
use App\Support\UploadedImageCompressor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SalesRevenueController extends Controller
{
    public function salesIndex(): View
    {
        abort_unless($this->canView(), 403);

        return view('finance.sales-earnings', $this->sharedViewData());
    }

    public function companyIndex(): View
    {
        $this->assertCanViewCompanyRevenue();

        $salesIds = Marking::query()
            ->whereNotNull('sales_id')
            ->distinct()
            ->pluck('sales_id');

        $marketingOptions = User::query()
            ->whereIn('id', $salesIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('finance.company-revenue', array_merge($this->sharedViewData(), [
            'marketingOptions' => $marketingOptions,
        ]));
    }

    public function companyMarkingOptions(Request $request): JsonResponse
    {
        $this->assertCanViewCompanyRevenue();

        $request->validate(['sales_id' => 'required|integer|exists:users,id']);

        $markings = Marking::query()
            ->active()
            ->where('sales_id', (int) $request->input('sales_id'))
            ->orderBy('code')
            ->get(['code', 'customer_name'])
            ->map(static fn (Marking $marking): array => [
                'code' => $marking->code,
                'label' => trim($marking->code.($marking->customer_name ? ' — '.$marking->customer_name : '')),
            ])
            ->values();

        return response()->json($markings);
    }

    public function companySummary(Request $request): JsonResponse
    {
        $this->assertCanViewCompanyRevenue();

        $period = (string) $request->input('period', now()->format('Y-m'));
        SalesSalaryAggregator::assertValidPeriod($period);
        $range = SalesSalaryAggregator::periodDateRange($period);

        $filters = $this->companyFiltersFromRequest($request);

        $totals = SalesRevenueCalculator::totalsForUser(
            $this->scopedUser(auth()->user()),
            $range['date_from'],
            $range['date_to'],
            $filters['marketing_id'],
            $filters['excluded_markings'],
            $filters['partai'],
        );

        return response()->json([
            'estimasi_company' => $totals['estimasi_company'],
            'fix_company' => $totals['fix_company'],
            'category_totals' => $totals['category_totals'] ?? [],
        ]);
    }

    /** @return array<string, mixed> */
    private function sharedViewData(): array
    {
        $user = auth()->user();
        $isScopedSales = $user?->hasAnyRole(['sales', 'supervisor']) ?? false;

        $canViewIncentive = $user !== null && $user->can('view sales incentive');
        $incentivePeriod = now()->format('Y-m');

        return [
            'totals' => SalesRevenueCalculator::totalsForUser($this->scopedUser($user)),
            'incentiveTotals' => $canViewIncentive
                ? SalesIncentiveService::summaryTotals($incentivePeriod, SalesSalaryAggregator::resolveScopedSalesId())
                : null,
            'incentivePeriod' => $incentivePeriod,
            'isSales' => $isScopedSales,
            'isSupervisor' => $user?->hasRole('supervisor') ?? false,
            'isSupervisorOnly' => $user?->isSupervisorOnly() ?? false,
            'canUploadSalaryProof' => $user?->can('upload sales salary proof') ?? false,
            'canViewCompanyPayout' => $this->canViewCompanyPayout($user),
            'canManageCompanyPayout' => $user?->can('manage company payout') ?? false,
            'canViewIncentive' => $canViewIncentive,
            'commissionRates' => SalesCommissionSettings::current(),
            'companyRevenueFixed' => CompanyRevenueSettings::fixedAmount(),
            'partaiOptions' => PartaiFilterOptions::all(),
        ];
    }

    public function companyData(Request $request): JsonResponse
    {
        $this->assertCanViewCompanyRevenue();

        $filters = $this->companyFiltersFromRequest($request);

        return response()->json(SalesRevenueCalculator::companyRevenueRows(
            $this->scopedUser(auth()->user()),
            $filters['status'],
            $filters['date_from'],
            $filters['date_to'],
            $filters['marketing_id'],
            $filters['excluded_markings'],
            $filters['partai'],
        ));
    }

    public function summary(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);

        $filters = $this->filtersFromRequest($request);

        return response()->json(SalesRevenueCalculator::totalsForUser(
            $this->scopedUser(auth()->user()),
            $filters['date_from'],
            $filters['date_to'],
            null,
            [],
            $filters['partai'],
        ));
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);

        $filters = $this->filtersFromRequest($request);

        $rows = SalesRevenueCalculator::commissionRows(
            $this->scopedUser(auth()->user()),
            $filters['status'],
            $filters['date_from'],
            $filters['date_to'],
            null,
            [],
            $filters['partai'],
        );

        return response()->json($this->enrichCommissionPaymentStatus(
            SalesRevenueCalculator::stripCompanySecretsFromRows($rows),
        ));
    }

    public function commissionMarkingDetail(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);

        $validated = $request->validate([
            'sales_id' => 'required|integer|exists:users,id',
            'marking' => 'required|string|max:100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'partai' => 'nullable|string|max:100',
        ]);

        $salesId = (int) $validated['sales_id'];
        $this->assertCommissionSalesAccess($salesId);

        $partai = trim((string) ($validated['partai'] ?? ''));

        return response()->json(SalesMarkingCommissionDetail::detail(
            $salesId,
            (string) $validated['marking'],
            $this->scopedUser(auth()->user()),
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
            $partai !== '' ? $partai : null,
        ));
    }

    public function updateCommissionMarkingOverride(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('upload sales salary proof'), 403);

        $validated = $request->validate([
            'sales_id' => 'required|integer|exists:users,id',
            'marking' => 'required|string|max:100',
            'commission_rate' => 'required|numeric|min:0|max:100',
            'total_komisi' => 'required|numeric|min:0',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'partai' => 'nullable|string|max:100',
        ]);

        $salesId = (int) $validated['sales_id'];
        $this->assertCommissionSalesAccess($salesId, allowManageAll: true);

        $partai = trim((string) ($validated['partai'] ?? ''));

        $detail = SalesMarkingCommissionDetail::saveOverride(
            $salesId,
            trim((string) $validated['marking']),
            (float) $validated['commission_rate'],
            (float) $validated['total_komisi'],
            auth()->id(),
            $this->scopedUser(auth()->user()),
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
            $partai !== '' ? $partai : null,
        );

        return response()->json([
            'success' => true,
            'message' => __('sales-salary.komisi_override_saved'),
            'detail' => $detail,
        ]);
    }

    /**
     * Idempotent schema setup for commission persenan/total override (Detail Komisi).
     * Prefer opening this URL on production instead of running artisan migrate.
     */
    public function setupCommissionOverrides(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(auth()->user()?->can('upload sales salary proof'), 403);

        $table = 'sales_marking_commission_overrides';
        $createdTable = false;
        $addedColumns = [];

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->foreignId('sales_id')->constrained('users')->cascadeOnDelete();
                $blueprint->string('marking', 100);
                $blueprint->decimal('commission_rate', 5, 2);
                $blueprint->decimal('total_komisi', 15, 2);
                $blueprint->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->timestamps();

                $blueprint->unique(['sales_id', 'marking']);
                $blueprint->index(['sales_id', 'marking']);
            });
            $createdTable = true;
        } else {
            $columnDefs = [
                'sales_id' => static function (Blueprint $blueprint): void {
                    $blueprint->foreignId('sales_id')->after('id')->constrained('users')->cascadeOnDelete();
                },
                'marking' => static function (Blueprint $blueprint): void {
                    $blueprint->string('marking', 100);
                },
                'commission_rate' => static function (Blueprint $blueprint): void {
                    $blueprint->decimal('commission_rate', 5, 2);
                },
                'total_komisi' => static function (Blueprint $blueprint): void {
                    $blueprint->decimal('total_komisi', 15, 2);
                },
                'updated_by' => static function (Blueprint $blueprint): void {
                    $blueprint->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                },
            ];

            foreach ($columnDefs as $column => $definition) {
                if (Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, $definition);
                $addedColumns[] = $column;
            }

            $hasCreatedAt = Schema::hasColumn($table, 'created_at');
            $hasUpdatedAt = Schema::hasColumn($table, 'updated_at');
            if (! $hasCreatedAt && ! $hasUpdatedAt) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->timestamps();
                });
                $addedColumns[] = 'timestamps';
            } elseif (! $hasCreatedAt) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->timestamp('created_at')->nullable();
                });
                $addedColumns[] = 'created_at';
            } elseif (! $hasUpdatedAt) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->timestamp('updated_at')->nullable();
                });
                $addedColumns[] = 'updated_at';
            }
        }

        $parts = [];
        if ($createdTable) {
            $parts[] = "tabel {$table} dibuat";
        } elseif ($addedColumns !== []) {
            $parts[] = 'kolom ditambahkan: '.implode(', ', $addedColumns);
        } else {
            $parts[] = "tabel {$table} sudah siap (tidak ada perubahan)";
        }

        $message = 'Setup commission override: '.implode('; ', $parts).'.';

        if ($request->expectsJson() || $request->boolean('json')) {
            return response()->json([
                'success' => true,
                'created_table' => $createdTable,
                'added_columns' => $addedColumns,
                'table' => $table,
                'message' => $message,
            ]);
        }

        return redirect()
            ->to($request->query('redirect', route('sales-revenue.index')))
            ->with('success', $message);
    }

    /**
     * Idempotent schema setup for sales marking payouts (Tambah Bayar on Detail Komisi).
     * Prefer opening this URL on production instead of running artisan migrate.
     */
    public function setupMarkingPayouts(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(auth()->user()?->can('upload sales salary proof'), 403);

        $table = 'sales_marking_payouts';
        $createdTable = false;
        $addedColumns = [];

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->foreignId('sales_id')->constrained('users')->cascadeOnDelete();
                $blueprint->string('marking', 100);
                $blueprint->decimal('amount', 15, 2);
                $blueprint->date('payout_date');
                $blueprint->string('proof_file');
                $blueprint->text('note')->nullable();
                $blueprint->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $blueprint->timestamps();

                $blueprint->index(['sales_id', 'marking']);
                $blueprint->index('payout_date');
            });
            $createdTable = true;
        } else {
            $columnDefs = [
                'sales_id' => static function (Blueprint $blueprint): void {
                    $blueprint->foreignId('sales_id')->after('id')->constrained('users')->cascadeOnDelete();
                },
                'marking' => static function (Blueprint $blueprint): void {
                    $blueprint->string('marking', 100);
                },
                'amount' => static function (Blueprint $blueprint): void {
                    $blueprint->decimal('amount', 15, 2);
                },
                'payout_date' => static function (Blueprint $blueprint): void {
                    $blueprint->date('payout_date');
                },
                'proof_file' => static function (Blueprint $blueprint): void {
                    $blueprint->string('proof_file');
                },
                'note' => static function (Blueprint $blueprint): void {
                    $blueprint->text('note')->nullable();
                },
                'created_by' => static function (Blueprint $blueprint): void {
                    $blueprint->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                },
            ];

            foreach ($columnDefs as $column => $definition) {
                if (Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, $definition);
                $addedColumns[] = $column;
            }

            $hasCreatedAt = Schema::hasColumn($table, 'created_at');
            $hasUpdatedAt = Schema::hasColumn($table, 'updated_at');
            if (! $hasCreatedAt && ! $hasUpdatedAt) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->timestamps();
                });
                $addedColumns[] = 'timestamps';
            } elseif (! $hasCreatedAt) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->timestamp('created_at')->nullable();
                });
                $addedColumns[] = 'created_at';
            } elseif (! $hasUpdatedAt) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->timestamp('updated_at')->nullable();
                });
                $addedColumns[] = 'updated_at';
            }
        }

        $parts = [];
        if ($createdTable) {
            $parts[] = "tabel {$table} dibuat";
        } elseif ($addedColumns !== []) {
            $parts[] = 'kolom ditambahkan: '.implode(', ', $addedColumns);
        } else {
            $parts[] = "tabel {$table} sudah siap (tidak ada perubahan)";
        }

        $message = 'Setup sales marking payouts: '.implode('; ', $parts).'.';

        if ($request->expectsJson() || $request->boolean('json')) {
            return response()->json([
                'success' => true,
                'created_table' => $createdTable,
                'added_columns' => $addedColumns,
                'table' => $table,
                'message' => $message,
            ]);
        }

        return redirect()
            ->to($request->query('redirect', route('sales-revenue.index')))
            ->with('success', $message);
    }

    public function storeCommissionMarkingPayout(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('upload sales salary proof'), 403);

        $validated = $request->validate([
            'sales_id' => 'required|integer|exists:users,id',
            'marking' => 'required|string|max:100',
            'amount' => 'required|numeric|min:1',
            'payout_date' => 'required|date',
            'proof_file' => 'required|file|mimes:jpg,jpeg,png,pdf,webp|max:5120',
            'note' => 'nullable|string|max:500',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'partai' => 'nullable|string|max:100',
        ]);

        $salesId = (int) $validated['sales_id'];
        $this->assertCommissionSalesAccess($salesId, allowManageAll: true);

        $marking = trim((string) $validated['marking']);
        $partai = trim((string) ($validated['partai'] ?? ''));

        $detail = SalesMarkingCommissionDetail::detail(
            $salesId,
            $marking,
            $this->scopedUser(auth()->user()),
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
            $partai !== '' ? $partai : null,
        );

        if ($detail['total_komisi'] <= 0) {
            return response()->json(['message' => __('sales-salary.no_commissions_period')], 422);
        }

        if ((float) $validated['amount'] > $detail['remaining']) {
            return response()->json(['message' => __('sales-salary.payout_exceeds_remaining')], 422);
        }

        $path = UploadedImageCompressor::store($request->file('proof_file'), 'sales-marking-payout-proofs', 'public');

        $payout = SalesMarkingPayout::query()->create([
            'sales_id' => $salesId,
            'marking' => $marking,
            'amount' => round((float) $validated['amount'], 2),
            'payout_date' => $validated['payout_date'],
            'proof_file' => $path,
            'note' => $validated['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => __('sales-salary.marking_payout_saved'),
            'payout' => [
                'id' => $payout->id,
                'amount' => (float) $payout->amount,
                'payout_date' => $payout->payout_date?->format('d/m/Y'),
                'proof_url' => $payout->proofUrl(),
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        abort_unless($this->canView(), 403);

        $user = auth()->user();
        $filters = $this->filtersFromRequest($request);
        $isScopedSales = $user?->hasAnyRole(['sales', 'supervisor']) ?? false;

        $rows = SalesRevenueCalculator::stripCompanySecretsFromRows(
            SalesRevenueCalculator::commissionRows(
                $this->scopedUser($user),
                $filters['status'],
                $filters['date_from'],
                $filters['date_to'],
                null,
                [],
                $filters['partai'],
            ),
        );

        $filename = 'penghasilan-sales-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new SalesRevenueExport($rows, ! $isScopedSales),
            $filename,
        );
    }

    public function badgeCount(): JsonResponse
    {
        if (! auth()->user()?->can('confirm sales commission')) {
            return response()->json(['waiting' => 0]);
        }

        return response()->json([
            'waiting' => SalesRevenueCalculator::adminWaitingConfirmCount(),
        ]);
    }

    public function confirm(Request $request, Barang $barang): JsonResponse
    {
        abort_unless(auth()->user()?->can('confirm sales commission'), 403);

        $record = ShipmentEarningsSettlement::syncForBarang($barang);
        if ($record === null) {
            return response()->json(['message' => 'Pengiriman tidak memiliki sales pada marking.'], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('finance-earnings.auto_settle_hint'),
            'commission' => [
                'id' => $record->id,
                'status' => $record->status,
                'confirmed_amount' => (float) ($record->confirmed_amount ?? $record->estimated_amount),
            ],
        ]);
    }

    public function markCair(SalesCommission $salesCommission): JsonResponse
    {
        abort_unless(auth()->user()?->can('mark sales commission cair'), 403);

        if ($salesCommission->status === SalesCommission::STATUS_CAIR) {
            return response()->json(['message' => 'Sudah cair.'], 422);
        }

        if ($salesCommission->status !== SalesCommission::STATUS_CONFIRMED) {
            return response()->json(['message' => 'Konfirmasi nominal terlebih dahulu.'], 422);
        }

        $salesCommission->update([
            'status' => SalesCommission::STATUS_CAIR,
            'paid_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    private function canView(): bool
    {
        return auth()->user()?->can('view sales revenue') ?? false;
    }

    private function canViewCompanyPayout(?User $user): bool
    {
        return ($user?->can('view sales revenue') ?? false)
            && ! ($user?->hasAnyRole(['sales', 'supervisor']) ?? false);
    }

    private function assertCanViewCompanyRevenue(): void
    {
        abort_unless($this->canView(), 403);
        abort_if(auth()->user()?->hasAnyRole(['sales', 'supervisor']), 403);
    }

    private function scopedUser(?User $user): ?User
    {
        if ($user?->hasRole('sales')) {
            return $user;
        }

        if ($user?->hasRole('supervisor')) {
            return $user;
        }

        return null;
    }

    /** @return array{status: string, date_from: ?string, date_to: ?string, partai: ?string, marketing_id: ?int, excluded_markings: list<string>} */
    private function companyFiltersFromRequest(Request $request): array
    {
        $base = $this->filtersFromRequest($request);

        $excluded = $request->input('excluded_markings', []);
        if (is_string($excluded)) {
            $excluded = $excluded !== '' ? explode(',', $excluded) : [];
        }
        if (! is_array($excluded)) {
            $excluded = [];
        }

        $base['marketing_id'] = $request->filled('marketing_id')
            ? (int) $request->input('marketing_id')
            : null;
        $base['excluded_markings'] = array_values(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $excluded,
        )));

        return $base;
    }

    /** @return array{status: string, date_from: ?string, date_to: ?string, partai: ?string} */
    private function filtersFromRequest(Request $request): array
    {
        $rules = [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'partai' => 'nullable|string|max:100',
        ];
        if ($request->filled('status')) {
            $rules['status'] = 'string|in:waiting,fixed,confirmed,cair';
        }

        $validated = $request->validate($rules);
        $partai = trim((string) ($validated['partai'] ?? $request->input('partai', '')));

        return [
            'status' => (string) ($validated['status'] ?? $request->input('status', '')),
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'partai' => $partai !== '' ? $partai : null,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function enrichCommissionPaymentStatus(array $rows): array
    {
        return SalesMarkingCommissionDetail::enrichListPaymentStatuses($rows);
    }

    private function assertCommissionSalesAccess(int $salesId, bool $allowManageAll = false): void
    {
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }

        if ($allowManageAll && $user->can('upload sales salary proof')) {
            return;
        }

        if ($user->hasAnyRole(['sales', 'supervisor']) && (int) $user->id !== $salesId) {
            abort(403);
        }
    }

}
