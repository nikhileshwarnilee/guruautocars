<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$tables=['financial_years','invoice_counters','job_counters','estimate_counters','vehicle_brands','vehicle_models','vehicle_variants','vehicle_model_years','vehicle_colors','permissions','role_permissions'];
foreach($tables as $t){echo "=== $t\n";foreach($pdo->query("SHOW INDEX FROM `$t`") as $i){if((int)$i['Non_unique']===0){echo $i['Key_name'].'|'.$i['Seq_in_index'].'|'.$i['Column_name']."\n";}}echo "\n";}
?>
