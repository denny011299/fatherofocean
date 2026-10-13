<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\BongkarMuatScan;
use App\Models\BongkarMuatSession;
use App\Models\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class BongkarMuatController extends Controller
{
    /** Status barang yang masih perlu dicek saat bongkar muat. */
    private const BONGKAR_ELIGIBLE_STATUSES = [
        Barang::STATUS_INBOUND,
        Barang::STATUS_STUFFING,
        Barang::STATUS_ETD,
        Barang::STATUS_ETA,
    ];

    public function index(): View
    {
        abort_unless(auth()->user()?->can('view bongkar muat'), 403);

        return view('bongkar-muat.index');
    }

    public function data(): JsonResponse
    {
        abort_unless(auth()->user()?->can('view bongkar muat'), 403);

        $rows = BongkarMuatSession::query()
            ->with(['container:id,partai,container_no,no_bl', 'starter:id,name', 'completer:id,name'])
            ->withCount('scans')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (BongkarMuatSession $session): array {
                $container = $session->container;

                return [
                    'id' => $session->id,
                    'partai' => $container?->partai ?? '—',
                    'container_no' => $container?->container_no ?? '—',
                    'container_label' => $container ? self::containerSelectLabel($container) : '—',
                    'status' => $session->status,
                    'status_label' => BongkarMuatSession::statusLabel($session->status),
                    'scan_count' => (int) $session->scans_count,
                    'started_by' => $session->starter?->name ?? '—',
                    'completed_by' => $session->completer?->name ?? '—',
                    'completed_at' => $session->completed_at?->format('d/m/Y H:i') ?? '—',
                    'updated_at' => $session->updated_at?->format('d/m/Y H:i') ?? '—',
                    'can_continue' => $session->isInProgress() && auth()->user()?->can('manage bongkar muat'),
                ];
            });

        return response()->json($rows);
    }

    public function create(Request $request): View
    {
        abort_unless(auth()->user()?->can('manage bongkar muat'), 403);

        $resumeSession = null;
        if ($request->filled('session')) {
            $session = BongkarMuatSession::query()
                ->with('container')
                ->findOrFail((int) $request->get('session'));
            abort_unless($session->isInProgress(), 404);
            $resumeSession = $this->sessionPayload($session, $session->container);
        }

        return view('bongkar-muat.create', [
            'initialContainers' => $this->availableContainersForSelect(),
            'resumeSession' => $resumeSession,
        ]);
    }

    public function containers(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('view bongkar muat'), 403);

        $search = trim((string) $request->get('q', ''));

        $query = $this->availableContainersQuery();

        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(static function (Builder $q) use ($term): void {
                $q->where('partai', 'like', $term)
                    ->orWhere('container_no', 'like', $term)
                    ->orWhere('no_bl', 'like', $term);
            });
        }

        $rows = $query->limit(40)->get()->map(fn (Container $c) => [
            'id' => $c->id,
            'text' => self::containerSelectLabel($c, (int) $c->barangs_count),
            'partai' => $c->partai,
            'container_no' => $c->container_no,
            'barang_count' => (int) $c->barangs_count,
        ]);

        return response()->json(['results' => $rows]);
    }

    public function openSession(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage bongkar muat'), 403);

        $data = $request->validate([
            'container_id' => 'required|integer|exists:containers,id',
        ]);

        $containerId = (int) $data['container_id'];
        $container = Container::query()->findOrFail($containerId);

        $session = BongkarMuatSession::query()
            ->where('container_id', $containerId)
            ->where('status', BongkarMuatSession::STATUS_IN_PROGRESS)
            ->first();

        if ($session === null) {
            $session = BongkarMuatSession::query()->create([
                'container_id' => $containerId,
                'status' => BongkarMuatSession::STATUS_IN_PROGRESS,
                'started_by' => auth()->id(),
            ]);
        }

        return response()->json($this->sessionPayload($session->fresh(['container']), $container));
    }

    public function lookupScan(Request $request, BongkarMuatSession $session): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage bongkar muat'), 403);
        abort_unless($session->isInProgress(), 422, __('bongkar_muat.session_completed'));

        $data = $request->validate([
            'no_index' => 'required|string|max:50',
        ]);

        $noIndex = trim($data['no_index']);
        $barang = $this->findBarangInContainer($session->container_id, $noIndex);

        if ($barang === null) {
            return response()->json([
                'success' => false,
                'message' => __('bongkar_muat.barang_not_in_container', ['no_index' => $noIndex]),
            ], 404);
        }

        $existing = BongkarMuatScan::query()
            ->where('bongkar_muat_session_id', $session->id)
            ->where('barang_id', $barang->id)
            ->first();

        if ($existing !== null) {
            return response()->json([
                'success' => false,
                'message' => __('bongkar_muat.already_scanned', ['no_index' => $noIndex]),
                'scan' => $this->scanRow($existing->load('barang')),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'barang' => $this->barangPreview($barang),
        ]);
    }

    public function confirmScan(Request $request, BongkarMuatSession $session): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage bongkar muat'), 403);
        abort_unless($session->isInProgress(), 422, __('bongkar_muat.session_completed'));

        $data = $request->validate([
            'barang_id' => 'required|integer|exists:barangs,id',
            'confirmed_ctns' => 'required|integer|min:0|max:99999',
            'variance_note' => 'nullable|string|max:500',
        ]);

        $barang = Barang::query()->findOrFail((int) $data['barang_id']);
        $this->assertBarangBelongsToContainer($session->container_id, $barang);

        if (BongkarMuatScan::query()
            ->where('bongkar_muat_session_id', $session->id)
            ->where('barang_id', $barang->id)
            ->exists()) {
            return response()->json([
                'success' => false,
                'message' => __('bongkar_muat.already_scanned', ['no_index' => $barang->no_index]),
            ], 422);
        }

        $expected = max(0, (int) $barang->ctns);
        $confirmed = (int) $data['confirmed_ctns'];
        $hasVariance = $confirmed !== $expected;
        $note = trim((string) ($data['variance_note'] ?? ''));

        if ($hasVariance && $note === '') {
            return response()->json([
                'success' => false,
                'message' => __('bongkar_muat.variance_note_required'),
                'requires_note' => true,
            ], 422);
        }

        $scan = BongkarMuatScan::query()->create([
            'bongkar_muat_session_id' => $session->id,
            'barang_id' => $barang->id,
            'no_index' => (string) $barang->no_index,
            'expected_ctns' => $expected,
            'confirmed_ctns' => $confirmed,
            'variance_note' => $hasVariance ? $note : null,
            'scanned_by' => auth()->id(),
            'scanned_at' => now(),
        ]);

        ActivityLog::record('Bongkar muat scan', $barang, null, [
            'session_id' => $session->id,
            'expected_ctns' => $expected,
            'confirmed_ctns' => $confirmed,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('bongkar_muat.scan_saved'),
            'session' => $this->sessionPayload($session->fresh(['container']), $session->container),
            'scan' => $this->scanRow($scan->load('barang')),
        ]);
    }

    public function removeScan(BongkarMuatSession $session, BongkarMuatScan $scan): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage bongkar muat'), 403);
        abort_unless($session->isInProgress(), 422, __('bongkar_muat.session_completed'));
        abort_unless((int) $scan->bongkar_muat_session_id === (int) $session->id, 404);

        $scan->delete();

        return response()->json([
            'success' => true,
            'message' => __('bongkar_muat.scan_removed'),
            'session' => $this->sessionPayload($session->fresh(['container']), $session->container),
        ]);
    }

    public function complete(Request $request, BongkarMuatSession $session): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage bongkar muat'), 403);
        abort_unless($session->isInProgress(), 422, __('bongkar_muat.session_completed'));

        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        $container = $session->container;
        $expectedBarangs = $this->eligibleBarangsQuery($session->container_id)->get();
        $scannedIds = $session->scans()->pluck('barang_id')->all();

        $missing = $expectedBarangs->filter(static fn (Barang $b) => ! in_array($b->id, $scannedIds, true));

        if ($missing->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => __('bongkar_muat.incomplete_scan', [
                    'count' => $missing->count(),
                    'items' => $missing->pluck('no_index')->take(8)->implode(', '),
                ]),
            ], 422);
        }

        DB::transaction(function () use ($session, $data, $expectedBarangs): void {
            foreach ($session->scans()->with('barang')->get() as $scan) {
                $barang = $scan->barang;
                if ($barang === null) {
                    continue;
                }

                if ($scan->hasVariance()) {
                    $barang->update(['ctns' => $scan->confirmed_ctns]);
                    ActivityLog::record('Bongkar muat coly adjusted', $barang, [
                        'ctns' => $scan->expected_ctns,
                    ], [
                        'ctns' => $scan->confirmed_ctns,
                        'note' => $scan->variance_note,
                    ]);
                }

                if (in_array($barang->status, [
                    Barang::STATUS_INBOUND,
                    Barang::STATUS_STUFFING,
                    Barang::STATUS_ETD,
                ], true)) {
                    $barang->update(['status' => Barang::STATUS_ETA]);
                }
            }

            $session->update([
                'status' => BongkarMuatSession::STATUS_COMPLETED,
                'notes' => $data['notes'] ?? $session->notes,
                'completed_by' => auth()->id(),
                'completed_at' => now(),
            ]);

            ActivityLog::record('Bongkar muat completed', $session->fresh('container'));
        });

        return response()->json([
            'success' => true,
            'message' => __('bongkar_muat.session_completed_msg'),
            'redirect' => route('bongkar-muat.index'),
        ]);
    }

    /**
     * Partai dari Container Loading yang belum selesai bongkar muat.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Container>
     */
    private function availableContainersQuery(): Builder
    {
        return Container::query()
            ->has('barangs')
            ->whereDoesntHave('bongkarMuatSessions', static function (Builder $q): void {
                $q->where('status', BongkarMuatSession::STATUS_COMPLETED);
            })
            ->withCount('barangs')
            ->orderByDesc('id');
    }

    /**
     * @return list<array{id: int, text: string}>
     */
    private function availableContainersForSelect(): array
    {
        return $this->availableContainersQuery()
            ->limit(50)
            ->get()
            ->map(fn (Container $c) => [
                'id' => $c->id,
                'text' => self::containerSelectLabel($c, (int) $c->barangs_count),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(BongkarMuatSession $session, ?Container $container = null): array
    {
        $container ??= $session->container;
        $eligible = $this->eligibleBarangsQuery($session->container_id)->get();
        $scans = $session->scans()
            ->with('barang:id,no_index,marking,customer_name,ctns')
            ->orderByDesc('scanned_at')
            ->get()
            ->map(fn (BongkarMuatScan $s) => $this->scanRow($s));

        $scannedIds = $scans->pluck('barang_id')->all();
        $pending = $eligible
            ->reject(static fn (Barang $b) => in_array($b->id, $scannedIds, true))
            ->values()
            ->map(fn (Barang $b) => $this->barangPreview($b));

        return [
            'session' => [
                'id' => $session->id,
                'container_id' => $session->container_id,
                'status' => $session->status,
                'status_label' => BongkarMuatSession::statusLabel($session->status),
                'partai' => $container?->partai,
                'container_label' => $container ? self::containerSelectLabel($container) : null,
            ],
            'progress' => [
                'total' => $eligible->count(),
                'scanned' => $scans->count(),
                'pending' => $pending->count(),
            ],
            'scans' => $scans->values()->all(),
            'pending' => $pending->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scanRow(BongkarMuatScan $scan): array
    {
        return [
            'id' => $scan->id,
            'barang_id' => $scan->barang_id,
            'no_index' => $scan->no_index,
            'marking' => $scan->barang?->marking,
            'customer_name' => $scan->barang?->customer_name,
            'expected_ctns' => (int) $scan->expected_ctns,
            'confirmed_ctns' => (int) $scan->confirmed_ctns,
            'has_variance' => $scan->hasVariance(),
            'variance_note' => $scan->variance_note,
            'scanned_at' => $scan->scanned_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function barangPreview(Barang $barang): array
    {
        return [
            'id' => $barang->id,
            'no_index' => $barang->no_index,
            'marking' => $barang->marking,
            'customer_name' => $barang->customer_name,
            'expected_ctns' => max(0, (int) $barang->ctns),
            'status' => $barang->status,
            'status_label' => Barang::shipmentStatusLabel($barang->status),
        ];
    }

    private function eligibleBarangsQuery(int $containerId): Builder
    {
        $query = Barang::query()
            ->whereHas('containers', static function (Builder $q) use ($containerId): void {
                $q->where('containers.id', $containerId);
            })
            ->orderBy('no_index');

        $this->scopeUnloadEligibleBarangs($query);

        return $query;
    }

    private function scopeUnloadEligibleBarangs(Builder $query): void
    {
        $query->whereIn('barangs.status', self::BONGKAR_ELIGIBLE_STATUSES);
    }

    private function findBarangInContainer(int $containerId, string $noIndex): ?Barang
    {
        $normalized = strtolower(trim($noIndex));
        if ($normalized === '') {
            return null;
        }

        return $this->eligibleBarangsQuery($containerId)
            ->whereRaw('LOWER(TRIM(no_index)) = ?', [$normalized])
            ->first();
    }

    private function assertBarangBelongsToContainer(int $containerId, Barang $barang): void
    {
        $exists = $this->eligibleBarangsQuery($containerId)
            ->where('barangs.id', $barang->id)
            ->exists();

        abort_unless($exists, 422, __('bongkar_muat.barang_not_eligible'));
    }

    private static function containerSelectLabel(Container $container, ?int $barangCount = null): string
    {
        $partai = trim((string) ($container->partai ?? ''));
        $no = trim((string) ($container->container_no ?? ''));

        if ($partai !== '' && $no !== '') {
            $label = $partai.' — '.$no;
        } else {
            $label = $partai !== '' ? $partai : ($no !== '' ? $no : '#'.$container->id);
        }

        if ($barangCount !== null && $barangCount > 0) {
            return $label.' ('.$barangCount.' '.__('container.items_count_suffix').')';
        }

        return $label;
    }
}
