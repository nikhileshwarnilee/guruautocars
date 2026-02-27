<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

$canView = has_permission('job.view')
    || has_permission('job.manage')
    || has_permission('job.print')
    || has_permission('settings.manage');

if (!$canView || !has_permission('settings.view')) {
    flash_set('access_denied', 'You do not have permission to access job card print settings.', 'danger');
    redirect('dashboard.php');
}

redirect('modules/system/settings.php?tab=job_card_print');
