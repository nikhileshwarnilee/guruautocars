<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$rows=$pdo->query("SELECT invoice_status, payment_status, COUNT(*) c FROM invoices GROUP BY invoice_status,payment_status ORDER BY invoice_status,payment_status")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r){echo $r['invoice_status'].'|'.$r['payment_status'].'|'.$r['c']."\n";}
?>
