import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const PAGES = [
  { url: '/login',          title: 'Login' },
  { url: '/companies',      title: 'Companies' },
  { url: '/coverage',       title: 'Coverage' },
  { url: '/rgpd/requests',  title: 'RGPD' },
];

for (const { url, title } of PAGES) {
  test(`${title} has no critical a11y violations`, async ({ page }) => {
    await page.goto(url);
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
      .analyze();

    const critical = results.violations.filter((v) => v.impact === 'critical');
    expect(critical).toEqual([]);
  });
}
