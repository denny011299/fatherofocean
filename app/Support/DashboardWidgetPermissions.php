<?php

declare(strict_types=1);

namespace App\Support;

final class DashboardWidgetPermissions
{
    public const ACTIVE_SHIPMENTS = 'view dashboard widget active shipments';

    public const MASTER_MARKING = 'view dashboard widget master marking';

    public const INVOICES = 'view dashboard widget invoices';

    public const SALES_REVENUE = 'view dashboard widget sales revenue';

    public const COMPANY_VALUE = 'view dashboard widget company value';

    public const OMSET = 'view dashboard widget omset';

    public const TOTAL_CBM = 'view dashboard widget total cbm';

    public const SHIPMENT_CHART = 'view dashboard widget shipment chart';

    public const FEEDBACK = 'view dashboard widget feedback';

    public const DAILY_TASK = 'view dashboard widget daily task';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::OMSET,
            self::TOTAL_CBM,
            self::SHIPMENT_CHART,
            self::ACTIVE_SHIPMENTS,
            self::MASTER_MARKING,
            self::INVOICES,
            self::SALES_REVENUE,
            self::COMPANY_VALUE,
            self::FEEDBACK,
            self::DAILY_TASK,
        ];
    }

    /** @return list<string> */
    public static function defaultAdminStaff(): array
    {
        return [
            self::ACTIVE_SHIPMENTS,
            self::MASTER_MARKING,
            self::INVOICES,
            self::OMSET,
            self::SHIPMENT_CHART,
            self::FEEDBACK,
            self::DAILY_TASK,
        ];
    }

    /** @return list<string> */
    public static function defaultSales(): array
    {
        return [
            self::ACTIVE_SHIPMENTS,
            self::MASTER_MARKING,
            self::INVOICES,
            self::OMSET,
            self::TOTAL_CBM,
            self::SALES_REVENUE,
            self::SHIPMENT_CHART,
            self::FEEDBACK,
            self::DAILY_TASK,
        ];
    }

    /** @return list<string> */
    public static function defaultGudangChina(): array
    {
        return [
            self::ACTIVE_SHIPMENTS,
            self::MASTER_MARKING,
            self::SHIPMENT_CHART,
            self::DAILY_TASK,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function visibilityFor(?\App\Models\User $user): array
    {
        return [
            'active_shipments' => $user?->can(self::ACTIVE_SHIPMENTS) ?? false,
            'master_marking' => $user?->can(self::MASTER_MARKING) ?? false,
            'invoices' => $user?->can(self::INVOICES) ?? false,
            'sales_revenue' => $user?->can(self::SALES_REVENUE) ?? false,
            'company_value' => $user?->can(self::COMPANY_VALUE) ?? false,
            'omset' => $user?->can(self::OMSET) ?? false,
            'total_cbm' => $user?->can(self::TOTAL_CBM) ?? false,
            'shipment_chart' => $user?->can(self::SHIPMENT_CHART) ?? false,
            'feedback' => $user?->can(self::FEEDBACK) ?? false,
            'daily_task' => $user?->can(self::DAILY_TASK) ?? false,
        ];
    }
}
