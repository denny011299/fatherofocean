<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Container;
use App\Models\DeliveryTrip;
use App\Models\DeliveryTripMarkingSummary;
use App\Models\DeliveryTripMedia;
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
        self::syncBarangVolumesFromProducts($allBarangs);
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
                'total_cbm' => CbmFormat::truncate((float) $items->sum(
                    static fn (Barang $barang): float => $barang->displayTotalCbm(),
                )),
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
        self::syncBarangVolumesFromProducts($barangs);
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
            $markingRow = self::markingSummaryRowForKey($trip, $group['key']);
            $summaryMismatch = self::isMarkingCodeMismatch($group['key'], (string) ($summary['marking_code'] ?? ''));
            $canDownload = $canPdf && $tripId !== null;
            $pdfUrl = $canDownload
                ? route('delivery-trip.pdf', ['deliveryTrip' => $tripId, 'marking' => $group['key']])
                : null;
            $media = self::markingMediaPayload($trip, $group['key']);
            $canConfirm = auth()->user()?->can('confirm delivery trip') ?? false;

            $hasSavedSummary = $markingRow !== null && ! $summaryMismatch;
            $status = (string) ($markingRow?->status ?? DeliveryTrip::STATUS_READY);
            $summaryTruckSaved = self::optionalTruckValue($summary['truck_plate'] ?? '') !== ''
                && self::optionalTruckValue($summary['driver_name'] ?? '') !== '';
            $truckSaved = ($markingRow?->truckSaved() ?? false) || ($markingRow === null && $summaryTruckSaved);
            // Truck plate/driver optional for now — re-require when client asks.
            $canConfirmDeparture = $canConfirm && in_array(
                $status,
                [DeliveryTrip::STATUS_DRAFT, DeliveryTrip::STATUS_READY],
                true,
            );
            $canConfirmArrival = $canConfirm && $status === DeliveryTrip::STATUS_DEPARTED;

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
                'status' => $status,
                'status_label' => DeliveryTrip::statusLabel($status),
                'status_badge' => $markingRow?->statusBadgeClass() ?? 'badge-status-pending',
                'truck_saved' => $truckSaved,
                'can_download_pdf' => $canDownload,
                'shipments_count' => $group['shipments_count'],
                'total_ctns' => $group['total_ctns'],
                'total_cbm' => $group['total_cbm'],
                'no_indexes' => $group['no_indexes'],
                'shipments' => $group['barangs']->map(static function (Barang $barang): array {
                    return [
                        'id' => $barang->id,
                        'no_index' => $barang->no_index,
                        'marking' => $barang->marking,
                        'nama_barang' => self::namaBarang($barang),
                        'customer_name' => $barang->customer_name ?: ($barang->customer?->name ?? ''),
                        'ctns' => (int) $barang->ctns,
                        'total_cbm' => $barang->displayTotalCbm(),
                    ];
                })->values()->all(),
                'pdf_url' => $pdfUrl,
                'sheet_label' => __('delivery_trip.sheet_index_label', [
                    'index' => $index + 1,
                    'total' => count($groups),
                    'marking' => $group['code'],
                ]),
                'summary' => $summary,
                'can_confirm_departure' => $canConfirmDeparture,
                'can_confirm_arrival' => $canConfirmArrival,
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

    private static function isMarkingCodeMismatch(string $markingKey, string $summaryMarkingCode): bool
    {
        if ($markingKey === '' || $markingKey === '__all__' || $markingKey === self::NO_MARKING_KEY) {
            return false;
        }

        $summaryCode = trim($summaryMarkingCode);
        if ($summaryCode === '') {
            return false;
        }

        $expected = trim(self::markingLabel($markingKey));
        if ($expected === '') {
            return false;
        }

        return mb_strtolower($summaryCode) !== mb_strtolower($expected);
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
        $markingRow = self::markingSummaryRowForKey($trip, $markingKey);
        $splits = DeliverySplitDeliveries::fromSummaryRow($markingRow);
        if ($splits !== []) {
            // Multi-page PDF only; main summary fields stay single shipment for invoice/API.
            if (DeliverySplitDeliveries::usesIndexMode($splits)) {
                return self::buildPdfPayloadForIndexSplits(
                    $trip,
                    $barangs,
                    $summary,
                    $splits,
                    $markingCode,
                    $markingIndex,
                    $markingTotal,
                );
            }

            return self::buildPdfPayloadForSplits(
                $trip,
                $barangs,
                $summary,
                $splits,
                $markingCode,
                $markingIndex,
                $markingTotal,
            );
        }

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
                'penerima' => $penerima,
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
     * @param  array<string, string>  $summary
     * @param  list<array{address: string, receiver_name: string, receiver_phone: string, coly: int}>  $splits
     * @return array{
     *     pages: list<array<string, mixed>>,
     *     penerima: string,
     *     trip_no: string,
     *     printDate: string,
     *     marking_code: string,
     *     marking_index: int,
     *     marking_total: int,
     *     sheet_title: string,
     * }
     */
    private static function buildPdfPayloadForSplits(
        DeliveryTrip $trip,
        Collection $barangs,
        array $summary,
        array $splits,
        string $markingCode,
        int $markingIndex,
        int $markingTotal,
    ): array {
        $headerBase = self::buildHeader($trip, $summary, $barangs);
        $namaBarang = self::firstNonEmpty(
            trim((string) ($summary['nama_barang'] ?? '')),
            self::joinUnique($barangs->map(static fn (Barang $barang): string => self::namaBarang($barang))),
        );
        $keterangan = trim((string) ($summary['notes'] ?? ''));
        $baseSheetTitle = __('delivery_trip.sheet_index_label', [
            'index' => $markingIndex,
            'total' => $markingTotal,
            'marking' => $markingCode,
        ]);
        $splitCount = count($splits);
        $pages = [];
        $firstPenerima = '';

        foreach ($splits as $splitIndex => $split) {
            $header = $headerBase;
            if ($split['address'] !== '') {
                $header['alamat'] = $split['address'];
            }

            $penerima = trim($split['receiver_name']);
            if ($split['receiver_phone'] !== '') {
                $penerima = trim($penerima.($penerima !== '' ? ' · ' : '').$split['receiver_phone']);
            }
            if ($firstPenerima === '' && $penerima !== '') {
                $firstPenerima = $penerima;
            }

            $coly = (string) (int) ($split['coly'] ?? 0);
            $rows = [[
                'index' => '-',
                'nama_barang' => $namaBarang !== '' ? $namaBarang : '-',
                'colli' => $coly,
                'cbm' => '',
                'gw' => '',
                'keterangan' => $keterangan,
            ]];

            $pages[] = [
                'header' => $header,
                'rows' => $rows,
                'totals' => [
                    'colli' => $coly,
                    'cbm' => '',
                    'gw' => '',
                ],
                'show_footer' => true,
                'penerima' => $penerima,
                'sheet_title' => $baseSheetTitle.' — '.__('delivery_trip.split_sheet_label', [
                    'index' => $splitIndex + 1,
                    'total' => $splitCount,
                ]),
            ];
        }

        return [
            'pages' => $pages,
            'penerima' => $firstPenerima,
            'trip_no' => trim((string) ($trip->trip_no ?? '')),
            'printDate' => now()->format('d/m/Y'),
            'marking_code' => $markingCode,
            'marking_index' => $markingIndex,
            'marking_total' => $markingTotal,
            'sheet_title' => $baseSheetTitle,
        ];
    }

    /**
     * Per-index split: one or more PDF pages per address group with real barang rows.
     *
     * @param  array<string, string>  $summary
     * @param  list<array{address: string, receiver_name: string, receiver_phone: string, coly: int, barang_ids: list<int>, no_indexes: list<string>}>  $splits
     * @return array{
     *     pages: list<array<string, mixed>>,
     *     penerima: string,
     *     trip_no: string,
     *     printDate: string,
     *     marking_code: string,
     *     marking_index: int,
     *     marking_total: int,
     *     sheet_title: string,
     * }
     */
    private static function buildPdfPayloadForIndexSplits(
        DeliveryTrip $trip,
        Collection $barangs,
        array $summary,
        array $splits,
        string $markingCode,
        int $markingIndex,
        int $markingTotal,
    ): array {
        $headerBase = self::buildHeader($trip, $summary, $barangs);
        $baseSheetTitle = __('delivery_trip.sheet_index_label', [
            'index' => $markingIndex,
            'total' => $markingTotal,
            'marking' => $markingCode,
        ]);
        $groups = DeliverySplitDeliveries::groupsForIndexPdf($splits, $barangs);
        $groupsWithAddress = array_values(array_filter(
            $groups,
            static fn (array $group): bool => trim($group['address']) !== '' || $group['barangs']->isNotEmpty(),
        ));
        $addressGroupCount = count(array_filter(
            $groupsWithAddress,
            static fn (array $group): bool => trim($group['address']) !== '',
        ));
        $pages = [];
        $firstPenerima = '';
        $addressPageIndex = 0;

        foreach ($groupsWithAddress as $group) {
            /** @var Collection<int, Barang> $groupBarangs */
            $groupBarangs = $group['barangs'];
            if ($groupBarangs->isEmpty()) {
                continue;
            }

            $header = $headerBase;
            $groupAddress = trim($group['address']);
            if ($groupAddress !== '') {
                $header['alamat'] = $groupAddress;
            }

            $penerima = trim($group['receiver_name']);
            if ($group['receiver_phone'] !== '') {
                $penerima = trim($penerima.($penerima !== '' ? ' · ' : '').$group['receiver_phone']);
            }
            if ($firstPenerima === '' && $penerima !== '') {
                $firstPenerima = $penerima;
            }

            $rows = self::buildRows($trip, $groupBarangs, $summary);
            $totals = self::buildTotals($rows);
            $rowChunks = array_chunk($rows, self::ROWS_PER_PAGE);
            $lastChunk = count($rowChunks) - 1;

            $sheetSuffix = $groupAddress !== '' && $addressGroupCount > 1
                ? ' — '.__('delivery_trip.split_sheet_label', [
                    'index' => ++$addressPageIndex,
                    'total' => $addressGroupCount,
                ])
                : '';

            foreach ($rowChunks as $chunkIndex => $chunk) {
                $pages[] = [
                    'header' => $header,
                    'rows' => $chunk,
                    'totals' => $chunkIndex === $lastChunk ? $totals : null,
                    'show_footer' => $chunkIndex === $lastChunk,
                    'penerima' => $penerima,
                    'sheet_title' => $baseSheetTitle.$sheetSuffix,
                ];
            }
        }

        if ($pages === []) {
            $header = self::buildHeader($trip, $summary, $barangs);
            $rows = self::buildRows($trip, $barangs, $summary);
            $totals = self::buildTotals($rows);
            $penerima = trim((string) ($summary['nama_penerima'] ?? ''));
            $rowChunks = $rows !== [] ? array_chunk($rows, self::ROWS_PER_PAGE) : [[]];
            $lastIndex = count($rowChunks) - 1;
            foreach ($rowChunks as $index => $chunk) {
                $pages[] = [
                    'header' => $header,
                    'rows' => $chunk,
                    'totals' => $index === $lastIndex ? $totals : null,
                    'show_footer' => $index === $lastIndex,
                    'penerima' => $penerima,
                ];
            }
        }

        return [
            'pages' => $pages,
            'penerima' => $firstPenerima !== '' ? $firstPenerima : trim((string) ($summary['nama_penerima'] ?? '')),
            'trip_no' => trim((string) ($trip->trip_no ?? '')),
            'printDate' => now()->format('d/m/Y'),
            'marking_code' => $markingCode,
            'marking_index' => $markingIndex,
            'marking_total' => $markingTotal,
            'sheet_title' => $baseSheetTitle,
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
            'partai_label' => self::formatPartaiContainerLabel(
                $container?->partai,
                $container?->container_no,
            ),
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
                'partai_label' => self::formatPartaiContainerLabel(
                    $summary['partai'] ?? null,
                    $summary['container_no'] ?? null,
                ),
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
     * @return array<string, string>
     */
    public static function summaryForMarking(
        ?DeliveryTrip $trip,
        ?Container $container,
        Collection $barangs,
        string $markingKey,
    ): array {
        $override = self::summaryOverrideForMarking($trip, $markingKey);
        $hasSavedOverride = $override !== [];

        if ($hasSavedOverride && self::isMarkingCodeMismatch($markingKey, (string) ($override['marking_code'] ?? ''))) {
            $fallback = self::buildSummaryFields($trip, $container, $barangs);

            $fallback['marking_code'] = self::markingLabel($markingKey);
            $fallback['truck_plate'] = trim((string) ($override['truck_plate'] ?? ''));
            $fallback['driver_name'] = trim((string) ($override['driver_name'] ?? ''));
            $fallback['driver_phone'] = trim((string) ($override['driver_phone'] ?? ''));
            $fallback['destination'] = trim((string) ($override['destination'] ?? ''));
            $fallback['trip_notes'] = trim((string) ($override['trip_notes'] ?? ''));

            return $fallback;
        }

        return self::buildSummaryFields(
            $trip,
            $container,
            $barangs,
            $override,
            $hasSavedOverride,
        );
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

        $partai = self::firstNonEmpty(
            trim((string) ($summary['partai'] ?? '')),
            trim((string) ($trip->container?->partai ?? '')),
        );
        $containerNo = self::firstNonEmpty(
            trim((string) ($summary['container_no'] ?? '')),
            trim((string) ($trip->container?->container_no ?? '')),
        );

        return [
            'no_pol' => self::firstNonEmpty(
                self::optionalTruckValue($summary['truck_plate'] ?? ''),
                self::optionalTruckValue($trip->truck_plate ?? ''),
            ),
            'sopir' => self::firstNonEmpty(
                self::optionalTruckValue($summary['driver_name'] ?? ''),
                self::optionalTruckValue($trip->driver_name ?? ''),
            ),
            'sby' => $sby,
            'mark' => trim((string) ($summary['marking_code'] ?? '')),
            'alamat' => trim((string) ($summary['alamat'] ?? '')),
            'partai' => $partai,
            'container_no' => $containerNo,
            'partai_label' => self::formatPartaiContainerLabel($partai, $containerNo),
        ];
    }

    /**
     * Format tampilan partai + no kontainer di surat jalan / tanda terima.
     * Contoh: partai 601 - K.8
     */
    public static function formatPartaiContainerLabel(?string $partai, ?string $containerNo): string
    {
        $partai = trim((string) $partai);
        $containerNo = trim((string) $containerNo);

        $kLabel = '';
        if ($containerNo !== '') {
            $num = preg_replace('/^K\.?\s*/i', '', $containerNo) ?? $containerNo;
            $num = trim((string) $num);
            if ($num !== '') {
                $kLabel = 'K.'.$num;
            }
        }

        if ($partai !== '' && $kLabel !== '') {
            return 'partai '.$partai.' - '.$kLabel;
        }

        if ($partai !== '') {
            return 'partai '.$partai;
        }

        return $kLabel;
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
                    'cbm' => CbmFormat::format($barang->displayTotalCbm(), ',', '.'),
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
            'cbm' => CbmFormat::format($totalCbm, ',', '.'),
            'gw' => self::formatDecimal($totalGw, 2),
        ];
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     * @return array<string, string>
     */
    private static function buildSummaryFields(
        ?DeliveryTrip $trip,
        ?Container $container,
        Collection $barangs,
        array $override = [],
        bool $hasSavedOverride = false,
    ): array
    {
        /** @var Barang|null $firstBarang */
        $firstBarang = $barangs->first();
        $marking = self::resolveMarkingForBarangs($barangs);
        if ($marking === null && $container?->relationLoaded('marking')) {
            $marking = $container->marking;
        }

        // When the user has already saved data for this marking, use the saved values
        // directly (even if empty) so the user can intentionally clear a field.
        // Only fall back to auto-detected defaults when no record has been saved yet.
        if ($hasSavedOverride) {
            $loadingDate = trim((string) ($override['loading_date'] ?? ''));
            if ($loadingDate === '') {
                $loadingDate = $trip?->departed_at?->format('Y-m-d')
                    ?? $container?->load_date?->format('Y-m-d')
                    ?? ($firstBarang?->tanggal_masuk?->format('Y-m-d') ?? '');
            }

            return [
                'marking_code' => trim((string) ($override['marking_code'] ?? '')),
                'customer' => trim((string) ($override['customer_name'] ?? '')),
                'telp' => trim((string) ($override['customer_phone'] ?? '')),
                'alamat' => trim((string) ($override['customer_address'] ?? '')),
                'loading_date' => $loadingDate,
                'nama_barang' => trim((string) ($override['item_name'] ?? '')),
                'total_coly' => trim((string) ($override['total_coly'] ?? '')),
                'partai' => self::firstNonEmpty(
                    trim((string) ($override['partai'] ?? '')),
                    trim((string) ($container?->partai ?? '')),
                ),
                'container_no' => trim((string) ($container?->container_no ?? '')),
                'notes' => trim((string) ($override['notes'] ?? '')),
                'nama_penerima' => trim((string) ($override['receiver_name'] ?? '')),
                'no_telp_penerima' => trim((string) ($override['receiver_phone'] ?? '')),
                'tanggal_terima' => trim((string) ($override['received_date'] ?? '')),
                'truck_plate' => trim((string) ($override['truck_plate'] ?? '')),
                'driver_name' => trim((string) ($override['driver_name'] ?? '')),
                'driver_phone' => trim((string) ($override['driver_phone'] ?? '')),
                'destination' => trim((string) ($override['destination'] ?? '')),
                'trip_notes' => trim((string) ($override['trip_notes'] ?? '')),
                'split_deliveries' => DeliverySplitDeliveries::normalize($override['split_deliveries'] ?? []),
            ];
        }

        // No saved record yet — auto-detect defaults from barang/container/trip.
        $address = trim(implode(' ', array_filter([
            trim((string) ($marking?->address_1 ?? '')),
            trim((string) ($marking?->address_2 ?? '')),
        ])));
        $phone = trim((string) ($marking?->phone_hp ?? $marking?->phone_02 ?? ''));
        $containerPartai = trim((string) ($container?->partai ?? ''));
        $containerNo = trim((string) ($container?->container_no ?? ''));
        $loadingDate = $trip?->departed_at?->format('Y-m-d')
            ?? $container?->load_date?->format('Y-m-d')
            ?? ($firstBarang?->tanggal_masuk?->format('Y-m-d') ?? '');

        return [
            'marking_code' => self::firstNonEmpty(
                trim((string) ($marking?->displayCode() ?? '')),
                self::joinUnique($barangs->pluck('marking')),
            ),
            'customer' => self::firstNonEmpty(
                trim((string) ($marking?->customer_name ?? '')),
                self::joinUnique($barangs->map(static fn (Barang $barang): string => trim((string) ($barang->customer_name ?? $barang->customer?->name ?? '')))),
            ),
            'telp' => self::firstNonEmpty(
                $phone,
                self::joinUnique($barangs->map(static fn (Barang $barang): string => trim((string) ($barang->customer?->phone ?? '')))),
            ),
            'alamat' => self::firstNonEmpty(
                $address,
            ),
            'loading_date' => self::firstNonEmpty(
                $loadingDate,
            ),
            'nama_barang' => self::firstNonEmpty(
                self::joinUnique($barangs->map(static fn (Barang $barang): string => self::namaBarang($barang))),
            ),
            'total_coly' => self::firstNonEmpty(
                $barangs->isNotEmpty() ? (string) ((int) $barangs->sum('ctns')) : '',
            ),
            'partai' => self::firstNonEmpty(
                $containerPartai,
            ),
            'container_no' => $containerNo,
            'notes' => '',
            'nama_penerima' => trim((string) ($trip?->receiver_name ?? '')),
            'no_telp_penerima' => trim((string) ($trip?->receiver_phone ?? '')),
            'tanggal_terima' => $trip?->received_date?->format('Y-m-d') ?? '',
            'truck_plate' => trim((string) ($trip?->truck_plate ?? '')),
            'driver_name' => trim((string) ($trip?->driver_name ?? '')),
            'driver_phone' => trim((string) ($trip?->driver_phone ?? '')),
            'destination' => trim((string) ($trip?->destination ?? '')),
            'trip_notes' => trim((string) ($trip?->notes ?? '')),
            'split_deliveries' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function summaryOverrideForMarking(?DeliveryTrip $trip, string $markingKey): array
    {
        if ($trip === null || $markingKey === '' || $markingKey === '__all__') {
            return [];
        }

        $row = self::markingSummaryRowForKey($trip, $markingKey);
        if ($row === null) {
            return [];
        }

        return [
            'truck_plate' => trim((string) ($row->truck_plate ?? '')),
            'driver_name' => trim((string) ($row->driver_name ?? '')),
            'driver_phone' => trim((string) ($row->driver_phone ?? '')),
            'destination' => trim((string) ($row->destination ?? '')),
            'trip_notes' => trim((string) ($row->trip_notes ?? '')),
            'marking_code' => trim((string) ($row->marking_code ?? '')),
            'customer_name' => trim((string) ($row->customer_name ?? '')),
            'customer_phone' => trim((string) ($row->customer_phone ?? '')),
            'customer_address' => trim((string) ($row->customer_address ?? '')),
            'loading_date' => $row->loading_date?->format('Y-m-d') ?? '',
            'item_name' => trim((string) ($row->item_name ?? '')),
            'total_coly' => trim((string) ($row->total_coly ?? '')),
            'partai' => trim((string) ($row->partai ?? '')),
            'notes' => trim((string) ($row->notes ?? '')),
            'receiver_name' => trim((string) ($row->receiver_name ?? '')),
            'receiver_phone' => trim((string) ($row->receiver_phone ?? '')),
            'received_date' => $row->received_date?->format('Y-m-d') ?? '',
            'split_deliveries' => DeliverySplitDeliveries::fromSummaryRow($row),
        ];
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

    private static function optionalTruckValue(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));

        return ($normalized === '-' || $normalized === '.') ? '' : $normalized;
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
            'markingSummaries',
        ]);
    }

    /**
     * @param  Collection<int, Barang>  $barangs
     */
    private static function syncBarangVolumesFromProducts(Collection $barangs): void
    {
        foreach ($barangs as $barang) {
            if (! $barang instanceof Barang) {
                continue;
            }

            $hasParents = $barang->relationLoaded('products')
                ? $barang->products->contains(static fn (BarangProduct $p): bool => $p->parent_id === null)
                : $barang->products()->whereNull('parent_id')->exists();

            if (! $hasParents) {
                continue;
            }

            $live = $barang->displayTotalCbm();
            if (abs($live - (float) ($barang->total_cbm ?? 0)) <= 0.0005) {
                continue;
            }

            $barang->recalculateTotals();
        }
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
