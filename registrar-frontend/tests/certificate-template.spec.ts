import { test, expect } from '@playwright/test';

test.describe('Certificate Template Management E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    // 1. Bypass Terms and Conditions modal
    await context.addInitScript(() => {
      window.localStorage.setItem('hasAgreed', 'true');
    });

    // Enable console logging for debugging
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.message));

    // 2. Mock /api/me to return a Super Admin user
    await page.route(/\/api\/(v1\/)?me$/, async (route) => {
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

    // 3. Mock /api/certifications (anchored so it doesn't swallow /layouts)
    await page.route(/\/api\/(v1\/)?certifications$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          { certificate_type_id: 101, certificate_name: 'Certificate of Enrollment', is_archived: false },
          { certificate_type_id: 102, certificate_name: 'Certificate of Registration', is_archived: false },
          { certificate_type_id: 103, certificate_name: 'Archived Certificate', is_archived: true }
        ]),
      });
    });

    // 4. Mock /api/certifications/layouts
    await page.route(/\/api\/(v1\/)?certifications\/layouts$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          {
            certificate_layout_id: 1,
            certificate_type_id: 101,
            layout_header_left_url: 'http://localhost:8000/logos/main.png',
            layout_header_right_url: 'http://localhost:8000/logos/right.png',
            layout_header_logo_size: 60,
            layout_footer_logo_size: 40,
            layout_footer_urls: ['http://localhost:8000/logos/footer1.png']
          },
          {
            certificate_layout_id: 2,
            certificate_type_id: 102,
            layout_header_left_url: '',
            layout_header_right_url: '',
            layout_header_logo_size: 50,
            layout_footer_logo_size: 30,
            layout_footer_urls: []
          }
        ]),
      });
    });

    // 5. Mock /api/document-types
    await page.route(/\/api\/(v1\/)?document-types$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
      });
    });

    // 6. Mock reference data endpoints
    await page.route(/\/api\/(v1\/)?request-statuses$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
      });
    });

    await page.route(/\/api\/(v1\/)?request-purposes$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
      });
    });

    await page.route(/\/api\/(v1\/)?programs$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
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
          body: JSON.stringify({ data: [] }),
        });
      }
    });
  });

  test('should load editor page, render layout controls, and test state changes', async ({ page }) => {
    await page.goto('/super-admin/documents');

    const logoTab = page.locator('button:has-text("Certificate Logo Management")');
    await expect(logoTab).toBeVisible();
    await logoTab.click();

    const heading = page.locator('h1:has-text("Certificate Template Editor")');
    await expect(heading).toBeVisible();

    const dropdown = page.locator('div:has(> label:has-text("Certificate Type")) button');
    await expect(dropdown).toBeVisible();
    await expect(dropdown).toContainText('Certificate of Enrollment');

    await dropdown.click();
    const option = page.locator('div:has(> label:has-text("Certificate Type")) li button').filter({ hasText: 'Certificate of Registration' });
    await expect(option).toBeVisible();
    await option.click();
    await expect(dropdown).toContainText('Certificate of Registration');

    const mainLogoCheckbox = page.locator('#checkbox-apply-main-logo');
    await expect(mainLogoCheckbox).toBeVisible();
    await expect(mainLogoCheckbox).not.toBeChecked();

    const rightLogoCheckbox = page.locator('#checkbox-apply-right-logo');
    await expect(rightLogoCheckbox).toBeVisible();
    await expect(rightLogoCheckbox).not.toBeChecked();

    await mainLogoCheckbox.check();
    await expect(mainLogoCheckbox).toBeChecked();

    await rightLogoCheckbox.check();
    await expect(rightLogoCheckbox).toBeChecked();

    const undoButton = page.locator('#btn-undo-logo');
    await expect(undoButton).toBeDisabled();

    const redoButton = page.locator('#btn-redo-logo');
    await expect(redoButton).toBeDisabled();
  });

  test('should save layouts correctly', async ({ page }) => {
    let putRequestPayload: any = null;

    // Track PUT requests to layout updates
    await page.route(/\/api\/(v1\/)?certifications\/[^/]+\/layout/, async (route) => {
      putRequestPayload = route.request().postDataJSON();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            certificate_type_id: 101,
            header_left_url: 'http://localhost:8000/logos/main_new.png',
            header_right_url: 'http://localhost:8000/logos/right.png',
            header_logo_size: 60,
            footer_logo_size: 40,
            footer_urls: []
          }
        }),
      });
    });

    await page.goto('/super-admin/documents');
    await page.locator('button:has-text("Certificate Logo Management")').click();

    const saveButton = page.locator('#btn-save-layout');
    await expect(saveButton).toBeVisible();

    const responsePromise = page.waitForResponse(/\/api\/(v1\/)?certifications\/[^/]+\/layout/);
    await saveButton.click();
    await responsePromise;

    expect(putRequestPayload).not.toBeNull();
    expect(putRequestPayload.layout_header_logo_size).toBe(60);
  });

  test('should show read-only alert and lock editing when selection is archived', async ({ page }) => {
    await page.goto('/super-admin/documents');
    await page.locator('button:has-text("Certificate Logo Management")').click();

    const dropdown = page.locator('div:has(> label:has-text("Certificate Type")) button');
    await expect(dropdown).toBeVisible();
    await dropdown.click();

    const option = page.locator('div:has(> label:has-text("Certificate Type")) li button').filter({ hasText: 'Archived Certificate' });
    await expect(option).toBeVisible();
    await option.click();

    const archivedWarning = page.locator('text=This certificate is archived — the template is read-only');
    await expect(archivedWarning).toBeVisible();

    const resetLogosButton = page.locator('#btn-reset-logos');
    await expect(resetLogosButton).toBeDisabled();

    const saveButton = page.locator('#btn-save-layout');
    await expect(saveButton).toHaveText('Archived — Read Only');
    await expect(saveButton).toBeDisabled();
  });
});