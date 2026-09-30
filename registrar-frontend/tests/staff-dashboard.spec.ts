import { test, expect } from '@playwright/test';

test.describe('Staff Dashboard E2E Tests', () => {

  test.beforeEach(async ({ context, page }) => {
    // 1. Bypass Terms and Conditions modal
    await context.addInitScript(() => {
      window.localStorage.setItem('hasAgreed', 'true');
    });

    // Enable console logging for debugging
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.message));

    // 1. Mock /api/me to return an Admin user with correct dashboard permissions
    await page.route('**/api/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user_id: 1,
            email: 'admin@example.com',
            name: 'Admin User',
            role_name: 'admin',
            policies: ['dashboard', 'analytics', 'logbook'],
            effective_permissions: {
              dashboard: ['read', 'write']
            }
          }
        }),
      });
    });

    // 2. Mock /api/document-requests lookup list
    await page.route('**/api/document-requests*', async (route) => {
      const url = route.request().url();
      const method = route.request().method();

      if (method === 'PUT' || method === 'DELETE' || method === 'POST') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ success: true }),
        });
        return;
      }

      // GET requests
      const parsedUrl = new URL(url);
      const isArchived = parsedUrl.searchParams.get('view') === 'archived';
      const searchParam = parsedUrl.searchParams.get('search')?.toLowerCase();
      
      let mockRequests = isArchived
        ? [
            {
              request_id: 2001,
              requested_at: new Date().toISOString(),
              or_number: 'OR-2001',
              receipt_date: '2026-07-28',
              is_archived: true,
              student_profile: {
                first_name: 'Archived',
                middle_name: 'Request',
                last_name: 'User',
                course: 'BSCS',
                major: '',
                education_level: 'Undergraduate'
              },
              academic_record: {
                student_number: '2019-11111-TG-0',
                sy_admitted: '2019-2020'
              },
              status: {
                status_id: 3,
                status_name: 'Completed'
              },
              documents: [
                {
                  document_type_id: 1,
                  number_of_copies: 1,
                  document_type: {
                    document_name: 'Transcript of Records'
                  }
                }
              ],
              certificates: []
            }
          ]
        : [
            {
              request_id: 1001,
              requested_at: new Date().toISOString(),
              or_number: 'OR-1001',
              receipt_date: '2026-07-28',
              is_archived: false,
              student_profile: {
                first_name: 'Juan',
                middle_name: 'Dela',
                last_name: 'Cruz',
                course: 'BSIT',
                major: '',
                education_level: 'Undergraduate'
              },
              academic_record: {
                student_number: '2020-00123-TG-0',
                sy_admitted: '2020-2021'
              },
              status: {
                status_id: 1,
                status_name: 'Pending'
              },
              documents: [
                {
                  document_type_id: 1,
                  number_of_copies: 2,
                  document_type: {
                    document_name: 'Transcript of Records'
                  }
                }
              ],
              certificates: []
            },
            {
              request_id: 1002,
              requested_at: new Date().toISOString(),
              or_number: 'OR-1002',
              receipt_date: '2026-07-27',
              is_archived: false,
              alumni_profile: {
                first_name: 'Maria',
                middle_name: 'Santos',
                last_name: 'Reyes'
              },
              alumni_academic_record: {
                student_number: '2015-00456-TG-0'
              },
              status: {
                status_id: 2,
                status_name: 'Ready to Claim'
              },
              documents: [
                {
                  document_type_id: 2,
                  number_of_copies: 1,
                  document_type: {
                    document_name: 'Honorable Dismissal'
                  }
                }
              ],
              certificates: []
            }
          ];

      if (searchParam) {
        mockRequests = mockRequests.filter(r => {
          const studentName = `${r.student_profile?.first_name || ''} ${r.student_profile?.last_name || ''}`.toLowerCase();
          const alumniName = `${r.alumni_profile?.first_name || ''} ${r.alumni_profile?.last_name || ''}`.toLowerCase();
          return studentName.includes(searchParam) || alumniName.includes(searchParam);
        });
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockRequests }),
      });
    });

    // 3. Mock metadata endpoints
    await page.route('**/api/request-statuses', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          { status_id: 1, status_name: 'Pending' },
          { status_id: 2, status_name: 'Ready to Claim' },
          { status_id: 3, status_name: 'Completed' }
        ]),
      });
    });

    await page.route('**/api/document-types', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          { document_type_id: 1, document_name: 'Transcript of Records', access_id: 3 },
          { document_type_id: 2, document_name: 'Honorable Dismissal', access_id: 3 }
        ]),
      });
    });

    await page.route('**/api/certifications', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
      });
    });

    await page.route('**/api/request-purposes', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
      });
    });

    await page.route('**/api/programs', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      });
    });

    await page.route('**/api/notifications*', async (route) => {
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

  test('Verify dashboard lists request items, supports searching and tab switching', async ({ page }) => {
    // Navigate to staff dashboard
    await page.goto('/staff/dashboard');

    // 1. Verify Active Requests lists the initial active items
    await expect(page.getByText('Juan Dela Cruz')).toBeVisible();
    await expect(page.getByText('Maria Santos Reyes')).toBeVisible();

    // 2. Test Text Search functionality
    const searchInput = page.getByPlaceholder('Search', { exact: true });
    const searchResponsePromise = page.waitForResponse(resp =>
      resp.url().includes('/api/document-requests') && resp.url().includes('search=Juan')
    );
    await searchInput.fill('Juan');
    await searchResponsePromise;

    await expect(page.getByText('Maria Santos Reyes')).not.toBeVisible();
    await expect(page.getByText('Juan Dela Cruz')).toBeVisible();

    // Clear search
    const clearResponsePromise = page.waitForResponse(resp =>
      resp.url().includes('/api/document-requests') && !resp.url().includes('search=Juan')
    );
    await searchInput.fill('');
    await clearResponsePromise;
    await expect(page.getByText('Maria Santos Reyes')).toBeVisible();

    // 3. Test Details Modal triggers on clicking the View Details button
    await page.locator('tr:has-text("Juan Dela Cruz")').getByRole('button', { name: 'More Actions' }).click();
    await page.locator('tr:has-text("Juan Dela Cruz")').getByRole('button', { name: 'View Details' }).click();
    
    // Check that the request details drawer opens
    await expect(page.getByRole('heading', { name: 'Request Details' })).toBeVisible();
    
    // Close modal (clicking the close button)
    await page.getByRole('button', { name: 'Close request details' }).click();
    await expect(page.getByRole('heading', { name: 'Request Details' })).not.toBeVisible();

    // 4. Test Tab Navigation switching to Archived
    await page.getByRole('button', { name: 'Archived records' }).click();
    await expect(page.getByText('Archived Request User')).toBeVisible();
    await expect(page.getByText('Juan Dela Cruz')).not.toBeVisible();

    // Switch back to Active
    await page.getByRole('button', { name: 'Active requests' }).click();
    await expect(page.getByText('Juan Dela Cruz')).toBeVisible();
  });

  test('Verify opening and using the Scan to Claim modal (tab switching, inputs)', async ({ page }) => {
    // Navigate to staff dashboard
    await page.goto('/staff/dashboard');

    // 1. Verify "Scan to Claim" button is visible next to Search
    const scanBtn = page.getByRole('button', { name: 'Scan to Claim' });
    await expect(scanBtn).toBeVisible();
    await scanBtn.click();

    // 2. Verify modal opened
    await expect(page.getByRole('heading', { name: 'Scan QR code' })).toBeVisible();

    // 3. Verify Scan QR Code tab details
    await expect(page.getByRole('button', { name: 'Scan QR Code' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enter Claim Code' })).toBeVisible();
    await expect(page.getByText('Point your QR code at the camera. If the camera isn\'t working, switch to the "Enter Claim Code" tab.')).toBeVisible();

    // 4. Switch to Enter Claim Code tab
    await page.getByRole('button', { name: 'Enter Claim Code' }).click();
    await expect(page.getByText('Enter the 6-character alphanumeric claim code.')).toBeVisible();

    // 5. Verify passcode input boxes render
    const inputs = page.locator('input[placeholder="0"]');
    await expect(inputs).toHaveCount(6);

    // 6. Close the scanner modal
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('heading', { name: 'Scan QR code' })).not.toBeVisible();
  });
});
