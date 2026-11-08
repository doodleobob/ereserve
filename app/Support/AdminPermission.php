<?php

namespace App\Support;

/** Fixed capability identifiers available for restricting Barangay Admin accounts. */
enum AdminPermission: string
{
    case RESERVATIONS_VIEW = 'reservations.view';
    case RESERVATIONS_ACCEPT = 'reservations.accept';
    case RESERVATIONS_MANAGE = 'reservations.manage';
    case PAYMENTS_VIEW = 'payments.view';
    case PAYMENTS_MANAGE = 'payments.manage';
    case FACILITIES_VIEW = 'facilities.view';
    case FACILITIES_MANAGE = 'facilities.manage';
    case OFFICIAL_USES_MANAGE = 'official_uses.manage';
    case RESOURCE_USAGE_VIEW = 'resource_usage.view';
    case RESOURCE_USAGE_OPERATE = 'resource_usage.operate';
    case EQUIPMENT_CHARGES_MANAGE = 'equipment_charges.manage';
    case INVENTORY_VIEW = 'inventory.view';
    case INVENTORY_ADJUST = 'inventory.adjust';
    case RESIDENTS_VIEW = 'residents.view';
    case RESIDENTS_MANAGE = 'residents.manage';
    case ANALYTICS_VIEW = 'analytics.view';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Capabilities with live centralized checks that the editor can safely restrict. */
    public static function individuallyRestrictable(): array
    {
        return [
            self::RESERVATIONS_VIEW,
            self::RESERVATIONS_ACCEPT,
            self::RESERVATIONS_MANAGE,
            self::PAYMENTS_VIEW,
            self::PAYMENTS_MANAGE,
            self::FACILITIES_VIEW,
            self::FACILITIES_MANAGE,
            self::OFFICIAL_USES_MANAGE,
            self::RESOURCE_USAGE_VIEW,
            self::RESOURCE_USAGE_OPERATE,
            self::EQUIPMENT_CHARGES_MANAGE,
            self::INVENTORY_VIEW,
            self::INVENTORY_ADJUST,
            self::RESIDENTS_VIEW,
            self::RESIDENTS_MANAGE,
            self::ANALYTICS_VIEW,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::RESERVATIONS_VIEW => 'View reservations',
            self::RESERVATIONS_ACCEPT => 'Accept or reject reservations',
            self::RESERVATIONS_MANAGE => 'Edit, reschedule, or cancel reservations',
            self::PAYMENTS_VIEW => 'View reservation payment records',
            self::PAYMENTS_MANAGE => 'Record or update reservation payments',
            self::FACILITIES_VIEW => 'View facilities and equipment',
            self::RESOURCE_USAGE_VIEW => 'View resource usage records',
            self::RESOURCE_USAGE_OPERATE => 'Release equipment and record returns',
            self::EQUIPMENT_CHARGES_MANAGE => 'Manage equipment charges and charge payments',
            self::INVENTORY_VIEW => 'View equipment inventory and adjustments',
            self::INVENTORY_ADJUST => 'Adjust equipment inventory',
            self::RESIDENTS_VIEW => 'View residents',
            self::RESIDENTS_MANAGE => 'Activate or deactivate residents',
            self::FACILITIES_MANAGE => 'Manage facilities and equipment',
            self::OFFICIAL_USES_MANAGE => 'Manage Official Use schedules',
            self::ANALYTICS_VIEW => 'View analytics',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RESERVATIONS_VIEW => 'Open reservation management lists and details.',
            self::RESERVATIONS_ACCEPT => 'Make decisions on pending reservations.',
            self::RESERVATIONS_MANAGE => 'Change accepted reservation schedules or cancel reservations.',
            self::PAYMENTS_VIEW => 'Open centralized reservation payment records and exports.',
            self::PAYMENTS_MANAGE => 'Record or update a reservation payment.',
            self::RESOURCE_USAGE_VIEW => 'View resource usage and release/return history.',
            self::RESOURCE_USAGE_OPERATE => 'Perform equipment release and return operations.',
            self::EQUIPMENT_CHARGES_MANAGE => 'Manage equipment charges and record their payments.',
            self::INVENTORY_VIEW => 'View inventory adjustment history and inventory details.',
            self::INVENTORY_ADJUST => 'Record an equipment inventory adjustment.',
            self::RESIDENTS_VIEW => 'Open the resident management list and details.',
            self::RESIDENTS_MANAGE => 'Activate or deactivate resident accounts.',
            self::FACILITIES_VIEW => 'Open facility and equipment catalogs and details.',
            self::FACILITIES_MANAGE => 'Create, edit, or remove facility and equipment records. Inventory adjustments remain separately permission-controlled.',
            self::OFFICIAL_USES_MANAGE => 'Open, create, and edit Official Use schedules in the assigned barangay.',
            self::ANALYTICS_VIEW => 'Open analytics and reports for the assigned barangay.',
        };
    }
}
