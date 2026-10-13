<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SuratJalanRekapanExport;
use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Container;
use App\Models\DeliveryTrip;
use App\Models\DeliveryTripMarkingSummary;
use App\Models\DeliveryTripMedia;
use App\Models\Marking;
use App\Support\CbmFormat;
use App\Support\DeliverySplitDeliveries;
use App\Support\DeliveryTripMediaStorage;
use App\Support\PublicStorageUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DeliveryTripController extends Controller
{
    public function index(): View
    {
        return view('delivery-trip.index');
    }

    public function data(): JsonResponse
    {
        $trips = DeliveryTrip::query()
            ->with([
                'container:id,partai,container_no',
                'creator:id,name',
                'barangs' => static fn ($q) => $q->with('containers:id,partai,container_no'),
            ])
            ->withCount('barangs')
            ->latest()
            ->get();

        $canExportPdf = auth()->user()?->can('export delivery trip pdf') ?? false;

        $rows = $trips->map(fn (DeliveryTrip $trip) => [
                'id' => $trip->id,
                'trip_no' => $trip->trip_no,
                'truck_plate' => $trip->truck_plate,
                'driver_name' => $trip->driver_name,
                'destination' => $trip->destination,
                'container_partai' => self::displayContainerForTrip($trip),
                'item_count' => $trip->barangs_count,
                'status' => $trip->status,
                'status_label' => DeliveryTrip::statusLabel($trip->status),
                'departed_at' => $trip->departed_at?->format('d M Y H:i'),
                'arrived_at' => $trip->arrived_at?->format('d M Y H:i'),
                'created_at' => $trip->created_at?->format('d M Y'),
                'can_pdf' => $canExportPdf && $trip->canGeneratePdf(),
                'pdf_url' => $canExportPdf && $trip->canGeneratePdf()
                    ? route('delivery-trip.pdf', $trip)
                    : null,
            ]);

        return response()->json($rows);
    }

    public function create(): View
    {
        return view('delivery-trip.create');
    }

    public function trackingSummary(Container $container): JsonResponse
    {
        abort_unless(
            (auth()->user()?->can('view delivery trip') ?? false)
            || (auth()->user()?->can('create delivery trip') ?? false)
            || (auth()->user()?->can('manage delivery trip') ?? false),
            403,
        );

        $container->loadMissing([
            'gudangChina:id,name',
            'marking',
        ]);
        $trip = $container->latestDeliveryTrip()->first();
        $barangs = $this->trackingContainerBarangs($container);
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
        if ($markingKey !== '') {
            $container->loadMissing('marking');
            $barangs = $this->trackingContainerBarangs($container);
            abort_if($barangs->isEmpty(), 422, __('delivery_trip.no_items'));

            $trip = $this->ensureTrackingTrip($container, $barangs);

            if ($request->filled('media_phase')) {
                return $this->trackingMarkingMediaResponse($request, $container, $trip, $markingKey, $barangs);
            }

            $data = $request->validate([
                'marking_key' => 'required|string|max:255',
                'truck_plate' => 'nullable|string|max:30',
                'driver_name' => 'nullable|string|max:120',
                'driver_phone' => 'nullable|string|max:30',
                'destination' => 'nullable|string|max:255',
                'trip_notes' => 'nullable|string|max:2000',
                'marking_code' => 'required|string|max:255',
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
                'split_deliveries.*.address' => 'nullable|string|max:255',
                'split_deliveries.*.receiver_name' => 'nullable|string|max:255',
                'split_deliveries.*.receiver_phone' => 'nullable|string|max:60',
                'split_deliveries.*.coly' => 'nullable|integer|min:0|max:99999',
                'split_deliveries.*.barang_ids' => 'nullable|array',
                'split_deliveries.*.barang_ids.*' => 'integer|min:1',
                'split_deliveries.*.no_indexes' => 'nullable|array',
                'split_deliveries.*.no_indexes.*' => 'string|max:60',
            ]);

            $splitDeliveries = DeliverySplitDeliveries::fromRequest($request);
            $persistSplits = DeliverySplitDeliveries::requestIncludesSplits($request);
            $markingBarangs = $barangs->filter(
                static fn (Barang $barang): bool => \App\Support\SuratJalanPdfData::markingKey($barang) === $markingKey,
            );
            if ($persistSplits) {
                DeliverySplitDeliveries::assertValidForSave(
                    $splitDeliveries,
                    $this->nullableTrim($data['total_coly'] ?? null),
                    $markingBarangs,
                );
            }

            $trip = DB::transaction(function () use ($trip, $data, $splitDeliveries, $persistSplits): DeliveryTrip {
                $key = trim((string) $data['marking_key']);
                $existing = $trip->markingSummaries()->where('marking_key', $key)->first();
                $truckFields = $this->markingSummaryTruckFields($data, $existing);

                $summaryAttributes = [
                        'truck_plate' => $truckFields['truck_plate'],
                        'driver_name' => $truckFields['driver_name'],
                        'driver_phone' => $truckFields['driver_phone'],
                        'destination' => $this->nullableOptionalTruckTrim($data['destination'] ?? null),
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

                // PDF-only: persisted so split Surat Jalan pages can be re-generated; no invoice/finance split.
                if ($persistSplits) {
                    $summaryAttributes['split_deliveries'] = $splitDeliveries !== [] ? $splitDeliveries : null;
                }

                $trip->markingSummaries()->updateOrCreate(
                    ['marking_key' => $key],
                    $summaryAttributes,
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

        abort(422, __('delivery_trip.marking_required_for_pdf'));
    }

    public function containers(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('view delivery trip'), 403);

        $search = trim((string) $request->get('q', ''));

        $query = $this->eligibleContainersQuery();

        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(static function (Builder $q) use ($term, $search): void {
                $q->where('partai', 'like', $term)
                    ->orWhere('container_no', 'like', $term)
                    ->orWhere('no_bl', 'like', $term);
                self::applyEtaSearchConstraints($q, $search);
            });
        }

        $rows = $query->limit(40)->get()->map(fn (Container $c) => self::containerSelectPayload($c, (int) $c->eligible_barangs_count));

        return response()->json(['results' => $rows]);
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
            $searchRaw = trim((string) $data['search']);
            $query->where(static function (Builder $q) use ($term, $searchRaw): void {
                $q->where('no_index', 'like', $term)
                    ->orWhere('marking', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhereHas('containers', static function (Builder $c) use ($term, $searchRaw): void {
                        $c->where('partai', 'like', $term)
                            ->orWhere('container_no', 'like', $term);
                        self::applyEtaSearchConstraints($c, $searchRaw);
                    });
            });
        }

        $rows = $query
            ->with(['containers:id,partai,eta,container_no'])
            ->orderBy('no_index')
            ->get()
            ->map(static function (Barang $b): array {
                $container = self::primaryContainerForBarang($b);
                $eta = $container?->eta;

                return [
                    'id' => $b->id,
                    'no_index' => $b->no_index,
                    'marking' => $b->marking,
                    'customer_name' => $b->customer_name,
                    'ctns' => $b->ctns,
                    'total_cbm' => CbmFormat::truncate((float) $b->total_cbm),
                    'status' => $b->status,
                    'status_label' => Barang::shipmentStatusLabel($b->status),
                    'container_partai' => $container?->partai,
                    'container_no' => $container?->container_no,
                    'container_eta' => $eta?->format('Y-m-d'),
                    'container_eta_label' => $eta?->format('d/m/Y'),
                    'container_eta_relative' => self::etaRelativeLabel($eta),
                ];
            });

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

            foreach ($deliveryTrip->barangs as $barang) {
                if ($barang->status !== Barang::STATUS_ETD) {
                    $barang->update(['status' => Barang::STATUS_ETD]);
                }

                $barang->syncEtdTracking($deliveryTrip->destination ?: $barang->destination, null, auth()->id());
            }

            $deliveryTrip->container?->syncEtdTracking($deliveryTrip->destination, null, auth()->id());

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
                if ($barang->status !== Barang::STATUS_ETA) {
                    $barang->update(['status' => Barang::STATUS_ETA]);
                }

                $barang->syncEtaTracking($deliveryTrip->destination ?: $barang->destination, null, auth()->id());
            }

            $deliveryTrip->container?->syncEtaTracking($deliveryTrip->destination, null, auth()->id());

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

        $payload = \App\Support\SuratJalanPdfData::forTrip($deliveryTrip, $markingKey);
        $safeMarking = preg_replace('/[^A-Za-z0-9_-]+/', '-', \App\Support\SuratJalanPdfData::markingLabel($markingKey)) ?: 'marking';

        $pdf = Pdf::loadView('exports.surat-jalan-pdf', $payload)
            ->setPaper('a5', 'landscape');

        return $pdf->download('Surat-Jalan-'.$deliveryTrip->trip_no.'-'.$safeMarking.'.pdf');
    }

    public function blankSuratJalanTemplate(Request $request, Container $container): Response
    {
        abort_unless(auth()->user()?->hasRole('super_admin') === true, 403);

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

    public function rekapanSuratJalanExcel(Container $container): StreamedResponse
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

        $rows = $this->applyRekapanAddressTotals($rows);

        return (new SuratJalanRekapanExport(
            $rows,
            (string) ($container->partai ?? 'container'),
            $container->container_no ? trim((string) $container->container_no) : null,
        ))->download();
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
     * Container yang punya minimal satu barang siap surat jalan.
     *
     * @return Builder<Container>
     */
    private function eligibleContainersQuery(): Builder
    {
        return Container::query()
            ->whereHas('barangs', static function (Builder $q): void {
                $q->eligibleForDeliveryTrip();
                Marking::restrictBarangsQuery($q);
            })
            ->withCount(['barangs as eligible_barangs_count' => static function (Builder $q): void {
                $q->eligibleForDeliveryTrip();
                Marking::restrictBarangsQuery($q);
            }])
            ->orderByRaw('eta IS NULL, eta ASC')
            ->orderByDesc('id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableContainersForSelect(): array
    {
        return $this->eligibleContainersQuery()
            ->limit(50)
            ->get()
            ->map(fn (Container $c) => self::containerSelectPayload($c, (int) $c->eligible_barangs_count))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function containerSelectPayload(Container $container, int $eligibleCount): array
    {
        $eta = $container->eta;

        return [
            'id' => $container->id,
            'text' => self::containerSelectLabel($container, $eligibleCount),
            'partai' => $container->partai,
            'container_no' => $container->container_no,
            'eta' => $eta?->format('Y-m-d'),
            'eta_label' => $eta?->format('d/m/Y'),
            'eta_relative' => self::etaRelativeLabel($eta),
            'eta_status' => self::etaStatusKey($eta),
            'eligible_count' => $eligibleCount,
        ];
    }

    /**
     * @param  Builder<Container>  $query
     */
    private static function applyEtaSearchConstraints(Builder $query, string $search): void
    {
        $term = '%'.$search.'%';
        $query->orWhereRaw("DATE_FORMAT(eta, '%d/%m/%Y') LIKE ?", [$term])
            ->orWhereRaw("DATE_FORMAT(eta, '%d-%m-%Y') LIKE ?", [$term])
            ->orWhereRaw("DATE_FORMAT(eta, '%Y-%m-%d') LIKE ?", [$term]);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search) === 1) {
            $query->orWhereDate('eta', $search);

            return;
        }

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $search, $m) === 1) {
            $parsed = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            $query->orWhereDate('eta', $parsed);

            return;
        }

        $normalized = mb_strtolower(trim($search));
        $today = Carbon::today();

        if (in_array($normalized, ['hari ini', 'hariini', 'today', 'eta hari ini'], true)) {
            $query->orWhereDate('eta', $today);

            return;
        }

        if (in_array($normalized, ['besok', 'tomorrow', 'eta besok'], true)) {
            $query->orWhereDate('eta', $today->copy()->addDay());

            return;
        }

        if (in_array($normalized, ['terlambat', 'overdue', 'telat', 'lewat', 'eta terlambat'], true)) {
            $query->orWhere(static function (Builder $q) use ($today): void {
                $q->whereNotNull('eta')->whereDate('eta', '<', $today);
            });

            return;
        }

        if (in_array($normalized, ['belum eta', 'tanpa eta', 'eta belum', 'no eta'], true)) {
            $query->orWhereNull('eta');
        }
    }

    private static function etaStatusKey(?Carbon $eta): string
    {
        if ($eta === null) {
            return 'unset';
        }

        $days = Carbon::today()->diffInDays($eta->copy()->startOfDay(), false);

        if ($days < 0) {
            return 'overdue';
        }

        if ($days === 0) {
            return 'today';
        }

        return 'upcoming';
    }

    private static function containerSelectLabel(Container $container, int $eligibleCount): string
    {
        $partai = trim((string) ($container->partai ?? ''));
        $label = $partai !== '' ? $partai : ('#'.$container->id);

        if ($container->container_no) {
            $label .= ' · '.$container->container_no;
        }

        if ($container->eta !== null) {
            $label .= ' — ETA '.$container->eta->format('d/m/Y');
            $relative = self::etaRelativeLabel($container->eta);
            if ($relative !== '') {
                $label .= ' ('.$relative.')';
            }
        } else {
            $label .= ' — '.__('delivery_trip.eta_unset');
        }

        $label .= ' · '.$eligibleCount.' '.__('delivery_trip.items_available_short');

        return $label;
    }

    private static function etaRelativeLabel(?Carbon $eta): string
    {
        if ($eta === null) {
            return '';
        }

        $days = Carbon::today()->diffInDays($eta->copy()->startOfDay(), false);

        if ($days === 0) {
            return __('delivery_trip.eta_today');
        }

        if ($days > 0) {
            return __('delivery_trip.eta_in_days', ['days' => $days]);
        }

        return __('delivery_trip.eta_overdue_days', ['days' => abs($days)]);
    }

    private static function primaryContainerForBarang(Barang $barang): ?Container
    {
        $containers = $barang->relationLoaded('containers')
            ? $barang->containers
            : collect();

        if ($containers->isEmpty()) {
            return null;
        }

        return $containers->sortByDesc(static fn (Container $c) => $c->pivot?->loaded_at ?? '')->first()
            ?? $containers->first();
    }

    private static function displayContainerForTrip(DeliveryTrip $trip): string
    {
        $container = $trip->container;

        if ($container === null) {
            $firstBarang = $trip->relationLoaded('barangs') ? $trip->barangs->first() : null;
            $container = $firstBarang instanceof Barang ? self::primaryContainerForBarang($firstBarang) : null;
        }

        if ($container === null) {
            return '—';
        }

        $partai = trim((string) ($container->partai ?? ''));
        $containerNo = trim((string) ($container->container_no ?? ''));

        if ($partai !== '' && $containerNo !== '') {
            return $partai.' · '.$containerNo;
        }

        return $partai !== '' ? $partai : ($containerNo !== '' ? $containerNo : '—');
    }

    /**
     * Semua barang di container (untuk daftar marking surat jalan).
     *
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
            ->get([
            'barangs.id',
            'barangs.no_index',
            'barangs.marking',
            'barangs.customer_name',
            'barangs.user_id',
            'barangs.destination',
            'barangs.ctns',
            'barangs.total_cbm',
            'barangs.total_gross_weight',
            'barangs.status',
            'barangs.tanggal_masuk',
            'barangs.notes',
            'barangs.form',
            'barangs.material_desc',
            'barangs.supplier_chinese',
        ]);
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
     * @return Collection<int, Barang>
     */
    private function trackingTripBarangs(DeliveryTrip $trip): Collection
    {
        $trip->loadMissing([
            'barangs' => static fn ($q) => $q
                ->with([
                    'customer:id,name',
                    'containers:id,partai,container_no',
                    'products' => static fn ($pq) => $pq->orderBy('sort_order'),
                ])
                ->orderBy('delivery_trip_barang.sort_order'),
        ]);

        /** @var Collection<int, Barang> $barangs */
        $barangs = $trip->barangs;

        return $barangs;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function trackingTripAttributes(array $data, Container $container): array
    {
        return [
            'truck_plate' => trim((string) $data['truck_plate']),
            'driver_name' => trim((string) $data['driver_name']),
            'driver_phone' => $this->nullableTrim($data['driver_phone'] ?? null),
            'destination' => $this->nullableTrim($data['destination'] ?? null),
            'notes' => $this->nullableTrim($data['trip_notes'] ?? null),
            'print_marking_code' => $this->nullableTrim($data['marking_code'] ?? null),
            'print_customer_name' => $this->nullableTrim($data['customer'] ?? null),
            'print_customer_phone' => $this->nullableTrim($data['telp'] ?? null),
            'print_customer_address' => $this->nullableTrim($data['alamat'] ?? null),
            'print_loading_date' => $data['loading_date'] ?? null,
            'print_item_name' => $this->nullableTrim($data['nama_barang'] ?? null),
            'print_total_coly' => $this->nullableTrim($data['total_coly'] ?? null),
            'print_partai' => $this->nullableTrim($data['partai'] ?? null) ?? trim((string) ($container->partai ?? '')),
            'print_notes' => $this->nullableTrim($data['print_notes'] ?? null),
            'receiver_name' => $this->nullableTrim($data['receiver_name'] ?? null),
            'receiver_phone' => $this->nullableTrim($data['receiver_phone'] ?? null),
            'received_date' => $data['received_date'] ?? null,
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, mixed>
     */
    private function trackingTripPayload(Container $container, ?DeliveryTrip $trip, Collection $barangs): array
    {
        $canPdf = ($trip !== null)
            && (auth()->user()?->can('export delivery trip pdf') ?? false)
            && $trip->canGeneratePdf();

        $markings = \App\Support\SuratJalanPdfData::markingGroupsForApi(
            $trip,
            $container,
            $barangs,
            $canPdf,
        );

        return [
            'exists' => $trip !== null,
            'view_url' => route('tracking.staff.surat-jalan', $container),
            'blank_template_url' => auth()->user()?->hasRole('super_admin') === true
                ? route('tracking.staff.surat-jalan.template', $container)
                : null,
            'rekapan_url' => route('tracking.staff.surat-jalan.rekapan', $container),
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
                        'total_cbm' => $barang->displayTotalCbm(),
                    ];
                })->values()->all(),
                'can_pdf' => $canPdf && count($markings) > 0,
            ],
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private function ensureTrackingTrip(Container $container, Collection $barangs): DeliveryTrip
    {
        $trip = $container->latestDeliveryTrip()->first();
        if ($trip !== null) {
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

        $markingBarangs = $barangs->filter(
            static fn (Barang $barang): bool => \App\Support\SuratJalanPdfData::markingKey($barang) === $markingKey,
        );
        $markingRow = $this->ensureMarkingSummaryForMedia($trip, $container, $markingKey, $markingBarangs);
        abort_if($markingRow === null, 422, __('delivery_trip.no_items'));

        $phase = (string) $data['media_phase'];
        if ($phase === 'departure') {
            abort_unless($markingRow->canConfirmDeparture(), 422, __('delivery_trip.cannot_confirm_departure'));
        } else {
            abort_unless($markingRow->canConfirmArrival(), 422, __('delivery_trip.cannot_confirm_arrival'));
        }

        DB::transaction(function () use ($request, $trip, $markingKey, $phase, $markingBarangs, $markingRow): void {
            try {
                DeliveryTripMediaStorage::storePhase(
                    $trip,
                    $phase === 'departure' ? DeliveryTripMedia::PHASE_DEPARTURE : DeliveryTripMedia::PHASE_ARRIVAL,
                    $request->file('photos', []),
                    $request->file('videos', []),
                    auth()->id(),
                    $markingKey,
                );
            } catch (\InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }

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

    /**
     * @param  Collection<int, Barang>  $markingBarangs
     */
    private function ensureMarkingSummaryForMedia(
        DeliveryTrip $trip,
        Container $container,
        string $markingKey,
        Collection $markingBarangs,
    ): ?DeliveryTripMarkingSummary {
        $existing = \App\Support\SuratJalanPdfData::markingSummaryRowForKey($trip, $markingKey);
        if ($existing !== null) {
            return $existing;
        }

        $summary = \App\Support\SuratJalanPdfData::summaryForMarking($trip, $container, $markingBarangs, $markingKey);
        $truckPlate = $this->nullableOptionalTruckTrim($summary['truck_plate'] ?? null);
        $driverName = $this->nullableOptionalTruckTrim($summary['driver_name'] ?? null);

        return $trip->markingSummaries()->create([
            'marking_key' => $markingKey,
            'truck_plate' => $truckPlate,
            'driver_name' => $driverName,
            'driver_phone' => $this->nullableOptionalTruckTrim($summary['driver_phone'] ?? null),
            'destination' => $this->nullableOptionalTruckTrim($summary['destination'] ?? null),
            'trip_notes' => $this->nullableTrim($summary['trip_notes'] ?? null),
            'status' => DeliveryTrip::STATUS_READY,
            'marking_code' => $this->nullableTrim($summary['marking_code'] ?? null) ?: $markingKey,
            'customer_name' => $this->nullableTrim($summary['customer'] ?? null),
            'customer_phone' => $this->nullableTrim($summary['telp'] ?? null),
            'customer_address' => $this->nullableTrim($summary['alamat'] ?? null),
            'loading_date' => $this->nullableTrim($summary['loading_date'] ?? null),
            'item_name' => $this->nullableTrim($summary['nama_barang'] ?? null),
            'total_coly' => $this->nullableTrim($summary['total_coly'] ?? null),
            'partai' => $this->nullableTrim($summary['partai'] ?? null),
            'notes' => $this->nullableTrim($summary['notes'] ?? null),
            'receiver_name' => $this->nullableTrim($summary['nama_penerima'] ?? null),
            'receiver_phone' => $this->nullableTrim($summary['no_telp_penerima'] ?? null),
            'received_date' => $this->nullableTrim($summary['tanggal_terima'] ?? null),
        ]);
    }

    private function trackingMarkingJsonResponse(
        Container $container,
        DeliveryTrip $trip,
        string $message,
    ): JsonResponse {
        $container->loadMissing('marking');
        $barangs = $this->trackingContainerBarangs($container);
        $this->syncTripBarangsFromContainer($trip, $barangs);
        $trip->unsetRelation('markingSummaries');
        $this->loadTrackingTrip($trip);

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

    private function nullableOptionalTruckTrim(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return ($normalized !== '' && $normalized !== '-' && $normalized !== '.') ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{truck_plate: ?string, driver_name: ?string, driver_phone: ?string}
     */
    private function markingSummaryTruckFields(array $data, ?DeliveryTripMarkingSummary $existing): array
    {
        $truckPlate = $this->nullableOptionalTruckTrim($data['truck_plate'] ?? null);
        $driverName = $this->nullableOptionalTruckTrim($data['driver_name'] ?? null);
        $driverPhone = $this->nullableOptionalTruckTrim($data['driver_phone'] ?? null);

        if ($existing !== null) {
            if ($truckPlate === null) {
                $truckPlate = $this->nullableOptionalTruckTrim($existing->truck_plate);
            }
            if ($driverName === null) {
                $driverName = $this->nullableOptionalTruckTrim($existing->driver_name);
            }
            if ($driverPhone === null) {
                $driverPhone = $this->nullableOptionalTruckTrim($existing->driver_phone);
            }
        }

        return [
            'truck_plate' => $truckPlate,
            'driver_name' => $driverName,
            'driver_phone' => $driverPhone,
        ];
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
            foreach ($splitGroups as $splitIndex => $split) {
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
                        'coly_raw' => $this->rekapanColyRaw($barang),
                        'cbm' => $this->formatRekapanCbm((float) ($barang->total_cbm ?? 0)),
                        'kg' => $this->formatRekapanKg($this->rekapanKgRaw($barang)),
                        'cbm_raw' => (float) ($barang->total_cbm ?? 0),
                        'kg_raw' => $this->rekapanKgRaw($barang),
                        'alamat' => $splitAddress !== '' ? $splitAddress : trim((string) ($summary['alamat'] ?? '')),
                        'notes' => $splitNotes !== '' ? $splitNotes : trim((string) ($barang->notes ?? '')),
                        'merge_group_key' => $this->rekapanSplitMergeGroupKey($markingKey, $splitIndex),
                    ];
                }
            }
        } elseif ($splits !== []) {
            foreach ($splits as $splitIndex => $split) {
                $splitAddress = trim((string) ($split['address'] ?? ''));
                $items[] = [
                    'no_urut' => '',
                    'item' => trim((string) ($summary['nama_barang'] ?? '-')),
                    'coly' => (string) max(0, (int) ($split['coly'] ?? 0)),
                    'coly_raw' => max(0, (int) ($split['coly'] ?? 0)),
                    'cbm' => '',
                    'kg' => '',
                    'cbm_raw' => 0.0,
                    'kg_raw' => 0.0,
                    'alamat' => $splitAddress !== '' ? $splitAddress : trim((string) ($summary['alamat'] ?? '')),
                    'notes' => trim((string) ($summary['notes'] ?? '')),
                    'merge_group_key' => $this->rekapanSplitMergeGroupKey($markingKey, $splitIndex),
                ];
            }
        }

        if ($items === []) {
            foreach ($markingBarangs as $barang) {
                if (! $barang instanceof Barang) {
                    continue;
                }
                $alamat = trim((string) ($summary['alamat'] ?? ''));
                $items[] = [
                    'no_urut' => $this->rekapanNoUrut($barang),
                    'item' => $this->rekapanItem($barang),
                    'coly' => $this->rekapanColy($barang),
                    'coly_raw' => $this->rekapanColyRaw($barang),
                    'cbm' => $this->formatRekapanCbm((float) ($barang->total_cbm ?? 0)),
                    'kg' => $this->formatRekapanKg($this->rekapanKgRaw($barang)),
                    'cbm_raw' => (float) ($barang->total_cbm ?? 0),
                    'kg_raw' => $this->rekapanKgRaw($barang),
                    'alamat' => $alamat,
                    'notes' => trim((string) ($summary['notes'] ?? $barang->notes ?? '')),
                    'merge_group_key' => $this->rekapanAddressMergeGroupKey($markingKey, $alamat, (int) $barang->id),
                ];
            }
        }

        if ($items === []) {
            $alamat = trim((string) ($summary['alamat'] ?? ''));
            $items[] = [
                'no_urut' => '',
                'item' => trim((string) ($summary['nama_barang'] ?? '-')),
                'coly' => trim((string) ($summary['total_coly'] ?? '')),
                'coly_raw' => DeliverySplitDeliveries::parseColy($summary['total_coly'] ?? ''),
                'cbm' => '',
                'kg' => '',
                'cbm_raw' => (float) ($summary['total_cbm'] ?? 0),
                'kg_raw' => (float) ($summary['total_gross_weight'] ?? $summary['total_kg'] ?? 0),
                'alamat' => $alamat,
                'notes' => trim((string) ($summary['notes'] ?? '')),
                'merge_group_key' => $this->rekapanAddressMergeGroupKey($markingKey, $alamat, 0),
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
                'cbm_raw' => (float) ($item['cbm_raw'] ?? 0),
                'kg_raw' => (float) ($item['kg_raw'] ?? 0),
                'coly_raw' => (int) ($item['coly_raw'] ?? 0),
                'alamat' => $this->normalizeRekapanAlamat((string) ($item['alamat'] ?? '')),
                'notes' => (string) ($item['notes'] ?? ''),
                'merge_group_key' => (string) ($item['merge_group_key'] ?? ''),
                'truck_plate' => (string) ($this->nullableOptionalTruckTrim($summary['truck_plate'] ?? null) ?? ''),
                'driver_name' => (string) ($this->nullableOptionalTruckTrim($summary['driver_name'] ?? null) ?? ''),
                'driver_phone' => (string) ($this->nullableOptionalTruckTrim($summary['driver_phone'] ?? null) ?? ''),
                'telp' => trim((string) ($summary['telp'] ?? '')),
            ];
        }

        return $groupRows;
    }

    /**
     * Jumlahkan total CBM/KG/coly per grup merge (marking + alamat sama, atau per split admin).
     *
     * @param  list<array<string, string|int|bool|float>>  $rows
     * @return list<array<string, string|int|bool|float>>
     */
    private function applyRekapanAddressTotals(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        /** @var array<string, array{cbm: float, kg: float, coly: int}> $totalsByGroup */
        $totalsByGroup = [];

        foreach ($rows as $row) {
            $groupKey = $this->rekapanRowMergeGroupKey($row);
            if ($groupKey === null) {
                continue;
            }

            $totalsByGroup[$groupKey] ??= ['cbm' => 0.0, 'kg' => 0.0, 'coly' => 0];
            $totalsByGroup[$groupKey]['cbm'] += (float) ($row['cbm_raw'] ?? 0);
            $totalsByGroup[$groupKey]['kg'] += (float) ($row['kg_raw'] ?? 0);
            $totalsByGroup[$groupKey]['coly'] += (int) ($row['coly_raw'] ?? 0);
        }

        foreach ($rows as $index => $row) {
            $groupKey = $this->rekapanRowMergeGroupKey($row);

            if ($groupKey === null) {
                $rows[$index]['total_cbm'] = $this->formatRekapanCbm((float) ($row['cbm_raw'] ?? 0));
                $rows[$index]['total_kg'] = $this->formatRekapanKg((float) ($row['kg_raw'] ?? 0));
                $rows[$index]['total_coly'] = $this->formatRekapanColy((int) ($row['coly_raw'] ?? 0));

                continue;
            }

            $rows[$index]['total_cbm'] = $this->formatRekapanCbm($totalsByGroup[$groupKey]['cbm']);
            $rows[$index]['total_kg'] = $this->formatRekapanKg($totalsByGroup[$groupKey]['kg']);
            $rows[$index]['total_coly'] = $this->formatRekapanColy($totalsByGroup[$groupKey]['coly']);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rekapanRowMergeGroupKey(array $row): ?string
    {
        $explicit = trim((string) ($row['merge_group_key'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $addressKey = $this->rekapanAddressGroupKey((string) ($row['alamat'] ?? ''));
        if ($addressKey === null) {
            return null;
        }

        return $this->rekapanAddressMergeGroupKey(
            (string) ($row['marking'] ?? ''),
            (string) ($row['alamat'] ?? ''),
        );
    }

    private function rekapanSplitMergeGroupKey(string $markingKey, int $splitIndex): string
    {
        return mb_strtolower(trim($markingKey)).'|split:'.$splitIndex;
    }

    private function rekapanAddressMergeGroupKey(string $markingKey, string $address, ?int $uniqueRowId = null): string
    {
        $addressKey = $this->rekapanAddressGroupKey($address);
        if ($addressKey === null) {
            return mb_strtolower(trim($markingKey)).'|row:'.($uniqueRowId ?? 0);
        }

        return mb_strtolower(trim($markingKey)).'|addr:'.$addressKey;
    }

    /**
     * Kunci pengelompokan alamat: trim + rapikan spasi + case-insensitive.
     * Null = alamat kosong, tidak digabung dengan baris lain.
     */
    private function rekapanAddressGroupKey(string $address): ?string
    {
        $normalized = $this->normalizeRekapanAlamat($address);
        if ($normalized === '') {
            return null;
        }

        return mb_strtolower($normalized);
    }

    private function normalizeRekapanAlamat(string $address): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($address)) ?? '';

        return ($normalized === '-' || $normalized === '.') ? '' : $normalized;
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

    private function rekapanColyRaw(Barang $barang): int
    {
        return max(0, (int) ($barang->ctns ?? 0));
    }

    private function rekapanKgRaw(Barang $barang): float
    {
        $total = (float) ($barang->total_gross_weight ?? 0);
        if ($total > 0) {
            return $total;
        }

        if (! $barang->relationLoaded('products')) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($barang->products as $product) {
            if ($product->parent_id !== null) {
                continue;
            }

            $sum += (float) ($product->gross_weight_kg ?? 0) * max(1, (int) ($product->ctns ?? 0));
        }

        return $sum > 0 ? $sum : 0.0;
    }

    private function formatRekapanColy(int $value): string
    {
        return $value > 0 ? (string) $value : '';
    }

    private function formatRekapanCbm(float $value): string
    {
        if ($value <= 0) {
            return '';
        }

        return CbmFormat::format($value, '.', '');
    }

    private function formatRekapanKg(float $value): string
    {
        if ($value <= 0) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
