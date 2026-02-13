<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
foreach(['job_issues','vis_variant_specs'] as $t){echo "=== $t\n";foreach($pdo->query("SHOW INDEX FROM `$t`") as $i){if((int)$i['Non_unique']===0){echo $i['Key_name'].'|'.$i['Seq_in_index'].'|'.$i['Column_name']."\n";}}echo "\n";}
?>
