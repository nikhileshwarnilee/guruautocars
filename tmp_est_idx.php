<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
foreach($pdo->query('SHOW INDEX FROM estimates') as $r){if((int)$r['Non_unique']===0){echo $r['Key_name'].'|'.$r['Seq_in_index'].'|'.$r['Column_name']."\n";}}
?>
