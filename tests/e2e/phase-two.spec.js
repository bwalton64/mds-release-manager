const { expect, test: base } = require('@playwright/test');
const { join } = require('node:path');
const { readWpJson, runWpCli } = require('./wp-cli');

const smokeScript = join(__dirname, 'smoke-fixtures.php');
const phaseTwoScript = join(__dirname, 'phase-two-fixtures.php');
const test = base.extend({
	fixture: [async ({}, use) => {
		const fixture = readWpJson(['eval-file', smokeScript, 'seed']);
		try {
			await use(fixture);
		} finally {
			runWpCli(['eval-file', smokeScript, 'cleanup'], { MDS_RM_FIXTURE_STATE: JSON.stringify(fixture.state) });
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

test('public releases REST response is paginated without changing its array shape', async ({ page, fixture }) => {
	const response = await page.request.get('/wp-json/mds/v1/releases?per_page=1&page=1');
	expect(response.status()).toBe(200);
	const releases = await response.json();
	expect(Array.isArray(releases)).toBe(true);
	expect(releases).toHaveLength(1);
	expect(releases[0]).toEqual(expect.objectContaining({ id: expect.any(Number), title: expect.any(String), upc: expect.any(String) }));
	expect(Number(response.headers()['x-wp-total'])).toBeGreaterThanOrEqual(1);
	expect(Number(response.headers()['x-wp-totalpages'])).toBeGreaterThanOrEqual(1);

	const invalid = await page.request.get('/wp-json/mds/v1/releases?per_page=101');
	expect(invalid.status()).toBe(400);
});

test('Release editor searches and selects Artists without loading the complete list', async ({ page, fixture }) => {
	await allowLocalRequests(page);
	await login(page, fixture);
	await page.goto(`/wp-admin/post.php?post=${fixture.state.release}&action=edit`);
	const nonce = await page.evaluate(() => window.mdsReleaseEditorData.nonce);

	const apiResponse = await page.request.get(`/wp-json/mds/v1/artists?search=${encodeURIComponent(fixture.artistTitle)}`, {
		headers: { 'X-WP-Nonce': nonce },
	});
	expect(apiResponse.status()).toBe(200);
	expect(await apiResponse.json()).toMatchObject({
		items: [expect.objectContaining({ uuid: fixture.artistUuid, title: fixture.artistTitle })],
	});

	await expect(page.locator('select#artist_id')).toHaveCount(0);
	const artistCombo = page.getByRole('combobox', { name: /Artist/i }).first();
	await expect(artistCombo).toBeVisible();
	await artistCombo.fill(fixture.artistTitle);
	await page.getByRole('option', { name: fixture.artistTitle, exact: true }).click();
	await expect(page.locator('input[name="artist_id"]')).toHaveValue(fixture.artistUuid);
});

test('UPC validation detects both legacy and normalized duplicate keys', async () => {
	const result = readWpJson(['eval-file', phaseTwoScript, 'check-upc']);
	expect(result).toEqual({ legacy_error: 'mds_duplicate_upc', indexed_error: 'mds_duplicate_upc' });
});

test('manual network sync resumes bounded batches using the returned job ID', async ({ page, fixture }) => {
	test.setTimeout(120_000);
	const state = readWpJson(['eval-file', phaseTwoScript, 'sync-seed']);
	let jobId = '';

	try {
		runWpCli(['super-admin', 'add', fixture.username]);
		await allowLocalRequests(page);
		await login(page, fixture);
		const batchJobIds = [];
		page.on('request', (request) => {
			if (request.url().includes('/mds/v1/sync-network')) {
				batchJobIds.push(new URLSearchParams(request.postData() || '').get('job_id'));
			}
		});

		await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings');
		await page.getByRole('link', { name: 'General Settings', exact: true }).click();
		await page.getByRole('button', { name: 'Run Manual Sync', exact: true }).click();
		await expect(page.locator('#mds-manual-sync-status')).toContainText('Sync completed', { timeout: 90_000 });
		expect(batchJobIds.length).toBeGreaterThan(1);
		expect(batchJobIds[0]).toBe('');
		expect(batchJobIds[1]).toBeTruthy();

		const currentJob = readWpJson([
			'eval',
			"echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_option('mds_rm_network_sync_job'));",
		]);
		jobId = currentJob.job_id;
		expect(currentJob.status).toBe('complete');
		expect(currentJob.artists_synced).toBeGreaterThanOrEqual(12);
		expect(currentJob.releases_synced).toBeGreaterThanOrEqual(12);
	} finally {
		const currentJob = readWpJson([
			'eval',
			"echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_option('mds_rm_network_sync_job'));",
		]);
		jobId = jobId || (currentJob && currentJob.job_id) || '';
		runWpCli(['eval-file', phaseTwoScript, 'sync-cleanup'], {
			MDS_RM_SYNC_TEST_STATE: JSON.stringify(state),
			MDS_RM_SYNC_TEST_JOB_ID: jobId,
		});
	}
});

test('CSV export preserves the row format across page boundaries', async ({ page, fixture }) => {
	test.setTimeout(90_000);
	const state = readWpJson(['eval-file', phaseTwoScript, 'export-seed']);
	try {
		await allowLocalRequests(page);
		await login(page, fixture);
		await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-csv');
		const downloadPromise = page.waitForEvent('download');
		await page.getByRole('button', { name: 'Export to CSV', exact: true }).click();
		const download = await downloadPromise;
		const stream = await download.createReadStream();
		let csv = '';
		for await (const chunk of stream) {
			csv += chunk.toString();
		}

		expect(csv.startsWith('UPC/EAN,"Release Title",Artist,"Release Date"')).toBe(true);
		expect(csv).toContain(`Phase Two Export ${state.marker} 100`);
		expect(csv.split(/\r?\n/).length).toBeGreaterThan(101);
	} finally {
		runWpCli(['eval-file', phaseTwoScript, 'export-cleanup'], { MDS_RM_EXPORT_TEST_STATE: JSON.stringify(state) });
	}
});
