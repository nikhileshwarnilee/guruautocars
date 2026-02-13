<?php
$pdo = new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$tables=['companies','garages','roles','users','customers','vehicles','service_categories','services','vendors','part_categories','parts','garage_inventory','inventory_movements','temp_stock_entries','job_cards','job_labor','job_parts','invoices','payments','purchases','purchase_items','purchase_payments','outsourced_works','outsourced_work_payments','payroll_salary_structures','payroll_advances','payroll_loans','payroll_salary_sheets','payroll_salary_items','payroll_salary_payments','expenses','vis_brands','vis_models','vis_variants','vis_part_compatibility','vis_service_part_map','vehicle_brands','vehicle_models','vehicle_variants','vehicle_model_years','vehicle_colors'];
foreach($tables as $t){
    $c=$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo $t.':'.$c.PHP_EOL;
}
?>
