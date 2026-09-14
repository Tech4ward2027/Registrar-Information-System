import { test, expect } from '@playwright/test';

test.describe('Landing Page Tests', () => {
  
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('1. Verify page elements and branding links', async ({ page }) => {
    // Assert main header titles are visible
    await expect(page.getByRole('heading', { name: /Registrar/i, level: 1 })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Information System/i, level: 1 })).toBeVisible();
    await expect(page.getByText('Academic Request. Redefined Simplicity.')).toBeVisible();

    // Verify presence of PUP Logo image
    const logo = page.locator('header img[alt="PUP Logo"]');
    await expect(logo).toBeVisible();

    // Verify external branding footer links exist with correct attributes
    const termsLink = page.getByRole('link', { name: 'Terms of Use' });
    await expect(termsLink).toHaveAttribute('href', 'https://www.pup.edu.ph/terms/');
    await expect(termsLink).toHaveAttribute('target', '_blank');

    const privacyLink = page.getByRole('link', { name: 'Privacy Statement' });
    await expect(privacyLink).toHaveAttribute('href', 'https://www.pup.edu.ph/privacy/');
    await expect(privacyLink).toHaveAttribute('target', '_blank');
  });

  test('2. Password visibility toggle works correctly', async ({ page }) => {
    // Open the local login modal
    await page.getByRole('button', { name: /Need to log in locally/i }).click();

    const passwordInput = page.getByPlaceholder('Password');
    
    // Default mode should be password
    await expect(passwordInput).toHaveAttribute('type', 'password');

    // Type a sample password
    await passwordInput.fill('secretPassword123');

    // Click the toggle visibility button (the eye slash button)
    const toggleButton = page.locator('input[placeholder="Password"] + button');
    await toggleButton.click();

    // It should now be visible as a text input
    await expect(passwordInput).toHaveAttribute('type', 'text');

    // Click the toggle button again
    await toggleButton.click();

    // It should go back to password type
    await expect(passwordInput).toHaveAttribute('type', 'password');
  });

  test('3. Client-side form validation handling', async ({ page }) => {
    // Open the local login modal
    await page.getByRole('button', { name: /Need to log in locally/i }).click();

    // Clear any autofill and click "Sign In Locally" with empty fields
    await page.getByPlaceholder('Email Address').fill('');
    await page.getByPlaceholder('Password').fill('');
    await page.locator('form').getByRole('button', { name: /Sign In Locally/i }).click();

    // Should display email required warning
    await expect(page.getByText('Email is required.')).toBeVisible();

    // Fill email but leave password empty and submit
    await page.getByPlaceholder('Email Address').fill('test@example.com');
    await page.locator('form').getByRole('button', { name: /Sign In Locally/i }).click();

    // Should display password required warning
    await expect(page.getByText('Password is required.')).toBeVisible();
  });

  test('4. Failed login display message', async ({ page }) => {
    // Intercept and mock local-login POST request to return a 401 error
    await page.route('**/api/auth/local-login', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Invalid credentials.' }),
      });
    });

    // Open the local login modal
    await page.getByRole('button', { name: /Need to log in locally/i }).click();

    await page.getByPlaceholder('Email Address').fill('wrong@example.com');
    await page.getByPlaceholder('Password').fill('wrongpassword');
    await page.locator('form').getByRole('button', { name: /Sign In Locally/i }).click();

    // Should display the returned error message
    await expect(page.getByText('Invalid credentials.')).toBeVisible();
  });

  test('5. System Announcements slideshow works', async ({ page }) => {
    // Mock the announcements API response
    await page.route('**/api/announcements*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { id: 1, title: 'Maintenance Notice', content: 'Scheduled maintenance on Sunday.', enabled: true },
            { id: 2, title: 'Holiday Announcement', content: 'No classes on Monday.', enabled: true },
            { id: 3, title: 'Enrollment Open', content: 'Second semester enrollment is open.', enabled: true },
            { id: 4, title: 'System Update', content: 'New features deployed.', enabled: true },
          ],
          last_page: 1,
        }),
      });
    });

    // Reload the page to load mocked announcements
    await page.goto('/');

    // Assert that the first page of announcements is visible
    await expect(page.getByRole('heading', { name: 'MAINTENANCE NOTICE' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'HOLIDAY ANNOUNCEMENT' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'ENROLLMENT OPEN' })).toBeVisible();

    // Verify slide indicator dot for slide 2 is visible
    const nextBtn = page.getByLabel('Go to slide 2');
    await expect(nextBtn).toBeVisible();

    // Click slide 2 indicator dot
    await nextBtn.click();

    // Assert that the 4th announcement is now visible
    await expect(page.getByRole('heading', { name: 'SYSTEM UPDATE' })).toBeVisible();
  });

  test('6. Frequently asked questions section works correctly', async ({ page }) => {
    // Check that FAQ section header is visible
    await expect(page.getByRole('heading', { name: 'Frequently asked questions', level: 2 })).toBeVisible();

    // Check that the first FAQ question is visible
    const firstQuestion = page.getByText('What is the Registrar Information System (RIS)?');
    await expect(firstQuestion).toBeVisible();

    // Answer container should have collapsed class (max-h-0) initially
    const firstAnswerContainer = page.locator('p', { hasText: 'The RIS is a secure' }).locator('..');
    await expect(firstAnswerContainer).toHaveClass(/max-h-0/);

    // Click the question to toggle visibility of the answer
    await firstQuestion.click();
    await expect(firstAnswerContainer).toHaveClass(/max-h-40/);

    // Go to FAQ page 2
    const page2Button = page.getByLabel('Go to FAQ page 2');
    await expect(page2Button).toBeVisible();
    await page2Button.click();

    // Question from page 2 should now be visible
    const page2Question = page.getByText('Is my personal data protected?');
    await expect(page2Question).toBeVisible();
  });

  test('7. Copyright Footer text renders dynamic year', async ({ page }) => {
    const currentYear = new Date().getFullYear();
    const copyrightRegex = new RegExp(`© 1998–${currentYear} Polytechnic University of the Philippines`, 'i');
    await expect(page.getByText(copyrightRegex)).toBeVisible();
  });
});
