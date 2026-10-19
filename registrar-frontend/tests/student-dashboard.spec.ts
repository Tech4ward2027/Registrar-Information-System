import { test, expect } from '@playwright/test';

test.describe('Student Dashboard E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    // Add init script to bypass the Terms and Conditions modal
    await context.addInitScript(() => {
      window.localStorage.setItem('hasAgreed', 'true');
    });

    // 1. Mock the /me endpoint to authenticate as a Student (role: student)
    await page.route(/\/api\/(v1\/)?me$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user_id: 42,
            email: 'student@example.com',
            name: 'Student User',
            role_name: 'student',
            policies: []
          }
        }),
      });
    });

    // 2. Mock reference status list
    await page.route(/\/api\/(v1\/)?request-statuses$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          { status_id: 1, status_name: 'Processing' },
          { status_id: 2, status_name: 'Ready to Claim' },
          { status_id: 3, status_name: 'Completed' },
          { status_id: 4, status_name: 'Forfeited' }
        ]),
      });
    });

    // 3. Mock other lookup data
    await page.route(/\/api\/(v1\/)?document-types$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      });
    });
    await page.route(/\/api\/(v1\/)?certifications$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      });
    });
    await page.route(/\/api\/(v1\/)?request-purposes$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      });
    });
    await page.route(/\/api\/(v1\/)?programs$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      });
    });

    // 4. Mock announcements
    await page.route(/\/api\/(v1\/)?announcements/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], last_page: 1 }),
      });
    });

    // Mock notifications endpoints (which run inside NotificationsProvider on mount)
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

    // 5. Mock document requests (one for user 42, one ready for user 42, and one for user 99)
    await page.route(/\/api\/(v1\/)?document-requests(\?.*)?$/, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              request_id: 201,
              user_id: 42,
              requested_at: new Date().toISOString(),
              status_id: 1,
              is_archived: 0,
              status: { status_id: 1, status_name: 'Processing' },
              request_purpose: { purpose_name: 'Employment - Local' },
              documents: [
                {
                  request_document_id: 1001,
                  document_type_id: 5,
                  number_of_copies: 1,
                  document_type: { document_name: 'Transcript of Records' }
                }
              ]
            },
            {
              request_id: 202,
              user_id: 42,
              requested_at: new Date().toISOString(),
              status_id: 2,
              is_archived: 0,
              status: { status_id: 2, status_name: 'Ready to Claim' },
              request_purpose: { purpose_name: 'Further Studies' },
              documents: [
                {
                  request_document_id: 1002,
                  document_type_id: 2,
                  number_of_copies: 2,
                  document_type: { document_name: 'Course Subject Description' }
                }
              ]
            },
            {
              request_id: 203,
              user_id: 99, // Different student! Should be filtered out
              requested_at: new Date().toISOString(),
              status_id: 1,
              is_archived: 0,
              status: { status_id: 1, status_name: 'Processing' },
              request_purpose: { purpose_name: 'Employment - Local' },
              documents: [
                {
                  request_document_id: 1003,
                  document_type_id: 5,
                  number_of_copies: 1,
                  document_type: { document_name: 'Transcript of Records' }
                }
              ]
            }
          ]
        }),
      });
    });
  });

  test('1. Verify student dashboard only displays own pending requests', async ({ page }) => {
    // Navigate directly to the student portal (redirects to /student/home)
    await page.goto('/student/home');

    // The default tab is 'Pending'. Verify Request 201 (Transcript of Records) is visible
    await expect(page.getByText('Transcript of Records')).toBeVisible();

    // Verify Request 203 (belonging to user 99) is NOT rendered, even though it is also pending
    // We check this by verifying that only 1 request card (representing by h4 tag) is rendered in the list
    await expect(page.locator('h4')).toHaveCount(1); 
  });

  test('2. Verify tab switching filters correctly', async ({ page }) => {
    await page.goto('/student/home');

    // In Pending tab: Transcript of Records is visible, Course Description is not
    await expect(page.getByText('Transcript of Records')).toBeVisible();
    await expect(page.getByText('Course Subject Description')).not.toBeVisible();

    // Click 'To Claim' tab (value: 'ready')
    const toClaimTab = page.locator('button:has-text("To Claim")');
    await toClaimTab.click();

    // Now Course Description is visible, Transcript of Records is not
    await expect(page.getByText('Course Subject Description')).toBeVisible();
    await expect(page.getByText('Transcript of Records')).not.toBeVisible();
  });

  test('3. Verify details modal displays request details', async ({ page }) => {
    await page.goto('/student/home');

    // Click the Transcript of Records card (events bubble up to the card div)
    await page.getByRole('heading', { name: 'Transcript of Records' }).click();

    // Modal should show up containing request info
    const modal = page.locator('.fixed');
    await expect(modal.getByRole('heading', { name: 'Request Details' })).toBeVisible();
    await expect(modal.getByText('Transcript of Records')).toBeVisible();
    await expect(modal.getByText('Employment - Local')).toBeVisible();

    // Close the modal via the close icon button using its aria-label
    await page.getByLabel('Close request details').click();
    await expect(page.getByRole('heading', { name: 'Request Details' })).not.toBeVisible();
  });

  test('4. Verify search input works correctly', async ({ page }) => {
    await page.goto('/student/home');
    
    // Switch to To Claim tab to search
    const toClaimTab = page.locator('button:has-text("To Claim")');
    await toClaimTab.click();
    await expect(page.getByText('Course Subject Description')).toBeVisible();

    // Type a mismatch in search bar
    const searchInput = page.getByPlaceholder(/search/i);
    await searchInput.fill('Transcript');

    // Should hide the Course Subject Description
    await expect(page.getByText('Course Subject Description')).not.toBeVisible();

    // Clear search and type matching search
    await searchInput.fill('Subject');
    await expect(page.getByText('Course Subject Description')).toBeVisible();
  });
});