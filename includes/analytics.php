<?php
declare(strict_types=1);

function analytics_is_owner_role(?string $roleKey): bool
{
    $role = strtolower(trim((string) $roleKey));
    return in_array($role, ['super_admin', 'garage_owner'], true);
}

function analytics_parse_iso_date(?string $value, string $fallback): string
{
    $candidate = trim((string) $value);
    if ($candidate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
        return $fallback;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $candidate));
    if (!checkdate($month, $day, $year)) {
        return $fallback;
    }

    return $candidate;
}

function analytics_derive_fy_range(string $referenceDate): array
{
    $timestamp = strtotime($referenceDate);
    if ($timestamp === false) {
        $timestamp = time();
    }

    $year = (int) date('Y', $timestamp);
    $month = (int) date('n', $timestamp);
    $startYear = $month >= 4 ? $year : ($year - 1);
    $endYear = $startYear + 1;

    return [
        'id' => 0,
        'fy_label' => sprintf('%d-%02d', $startYear, $endYear % 100),
        'start_date' => sprintf('%d-04-01', $startYear),
        'end_date' => sprintf('%d-03-31', $endYear),
        'is_default' => 1,
    ];
}

function analytics_fetch_financial_years(int $companyId): array
{
    $stmt = db()->prepare(
        'SELECT id, fy_label, start_date, end_date, is_default
         FROM financial_years
         WHERE company_id = :company_id
           AND status_code = "ACTIVE"
         ORDER BY start_date DESC'
    );
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function analytics_resolve_financial_year(int $companyId, int $requestedFyId = 0, ?string $referenceDate = null): array
{
    $today = $referenceDate !== null && $referenceDate !== '' ? $referenceDate : date('Y-m-d');
    $years = analytics_fetch_financial_years($companyId);

    $selected = null;
    if ($requestedFyId > 0) {
        foreach ($years as $year) {
            if ((int) $year['id'] === $requestedFyId) {
                $selected = $year;
                break;
            }
        }
    }

    if ($selected === null) {
        foreach ($years as $year) {
            $start = (string) ($year['start_date'] ?? '');
            $end = (string) ($year['end_date'] ?? '');
            if ($start !== '' && $end !== '' && $today >= $start && $today <= $end) {
                $selected = $year;
                break;
            }
        }
    }

    if ($selected === null && !empty($years)) {
        foreach ($years as $year) {
            if ((int) ($year['is_default'] ?? 0) === 1) {
                $selected = $year;
                break;
            }
        }
    }

    if ($selected === null && !empty($years)) {
        $selected = $years[0];
    }

    if ($selected === null) {
        $selected = analytics_derive_fy_range($today);
    }

    return [
        'years' => $years,
        'selected' => $selected,
    ];
}

function analytics_accessible_garages(int $companyId, bool $ownerScope): array
{
    if ($ownerScope) {
        $stmt = db()->prepare(
            'SELECT id, name, code
             FROM garages
             WHERE company_id = :company_id
               AND (status_code IS NULL OR status_code = "ACTIVE")
               AND (status = "active" OR status IS NULL)
             ORDER BY name ASC'
        );
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll();
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT g.id, g.name, g.code
         FROM user_garages ug
         INNER JOIN garages g ON g.id = ug.garage_id
         WHERE ug.user_id = :user_id
           AND g.company_id = :company_id
           AND (g.status_code IS NULL OR g.status_code = "ACTIVE")
           AND (g.status = "active" OR g.status IS NULL)
         ORDER BY g.name ASC'
    );
    $stmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
    ]);

    return $stmt->fetchAll();
}

function analytics_resolve_scope_garage_id(
    array $garageOptions,
    int $activeGarageId,
    int $requestedGarageId,
    bool $allowAll
): int {
    $garageIds = array_values(
        array_unique(
            array_map(
                static fn (array $garage): int => (int) ($garage['id'] ?? 0),
                $garageOptions
            )
        )
    );

    $garageIds = array_values(array_filter($garageIds, static fn (int $id): bool => $id > 0));

    if ($allowAll && $requestedGarageId === 0) {
        return 0;
    }

    if ($requestedGarageId > 0 && in_array($requestedGarageId, $garageIds, true)) {
        return $requestedGarageId;
    }

    if ($activeGarageId > 0 && in_array($activeGarageId, $garageIds, true)) {
        return $activeGarageId;
    }

    if (!empty($garageIds)) {
        return $garageIds[0];
    }

    return $activeGarageId > 0 ? $activeGarageId : 0;
}

function analytics_garage_scope_sql(
    string $column,
    int $selectedGarageId,
    array $allowedGarageIds,
    array &$params,
    string $paramPrefix = 'garage_scope'
): string {
    $allowedGarageIds = array_values(array_filter(array_unique(array_map('intval', $allowedGarageIds)), static fn (int $id): bool => $id > 0));

    if ($selectedGarageId > 0) {
        $key = $paramPrefix . '_selected';
        $params[$key] = $selectedGarageId;
        return ' AND ' . $column . ' = :' . $key . ' ';
    }

    if (empty($allowedGarageIds)) {
        return ' AND 1 = 0 ';
    }

    $placeholders = [];
    foreach ($allowedGarageIds as $index => $garageId) {
        $key = $paramPrefix . '_' . $index;
        $params[$key] = $garageId;
        $placeholders[] = ':' . $key;
    }

    return ' AND ' . $column . ' IN (' . implode(', ', $placeholders) . ') ';
}

function analytics_scope_garage_label(array $garageOptions, int $selectedGarageId): string
{
    if ($selectedGarageId === 0) {
        return 'All Accessible Garages';
    }

    foreach ($garageOptions as $garage) {
        if ((int) ($garage['id'] ?? 0) === $selectedGarageId) {
            $name = trim((string) ($garage['name'] ?? ''));
            $code = trim((string) ($garage['code'] ?? ''));
            if ($name === '') {
                return 'Garage #' . $selectedGarageId;
            }
            return $code !== '' ? ($name . ' (' . $code . ')') : $name;
        }
    }

    return $selectedGarageId > 0 ? ('Garage #' . $selectedGarageId) : 'All Accessible Garages';
}

function analytics_progress_width(float $value, float $maxValue): float
{
    if ($maxValue <= 0.0) {
        return 0.0;
    }

    $ratio = ($value / $maxValue) * 100;
    if ($ratio < 0.0) {
        return 0.0;
    }

    if ($ratio > 100.0) {
        return 100.0;
    }

    return round($ratio, 2);
}
