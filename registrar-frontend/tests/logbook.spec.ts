import { test, expect, type Page, type Route } from '@playwright/test';

// ─────────────────────────────────────────────────────────────────────────────
// Admin Logbook: the screen shows the server's official processing minutes,
// and search / category filtering / totals / paging are done by the server.
//
// The API is mocked. The logbook mock behaves like the real endpoint (it
// filters, counts and pages), so these tests would fail if the screen went
// back to filtering or summing in the browser.
// ─────────────────────────────────────────────────────────────────────────────

type MockRow = {
  request_id: number;
  requested_at: string;
  email: string;
  display_name: string;
  student_profile: { first_name: string; middle_name: string; last_name: string; sex_at_birth: string };
  academic_record: { course: string; student_number: string };
  documents: Array<{ request_document_id: number; documentType: { document_name: string; logbook_category_id: number | null } }>;
  certificates: unknown[];
  processing: { minutes: number | null; processed_at: string | null; end_status_id: number | null; claimed_at: string | null };
  items_processing: unknown[];
  history: Array<{ new_status_id: number; business_minutes: number; changed_at: string }>;
};

const CATEGORY_TOR = { logbook_category_id: 1, name: 'Transcript of Records' };

const makeRow = (
  id: number,
  first: string,
  last: string,
  docName: string,
  categoryId: number | null,
  minutes: number | null,
  processedAt: string | null,
): MockRow => ({
  request_id: id,
  requested_at: '2026-03-10T02:00:00Z',
  email: `${first.toLowerCase()}.${last.toLowerCase()}@example.com`,
  display_name: `${first} ${last}`,
  student_profile: { first_name: first, middle_name: '', last_name: last, sex_at_birth: 'Male' },
  academic_record: { course: 'BSCS', student_number: `2020-${id}` },
  documents: [{ request_document_id: id * 10, documentType: { document_name: docName, logbook_category_id: categoryId } }],
  certificates: [],
  processing: { minutes, processed_at: processedAt, end_status_id: 6, claimed_at: '2026-03-12T02:00:00Z' },
  items_processing: [
    { type: 'document', id: id * 10, minutes, processed_at: processedAt, end_status_id: 6, claimed_at: '2026-03-12T02:00:00Z' },
  ],
  // Raw stored segments. Summing these in the browser (the old bug) would give
  // 999 minutes, which the screen must NOT show.
  history: [
    { new_status_id: 2, business_minutes: 600, changed_at: '2026-03-11T02:00:00Z' },
    { new_status_id: 6, business_minutes: 399, changed_at: processedAt ?? '2026-03-10T05:30:00Z' },
  ],
});

const DEFAULT_ROWS: MockRow[] = [
  makeRow(1, 'Juan', 'Cruz', 'Transcript of Records (Regular)', 1, 90, '2026-03-10T05:30:00Z'),
  makeRow(2, 'Maria', 'Santos', 'Diploma', null, 45, '2026-03-10T04:45:00Z'),
  makeRow(3, 'Doctor', 'Who', 'Doctor Visit Form', null, 10, '2026-03-10T03:10:00Z'),
];

type Captured = { url: URL }[];

