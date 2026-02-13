<?php
error_reporting(E_ALL);
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rows=$pdo->query('SELECT id, role_key, role_name FROM roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r){echo $r['id'].'|'.$r['role_key'].'|'.$r['role_name']."\n";}
?>
