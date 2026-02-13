<?php
$pdo = new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$tables=['vehicle_brands','vehicle_models','vehicle_variants','vehicle_model_years','vehicle_colors','customer_history','vehicle_history','estimates','estimate_services','estimate_parts','estimate_history','estimate_counters','job_counters','invoice_counters','invoice_number_sequences'];
foreach($tables as $t){echo "=== $t\n";foreach($pdo->query("SHOW COLUMNS FROM `$t`") as $c){$d=$c['Default']===null?'NULL':$c['Default'];echo $c['Field'].'|'.$c['Type'].'|'.$c['Null'].'|'.$d."\n";}echo "\n";}
