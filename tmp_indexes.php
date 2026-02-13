<?php
$pdo = new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$tables=['companies','garages','users','customers','vehicles','vendors','service_categories','services','part_categories','parts','job_cards','invoices','invoice_number_sequences','purchases','temp_stock_entries','inventory_transfers','vis_brands','vis_models','vis_variants'];
foreach($tables as $t){echo "=== $t\n";foreach($pdo->query("SHOW INDEX FROM `$t`") as $idx){if((int)$idx['Non_unique']===0){echo $idx['Key_name'].'|'.$idx['Seq_in_index'].'|'.$idx['Column_name']."\n";}}echo "\n";}
