<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class Marking extends Model
{
    /** Roles that see all markings (aligned with dashboard omset scope). */
    public const GLOBAL_VIEW_ROLES = ['super_admin', 'admin_cs'];

    /** Staff limited to markings where they are assigned as sales/marketing (markings.sales_id). */
    public const SALES_MARKING_SCOPE_ROLES = ['sales', 'marketing'];

    /** @var array<string, bool> */
    private static array $schemaColumnCache = [];

    protected $attributes = [
        'is_active' => true,
        'invoice_due_days' => 0,
        'max_weight_kg' => 0,
    ];

    protected $fillable = [
        'user_id', 'related_marking_id', 'customer_name', 'sales_id', 'code', 'description', 'is_active',
        'contact_person', 'email', 'pic', 'priority', 'branch_id',
        'address_1', 'address_2',
        'phone_hp', 'fax', 'phone_02', 'wa_name', 'nik', 'nik_name', 'ktp_photo_path', 'passcode',
        'effective_start', 'invoice_due_days', 'max_weight_kg', 'minimum_charge',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'related_marking_id' => 'integer',
            'effective_start' => 'date',
            'invoice_due_days' => 'integer',
            'max_weight_kg' => 'decimal:2',
            'minimum_charge' => 'decimal:2',
        ];
    }

    /** Global minimum billable CBM; falls back to max per pricing row before migration. */
    public function resolvedMinimumCharge(): string
    {
        if (self::hasColumnCached('markings', 'minimum_charge')) {
            return (string) ($this->minimum_charge ?? 0);
        }

        if (! $this->relationLoaded('pricings')) {
            return '0';
        }

        $max = $this->pricings->max('minimum_charge');

        return $max !== null ? (string) $max : '0';
    }

    public function scopeActive($q)
    {
        return $q->where(function ($query) {
            $query->where('is_active', true)->orWhereNull('is_active');
        });
    }

    public function scopeForDropdown($q)
    {
        return $q->active()->orderByCode();
    }

    public function scopeOrderByCode(Builder $query, string $direction = 'asc'): Builder
    {
        $col = self::codeColumn();

        return $col !== null
            ? $query->orderBy($col, $direction)
            : $query->orderBy($query->getModel()->getQualifiedKeyName(), $direction);
    }

    public static function userCanViewAll(?User $user = null): bool
    {
        $user ??= auth()->user();

        if ($user === null) {
            return false;
        }

        // Supervisors and sales/marketing are always team-scoped even when they hold view all marking.
        if ($user->hasRole('supervisor') || $user->hasAnyRole(self::SALES_MARKING_SCOPE_ROLES)) {
            return false;
        }

        return $user->can('view all marking')
            || $user->hasAnyRole(self::GLOBAL_VIEW_ROLES);
    }

    public static function userHasSalesMarkingScope(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null
            && ! static::userCanViewAll($user)
            && $user->hasAnyRole(self::SALES_MARKING_SCOPE_ROLES);
    }

    public static function userHasSupervisorMarkingScope(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null
            && ! static::userCanViewAll($user)
            && $user->hasRole('supervisor');
    }

    /** Sales or supervisor limited to own team (self + bawahan for supervisors). */
    public static function userHasTeamMarkingScope(?User $user = null): bool
    {
        return static::userHasSalesMarkingScope($user)
            || static::userHasSupervisorMarkingScope($user);
    }

    /** @return list<int> */
    public static function teamScopeUserIds(?User $user = null): array
    {
        $user ??= auth()->user();
        if ($user === null) {
            return [];
        }

        if (static::userHasSupervisorMarkingScope($user)) {
            return $user->portalScopeUserIds();
        }

        if (static::userHasSalesMarkingScope($user)) {
            return [(int) $user->id];
        }

        return [];
    }

    public function scopeVisibleToUser($query, ?User $user = null)
    {
        $user ??= auth()->user();

        if ($user === null || static::userCanViewAll($user)) {
            return $query;
        }

        if (static::userHasSupervisorMarkingScope($user)) {
            return static::applySupervisorMarkingScope($query, $user);
        }

        if (static::userHasSalesMarkingScope($user)) {
            return $query->where('sales_id', $user->id);
        }

        return $query;
    }

    public function isVisibleToUser(?User $user = null): bool
    {
        $user ??= auth()->user();

        if ($user === null) {
            return false;
        }

        if (static::userCanViewAll($user)) {
            return true;
        }

        if (static::userHasSupervisorMarkingScope($user)) {
            $salesIds = $user->portalScopeUserIds();

            return in_array((int) $this->sales_id, $salesIds, true)
                || (int) $this->user_id === (int) $user->id;
        }

        if (static::userHasSalesMarkingScope($user)) {
            return (int) $this->sales_id === (int) $user->id;
        }

        return true;
    }

    public static function barangVisibleToUser(Barang $barang, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if ($user === null) {
            return false;
        }

        if (! static::userHasTeamMarkingScope($user)) {
            return true;
        }

        $query = Barang::query()->whereKey($barang->id);

        return static::restrictBarangsQuery($query, $user)->exists();
    }

    public static function invoiceVisibleToUser(Invoice $invoice, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if ($user === null) {
            return false;
        }

        if (! static::userHasTeamMarkingScope($user)) {
            return true;
        }

        $query = Invoice::query()->whereKey($invoice->id);

        return static::restrictInvoicesQuery($query, $user)->exists();
    }

    /** @param  Builder<Invoice>  $invoiceQuery */
    public static function restrictInvoicesQuery(Builder $invoiceQuery, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if ($user === null || ! static::userHasTeamMarkingScope($user)) {
            return $invoiceQuery;
        }

        return $invoiceQuery->whereHas(
            'items.barang',
            static fn (Builder $barangQuery) => static::restrictBarangsQuery($barangQuery, $user),
        );
    }

    /** @param  Builder<self>  $query */
    private static function applySupervisorMarkingScope(Builder $query, User $user): Builder
    {
        $salesIds = $user->portalScopeUserIds();

        return $query->where(function (Builder $scope) use ($user, $salesIds): void {
            $scope->whereIn('sales_id', $salesIds)
                ->orWhere('user_id', $user->id);
        });
    }

    public function dropdownLabel(): string
    {
        $name = trim((string) ($this->customer_name ?? $this->customer?->name ?? ''));
        $code = $this->displayCode();

        return $name === '' ? $code : "{$code} — {$name}";
    }

    public function displayCode(): string
    {
        $col = self::codeColumn();
        if ($col !== null) {
            return (string) ($this->getAttribute($col) ?? '');
        }

        return (string) $this->getKey();
    }

    /**
     * Cari record marking dari nilai barangs.marking / customer_name / nama user.
     */
    public static function findForBarangValue(string $value, ?string $customerName = null): ?self
    {
        foreach (array_unique(array_filter([
            trim($value),
            trim((string) $customerName),
        ], static fn (string $needle) => $needle !== '')) as $needle) {
            $marking = self::query()->whereBarangMarkingValue($needle)->first();
            if ($marking !== null) {
                return $marking;
            }

            if (self::hasColumnCached('markings', 'customer_name')) {
                $marking = self::query()
                    ->where('customer_name', $needle)
                    ->first()
                    ?? self::query()
                        ->whereRaw('LOWER(TRIM(customer_name)) = ?', [mb_strtolower($needle)])
                        ->first();
                if ($marking !== null) {
                    return $marking;
                }
            }

            $marking = self::query()
                ->whereHas('customer', static function (Builder $q) use ($needle): void {
                    $q->where('name', $needle)
                        ->orWhereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($needle)]);
                    if (self::hasColumnCached('users', 'marking_code')) {
                        $q->orWhere('marking_code', $needle);
                    }
                })
                ->first();
            if ($marking !== null) {
                return $marking;
            }
        }

        return null;
    }

    /**
     * Batch lookup markings (with sales) by barang marking values — avoids N+1 on list pages.
     *
     * @param  iterable<string|null>  $barangMarkingValues
     * @return Collection<string, self>
     */
    public static function lookupForBarangValues(iterable $barangMarkingValues): Collection
    {
        $codes = collect($barangMarkingValues)
            ->map(static fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        $codeCol = self::codeColumn();
        if ($codeCol === null) {
            return collect();
        }

        return self::query()
            ->with('sales:id,name')
            ->whereIn($codeCol, $codes)
            ->get()
            ->keyBy(static fn (self $m) => $m->displayCode());
    }

    /**
     * Resolve kode marking hanya dari nilai barangs.marking (kolom code).
     * Tidak mencocokkan customer_name — dipakai pengelompokan surat jalan.
     */
    public static function resolveCodeFromBarangMarkingOnly(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $marking = self::query()->whereBarangMarkingValue($value)->first();
        if ($marking !== null) {
            return $marking->displayCode();
        }

        return $value;
    }

    /** Nomor/kode marking untuk tampilan (bukan nama customer). */
    public static function resolvedCodeForBarangValue(string $value, ?string $customerName = null): string
    {
        $marking = self::findForBarangValue($value, $customerName);
        if ($marking !== null) {
            return $marking->displayCode();
        }

        $value = trim($value);
        if ($value !== '' && self::query()->whereBarangMarkingValue($value)->exists()) {
            return $value;
        }

        return '';
    }

    /** @deprecated Use {@see resolvedCodeForBarangValue()} */
    public static function displayCodeForBarangValue(string $value): string
    {
        return self::resolvedCodeForBarangValue($value);
    }

    /**
     * Resolve marking for customer portal login (marking code + passcode).
     */
    public static function findForCustomerLogin(string $loginCode, string $passcode): ?self
    {
        $loginCode = trim($loginCode);
        $passcode = trim($passcode);
        if ($loginCode === '' || $passcode === '') {
            return null;
        }

        $codeCol = self::codeColumn();
        if ($codeCol === null) {
            return null;
        }

        $marking = self::queryForCustomerLogin($codeCol, $loginCode)->first();

        if ($marking === null) {
            $marking = self::queryForCustomerLogin($codeCol, $loginCode, caseInsensitive: true)->first();
        }

        if ($marking === null) {
            return null;
        }

        if (! hash_equals((string) $marking->passcode, $passcode)) {
            return null;
        }

        $user = $marking->ensureCustomerUser();
        if ($user === null || ! $user->hasRole('customer')) {
            return null;
        }

        return $marking->fresh(['customer.roles']);
    }

    /**
     * Link or create a customer user so marking code + passcode login works.
     */
    public function ensureCustomerUser(): ?User
    {
        $existing = $this->customer;
        if ($existing !== null) {
            if (! $existing->hasRole('customer')) {
                $existing->assignRole('customer');
            }

            $code = $this->displayCode();
            if ($code !== '' && (string) ($existing->marking_code ?? '') === '') {
                $existing->forceFill(['marking_code' => $code])->saveQuietly();
            }

            if ((int) $this->user_id !== (int) $existing->id) {
                $this->forceFill(['user_id' => $existing->id])->saveQuietly();
            }

            return $existing;
        }

        $code = $this->displayCode();
        if ($code === '') {
            return null;
        }

        $baseUsername = self::loginUsernameFromCode($code);
        $user = User::query()
            ->where(function (Builder $query) use ($code, $baseUsername): void {
                $query->where('marking_code', $code)
                    ->orWhere('username', $baseUsername);
            })
            ->first();

        if ($user === null) {
            $user = User::create([
                'name' => trim((string) ($this->customer_name ?? '')) !== ''
                    ? (string) $this->customer_name
                    : $code,
                'username' => self::allocateLoginUsername($code),
                'email' => $this->email ?: null,
                'phone' => $this->phone_hp ?: null,
                'password' => Hash::make(Str::random(32)),
                'marking_code' => $code,
                'is_marking_portal' => true,
            ]);
        } elseif (! $user->is_marking_portal) {
            $user->forceFill(['is_marking_portal' => true])->saveQuietly();
        }

        if (! $user->hasRole('customer')) {
            $user->assignRole('customer');
        }

        if ((string) ($user->marking_code ?? '') === '') {
            $user->forceFill(['marking_code' => $code])->saveQuietly();
        }

        $this->forceFill(['user_id' => $user->id])->saveQuietly();

        return $user;
    }

    public static function loginUsernameFromCode(string $code): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', trim($code)));
        $base = trim($base, '_');

        return $base !== '' ? substr($base, 0, 50) : 'marking';
    }

    private static function allocateLoginUsername(string $code): string
    {
        $base = self::loginUsernameFromCode($code);
        $candidate = $base;
        $suffix = 1;

        while (User::query()->where('username', $candidate)->exists()) {
            $suffix++;
            $candidate = substr($base, 0, 45).'_'.$suffix;
        }

        return $candidate;
    }

    private static function queryForCustomerLogin(
        string $codeCol,
        string $loginCode,
        bool $caseInsensitive = false,
    ): Builder {
        $query = self::query()
            ->with(['customer.roles'])
            ->whereNotNull('passcode');

        if ($caseInsensitive) {
            $query->whereRaw('LOWER(`'.$codeCol.'`) = ?', [mb_strtolower($loginCode)]);
        } else {
            $query->where($codeCol, $loginCode);
        }

        if (self::hasColumnCached('markings', 'is_active')) {
            $query->active();
        }

        return $query;
    }

    public function ktpPhotoUrl(): ?string
    {
        return \App\Support\PublicStorageUrl::url($this->ktp_photo_path);
    }

    public function normalizedKtpPhotoPath(): ?string
    {
        if (! $this->ktp_photo_path) {
            return null;
        }

        $path = str_replace('\\', '/', trim($this->ktp_photo_path));
        $path = ltrim($path, '/');

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'public/')) {
            $path = substr($path, 7);
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        return $path;
    }

    protected static function booted(): void
    {
        static::deleting(function (Marking $marking): void {
            if ($marking->ktp_photo_path) {
                Storage::disk('public')->delete($marking->ktp_photo_path);
            }
        });
    }

    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    public function relatedMarking(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_marking_id');
    }

    public function relatedMarkings(): HasMany
    {
        return $this->hasMany(self::class, 'related_marking_id');
    }

    /** Hub marking id for customer portal cluster (self or parent when this is a related marking). */
    public function portalClusterHubId(): int
    {
        return (int) ($this->related_marking_id ?? $this->id);
    }

    /** All markings in the same relation cluster (hub + direct related children). */
    public function portalClusterMarkings(): \Illuminate\Support\Collection
    {
        $hubId = $this->portalClusterHubId();

        return static::query()
            ->where(fn (Builder $q) => $q->whereKey($hubId)->orWhere('related_marking_id', $hubId))
            ->orderBy('id')
            ->get();
    }

    /** Keep linked markings on the same customer user (hub ↔ children). */
    public function syncRelationClusterUser(): void
    {
        if ($this->related_marking_id) {
            $hub = static::query()->find($this->related_marking_id);
            if ($hub !== null && $hub->user_id !== null && (int) $this->user_id !== (int) $hub->user_id) {
                $this->forceFill(['user_id' => $hub->user_id])->saveQuietly();
            }
        }

        $hubId = $this->portalClusterHubId();
        $hub = static::query()->find($hubId);
        if ($hub === null || $hub->user_id === null) {
            return;
        }

        static::query()
            ->where(fn (Builder $q) => $q->whereKey($hubId)->orWhere('related_marking_id', $hubId))
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $hub->user_id))
            ->update(['user_id' => $hub->user_id]);
    }

    public function sales(): BelongsTo { return $this->belongsTo(User::class, 'sales_id'); }

    public function branch(): BelongsTo { return $this->belongsTo(MasterBranch::class, 'branch_id'); }

    public function pricings(): HasMany { return $this->hasMany(MarkingPricing::class); }

    /** Calendar date used when deciding which marking pricing row is effective. */
    public static function pricingAsOfDateString(): string
    {
        return Carbon::today()->toDateString();
    }

    public static function effectiveStartDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $date = substr((string) $value, 0, 10);

        return $date !== '' ? $date : null;
    }

    public function markingEffectiveStartDateString(): ?string
    {
        return self::effectiveStartDateString($this->effective_start);
    }

    public function isPricingEffectiveAsOf(?string $today = null): bool
    {
        $today ??= self::pricingAsOfDateString();
        $start = $this->markingEffectiveStartDateString();

        return $start === null || $start <= $today;
    }

    /** First pricing row when marking-level effective date applies (fallback display). */
    public function currentEffectivePricing(): ?MarkingPricing
    {
        if (! $this->isPricingEffectiveAsOf()) {
            return null;
        }

        return $this->pricings
            ->sort(fn (MarkingPricing $a, MarkingPricing $b) => $b->id <=> $a->id)
            ->first();
    }

    public function barangs(): HasMany
    {
        if (self::hasColumnCached('barangs', 'marking_id')) {
            return $this->hasMany(Barang::class, 'marking_id');
        }

        $codeCol = self::codeColumn();
        if ($codeCol !== null && self::hasColumnCached('barangs', 'marking')) {
            return $this->hasMany(Barang::class, 'marking', $codeCol);
        }

        return $this->hasMany(Barang::class, 'marking_id')->whereRaw('0 = 1');
    }

    /** @return list<string> */
    public static function dropdownSelectColumns(): array
    {
        $columns = ['id', 'customer_name', 'user_id'];
        if ($col = self::codeColumn()) {
            $columns[] = $col;
        }

        return $columns;
    }

    public function scopeWhereBarangMarkingValue(Builder $query, string $value): Builder
    {
        if ($col = self::codeColumn()) {
            return $query->where($col, $value);
        }

        if (ctype_digit($value)) {
            return $query->whereKey((int) $value);
        }

        return $query->whereRaw('0 = 1');
    }

    /** Limit barang queries to markings visible to the user (sales scope). */
    public static function restrictBarangsQuery(Builder $barangQuery, ?User $user = null): Builder
    {
        $markingQuery = static::visibleToUser($user);

        if (self::hasColumnCached('barangs', 'marking_id')) {
            return $barangQuery->whereIn('marking_id', $markingQuery->select('id'));
        }

        if ($col = self::codeColumn()) {
            return $barangQuery->whereIn('marking', $markingQuery->select($col));
        }

        return $barangQuery->whereRaw('0 = 1');
    }

    /** @return list<string> */
    public static function periodFilterValues(): array
    {
        return \App\Support\DatePresetFilter::presetValues();
    }

    /**
     * Filter by last shipping date only (never = no shipping activity).
     * Must be applied after {@see scopeWithLastShippingAt} when selecting last_shipping_at.
     */
    public function scopeFilterByPeriod(Builder $query, ?string $period, ?string $from = null, ?string $to = null): Builder
    {
        $period = \App\Support\DatePresetFilter::normalizePreset($period);
        if ($period === '' || ! in_array($period, self::periodFilterValues(), true)) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $lastShipSql = self::lastShippingAtSubquery($table);

        [$start, $end] = self::resolvePeriodBounds($period, $from, $to);
        if ($start === null && $end === null) {
            return $query;
        }

        if ($start !== null && $end !== null) {
            return $query->whereRaw(
                "{$lastShipSql} BETWEEN ? AND ?",
                [$start->toDateString(), $end->toDateString()],
            );
        }

        if ($start !== null) {
            return $query->whereRaw("{$lastShipSql} >= ?", [$start->toDateString()]);
        }

        return $query->whereRaw("{$lastShipSql} <= ?", [$end->toDateString()]);
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    public static function resolvePeriodBounds(string $period, ?string $from = null, ?string $to = null): array
    {
        return \App\Support\DatePresetFilter::resolveBounds($period, $from, $to);
    }

    /**
     * Latest shipping-related date for this marking (max across barang entry, container load, tracking).
     */
    public function scopeWithLastShippingAt(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        if ($query->getQuery()->columns === null) {
            $query->select("{$table}.*");
        }

        return $query->selectRaw(self::lastShippingAtSubquery($table) . ' AS last_shipping_at');
    }

    public static function hasColumnCached(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";

        return self::$schemaColumnCache[$key] ??= Schema::hasColumn($table, $column);
    }

    public static function hasTableCached(string $table): bool
    {
        $key = "@table.{$table}";

        return self::$schemaColumnCache[$key] ??= Schema::hasTable($table);
    }

    /** markings column used as barang code / display (null if none). */
    public static function codeColumn(): ?string
    {
        foreach (['code', 'marking_code'] as $column) {
            if (self::hasColumnCached('markings', $column)) {
                return $column;
            }
        }

        return null;
    }

    /** Map request `code` to the actual DB column; strip when no code column exists. */
    public static function prepareAttributesForSave(array $data): array
    {
        if (! self::hasColumnCached('markings', 'related_marking_id')) {
            unset($data['related_marking_id']);
        }

        $codeCol = self::codeColumn();
        if ($codeCol === null) {
            unset($data['code']);

            return $data;
        }

        if (! array_key_exists('code', $data)) {
            return $data;
        }

        if ($codeCol !== 'code') {
            $data[$codeCol] = $data['code'];
            unset($data['code']);
        }

        return $data;
    }

    /** WHERE fragment linking barangs row to outer markings row, or null if impossible. */
    public static function barangToMarkingWhereSql(string $markingsTable, string $barangAlias = 'b'): ?string
    {
        if (self::hasColumnCached('barangs', 'marking_id')) {
            return "{$barangAlias}.marking_id = {$markingsTable}.id";
        }

        $codeCol = self::codeColumn();
        if ($codeCol !== null && self::hasColumnCached('barangs', 'marking')) {
            return "{$barangAlias}.marking = {$markingsTable}.{$codeCol}";
        }

        return null;
    }

    public static function lastShippingAtSubquery(string $table = 'markings'): string
    {
        $barangLink = self::barangToMarkingWhereSql($table);
        $sentinel = "'1970-01-01'";
        $branches = [];

        if ($barangLink !== null && self::hasColumnCached('barangs', 'tanggal_masuk')) {
            $branches[] = "COALESCE((SELECT MAX(b.tanggal_masuk) FROM barangs b WHERE {$barangLink} AND b.tanggal_masuk IS NOT NULL), {$sentinel})";
        }

        if (
            $barangLink !== null
            && self::hasTableCached('container_barangs')
            && self::hasColumnCached('container_barangs', 'loaded_at')
        ) {
            $branches[] = "COALESCE((SELECT MAX(DATE(cb.loaded_at)) FROM barangs b INNER JOIN container_barangs cb ON cb.barang_id = b.id WHERE {$barangLink} AND cb.loaded_at IS NOT NULL), {$sentinel})";
        }

        if (
            $barangLink !== null
            && self::hasTableCached('container_barangs')
            && self::hasTableCached('containers')
            && self::hasColumnCached('containers', 'load_date')
        ) {
            $branches[] = "COALESCE((SELECT MAX(c.load_date) FROM barangs b INNER JOIN container_barangs cb ON cb.barang_id = b.id INNER JOIN containers c ON c.id = cb.container_id WHERE {$barangLink} AND c.load_date IS NOT NULL), {$sentinel})";
        }

        if (self::hasColumnCached('containers', 'marking_id') && self::hasColumnCached('containers', 'load_date')) {
            $branches[] = "COALESCE((SELECT MAX(c.load_date) FROM containers c WHERE c.marking_id = {$table}.id AND c.load_date IS NOT NULL), {$sentinel})";
        }

        if (
            $barangLink !== null
            && self::hasTableCached('tracking_statuses')
            && self::hasColumnCached('tracking_statuses', 'barang_id')
        ) {
            $branches[] = "COALESCE((SELECT MAX(DATE(ts.created_at)) FROM barangs b INNER JOIN tracking_statuses ts ON ts.barang_id = b.id WHERE {$barangLink}), {$sentinel})";
        }

        if ($branches === []) {
            return '(SELECT NULL)';
        }

        if (count($branches) === 1) {
            return 'NULLIF(' . $branches[0] . ", {$sentinel})";
        }

        return 'NULLIF(GREATEST(' . implode(', ', $branches) . "), {$sentinel})";
    }
}