async function mockLogbookApi(
  page: Page,
  options: { rows?: MockRow[]; actions?: string[]; auditStatus?: number } = {},
) {
  const rows = options.rows ?? DEFAULT_ROWS;
  const actions = options.actions ?? ['View'];
  const logbookCalls: Captured = [];
  const auditBodies: unknown[] = [];

  await page.context().addInitScript(() => {
    window.localStorage.setItem('hasAgreed', 'true');
  });

  // Fallback first: later routes take precedence.
  await page.route('**/api/**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }),
  );

  await page.route('**/api/me', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          user_id: 1,
          email: 'admin@example.com',
          name: 'Admin User',
          role_name: 'admin',
          effective_permissions: { dashboard: ['View'], logbook: actions },
        },
      }),
    }),
  );

  await page.route('**/api/document-types*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [
          { document_type_id: 1, document_name: 'Transcript of Records (Regular)', logbook_category_id: 1 },
          { document_type_id: 2, document_name: 'Diploma', logbook_category_id: null },
          { document_type_id: 3, document_name: 'Doctor Visit Form', logbook_category_id: null },
        ],
      }),
    }),
  );

  await page.route('**/api/logbook-categories*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [CATEGORY_TOR] }) }),
  );

  await page.route('**/api/document-requests/logbook/export-audit', async (route: Route) => {
    auditBodies.push(route.request().postDataJSON());
    await route.fulfill({
      status: options.auditStatus ?? 201,
      contentType: 'application/json',
      body: JSON.stringify({ message: options.auditStatus && options.auditStatus >= 400 ? 'Audit failed' : 'Recorded' }),
    });
  });

  // Behaves like the real endpoint: filters, counts and pages on the "server".
  await page.route('**/api/document-requests/logbook?*', async (route: Route) => {
    const url = new URL(route.request().url());
    logbookCalls.push({ url });

    const search = (url.searchParams.get('search') ?? '').toLowerCase();
    const categories = url.searchParams.getAll('categories[]').map((c) => c.toLowerCase());
    const perPage = Number(url.searchParams.get('per_page') ?? 25);
    const pageNo = Number(url.searchParams.get('page') ?? 1);

    const matching = rows.filter((row) => {
      if (search && !row.display_name.toLowerCase().includes(search) && !row.email.toLowerCase().startsWith(search)) {
        return false;
      }
      if (categories.length > 0 && !categories.includes('all document')) {
        const label = (d: MockRow['documents'][number]) =>
          (d.documentType.logbook_category_id === 1 ? CATEGORY_TOR.name : d.documentType.document_name).toLowerCase();
        return row.documents.some((d) => categories.includes(label(d)));
      }
      return true;
    });

    const data = matching.slice((pageNo - 1) * perPage, pageNo * perPage);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data,
        total: matching.length,
        per_page: perPage,
        current_page: pageNo,
        last_page: Math.max(1, Math.ceil(matching.length / perPage)),
      }),
    });
  });

  return { logbookCalls, auditBodies };
}

const tableRows = (page: Page) => page.locator('table tbody tr:has(td:not([colspan]))');

