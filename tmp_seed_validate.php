<?php
$pdo=new PDO('mysql:host=localhost;dbname=guruautocars;charset=utf8mb4','root','');
$queries=[
  'invoice_reversal_payments'=>"SELECT COUNT(*) FROM payments WHERE entry_type='REVERSAL'",
  'purchase_reversal_payments'=>"SELECT COUNT(*) FROM purchase_payments WHERE entry_type='REVERSAL'",
  'outsource_reversal_payments'=>"SELECT COUNT(*) FROM outsourced_work_payments WHERE entry_type='REVERSAL'",
  'partial_salary_items'=>"SELECT COUNT(*) FROM payroll_salary_items WHERE status='PARTIAL'",
  'active_loans'=>"SELECT COUNT(*) FROM payroll_loans WHERE status='ACTIVE'",
  'open_advances'=>"SELECT COUNT(*) FROM payroll_advances WHERE status='OPEN'",
  'unpaid_outsourced_payable'=>"SELECT COUNT(*) FROM outsourced_works ow LEFT JOIN (SELECT outsourced_work_id,SUM(amount) AS paid FROM outsourced_work_payments GROUP BY outsourced_work_id) p ON p.outsourced_work_id=ow.id WHERE ow.current_status='PAYABLE' AND COALESCE(p.paid,0) < ow.agreed_cost - 0.01",
  'negative_stock'=>"SELECT COUNT(*) FROM garage_inventory WHERE quantity < 0",
  'closed_with_stock_posted'=>"SELECT COUNT(*) FROM job_cards WHERE status='CLOSED' AND stock_posted_at IS NOT NULL",
  'closed_without_stock_posted'=>"SELECT COUNT(*) FROM job_cards WHERE status='CLOSED' AND stock_posted_at IS NULL",
  'business_customers'=>"SELECT COUNT(*) FROM customers WHERE gstin IS NOT NULL AND gstin <> ''",
  'inactive_customers'=>"SELECT COUNT(*) FROM customers WHERE is_active=0 OR status_code='INACTIVE'",
  'jobs_without_invoice_closed'=>"SELECT COUNT(*) FROM job_cards jc LEFT JOIN invoices i ON i.job_card_id=jc.id WHERE jc.status='CLOSED' AND i.id IS NULL"
];
foreach($queries as $k=>$q){$v=$pdo->query($q)->fetchColumn();echo $k.':'.$v."\n";}
?>
