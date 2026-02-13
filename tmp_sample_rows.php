<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
foreach(['companies','garages','users','customers','vehicles','services','parts'] as $t){
 echo "\n=== $t\n";
 foreach($pdo->query("SELECT * FROM `$t` LIMIT 10") as $r){
  echo json_encode($r,JSON_UNESCAPED_UNICODE)."\n";
 }
}
?>
