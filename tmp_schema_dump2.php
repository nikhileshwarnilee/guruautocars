<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
$pdo = db();
$tables = ['companies','garages','users','chart_of_accounts','financial_years','expense_categories','services','service_categories','part_categories'];
foreach ($tables as $t) {
    $s = $pdo->prepare('SHOW TABLES LIKE :t');
    $s->execute(['t'=>$t]);
    if(!$s->fetchColumn()){ echo "## {$t} MISSING\n"; continue; }
    echo "## {$t}\n";
    foreach($pdo->query('DESCRIBE `'.$t.'`') as $c){
      $d=$c['Default']; if($d===null){$d='NULL';}
      echo $c['Field'].'|'.$c['Type'].'|'.$c['Null'].'|'.$c['Key'].'|'.$d.'|'.$c['Extra']."\n";
    }
  }
