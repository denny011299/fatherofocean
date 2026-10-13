<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;

final class ActivityLog extends Model
{
    /** @var list<string> */
    private const IGNORED_DIFF_KEYS = [
        'id',
        'created_at',
        'updated_at',
        'created_by',
        'approved_by',
        'approved_at',
        'reviewed_by',
        'reviewed_at',
        'pivot',
    ];

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(null, 'model_type', 'model_id');
    }

    public static function record(string $action, ?Model $model = null, ?array $oldValues = null, ?array $newValues = null): static
    {
        $normalized = self::normalizePayload($action, $model, $oldValues, $newValues);

        return static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => $model ? $model::class : null,
            'model_id' => $model?->getKey(),
            'old_values' => $normalized['old'],
            'new_values' => $normalized['new'],
            'ip_address' => request()->ip(),
        ]);
    }

    public function hasDetail(): bool
    {
        return $this->detailLines() !== [];
    }

    /**
     * @return list<string>
     */
    public function detailLines(): array
    {
        $actionLower = strtolower($this->action);

        if (str_contains($actionLower, 'logo')) {
            return [__('system.activity.logo_' . (str_contains($actionLower, 'removed') ? 'removed' : 'updated'))];
        }

        if (
            str_contains($actionLower, 'shutdown')
            || str_contains($actionLower, 'brought back online')
            || (is_array($this->new_values) && isset($this->new_values['event']) && count($this->new_values) === 1)
        ) {
            return [__('system.activity.system_event', ['action' => $this->action])];
        }

        if ($this->isDeleteAction($actionLower) && is_array($this->old_values) && $this->old_values !== []) {
            return [__('system.activity.deleted', ['details' => $this->formatSnapshot($this->old_values)])];
        }

        if ($this->isCreateAction($actionLower) && is_array($this->new_values) && $this->new_values !== []) {
            return [__('system.activity.created', ['details' => $this->formatSnapshot($this->new_values)])];
        }

        $changes = $this->changedFieldLines();

        if ($changes !== []) {
            return $changes;
        }

        if (is_array($this->new_values) && $this->new_values !== []) {
            return [$this->formatSnapshot($this->new_values)];
        }

        if (is_array($this->old_values) && $this->old_values !== []) {
            return [$this->formatSnapshot($this->old_values)];
        }

        return [];
    }

    public function detailSummary(): string
    {
        $lines = $this->detailLines();

        return $lines === [] ? '' : implode('; ', $lines);
    }

    /**
     * @return array{old: ?array, new: ?array}
     */
    private static function normalizePayload(
        string $action,
        ?Model $model,
        ?array $oldValues,
        ?array $newValues,
    ): array {
        $actionLower = strtolower($action);

        if ($model !== null) {
            if ($oldValues === null && $newValues === null) {
                if (self::isDeleteActionStatic($actionLower)) {
                    $oldValues = self::modelSnapshot($model);
                } else {
                    $newValues = self::modelSnapshot($model);
                }
            } elseif ($oldValues !== null && $newValues !== null) {
                [$oldValues, $newValues] = self::diffSnapshots(
                    self::filterLogAttributes($oldValues, $model),
                    self::filterLogAttributes($newValues, $model),
                );
            } else {
                $oldValues = $oldValues !== null ? self::filterLogAttributes($oldValues, $model) : null;
                $newValues = $newValues !== null
                    ? self::enrichPartialValues(self::filterLogAttributes($newValues, $model), $model, $actionLower)
                    : null;
            }
        }

        if ($oldValues === null && $newValues === null) {
            $newValues = ['event' => $action];
        }

        return ['old' => $oldValues, 'new' => $newValues];
    }

    /**
     * @return array<string, mixed>
     */
    private static function modelSnapshot(Model $model): array
    {
        return match (true) {
            $model instanceof Barang => self::barangSnapshot($model),
            $model instanceof Container => self::containerSnapshot($model),
            $model instanceof Invoice => self::invoiceSnapshot($model),
            $model instanceof Payment => self::paymentSnapshot($model),
            default => self::filterLogAttributes($model->toArray(), $model),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function barangSnapshot(Barang $barang): array
    {
        $barang->loadMissing('customer');

        return Arr::only([
            'marking' => $barang->marking,
            'no_index' => $barang->no_index,
            'customer_name' => $barang->customer_name ?: $barang->customer?->name,
            'status' => $barang->status,
            'approval_status' => $barang->approval_status,
            'no_po' => $barang->no_po,
            'destination' => $barang->destination,
        ], self::loggableKeys(Barang::class));
    }

    /**
     * @return array<string, mixed>
     */
    private static function containerSnapshot(Container $container): array
    {
        $container->loadMissing('marking');

        return Arr::only([
            'partai' => $container->partai,
            'container_no' => $container->container_no,
            'marking' => $container->marking?->code,
            'status' => $container->status,
            'type' => $container->type,
        ], self::loggableKeys(Container::class));
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoiceSnapshot(Invoice $invoice): array
    {
        return Arr::only([
            'invoice_no' => $invoice->invoice_no,
            'status' => $invoice->status,
            'grand_total' => $invoice->grand_total,
            'promo_name' => $invoice->promo_name,
            'promo_type' => $invoice->promo_type,
            'promo_persen' => $invoice->promo_persen,
            'promo_amount_rp' => $invoice->promo_amount_rp,
            'promo_discount' => $invoice->promo_discount,
        ], self::loggableKeys(Invoice::class));
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentSnapshot(Payment $payment): array
    {
        $payment->loadMissing('invoice');

        return Arr::only([
            'invoice_no' => $payment->invoice?->invoice_no,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'sender_name' => $payment->sender_name,
        ], self::loggableKeys(Payment::class));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function filterLogAttributes(array $values, Model $model): array
    {
        $keys = self::loggableKeys($model::class);

        if ($keys === []) {
            return Arr::except($values, self::IGNORED_DIFF_KEYS);
        }

        return Arr::only($values, $keys);
    }

    /**
     * @return list<string>
     */
    private static function loggableKeys(string $modelClass): array
    {
        return match ($modelClass) {
            Barang::class => [
                'marking', 'no_index', 'no_po', 'customer_name', 'status', 'approval_status',
                'destination', 'ctns', 'pcs', 'total_cbm', 'warehouse_origin', 'gudang_china_id',
                'price', 'price_per_cbm', 'extra_charge', 'rate', 'rejection_note',
                'repricing_request_id', 'submitted_by', 'container_id', 'container_no', 'file', 'event',
            ],
            Container::class => [
                'partai', 'container_no', 'no_bl', 'notes', 'marking', 'status', 'type', 'branch', 'route',
            ],
            Invoice::class => [
                'invoice_no', 'status', 'grand_total', 'user_id',
                'promo_name', 'promo_type', 'promo_persen', 'promo_amount_rp', 'promo_discount',
            ],
            Payment::class => ['invoice_no', 'amount', 'status', 'sender_name', 'review_notes'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{0: ?array, 1: ?array}
     */
    private static function diffSnapshots(array $old, array $new): array
    {
        $oldFiltered = [];
        $newFiltered = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            if (in_array($key, self::IGNORED_DIFF_KEYS, true)) {
                continue;
            }

            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;

            if (self::valuesEqual($oldValue, $newValue)) {
                continue;
            }

            $oldFiltered[$key] = $oldValue;
            $newFiltered[$key] = $newValue;
        }

        if ($oldFiltered === [] && $newFiltered === []) {
            return [null, null];
        }

        return [$oldFiltered, $newFiltered];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function enrichPartialValues(array $values, Model $model, string $actionLower): array
    {
        if ($model instanceof Barang) {
            $base = self::barangSnapshot($model);
            $values = array_merge($base, $values);

            if (isset($values['container_id']) && ! isset($values['container_no'])) {
                $container = Container::query()->find($values['container_id']);
                if ($container) {
                    $values['container_no'] = $container->container_no ?: $container->partai;
                }
            }

            if (str_contains($actionLower, 'loaded')) {
                $values['status'] = Barang::STATUS_STUFFING;
            } elseif (str_contains($actionLower, 'unloaded')) {
                $values['status'] = Barang::STATUS_STUFFING;
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function changedFieldLines(): array
    {
        $old = is_array($this->old_values) ? $this->old_values : [];
        $new = is_array($this->new_values) ? $this->new_values : [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $lines = [];

        foreach ($keys as $key) {
            if (in_array($key, self::IGNORED_DIFF_KEYS, true) || $key === 'event') {
                continue;
            }

            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;

            if (self::valuesEqual($oldValue, $newValue)) {
                continue;
            }

            $lines[] = __('system.activity.change', [
                'field' => self::fieldLabel((string) $key),
                'old' => self::formatValue($oldValue),
                'new' => self::formatValue($newValue),
            ]);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function formatSnapshot(array $snapshot): string
    {
        $parts = [];

        foreach ($snapshot as $key => $value) {
            if (in_array($key, self::IGNORED_DIFF_KEYS, true) || $key === 'event' || self::isBlank($value)) {
                continue;
            }

            $parts[] = self::fieldLabel((string) $key) . ': ' . self::formatValue($value);
        }

        return implode(', ', $parts);
    }

    private static function fieldLabel(string $key): string
    {
        $translated = __('system.activity.fields.' . $key);

        return $translated !== 'system.activity.fields.' . $key ? $translated : str_replace('_', ' ', $key);
    }

    private static function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '-';
        }

        return (string) $value;
    }

    private static function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function isDeleteAction(string $actionLower): bool
    {
        return self::isDeleteActionStatic($actionLower);
    }

    private static function isDeleteActionStatic(string $actionLower): bool
    {
        return str_contains($actionLower, 'delet') || str_contains($actionLower, 'removed');
    }

    private function isCreateAction(string $actionLower): bool
    {
        return self::isCreateActionStatic($actionLower);
    }

    private static function isCreateActionStatic(string $actionLower): bool
    {
        return str_contains($actionLower, 'created')
            || str_contains($actionLower, 'generated')
            || str_contains($actionLower, 'approved')
            || str_contains($actionLower, 'exported');
    }
}
