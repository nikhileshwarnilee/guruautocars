<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$tables=['estimates','estimate_services','estimate_parts','estimate_history'];
foreach($tables as $t){
 echo "=== {$t}\n";
 foreach($pdo->query("SHOW COLUMNS FROM `{$t}`") as $c){
   $d=$c['Default']===null?'NULL':$c['Default'];
   echo $c['Field'].'|'.$c['Type'].'|'.$c['Null'].'|'.$d."\n";
 }
 echo "\n";
}
?>
