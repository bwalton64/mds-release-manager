const { expect, test } = require('@playwright/test');

test('network activation makes Release and Artist archives routable', async ({ page }) => {
	for (const archive of ['release', 'artist']) {
		const response = await page.goto(`/${archive}/`);
		expect(response, `${archive} archive should respond`).not.toBeNull();
		expect(response.status(), `${archive} archive should resolve after activation`).toBe(200);
	}
});
