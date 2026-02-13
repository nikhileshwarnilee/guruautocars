<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
foreach(['vehicle_model_years','vehicle_colors','vehicle_brands','vehicle_models','vehicle_variants'] as $t){echo "=== $t\n";foreach($pdo->query("SHOW COLUMNS FROM `$t`") as $c){echo $c['Field']."\n";} }
?>
