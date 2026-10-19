import { test, expect } from '@playwright/test';

test.describe('Student Request Form E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    // 1. Bypass Terms and Conditions modal
    await context.addInitScript(() => {
      window.localStorage.setItem('hasAgreed', 'true');
    });

    // Enable console logging for debugging
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.message));

    // 2. Mock /api/me to return a Student user
    await page.route(/\/api\/(v1\/)?me$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user_id: 43,
            email: 'student@example.com',
            name: 'Student User',
            role_name: 'student',
            policies: []
          }
        }),
      });
    });

    // 3. Mock document types (return array directly)
    await page.route(/\/api\/(v1\/)?document-types/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          { document_type_id: 1, document_name: 'Transcript of Records', access_id: 1, document_requirements: 'Dummy requirements' },
          { document_type_id: 2, document_name: 'Honorable Dismissal', access_id: 3, document_requirements: 'Dummy requirements' }
        ]),
      });
    });

    // 4. Mock certifications (return array directly)
    await page.route(/\/api\/(v1\/)?certifications/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          { certificate_type_id: 10, certificate_name: 'Graduation Certification', access_id: 1 }
        ]),
      });
    });

    // 5. Mock request purposes (return array directly)
    await page.route(/\/api\/(v1\/)?request-purposes/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          { request_purpose_id: 101, purpose_name: 'Employment' },
          { request_purpose_id: 102, purpose_name: 'Further Studies' }
        ]),
      });
    });

    // 6. Mock other metadata lookups to prevent hangs
    await page.route(/\/api\/(v1\/)?programs/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      });
    });
    await page.route(/\/api\/(v1\/)?request-statuses/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
      });
    });
    await page.route(/\/api\/(v1\/)?announcements/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], last_page: 1 }),
      });
    });
    await page.route(/\/api\/(v1\/)?notifications/, async (route) => {
      const url = route.request().url();
      if (url.includes('/unread-count')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ count: 0 }),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1 } }),
        });
      }
    });

    // 7. Mock the OR-first verify-or step — hit before Documents now (see
    // RequestForm.jsx's orStep/docStep reorder). The mutation's onSuccess
    // reads response.data.suggestions.{documents,certificates,unresolved}
    // and uses each suggested document_type_id to look up a name from the
    // already-mocked /api/document-types list above, so this id must
    // match one of those (1 = 'Transcript of Records').
    await page.route(/\/api\/(v1\/)?document-requests\/verify-or/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          valid: true,
          is_mock: true,
          suggestions: {
            documents: [{ document_type_id: 1, document_name: 'Transcript of Records', number_of_copies: 1 }],
            certificates: [],
            unresolved: [
              { label: 'CAV/Apostille (DFA) - undergraduate', amount: '620.00', quantity: 1 },
              { label: 'CAV/Apostille (DFA) with Special Certification', amount: '1070.00', quantity: 1 },
              { label: 'Additional Unresolved Document', amount: '150.00', quantity: 2 }
            ],
          },
        }),
      });
    });

    // 8. Mock form submission endpoint
    await page.route(/\/api\/document-requests$/, async (route) => {
      if (route.request().method() === 'POST') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ success: true, request_id: 502 }),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [] }),
        });
      }
    });
  });

  test('Walk through multi-step Student Request wizard and submit successfully', async ({ page }) => {
    // Navigate to student request page
    await page.goto('/student/request');

    // --- STEP 1: TERMS & CONDITIONS ---
    await expect(page.getByText('In compliance with the Data Privacy Act (DPA) of 2012')).toBeVisible();
    
    // Attempting to click Next without agreeing to terms should display an error
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.getByText('You must read and agree to the Terms & Conditions to proceed.')).toBeVisible();
    
    // Check agreement checkbox
    await page.locator('input[name="termsAgreed"]').check();
    await page.getByRole('button', { name: 'Next' }).click();

    // --- STEP 2: OFFICIAL RECEIPT VERIFICATION ---
    // Moved ahead of Documents (OR-first wizard reorder) — clicking Next
    // here calls handleVerifyOr(), which POSTs to verify-or and only
    // advances on a successful match (mocked above).
    await page.getByPlaceholder('XXXXXXX').fill('1234567');

    const today = new Date().toISOString().split('T')[0];
    await page.locator('input[name="dateOfPayment"]').fill(today);

    await page.getByRole('button', { name: 'Next' }).click();

    // --- STEP 3: DOCUMENT REQUEST (Document & Purpose Selection) ---
    await expect(page.getByRole('heading', { name: 'Select documents' })).toBeVisible();
    await expect(page.getByText('Documents & Certifications')).toBeVisible();

    // 'Transcript of Records' should already be selected — auto-filled
    // from the verify-or suggestion above — so just confirm the
    // auto-fill banner and the pill are present rather than re-selecting.
    await expect(page.getByText(/Auto-filled from OR #1234567/)).toBeVisible();
    await expect(page.getByText('Transcript of Records', { exact: true })).toBeVisible();

    // Verify the "Couldn't match automatically" unresolved items box at the bottom
    await expect(page.getByText("Couldn't match automatically")).toBeVisible();
    await expect(page.getByText("3 items")).toBeVisible();
    await expect(page.getByText("CAV/Apostille (DFA) - undergraduate")).toBeVisible();
    await expect(page.getByText("₱620.00 • qty 1")).toBeVisible();
    await expect(page.getByText("CAV/Apostille (DFA) with Special Certification")).toBeVisible();
    await expect(page.getByText("₱1,070.00 • qty 1")).toBeVisible();
    await expect(page.getByText("Additional Unresolved Document")).toBeVisible();
    await expect(page.getByText("₱150.00 • qty 2")).toBeVisible();
    await expect(page.getByText("Select the matching document below, or contact the registrar's office if unsure.")).toBeVisible();

    // Select 'Employment' from purpose dropdown
    await page.locator('div:has(> label:has-text("Purpose of Request")) button').click();
    await page.getByRole('button', { name: 'Employment', exact: true }).click();
    await page.getByRole('button', { name: 'Next' }).click();

    // --- STEP 4: NUMBER OF COPIES & CLAIM TICKET ---
    await expect(page.getByText('Number of copies per document / certificate')).toBeVisible();

    // Click Submit
    await page.getByRole('button', { name: 'Submit' }).click();

    // --- CONFIRMATION DIALOG ---
    await expect(page.getByText('Submit Confirmation')).toBeVisible();
    await page.locator('.modal-overlay-container').getByRole('button', { name: 'Submit' }).click();

    // --- SUCCESS SCREEN ---
    await expect(page.getByText('Please be patient as we process your requested document.')).toBeVisible();
  });

  test('Pressing Enter on OR input verifies OR and advances to next step instead of submitting form', async ({ page }) => {
    let postRequestSubmitted = false;
    await page.route(/\/api\/document-requests$/, async (route) => {
      if (route.request().method() === 'POST') {
        postRequestSubmitted = true;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ success: true, request_id: 502 }),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [] }),
        });
      }
    });

    await page.goto('/student/request');

    // Step 1: Agree to terms and proceed
    await page.locator('input[name="termsAgreed"]').check();
    await page.getByRole('button', { name: 'Next' }).click();

    // Step 2: Fill OR fields
    await page.getByPlaceholder('XXXXXXX').fill('1234567');
    const today = new Date().toISOString().split('T')[0];
    await page.locator('input[name="dateOfPayment"]').fill(today);

    // Press Enter while focused inside the OR number input
    await page.getByPlaceholder('XXXXXXX').press('Enter');

    // Should verify OR and advance to Step 3, without submitting the entire request
    await expect(page.getByRole('heading', { name: 'Select documents' })).toBeVisible();
    await expect(page.getByText(/Auto-filled from OR #1234567/)).toBeVisible();
    expect(postRequestSubmitted).toBe(false);
  });
});