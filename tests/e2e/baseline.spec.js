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

function saveMarkup(postId, markup) {
	runWpCli(['eval', `if (is_wp_error(wp_update_post(array('ID' => ${postId}, 'post_content' => ${JSON.stringify(markup)}), true))) WP_CLI::error('Could not save block fixture.');`]);
}

async function expectUnchangedMarkup(page, markup) {
	await expect.poll(() => page.evaluate(() => {
		const editor = window.wp?.data?.select('core/block-editor');
		return editor && window.wp.blocks.serialize(editor.getBlocks());
	})).toBe(markup);
}

test('baseline: frontend fallbacks, Gutenberg metadata, and settings save', async ({ page, fixture }) => {
		test.setTimeout(90_000);
		const origin = new URL(process.env.MDS_RM_TEST_URL).origin;
		const savedMarkup = [];
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
			await expect(page.locator('#mds_add_streaming_row')).toHaveCSS('margin-bottom', '20px');
			await expect(page.locator('.mds-buy-now-field')).toHaveCSS('display', 'flex');
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
			await expect(page.locator('#mds-settings-form')).toHaveCSS('margin-top', '20px');
			await page.getByRole('button', { name: 'Add Provider' }).click();
			await expect(page.locator('.mds-provider-row-new').last()).toHaveCSS('display', 'flex');
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

		await test.step('CSV, network settings, and Artist admin views remain usable', async () => {
			await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-csv');
			await expect(page.getByRole('button', { name: 'Export to CSV' })).toBeVisible();
			await expect(page.getByLabel('CSV File')).toBeVisible();
			await expect(page.locator('.mds-csv-import')).toHaveCSS('margin-top', '15px');

			// Only the disposable fixture user needs network access, and fixture cleanup deletes it.
			runWpCli(['super-admin', 'add', fixture.username]);
			await page.goto('/wp-admin/network/settings.php?page=mds-release-network-settings');
			await expect(page.getByRole('heading', { name: 'Network Architecture' })).toBeVisible();
			await expect(page.getByRole('button', { name: 'Save Network Settings' })).toBeVisible();
			await expect(page.locator('#mds-network-settings-form .mds-admin-save-actions')).toHaveCSS('display', 'flex');
			runWpCli(['super-admin', 'remove', fixture.username]);

			await page.goto(`/wp-admin/post.php?post=${fixture.state.artist}&action=edit`);
			const homepage = page.getByLabel('Official Homepage URL');
			const metaBoxes = page.getByRole('button', { name: 'Meta Boxes', exact: true });
			await expect(homepage.or(metaBoxes).filter({ visible: true }).first()).toBeVisible();
			if (!(await homepage.isVisible())) {
				await metaBoxes.press('Enter');
			}
			await expect(homepage).toBeVisible();
			await expect(page.getByLabel('Redirect visitors to this homepage')).toBeVisible();
		});

		await test.step('Previously saved Release Meta block opens and renders', async () => {
			const name = 'mds-rm/release-meta';
			const attributes = {
				items: [{ field: 'record_label', enabled: true, icon: '', title: 'Saved label' }],
				styles: { desktop: { color: '#123456' } },
			};
			const markup = `<!-- wp:${name} ${JSON.stringify(attributes)} /-->`;
			savedMarkup.push(markup);
			saveMarkup(fixture.state.release, markup);

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
			await expectUnchangedMarkup(page, markup);
		});

		await test.step('Previously saved Release Links block opens and renders', async () => {
			const name = 'mds-rm/release-links';
			const attributes = { items: [{ name: 'Spotify', enabled: true }], newTab: false, showAudio: false };
			const markup = `<!-- wp:${name} ${JSON.stringify(attributes)} /-->`;
			savedMarkup.push(markup);
			saveMarkup(fixture.state.release, markup);
			await page.goto(fixture.releaseUrl);
			await expect(page.locator('.wp-block-mds-rm-release-links').getByRole('link', { name: /spotify/i }))
				.toHaveAttribute('href', fixture.spotify);
			await page.goto(`/wp-admin/post.php?post=${fixture.state.release}&action=edit`);
			await expect.poll(() => page.evaluate((blockName) => {
				const block = window.wp?.data?.select('core/block-editor')?.getBlocks()
					.find(({ name }) => name === blockName);
				return block && { isValid: block.isValid, items: block.attributes.items, newTab: block.attributes.newTab, showAudio: block.attributes.showAudio };
			}, name)).toEqual({ isValid: true, ...attributes });
			await expect.poll(() => page.evaluate(() => {
				const canvas = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				return canvas.querySelector('[data-type="mds-rm/release-links"] a[href*="open.spotify.com"]')?.href;
			})).toBe(fixture.spotify);
			await expectUnchangedMarkup(page, markup);
		});

		await test.step('Previously saved Artist Discography and dynamic Paragraph open and render', async () => {
			const name = 'mds-rm/artist-discography';
			const artistId = fixture.state.artist;
			const markup = [
				`<!-- wp:${name} {"artistId":${artistId}} /-->`,
				'<!-- wp:paragraph {"mdsMetaField":"record_label"} -->\n<p>Static fallback text.</p>\n<!-- /wp:paragraph -->',
			].join('\n\n');
			savedMarkup.push(markup);
			saveMarkup(fixture.state.release, markup);
			const title = `${fixture.title} edited`;
			await page.goto(fixture.releaseUrl);
			const discography = page.locator('.wp-block-mds-rm-artist-discography');
			await expect(discography.getByRole('link', { name: title })).toHaveAttribute('href', fixture.releaseUrl);
			await expect(page.getByText(`${fixture.label} edited`, { exact: true }).filter({ visible: true }).and(page.locator('p')))
				.toBeVisible();
			await expect(page.getByText('Static fallback text.', { exact: true })).toHaveCount(0);

			await page.goto(`/wp-admin/post.php?post=${fixture.state.release}&action=edit`);
			await expect.poll(() => page.evaluate((blockName) => {
				const blocks = window.wp?.data?.select('core/block-editor')?.getBlocks();
				const discography = blocks?.find(({ name }) => name === blockName);
				const paragraph = blocks?.find(({ name }) => name === 'core/paragraph');
				return {
					artistId: discography?.attributes.artistId,
					discographyValid: discography?.isValid,
					metaField: paragraph?.attributes.mdsMetaField,
					paragraphValid: paragraph?.isValid,
				};
			}, name)).toEqual({ artistId, discographyValid: true, metaField: 'record_label', paragraphValid: true });
			await expect.poll(() => page.evaluate(() => {
				const canvas = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				return canvas.querySelector('[data-type="mds-rm/artist-discography"] .mds-discography a')?.href;
			})).toBe(fixture.releaseUrl);
			await expect.poll(() => page.evaluate(() => {
				const canvas = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				return !!canvas.querySelector('[data-type="core/paragraph"].mds-dynamic-block-active');
			})).toBe(true);
			await expectUnchangedMarkup(page, markup);
		});

		await test.step('Re-saving the blocks leaves stored markup byte-identical', async () => {
			const markup = savedMarkup.join('\n\n');
			const postId = fixture.state.release;
			saveMarkup(postId, markup);
			await page.goto(`/wp-admin/post.php?post=${postId}&action=edit`);
			await expectUnchangedMarkup(page, markup);
			const title = `${fixture.title} re-saved`;
			await page.evaluate((newTitle) => window.wp.data.dispatch('core/editor').editPost({ title: newTitle }), title);
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await expect.poll(() => readWpJson([
				'eval',
				`echo 'MDS_RM_TEST_JSON:' . wp_json_encode(array(get_post_field('post_title', ${postId}, 'raw'), get_post_field('post_content', ${postId}, 'raw')));`,
			])).toEqual([title, markup]);
		});
});
