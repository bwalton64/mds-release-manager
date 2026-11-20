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

test('baseline: frontend fallbacks, Gutenberg metadata, and settings save', async ({ page, fixture }) => {
	test.setTimeout(60_000);
		const origin = new URL(process.env.MDS_RM_TEST_URL).origin;
		await page.route('**/*', (route) => new URL(route.request().url()).origin === origin
			? route.continue()
			: route.abort());

		await test.step('Release fallback renders metadata and Spotify link', async () => {
			expect((await page.goto(fixture.releaseUrl)).status()).toBe(200);
			const fallback = page.locator('.mds-release-fallback');
			await expect(fallback.getByText(fixture.label, { exact: true })).toBeVisible();
			await expect(fallback.getByRole('link', { name: /spotify/i })).toHaveAttribute('href', fixture.spotify);
		});

		await test.step('Artist fallback renders the linked release in Discography', async () => {
			expect((await page.goto(fixture.artistUrl)).status()).toBe(200);
			const discography = page.getByRole('region', { name: 'Discography' });
			await expect(discography.getByRole('row').getByRole('link', { name: fixture.title })).toHaveAttribute('href', fixture.releaseUrl);
		});

		await page.goto('/wp-login.php');
		await page.getByLabel('Username or Email Address').fill(fixture.username);
		await page.getByLabel('Password', { exact: true }).fill(fixture.password);
		await page.getByRole('button', { name: 'Log In' }).click();
		await expect(page).toHaveURL(/\/wp-admin\//);

		await test.step('Gutenberg saves the release metabox to the database', async () => {
			await page.goto(`/wp-admin/post.php?post=${fixture.state.release}&action=edit`);
			const welcome = page.getByRole('dialog', { name: 'Welcome to the editor' });
			const labelField = page.getByLabel('Record Label', { exact: true });
			const metaBoxes = page.getByRole('button', { name: 'Meta Boxes', exact: true });
			await expect(welcome.or(labelField).or(metaBoxes).filter({ visible: true }).first()).toBeVisible();
			if (await welcome.isVisible()) {
				await welcome.getByRole('button', { name: 'Close', exact: true }).click();
			}
			if (!(await labelField.isVisible())) {
				await metaBoxes.press('Enter', { timeout: 10_000 });
			}
			const label = `${fixture.label} edited`;
			await labelField.fill(label, { timeout: 10_000 });
			await page.evaluate((title) => window.wp.data.dispatch('core/editor').editPost({ title }), `${fixture.title} edited`);
			await page.getByRole('button', { name: 'Save', exact: true }).click({ timeout: 10_000 });
			await expect.poll(() => readWpJson([
				'eval',
				`echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_meta(${fixture.state.release}, 'record_label', true));`,
			])).toBe(label);
		});

		await test.step('Settings form saves through REST and persists the provider', async () => {
			await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings');
			await page.getByRole('button', { name: 'Add Provider' }).click();
			await page.getByPlaceholder('Provider Name').last().fill(fixture.label);
			const [response] = await Promise.all([
				page.waitForResponse((response) =>
					response.request().method() === 'POST' && response.url().includes('/mds/v1/settings'), { timeout: 15_000 }),
				page.locator('#tab-shortcode').getByRole('button', { name: 'Save Settings' }).click(),
			]);
			expect(response.status()).toBe(200);
			expect(await response.json()).toMatchObject({ success: true });
			const providers = readWpJson(['eval', "echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_option('mds_rm_providers'));"]);
			expect(providers).toEqual(expect.arrayContaining([expect.objectContaining({ name: fixture.label })]));
		});

		await test.step('Previously saved Release Meta block opens and renders', async () => {
			const name = 'mds-rm/release-meta';
			const attributes = {
				items: [{ field: 'record_label', enabled: true, icon: '', title: 'Saved label' }],
				styles: { desktop: { color: '#123456' } },
			};
			const markup = `<!-- wp:${name} ${JSON.stringify(attributes)} /-->`;
			runWpCli(['eval', `if (is_wp_error(wp_update_post(array('ID' => ${fixture.state.release}, 'post_content' => ${JSON.stringify(markup)}), true))) WP_CLI::error('Could not save block fixture.');`]);

			await page.goto(fixture.releaseUrl);
			await expect(page.locator('.wp-block-mds-rm-release-meta .mds-release-meta'))
				.toContainText(`${fixture.label} edited`);

			await page.goto(`/wp-admin/post.php?post=${fixture.state.release}&action=edit`);
			await expect.poll(() => page.evaluate((blockName) => {
				const block = window.wp?.data?.select('core/block-editor')?.getBlocks()
					.find(({ name }) => name === blockName);
				return block && { isValid: block.isValid, items: block.attributes.items, styles: block.attributes.styles };
			}, name)).toEqual({ isValid: true, ...attributes });
			await expect.poll(() => page.evaluate(() => {
				const canvas = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				return canvas.querySelector('.editor-styles-wrapper .mds-release-meta, .block-editor-block-list__layout .mds-release-meta')?.textContent;
			})).toContain(`${fixture.label} edited`);
		});
});
