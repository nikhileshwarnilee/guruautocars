<?php
declare(strict_types=1);

function safe_delete_generic_entity_configs(): array
{
    static $configs = null;
    if (is_array($configs)) {
        return $configs;
    }

    $configs = [];

    $configs['org_company'] = [
        'label' => 'Company',
        'operation' => 'delete',
        'sql' => 'SELECT c.id, c.name, c.legal_name, c.city, c.state, c.status_code,
                         (SELECT COUNT(*) FROM garages g WHERE g.company_id = c.id AND g.status_code <> "DELETED") AS garage_count,
                         (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.status_code <> "DELETED") AS staff_count
                  FROM companies c
                  WHERE c.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['name', 'legal_name'],
        'reference_fallback_prefix' => 'COMP',
        'label_prefix' => 'Company ',
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $city = trim((string) ($row['city'] ?? ''));
            $state = trim((string) ($row['state'] ?? ''));
            $place = trim($city . ($city !== '' && $state !== '' ? ', ' : '') . $state);
            return $place !== '' ? $place : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Company is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
        'postprocess' => static function (PDO $pdo, array $row, int $recordId, array $scope, array &$summary): void {
            $garageCount = max(0, (int) ($row['garage_count'] ?? 0));
            $staffCount = max(0, (int) ($row['staff_count'] ?? 0));
            $summary['groups'][] = safe_delete_make_group('garages', 'Garages', $garageCount, [
                safe_delete_make_item('Active Garages', null, null, $garageCount > 0 ? 'LINKED' : 'NONE')
            ], 0.0);
            $summary['groups'][] = safe_delete_make_group('staff', 'Staff Users', $staffCount, [
                safe_delete_make_item('Active Staff', null, null, $staffCount > 0 ? 'LINKED' : 'NONE')
            ], 0.0);
            if ($garageCount > 0 || $staffCount > 0) {
                $summary['warnings'][] = 'Company has linked garages/staff. Deletion may be restricted by existing workflow permissions and scope.';
            }
        },
    ];

    $configs['org_garage'] = [
        'label' => 'Garage',
        'operation' => 'delete',
        'sql' => 'SELECT g.id, g.company_id, g.name, g.code, g.city, g.state, g.status_code, c.name AS company_name
                  FROM garages g
                  LEFT JOIN companies c ON c.id = g.company_id
                  WHERE g.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['code', 'name'],
        'reference_fallback_prefix' => 'GAR',
        'label_prefix' => 'Garage ',
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['company_name'] ?? '')) !== '') {
                $parts[] = (string) $row['company_name'];
            }
            $place = trim((string) ($row['city'] ?? '') . ((string) ($row['city'] ?? '') !== '' && (string) ($row['state'] ?? '') !== '' ? ', ' : '') . (string) ($row['state'] ?? ''));
            if ($place !== '') {
                $parts[] = $place;
            }
            return $parts !== [] ? implode(' | ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Garage is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['org_staff_user'] = [
        'label' => 'Staff User',
        'operation' => 'delete',
        'sql' => 'SELECT u.id, u.company_id, u.primary_garage_id, u.name, u.username, u.email, u.status_code, u.is_active,
                         c.name AS company_name, g.name AS garage_name, r.role_name
                  FROM users u
                  LEFT JOIN companies c ON c.id = u.company_id
                  LEFT JOIN garages g ON g.id = u.primary_garage_id
                  LEFT JOIN roles r ON r.id = u.role_id
                  WHERE u.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['username', 'name', 'email'],
        'reference_fallback_prefix' => 'USR',
        'label_prefix' => 'User ',
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['name'] ?? '')) !== '') {
                $parts[] = (string) $row['name'];
            }
            if (trim((string) ($row['role_name'] ?? '')) !== '') {
                $parts[] = 'Role: ' . (string) $row['role_name'];
            }
            if (trim((string) ($row['garage_name'] ?? '')) !== '') {
                $parts[] = 'Garage: ' . (string) $row['garage_name'];
            }
            return $parts !== [] ? implode(' | ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Staff user is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['role'] = [
        'label' => 'Role',
        'operation' => 'delete',
        'sql' => 'SELECT r.id, r.role_key, r.role_name, r.status_code,
                         (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
                         (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
                  FROM roles r
                  WHERE r.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['role_name', 'role_key'],
        'reference_fallback_prefix' => 'ROLE',
        'label_prefix' => 'Role ',
        'status_columns' => ['status_code'],
        'note' => static fn (array $row): ?string => trim((string) ($row['role_key'] ?? '')) !== '' ? ('Key: ' . (string) $row['role_key']) : null,
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Role is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
        'postprocess' => static function (PDO $pdo, array $row, int $recordId, array $scope, array &$summary): void {
            $userCount = max(0, (int) ($row['user_count'] ?? 0));
            $permCount = max(0, (int) ($row['permission_count'] ?? 0));
            $summary['groups'][] = safe_delete_make_group('users', 'Users', $userCount, [
                safe_delete_make_item('Assigned Users', null, null, $userCount > 0 ? 'LINKED' : 'NONE')
            ], 0.0);
            $summary['groups'][] = safe_delete_make_group('permissions', 'Role Permissions', $permCount, [
                safe_delete_make_item('Permission Links', null, null, $permCount > 0 ? 'LINKED' : 'NONE')
            ], 0.0);
            if (strtolower(trim((string) ($row['role_key'] ?? ''))) === 'super_admin') {
                $summary['blockers'][] = 'Super Admin role cannot be deactivated or deleted.';
            }
        },
    ];

    $configs['permission'] = [
        'label' => 'Permission',
        'operation' => 'delete',
        'sql' => 'SELECT p.id, p.perm_key, p.perm_name, p.status_code,
                         (SELECT COUNT(*) FROM role_permissions rp WHERE rp.permission_id = p.id) AS role_count
                  FROM permissions p
                  WHERE p.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['perm_key', 'perm_name'],
        'reference_fallback_prefix' => 'PERM',
        'label_prefix' => 'Permission ',
        'status_columns' => ['status_code'],
        'note' => static fn (array $row): ?string => trim((string) ($row['perm_name'] ?? '')) !== '' ? (string) $row['perm_name'] : null,
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Permission is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
        'postprocess' => static function (PDO $pdo, array $row, int $recordId, array $scope, array &$summary): void {
            $roleCount = max(0, (int) ($row['role_count'] ?? 0));
            $summary['groups'][] = safe_delete_make_group('roles', 'Assigned Roles', $roleCount, [
                safe_delete_make_item('Role Permission Mappings', null, null, $roleCount > 0 ? 'LINKED' : 'NONE')
            ], 0.0);
            $permKey = strtolower(trim((string) ($row['perm_key'] ?? '')));
            if (in_array($permKey, ['record.delete', 'financial.reverse', 'dependency.resolve'], true)) {
                $summary['blockers'][] = 'Protected global permission cannot be deleted. Set it INACTIVE only if required.';
            }
        },
    ];

    $configs['system_setting'] = [
        'label' => 'System Setting',
        'operation' => 'delete',
        'sql' => 'SELECT ss.id, ss.company_id, ss.garage_id, ss.setting_group, ss.setting_key, ss.value_type, ss.status_code, g.name AS garage_name
                  FROM system_settings ss
                  LEFT JOIN garages g ON g.id = ss.garage_id
                  WHERE ss.id = :id',
        'company_scope_column' => 'ss.company_id',
        'garage_scope_column' => null,
        'reference_columns' => ['setting_key'],
        'reference_fallback_prefix' => 'SET',
        'label_prefix' => 'Setting ',
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['setting_group'] ?? '')) !== '') {
                $parts[] = (string) $row['setting_group'];
            }
            if (trim((string) ($row['value_type'] ?? '')) !== '') {
                $parts[] = (string) $row['value_type'];
            }
            if (trim((string) ($row['garage_name'] ?? '')) !== '') {
                $parts[] = 'Garage: ' . (string) $row['garage_name'];
            }
            return $parts !== [] ? implode(' | ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Setting is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['financial_year'] = [
        'label' => 'Financial Year',
        'operation' => 'delete',
        'sql' => 'SELECT fy.id, fy.company_id, fy.fy_label, fy.start_date, fy.end_date, fy.status_code, c.name AS company_name
                  FROM financial_years fy
                  LEFT JOIN companies c ON c.id = fy.company_id
                  WHERE fy.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['fy_label'],
        'reference_fallback_prefix' => 'FY',
        'label_prefix' => 'Financial Year ',
        'date_columns' => ['start_date', 'end_date'],
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['company_name'] ?? '')) !== '') {
                $parts[] = (string) $row['company_name'];
            }
            if (trim((string) ($row['start_date'] ?? '')) !== '' || trim((string) ($row['end_date'] ?? '')) !== '') {
                $parts[] = trim((string) ($row['start_date'] ?? '') . ' to ' . (string) ($row['end_date'] ?? ''));
            }
            return $parts !== [] ? implode(' | ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Financial year is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['inventory_part_category'] = [
        'label' => 'Part Category',
        'operation' => 'delete',
        'sql' => 'SELECT pc.id, pc.company_id, pc.category_code, pc.category_name, pc.status_code,
                         (SELECT COUNT(*) FROM parts p WHERE p.category_id = pc.id AND p.company_id = pc.company_id AND p.status_code <> "DELETED") AS part_count
                  FROM part_categories pc
                  WHERE pc.id = :id',
        'company_scope_column' => 'pc.company_id',
        'garage_scope_column' => null,
        'reference_columns' => ['category_code', 'category_name'],
        'reference_fallback_prefix' => 'PCAT',
        'label_prefix' => 'Part Category ',
        'status_columns' => ['status_code'],
        'note' => static fn (array $row): ?string => trim((string) ($row['category_name'] ?? '')) !== '' ? (string) $row['category_name'] : null,
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Part category is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
        'postprocess' => static function (PDO $pdo, array $row, int $recordId, array $scope, array &$summary): void {
            $partCount = max(0, (int) ($row['part_count'] ?? 0));
            $summary['groups'][] = safe_delete_make_group('parts', 'Parts', $partCount, [
                safe_delete_make_item('Linked Parts', null, null, $partCount > 0 ? 'LINKED' : 'NONE')
            ], 0.0);
            if ($partCount > 0) {
                $summary['warnings'][] = 'Category has linked parts. Existing workflows may block hard removal and keep historical references.';
            }
        },
    ];

    $configs['inventory_part'] = [
        'label' => 'Part',
        'operation' => 'delete',
        'sql' => 'SELECT p.id, p.company_id, p.category_id, p.part_name, p.part_sku, p.status_code, p.selling_price, p.is_active,
                         pc.category_name
                  FROM parts p
                  LEFT JOIN part_categories pc ON pc.id = p.category_id
                  WHERE p.id = :id',
        'company_scope_column' => 'p.company_id',
        'garage_scope_column' => null,
        'reference_columns' => ['part_sku', 'part_name'],
        'reference_fallback_prefix' => 'PART',
        'label_prefix' => 'Part ',
        'status_columns' => ['status_code'],
        'amount_columns' => ['selling_price'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['part_name'] ?? '')) !== '') {
                $parts[] = (string) $row['part_name'];
            }
            if (trim((string) ($row['category_name'] ?? '')) !== '') {
                $parts[] = 'Category: ' . (string) $row['category_name'];
            }
            return $parts !== [] ? implode(' | ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Part is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['service_category_master'] = [
        'label' => 'Labour Category',
        'operation' => 'delete',
        'sql' => 'SELECT sc.id, sc.company_id, sc.category_code, sc.category_name, sc.status_code,
                         (SELECT COUNT(*) FROM services s WHERE s.company_id = sc.company_id AND s.category_id = sc.id AND s.status_code = "ACTIVE") AS active_service_count
                  FROM service_categories sc
                  WHERE sc.id = :id',
        'company_scope_column' => 'sc.company_id',
        'garage_scope_column' => null,
        'reference_columns' => ['category_code', 'category_name'],
        'reference_fallback_prefix' => 'SCAT',
        'label_prefix' => 'Labour Category ',
        'status_columns' => ['status_code'],
        'note' => static fn (array $row): ?string => trim((string) ($row['category_name'] ?? '')) !== '' ? (string) $row['category_name'] : null,
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Labour category is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
        'postprocess' => static function (PDO $pdo, array $row, int $recordId, array $scope, array &$summary): void {
            $count = max(0, (int) ($row['active_service_count'] ?? 0));
            $summary['groups'][] = safe_delete_make_group('services', 'Active Labour', $count, [
                safe_delete_make_item('Linked Active Labour', null, null, $count > 0 ? 'LINKED' : 'NONE')
            ], 0.0);
            if ($count > 0) {
                $summary['warnings'][] = 'Deleting this category will uncategorize linked services as per existing workflow.';
            }
        },
    ];

    $configs['service_master'] = [
        'label' => 'Labour',
        'operation' => 'delete',
        'sql' => 'SELECT s.id, s.company_id, s.category_id, s.service_code, s.service_name, s.status_code, s.default_rate, sc.category_name,
                         (SELECT COUNT(*) FROM vis_service_part_map m WHERE m.service_id = s.id AND m.status_code = "ACTIVE") AS mapped_parts
                  FROM services s
                  LEFT JOIN service_categories sc ON sc.id = s.category_id
                  WHERE s.id = :id',
        'company_scope_column' => 's.company_id',
        'garage_scope_column' => null,
        'reference_columns' => ['service_code', 'service_name'],
        'reference_fallback_prefix' => 'SRV',
        'label_prefix' => 'Labour ',
        'status_columns' => ['status_code'],
        'amount_columns' => ['default_rate'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['service_name'] ?? '')) !== '') {
                $parts[] = (string) $row['service_name'];
            }
            if (trim((string) ($row['category_name'] ?? '')) !== '') {
                $parts[] = 'Category: ' . (string) $row['category_name'];
            }
            return $parts !== [] ? implode(' | ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Labour is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
        'postprocess' => static function (PDO $pdo, array $row, int $recordId, array $scope, array &$summary): void {
            $count = max(0, (int) ($row['mapped_parts'] ?? 0));
            if ($count > 0) {
                $summary['groups'][] = safe_delete_make_group('service_part_map', 'VIS Labour-Part Maps', $count, [
                    safe_delete_make_item('Active Labour-Part Links', null, null, 'LINKED')
                ], 0.0);
                $summary['warnings'][] = 'Labour has active VIS service-part mappings.';
            }
        },
    ];

    $configs['vis_catalog_brand'] = [
        'label' => 'VIS Brand',
        'operation' => 'delete',
        'sql' => 'SELECT b.id, b.brand_name, b.status_code FROM vis_brands b WHERE b.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['brand_name'],
        'reference_fallback_prefix' => 'VISB',
        'label_prefix' => 'VIS Brand ',
        'status_columns' => ['status_code'],
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'VIS brand is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['vis_catalog_model'] = [
        'label' => 'VIS Model',
        'operation' => 'delete',
        'sql' => 'SELECT m.id, m.model_name, m.status_code, b.brand_name
                  FROM vis_models m
                  LEFT JOIN vis_brands b ON b.id = m.brand_id
                  WHERE m.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['model_name'],
        'reference_fallback_prefix' => 'VISM',
        'label_prefix' => 'VIS Model ',
        'status_columns' => ['status_code'],
        'note' => static fn (array $row): ?string => trim((string) ($row['brand_name'] ?? '')) !== '' ? ('Brand: ' . (string) $row['brand_name']) : null,
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'VIS model is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['vis_catalog_variant'] = [
        'label' => 'VIS Variant',
        'operation' => 'delete',
        'sql' => 'SELECT v.id, v.variant_name, v.status_code, m.model_name, b.brand_name
                  FROM vis_variants v
                  LEFT JOIN vis_models m ON m.id = v.model_id
                  LEFT JOIN vis_brands b ON b.id = m.brand_id
                  WHERE v.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['variant_name'],
        'reference_fallback_prefix' => 'VISV',
        'label_prefix' => 'VIS Variant ',
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['brand_name'] ?? '')) !== '') {
                $parts[] = (string) $row['brand_name'];
            }
            if (trim((string) ($row['model_name'] ?? '')) !== '') {
                $parts[] = (string) $row['model_name'];
            }
            return $parts !== [] ? implode(' / ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'VIS variant is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['vis_catalog_spec'] = [
        'label' => 'VIS Spec',
        'operation' => 'delete',
        'sql' => 'SELECT s.id, s.spec_key, s.spec_value, s.status_code, v.variant_name, m.model_name, b.brand_name
                  FROM vis_variant_specs s
                  LEFT JOIN vis_variants v ON v.id = s.variant_id
                  LEFT JOIN vis_models m ON m.id = v.model_id
                  LEFT JOIN vis_brands b ON b.id = m.brand_id
                  WHERE s.id = :id',
        'company_scope_column' => null,
        'garage_scope_column' => null,
        'reference_columns' => ['spec_key'],
        'reference_fallback_prefix' => 'VISS',
        'label_prefix' => 'VIS Spec ',
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['brand_name'] ?? '')) !== '') {
                $parts[] = (string) $row['brand_name'];
            }
            if (trim((string) ($row['model_name'] ?? '')) !== '') {
                $parts[] = (string) $row['model_name'];
            }
            if (trim((string) ($row['variant_name'] ?? '')) !== '') {
                $parts[] = (string) $row['variant_name'];
            }
            if (trim((string) ($row['spec_value'] ?? '')) !== '') {
                $parts[] = 'Value: ' . (string) $row['spec_value'];
            }
            return $parts !== [] ? implode(' | ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'VIS specification is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['vis_part_compatibility_map'] = [
        'label' => 'VIS Part Compatibility',
        'operation' => 'delete',
        'sql' => 'SELECT c.id, c.company_id, c.status_code, c.compatibility_note, p.part_name, p.part_sku, v.variant_name, m.model_name, b.brand_name
                  FROM vis_part_compatibility c
                  LEFT JOIN parts p ON p.id = c.part_id AND p.company_id = c.company_id
                  LEFT JOIN vis_variants v ON v.id = c.variant_id
                  LEFT JOIN vis_models m ON m.id = v.model_id
                  LEFT JOIN vis_brands b ON b.id = m.brand_id
                  WHERE c.id = :id',
        'company_scope_column' => 'c.company_id',
        'garage_scope_column' => null,
        'reference_columns' => ['part_sku', 'part_name'],
        'reference_fallback_prefix' => 'VCMP',
        'label_prefix' => 'Compatibility ',
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $variant = trim(implode(' / ', array_values(array_filter([
                trim((string) ($row['brand_name'] ?? '')),
                trim((string) ($row['model_name'] ?? '')),
                trim((string) ($row['variant_name'] ?? '')),
            ]))));
            $part = trim((string) ($row['part_name'] ?? ''));
            return trim(($part !== '' ? $part : '') . ($variant !== '' ? ' | ' . $variant : ''));
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Compatibility mapping is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['vis_service_part_map'] = [
        'label' => 'VIS Labour-Part Map',
        'operation' => 'delete',
        'sql' => 'SELECT sm.id, sm.company_id, sm.status_code, sm.is_required, s.service_name, s.service_code, p.part_name, p.part_sku
                  FROM vis_service_part_map sm
                  LEFT JOIN services s ON s.id = sm.service_id AND s.company_id = sm.company_id
                  LEFT JOIN parts p ON p.id = sm.part_id AND p.company_id = sm.company_id
                  WHERE sm.id = :id',
        'company_scope_column' => 'sm.company_id',
        'garage_scope_column' => null,
        'reference_columns' => ['service_code', 'service_name'],
        'reference_fallback_prefix' => 'VSMP',
        'label_prefix' => 'Labour-Part Map ',
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['service_name'] ?? '')) !== '') {
                $parts[] = 'Labour: ' . (string) $row['service_name'];
            }
            if (trim((string) ($row['part_name'] ?? '')) !== '') {
                $parts[] = 'Part: ' . (string) $row['part_name'];
            }
            $parts[] = (int) ($row['is_required'] ?? 0) === 1 ? 'Required' : 'Optional';
            return implode(' | ', $parts);
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Labour-part mapping is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['job_condition_photo'] = [
        'label' => 'Job Condition Photo',
        'operation' => 'delete',
        'sql' => 'SELECT p.id, p.company_id, p.garage_id, p.job_card_id, p.file_path, p.note, p.created_at, p.status_code,
                         jc.job_number
                  FROM job_condition_photos p
                  INNER JOIN job_cards jc ON jc.id = p.job_card_id
                  WHERE p.id = :id',
        'company_scope_column' => 'p.company_id',
        'garage_scope_column' => 'p.garage_id',
        'reference_columns' => ['file_path'],
        'reference_fallback_prefix' => 'PHOTO',
        'label_prefix' => 'Condition Photo ',
        'date_columns' => ['created_at'],
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['job_number'] ?? '')) !== '') {
                $parts[] = 'Job ' . (string) $row['job_number'];
            }
            if (trim((string) ($row['note'] ?? '')) !== '') {
                $parts[] = (string) $row['note'];
            }
            return $parts !== [] ? implode(' | ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Condition photo is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['job_insurance_document'] = [
        'label' => 'Job Insurance Document',
        'operation' => 'delete',
        'sql' => 'SELECT d.id, d.company_id, d.garage_id, d.job_card_id, d.file_name, d.file_path, d.note, d.created_at, d.status_code,
                         jc.job_number
                  FROM job_insurance_documents d
                  INNER JOIN job_cards jc ON jc.id = d.job_card_id
                  WHERE d.id = :id',
        'company_scope_column' => 'd.company_id',
        'garage_scope_column' => 'd.garage_id',
        'reference_columns' => ['file_name', 'file_path'],
        'reference_fallback_prefix' => 'IDOC',
        'label_prefix' => 'Insurance Document ',
        'date_columns' => ['created_at'],
        'status_columns' => ['status_code'],
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['job_number'] ?? '')) !== '') {
                $parts[] = 'Job ' . (string) $row['job_number'];
            }
            if (trim((string) ($row['note'] ?? '')) !== '') {
                $parts[] = (string) $row['note'];
            }
            return $parts !== [] ? implode(' | ', $parts) : null;
        },
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Insurance document is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['job_labor_line'] = [
        'label' => 'Job Labor Line',
        'operation' => 'delete',
        'sql' => 'SELECT jl.id, jl.job_card_id, jl.description, jl.total_amount, jl.execution_type, jl.outsource_cost, jl.outsource_payable_status,
                         jc.company_id, jc.garage_id, jc.job_number
                  FROM job_labor jl
                  INNER JOIN job_cards jc ON jc.id = jl.job_card_id
                  WHERE jl.id = :id',
        'company_scope_column' => 'jc.company_id',
        'garage_scope_column' => 'jc.garage_id',
        'reference_columns' => ['description'],
        'reference_fallback_prefix' => 'LAB',
        'label_prefix' => 'Labor Line ',
        'amount_columns' => ['total_amount'],
        'status_builder' => static fn (array $row): string => (string) (($row['execution_type'] ?? '') ?: 'LINE'),
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['job_number'] ?? '')) !== '') {
                $parts[] = 'Job ' . (string) $row['job_number'];
            }
            if (trim((string) ($row['execution_type'] ?? '')) !== '') {
                $parts[] = 'Type: ' . (string) $row['execution_type'];
            }
            if (strtoupper(trim((string) ($row['execution_type'] ?? ''))) === 'OUTSOURCED') {
                $parts[] = 'Payable: ' . (string) ($row['outsource_payable_status'] ?? 'UNPAID');
                $parts[] = 'Outsource Cost: ' . number_format((float) ($row['outsource_cost'] ?? 0), 2);
            }
            return implode(' | ', $parts);
        },
        'status_block_values' => [],
        'recommended_action' => 'DELETE_LINE',
        'execution_mode' => 'hard_delete',
    ];

    $configs['job_part_line'] = [
        'label' => 'Job Part Line',
        'operation' => 'delete',
        'sql' => 'SELECT jp.id, jp.job_card_id, jp.part_id, jp.quantity, jp.total_amount, jc.company_id, jc.garage_id, jc.job_number,
                         p.part_name, p.part_sku
                  FROM job_parts jp
                  INNER JOIN job_cards jc ON jc.id = jp.job_card_id
                  LEFT JOIN parts p ON p.id = jp.part_id
                  WHERE jp.id = :id',
        'company_scope_column' => 'jc.company_id',
        'garage_scope_column' => 'jc.garage_id',
        'reference_columns' => ['part_sku', 'part_name'],
        'reference_fallback_prefix' => 'JPART',
        'label_prefix' => 'Job Part Line ',
        'amount_columns' => ['total_amount'],
        'status_builder' => static fn (array $row): string => 'LINE',
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['job_number'] ?? '')) !== '') {
                $parts[] = 'Job ' . (string) $row['job_number'];
            }
            $parts[] = 'Qty: ' . number_format((float) ($row['quantity'] ?? 0), 2);
            return implode(' | ', $parts);
        },
        'status_block_values' => [],
        'recommended_action' => 'DELETE_LINE',
        'execution_mode' => 'hard_delete',
    ];

    $configs['return_attachment'] = [
        'label' => 'Return Attachment',
        'operation' => 'delete',
        'sql' => 'SELECT ra.id, ra.company_id, ra.garage_id, ra.return_id, ra.file_name, ra.file_path, ra.created_at, ra.status_code,
                         r.return_number
                  FROM return_attachments ra
                  INNER JOIN returns_rma r ON r.id = ra.return_id
                  WHERE ra.id = :id',
        'company_scope_column' => 'ra.company_id',
        'garage_scope_column' => 'ra.garage_id',
        'reference_columns' => ['file_name', 'file_path'],
        'reference_fallback_prefix' => 'RATT',
        'label_prefix' => 'Return Attachment ',
        'date_columns' => ['created_at'],
        'status_columns' => ['status_code'],
        'note' => static fn (array $row): ?string => trim((string) ($row['return_number'] ?? '')) !== '' ? ('Return ' . (string) $row['return_number']) : null,
        'status_block_values' => ['DELETED'],
        'status_block_message' => 'Return attachment is already deleted.',
        'recommended_action' => 'SOFT_DELETE',
        'execution_mode' => 'soft_delete',
    ];

    $configs['estimate_service_line'] = [
        'label' => 'Estimate Labour Line',
        'operation' => 'delete',
        'sql' => 'SELECT es.id, es.estimate_id, es.description, es.total_amount, es.quantity, es.unit_price, es.gst_rate,
                         e.company_id, e.garage_id, e.estimate_number, e.estimate_status, s.service_name
                  FROM estimate_services es
                  INNER JOIN estimates e ON e.id = es.estimate_id
                  LEFT JOIN services s ON s.id = es.service_id
                  WHERE es.id = :id',
        'company_scope_column' => 'e.company_id',
        'garage_scope_column' => 'e.garage_id',
        'reference_columns' => ['service_name', 'description'],
        'reference_fallback_prefix' => 'ESVC',
        'label_prefix' => 'Estimate Labour ',
        'amount_columns' => ['total_amount'],
        'status_builder' => static fn (array $row): string => (string) (($row['estimate_status'] ?? '') ?: 'LINE'),
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['estimate_number'] ?? '')) !== '') {
                $parts[] = 'Estimate ' . (string) $row['estimate_number'];
            }
            $parts[] = 'Qty: ' . number_format((float) ($row['quantity'] ?? 0), 2);
            $parts[] = 'Rate: ' . number_format((float) ($row['unit_price'] ?? 0), 2);
            return implode(' | ', $parts);
        },
        'status_block_values' => [],
        'recommended_action' => 'DELETE_LINE',
        'execution_mode' => 'hard_delete',
    ];

    $configs['estimate_part_line'] = [
        'label' => 'Estimate Part Line',
        'operation' => 'delete',
        'sql' => 'SELECT ep.id, ep.estimate_id, ep.quantity, ep.unit_price, ep.gst_rate, ep.total_amount,
                         e.company_id, e.garage_id, e.estimate_number, e.estimate_status, p.part_name, p.part_sku
                  FROM estimate_parts ep
                  INNER JOIN estimates e ON e.id = ep.estimate_id
                  LEFT JOIN parts p ON p.id = ep.part_id
                  WHERE ep.id = :id',
        'company_scope_column' => 'e.company_id',
        'garage_scope_column' => 'e.garage_id',
        'reference_columns' => ['part_sku', 'part_name'],
        'reference_fallback_prefix' => 'EPRT',
        'label_prefix' => 'Estimate Part ',
        'amount_columns' => ['total_amount'],
        'status_builder' => static fn (array $row): string => (string) (($row['estimate_status'] ?? '') ?: 'LINE'),
        'note' => static function (array $row): ?string {
            $parts = [];
            if (trim((string) ($row['estimate_number'] ?? '')) !== '') {
                $parts[] = 'Estimate ' . (string) $row['estimate_number'];
            }
            $parts[] = 'Qty: ' . number_format((float) ($row['quantity'] ?? 0), 2);
            $parts[] = 'Rate: ' . number_format((float) ($row['unit_price'] ?? 0), 2);
            return implode(' | ', $parts);
        },
        'status_block_values' => [],
        'recommended_action' => 'DELETE_LINE',
        'execution_mode' => 'hard_delete',
    ];

    return $configs;
}

