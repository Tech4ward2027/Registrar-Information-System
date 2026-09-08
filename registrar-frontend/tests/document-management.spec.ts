import { test, expect } from '@playwright/test';

test.describe('Document and Certificate Management E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    // 1. Bypass Terms and Conditions modal
    await context.addInitScript(() => {
      window.localStorage.setItem('hasAgreed', 'true');
    });

    // Enable console logging for debugging
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.message));

    // 2. Mock /api/me to return a Super Admin user
    await page.route('**/api/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user_id: 1,
            email: 'superadmin@example.com',
            name: 'Super Admin User',
            role_name: 'super_admin',
            policies: ['system'],
            effective_permissions: {}
          }
        }),
      });
    });

    // 3. Mock /api/certifications
    await page.route('**/api/certifications*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          {
            certificate_type_id: 101,
            certificate_name: 'Certificate of Enrollment',
            certificate_requirements: 'Registration Card',
            certificate_process_period: '1 working day',
            access_id: 3,
            is_archived: false
          }
        ]),
      });
    });

    // 4. Mock /api/certifications/layouts
    await page.route('**/api/certifications/layouts*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
      });
    });

    // 5. Mock /api/document-types
    await page.route('**/api/document-types*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          {
            document_type_id: 201,
            document_name: 'Official Transcript of Records',
            document_description: 'Official academic transcript',
            document_requirements: 'Clearance, dry seal request',
            document_process_period: '5 working days',
            access_id: 3,
            is_archived: false
          },
          {
            document_type_id: 202,
            document_name: 'Honorable Dismissal',
            document_description: 'Transfer credential letter',
            document_requirements: 'Clearance form',
            document_process_period: '3 working days',
            access_id: 1,
            is_archived: false
          }
        ]),
      });
    });

    // 6. Mock reference data endpoints
    await page.route('**/api/request-statuses', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([]) });
    });
    await page.route('**/api/request-purposes', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([]) });
    });
    await page.route('**/api/programs', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    });
    await page.route('**/api/notifications*', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ count: 0 }) });
    });
  });

  test('should load document and certificate lists, allow searching, and populate form for editing', async ({ page }) => {
    await page.goto('/super-admin/documents');

    // 1. Verify default view loads lists
    const docCard = page.locator('text=Official Transcript of Records');
    await expect(docCard).toBeVisible();

    const certCard = page.locator('text=Certificate of Enrollment');
    await expect(certCard).toBeVisible();

    // 2. Test search filters lists
    const searchInput = page.getByPlaceholder('Search');
    await searchInput.fill('Transcript');
    await expect(page.locator('text=Honorable Dismissal')).not.toBeVisible();
    await expect(page.locator('text=Official Transcript of Records')).toBeVisible();

    // Clear search
    await searchInput.fill('');
    await expect(page.locator('text=Honorable Dismissal')).toBeVisible();

    // 3. Test clicking card populates form for editing
    await docCard.click();
    await expect(page.locator('h2:has-text("Edit Document")')).toBeVisible();
    await expect(page.locator('input[name="document_name"]')).toHaveValue('Official Transcript of Records');
    await expect(page.locator('textarea#document_description')).toHaveValue('Official academic transcript');
    await expect(page.locator('textarea#document_requirements')).toHaveValue('Clearance, dry seal request');
    await expect(page.locator('input[type="number"]').nth(0)).toHaveValue('5');
    await expect(page.locator('text=5 working days')).toBeVisible();
  });

  test('should validate process period and allow saving edits', async ({ page }) => {
    let putRequestPayload: any = null;

    // Intercept update PUT request
    await page.route('**/api/document-types/201', async (route) => {
      if (route.request().method() === 'PUT') {
        putRequestPayload = route.request().postDataJSON();
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            data: {
              document_type_id: 201,
              document_name: 'Official Transcript of Records (Updated)',
              document_description: 'Updated description',
              document_requirements: 'None',
              document_process_period: '10 working days',
              access_id: 3,
              is_archived: false
            }
          })
        });
      }
    });

    await page.goto('/super-admin/documents');
    await page.locator('text=Official Transcript of Records').click();

    // Edit Name
    const nameInput = page.locator('input[name="document_name"]');
    await nameInput.fill('Official Transcript of Records (Updated)');

    // Test Validation - Decimal is invalid
    const periodInput = page.locator('input[type="number"]').nth(0);
    await periodInput.fill('5.5');
    await page.locator('button:has-text("Save Changes")').click();

    // Alert / error message should appear
    await expect(page.locator('text=Process Period must be a whole number between 1 and 30 working days.')).toBeVisible();

    // Test Validation - Out of bounds is invalid
    await periodInput.fill('45');
    await page.locator('button:has-text("Save Changes")').click();
    await expect(page.locator('text=Process Period must be a whole number between 1 and 30 working days.')).toBeVisible();

    // Correct process period (whole number <= 30)
    await periodInput.fill('10');

    const responsePromise = page.waitForResponse(/\/api\/document-types\/201/);
    await page.locator('button:has-text("Save Changes")').click();
    await responsePromise;

    expect(putRequestPayload).not.toBeNull();
    expect(putRequestPayload.document_name).toBe('Official Transcript of Records (Updated)');
    expect(putRequestPayload.document_process_period).toBe('10 working days');
  });

  test('should support creating a new certificate', async ({ page }) => {
    let postRequestPayload: any = null;

    // Intercept POST request
    await page.route('**/api/certifications*', async (route) => {
      if (route.request().method() === 'POST') {
        postRequestPayload = route.request().postDataJSON();
        await route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            data: {
              certificate_type_id: 102,
              certificate_name: 'New Custom Certificate',
              certificate_requirements: 'ID and proof of payment',
              certificate_process_period: '2 working days',
              access_id: 3,
              is_archived: false
            }
          })
        });
      } else {
        await route.fallback();
      }
    });

    await page.goto('/super-admin/documents');
    // Click the tab button (first match) to switch to "Add Certificate" mode
    await page.getByRole('button', { name: 'Add Certificate' }).first().click();

    await expect(page.locator('h2:has-text("Add Certificate")')).toBeVisible();

    await page.locator('input[name="document_name"]').fill('New Custom Certificate');
    await page.locator('textarea#document_requirements').fill('ID and proof of payment');
    await page.locator('input[type="number"]').nth(0).fill('2');
    await page.locator('button:has-text("All")').click();

    const responsePromise = page.waitForResponse(
      (resp) => /\/api\/certifications/.test(resp.url()) && resp.request().method() === 'POST'
    );
    // Click the form submit button (last match)
    await page.getByRole('button', { name: 'Add Certificate' }).last().click();
    await responsePromise;

    expect(postRequestPayload).not.toBeNull();
    expect(postRequestPayload.certificate_name).toBe('New Custom Certificate');
    expect(postRequestPayload.certificate_requirements).toBe('ID and proof of payment');
    expect(postRequestPayload.certificate_process_period).toBe('2 working days');
  });

  test('should display confirmation modal and delete a document', async ({ page }) => {
    let deleteRequestCalled = false;

    // Intercept DELETE request
    await page.route('**/api/document-types/202', async (route) => {
      if (route.request().method() === 'DELETE') {
        deleteRequestCalled = true;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ success: true })
        });
      } else {
        await route.fallback();
      }
    });

    await page.goto('/super-admin/documents');

    // Click trash button on "Honorable Dismissal" card
    // Locate card containing exact text 'Honorable Dismissal' and search for Delete button inside it
    const card = page.locator('.group', { hasText: 'Honorable Dismissal' });
    const trashBtn = card.getByRole('button', { name: 'Delete Document' });
    await expect(trashBtn).toBeVisible();
    await trashBtn.click();

    // Verify modal is shown
    const modalHeading = page.locator('h2:has-text("Delete Confirmation")');
    await expect(modalHeading).toBeVisible();

    // Confirm delete
    const responsePromise = page.waitForResponse(/\/api\/document-types\/202/);
    await page.locator('button:has-text("Delete")').click();
    await responsePromise;

    expect(deleteRequestCalled).toBe(true);
    await expect(page.locator('text=Honorable Dismissal')).not.toBeVisible();
  });
});
