const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');
const dotenv = require('dotenv');
const { execFileSync } = require('child_process');
const path = require('path');

dotenv.config({ path: path.resolve(__dirname, '..', '.env') });

const APP_ROOT = path.resolve(__dirname, '..');
const RUN_UI = process.env.RUN_UI_REGRESSION === '1';
const BASE_URL = process.env.UI_BASE_URL || 'http://localhost/guruautocars';
const LOGIN_USER = process.env.UI_REGRESSION_USER || 'reg_admin_910001';
const LOGIN_PASS = process.env.UI_REGRESSION_PASS || 'regression123';
const COMPANY_ID = Number(process.env.REGRESSION_COMPANY_ID || 910001);
const GARAGE_ID = Number(process.env.REGRESSION_GARAGE_ID || 910001);

function dbConfig() {
  return {
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'guruautocars',
  };
}

async function withDb(fn) {
  const conn = await mysql.createConnection(dbConfig());
  try {
    return await fn(conn);
  } finally {
    await conn.end();
  }
}

async function dbRow(sql, params = []) {
  return withDb(async (conn) => {
    const [rows] = await conn.execute(sql, params);
    return rows[0] || null;
  });
}

async function dbValue(sql, params = []) {
  const row = await dbRow(sql, params);
  if (!row) return null;
  const firstKey = Object.keys(row)[0];
  return row[firstKey];
}

async function login(page) {
  await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('input[name="identifier"]')).toBeVisible();
  await page.fill('input[name="identifier"]', LOGIN_USER);
  await page.fill('input[name="password"]', LOGIN_PASS);
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.locator('form').getByRole('button', { name: /login|sign in/i }).click().catch(async () => {
      await page.locator('form').press('Enter');
    }),
  ]);
  await expect(page).toHaveURL(/dashboard\.php|\/guruautocars\/?$/i);
}

function formByAction(page, actionValue) {
  return page.locator(`form:has(input[name="_action"][value="${actionValue}"])`).first();
}

async function submitForm(form, submitLabelRegex = /create|save|add|apply|submit|finalize/i) {
  const button = form.getByRole('button', { name: submitLabelRegex }).first();
  await expect(button).toBeVisible();
  await Promise.all([
    form.page().waitForLoadState('networkidle'),
    button.click(),
  ]);
}

