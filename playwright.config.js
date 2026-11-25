const fs = require('node:fs');
const path = require('node:path');
const { defineConfig } = require('@playwright/test');

const envFile = path.resolve(__dirname, '.env.e2e');
if (fs.existsSync(envFile)) {
	for (const rawLine of fs.readFileSync(envFile, 'utf8').split(/\r?\n/)) {
		const line = rawLine.trim();
		if (!line || line.startsWith('#')) continue;
		const eq = line.indexOf('=');
		if (eq === -1) continue;
		const key = line.slice(0, eq).trim();
		let value = line.slice(eq + 1).trim();
		if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
			value = value.slice(1, -1);
		}
		if (key && process.env[key] === undefined) {
			process.env[key] = value;
		}
	}
}

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