function safe_delete_analyze_generic_entity(PDO $pdo, string $entity, int $recordId, array $scope, array $options = []): array
{
    $configs = safe_delete_generic_entity_configs();
    $config = $configs[$entity] ?? null;
    if (!is_array($config)) {
        throw new RuntimeException('Safe delete preview handler missing.');
    }

    $summary = safe_delete_summary_base($entity, $recordId, (string) ($options['operation'] ?? ($config['operation'] ?? 'delete')));

    $sql = (string) ($config['sql'] ?? '');
    if ($sql === '') {
        throw new RuntimeException('Safe delete preview SQL is not configured for entity: ' . $entity);
    }
    $params = ['id' => $recordId];
    $companyScopeColumn = $config['company_scope_column'] ?? null;
    $garageScopeColumn = $config['garage_scope_column'] ?? null;
    if (is_string($companyScopeColumn) && trim($companyScopeColumn) !== '' && ((int) ($scope['company_id'] ?? 0)) > 0) {
        $sql .= ' AND ' . $companyScopeColumn . ' = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (is_string($garageScopeColumn) && trim($garageScopeColumn) !== '' && ((int) ($scope['garage_id'] ?? 0)) > 0) {
        $sql .= ' AND ' . $garageScopeColumn . ' = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    if (!str_contains(strtoupper($sql), ' LIMIT ')) {
        $sql .= ' LIMIT 1';
    }

    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException(((string) ($config['label'] ?? safe_delete_entity_label($entity))) . ' not found for this scope.');
    }

    $referenceColumns = array_values(array_filter(array_map('strval', (array) ($config['reference_columns'] ?? []))));
    $referenceFallback = (string) ($config['reference_fallback_prefix'] ?? strtoupper(substr($entity, 0, 4)));
    $referenceIdKey = (string) ($config['reference_id_key'] ?? 'id');
    $reference = safe_delete_row_reference($row, $referenceColumns, $referenceFallback, $referenceIdKey);

    $labelPrefix = trim((string) ($config['label_prefix'] ?? safe_delete_entity_label($entity) . ' '));
    $label = trim($labelPrefix . $reference);
    if (isset($config['label_builder']) && is_callable($config['label_builder'])) {
        $labelResult = $config['label_builder']($row, $recordId, $scope);
        if (is_string($labelResult) && trim($labelResult) !== '') {
            $label = trim($labelResult);
        }
    }

    $date = null;
    if (isset($config['date_builder']) && is_callable($config['date_builder'])) {
        $dateResult = $config['date_builder']($row, $recordId, $scope);
        $date = is_string($dateResult) ? trim($dateResult) : null;
        if ($date === '') {
            $date = null;
        }
    } else {
        $date = safe_delete_pick_date($row, array_values((array) ($config['date_columns'] ?? [])));
    }

    $amount = null;
    if (isset($config['amount_builder']) && is_callable($config['amount_builder'])) {
        $amountResult = $config['amount_builder']($row, $recordId, $scope);
        $amount = $amountResult !== null ? round((float) $amountResult, 2) : null;
    } else {
        $amount = safe_delete_pick_amount($row, array_values((array) ($config['amount_columns'] ?? [])));
    }

    $statusColumns = array_values((array) ($config['status_columns'] ?? ['status_code', 'status']));
    $status = null;
    if (isset($config['status_builder']) && is_callable($config['status_builder'])) {
        $statusResult = $config['status_builder']($row, $recordId, $scope);
        $status = $statusResult !== null ? trim((string) $statusResult) : null;
    } else {
        foreach ($statusColumns as $statusColumn) {
            $candidate = trim((string) ($row[$statusColumn] ?? ''));
            if ($candidate !== '') {
                $status = $candidate;
                break;
            }
        }
    }

    $note = null;
    if (isset($config['note']) && is_callable($config['note'])) {
        $noteResult = $config['note']($row, $recordId, $scope);
        $note = $noteResult !== null ? trim((string) $noteResult) : null;
        if ($note === '') {
            $note = null;
        }
    }

    $summary['main_record'] = [
        'label' => $label,
        'reference' => $reference,
        'date' => $date,
        'amount' => $amount,
        'status' => $status,
        'note' => $note,
    ];

    if (isset($config['postprocess']) && is_callable($config['postprocess'])) {
        $config['postprocess']($pdo, $row, $recordId, $scope, $summary);
    }

    $statusValue = strtoupper(trim((string) ($status ?? '')));
    $blockedValues = array_values(array_map(
        static fn (mixed $v): string => strtoupper(trim((string) $v)),
        (array) ($config['status_block_values'] ?? ['DELETED'])
    ));
    if ($statusValue !== '' && in_array($statusValue, $blockedValues, true)) {
        $summary['blockers'][] = (string) ($config['status_block_message'] ?? (safe_delete_entity_label($entity) . ' is already deleted.'));
    }

    if (isset($config['extra_blockers']) && is_callable($config['extra_blockers'])) {
        $extraBlockers = $config['extra_blockers']($pdo, $row, $recordId, $scope, $summary);
        foreach ((array) $extraBlockers as $message) {
            $message = trim((string) $message);
            if ($message !== '') {
                $summary['blockers'][] = $message;
            }
        }
    }

    if (isset($config['extra_warnings']) && is_callable($config['extra_warnings'])) {
        $extraWarnings = $config['extra_warnings']($pdo, $row, $recordId, $scope, $summary);
        foreach ((array) $extraWarnings as $message) {
            $message = trim((string) $message);
            if ($message !== '') {
                $summary['warnings'][] = $message;
            }
        }
    }

    if (!empty($config['warnings']) && is_array($config['warnings'])) {
        foreach ($config['warnings'] as $warning) {
            $warning = trim((string) $warning);
            if ($warning !== '') {
                $summary['warnings'][] = $warning;
            }
        }
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = (string) ($config['recommended_action'] ?? ($summary['can_proceed'] ? 'SOFT_DELETE' : 'BLOCK'));
    $summary['execution_mode'] = (string) ($config['execution_mode'] ?? ($summary['can_proceed'] ? 'soft_delete' : 'block'));

    if (isset($config['post_finalize']) && is_callable($config['post_finalize'])) {
        $config['post_finalize']($pdo, $row, $recordId, $scope, $summary);
    }

    return safe_delete_summary_finalize($summary);
}

