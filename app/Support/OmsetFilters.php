<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class OmsetFilters
{
    public function __construct(
        public string $datePreset = '',
        public ?string $tanggalFrom = null,
        public ?string $tanggalTo = null,
        public ?string $partai = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $preset = DatePresetFilter::normalizePreset($request->input('date_preset'));

        if ($preset === '' && $request->filled('period')) {
            return self::fromLegacyPeriodRequest($request);
        }

        if (
            $preset === ''
            && ! $request->has('date_preset')
            && ! $request->has('period')
            && ! $request->has('partai')
            && ! $request->has('tanggal_from')
            && ! $request->has('tanggal_to')
        ) {
            return self::defaultForOmset();
        }

        [$from, $to] = DatePresetFilter::resolveBounds(
            $preset,
            $request->input('tanggal_from'),
            $request->input('tanggal_to'),
        );

        return new self(
            datePreset: $preset,
            tanggalFrom: $from?->toDateString(),
            tanggalTo: $to?->toDateString(),
            partai: self::normalizePartai($request->input('partai')),
        );
    }

    /** Default omset: tahun berjalan (setara filter lama periode bulanan + tahun). */
    public static function defaultForOmset(): self
    {
        [$from, $to] = DatePresetFilter::resolveBounds('this_year');

        return new self(
            datePreset: 'this_year',
            tanggalFrom: $from?->toDateString(),
            tanggalTo: $to?->toDateString(),
            partai: null,
        );
    }

    /** @param Builder<\App\Models\Barang> $query */
    public function applyToBarangQuery(Builder $query): Builder
    {
        if ($this->tanggalFrom !== null && $this->tanggalFrom !== '') {
            $query->whereDate('tanggal_masuk', '>=', $this->tanggalFrom);
        }

        if ($this->tanggalTo !== null && $this->tanggalTo !== '') {
            $query->whereDate('tanggal_masuk', '<=', $this->tanggalTo);
        }

        if ($this->partai === 'belum_di_muat') {
            $query->whereDoesntHave('containers');
        } elseif ($this->partai !== null && $this->partai !== '') {
            $partai = $this->partai;
            $query->whereHas('containers', static function (Builder $containerQuery) use ($partai): void {
                $containerQuery->where('partai', $partai);
            });
        }

        return $query;
    }

    public function previewPeriodSelectSql(): string
    {
        if ($this->tanggalFrom !== null && $this->tanggalTo !== null) {
            $from = Carbon::parse($this->tanggalFrom);
            $to = Carbon::parse($this->tanggalTo);
            if ($from->diffInDays($to) <= 60) {
                return "DATE_FORMAT(barangs.tanggal_masuk, '%d %b %Y')";
            }
        }

        return "DATE_FORMAT(barangs.tanggal_masuk, '%b %Y')";
    }

    public function filterTitle(): string
    {
        $parts = [];

        if ($this->datePreset === '') {
            $parts[] = 'Semua tanggal';
        } else {
            $parts[] = self::presetLabel($this->datePreset, $this->tanggalFrom, $this->tanggalTo);
        }

        if ($this->partai === 'belum_di_muat') {
            $parts[] = 'Belum dimuat';
        } elseif ($this->partai !== null && $this->partai !== '') {
            $parts[] = 'Partai '.$this->partai;
        }

        return implode(' · ', $parts);
    }

    public function rangeLabel(): string
    {
        if ($this->tanggalFrom === null && $this->tanggalTo === null) {
            return '';
        }

        if ($this->tanggalFrom !== null && $this->tanggalTo !== null) {
            $from = Carbon::parse($this->tanggalFrom)->locale(app()->getLocale());
            $to = Carbon::parse($this->tanggalTo)->locale(app()->getLocale());

            if ($from->isSameDay($to)) {
                return $from->translatedFormat('d M Y');
            }

            return $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y');
        }

        if ($this->tanggalFrom !== null) {
            return '≥ '.Carbon::parse($this->tanggalFrom)->locale(app()->getLocale())->translatedFormat('d M Y');
        }

        return '≤ '.Carbon::parse((string) $this->tanggalTo)->locale(app()->getLocale())->translatedFormat('d M Y');
    }

    public function hasActiveFilter(): bool
    {
        return $this->datePreset !== ''
            || ($this->partai !== null && $this->partai !== '');
    }

    /** @return array<string, string> */
    public function toQueryArray(): array
    {
        $params = [];

        if ($this->datePreset !== '') {
            $params['date_preset'] = $this->datePreset;
        }
        if ($this->tanggalFrom !== null && $this->tanggalFrom !== '') {
            $params['tanggal_from'] = $this->tanggalFrom;
        }
        if ($this->tanggalTo !== null && $this->tanggalTo !== '') {
            $params['tanggal_to'] = $this->tanggalTo;
        }
        if ($this->partai !== null && $this->partai !== '') {
            $params['partai'] = $this->partai;
        }

        return $params;
    }

    public function exportFilenameSuffix(): string
    {
        if ($this->tanggalFrom !== null && $this->tanggalTo !== null) {
            return $this->tanggalFrom.'_'.$this->tanggalTo;
        }

        if ($this->tanggalFrom !== null) {
            return 'from-'.$this->tanggalFrom;
        }

        if ($this->tanggalTo !== null) {
            return 'to-'.$this->tanggalTo;
        }

        return 'all-'.now()->format('Ymd');
    }

    private static function fromLegacyPeriodRequest(Request $request): self
    {
        $periodType = (string) $request->get('period', 'bulanan');
        $year = (int) $request->get('year', now()->year);
        $month = $request->filled('month') ? (int) $request->get('month') : null;

        if ($periodType === 'tahunan') {
            $from = Carbon::create($year, 1, 1)->startOfDay();
            $to = Carbon::create($year, 12, 31)->endOfDay();
        } elseif ($month !== null) {
            $from = Carbon::create($year, $month, 1)->startOfDay();
            $to = $from->copy()->endOfMonth()->endOfDay();
        } else {
            $from = Carbon::create($year, 1, 1)->startOfDay();
            $to = Carbon::create($year, 12, 31)->endOfDay();
        }

        return new self(
            datePreset: 'custom',
            tanggalFrom: $from->toDateString(),
            tanggalTo: $to->toDateString(),
            partai: self::normalizePartai($request->input('partai')),
        );
    }

    private static function normalizePartai(mixed $partai): ?string
    {
        $value = trim((string) ($partai ?? ''));
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private static function presetLabel(string $preset, ?string $from, ?string $to): string
    {
        if ($preset === 'custom' && $from !== null && $to !== null) {
            return self::rangeLabelStatic($from, $to);
        }

        return match ($preset) {
            'today' => 'Hari ini',
            'yesterday' => 'Kemarin',
            '7d' => '7 hari terakhir',
            '30d' => '30 hari terakhir',
            'this_month' => 'Bulan ini',
            'this_year' => 'Tahun ini',
            'custom' => 'Kustom',
            default => 'Semua tanggal',
        };
    }

    private static function rangeLabelStatic(string $from, string $to): string
    {
        $start = Carbon::parse($from)->locale(app()->getLocale());
        $end = Carbon::parse($to)->locale(app()->getLocale());

        if ($start->isSameDay($end)) {
            return $start->translatedFormat('d M Y');
        }

        return $start->translatedFormat('d M Y').' – '.$end->translatedFormat('d M Y');
    }
}
