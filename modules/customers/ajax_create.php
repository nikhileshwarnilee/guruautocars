<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'message' => 'Method not allowed.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf();

$canCustomerManage = has_permission('customer.view') && has_permission('customer.manage');
$canJobCreate = has_permission('job.create') || has_permission('job.manage');
$canInlineCreate = $canCustomerManage || $canJobCreate;

if (!$canInlineCreate) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'You do not have permission to create customers.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$companyId = active_company_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$fullName = post_string('full_name', 150);
$phone = post_string('phone', 20);
$altPhone = post_string('alt_phone', 20);
$email = strtolower(post_string('email', 150));
$gstin = strtoupper(post_string('gstin', 15));
$address1 = post_string('address_line1', 200);
$city = post_string('city', 80);
$state = post_string('state', 80);
$notes = post_string('notes', 500);

if ($fullName === '' || $phone === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Customer name and phone are required.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = db()->prepare(
        'INSERT INTO customers
          (company_id, created_by, full_name, phone, alt_phone, email, gstin, address_line1, city, state, notes, is_active, status_code, deleted_at)
         VALUES
          (:company_id, :created_by, :full_name, :phone, :alt_phone, :email, :gstin, :address_line1, :city, :state, :notes, 1, "ACTIVE", NULL)'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'created_by' => $userId > 0 ? $userId : null,
        'full_name' => $fullName,
        'phone' => $phone,
        'alt_phone' => $altPhone !== '' ? $altPhone : null,
        'email' => $email !== '' ? $email : null,
        'gstin' => $gstin !== '' ? $gstin : null,
        'address_line1' => $address1 !== '' ? $address1 : null,
        'city' => $city !== '' ? $city : null,
        'state' => $state !== '' ? $state : null,
        'notes' => $notes !== '' ? $notes : null,
    ]);

    $customerId = (int) db()->lastInsertId();
    add_customer_history($customerId, 'CREATE', 'Customer created via inline modal', [
        'full_name' => $fullName,
        'phone' => $phone,
        'status_code' => 'ACTIVE',
    ]);

    log_audit('customers', 'create_inline', $customerId, 'Created customer ' . $fullName . ' via inline modal', [
        'entity' => 'customer',
        'source' => 'UI-AJAX',
        'before' => ['exists' => false],
        'after' => [
            'id' => $customerId,
            'full_name' => $fullName,
            'phone' => $phone,
            'status_code' => 'ACTIVE',
        ],
    ]);

    echo json_encode([
        'ok' => true,
        'message' => 'Customer created successfully.',
        'customer' => [
            'id' => $customerId,
            'full_name' => $fullName,
            'phone' => $phone,
            'label' => $fullName . ' (' . $phone . ')',
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to create customer right now.',
    ], JSON_UNESCAPED_UNICODE);
}
