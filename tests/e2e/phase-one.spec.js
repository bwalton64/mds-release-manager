const { expect, test: base } = require('@playwright/test');
const { join } = require('node:path');
const { readWpJson, runWpCli } = require('./wp-cli');

const script = join(__dirname, 'smoke-fixtures.php');
const test = base.extend({
	fixture: [async ({}, use) => {
		const fixture = readWpJson(['eval-file', script, 'seed']);
		try {
			await use(fixture);
		} finally {
			runWpCli(['eval-file', script, 'cleanup'], { MDS_RM_FIXTURE_STATE: JSON.stringify(fixture.state) });
		}
	}, { timeout: 30_000 }],
});

async function allowLocalRequests(page) {
	const origin = new URL(process.env.MDS_RM_TEST_URL).origin;
	await page.route('**/*', (route) => new URL(route.request().url()).origin === origin
		? route.continue()
		: route.abort());
}

async function login(page, fixture) {
	await page.goto('/wp-login.php');
	await page.getByLabel('Username or Email Address').fill(fixture.username);
	await page.getByLabel('Password', { exact: true }).fill(fixture.password);
	await page.getByRole('button', { name: 'Log In' }).click();
	await expect(page).toHaveURL(/\/wp-admin\//);
}

async function openBlockEditor(page, postId) {
	await page.goto(`/wp-admin/post.php?post=${postId}&action=edit`);
	const welcome = page.getByRole('dialog', { name: 'Welcome to the editor' });
	const canvas = page.locator('iframe[name="editor-canvas"], .block-editor-block-list__layout').first();
	await expect(welcome.or(canvas).filter({ visible: true }).first()).toBeVisible();
	if (await welcome.isVisible()) {
		await welcome.getByRole('button', { name: 'Close', exact: true }).click();
	}
}

async function trackLookupRequests(page) {
	await page.evaluate(() => {
		window.__phaseOneLookupCalls = [];
		window.__phaseOneUnhandled = [];
		window.addEventListener('unhandledrejection', (event) => {
			window.__phaseOneUnhandled.push(String(event.reason));
		});

		const originalApiFetch = wp.apiFetch;
		wp.apiFetch = function (options) {
			const path = String(options.path || '');
			const lookup = path.includes('/mds/v1/release-links-choices')
				|| path.includes('/mds/v1/release-links-source')
				|| path.startsWith('/wp/v2/artist');
			if (!lookup) {
				return originalApiFetch.apply(this, arguments);
			}

			return new Promise((resolve, reject) => {
				const request = { path, signal: options.signal };
				window.__phaseOneLookupCalls.push(request);
				options.signal.addEventListener('abort', () => {
					const error = new Error('Request aborted');
					error.name = 'AbortError';
					reject(error);
				}, { once: true });
			});
		};
	});
}

test('clipboard denial is handled and shown to the user', async ({ page }) => {
	await allowLocalRequests(page);
	await page.goto('/');
	await page.setContent('<span class="mds-shortcode-copy">[release-meta]</span>');
	await page.addScriptTag({ url: '/wp-includes/js/jquery/jquery.js' });
	await page.evaluate(() => {
		window.__phaseOneUnhandled = [];
		window.addEventListener('unhandledrejection', (event) => {
			window.__phaseOneUnhandled.push(String(event.reason));
		});
		Object.defineProperty(navigator, 'clipboard', {
			configurable: true,
			value: { writeText: () => Promise.reject(new Error('Clipboard permission denied.')) },
		});
	});
	await page.addScriptTag({ path: join(__dirname, '..', '..', 'js', 'meta-boxes.js') });

	const copy = page.locator('.mds-shortcode-copy');
	await copy.click();
	await expect(copy).toHaveText('Copy failed');
	await expect.poll(() => page.evaluate(() => window.__phaseOneUnhandled)).toEqual([]);
});

test('editor lookup requests abort when their blocks unmount', async ({ page, fixture }) => {
	await allowLocalRequests(page);
	await login(page, fixture);
	await openBlockEditor(page, fixture.state.host);
	await trackLookupRequests(page);

	await page.evaluate(() => {
		window.mdsRmCatalog = { preloaded: false, releases: [] };
		const blocks = [
			wp.blocks.createBlock('mds-rm/release-links'),
			wp.blocks.createBlock('mds-rm/artist-discography'),
		];
		wp.data.dispatch('core/block-editor').insertBlocks(blocks);
		wp.data.dispatch('core/block-editor').selectBlock(blocks[0].clientId);
	});
	await expect.poll(() => page.evaluate(() => window.__phaseOneLookupCalls.some((call) => call.path.includes('/mds/v1/release-links-choices')))).toBe(true);
	await page.evaluate(() => {
		const blocks = wp.data.select('core/block-editor').getBlocks();
		const disco = blocks.find((b) => b.name === 'mds-rm/artist-discography');
		if (disco) wp.data.dispatch('core/block-editor').selectBlock(disco.clientId);
	});
	await expect.poll(() => page.evaluate(() => window.__phaseOneLookupCalls.some((call) => call.path.startsWith('/wp/v2/artist')))).toBe(true);

	await page.evaluate(() => {
		const dispatch = wp.data.dispatch('core/block-editor');
		const ids = wp.data.select('core/block-editor').getBlocks().map((block) => block.clientId);
		ids.forEach((id) => dispatch.removeBlock(id));
	});
	await expect.poll(() => page.evaluate(() => window.__phaseOneLookupCalls.every((call) => call.signal.aborted))).toBe(true);
	await expect.poll(() => page.evaluate(() => window.__phaseOneUnhandled)).toEqual([]);
});

test('editor lookup requests time out and surface an error', async ({ page, fixture }) => {
	test.setTimeout(35_000);
	await allowLocalRequests(page);
	await login(page, fixture);
	await openBlockEditor(page, fixture.state.host);
	await trackLookupRequests(page);

	await page.evaluate(() => {
		window.mdsRmLookupTimeoutMs = 400;
		window.mdsRmCatalog = { preloaded: false, releases: [] };
		const block = wp.blocks.createBlock('mds-rm/release-links');
		wp.data.dispatch('core/block-editor').insertBlocks(block);
		wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
	});
	await expect.poll(() => page.evaluate(() => window.__phaseOneLookupCalls.length)).toBeGreaterThan(0);
	await expect.poll(() => page.evaluate(() => window.__phaseOneLookupCalls.every((call) => call.signal.aborted)), {
		timeout: 22_000,
		intervals: [100, 250, 500, 1000],
	}).toBe(true);
	await expect(page.getByRole('tabpanel', { name: 'Settings' })
		.getByText('Release search failed. Change the search to try again.', { exact: true })).toBeVisible({ timeout: 10_000 });
	await expect.poll(() => page.evaluate(() => window.__phaseOneUnhandled)).toEqual([]);
});
