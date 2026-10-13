<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Container;
use App\Models\ContainerTrackingStatus;
use App\Models\Invoice;
use App\Models\Marking;
use App\Models\Payment;
use App\Models\Rating;
use App\Models\TrackingStatus;
use App\Support\CbmFormat;
use App\Support\CustomerPortalScope;
use App\Support\CustomerTrackingPresenter;
use App\Support\TrackingEventDate;
use App\Support\UploadedImageCompressor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class TrackingController extends Controller
{
    // ── CUSTOMER: lacak barang milik sendiri ──
    public function customerIndex(Request $request): View|JsonResponse
    {
        $user = auth()->user();
        $baseQuery = Barang::query();
        CustomerPortalScope::applyBarangScope($baseQuery, $user);

        $query = (clone $baseQuery)->with([
            'latestTracking',
            'containers',
            'rating',
            'invoiceItems.invoice',
            'products' => static fn ($q) => $q
                ->select('id', 'barang_id', 'parent_id', 'product_name', 'material', 'sort_order')
                ->orderBy('sort_order'),
        ]);

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('no_index', 'like', "%{$search}%")
                ->orWhere('marking', 'like', "%{$search}%"));
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $barangs = $query->latest()->paginate(15);
        $barangs->setCollection(
            $barangs->getCollection()->map(fn (Barang $barang) => $this->decorateTrackingDisplay($barang))
        );

        $statsQuery = Barang::query();
        CustomerPortalScope::applyBarangScope($statsQuery, $user);
        $stats = [
            'total' => (clone $statsQuery)->count(),
        ] + $this->buildShipmentStatusStats($statsQuery);

        if ($this->isTrackingAjaxRequest($request)) {
            return $this->barangTrackingAjaxResponse($barangs, $stats, 'tracking._partials.customer-rows');
        }

        return view('tracking.customer', array_merge(
            CustomerPortalScope::portalMarkingViewData(),
            [
            'barangs' => $barangs,
            'stats' => $stats,
            ],
        ));
    }

    // ── STAFF: satu halaman tracking untuk semua role non-customer ──
    public function staffIndex(Request $request): View|JsonResponse
    {
        if (! $this->canUseContainerTracking(auth()->user())) {
            return $this->staffIndexPerIndex($request);
        }

        $query = Container::query()->has('barangs');
        $this->applyTeamScopeToContainerQuery($query);
        $query->with([
            'latestTracking',
            'latestDeliveryTrip',
            'gudangChina:id,name',
            'barangs' => static fn ($q) => $q
                ->select([
                    'barangs.id',
                    'barangs.no_index',
                    'barangs.marking',
                    'barangs.user_id',
                    'barangs.destination',
                    'barangs.ctns',
                    'barangs.total_cbm',
                    'barangs.status',
                    'barangs.form',
                    'barangs.supplier_chinese',
                ])
                ->with([
                    'customer:id,name',
                    'products' => static fn ($productQuery) => $productQuery
                        ->select('id', 'barang_id', 'parent_id', 'product_name', 'material', 'sort_order')
                        ->orderBy('sort_order'),
                ])
                ->orderBy('barangs.no_index'),
        ]);
        $this->applyStaffContainerFilters($query, $request);
        $containers = $query->latest()->paginate(25);
        $containers->setCollection(
            $containers->getCollection()->map(fn (Container $container) => $this->decorateContainerTrackingDisplay($container))
        );
        $stats = $this->getStaffContainerStats();

        if ($this->isTrackingAjaxRequest($request)) {
            return $this->staffContainerAjaxResponse($containers, $stats);
        }

        return view('tracking.staff', compact('containers', 'stats'));
    }

    public function staffShow(Container $container): JsonResponse
    {
        abort_unless($this->canUseContainerTracking(auth()->user()), 403);
        abort_unless($this->containerVisibleToUser($container), 403);

        $container->pruneRedundantLoadingTracking();
        $container->alignLaggingBarangsToCurrentTracking(auth()->id());
        $container->loadMissing('barangs');
        foreach ($container->barangs as $barang) {
            $barang->pruneRedundantStuffingTracking();
        }

        $container->loadMissing([
            'latestTracking',
            'latestDeliveryTrip',
            'gudangChina:id,name',
            'barangs' => static fn ($q) => $q
                ->select([
                    'barangs.id',
                    'barangs.no_index',
                    'barangs.marking',
                    'barangs.user_id',
                    'barangs.destination',
                    'barangs.ctns',
                    'barangs.total_cbm',
                    'barangs.status',
                    'barangs.form',
                    'barangs.supplier_chinese',
                ])
                ->with([
                    'customer:id,name',
                    'products' => static fn ($productQuery) => $productQuery
                        ->select('id', 'barang_id', 'parent_id', 'product_name', 'material', 'sort_order')
                        ->orderBy('sort_order'),
                ])
                ->orderBy('barangs.no_index'),
        ]);

        $container = $this->decorateContainerTrackingDisplay($container);

        $canManage = $this->canUseContainerTracking(auth()->user())
            && auth()->user()?->can('update tracking status');

        return response()->json([
            'container' => $this->containerPayload($container),
            'can_manage_timeline' => (bool) $canManage,
            'timeline' => $container->trackingStatuses()
                ->with('creator:id,name')
                ->get()
                ->map(static fn ($row): array => [
                    'id' => $row->id,
                    'status' => $row->status,
                    'status_label' => Container::trackingStatusLabel($row->status),
                    'location' => $row->location,
                    'description' => $row->description,
                    'event_date' => TrackingEventDate::normalize($row->event_date ?? null),
                    'created_at' => TrackingEventDate::displayIso(
                        $row->event_date ?? null,
                        $row->created_at,
                    ),
                    'creator' => $row->creator ? ['name' => $row->creator->name] : null,
                ])->values(),
        ]);
    }

    public function staffUpdateTimelineEntry(
        Request $request,
        Container $container,
        ContainerTrackingStatus $containerTrackingStatus,
    ): JsonResponse {
        abort_unless($this->canUseContainerTracking(auth()->user()), 403);
        abort_unless($this->containerVisibleToUser($container), 403);
        abort_unless(auth()->user()?->can('update tracking status'), 403);
        abort_unless((int) $containerTrackingStatus->container_id === (int) $container->id, 404);

        self::ensureTrackingEventDateSchema();

        if ($request->filled('status')) {
            $request->merge(['status' => Barang::normalizeShipmentStatus((string) $request->input('status'))]);
        }

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(Container::TRACKING_STATUSES)],
            'location' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'event_date' => 'nullable|date|before_or_equal:today',
        ]);

        $old = [
            'status' => $containerTrackingStatus->status,
            'location' => $containerTrackingStatus->location,
            'description' => $containerTrackingStatus->description,
            'event_date' => TrackingEventDate::normalize($containerTrackingStatus->event_date)
                ?? TrackingEventDate::normalize($containerTrackingStatus->created_at),
        ];

        $eventDate = isset($data['event_date'])
            ? (string) $data['event_date']
            : ($old['event_date'] ?? null);

        DB::transaction(function () use ($container, $containerTrackingStatus, $data, $eventDate): void {
            $container->loadMissing('barangs');
            $container->updateTrackingTimelineEntry(
                $containerTrackingStatus,
                $data['status'],
                $data['location'] ?? null,
                $data['description'] ?? null,
                $eventDate,
                (int) auth()->id(),
            );
        });

        $fresh = $containerTrackingStatus->fresh();
        ActivityLog::record('Container timeline entry updated', $container, $old, [
            'status' => $fresh?->status,
            'location' => $fresh?->location,
            'description' => $fresh?->description,
            'event_date' => TrackingEventDate::normalize($fresh?->event_date),
            'timeline_id' => $containerTrackingStatus->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.tracking_timeline_updated'),
        ]);
    }

    public function staffDeleteTimelineEntry(
        Container $container,
        ContainerTrackingStatus $containerTrackingStatus,
    ): JsonResponse {
        abort_unless($this->canUseContainerTracking(auth()->user()), 403);
        abort_unless($this->containerVisibleToUser($container), 403);
        abort_unless(auth()->user()?->can('update tracking status'), 403);
        abort_unless((int) $containerTrackingStatus->container_id === (int) $container->id, 404);

        $snapshot = [
            'status' => $containerTrackingStatus->status,
            'location' => $containerTrackingStatus->location,
            'description' => $containerTrackingStatus->description,
            'event_date' => TrackingEventDate::normalize($containerTrackingStatus->event_date)
                ?? TrackingEventDate::normalize($containerTrackingStatus->created_at),
            'timeline_id' => $containerTrackingStatus->id,
        ];

        DB::transaction(function () use ($container, $containerTrackingStatus): void {
            $container->loadMissing('barangs');
            $container->deleteTrackingTimelineEntry(
                $containerTrackingStatus,
                (int) auth()->id(),
            );
        });

        ActivityLog::record('Container timeline entry deleted', $container, $snapshot, null);

        return response()->json([
            'success' => true,
            'message' => __('messages.tracking_timeline_deleted'),
        ]);
    }

    public function setupEventDate(Request $request): JsonResponse|HttpResponse
    {
        abort_unless(auth()->user()?->can('update tracking status'), 403);

        $result = self::ensureTrackingEventDateSchema();

        $payload = [
            'success' => true,
            'container_column_added' => $result['container_added'],
            'barang_column_added' => $result['barang_added'],
            'container_backfilled' => $result['container_backfilled'],
            'barang_backfilled' => $result['barang_backfilled'],
            'message' => 'Kolom event_date siap. Container backfill '.$result['container_backfilled']
                .', barang backfill '.$result['barang_backfilled'].'.',
        ];

        if ($request->boolean('json') || $request->expectsJson()) {
            return response()->json($payload);
        }

        return response(
            '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">'
            .'<h2>Setup tanggal tracking</h2>'
            .'<p>'.e($payload['message']).'</p>'
            .'<p><a href="'.e(route('tracking.staff')).'">Kembali ke Tracking</a></p>'
            .'</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * @return array{container_added: bool, barang_added: bool, container_backfilled: int, barang_backfilled: int}
     */
    public static function ensureTrackingEventDateSchema(): array
    {
        $containerAdded = false;
        $barangAdded = false;
        $containerBackfilled = 0;
        $barangBackfilled = 0;

        if (Schema::hasTable('container_tracking_statuses')
            && ! Schema::hasColumn('container_tracking_statuses', 'event_date')) {
            Schema::table('container_tracking_statuses', static function (Blueprint $table): void {
                $table->date('event_date')->nullable()->after('description');
                $table->index('event_date');
            });
            $containerAdded = true;
        }

        if (Schema::hasTable('tracking_statuses')
            && ! Schema::hasColumn('tracking_statuses', 'event_date')) {
            Schema::table('tracking_statuses', static function (Blueprint $table): void {
                $table->date('event_date')->nullable()->after('description');
                $table->index('event_date');
            });
            $barangAdded = true;
        }

        if (Schema::hasColumn('container_tracking_statuses', 'event_date')) {
            $containerBackfilled = (int) DB::table('container_tracking_statuses')
                ->whereNull('event_date')
                ->whereNotNull('created_at')
                ->update(['event_date' => DB::raw('DATE(created_at)')]);
        }

        if (Schema::hasColumn('tracking_statuses', 'event_date')) {
            $barangBackfilled = (int) DB::table('tracking_statuses')
                ->whereNull('event_date')
                ->whereNotNull('created_at')
                ->update(['event_date' => DB::raw('DATE(created_at)')]);
        }

        return [
            'container_added' => $containerAdded,
            'barang_added' => $barangAdded,
            'container_backfilled' => $containerBackfilled,
            'barang_backfilled' => $barangBackfilled,
        ];
    }

    public function alignContainerBarangStatuses(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless(auth()->user()?->can('update tracking status'), 403);

        $partai = trim((string) $request->query('partai', ''));
        $dryRun = $request->boolean('dry_run');

        $query = Container::query()
            ->with(['latestTracking', 'barangs'])
            ->where(function ($outer): void {
                $outer->whereHas('trackingStatuses', static function ($q): void {
                    $q->whereIn('status', [
                        Container::TRACKING_ETD,
                        Container::TRACKING_ETA,
                        Container::TRACKING_BEA_CUKAI,
                        Container::TRACKING_BONGKAR,
                    ]);
                })->orWhereHas('trackingStatuses', static function ($q): void {
                    $q->where('status', Container::TRACKING_LOADING);
                });
            });

        if ($partai !== '') {
            $query->where('partai', $partai);
        }

        $this->applyTeamScopeToContainerQuery($query);

        $fixed = 0;
        $pruned = 0;
        $prunedBarangStuffing = 0;
        $samples = [];
        $pruneSamples = [];

        foreach ($query->orderBy('id')->get() as $container) {
            $redundantLoadingIds = $container->redundantLoadingTrackingIds();
            if ($redundantLoadingIds !== []) {
                if ($dryRun) {
                    $pruned += count($redundantLoadingIds);
                } else {
                    $deleted = DB::transaction(static function () use ($container): int {
                        return $container->pruneRedundantLoadingTracking();
                    });
                    $pruned += $deleted;
                }

                if (count($pruneSamples) < 20) {
                    $pruneSamples[] = [
                        'partai' => $container->partai,
                        'loading_deleted' => count($redundantLoadingIds),
                    ];
                }
            }

            $container->loadMissing('barangs');
            foreach ($container->barangs as $barang) {
                $redundantStuffingIds = $barang->redundantStuffingTrackingIds();
                if ($redundantStuffingIds === []) {
                    continue;
                }

                if ($dryRun) {
                    $prunedBarangStuffing += count($redundantStuffingIds);
                } else {
                    $prunedBarangStuffing += DB::transaction(static function () use ($barang): int {
                        return $barang->pruneRedundantStuffingTracking();
                    });
                }
            }

            $tracking = $container->trackingStatusBeyondLoading();
            if ($tracking === null) {
                continue;
            }

            $expected = Container::barangStatusForTrackingStatus($tracking);
            $expectedRank = Container::barangShipmentProgressRank($expected);
            $lagging = $container->barangs->filter(static function (Barang $barang) use ($expected, $expectedRank): bool {
                if (Barang::isDitarikKembaliStatus($barang->status)) {
                    return false;
                }

                $rank = Container::barangShipmentProgressRank((string) $barang->status);

                return $rank < $expectedRank
                    || ($rank === $expectedRank && (string) $barang->status !== $expected);
            });

            if ($lagging->isEmpty()) {
                continue;
            }

            foreach ($lagging as $barang) {
                if (count($samples) < 20) {
                    $samples[] = [
                        'partai' => $container->partai,
                        'no_index' => $barang->no_index,
                        'from' => $barang->status,
                        'to' => $expected,
                    ];
                }
            }

            if ($dryRun) {
                $fixed += $lagging->count();

                continue;
            }

            $fixed += DB::transaction(static function () use ($container): int {
                return $container->alignLaggingBarangsToCurrentTracking(auth()->id());
            });
        }

        // Barang tanpa container tapi punya stuffing dobel / stuffing setelah ETD (data lama).
        $orphanQuery = Barang::query()
            ->whereHas('trackingStatuses', static function ($q): void {
                $q->where('status', Barang::STATUS_STUFFING);
            })
            ->where(function ($outer): void {
                $outer->whereHas('trackingStatuses', static function ($q): void {
                    $q->whereIn('status', [
                        Barang::STATUS_ETD,
                        Barang::STATUS_ETA,
                        Barang::STATUS_BONGKAR,
                    ]);
                })->orWhereHas('trackingStatuses', static function ($q): void {
                    $descriptions = CustomerTrackingPresenter::autoStuffingDescriptions();
                    $q->where('status', Barang::STATUS_STUFFING)
                        ->whereIn('description', $descriptions);
                }, '>=', 2);
            });

        if ($partai !== '') {
            $orphanQuery->whereHas('containers', static fn ($q) => $q->where('partai', $partai));
        }

        foreach ($orphanQuery->orderBy('id')->limit(500)->get() as $barang) {
            $redundantStuffingIds = $barang->redundantStuffingTrackingIds();
            if ($redundantStuffingIds === []) {
                continue;
            }

            if ($dryRun) {
                $prunedBarangStuffing += count($redundantStuffingIds);
            } else {
                $prunedBarangStuffing += DB::transaction(static function () use ($barang): int {
                    return $barang->pruneRedundantStuffingTracking();
                });
            }
        }

        $parts = [];
        if ($pruned > 0 || $dryRun) {
            $parts[] = $dryRun
                ? "{$pruned} timeline stuffing container dobel akan dihapus"
                : "{$pruned} timeline stuffing container dobel dihapus";
        }
        if ($prunedBarangStuffing > 0 || $dryRun) {
            $parts[] = $dryRun
                ? "{$prunedBarangStuffing} timeline stuffing barang (customer) akan dihapus"
                : "{$prunedBarangStuffing} timeline stuffing barang (customer) dihapus";
        }
        if ($fixed > 0 || $dryRun) {
            $parts[] = $dryRun
                ? "{$fixed} barang akan disamakan"
                : "{$fixed} barang disamakan ke status container";
        }

        $message = $parts === []
            ? 'Tidak ada status dobel / tertinggal yang perlu diperbaiki.'
            : (($dryRun ? 'Dry-run: ' : 'Berhasil: ').implode('; ', $parts).'.');

        if ($request->expectsJson() || $request->boolean('json')) {
            return response()->json([
                'success' => true,
                'dry_run' => $dryRun,
                'fixed' => $fixed,
                'pruned_loading' => $pruned,
                'pruned_barang_stuffing' => $prunedBarangStuffing,
                'partai' => $partai !== '' ? $partai : null,
                'samples' => $samples,
                'prune_samples' => $pruneSamples,
                'message' => $message,
            ]);
        }

        return redirect()
            ->to($request->query('redirect', route('barang.index')))
            ->with('success', $message);
    }

    public function staffUpdateStatus(Request $request, Container $container): JsonResponse
    {
        abort_unless($this->canUseContainerTracking(auth()->user()), 403);
        abort_unless($this->containerVisibleToUser($container), 403);
        abort_unless(auth()->user()?->can('update tracking status'), 403);

        self::ensureTrackingEventDateSchema();

        if ($request->filled('status')) {
            $request->merge(['status' => Barang::normalizeShipmentStatus((string) $request->input('status'))]);
        }

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(Container::TRACKING_STATUSES)],
            'location' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'event_date' => 'nullable|date|before_or_equal:today',
        ]);

        $container->loadMissing('latestTracking', 'barangs');
        $oldTrackingStatus = $this->containerTrackingStatus($container);
        $oldCurrentStatus = $container->status;
        $eventDate = isset($data['event_date']) ? (string) $data['event_date'] : null;
        /** @var array<int, string> $priorBarangStatuses */
        $priorBarangStatuses = $container->barangs
            ->mapWithKeys(static fn (Barang $barang): array => [(int) $barang->id => (string) $barang->status])
            ->all();

        DB::transaction(function () use ($container, $data, $oldTrackingStatus, $oldCurrentStatus, $eventDate): void {
            $payload = $this->applyContainerTrackingUpdate(
                $container,
                $data['status'],
                $data['location'] ?? null,
                $data['description'] ?? null,
                (int) auth()->id(),
                $eventDate,
            );

            ActivityLog::record('Container tracking updated', $container, [
                'tracking_status' => $oldTrackingStatus,
                'container_status' => $oldCurrentStatus,
            ], [
                'tracking_status' => $payload['status'],
                'container_status' => $payload['current_status'],
                'location' => $payload['location'],
                'description' => $payload['description'],
                'event_date' => $eventDate,
            ]);
        });

        $invoiceWarnings = [];
        if ($data['status'] === Container::TRACKING_BONGKAR) {
            $container->loadMissing('barangs');
            foreach ($container->barangs as $barang) {
                $warning = $this->invoiceWarningAfterBongkar(
                    $barang->fresh(),
                    (string) ($priorBarangStatuses[(int) $barang->id] ?? $barang->status),
                );
                if ($warning !== null) {
                    $invoiceWarnings[] = $barang->no_index.': '.$warning;
                }
            }
        }

        return response()->json(array_filter([
            'success' => true,
            'message' => __('messages.tracking_updated'),
            'invoice_warning' => $invoiceWarnings !== [] ? implode('; ', array_unique($invoiceWarnings)) : null,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    private function staffIndexPerIndex(Request $request): View|JsonResponse
    {
        $query = Barang::query()->with([
            'latestTracking',
            'containers',
            'rating',
            'products' => static fn ($q) => $q
                ->select('id', 'barang_id', 'parent_id', 'product_name', 'material', 'sort_order')
                ->orderBy('sort_order'),
        ]);
        $user = auth()->user();

        if (Marking::userHasTeamMarkingScope($user)) {
            Marking::restrictBarangsQuery($query, $user);
        }

        $this->applyFilters($query, $request);
        $barangs = $query->latest()->paginate(25);
        $barangs->setCollection($this->decorateStaffBarangTrackingRows($barangs->getCollection()));

        $statsQuery = Barang::query();
        if (Marking::userHasTeamMarkingScope($user)) {
            Marking::restrictBarangsQuery($statsQuery, $user);
        }
        $stats = [
            'total' => (clone $statsQuery)->count(),
        ] + $this->buildShipmentStatusStats($statsQuery);

        $teamScoped = Marking::userHasTeamMarkingScope($user);

        if ($this->isTrackingAjaxRequest($request)) {
            return $this->barangTrackingAjaxResponse($barangs, $stats, 'tracking._partials.staff-barang-rows');
        }

        return view('tracking.staff-barang', compact('barangs', 'stats', 'teamScoped'));
    }

    private function canUseContainerTracking(?\App\Models\User $user): bool
    {
        if ($user === null || $user->hasRole('customer')) {
            return false;
        }

        if (Marking::userHasSupervisorMarkingScope($user)) {
            return false;
        }

        if ($user->hasAnyRole(['super_admin', 'admin_cs', 'admin'])) {
            return true;
        }

        return $user->can('view container') && $user->can('update tracking status');
    }

    // ── SHARED: get timeline for a barang ──
    public function show(Barang $barang): JsonResponse
    {
        $user = auth()->user();
        if ($user?->hasRole('customer') && ! CustomerPortalScope::barangVisible($barang)) {
            abort(403);
        }
        abort_unless(Marking::barangVisibleToUser($barang, $user), 403);

        $timeline = $barang->trackingStatuses()->with('creator')->get();
        $forCustomer = $user?->hasRole('customer') === true;
        if ($forCustomer) {
            $timeline = CustomerTrackingPresenter::presentTimeline($barang, $timeline)
                ->map(static function (TrackingStatus $row): TrackingStatus {
                    $row->setAttribute(
                        'event_at',
                        TrackingEventDate::displayIso($row->event_date ?? null, $row->created_at),
                    );

                    return $row;
                })
                ->values();
        } else {
            $timeline = $timeline->map(static function (TrackingStatus $row): TrackingStatus {
                $row->setAttribute(
                    'event_at',
                    TrackingEventDate::displayIso($row->event_date ?? null, $row->created_at),
                );
                $row->setAttribute(
                    'event_date_label',
                    TrackingEventDate::displayLabel($row->event_date ?? null, $row->created_at),
                );

                return $row;
            })->values();
        }

        return response()->json([
            'barang' => $barang->load('customer', 'containers'),
            'timeline' => $timeline,
            'status_labels' => $forCustomer
                ? CustomerTrackingPresenter::statusLabels()
                : $this->statusLabels(),
        ]);
    }

    // ── Update status (admin CS, gudang indo, supervisor) ──
    public function updateStatus(Request $request, Barang $barang): JsonResponse
    {
        $user = auth()->user();
        abort_unless(Marking::barangVisibleToUser($barang, $user), 403);

        if ($request->filled('status')) {
            $request->merge(['status' => Barang::normalizeShipmentStatus((string) $request->input('status'))]);
        }

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(Barang::SHIPMENT_STATUSES)],
            'location' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'event_date' => 'nullable|date|before_or_equal:today',
        ]);

        $this->assertBarangStatusUpdateAuthorized($barang, $data['status'], $user);
        $this->assertBarangStatusTransitionAllowed($barang, $data['status'], $user);

        $old = (string) $barang->status;
        $targetStatus = Barang::normalizeShipmentStatus($data['status']);
        $trackingDescription = $this->resolveTrackingStatusDescription($barang, $targetStatus, $old, $data['description'] ?? null);
        $eventDate = isset($data['event_date']) ? (string) $data['event_date'] : null;

        DB::transaction(function () use ($data, $barang, $targetStatus, $trackingDescription, $user, $eventDate): void {
            $barang->syncShipmentTrackingStatus(
                $targetStatus,
                isset($data['location']) ? (string) $data['location'] : null,
                $trackingDescription,
                $user?->id,
                $eventDate,
            );

            $barang->update(['status' => $targetStatus]);
        });

        ActivityLog::record('Tracking updated', $barang, ['status' => $old], [
            'status' => $targetStatus,
            'event_date' => $eventDate,
        ]);

        $barang->refresh();
        $invoiceWarning = $this->invoiceWarningAfterBongkar($barang, $old);

        $message = match (Barang::normalizeShipmentStatus($data['status'])) {
            Barang::STATUS_DITARIK_KEMBALI => __('messages.barang_withdrawn'),
            Barang::STATUS_STUFFING => Barang::isDitarikKembaliStatus($old)
                ? __('messages.barang_reactivated')
                : __('messages.tracking_updated'),
            default => __('messages.tracking_updated'),
        };

        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'invoice_warning' => $invoiceWarning,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    // ── Billing (customer) ──
    public function billing(): View
    {
        $barangScope = Barang::query();
        CustomerPortalScope::applyBarangScope($barangScope);
        Barang::backfillMissingInvoicesForMasuk($barangScope, 100);
        Barang::backfillMissingInvoicesForBongkar($barangScope, 100);

        $query = Invoice::query()->with('items.barang', 'payments');
        CustomerPortalScope::applyInvoiceScope($query);

        return view('tracking.billing', array_merge(
            CustomerPortalScope::portalMarkingViewData(),
            [
            'invoices' => $query->latest()->paginate(10),
            ],
        ));
    }

    public function billingInvoicePdf(Invoice $invoice): Response
    {
        abort_unless(CustomerPortalScope::invoiceVisible($invoice), 403);

        \App\Support\InvoiceConsolidation::repairDraftConsolidationForInvoice($invoice);
        $invoice = $invoice->fresh();
        $pdfData = \App\Support\InvoicePdfData::forInvoice($invoice);

        $pdf = Pdf::loadView('exports.invoice-pdf', $pdfData)
            ->setPaper('a4', 'landscape');

        return $pdf->download($invoice->downloadFileBasename('pdf'));
    }

    public function uploadPayment(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('upload payment proof'), 403);

        $data = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0',
            'sender_name' => 'required|string|max:255',
            'proof_file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $user = auth()->user();
        $invoiceQuery = Invoice::where('id', $data['invoice_id']);

        if ($user->hasRole('customer')) {
            CustomerPortalScope::applyInvoiceScope($invoiceQuery, $user);
        }

        $invoice = $invoiceQuery->firstOrFail();

        $path = UploadedImageCompressor::store($request->file('proof_file'), 'payment-proofs', 'public');

        Payment::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'amount' => $data['amount'],
            'sender_name' => $data['sender_name'],
            'proof_file' => $path,
            'status' => 'pending',
        ]);

        return response()->json(['success' => true, 'message' => __('messages.payment_proof_uploaded')]);
    }

    public function rate(Request $request, Barang $barang): JsonResponse
    {
        abort_unless(CustomerPortalScope::barangVisible($barang), 403, __('messages.rating_forbidden'));

        $data = $request->validate([
            'score' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
        ]);

        Rating::updateOrCreate(
            ['barang_id' => $barang->id, 'user_id' => auth()->id()],
            $data
        );

        return response()->json(['success' => true, 'message' => __('messages.rating_thanks')]);
    }

    // ── Public Tracking Portal (no auth) ──
    public function publicIndex(Request $request): View
    {
        return view('tracking.public-index', [
            'prefillIndex' => $request->query('index'),
        ]);
    }

    public function publicSearch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'no_index' => 'required|string|max:100',
        ]);

        $noIndex = trim($data['no_index']);

        $barang = Barang::query()
            ->where('no_index', $noIndex)
            ->first();

        if (!$barang) {
            return back()->withInput()->withErrors([
                'no_index' => __('messages.index_not_found'),
            ]);
        }

        return redirect()->route('track.show', [
            'noIndex' => $barang->no_index,
        ]);
    }

    public function publicShow(string $noIndex): View
    {
        $barang = Barang::query()
            ->where('no_index', $noIndex)
            ->with([
                'trackingStatuses' => fn ($query) => $query->orderByDesc('created_at'),
                'trackingStatuses.creator',
                'containers',
                'latestTracking',
                'invoiceItems.invoice.items.barang',
                'customer',
                'rating',
                'gudangChina:id,name',
            ])
            ->firstOrFail();

        $markingRecord = Marking::query()
            ->whereBarangMarkingValue((string) $barang->marking)
            ->active()
            ->with(['customer', 'branch'])
            ->firstOrFail();

        $invoices = $barang->invoiceItems
            ->map(fn ($item) => $item->invoice)
            ->filter()
            ->unique('id')
            ->values();

        $customerTimeline = CustomerTrackingPresenter::presentTimeline($barang, $barang->trackingStatuses);
        $latestUpdate = $customerTimeline->first();
        $statusLabels = CustomerTrackingPresenter::statusLabels();
        $tanggalMasukLabel = CustomerTrackingPresenter::tanggalMasukLabel($barang);
        $tanggalMasukFieldLabel = CustomerTrackingPresenter::tanggalMasukFieldLabel($barang);

        return view('tracking.public-show', compact(
            'markingRecord',
            'barang',
            'invoices',
            'latestUpdate',
            'statusLabels',
            'customerTimeline',
            'tanggalMasukLabel',
            'tanggalMasukFieldLabel',
        ));
    }

    public function publicRate(Request $request, Barang $barang): JsonResponse
    {
        abort_unless($barang->status === Barang::STATUS_BONGKAR, 422, __('messages.rating_requires_bongkar'));

        if ($barang->rating) {
            return response()->json([
                'success' => false,
                'message' => __('messages.review_already_submitted'),
            ], 422);
        }

        $data = $request->validate([
            'score' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
        ]);

        $userId = $barang->user_id ?? Marking::query()
            ->whereBarangMarkingValue((string) $barang->marking)
            ->value('user_id');

        abort_unless($userId, 422, __('messages.review_save_failed'));

        Rating::create([
            'barang_id' => $barang->id,
            'user_id' => $userId,
            'score' => $data['score'],
            'review' => $data['review'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.rating_thanks_review'),
        ]);
    }

    public function publicInvoicePdf(Request $request, Invoice $invoice): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $data = $request->validate([
            'no_index' => 'required|string|max:100',
        ]);

        $attached = $invoice->items()
            ->whereHas('barang', fn ($query) => $query
                ->where('no_index', $data['no_index']))
            ->exists();

        abort_unless($attached, 404);

        \App\Support\InvoiceConsolidation::repairDraftConsolidationForInvoice($invoice);
        $invoice = $invoice->fresh();
        $pdfData = \App\Support\InvoicePdfData::forInvoice($invoice);

        $pdf = Pdf::loadView('exports.invoice-pdf', $pdfData)
            ->setPaper('a4', 'landscape');

        return $pdf->download($invoice->downloadFileBasename('pdf'));
    }

    public function shareLink(Barang $barang): JsonResponse
    {
        $url = url()->route('track.show', [
            'noIndex' => $barang->no_index,
        ]);

        return response()->json(['url' => $url]);
    }

    private function applyTeamScopeToContainerQuery($query, ?\App\Models\User $user = null): void
    {
        $user ??= auth()->user();

        if (! Marking::userHasTeamMarkingScope($user)) {
            return;
        }

        $query->whereHas(
            'barangs',
            static fn ($barangQuery) => Marking::restrictBarangsQuery($barangQuery, $user),
        );
    }

    private function containerVisibleToUser(Container $container, ?\App\Models\User $user = null): bool
    {
        $user ??= auth()->user();

        if (! Marking::userHasTeamMarkingScope($user)) {
            return true;
        }

        return Container::query()
            ->whereKey($container->id)
            ->whereHas(
                'barangs',
                static fn ($barangQuery) => Marking::restrictBarangsQuery($barangQuery, $user),
            )
            ->exists();
    }

    private function applyStaffContainerFilters($query, Request $request): void
    {
        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $term = '%'.$search.'%';
                $q->where('partai', 'like', $term)
                    ->orWhere('container_no', 'like', $term)
                    ->orWhere('no_bl', 'like', $term)
                    ->orWhereHas('barangs', static function ($barangQuery) use ($term): void {
                        $barangQuery->where('no_index', 'like', $term)
                            ->orWhere('marking', 'like', $term)
                            ->orWhere('destination', 'like', $term);
                    })
                    ->orWhereHas('barangs.customer', static function ($customerQuery) use ($term): void {
                        $customerQuery->where('name', 'like', $term);
                    });
            });
        }

        if ($status = trim((string) $request->get('status', ''))) {
            $mappedCurrentStatus = Container::currentStatusForTrackingStatus($status);
            $query->where(function ($q) use ($status, $mappedCurrentStatus): void {
                $q->whereHas('latestTracking', static function ($trackingQuery) use ($status): void {
                    $trackingQuery->where('status', $status);
                });

                if ($status !== Container::TRACKING_BEA_CUKAI) {
                    $q->orWhere(function ($fallbackQuery) use ($mappedCurrentStatus): void {
                        $fallbackQuery->whereDoesntHave('latestTracking')
                            ->where('status', $mappedCurrentStatus);
                    });
                }
            });
        }

        if ($dest = trim((string) $request->get('destination', ''))) {
            $query->whereHas('barangs', static function ($barangQuery) use ($dest): void {
                $barangQuery->where('destination', $dest);
            });
        }

        if ($from = $request->get('date_from')) {
            $query->whereDate('load_date', '>=', $from);
        }

        if ($to = $request->get('date_to')) {
            $query->whereDate('load_date', '<=', $to);
        }
    }

    private function getStaffContainerStats(): array
    {
        $query = Container::query()
            ->has('barangs');
        $this->applyTeamScopeToContainerQuery($query);
        $containers = $query->with([
                'latestTracking',
                'barangs:id,status',
            ])
            ->get();

        $stats = [
            'total' => $containers->count(),
            Container::TRACKING_DITARIK_KEMBALI => (int) $this->scopedBarangStatsQuery()
                ->where('status', Barang::STATUS_DITARIK_KEMBALI)
                ->count(),
            Container::TRACKING_LOADING => 0,
            Container::TRACKING_ETD => 0,
            Container::TRACKING_ETA => 0,
            Container::TRACKING_BEA_CUKAI => 0,
            Container::TRACKING_BONGKAR => 0,
        ];

        foreach ($containers as $container) {
            $status = $this->containerTrackingStatus($container);
            if ($status !== null && array_key_exists($status, $stats)) {
                $stats[$status] += 1;
            }
        }

        return $stats;
    }

    private function scopedBarangStatsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Barang::query();
        $user = auth()->user();

        if (Marking::userHasTeamMarkingScope($user)) {
            Marking::restrictBarangsQuery($query, $user);
        }

        return $query;
    }

    private function applyContainerTrackingUpdate(
        Container $container,
        string $trackingStatus,
        ?string $location,
        ?string $description,
        int $actorId,
        ?string $eventDate = null,
    ): array {
        $payload = $container->resolveTrackingPayload($trackingStatus, $location, $description);
        $container->syncTrackingStatus(
            $trackingStatus,
            $payload['location'],
            $payload['description'],
            $actorId,
            $payload['current_status'],
            $eventDate,
        );

        $container->unsetRelation('barangs');
        $container->load('barangs');
        $container->cascadeTrackingToBarangs(
            $trackingStatus,
            $payload['location'],
            $payload['description'],
            $actorId,
            onlyUpgrade: false,
            eventDate: $eventDate,
        );

        return $payload;
    }

    private function invoiceWarningAfterBongkar(Barang $barang, string $previousStatus): ?string
    {
        if (! Barang::isBongkarStatus((string) $barang->status)) {
            return null;
        }

        $barang->recordInvoiceIfBongkar(Barang::normalizeShipmentStatus($previousStatus));

        return match ($barang->fresh()->invoiceSkipReasonOnBongkar()) {
            'no_customer' => (string) __('messages.invoice_skipped_no_customer'),
            'no_price' => (string) __('messages.invoice_skipped_no_price'),
            default => null,
        };
    }

    private function containerPayload(Container $container): array
    {
        return [
            'id' => $container->id,
            'partai' => $container->partai,
            'container_no' => $container->container_no,
            'no_bl' => $container->no_bl,
            'status' => $container->display_tracking_status,
            'status_label' => $container->display_tracking_status_label,
            'status_badge' => $container->display_tracking_badge,
            'shipments_count' => $container->shipments_count,
            'total_ctns' => $container->total_ctns_display,
            'total_cbm' => $container->total_cbm_display,
            'markings' => $container->markings_list,
            'customers' => $container->customers_list,
            'destinations' => $container->destinations_list,
            'last_location' => $container->display_tracking_location,
            'last_description' => $container->display_tracking_description,
            'load_date' => $container->load_date?->format('d/m/Y'),
            'etd' => $container->etd?->format('d/m/Y'),
            'eta' => $container->eta?->format('d/m/Y'),
            'delivery_trip_id' => $container->delivery_trip_id,
            'delivery_trip_status' => $container->delivery_trip_status,
            'has_delivery_trip' => (bool) $container->has_delivery_trip,
        ];
    }

    private function decorateContainerTrackingDisplay(Container $container): Container
    {
        $trackingStatus = $this->containerTrackingStatus($container);
        $latest = $container->latestTracking;

        $container->setAttribute('shipments_count', $container->barangs->count());
        $container->setAttribute('total_ctns_display', (int) $container->barangs->sum('ctns'));
        $totalCbm = (float) $container->barangs->sum('total_cbm');
        $container->setAttribute('total_cbm_display', CbmFormat::truncate($totalCbm));
        $container->setAttribute('container_label', $this->containerLabel($container));
        $container->setAttribute('markings_list', $this->joinUnique($container->barangs->pluck('marking')));
        $container->setAttribute('customers_list', $this->joinUnique(
            $container->barangs->map(static fn (Barang $barang): string => trim((string) ($barang->customer?->name ?? '')))
        ));
        $container->setAttribute('destinations_list', $this->joinUnique($container->barangs->pluck('destination')));
        $container->setAttribute('display_nama_barang', $this->containerItemsSummary($container));
        $container->setAttribute('no_indexes_list', $this->joinUnique($container->barangs->pluck('no_index')));
        $container->setAttribute('display_tracking_status', $trackingStatus);
        $container->setAttribute('display_tracking_status_label', $trackingStatus !== null ? Container::trackingStatusLabel($trackingStatus) : '-');
        $container->setAttribute('display_tracking_badge', Container::trackingStatusBadge($trackingStatus));
        $container->setAttribute(
            'display_tracking_location',
            $latest?->location ?: $this->fallbackContainerTrackingLocation($container, $trackingStatus)
        );
        $container->setAttribute(
            'display_tracking_description',
            $latest?->description ?: $this->fallbackContainerTrackingDescription($container, $trackingStatus)
        );
        $container->setAttribute('has_delivery_trip', $container->latestDeliveryTrip !== null);
        $container->setAttribute('delivery_trip_id', $container->latestDeliveryTrip?->id);
        $container->setAttribute('delivery_trip_status', $container->latestDeliveryTrip?->status);

        return $container;
    }

    private function containerTrackingStatus(Container $container): ?string
    {
        if ($container->latestTracking !== null) {
            return $container->latestTracking->status;
        }

        return Container::trackingStatusFromCurrentState(
            (string) ($container->status ?? Container::STATUS_OPEN),
            $container->relationLoaded('barangs') ? $container->barangs : null,
        );
    }

    private function fallbackContainerTrackingLocation(Container $container, ?string $trackingStatus): string
    {
        if ($trackingStatus === null) {
            return '-';
        }

        if ($trackingStatus === Container::TRACKING_LOADING) {
            return trim((string) ($container->gudangChina?->name ?? $container->alamat_gudang_china ?? ''))
                ?: __('tracking.auto_container_loading_location_fallback');
        }

        return $container->destinations_list !== '-'
            ? (string) $container->destinations_list
            : match ($trackingStatus) {
                Container::TRACKING_ETD => __('tracking.auto_etd_location_fallback'),
                Container::TRACKING_ETA => __('tracking.auto_eta_location_fallback'),
                Container::TRACKING_BEA_CUKAI => __('tracking.auto_bea_cukai_location_fallback'),
                Container::TRACKING_BONGKAR => __('tracking.auto_bongkar_location_fallback'),
                default => '-',
            };
    }

    private function fallbackContainerTrackingDescription(Container $container, ?string $trackingStatus): string
    {
        return match ($trackingStatus) {
            Container::TRACKING_LOADING => __('tracking.auto_container_loading_description', ['container' => $this->containerLabel($container)]),
            Container::TRACKING_ETD => __('tracking.auto_container_etd_description'),
            Container::TRACKING_ETA => __('tracking.auto_container_eta_description'),
            Container::TRACKING_BEA_CUKAI => __('tracking.auto_container_bea_cukai_description'),
            Container::TRACKING_BONGKAR => __('tracking.auto_container_bongkar_description'),
            default => '-',
        };
    }

    private function containerLabel(Container $container): string
    {
        $parts = array_filter([
            trim((string) ($container->partai ?? '')),
            trim((string) ($container->container_no ?? '')),
        ]);

        return $parts !== [] ? implode(' / ', $parts) : '#'.$container->id;
    }

    private function joinUnique(Collection $values, string $fallback = '-'): string
    {
        $items = $values
            ->map(static fn ($value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        return $items->isNotEmpty() ? $items->implode(', ') : $fallback;
    }

    private function containerItemsSummary(Container $container): string
    {
        $labels = collect();

        foreach ($container->barangs as $barang) {
            $summary = $barang->itemsSummary();
            if ($summary === '-') {
                continue;
            }

            foreach (explode(', ', $summary) as $label) {
                $trimmed = trim($label);
                if ($trimmed !== '') {
                    $labels->push($trimmed);
                }
            }
        }

        return $labels->unique()->values()->isNotEmpty()
            ? $labels->unique()->values()->implode(', ')
            : '-';
    }

    private function isTrackingAjaxRequest(Request $request): bool
    {
        return $request->ajax() || $request->wantsJson() || $request->boolean('ajax');
    }

    private function staffContainerAjaxResponse(LengthAwarePaginator $containers, array $stats): JsonResponse
    {
        return response()->json([
            'stats_html' => view('tracking._partials.staff-container-stats', compact('stats'))->render(),
            'table_html' => view('tracking._partials.staff-container-rows', compact('containers'))->render(),
            'pagination_html' => $containers->withQueryString()->links('vendor.pagination.bootstrap-5')->toHtml(),
            'total' => $containers->total(),
            'total_label' => __('tracking.containers_count', ['count' => $containers->total()]),
        ]);
    }

    private function barangTrackingAjaxResponse(
        LengthAwarePaginator $barangs,
        array $stats,
        string $rowsView,
    ): JsonResponse {
        return response()->json([
            'stats_html' => view('tracking._partials.barang-tracking-stats', compact('stats'))->render(),
            'table_html' => view($rowsView, compact('barangs'))->render(),
            'pagination_html' => $barangs->withQueryString()->links('vendor.pagination.bootstrap-5')->toHtml(),
            'total' => $barangs->total(),
            'total_label' => __('tracking.items_count', ['count' => $barangs->total()]),
        ]);
    }

    // ── Private helpers ──
    private function applyFilters($query, Request $request): void
    {
        if ($noResi = trim((string) $request->get('no_resi', ''))) {
            $query->where('no_index', 'like', '%'.$noResi.'%');
        }
        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('marking', 'like', "%{$search}%")
                ->orWhere('no_po', 'like', "%{$search}%"));
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($dest = $request->get('destination')) {
            $query->where('destination', $dest);
        }
        if ($from = $request->get('date_from')) {
            $query->whereDate('tanggal_masuk', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('tanggal_masuk', '<=', $to);
        }
    }

    private function getGlobalStats(): array
    {
        return [
            'total' => Barang::count(),
        ] + $this->buildShipmentStatusStats(Barang::query());
    }

    /** @return array<string, int> */
    private function buildShipmentStatusStats($query): array
    {
        $stats = [];
        foreach (Barang::SHIPMENT_STATUSES as $status) {
            $stats[$status] = (clone $query)->where('status', $status)->count();
        }

        return $stats;
    }

    private function assertBarangStatusTransitionAllowed(Barang $barang, string $newStatus, ?\App\Models\User $user): void
    {
        $oldStatus = Barang::normalizeShipmentStatus((string) $barang->status);
        $targetStatus = Barang::normalizeShipmentStatus($newStatus);

        if ($targetStatus === Barang::STATUS_DITARIK_KEMBALI) {
            abort_unless(Barang::userCanSetDitarikKembali($user), 403);
            abort_unless($barang->canWithdrawToDitarikKembali(), 422, (string) __('messages.barang_withdraw_not_allowed'));

            return;
        }

        if ($oldStatus === Barang::STATUS_DITARIK_KEMBALI) {
            abort_unless(Barang::userCanSetDitarikKembali($user), 403);
            abort_unless(
                $targetStatus === Barang::STATUS_STUFFING,
                422,
                (string) __('messages.barang_withdraw_reactivate_only_stuffing'),
            );

            return;
        }
    }

    private function assertBarangStatusUpdateAuthorized(Barang $barang, string $newStatus, ?\App\Models\User $user): void
    {
        $oldStatus = Barang::normalizeShipmentStatus((string) $barang->status);
        $targetStatus = Barang::normalizeShipmentStatus($newStatus);
        $isWithdrawReactivate = $targetStatus === Barang::STATUS_DITARIK_KEMBALI
            || $oldStatus === Barang::STATUS_DITARIK_KEMBALI;

        if ($isWithdrawReactivate) {
            abort_unless(Barang::userCanSetDitarikKembali($user), 403);

            return;
        }

        abort_unless($user?->can('update tracking status'), 403);
    }

    private function resolveTrackingStatusDescription(
        Barang $barang,
        string $targetStatus,
        string $previousStatus,
        ?string $description,
    ): ?string {
        $description = $description !== null ? trim($description) : null;
        if ($description !== null && $description !== '') {
            return $description;
        }

        return match ($targetStatus) {
            Barang::STATUS_DITARIK_KEMBALI => (string) __('tracking.auto_ditarik_kembali_description'),
            Barang::STATUS_STUFFING => Barang::isDitarikKembaliStatus($previousStatus)
                ? (string) __('tracking.auto_stuffing_description')
                : null,
            Barang::STATUS_ETD => (string) __('tracking.auto_etd_description'),
            Barang::STATUS_ETA => (string) __('tracking.auto_eta_description'),
            Barang::STATUS_BONGKAR => (string) __('tracking.auto_bongkar_description'),
            default => null,
        };
    }

    /** @return array<string, string> */
    private function statusLabels(): array
    {
        $labels = [];
        foreach (Barang::SHIPMENT_STATUSES as $status) {
            $labels[$status] = Barang::shipmentStatusLabel($status);
        }

        return $labels;
    }

    private function decorateTrackingDisplay(Barang $barang): Barang
    {
        $latest = $barang->latestTracking;
        $sameStatus = $latest !== null && $latest->status === $barang->status;
        $forCustomer = auth()->user()?->hasRole('customer') === true;

        if ($forCustomer && CustomerTrackingPresenter::isAutoStuffingEntry($latest)) {
            $barang->setAttribute('display_tracking_location', trim((string) ($latest->location ?? '')) ?: '-');
            $barang->setAttribute('display_tracking_description', (string) __('tracking.customer_notes_warehouse'));
            $barang->setAttribute('display_nama_barang', $barang->itemsSummary());

            return $barang;
        }

        $barang->setAttribute(
            'display_tracking_location',
            $sameStatus
                ? (trim((string) ($latest->location ?? '')) ?: '-')
                : $this->fallbackTrackingLocation($barang)
        );

        $barang->setAttribute(
            'display_tracking_description',
            $sameStatus
                ? (trim((string) ($latest->description ?? '')) ?: '-')
                : $this->fallbackTrackingDescription($barang)
        );

        $barang->setAttribute('display_nama_barang', $barang->itemsSummary());

        return $barang;
    }

    /** @param  Collection<int, Barang>  $barangs */
    private function decorateStaffBarangTrackingRows(Collection $barangs): Collection
    {
        $markingLookup = Marking::lookupForBarangValues($barangs->pluck('marking'));

        return $barangs->map(function (Barang $barang) use ($markingLookup): Barang {
            $decorated = $this->decorateTrackingDisplay($barang);
            $markingValue = trim((string) ($barang->marking ?? ''));
            $markingRecord = $markingValue !== '' ? $markingLookup->get($markingValue) : null;
            $decorated->setAttribute(
                'marketing_name',
                trim((string) ($markingRecord?->sales?->name ?? '')) ?: '-',
            );

            return $decorated;
        });
    }

    private function fallbackTrackingLocation(Barang $barang): string
    {
        return match ($barang->status) {
            Barang::STATUS_ETD => trim((string) ($barang->destination ?? '')) ?: __('tracking.auto_etd_location_fallback'),
            Barang::STATUS_ETA => trim((string) ($barang->destination ?? '')) ?: __('tracking.auto_eta_location_fallback'),
            Barang::STATUS_BONGKAR => trim((string) ($barang->destination ?? '')) ?: __('tracking.auto_bongkar_location_fallback'),
            default => trim((string) ($barang->latestTracking?->location ?? '')) ?: '-',
        };
    }

    private function fallbackTrackingDescription(Barang $barang): string
    {
        return match ($barang->status) {
            Barang::STATUS_DITARIK_KEMBALI => __('tracking.auto_ditarik_kembali_description'),
            Barang::STATUS_ETD => __('tracking.auto_etd_description'),
            Barang::STATUS_ETA => __('tracking.auto_eta_description'),
            Barang::STATUS_BONGKAR => __('tracking.auto_bongkar_description'),
            default => trim((string) ($barang->latestTracking?->description ?? '')) ?: '-',
        };
    }
}
