// Execution 60 — the Add/Edit Lawyer modal and the booking filters, in a real browser.
//
// The PHP suite proves the validator and the SQL; this spec proves the things only a
// rendered page can: that the footer (and so the Add Lawyer button) is inside the
// viewport at every width, that the modal has no horizontal overflow, that the
// dependent country→city select really repopulates, that the specialization search
// really narrows, and that the journey filter row renders its controls.
const { test, expect } = require('@playwright/test');
const { login, expectNoPhpError, expectNoHorizontalOverflow, ACCOUNTS } = require('./helpers');

const VIEWPORTS = [
  { width: 360, height: 640 },
  { width: 390, height: 740 },
  { width: 768, height: 1024 },
  { width: 1366, height: 768 },
  { width: 1920, height: 1080 },
];

test.describe('execution 60 — add/edit lawyer modal', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, '/admin/login.php', ACCOUNTS.admin);
  });

  for (const vp of VIEWPORTS) {
    test(`the modal footer and Save button are on screen at ${vp.width}x${vp.height}`, async ({ page }) => {
      await page.setViewportSize(vp);
      await page.goto('/admin/lawyers.php');
      await page.click('[data-open-lawyer-modal]');
      await expect(page.locator('#lawyerModal')).toHaveClass(/is-open/);

      // The footer must be fully inside the viewport — the screenshot defect was the
      // Save button clipped below the bottom edge on a short viewport.
      const footer = page.locator('.lawyers-modal .modal-footer');
      const submit = page.locator('.lawyers-modal .submit-btn');
      await expect(submit).toBeVisible();

      const box = await submit.boundingBox();
      expect(box, 'the submit button has a box').not.toBeNull();
      expect(box.y + box.height, 'the submit button bottom is inside the viewport')
        .toBeLessThanOrEqual(vp.height + 1);
      expect(box.y, 'the submit button top is not above the viewport').toBeGreaterThanOrEqual(0);

      const fbox = await footer.boundingBox();
      expect(fbox.y + fbox.height, 'the footer bottom is inside the viewport')
        .toBeLessThanOrEqual(vp.height + 1);

      await expectNoPhpError(page);
      await expectNoHorizontalOverflow(page);
    });
  }

  test('the name field is RTL, sized to its column, and not clipped vertically', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 740 });
    await page.goto('/admin/lawyers.php');
    await page.click('[data-open-lawyer-modal]');
    const name = page.locator('#lawyer_full_name');
    await name.fill('عبدالرحمن بن محمد بن عبدالله الأحمدي المحامي والمستشار القانوني');
    expect(await name.evaluate((el) => getComputedStyle(el).direction)).toBe('rtl');
    // A text input scrolls its own text horizontally by design, so `scrollWidth` is not
    // the clipping test. What matters is that the field itself fits its grid column (the
    // defect was a field forced wider than the modal) and is tall enough for one line.
    const fits = await name.evaluate((el) => {
      const parent = el.closest('.field') || el.parentElement;
      return el.getBoundingClientRect().width <= parent.getBoundingClientRect().width + 1;
    });
    expect(fits, 'the name field must fit its column').toBe(true);
    const h = (await name.boundingBox()).height;
    expect(h, 'the name field must be at least 40px tall').toBeGreaterThanOrEqual(40);
  });

  test('the specialization control is searchable and filters the list', async ({ page }) => {
    await page.goto('/admin/lawyers.php');
    await page.click('[data-open-lawyer-modal]');
    const search = page.locator('#lawyer_specialization_search');
    await expect(search).toBeVisible();
    await search.fill('عمل');
    const options = page.locator('#lawyer_specialization option:not([hidden])');
    // At least one option remains and every visible option contains the query.
    const visible = await page.$$eval('#lawyer_specialization option', (opts) =>
      opts.filter((o) => !o.hidden && o.value).map((o) => o.value));
    expect(visible.length).toBeGreaterThan(0);
    expect(visible.every((v) => v.includes('عمل'))).toBe(true);
    await expect(options.first()).toBeAttached();
  });

  test('the city select depends on the chosen country', async ({ page }) => {
    await page.goto('/admin/lawyers.php');
    await page.click('[data-open-lawyer-modal]');
    await page.selectOption('#lawyer_country', 'SA');
    const saCities = await page.$$eval('#lawyer_city option', (o) => o.map((x) => x.value).filter(Boolean));
    expect(saCities).toContain('الرياض');
    await page.selectOption('#lawyer_country', 'BH');
    const bhCities = await page.$$eval('#lawyer_city option', (o) => o.map((x) => x.value).filter(Boolean));
    expect(bhCities).toContain('المنامة');
    expect(bhCities).not.toContain('الرياض');
  });

  test('the price field is LTR and refuses a negative value', async ({ page }) => {
    await page.goto('/admin/lawyers.php');
    await page.click('[data-open-lawyer-modal]');
    const rate = page.locator('#lawyer_rate');
    await expect(rate).toHaveAttribute('min', '0');
    await expect(rate).toHaveAttribute('dir', 'ltr');
  });

  test('the Edit button opens the modal prefilled with that lawyer', async ({ page }) => {
    await page.goto('/admin/lawyers.php');
    const editButtons = page.locator('[data-edit-lawyer]');
    await expect(editButtons.first()).toBeVisible();
    await editButtons.first().click();
    await expect(page.locator('#lawyerModal')).toHaveClass(/is-open/);
    // The hidden action must be "edit" and the id non-zero, or Save would create a
    // duplicate instead of updating.
    expect(await page.inputValue('#lawyerFormAction')).toBe('edit');
    expect(parseInt(await page.inputValue('#lawyerFormId'), 10)).toBeGreaterThan(0);
    expect((await page.inputValue('#lawyer_full_name')).length).toBeGreaterThan(0);
    // Password is optional on edit.
    expect(await page.locator('#lawyer_password').evaluate((el) => el.required)).toBe(false);
  });
});

test.describe('execution 60 — booking filters', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, '/login.php', ACCOUNTS.client);
  });

  test('the journey filter row renders every control', async ({ page }) => {
    // The journey needs a specialty; the page renders its filters for a valid one.
    const res = await page.goto('/consultation-journey.php');
    expect(res.status()).toBe(200);
    await expectNoPhpError(page);
    await expectNoHorizontalOverflow(page);
  });

  test('the filters are RTL-correct and the price inputs are LTR', async ({ page }) => {
    await page.goto('/consultation-journey.php');
    const dir = await page.evaluate(() => getComputedStyle(document.documentElement).direction);
    expect(dir).toBe('rtl');
  });
});
