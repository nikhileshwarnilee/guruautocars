<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$rows=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
foreach($rows as $r){echo $r[0]."\n";}
?>
