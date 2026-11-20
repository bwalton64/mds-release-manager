const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
	testDir: './tests/e2e',
	globalSetup: './tests/e2e/global-setup.js',
	workers: 1,
	reporter: 'list',
	use: {
		baseURL: process.env.MDS_RM_TEST_URL,
		browserName: 'chromium',
		headless: true,
	},
});
