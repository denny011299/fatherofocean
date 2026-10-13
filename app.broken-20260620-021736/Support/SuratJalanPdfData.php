<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Container;
use App\Models\DeliveryTrip;
use App\Models\DeliveryTripMarkingSummary;
use App\Models\Marking;
use Illuminate\Support\Collection;

final class SuratJalanPdfData
{
    private const ROWS_PER_PAGE = 14;

    /** Empty item rows on blank template — must fit one A5 landscape page with header/footer. */
    private const BLANK_TEMPLATE_ROWS = 9;

    /**
     * @return array{
     *     pages: list<array{
     *         header: array<string, string>,
     *         rows: list<array<string, string>>,
     *         totals: ?array<string, string>,
     *         show_footer: bool,
     *     }>,
     *     penerima: string,
     *     trip_no: string,
     *     printDate: string,
     * }
     */
    public const NO_MARKING_KEY = '__no_marking__';

    public static function forTrip(DeliveryTrip $trip, ?string $markingKey = null): array
    {
        self::loadTripRelations($trip);

        $allBarangs = $trip->barangs instanceof Collection ? $trip->barangs : collect();
        $groups = self::buildMarkingGroups($allBarangs);
        if ($markingKey === null && $groups !== []) {
            $markingKey = $groups[0]['key'];
        }

        $groupIndex = self::findMarkingGroupIndex($groups, $markingKey);
        if ($groupIndex === null) {
            abort(404, __('delivery_trip.marking_not_found'));
        }

        $group = $groups[$groupIndex];
        $barangs = $group['barangs'];

        return self::buildPdfPayload(
            $trip,
            $barangs,
            $group['key'],
            $group['code'],
            $groupIndex + 1,
            count($groups),
        );
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return list<array{
     *     key: string,
     *     code: string,
     *     barangs: Collection<int, Barang>,
     *     shipments_count: int,
     *     total_ctns: int,
     *     total_cbm: float,
     *     customer: string,
     *     telp: string,
     *     alamat: string,
     *     no_indexes: string,
     * }>
     */
    public static function buildMarkingGroups(Collection $barangs): array
    {
        if ($barangs->isEmpty()) {
            return [];
        }

        $grouped = $barangs->groupBy(static fn (Barang $barang): string => self::markingKey($barang));

        $groups = $grouped->map(static function (Collection $items, string $key): array {
            $items = $items->values();
            $summary = self::buildSummaryFields(null, null, $items);

            return [
                'key' => $key,
                'code' => self::markingCodeLabelForBarangs($items, $key),
                'barangs' => $items,
                'shipments_count' => $items->count(),
                'total_ctns' => (int) $items->sum('ctns'),
                'total_cbm' => round((float) $items->sum('total_cbm'), 4),
                'customer' => $summary['customer'],
                'telp' => $summary['telp'],
                'alamat' => $summary['alamat'],
                'no_indexes' => $items->pluck('no_index')->filter()->implode(', '),
            ];
        })->values()->all();

        usort($groups, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $groups;
    }

    /**
     * @param  list<array{key: string, code: string, barangs: Collection<int, Barang>, shipments_count: int, total_ctns: int, total_cbm: float, customer: string, telp: string, alamat: string, no_indexes: string}>  $groups
     * @return list<array<string, mixed>>
     */
    public static function markingGroupsForApi(
        ?DeliveryTrip $trip,
        ?Container $container,
        Collection $barangs,
        bool $canPdf,
    ): array {
        $groups = self::buildMarkingGroups($barangs);
        $tripId = $trip?->id;
        if ($trip !== null && ! $trip->relationLoaded('markingSummaries')) {
            $trip->loadMissing('markingSummaries');
        }
        if ($trip !== null && ! $trip->relationLoaded('media')) {
            $trip->loadMissing('media.uploader:id,name');
        }

        $result = [];
        foreach ($groups as $index => $group) {
            $summary = self::summaryForMarking($trip, $container, $group['barangs'], $group['key']);
            $markingRow = self::markingSummaryRow($trip, $group['key']);
            $hasSavedSummary = $markingRow !== null;
            $truckSaved = $markingRow?->truckSaved() ?? false;
            $canDownload = $canPdf && $tripId !== null && $truckSaved;
            $pdfUrl = $canDownload
                ? route('delivery-trip.pdf', ['deliveryTrip' => $tripId, 'marking' => $group['key']])
                : null;
            $media = self::markingMediaPayload($trip, $group['key']);
            $canConfirm = auth()->user()?->can('confirm delivery trip') ?? false;

            $result[] = [
                'index' => $index + 1,
                'total' => count($groups),
                'key' => $group['key'],
                'code' => $group['code'],
                'has_saved_summary' => $hasSavedSummary,
                'customer' => $hasSavedSummary ? $summary['customer'] : ($summary['customer'] ?: $group['customer']),
                'telp' => $hasSavedSummary ? $summary['telp'] : ($summary['telp'] ?: $group['telp']),
                'alamat' => $hasSavedSummary ? $summary['alamat'] : ($summary['alamat'] ?: $group['alamat']),
                'truck_plate' => trim((string) ($summary['truck_plate'] ?? '')),
                'driver_name' => trim((string) ($summary['driver_name'] ?? '')),
                'driver_phone' => trim((string) ($summary['driver_phone'] ?? '')),
                'destination' => trim((string) ($summary['destination'] ?? '')),
                'trip_notes' => trim((string) ($summary['trip_notes'] ?? '')),
                'status' => $markingRow?->status ?? DeliveryTrip::STATUS_READY,
                'status_label' => DeliveryTrip::statusLabel($markingRow?->status ?? DeliveryTrip::STATUS_READY),
                'status_badge' => $markingRow?->statusBadgeClass() ?? 'badge-status-pending',
                'truck_saved' => $truckSaved,
                'shipments_count' => $group['shipments_count'],
                'total_ctns' => $group['total_ctns'],
                'total_cbm' => $group['total_cbm'],
                'no_indexes' => $group['no_indexes'],
                'shipments' => $group['barangs']->map(static function (Barang $barang): array {
                    return [
                        'id' => $barang->id,
                        'no_index' => $barang->no_index,
                        'marking' => $barang->marking,
                        'customer_name' => $barang->customer_name ?: ($barang->customer?->name ?? ''),
                        'ctns' => (int) $barang->ctns,
                        'total_cbm' => round((float) $barang->total_cbm, 4),
                    ];
                })->values()->all(),
                'pdf_url' => $pdfUrl,
                'sheet_label' => __('delivery_trip.sheet_index_label', [
                    'index' => $index + 1,
                    'total' => count($groups),
                    'marking' => $group['code'],
                ]),
                'summary' => $summary,
                'can_confirm_departure' => $canConfirm && ($markingRow?->canConfirmDeparture() ?? false),
                'can_confirm_arrival' => $canConfirm && ($markingRow?->canConfirmArrival() ?? false),
                'departure_photos' => $media['departure_photos'],
                'departure_videos' => $media['departure_videos'],
                'arrival_photos' => $media['arrival_photos'],
                'arrival_videos' => $media['arrival_videos'],
            ];
        }

        return $result;
    }

    public static function markingKey(Barang $barang): string
    {
        $raw = trim((string) ($barang->marking ?? ''));
        if ($raw === '') {
            return self::NO_MARKING_KEY;
        }

        return Marking::resolveCodeFromBarangMarkingOnly($raw);
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function markingCodeLabelForBarangs(Collection $barangs, string $fallbackKey): string
    {
        $labels = $barangs
            ->map(static function (Barang $barang): string {
                $raw = trim((string) ($barang->marking ?? ''));

                return $raw !== '' ? Marking::resolveCodeFromBarangMarkingOnly($raw) : '';
            })
            ->filter(static fn (string $label): bool => $label !== '')
            ->unique()
            ->values();

        if ($labels->isEmpty()) {
            return self::markingLabel($fallbackKey);
        }

        return $labels->count() === 1
            ? (string) $labels->first()
            : $labels->implode(', ');
    }

    public static function markingLabel(string $key): string
    {
        if ($key === self::NO_MARKING_KEY) {
            return (string) __('container.no_marking');
        }

        return $key;
    }

    public static function markingSummaryRowForKey(?DeliveryTrip $trip, string $markingKey): ?DeliveryTripMarkingSummary
    {
        return self::markingSummaryRow($trip, $markingKey);
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function buildPdfPayload(
        DeliveryTrip $trip,
        Collection $barangs,
        string $markingKey,
        string $markingCode,
        int $markingIndex,
        int $markingTotal,
    ): array {
        $summary = self::summaryForMarking($trip, $trip->container, $barangs, $markingKey);
        $header = self::buildHeader($trip, $summary, $barangs);
        $rows = self::buildRows($trip, $barangs, $summary);
        $totals = self::buildTotals($rows);
        $penerima = trim((string) ($summary['nama_penerima'] ?? ''));

        $rowChunks = $rows !== []
            ? array_chunk($rows, self::ROWS_PER_PAGE)
            : [[]];
        $lastIndex = count($rowChunks) - 1;
        $pages = [];

        foreach ($rowChunks as $index => $chunk) {
            $pages[] = [
                'header' => $header,
                'rows' => $chunk,
                'totals' => $index === $lastIndex ? $totals : null,
                'show_footer' => $index === $lastIndex,
            ];
        }

        return [
            'pages' => $pages,
            'penerima' => $penerima,
            'trip_no' => trim((string) ($trip->trip_no ?? '')),
            'printDate' => now()->format('d/m/Y'),
            'marking_code' => $markingCode,
            'marking_index' => $markingIndex,
            'marking_total' => $markingTotal,
            'sheet_title' => __('delivery_trip.sheet_index_label', [
                'index' => $markingIndex,
                'total' => $markingTotal,
                'marking' => $markingCode,
            ]),
        ];
    }

    /**
     * Blank surat jalan: header/meta prefilled when trip/marking context exists; item rows empty for manual fill.
     *
     * @return array{
     *     pages: list<array{
     *         header: array<string, string>,
     *         rows: list<array<string, string>>,
     *         totals: null,
     *         show_footer: bool,
     *         blank_row_count: int,
     *     }>,
     *     penerima: string,
     *     trip_no: string,
     *     printDate: string,
     *     is_blank_template: bool,
     *     sheet_title: ?string,
     * }
     */
    public static function blankTemplate(
        ?DeliveryTrip $trip = null,
        ?Container $container = null,
        ?string $markingKey = null,
        ?Collection $barangs = null,
    ): array {
        $header = [
            'no_pol' => '',
            'sopir' => '',
            'mark' => '',
            'alamat' => '',
        ];
        $penerima = '';
        $tripNo = '';
        $barangs ??= collect();

        if ($trip !== null) {
            self::loadTripRelations($trip);
            $allBarangs = $trip->barangs instanceof Collection ? $trip->barangs : collect();
            $barangs = collect();
            $resolvedKey = '__all__';

            if ($markingKey !== null && $markingKey !== '' && $markingKey !== '__all__') {
                $resolvedKey = $markingKey;
                foreach (self::buildMarkingGroups($allBarangs) as $group) {
                    if ($group['key'] === $markingKey) {
                        $barangs = $group['barangs'];
                        break;
                    }
                }
            }

            $summary = self::summaryForMarking($trip, $container ?? $trip->container, $barangs, $resolvedKey);
            $header = self::buildHeader($trip, $summary, $barangs);
            $penerima = trim((string) ($summary['nama_penerima'] ?? ''));
            $tripNo = trim((string) ($trip->trip_no ?? ''));
        } elseif ($barangs->isNotEmpty()) {
            $scoped = $barangs;
            $resolvedKey = '__all__';

            if ($markingKey !== null && $markingKey !== '' && $markingKey !== '__all__') {
                $resolvedKey = $markingKey;
                foreach (self::buildMarkingGroups($barangs) as $group) {
                    if ($group['key'] === $markingKey) {
                        $scoped = $group['barangs'];
                        break;
                    }
                }
            }

            $summary = self::buildSummaryFields(null, $container, $scoped);
            $header = [
                'no_pol' => '',
                'sopir' => '',
                'mark' => trim((string) ($summary['marking_code'] ?? '')),
                'alamat' => trim((string) ($summary['alamat'] ?? '')),
            ];
            $penerima = trim((string) ($summary['nama_penerima'] ?? ''));
        }

        return [
            'pages' => [[
                'header' => $header,
                'rows' => [],
                'totals' => null,
                'show_footer' => true,
                'blank_row_count' => self::BLANK_TEMPLATE_ROWS,
            ]],
            'penerima' => $penerima,
            'trip_no' => $tripNo,
            'printDate' => now()->format('d/m/Y'),
            'sheet_title' => null,
            'is_blank_template' => true,
        ];
    }

    /**
     * @param  list<array{key: string}>  $groups
     */
    private static function findMarkingGroupIndex(array $groups, ?string $markingKey): ?int
    {
        if ($markingKey === null || $groups === []) {
            return null;
        }

        foreach ($groups as $index => $group) {
            if ($group['key'] === $markingKey) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public static function summaryFormDefaults(DeliveryTrip $trip): array
    {
        self::loadTripRelations($trip);

        return self::summaryForMarking(
            $trip,
            $trip->container,
            $trip->barangs instanceof Collection ? $trip->barangs : collect(),
            '__all__',
        );
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, mixed>
     */
    public static function summaryForMarking(
        ?DeliveryTrip $trip,
        ?Container $container,
        Collection $barangs,
        string $markingKey,
    ): array {
        $markingRow = self::markingSummaryRowForKey($trip, $markingKey);
        $savedOnly = $markingRow !== null;
        $override = $savedOnly ? self::summaryOverrideFromRow($markingRow) : [];

        $fields = self::buildSummaryFields($trip, $container, $barangs, $override, $savedOnly);

        if ($markingRow !== null) {
            $fields['truck_plate'] = trim((string) ($markingRow->truck_plate ?? ''));
            $fields['driver_name'] = trim((string) ($markingRow->driver_name ?? ''));
            $fields['driver_phone'] = trim((string) ($markingRow->driver_phone ?? ''));
            $fields['destination'] = trim((string) ($markingRow->destination ?? ''));
            $fields['trip_notes'] = trim((string) ($markingRow->trip_notes ?? ''));
            $fields['split_deliveries'] = DeliverySplitDeliveries::fromSummaryRow($markingRow);
        } else {
            $fields['truck_plate'] = trim((string) ($trip?->truck_plate ?? ''));
            $fields['driver_name'] = trim((string) ($trip?->driver_name ?? ''));
            $fields['driver_phone'] = trim((string) ($trip?->driver_phone ?? ''));
            $fields['destination'] = trim((string) ($trip?->destination ?? ''));
            $fields['trip_notes'] = trim((string) ($trip?->notes ?? ''));
            $fields['split_deliveries'] = [];
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private static function summaryOverrideFromRow(DeliveryTripMarkingSummary $row): array
    {
        return [
            'marking_code' => trim((string) ($row->marking_code ?? '')),
            'customer' => trim((string) ($row->customer_name ?? '')),
            'telp' => trim((string) ($row->customer_phone ?? '')),
            'alamat' => trim((string) ($row->customer_address ?? '')),
            'loading_date' => $row->loading_date?->format('Y-m-d') ?? '',
            'nama_barang' => trim((string) ($row->item_name ?? '')),
            'total_coly' => trim((string) ($row->total_coly ?? '')),
            'partai' => trim((string) ($row->partai ?? '')),
            'notes' => trim((string) ($row->notes ?? '')),
            'nama_penerima' => trim((string) ($row->receiver_name ?? '')),
            'no_telp_penerima' => trim((string) ($row->receiver_phone ?? '')),
            'tanggal_terima' => $row->received_date?->format('Y-m-d') ?? '',
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, string>
     */
    public static function draftSummary(Container $container, Collection $barangs): array
    {
        return self::buildSummaryFields(null, $container, $barangs);
    }

    /**
     * @param  array<string, string>  $summary
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, string>
     */
    private static function buildHeader(DeliveryTrip $trip, array $summary, Collection $barangs): array
    {
        $sby = self::firstNonEmpty(
            trim((string) ($summary['destination'] ?? '')),
            trim((string) ($trip->destination ?? '')),
            self::joinUnique($barangs->pluck('destination')),
            trim((string) ($trip->container?->branch ?? '')),
            trim((string) ($summary['partai'] ?? '')),
        );

        return [
            'no_pol' => self::firstNonEmpty(
                trim((string) ($summary['truck_plate'] ?? '')),
                trim((string) ($trip->truck_plate ?? '')),
            ),
            'sopir' => self::firstNonEmpty(
                trim((string) ($summary['driver_name'] ?? '')),
                trim((string) ($trip->driver_name ?? '')),
            ),
            'sby' => $sby,
            'mark' => trim((string) ($summary['marking_code'] ?? '')),
            'alamat' => trim((string) ($summary['alamat'] ?? '')),
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @param  array<string, string>  $summary
     * @return list<array<string, string>>
     */
    private static function buildRows(DeliveryTrip $trip, Collection $barangs, array $summary): array
    {
        if ($barangs->isNotEmpty()) {
            return $barangs->map(static function (Barang $barang) use ($trip, $summary): array {
                return [
                    'index' => trim((string) ($barang->no_index ?? '')),
                    'nama_barang' => self::namaBarang($barang),
                    'colli' => (string) (int) ($barang->ctns ?? 0),
                    'cbm' => self::formatDecimal((float) $barang->total_cbm, 3),
                    'gw' => self::formatDecimal((float) $barang->total_gross_weight, 2),
                    'keterangan' => trim((string) ($barang->keterangan ?? '')),
                ];
            })->values()->all();
        }

        $namaBarang = trim((string) ($summary['nama_barang'] ?? ''));
        if ($namaBarang === '') {
            return [];
        }

        return [[
            'index' => '-',
            'nama_barang' => $namaBarang,
            'colli' => trim((string) ($summary['total_coly'] ?? '')),
            'cbm' => '',
            'gw' => '',
            'keterangan' => trim((string) ($summary['notes'] ?? '')),
        ]];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, string>
     */
    private static function buildTotals(array $rows): array
    {
        $totalColli = 0;
        $totalCbm = 0.0;
        $totalGw = 0.0;

        foreach ($rows as $row) {
            $totalColli += (int) ($row['colli'] ?? 0);
            $totalCbm += self::parseDecimal((string) ($row['cbm'] ?? '0'));
            $totalGw += self::parseDecimal((string) ($row['gw'] ?? '0'));
        }

        return [
            'colli' => (string) $totalColli,
            'cbm' => self::formatDecimal($totalCbm, 3),
            'gw' => self::formatDecimal($totalGw, 2),
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @param  array<string, string>  $override
     * @return array<string, string>
     */
    private static function buildSummaryFields(
        ?DeliveryTrip $trip,
        ?Container $container,
        Collection $barangs,
        array $override = [],
        bool $savedOnly = false,
    ): array {
        /** @var Barang|null $firstBarang */
        $firstBarang = $barangs->first();
        $marking = self::resolveMarkingForBarangs($barangs);
        if ($marking === null && $container?->relationLoaded('marking')) {
            $marking = $container->marking;
        }

        $address = trim(implode(' ', array_filter([
            trim((string) ($marking?->address_1 ?? '')),
            trim((string) ($marking?->address_2 ?? '')),
        ])));
        $phone = trim((string) ($marking?->phone_hp ?? $marking?->phone_02 ?? ''));
        $containerPartai = trim((string) ($container?->partai ?? ''));
        $loadingDate = $trip?->departed_at?->format('Y-m-d')
            ?? $container?->load_date?->format('Y-m-d')
            ?? ($firstBarang?->tanggal_masuk?->format('Y-m-d') ?? '');

        return [
            'marking_code' => self::summaryValue($savedOnly, $override, 'marking_code',
                trim((string) ($trip?->print_marking_code ?? '')),
                self::joinUnique($barangs->pluck('marking')),
                trim((string) ($marking?->displayCode() ?? '')),
            ),
            'customer' => self::summaryValue($savedOnly, $override, 'customer',
                trim((string) ($trip?->print_customer_name ?? '')),
                self::joinUnique($barangs->map(static fn (Barang $barang): string => trim((string) ($barang->customer_name ?? $barang->customer?->name ?? '')))),
                trim((string) ($marking?->customer_name ?? '')),
            ),
            'telp' => self::summaryValue($savedOnly, $override, 'telp',
                trim((string) ($trip?->print_customer_phone ?? '')),
                $phone,
            ),
            'alamat' => self::summaryValue($savedOnly, $override, 'alamat',
                trim((string) ($trip?->print_customer_address ?? '')),
                $address,
            ),
            'loading_date' => self::summaryValue($savedOnly, $override, 'loading_date',
                $trip?->print_loading_date?->format('Y-m-d') ?? '',
                $loadingDate,
            ),
            'nama_barang' => self::summaryValue($savedOnly, $override, 'nama_barang',
                trim((string) ($trip?->print_item_name ?? '')),
                self::joinUnique($barangs->map(static fn (Barang $barang): string => self::namaBarang($barang))),
            ),
            'total_coly' => self::summaryValue($savedOnly, $override, 'total_coly',
                trim((string) ($trip?->print_total_coly ?? '')),
                $barangs->isNotEmpty() ? (string) ((int) $barangs->sum('ctns')) : '',
            ),
            'partai' => self::summaryValue($savedOnly, $override, 'partai',
                trim((string) ($trip?->print_partai ?? '')),
                $containerPartai,
            ),
            'notes' => self::summaryValue($savedOnly, $override, 'notes',
                trim((string) ($trip?->print_notes ?? '')),
                trim((string) ($trip?->notes ?? $firstBarang?->notes ?? '')),
            ),
            'nama_penerima' => self::summaryValue($savedOnly, $override, 'nama_penerima',
                trim((string) ($trip?->receiver_name ?? '')),
            ),
            'no_telp_penerima' => self::summaryValue($savedOnly, $override, 'no_telp_penerima',
                trim((string) ($trip?->receiver_phone ?? '')),
            ),
            'tanggal_terima' => self::summaryValue($savedOnly, $override, 'tanggal_terima',
                $trip?->received_date?->format('Y-m-d') ?? '',
            ),
        ];
    }

    /**
     * @param  array<string, string>  $override
     */
    private static function summaryValue(bool $savedOnly, array $override, string $key, string ...$fallbacks): string
    {
        if ($savedOnly && array_key_exists($key, $override)) {
            return trim((string) $override[$key]);
        }

        return self::firstNonEmpty(trim((string) ($override[$key] ?? '')), ...$fallbacks);
    }

    public static function markingSummaryRow(?DeliveryTrip $trip, string $markingKey): ?DeliveryTripMarkingSummary
    {
        if ($trip === null || $markingKey === '') {
            return null;
        }

        if (! $trip->relationLoaded('markingSummaries')) {
            $trip->loadMissing('markingSummaries');
        }

        /** @var DeliveryTripMarkingSummary|null $row */
        $row = $trip->markingSummaries->firstWhere('marking_key', $markingKey);

        return $row;
    }

    /**
     * @return array{
     *     departure_photos: list<array<string, mixed>>,
     *     departure_videos: list<array<string, mixed>>,
     *     arrival_photos: list<array<string, mixed>>,
     *     arrival_videos: list<array<string, mixed>>,
     * }
     */
    public static function markingMediaPayload(?DeliveryTrip $trip, string $markingKey): array
    {
        if ($trip === null) {
            return [
                'departure_photos' => [],
                'departure_videos' => [],
                'arrival_photos' => [],
                'arrival_videos' => [],
            ];
        }

        if (! $trip->relationLoaded('media')) {
            $trip->loadMissing('media.uploader:id,name');
        }

        $media = $trip->media
            ->filter(static fn ($item) => (string) ($item->marking_key ?? '') === $markingKey)
            ->map(static fn ($m) => [
                'id' => $m->id,
                'phase' => $m->phase,
                'media_type' => $m->media_type,
                'url' => $m->url(),
                'original_name' => $m->original_name,
                'uploaded_by' => $m->uploader?->name,
                'created_at' => $m->created_at?->format('d M Y H:i'),
            ]);

        return [
            'departure_photos' => $media->where('phase', DeliveryTripMedia::PHASE_DEPARTURE)->where('media_type', DeliveryTripMedia::TYPE_PHOTO)->values()->all(),
            'departure_videos' => $media->where('phase', DeliveryTripMedia::PHASE_DEPARTURE)->where('media_type', DeliveryTripMedia::TYPE_VIDEO)->values()->all(),
            'arrival_photos' => $media->where('phase', DeliveryTripMedia::PHASE_ARRIVAL)->where('media_type', DeliveryTripMedia::TYPE_PHOTO)->values()->all(),
            'arrival_videos' => $media->where('phase', DeliveryTripMedia::PHASE_ARRIVAL)->where('media_type', DeliveryTripMedia::TYPE_VIDEO)->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function resolveMarkingForBarangs(Collection $barangs): ?Marking
    {
        /** @var Barang|null $firstBarang */
        $firstBarang = $barangs->first();
        if (! $firstBarang instanceof Barang) {
            return null;
        }

        $key = self::markingKey($firstBarang);
        if ($key === self::NO_MARKING_KEY) {
            return null;
        }

        $marking = Marking::query()->whereBarangMarkingValue($key)->first();
        if ($marking !== null) {
            return $marking;
        }

        $raw = trim((string) ($firstBarang->marking ?? ''));
        if ($raw !== '' && $raw !== $key) {
            return Marking::query()->whereBarangMarkingValue($raw)->first();
        }

        return null;
    }

    private static function namaBarang(Barang $barang): string
    {
        $summary = trim($barang->itemsSummary());
        if ($summary !== '' && $summary !== '-') {
            return $summary;
        }

        return trim((string) ($barang->form ?? $barang->material_desc ?? ''));
    }

    private static function loadTripRelations(DeliveryTrip $trip): void
    {
        $trip->loadMissing([
            'container:id,partai,container_no,load_date,branch',
            'barangs' => static fn ($q) => $q->orderBy('delivery_trip_barang.sort_order')
                ->with([
                    'customer:id,name',
                    'containers:id,partai,container_no',
                    'products' => static fn ($pq) => $pq->orderBy('sort_order'),
                ]),
        ]);
    }

    private static function formatDecimal(float $value, int $decimals): string
    {
        if ($value <= 0) {
            return '';
        }

        return number_format($value, $decimals, ',', '.');
    }

    private static function parseDecimal(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        $value = preg_replace('/[^\d,.-]/', '', $value) ?? '';
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);

        return (float) $value;
    }

    private static function joinUnique(Collection $values): string
    {
        return $values
            ->map(static fn ($value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->implode(', ');
    }

    private static function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
