<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
foreach(['customer_history','vehicle_history'] as $t){echo "=== $t\n";foreach($pdo->query("SHOW COLUMNS FROM `$t`") as $c){echo $c['Field']."|".$c['Type']."\n";}echo "\n";}
?>