test.describe.serial('Full UI Flow vs DB', () => {
  test.skip(!RUN_UI, 'Set RUN_UI_REGRESSION=1 to run browser regression flow.');

  test('purchase -> job -> close -> invoice -> payment -> reports', async ({ page }) => {
    execFileSync('php', ['database/seed_regression_dataset.php'], { cwd: APP_ROOT, stdio: 'inherit' });

    await login(page);

    const stamp = Date.now();
    const purchaseInvoiceNumber = `UIREG-PUR-${stamp}`;
    const jobComplaint = `UIREG JOB ${stamp}`;
    const laborDescription = `UIREG LABOR ${stamp}`;
    const billingNote = `UIREG BILL ${stamp}`;
    const paymentNote = `UIREG PAY ${stamp}`;

    // Step 1: Create Purchase via UI
    await page.goto(`${BASE_URL}/modules/purchases/index.php`, { waitUntil: 'domcontentloaded' });
    const purchaseForm = formByAction(page, 'create_purchase');
    await expect(purchaseForm).toBeVisible();
    await purchaseForm.selectOption('select[name="vendor_id"]', '910101');
    await purchaseForm.fill('input[name="invoice_number"]', purchaseInvoiceNumber);
    await purchaseForm.fill('input[name="purchase_date"]', new Date().toISOString().slice(0, 10));
    await purchaseForm.selectOption('select[name="payment_status"]', 'UNPAID');
    await purchaseForm.locator('select[name="item_part_id[]"]').first().selectOption('910210');
    await purchaseForm.locator('input[name="item_quantity[]"]').first().fill('2');
    await purchaseForm.locator('input[name="item_unit_cost[]"]').first().fill('300');
    await purchaseForm.locator('input[name="item_gst_rate[]"]').first().fill('18');
    await submitForm(purchaseForm, /create|save/i);

    const purchaseRow = await expect
      .poll(() =>
        dbRow(
          'SELECT id, invoice_number, grand_total, company_id, garage_id FROM purchases WHERE company_id = ? AND invoice_number = ? ORDER BY id DESC LIMIT 1',
          [COMPANY_ID, purchaseInvoiceNumber],
        ),
      )
      .toBeTruthy();
    // `expect.poll` doesn't return the value; query again for assertions.
    const purchaseDb = await dbRow(
      'SELECT id, invoice_number, grand_total FROM purchases WHERE company_id = ? AND invoice_number = ? ORDER BY id DESC LIMIT 1',
      [COMPANY_ID, purchaseInvoiceNumber],
    );
    expect(purchaseDb).toBeTruthy();
    await expect(page.locator('body')).toContainText(purchaseInvoiceNumber);

    // Step 2: Create Job Card via UI
    await page.goto(`${BASE_URL}/modules/jobs/index.php`, { waitUntil: 'domcontentloaded' });
    const createJobForm = formByAction(page, 'create');
    await expect(createJobForm).toBeVisible();
    await createJobForm.selectOption('select[name="customer_id"]', '910301');
    await createJobForm.selectOption('select[name="vehicle_id"]', '910401');
    await createJobForm.fill('input[name="odometer_km"]', '54321');
    await createJobForm.selectOption('select[name="priority"]', 'MEDIUM');
    await createJobForm.fill('textarea[name="complaint"]', jobComplaint);
    await createJobForm.fill('textarea[name="diagnosis"]', 'UI regression diagnosis');
    await submitForm(createJobForm, /create/i);

    const jobDb = await expect
      .poll(() => dbRow('SELECT id, job_number, status FROM job_cards WHERE company_id = ? AND complaint = ? ORDER BY id DESC LIMIT 1', [COMPANY_ID, jobComplaint]))
      .toBeTruthy();
    const jobRow = await dbRow('SELECT id, job_number, status FROM job_cards WHERE company_id = ? AND complaint = ? ORDER BY id DESC LIMIT 1', [COMPANY_ID, jobComplaint]);
    expect(jobRow).toBeTruthy();
    expect(jobRow.status).toMatch(/OPEN|IN_PROGRESS/);

    // Step 3: Add one labor line + Close Job via UI
    await page.goto(`${BASE_URL}/modules/jobs/view.php?id=${jobRow.id}`, { waitUntil: 'domcontentloaded' });
    const addLaborForm = page.locator('form:has(#add-labor-description)').first();
    await expect(addLaborForm).toBeVisible();
    await addLaborForm.fill('#add-labor-description', laborDescription);
    await addLaborForm.fill('input[name="quantity"]', '1');
    await addLaborForm.fill('input[name="unit_price"]', '999');
    await addLaborForm.fill('input[name="gst_rate"]', '18');
    await submitForm(addLaborForm, /^add$/i);

    await expect
      .poll(() => dbValue('SELECT COUNT(*) AS c FROM job_labor WHERE job_card_id = ? AND description = ?', [jobRow.id, laborDescription]))
      .toBe(1);

    const transitionForm = formByAction(page, 'transition_status');
    await expect(transitionForm).toBeVisible();
    await transitionForm.selectOption('select[name="next_status"]', 'CLOSED');
    if (await transitionForm.locator('textarea[name="status_note"]').count()) {
      await transitionForm.locator('textarea[name="status_note"]').fill('UI regression close');
    }
    await submitForm(transitionForm, /apply transition/i);

    await expect
      .poll(() => dbValue('SELECT status FROM job_cards WHERE id = ? AND company_id = ?', [jobRow.id, COMPANY_ID]))
      .toBe('CLOSED');

    // Step 4: Create + Finalize Invoice via UI (same job)
    await page.goto(`${BASE_URL}/modules/billing/index.php`, { waitUntil: 'domcontentloaded' });
    const createInvoiceForm = formByAction(page, 'create_invoice');
    await expect(createInvoiceForm).toBeVisible();
    await createInvoiceForm.selectOption('select[name="job_card_id"]', String(jobRow.id));
    await createInvoiceForm.fill('input[name="notes"]', billingNote);
    await submitForm(createInvoiceForm, /create draft invoice/i);

    let invoiceRow = await dbRow(
      'SELECT id, invoice_number, invoice_status, grand_total FROM invoices WHERE company_id = ? AND job_card_id = ? ORDER BY id DESC LIMIT 1',
      [COMPANY_ID, jobRow.id],
    );
    expect(invoiceRow).toBeTruthy();
    expect(invoiceRow.invoice_status).toBe('DRAFT');
    await expect(page.locator('body')).toContainText(invoiceRow.invoice_number);

    const invoiceTableRow = page.locator('tr', { hasText: invoiceRow.invoice_number }).first();
    await expect(invoiceTableRow).toBeVisible();
    const finalizeBtn = invoiceTableRow.getByRole('button', { name: /finalize/i });
    if (await finalizeBtn.count()) {
      await Promise.all([page.waitForLoadState('networkidle'), finalizeBtn.click()]);
    } else {
      throw new Error(`Finalize button not found for invoice ${invoiceRow.invoice_number}`);
    }

    await expect
      .poll(() => dbValue('SELECT invoice_status FROM invoices WHERE id = ? AND company_id = ?', [invoiceRow.id, COMPANY_ID]))
      .toBe('FINALIZED');

    invoiceRow = await dbRow('SELECT id, invoice_number, invoice_status, grand_total, payment_status FROM invoices WHERE id = ? AND company_id = ?', [invoiceRow.id, COMPANY_ID]);

    // Step 5: Record Payment via UI
    const paymentForm = formByAction(page, 'add_payment');
    await expect(paymentForm).toBeVisible();
    await paymentForm.selectOption('select[name="invoice_id"]', String(invoiceRow.id));
    await paymentForm.fill('input[name="amount"]', '100');
    await paymentForm.fill('input[name="paid_on"]', new Date().toISOString().slice(0, 10));
    await paymentForm.selectOption('select[name="payment_mode"]', 'CASH');
    await paymentForm.fill('input[name="notes"]', paymentNote);
    await submitForm(paymentForm, /save payment/i);

    const paymentRow = await dbRow(
      'SELECT p.id, p.amount, p.invoice_id, i.invoice_number FROM payments p INNER JOIN invoices i ON i.id = p.invoice_id WHERE i.company_id = ? AND p.invoice_id = ? AND p.notes = ? ORDER BY p.id DESC LIMIT 1',
      [COMPANY_ID, invoiceRow.id, paymentNote],
    );
    expect(paymentRow).toBeTruthy();
    expect(Number(paymentRow.amount)).toBeCloseTo(100, 2);
    await expect(page.locator('body')).toContainText(invoiceRow.invoice_number);

    // Step 6: Generate / verify reports pages and compare UI presence to DB records
    const reportPaths = [
      '/modules/reports/index.php',
      '/modules/reports/purchases.php',
      '/modules/reports/jobs.php',
      '/modules/reports/payments.php',
    ];
    for (const reportPath of reportPaths) {
      await page.goto(`${BASE_URL}${reportPath}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('main, body')).toContainText(/report|reports|purchase|jobs|payments/i);
    }

    await page.goto(`${BASE_URL}/modules/reports/purchases.php`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toContainText(purchaseInvoiceNumber);

    await page.goto(`${BASE_URL}/modules/reports/jobs.php`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toContainText(jobRow.job_number);

    await page.goto(`${BASE_URL}/modules/reports/payments.php`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toContainText(invoiceRow.invoice_number);

    // Final DB integrity spot-check for the UI flow rows
    const uiFlowSummary = await dbRow(
      `SELECT
         (SELECT COUNT(*) FROM purchases WHERE company_id = ? AND invoice_number = ?) AS purchase_count,
         (SELECT COUNT(*) FROM job_cards WHERE company_id = ? AND id = ?) AS job_count,
         (SELECT COUNT(*) FROM invoices WHERE company_id = ? AND id = ?) AS invoice_count,
         (SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND notes = ?) AS payment_count`,
      [COMPANY_ID, purchaseInvoiceNumber, COMPANY_ID, jobRow.id, COMPANY_ID, invoiceRow.id, invoiceRow.id, paymentNote],
    );
    expect(Number(uiFlowSummary.purchase_count)).toBe(1);
    expect(Number(uiFlowSummary.job_count)).toBe(1);
    expect(Number(uiFlowSummary.invoice_count)).toBe(1);
    expect(Number(uiFlowSummary.payment_count)).toBe(1);
  });
});

