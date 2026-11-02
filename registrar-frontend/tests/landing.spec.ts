import { test, expect } from '@playwright/test';

test.describe('Landing Page Tests', () => {
  test('1. Verify page elements and branding links', async ({ page }) => {
    await page.goto('/');

    // Verify main headings and tagline
    await expect(
      page.getByRole('heading', { name: /Registrar/i, level: 1 })
    ).toBeVisible();

    await expect(
      page.getByRole('heading', { name: /Information System/i, level: 1 })
    ).toBeVisible();

    await expect(
      page.getByText('Academic Request. Redefined Simplicity.')
    ).toBeVisible();

    // Verify PUP logo
    const logo = page.locator('header img[alt="PUP Logo"]');
    await expect(logo).toBeVisible();

    // Verify Terms of Use link
    const termsLink = page.getByRole('link', {
      name: 'Terms of Use',
    });

    await expect(termsLink).toHaveAttribute(
      'href',
      'https://www.pup.edu.ph/terms/'
    );

    await expect(termsLink).toHaveAttribute('target', '_blank');

    // Verify Privacy Statement link
    const privacyLink = page.getByRole('link', {
      name: 'Privacy Statement',
    });

    await expect(privacyLink).toHaveAttribute(
      'href',
      'https://www.pup.edu.ph/privacy/'
    );

    await expect(privacyLink).toHaveAttribute('target', '_blank');
  });

  test('2. Password visibility toggle works correctly', async ({ page }) => {
    await page.goto('/');

    // Open local login modal
    await page.getByRole('button', { name: /Log in locally/i }).click();

    const passwordInput = page.getByPlaceholder('Password');

    // Password should be hidden initially
    await expect(passwordInput).toHaveAttribute('type', 'password');

    await passwordInput.fill('secretPassword123');

    // Toggle password visibility
    const toggleButton = page.locator(
      'input[placeholder="Password"] + button'
    );

    await toggleButton.click();

    await expect(passwordInput).toHaveAttribute('type', 'text');

    // Hide password again
    await toggleButton.click();

    await expect(passwordInput).toHaveAttribute('type', 'password');
  });

  test('3. Client-side form validation handling', async ({ page }) => {
    await page.goto('/');

    await page.getByRole('button', { name: /Log in locally/i }).click();

    const emailInput = page.getByPlaceholder('Email Address');
    const passwordInput = page.getByPlaceholder('Password');

    const submitButton = page
      .locator('form')
      .getByRole('button', { name: /Sign In Locally/i });

    // Submit empty fields
    await emailInput.fill('');
    await passwordInput.fill('');

    await submitButton.click();

    await expect(
      page.getByText('Email is required.')
    ).toBeVisible();

    // Enter email but leave password empty
    await emailInput.fill('test@example.com');

    await submitButton.click();

    await expect(
      page.getByText('Password is required.')
    ).toBeVisible();
  });

  test('4. Failed login display message', async ({ page }) => {
    // Mock local login API before navigation
    await page.route(
      /\/api\/(?:v1\/)?auth\/local-login$/,
      async (route) => {
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: JSON.stringify({
            message: 'Invalid credentials.',
          }),
        });
      }
    );

    await page.goto('/');

    await page.getByRole('button', {
      name: /Log in locally/i,
    }).click();

    await page
      .getByPlaceholder('Email Address')
      .fill('wrong@example.com');

    await page
      .getByPlaceholder('Password')
      .fill('wrongpassword');

    await page
      .locator('form')
      .getByRole('button', {
        name: /Sign In Locally/i,
      })
      .click();

    await expect(
      page.getByText('Invalid credentials.')
    ).toBeVisible();
  });

  test('5. System Announcements slideshow works', async ({ page }) => {
    // Mock announcements API before loading the landing page
    const announcementsResponse = {
      data: [
        {
          id: 1,
          title: 'Maintenance Notice',
          content: 'Scheduled maintenance on Sunday.',
          enabled: true,
        },
        {
          id: 2,
          title: 'Holiday Announcement',
          content: 'No classes on Monday.',
          enabled: true,
        },
        {
          id: 3,
          title: 'Enrollment Open',
          content: 'Second semester enrollment is open.',
          enabled: true,
        },
        {
          id: 4,
          title: 'System Update',
          content: 'New features deployed.',
          enabled: true,
        },
      ],
      last_page: 1,
    };

    await page.route(
      /\/api\/(?:v1\/)?announcements(?:\/.*)?(?:\?.*)?$/,
      async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(announcementsResponse),
        });
      }
    );

    // Optional: log failed requests for debugging
    page.on('requestfailed', (request) => {
      console.log(
        'FAILED REQUEST:',
        request.url(),
        request.failure()?.errorText
      );
    });

    await page.goto('/');

    // Verify initial announcements
    await expect(
      page.getByRole('heading', {
        name: /MAINTENANCE NOTICE/i,
      })
    ).toBeVisible();

    await expect(
      page.getByRole('heading', {
        name: /HOLIDAY ANNOUNCEMENT/i,
      })
    ).toBeVisible();

    await expect(
      page.getByRole('heading', {
        name: /ENROLLMENT OPEN/i,
      })
    ).toBeVisible();

    // Navigate to the next slide
    const nextSlideButton = page.getByLabel('Go to slide 2');

    await expect(nextSlideButton).toBeVisible();

    await nextSlideButton.click();

    // Verify fourth announcement
    await expect(
      page.getByRole('heading', {
        name: /SYSTEM UPDATE/i,
      })
    ).toBeVisible();
  });

  test('6. Frequently asked questions section works correctly', async ({
    page,
  }) => {
    await page.goto('/');

    // Verify Common Questions button
    const commonQuestionsButton = page.getByRole('button', {
      name: /Common questions/i,
    });

    await expect(commonQuestionsButton).toBeVisible();

    // Open Common Questions
    await commonQuestionsButton.click();

    // Verify Common Questions region
    const commonQuestionsRegion = page.getByRole('region', {
      name: /Common questions/i,
    });

    await expect(commonQuestionsRegion).toBeVisible();

    // Verify FAQ items inside Common questions
    await expect(
      commonQuestionsRegion.getByText('How do I log in to RIS?')
    ).toBeVisible();
  });

  test('7. Copyright Footer text renders dynamic year', async ({ page }) => {
    await page.goto('/');

    const currentYear = new Date().getFullYear();

    const copyrightRegex = new RegExp(
      `© 1998–${currentYear} Polytechnic University of the Philippines`,
      'i'
    );

    await expect(
      page.getByText(copyrightRegex)
    ).toBeVisible();
  });
});