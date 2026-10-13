<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Container;
use App\Models\DeliveryTrip;
use App\Models\DeliveryTripMedia;
use App\Models\Marking;
use App\Support\DeliverySplitDeliveries;
use App\Support\DeliveryTripMediaStorage;
use App\Support\PublicStorageUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class DeliveryTripController extends Controller
{
    public function index(): View
    {
        return view('delivery-trip.index');
    }

    public function data(): JsonResponse
    {
        $trips = DeliveryTrip::query()
            ->with(['container:id,partai,container_no', 'creator:id,name'])
            ->withCount('barangs')
            ->latest()
            ->get()
            ->map(fn (DeliveryTrip $trip) => [
                'id' => $trip->id,
                'trip_no' => $trip->trip_no,
                'truck_plate' => $trip->truck_plate,
                'driver_name' => $trip->driver_name,
                'destination' => $trip->destination,
                'container_partai' => $trip->container?->partai,
                'item_count' => $trip->barangs_count,
                'status' => $trip->status,
                'status_label' => DeliveryTrip::statusLabel($trip->status),
                'departed_at' => $trip->departed_at?->format('d M Y H:i'),
                'arrived_at' => $trip->arrived_at?->format('d M Y H:i'),
                'created_at' => $trip->created_at?->format('d M Y'),
            ]);

        return response()->json($trips);
    }

    public function create(): View
    {
        return view('delivery-trip.create');
    }

    public function eligibleBarangs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'container_id' => 'nullable|integer|exists:containers,id',
            'search' => 'nullable|string|max:100',
        ]);

        $query = Barang::query()
            ->with('customer:id,name')
            ->eligibleForDeliveryTrip();

        Marking::restrictBarangsQuery($query);

        if (! empty($data['container_id'])) {
            $query->whereHas('containers', static function (Builder $q) use ($data): void {
                $q->where('containers.id', $data['container_id']);
            });
        }

        if (! empty($data['search'])) {
            $term = '%'.$data['search'].'%';
            $query->where(static function (Builder $q) use ($term): void {
                $q->where('no_index', 'like', $term)
                    ->orWhere('marking', 'like', $term)
                    ->orWhere('customer_name', 'like', $term);
            });
        }

        $rows = $query->orderBy('no_index')->limit(200)->get()->map(static fn (Barang $b) => [
            'id' => $b->id,
            'no_index' => $b->no_index,
            'marking' => $b->marking,
            'customer_name' => $b->customer_name,
            'ctns' => $b->ctns,
            'total_cbm' => round((float) $b->total_cbm, 4),
            'status' => $b->status,
            'status_label' => Barang::shipmentStatusLabel($b->status),
        ]);

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('create delivery trip'), 403);

        $data = $request->validate([
            'container_id' => 'nullable|integer|exists:containers,id',
            'truck_plate' => 'required|string|max:30',
            'driver_name' => 'required|string|max:120',
            'driver_phone' => 'nullable|string|max:30',
            'destination' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'barang_ids' => 'required|array|min:1',
            'barang_ids.*' => 'integer|distinct|exists:barangs,id',
        ]);

        $barangIds = array_values(array_unique(array_map('intval', $data['barang_ids'])));

        $trip = DB::transaction(function () use ($data, $barangIds) {
            $this->assertBarangsEligible($barangIds, isset($data['container_id']) ? (int) $data['container_id'] : null);

            $trip = DeliveryTrip::query()->create([
                'trip_no' => DeliveryTrip::generateTripNo(),
                'container_id' => $data['container_id'] ?? null,
                'truck_plate' => trim($data['truck_plate']),
                'driver_name' => trim($data['driver_name']),
                'driver_phone' => isset($data['driver_phone']) ? trim((string) $data['driver_phone']) : null,
                'destination' => isset($data['destination']) ? trim((string) $data['destination']) : null,
                'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
                'status' => DeliveryTrip::STATUS_READY,
                'created_by' => auth()->id(),
            ]);

            $sync = [];
            foreach ($barangIds as $i => $barangId) {
                $sync[$barangId] = ['sort_order' => $i];
            }
            $trip->barangs()->sync($sync);

            ActivityLog::record('Delivery trip created', $trip, null, [
                'trip_no' => $trip->trip_no,
                'barang_count' => count($barangIds),
            ]);

            return $trip;
        });

        \App\Support\ShipmentNotificationService::notifyDeliveryTripCreated($trip, auth()->id());

        return response()->json([
            'success' => true,
            'message' => __('delivery_trip.created'),
            'redirect' => route('delivery-trip.show', $trip),
        ]);
    }

    public function show(DeliveryTrip $deliveryTrip): View
    {
        abort_unless(auth()->user()?->can('view delivery trip'), 403);

        $deliveryTrip->load([
            'container:id,partai,container_no,eta',
            'creator:id,name',
            'departedConfirmer:id,name',
            'arrivedConfirmer:id,name',
            'barangs' => static fn ($q) => $q->with('customer:id,name'),
            'media.uploader:id,name',
        ]);

        $mediaPayload = collect($deliveryTrip->media)->map(static fn (DeliveryTripMedia $m) => [
            'id' => $m->id,
            'phase' => $m->phase,
            'media_type' => $m->media_type,
            'url' => $m->url(),
            'original_name' => $m->original_name,
            'uploaded_by' => $m->uploader?->name,
            'created_at' => $m->created_at?->format('d M Y H:i'),
        ]);

        return view('delivery-trip.show', [
            'trip' => $deliveryTrip,
            'departurePhotos' => $mediaPayload->where('phase', DeliveryTripMedia::PHASE_DEPARTURE)->where('media_type', DeliveryTripMedia::TYPE_PHOTO)->values(),
            'departureVideos' => $mediaPayload->where('phase', DeliveryTripMedia::PHASE_DEPARTURE)->where('media_type', DeliveryTripMedia::TYPE_VIDEO)->values(),
            'arrivalPhotos' => $mediaPayload->where('phase', DeliveryTripMedia::PHASE_ARRIVAL)->where('media_type', DeliveryTripMedia::TYPE_PHOTO)->values(),
            'arrivalVideos' => $mediaPayload->where('phase', DeliveryTripMedia::PHASE_ARRIVAL)->where('media_type', DeliveryTripMedia::TYPE_VIDEO)->values(),
            'canConfirmDeparture' => auth()->user()?->can('confirm delivery trip') && $deliveryTrip->canConfirmDeparture(),
            'canConfirmArrival' => auth()->user()?->can('confirm delivery trip') && $deliveryTrip->canConfirmArrival(),
            'canPdf' => auth()->user()?->can('export delivery trip pdf') && $deliveryTrip->canGeneratePdf(),
        ]);
    }

    public function confirmDeparture(Request $request, DeliveryTrip $deliveryTrip): JsonResponse
    {
        abort_unless(auth()->user()?->can('confirm delivery trip'), 403);
        abort_unless($deliveryTrip->canConfirmDeparture(), 422, __('delivery_trip.cannot_confirm_departure'));

        $request->validate([
            'photos' => 'required|array|min:1',
            'photos.*' => 'file|mimes:jpg,jpeg,png,webp|max:'.DeliveryTripMediaStorage::MAX_PHOTO_KB,
            'videos' => 'required|array|min:1',
            'videos.*' => 'file|mimes:mp4,mov,webm,avi,3gp|max:'.DeliveryTripMediaStorage::MAX_VIDEO_KB,
            'notes' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($request, $deliveryTrip) {
            DeliveryTripMediaStorage::storePhase(
                $deliveryTrip,
                DeliveryTripMedia::PHASE_DEPARTURE,
                $request->file('photos', []),
                $request->file('videos', []),
                auth()->id(),
            );

            $deliveryTrip->update([
                'status' => DeliveryTrip::STATUS_DEPARTED,
                'departed_at' => now(),
                'departed_confirmed_by' => auth()->id(),
                'notes' => $request->filled('notes')
                    ? trim((string) $request->input('notes'))
                    : $deliveryTrip->notes,
            ]);

            ActivityLog::record('Delivery departure confirmed', $deliveryTrip);
        });

        return response()->json([
            'success' => true,
            'message' => __('delivery_trip.departure_confirmed'),
            'redirect' => route('delivery-trip.show', $deliveryTrip),
        ]);
    }

    public function confirmArrival(Request $request, DeliveryTrip $deliveryTrip): JsonResponse
    {
        abort_unless(auth()->user()?->can('confirm delivery trip'), 403);
        abort_unless($deliveryTrip->canConfirmArrival(), 422, __('delivery_trip.cannot_confirm_arrival'));

        $request->validate([
            'photos' => 'required|array|min:1',
            'photos.*' => 'file|mimes:jpg,jpeg,png,webp|max:'.DeliveryTripMediaStorage::MAX_PHOTO_KB,
            'videos' => 'required|array|min:1',
            'videos.*' => 'file|mimes:mp4,mov,webm,avi,3gp|max:'.DeliveryTripMediaStorage::MAX_VIDEO_KB,
            'notes' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($request, $deliveryTrip) {
            DeliveryTripMediaStorage::storePhase(
                $deliveryTrip,
                DeliveryTripMedia::PHASE_ARRIVAL,
                $request->file('photos', []),
                $request->file('videos', []),
                auth()->id(),
            );

            $deliveryTrip->update([
                'status' => DeliveryTrip::STATUS_ARRIVED,
                'arrived_at' => now(),
                'arrived_confirmed_by' => auth()->id(),
            ]);

            foreach ($deliveryTrip->barangs as $barang) {
                if ($barang->status !== Barang::STATUS_BONGKAR) {
                    $barang->update(['status' => Barang::STATUS_BONGKAR]);
                }
            }

            ActivityLog::record('Delivery arrival confirmed', $deliveryTrip);
        });

        return response()->json([
            'success' => true,
            'message' => __('delivery_trip.arrival_confirmed'),
            'redirect' => route('delivery-trip.show', $deliveryTrip),
        ]);
    }

    public function complete(DeliveryTrip $deliveryTrip): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage delivery trip'), 403);
        abort_unless($deliveryTrip->status === DeliveryTrip::STATUS_ARRIVED, 422);

        $deliveryTrip->update(['status' => DeliveryTrip::STATUS_COMPLETED]);

        return response()->json([
            'success' => true,
            'message' => __('delivery_trip.completed'),
        ]);
    }

    public function pdf(Request $request, DeliveryTrip $deliveryTrip): Response
    {
        abort_unless(auth()->user()?->can('export delivery trip pdf'), 403);
        abort_unless($deliveryTrip->canGeneratePdf(), 404);

        $markingKey = trim((string) $request->query('marking', ''));
        abort_if($markingKey === '', 422, __('delivery_trip.marking_required_for_pdf'));

        $deliveryTrip->loadMissing('markingSummaries');
        $markingRow = \App\Support\SuratJalanPdfData::markingSummaryRowForKey($deliveryTrip, $markingKey);
        abort_if($markingRow === null || ! $markingRow->truckSaved(), 422, __('delivery_trip.truck_required_for_pdf'));

        $payload = \App\Support\SuratJalanPdfData::forTrip($deliveryTrip, $markingKey);
        $safeMarking = preg_replace('/[^A-Za-z0-9_-]+/', '-', \App\Support\SuratJalanPdfData::markingLabel($markingKey)) ?: 'marking';

        $pdf = Pdf::loadView('exports.surat-jalan-pdf', $payload)
            ->setPaper('a5', 'landscape');

        return $pdf->download('Surat-Jalan-'.$deliveryTrip->trip_no.'-'.$safeMarking.'.pdf');
    }

    public function trackingSummary(Container $container): JsonResponse
    {
        abort_unless(
            (auth()->user()?->can('view delivery trip') ?? false)
            || (auth()->user()?->can('create delivery trip') ?? false)
            || (auth()->user()?->can('manage delivery trip') ?? false),
            403,
        );

        $container->loadMissing(['gudangChina:id,name', 'marking']);
        $barangs = $this->trackingContainerBarangs($container);
        $trip = $container->latestDeliveryTrip()->first();
        if ($trip !== null) {
            $this->syncTripBarangsFromContainer($trip, $barangs);
            $trip->loadMissing('media.uploader:id,name', 'markingSummaries');
        }

        return response()->json($this->trackingTripPayload($container, $trip, $barangs));
    }

    public function trackingUpsert(Request $request, Container $container): JsonResponse
    {
        abort_unless(
            (auth()->user()?->can('create delivery trip') ?? false)
            || (auth()->user()?->can('manage delivery trip') ?? false),
            403,
        );

        $markingKey = trim((string) $request->input('marking_key', ''));
        abort_if($markingKey === '', 422, __('delivery_trip.marking_required_for_pdf'));

        $container->loadMissing('marking');
        $barangs = $this->trackingContainerBarangs($container);
        abort_if($barangs->isEmpty(), 422, __('delivery_trip.no_items'));

        $trip = $this->ensureTrackingTrip($container, $barangs);

        if ($request->filled('media_phase')) {
            return $this->trackingMarkingMediaResponse($request, $container, $trip, $markingKey, $barangs);
        }

        $data = $request->validate([
            'marking_key' => 'required|string|max:255',
            'truck_plate' => 'required|string|max:30',
            'driver_name' => 'required|string|max:120',
            'driver_phone' => 'nullable|string|max:30',
            'destination' => 'nullable|string|max:255',
            'trip_notes' => 'nullable|string|max:2000',
            'marking_code' => 'nullable|string|max:255',
            'customer' => 'nullable|string|max:255',
            'telp' => 'nullable|string|max:60',
            'alamat' => 'nullable|string|max:255',
            'loading_date' => 'nullable|date',
            'nama_barang' => 'nullable|string|max:255',
            'total_coly' => 'nullable|string|max:60',
            'partai' => 'nullable|string|max:120',
            'print_notes' => 'nullable|string|max:2000',
            'receiver_name' => 'nullable|string|max:255',
            'receiver_phone' => 'nullable|string|max:60',
            'received_date' => 'nullable|date',
            'split_deliveries' => 'nullable|array',
        ]);

        $markingBarangs = $barangs->filter(
            static fn (Barang $barang): bool => \App\Support\SuratJalanPdfData::markingKey($barang) === $markingKey,
        );

        $trip = DB::transaction(function () use ($trip, $data, $request, $markingBarangs): DeliveryTrip {
            $key = trim((string) $data['marking_key']);
            $existing = $trip->markingSummaries()->where('marking_key', $key)->first();

            $attributes = [
                'truck_plate' => trim((string) $data['truck_plate']),
                'driver_name' => trim((string) $data['driver_name']),
                'driver_phone' => $this->nullableTrim($data['driver_phone'] ?? null),
                'destination' => $this->nullableTrim($data['destination'] ?? null),
                'trip_notes' => $this->nullableTrim($data['trip_notes'] ?? null),
                'status' => $existing?->status ?? DeliveryTrip::STATUS_READY,
                'marking_code' => $this->nullableTrim($data['marking_code'] ?? null),
                'customer_name' => $this->nullableTrim($data['customer'] ?? null),
                'customer_phone' => $this->nullableTrim($data['telp'] ?? null),
                'customer_address' => $this->nullableTrim($data['alamat'] ?? null),
                'loading_date' => $data['loading_date'] ?? null,
                'item_name' => $this->nullableTrim($data['nama_barang'] ?? null),
                'total_coly' => $this->nullableTrim($data['total_coly'] ?? null),
                'partai' => $this->nullableTrim($data['partai'] ?? null),
                'notes' => $this->nullableTrim($data['print_notes'] ?? null),
                'receiver_name' => $this->nullableTrim($data['receiver_name'] ?? null),
                'receiver_phone' => $this->nullableTrim($data['receiver_phone'] ?? null),
                'received_date' => $data['received_date'] ?? null,
            ];

            if (DeliverySplitDeliveries::requestIncludesSplits($request)) {
                $splits = DeliverySplitDeliveries::fromRequest($request);
                DeliverySplitDeliveries::assertValidForSave(
                    $splits,
                    $data['total_coly'] ?? null,
                    $markingBarangs,
                );
                $attributes['split_deliveries'] = $splits;
            }

            $trip->markingSummaries()->updateOrCreate(
                ['marking_key' => $key],
                $attributes,
            );

            ActivityLog::record('Delivery trip marking summary updated', $trip, null, [
                'marking_key' => $key,
            ]);

            return $trip->fresh();
        });

        return $this->trackingMarkingJsonResponse(
            $container,
            $trip,
            __('delivery_trip.marking_summary_updated'),
        );
    }

    public function blankSuratJalanTemplate(Request $request, Container $container): Response
    {
        abort_unless(
            (auth()->user()?->can('view delivery trip') ?? false)
            || (auth()->user()?->can('create delivery trip') ?? false)
            || (auth()->user()?->can('manage delivery trip') ?? false),
            403,
        );

        $markingKey = trim((string) $request->query('marking', ''));
        $barangs = $this->trackingContainerBarangs($container);
        $trip = $container->latestDeliveryTrip()->first();
        if ($trip !== null) {
            $trip->loadMissing('markingSummaries', 'container');
        }

        $payload = \App\Support\SuratJalanPdfData::blankTemplate(
            $trip,
            $container,
            $markingKey !== '' ? $markingKey : null,
            $barangs,
        );

        $pdf = Pdf::loadView('exports.surat-jalan-pdf', $payload)
            ->setPaper('a5', 'landscape');

        $partai = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($container->partai ?? 'container')) ?: 'container';
        $suffix = $markingKey !== ''
            ? '-'.(preg_replace('/[^A-Za-z0-9_-]+/', '-', \App\Support\SuratJalanPdfData::markingLabel($markingKey)) ?: 'marking')
            : '';

        return $pdf->download('Template-Surat-Jalan-'.$partai.$suffix.'.pdf');
    }

    public function rekapanSuratJalanPdf(Container $container): Response
    {
        abort_unless(
            (auth()->user()?->can('view delivery trip') ?? false)
            || (auth()->user()?->can('create delivery trip') ?? false)
            || (auth()->user()?->can('manage delivery trip') ?? false),
            403,
        );

        $container->loadMissing('marking');
        $barangs = $this->trackingContainerBarangs($container);
        $trip = $container->latestDeliveryTrip()->first();
        if ($trip !== null) {
            $this->syncTripBarangsFromContainer($trip, $barangs);
            $trip->loadMissing('markingSummaries');
        }

        $groups = \App\Support\SuratJalanPdfData::buildMarkingGroups($barangs);
        $rows = [];
        foreach ($groups as $group) {
            $markingBarangs = $group['barangs'];
            $summary = \App\Support\SuratJalanPdfData::summaryForMarking(
                $trip,
                $container,
                $markingBarangs,
                $group['key'],
            );

            $rows = array_merge(
                $rows,
                $this->buildRekapanRowsForGroup($group['key'], $group['code'], $markingBarangs, $summary),
            );
        }

        $pdf = Pdf::loadView('exports.surat-jalan-rekapan-pdf', [
            'container' => $container,
            'trip' => $trip,
            'rows' => $rows,
            'printDate' => now()->format('d/m/Y H:i'),
            'truck' => [
                'truck_plate' => trim((string) ($trip?->truck_plate ?? '')),
                'driver_name' => trim((string) ($trip?->driver_name ?? '')),
                'driver_phone' => trim((string) ($trip?->driver_phone ?? '')),
            ],
        ])->setPaper('a4', 'landscape');

        $partai = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($container->partai ?? 'container')) ?: 'container';

        return $pdf->download('Rekapan-Surat-Jalan-'.$partai.'.pdf');
    }

    public function trackingSuratJalanPage(Container $container): View
    {
        abort_unless(
            (auth()->user()?->can('view delivery trip') ?? false)
            || (auth()->user()?->can('create delivery trip') ?? false)
            || (auth()->user()?->can('manage delivery trip') ?? false),
            403,
        );

        $container->loadMissing(['gudangChina:id,name', 'marking']);
        $trip = $container->latestDeliveryTrip()->first();
        $barangs = $this->trackingContainerBarangs($container);
        if ($trip !== null) {
            $this->syncTripBarangsFromContainer($trip, $barangs);
            $trip->loadMissing('media.uploader:id,name', 'markingSummaries');
        }

        $payload = $this->trackingTripPayload($container, $trip, $barangs);

        return view('tracking.surat-jalan', [
            'container' => $container,
            'payload' => $payload,
        ]);
    }

    public function destroy(DeliveryTrip $deliveryTrip): JsonResponse
    {
        abort_unless(auth()->user()?->can('delete delivery trip'), 403);
        abort_unless(in_array($deliveryTrip->status, [DeliveryTrip::STATUS_DRAFT, DeliveryTrip::STATUS_READY, DeliveryTrip::STATUS_CANCELLED], true), 422);

        DB::transaction(function () use ($deliveryTrip) {
            DeliveryTripMediaStorage::deleteTripFiles($deliveryTrip);
            $deliveryTrip->barangs()->detach();
            $deliveryTrip->media()->delete();
            $deliveryTrip->delete();
        });

        return response()->json([
            'success' => true,
            'message' => __('delivery_trip.deleted'),
        ]);
    }

    /**
     * @param  list<int>  $barangIds
     */
    private function assertBarangsEligible(array $barangIds, ?int $containerId): void
    {
        $query = Barang::query()->eligibleForDeliveryTrip()->whereIn('id', $barangIds);
        Marking::restrictBarangsQuery($query);

        if ($containerId !== null) {
            $query->whereHas('containers', static function (Builder $q) use ($containerId): void {
                $q->where('containers.id', $containerId);
            });
        }

        $found = $query->pluck('id')->all();
        if (count($found) !== count($barangIds)) {
            abort(422, __('delivery_trip.some_barang_not_eligible'));
        }
    }

    /**
     * @return Collection<int, Barang>
     */
    private function trackingContainerBarangs(Container $container): Collection
    {
        return $container->barangs()
            ->with([
                'customer:id,name',
                'containers:id,partai,container_no',
                'products' => static fn ($q) => $q->orderBy('sort_order'),
            ])
            ->orderBy('barangs.no_index')
            ->get();
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private function syncTripBarangsFromContainer(DeliveryTrip $trip, Collection $barangs): void
    {
        $sync = [];
        foreach ($barangs as $index => $barang) {
            $sync[(int) $barang->id] = ['sort_order' => $index];
        }
        $trip->barangs()->sync($sync);
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private function ensureTrackingTrip(Container $container, Collection $barangs): DeliveryTrip
    {
        $trip = $container->latestDeliveryTrip()->first();
        if ($trip !== null) {
            $this->syncTripBarangsFromContainer($trip, $barangs);

            return $trip;
        }

        return DB::transaction(function () use ($container, $barangs): DeliveryTrip {
            $created = DeliveryTrip::query()->create([
                'trip_no' => DeliveryTrip::generateTripNo(),
                'container_id' => $container->id,
                'status' => DeliveryTrip::STATUS_READY,
                'created_by' => (int) auth()->id(),
            ]);

            $sync = [];
            foreach ($barangs as $index => $barang) {
                $sync[(int) $barang->id] = ['sort_order' => $index];
            }
            $created->barangs()->sync($sync);

            ActivityLog::record('Delivery trip created from tracking', $created, null, [
                'trip_no' => $created->trip_no,
                'container_id' => $container->id,
                'barang_count' => count($sync),
            ]);

            return $created;
        });
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, mixed>
     */
    private function trackingTripPayload(Container $container, ?DeliveryTrip $trip, Collection $barangs): array
    {
        $canPdf = ($trip !== null) && (auth()->user()?->can('export delivery trip pdf') ?? false);
        $markings = \App\Support\SuratJalanPdfData::markingGroupsForApi($trip, $container, $barangs, $canPdf);

        return [
            'exists' => $trip !== null,
            'view_url' => route('tracking.staff.surat-jalan', $container),
            'blank_template_url' => auth()->user()?->hasRole('super_admin')
                ? route('tracking.staff.surat-jalan.template', $container)
                : null,
            'rekapan_pdf_url' => route('tracking.staff.surat-jalan.rekapan', $container),
            'markings' => $markings,
            'markings_count' => count($markings),
            'container' => [
                'id' => $container->id,
                'partai' => $container->partai,
                'container_no' => $container->container_no,
                'eta' => $container->eta?->format('d/m/Y'),
                'shipments_count' => $barangs->count(),
            ],
            'trip' => [
                'id' => $trip?->id,
                'trip_no' => $trip?->trip_no ?? __('delivery_trip.status_draft'),
                'status' => $trip?->status ?? DeliveryTrip::STATUS_READY,
                'status_label' => DeliveryTrip::statusLabel($trip?->status ?? DeliveryTrip::STATUS_READY),
                'status_badge' => $trip?->statusBadgeClass() ?? 'badge-status-pending',
                'shipments' => $barangs->map(static function (Barang $barang): array {
                    return [
                        'id' => $barang->id,
                        'no_index' => $barang->no_index,
                        'marking' => $barang->marking,
                        'customer_name' => $barang->customer_name ?: ($barang->customer?->name ?? ''),
                        'ctns' => (int) $barang->ctns,
                        'total_cbm' => round((float) $barang->total_cbm, 4),
                    ];
                })->values()->all(),
                'can_pdf' => $canPdf && count($markings) > 0,
            ],
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private function trackingMarkingMediaResponse(
        Request $request,
        Container $container,
        DeliveryTrip $trip,
        string $markingKey,
        Collection $barangs,
    ): JsonResponse {
        abort_unless(auth()->user()?->can('confirm delivery trip'), 403);

        $data = $request->validate([
            'marking_key' => 'required|string|max:255',
            'media_phase' => 'required|in:departure,arrival',
            'photos' => 'required|array|min:1',
            'photos.*' => 'file|mimes:jpg,jpeg,png,webp|max:'.DeliveryTripMediaStorage::MAX_PHOTO_KB,
            'videos' => 'required|array|min:1',
            'videos.*' => 'file|mimes:mp4,mov,webm,avi,3gp|max:'.DeliveryTripMediaStorage::MAX_VIDEO_KB,
        ]);

        $markingRow = \App\Support\SuratJalanPdfData::markingSummaryRowForKey($trip, $markingKey);
        abort_if($markingRow === null || ! $markingRow->truckSaved(), 422, __('delivery_trip.truck_required_for_pdf'));

        $phase = (string) $data['media_phase'];
        if ($phase === 'departure') {
            abort_unless($markingRow->canConfirmDeparture(), 422, __('delivery_trip.cannot_confirm_departure'));
        } else {
            abort_unless($markingRow->canConfirmArrival(), 422, __('delivery_trip.cannot_confirm_arrival'));
        }

        $markingBarangs = $barangs->filter(
            static fn (Barang $barang): bool => \App\Support\SuratJalanPdfData::markingKey($barang) === $markingKey,
        );

        DB::transaction(function () use ($request, $trip, $markingKey, $phase, $markingBarangs, $markingRow): void {
            DeliveryTripMediaStorage::storePhase(
                $trip,
                $phase === 'departure' ? DeliveryTripMedia::PHASE_DEPARTURE : DeliveryTripMedia::PHASE_ARRIVAL,
                $request->file('photos', []),
                $request->file('videos', []),
                auth()->id(),
                $markingKey,
            );

            if ($phase === 'departure') {
                $markingRow->update([
                    'status' => DeliveryTrip::STATUS_DEPARTED,
                    'departed_at' => now(),
                    'departed_confirmed_by' => auth()->id(),
                ]);

                foreach ($markingBarangs as $barang) {
                    if ($barang->status !== Barang::STATUS_ETD) {
                        $barang->update(['status' => Barang::STATUS_ETD]);
                    }
                    $barang->syncEtdTracking(
                        $markingRow->destination ?: $barang->destination,
                        null,
                        auth()->id(),
                    );
                }
            } else {
                $markingRow->update([
                    'status' => DeliveryTrip::STATUS_ARRIVED,
                    'arrived_at' => now(),
                    'arrived_confirmed_by' => auth()->id(),
                ]);

                foreach ($markingBarangs as $barang) {
                    if ($barang->status !== Barang::STATUS_ETA) {
                        $barang->update(['status' => Barang::STATUS_ETA]);
                    }
                    $barang->syncEtaTracking(
                        $markingRow->destination ?: $barang->destination,
                        null,
                        auth()->id(),
                    );
                }
            }

            ActivityLog::record('Delivery marking '.$phase.' confirmed', $trip, null, [
                'marking_key' => $markingKey,
            ]);
        });

        $message = $phase === 'departure'
            ? __('delivery_trip.departure_confirmed')
            : __('delivery_trip.arrival_confirmed');

        return $this->trackingMarkingJsonResponse($container, $trip->fresh(), $message);
    }

    private function trackingMarkingJsonResponse(
        Container $container,
        DeliveryTrip $trip,
        string $message,
    ): JsonResponse {
        $this->loadTrackingTrip($trip);
        $barangs = $this->trackingContainerBarangs($container);
        $this->syncTripBarangsFromContainer($trip, $barangs);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->trackingTripPayload($container, $trip, $barangs),
        ]);
    }

    private function loadTrackingTrip(DeliveryTrip $trip): void
    {
        $trip->load([
            'container:id,partai,container_no,eta,load_date',
            'barangs' => static fn ($q) => $q
                ->with('customer:id,name', 'containers:id,partai,container_no')
                ->orderBy('delivery_trip_barang.sort_order'),
            'media.uploader:id,name',
            'markingSummaries',
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  Collection<int, Barang>  $markingBarangs
     * @param  array<string, mixed>  $summary
     * @return list<array<string, string|int|bool>>
     */
    private function buildRekapanRowsForGroup(
        string $markingKey,
        string $markingCode,
        Collection $markingBarangs,
        array $summary,
    ): array {
        $splits = DeliverySplitDeliveries::normalize($summary['split_deliveries'] ?? []);
        $items = [];
        if ($splits !== [] && DeliverySplitDeliveries::usesIndexMode($splits)) {
            $splitGroups = DeliverySplitDeliveries::groupsForIndexPdf($splits, $markingBarangs);
            foreach ($splitGroups as $split) {
                $splitAddress = trim((string) ($split['address'] ?? ''));
                $splitNotes = trim((string) ($summary['notes'] ?? $summary['trip_notes'] ?? ''));
                $splitBarangs = $split['barangs'] instanceof Collection ? $split['barangs'] : collect();
                foreach ($splitBarangs as $barang) {
                    if (! $barang instanceof Barang) {
                        continue;
                    }
                    $items[] = [
                        'no_urut' => $this->rekapanNoUrut($barang),
                        'item' => $this->rekapanItem($barang),
                        'coly' => $this->rekapanColy($barang),
                        'cbm' => $this->formatRekapanCbm((float) ($barang->total_cbm ?? 0)),
                        'kg' => $this->formatRekapanKg((float) ($barang->total_gross_weight ?? 0)),
                        'alamat' => $splitAddress !== '' ? $splitAddress : trim((string) ($summary['alamat'] ?? '')),
                        'notes' => $splitNotes !== '' ? $splitNotes : trim((string) ($barang->notes ?? '')),
                    ];
                }
            }
        }

        if ($items === []) {
            foreach ($markingBarangs as $barang) {
                if (! $barang instanceof Barang) {
                    continue;
                }
                $items[] = [
                    'no_urut' => $this->rekapanNoUrut($barang),
                    'item' => $this->rekapanItem($barang),
                    'coly' => $this->rekapanColy($barang),
                    'cbm' => $this->formatRekapanCbm((float) ($barang->total_cbm ?? 0)),
                    'kg' => $this->formatRekapanKg((float) ($barang->total_gross_weight ?? 0)),
                    'alamat' => trim((string) ($summary['alamat'] ?? '')),
                    'notes' => trim((string) ($summary['notes'] ?? $barang->notes ?? '')),
                ];
            }
        }

        if ($items === []) {
            $items[] = [
                'no_urut' => '',
                'item' => trim((string) ($summary['nama_barang'] ?? '-')),
                'coly' => trim((string) ($summary['total_coly'] ?? '')),
                'cbm' => '',
                'kg' => '',
                'alamat' => trim((string) ($summary['alamat'] ?? '')),
                'notes' => trim((string) ($summary['notes'] ?? '')),
            ];
        }

        $groupRows = [];
        $rowspan = count($items);
        foreach ($items as $index => $item) {
            $groupRows[] = [
                'show_group' => $index === 0,
                'group_rowspan' => $rowspan,
                'no_urut' => (string) ($item['no_urut'] ?? ''),
                'marking' => $markingCode !== '' ? $markingCode : $markingKey,
                'item' => (string) ($item['item'] ?? ''),
                'coly' => (string) ($item['coly'] ?? ''),
                'cbm' => (string) ($item['cbm'] ?? ''),
                'kg' => (string) ($item['kg'] ?? ''),
                'alamat' => (string) ($item['alamat'] ?? ''),
                'notes' => (string) ($item['notes'] ?? ''),
                'truck_plate' => trim((string) ($summary['truck_plate'] ?? '')),
                'driver_name' => trim((string) ($summary['driver_name'] ?? '')),
                'driver_phone' => trim((string) ($summary['driver_phone'] ?? '')),
            ];
        }

        return $groupRows;
    }

    private function rekapanNoUrut(Barang $barang): string
    {
        return trim((string) ($barang->no_index ?? ''));
    }

    private function rekapanItem(Barang $barang): string
    {
        return trim((string) $barang->itemsSummary());
    }

    private function rekapanColy(Barang $barang): string
    {
        $ctns = (int) ($barang->ctns ?? 0);

        return $ctns > 0 ? (string) $ctns : '';
    }

    private function formatRekapanCbm(float $value): string
    {
        if ($value <= 0) {
            return '';
        }

        return number_format($value, 3, '.', '');
    }

    private function formatRekapanKg(float $value): string
    {
        if ($value <= 0) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
