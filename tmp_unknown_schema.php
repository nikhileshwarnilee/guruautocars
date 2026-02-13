<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
foreach(['job_issues','vis_variant_specs','audit_logs','data_export_logs','backup_runs','backup_integrity_checks'] as $t){echo "=== $t\n";foreach($pdo->query("SHOW COLUMNS FROM `$t`") as $c){$d=$c['Default']===null?'NULL':$c['Default'];echo $c['Field'].'|'.$c['Type'].'|'.$c['Null'].'|'.$d."\n";}echo "\n";}
?>
