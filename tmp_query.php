<?php
$pdo = new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$stmt = $pdo->query("SELECT id,company_id,garage_id,setting_key,setting_value FROM system_settings WHERE setting_key='business_logo_path'");
foreach ($stmt as $r) {
    echo implode('|', [(string)$r['id'],(string)$r['company_id'],(string)$r['garage_id'],(string)$r['setting_key'],(string)$r['setting_value']]) . PHP_EOL;
}