test.describe('Admin Logbook', () => {
  test.use({ timezoneId: 'Asia/Manila' });

  test('shows the server processing minutes and dates, not minutes summed in the browser', async ({ page }) => {
    await mockLogbookApi(page);
    await page.goto('/staff/logbook');

    const juan = page.locator('table tbody tr', { hasText: 'Juan' });
    await expect(juan).toBeVisible();

    // Server figure: 90 minutes -> "1hr 30mins" (the stored segments sum to 999).
    await expect(juan).toContainText('1hr 30mins');
    await expect(juan).not.toContainText('16hrs 39mins');
    // Date/Time Processed is the server's processed_at (05:30Z = 13:30 Manila).
    await expect(juan).toContainText('March 10, 2026 13:30');
    // Date Claimed comes from the server too.
    await expect(juan).toContainText('March 12, 2026');
    // E-mail is the server-resolved value.
    await expect(juan).toContainText('juan.cruz@example.com');
  });

  test('an unmeasurable record shows --- and never a guessed number', async ({ page }) => {
    await mockLogbookApi(page, {
      rows: [makeRow(9, 'Pedro', 'Reyes', 'Diploma', null, null, null)],
    });
    await page.goto('/staff/logbook');

    const row = page.locator('table tbody tr', { hasText: 'Pedro' });
    await expect(row).toBeVisible();
    await expect(row).toContainText('---');
    await expect(row).not.toContainText('mins');
  });

  test('search is sent to the server (debounced) and only the server result is shown', async ({ page }) => {
    const { logbookCalls } = await mockLogbookApi(page);
    await page.goto('/staff/logbook');
    await expect(tableRows(page)).toHaveCount(3);

    const callsBefore = logbookCalls.length;
    await page.getByPlaceholder('Search name, email...').fill('maria');

    await expect(tableRows(page)).toHaveCount(1);
    await expect(page.locator('table tbody tr', { hasText: 'Maria' })).toBeVisible();
    await expect(page.getByText('1 record', { exact: false }).first()).toBeVisible();

    const searchCalls = logbookCalls.slice(callsBefore).filter((c) => c.url.searchParams.get('search') === 'maria');
    expect(searchCalls.length).toBe(1); // one request for the whole word, not one per keystroke
    expect(searchCalls[0].url.searchParams.get('page')).toBe('1');
  });

  test('the selected category is sent to the server and unrelated names are not matched', async ({ page }) => {
    const { logbookCalls } = await mockLogbookApi(page);
    await page.goto('/staff/logbook');
    await expect(tableRows(page)).toHaveCount(3);

    await page.getByText('All Categories').click();
    await page.getByRole('button', { name: 'Transcript of Records' }).click();

    await expect(tableRows(page)).toHaveCount(1);
    await expect(page.locator('table tbody tr', { hasText: 'Juan' })).toBeVisible();
    // "Doctor Visit Form" contains "tor" but is not a Transcript of Records.
    await expect(page.locator('table tbody tr', { hasText: 'Doctor' })).toHaveCount(0);

    const last = logbookCalls[logbookCalls.length - 1].url;
    expect(last.searchParams.getAll('categories[]')).toEqual(['Transcript of Records']);
  });

  test('the record total and paging come from the server', async ({ page }) => {
    const many = Array.from({ length: 10 }, (_, i) =>
      makeRow(100 + i, `Client${i}`, 'Test', 'Diploma', null, 30 + i, '2026-03-10T04:00:00Z'),
    );
    const { logbookCalls } = await mockLogbookApi(page, { rows: many });
    await page.goto('/staff/logbook');

    await expect(page.getByText('10 records')).toBeVisible();
    await expect(page.getByText('Showing 1 to 8 of 10 results')).toBeVisible();
    await expect(tableRows(page)).toHaveCount(8);

    await page.getByRole('button', { name: 'Next page' }).click();

    await expect(page.getByText('Showing 9 to 10 of 10 results')).toBeVisible();
    await expect(tableRows(page)).toHaveCount(2);

    const last = logbookCalls[logbookCalls.length - 1].url;
    expect(last.searchParams.get('page')).toBe('2');
    expect(last.searchParams.get('per_page')).toBe('8');
  });

  test('an export is audited first, with metadata only, and then the file is downloaded', async ({ page }) => {
    const { auditBodies } = await mockLogbookApi(page, { actions: ['View', 'Export'] });
    await page.goto('/staff/logbook');
    await expect(tableRows(page)).toHaveCount(3);

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'Export DOCX' }).click(),
    ]);

    expect(download.suggestedFilename()).toMatch(/^Logbook_Records_.*\.docx$/);
    expect(auditBodies).toHaveLength(1);

    const body = auditBodies[0] as Record<string, unknown>;
    expect(body).toMatchObject({ format: 'docx', mode: 'documents' });
    expect(body.row_count).toBeGreaterThan(0);
    // Metadata only: no names, e-mails or request contents.
    expect(JSON.stringify(body)).not.toMatch(/juan|maria|example\.com/i);
  });

  test('if the audit record fails, the export is cancelled and no file is produced', async ({ page }) => {
    const { auditBodies } = await mockLogbookApi(page, { actions: ['View', 'Export'], auditStatus: 500 });
    await page.goto('/staff/logbook');
    await expect(tableRows(page)).toHaveCount(3);

    let downloaded = false;
    page.on('download', () => {
      downloaded = true;
    });

    await page.getByRole('button', { name: 'Export DOCX' }).click();
    await expect.poll(() => auditBodies.length).toBe(1);
    await page.waitForTimeout(1500);

    expect(downloaded).toBe(false);
    await expect(page.getByRole('button', { name: 'Export DOCX' })).toBeEnabled();
  });

  test('the Export button is hidden without the Export permission', async ({ page }) => {
    await mockLogbookApi(page, { actions: ['View'] });
    await page.goto('/staff/logbook');

    await expect(tableRows(page)).toHaveCount(3);
    await expect(page.getByRole('button', { name: 'Export DOCX' })).toHaveCount(0);
  });
});
