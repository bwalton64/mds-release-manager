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

async function openBlockEditor(page, postId) {
	await page.goto(`/wp-admin/post.php?post=${postId}&action=edit`);
	const welcome = page.getByRole('dialog', { name: 'Welcome to the editor' });
	const canvas = page.locator('iframe[name="editor-canvas"], .block-editor-block-list__layout').first();
	await expect(welcome.or(canvas).filter({ visible: true }).first()).toBeVisible();
	if (await welcome.isVisible()) {
		await welcome.getByRole('button', { name: 'Close', exact: true }).click();
	}
}

test('baseline: frontend fallbacks, Gutenberg metadata, and settings save', async ({ page, fixture }) => {
		test.setTimeout(160_000);
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
			const font = await fallback.getByRole('link', { name: /spotify/i }).locator('.fa-spotify').evaluate(async (icon) => {
				const stylesheet = document.querySelector('#mds-font-awesome-css');
				if (!stylesheet) {
					return null;
				}
				const style = getComputedStyle(icon, '::before');
				const [face] = await document.fonts.load(`${style.fontWeight} ${style.fontSize} ${style.fontFamily}`, '\uf1bc');
				const { origin, pathname } = new URL(stylesheet.href);
				return { origin, pathname, glyph: style.content, family: face?.family, status: face?.status };
			});
			expect(font).toEqual({
				origin,
				pathname: expect.stringMatching(/\/assets\/fontawesome\/css\/all\.css$/),
				glyph: '"\uf1bc"',
				family: 'Font Awesome 6 Brands',
				status: 'loaded',
			});
		});

		await test.step('Legacy Font Awesome source still loads; disabled source does not', async () => {
			runWpCli(['option', 'update', 'mds_rm_fa_support', 'internal']);
			await page.goto(fixture.releaseUrl);
			await expect(page.locator('#mds-font-awesome-css')).toHaveAttribute('href', /\/assets\/fontawesome\/css\/all\.css/);
			runWpCli(['option', 'update', 'mds_rm_fa_support', 'none']);
			await page.goto(fixture.releaseUrl);
			await expect(page.locator('#mds-font-awesome-css')).toHaveCount(0);
			runWpCli(['option', 'update', 'mds_rm_fa_support', 'cdn']);
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

		await test.step('Classic editor saves the release metabox and excerpt to the database', async () => {
			await page.goto(`/wp-admin/post.php?post=${fixture.state.release}&action=edit`);
			// The catalog types use the classic editor; the block editor must not load.
			await expect(page.locator('body.block-editor-page')).toHaveCount(0);
			await expect(page.locator('form#post')).toBeVisible();
			// Custom Fields support is removed.
			await expect(page.locator('#postcustom')).toHaveCount(0);
			const labelField = page.getByLabel('Record Label', { exact: true });
			await expect(labelField).toBeVisible();
			await expect(page.locator('#mds_add_streaming_row')).toHaveCSS('margin-bottom', '20px');
			await expect(page.locator('.mds-buy-now-field')).toHaveCSS('display', 'flex');
			const label = `${fixture.label} edited`;
			const excerpt = `Smoke excerpt ${fixture.label}`;
			await labelField.fill(label, { timeout: 10_000 });
			await page.locator('#title').fill(`${fixture.title} edited`);
			await page.locator('#excerpt').fill(excerpt);
			await page.getByRole('button', { name: 'Update', exact: true }).click({ timeout: 10_000 });
			await expect.poll(() => readWpJson([
				'eval',
				`echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_meta(${fixture.state.release}, 'record_label', true));`,
			])).toBe(label);
			expect(readWpJson([
				'eval',
				`echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_field('post_excerpt', ${fixture.state.release}, 'raw'));`,
			])).toBe(excerpt);
		});

		await test.step('Settings form saves through REST and persists the provider', async () => {
			await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings');
			await expect(page.getByRole('link', { name: 'Release Meta', exact: true })).toHaveClass(/nav-tab-active/);
			await page.getByRole('link', { name: 'Release Links', exact: true }).click();
			await expect(page.locator('#mds-settings-form')).toHaveCSS('margin-top', '20px');
			await page.getByRole('button', { name: 'Add Provider' }).click();
			await expect(page.locator('.mds-provider-row-new').last()).toHaveCSS('display', 'flex');
			await page.getByPlaceholder('Provider Name').last().fill(fixture.label);
			const template = '[bandcamp album={release_number} size=small]';
			if (await page.locator('#mds_cb_bandcamp').count()) {
				await page.locator('#mds_cb_bandcamp').check();
			}
			await page.getByLabel('Bandcamp Shortcode Template').fill(template);
			const [response] = await Promise.all([
				page.waitForResponse((response) =>
					response.request().method() === 'POST' && response.url().includes('/mds/v1/settings'), { timeout: 15_000 }),
				page.locator('#tab-release-links').getByRole('button', { name: 'Save Settings' }).click(),
			]);
			expect(response.status()).toBe(200);
			expect(await response.json()).toMatchObject({ success: true });
			const providers = readWpJson(['eval', "echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_option('mds_rm_providers'));"]);
			expect(providers).toEqual(expect.arrayContaining([expect.objectContaining({ name: fixture.label })]));
			expect(readWpJson(['eval', "echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_option('mds_rm_bandcamp_template'));"])).toBe(template);
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

			// The catalog types use the classic editor, so block editing happens on regular posts.
			const hostAttributes = { releaseId: fixture.state.release, ...attributes };
			const hostMarkup = `<!-- wp:${name} ${JSON.stringify(hostAttributes)} /-->`;
			saveMarkup(fixture.state.host, hostMarkup);
			await openBlockEditor(page, fixture.state.host);
			await expect.poll(() => page.evaluate((blockName) => {
				const block = window.wp?.data?.select('core/block-editor')?.getBlocks()
					.find(({ name }) => name === blockName);
				return block && { isValid: block.isValid, items: block.attributes.items, styles: block.attributes.styles };
			}, name)).toEqual({ isValid: true, ...attributes });
			await expect.poll(() => page.evaluate(() => {
				const canvas = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				return canvas.querySelector('.editor-styles-wrapper .mds-release-meta, .block-editor-block-list__layout .mds-release-meta')?.textContent;
			})).toContain(`${fixture.label} edited`);
			await expectUnchangedMarkup(page, hostMarkup);
		});

		await test.step('Release Meta uses Artist context and saves native typography without dropping mobile styles', async () => {
			const { artist, release, host } = fixture.state;
			const upc = readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_meta(${release}, 'upc_ean', true));`]);
			const styles = { desktop: { font_size: '19px' }, mobile: { font_size: '16px' } };
			// An invalid releaseId on an Artist page falls back to the artist's latest release.
			saveMarkup(artist, `<!-- wp:mds-rm/release-meta ${JSON.stringify({ releaseId: artist, styles })} /-->`);
			await page.goto(fixture.artistUrl);
			await expect(page.locator('.wp-block-mds-rm-release-meta .mds-release-meta')).toContainText(`${fixture.label} edited`);

			saveMarkup(host, `<!-- wp:mds-rm/release-meta ${JSON.stringify({ releaseId: artist, styles })} /-->`);
			await openBlockEditor(page, host);
			const selectFirst = async () => {
				await expect.poll(() => page.evaluate(() => window.wp?.data?.select('core/block-editor')?.getBlocks()[0]?.clientId)).toBeTruthy();
				await page.evaluate(() => wp.data.dispatch('core/block-editor').selectBlock(wp.data.select('core/block-editor').getBlocks()[0].clientId));
			};
			await selectFirst();
			await expect(page.getByRole('tabpanel', { name: 'Settings' }).getByText('The selected Release is unavailable.', { exact: false })).toBeVisible();
			const picker = page.getByRole('combobox', { name: 'Release', exact: true });
			await picker.fill(upc);
			await expect(page.getByRole('option').filter({ hasText: upc })).toHaveCount(1);
			await picker.press('ArrowDown');
			await picker.press('Enter');
			await expect.poll(() => page.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0].attributes.releaseId)).toBe(release);
			await page.getByRole('tab', { name: 'Styles', exact: true }).click();
			await expect(page.getByLabel('Device', { exact: true })).toHaveCount(0);
			await page.getByRole('button', { name: 'Release Meta typography', exact: true }).click();
			await page.getByLabel('Text alignment', { exact: true }).selectOption('center');
			await expect.poll(() => page.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0].attributes.metaStyles)).toEqual({ text_align: 'center' });
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			const saved = () => readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(parse_blocks(get_post_field('post_content', ${host}, 'raw'))[0]['attrs']);`]);
			await expect.poll(saved, { timeout: 15_000 })
				.toMatchObject({ releaseId: release, styles, metaStyles: { text_align: 'center' } });
			await page.goto(fixture.hostUrl);
			const meta = page.locator('.wp-block-mds-rm-release-meta .mds-release-meta');
			await expect(meta).toContainText(`${fixture.label} edited`);
			await expect(meta.locator('.mds-release-meta__item > span:not(.screen-reader-text)').first()).toHaveCSS('text-align', 'center');
			await expect(meta).toHaveCSS('font-size', '19px');
			const viewport = page.viewportSize();
			await page.setViewportSize({ width: 767, height: 800 });
			await expect(meta).toHaveCSS('font-size', '16px');
			await page.setViewportSize(viewport);
			await openBlockEditor(page, host);
			await selectFirst();
			await page.getByRole('tab', { name: 'Styles', exact: true }).click();
			await page.getByRole('button', { name: 'Legacy responsive styles' }).click();
			await page.getByRole('button', { name: 'Remove legacy tablet/mobile overrides' }).click();
			await expect.poll(() => page.evaluate(() => wp.data.select('core/editor').isEditedPostDirty())).toBe(true);
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await expect.poll(() => saved().styles, { timeout: 15_000 }).toEqual({ desktop: styles.desktop });
			expect(saved().metaStyles).toEqual({ text_align: 'center' });
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
			const hostAttributes = { releaseId: fixture.state.release, ...attributes };
			const hostMarkup = `<!-- wp:${name} ${JSON.stringify(hostAttributes)} /-->`;
			saveMarkup(fixture.state.host, hostMarkup);
			await openBlockEditor(page, fixture.state.host);
			await expect.poll(() => page.evaluate((blockName) => {
				const block = window.wp?.data?.select('core/block-editor')?.getBlocks()
					.find(({ name }) => name === blockName);
				return block && { isValid: block.isValid, items: block.attributes.items, newTab: block.attributes.newTab, showAudio: block.attributes.showAudio };
			}, name)).toEqual({ isValid: true, ...attributes });
			await expect.poll(() => page.evaluate(() => {
				const canvas = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				const block = canvas.querySelector('[data-type="mds-rm/release-links"]');
				return block?.querySelector('a[href*="open.spotify.com"]')?.href || block?.textContent;
			}), { timeout: 15_000 }).toBe(fixture.spotify);
			await expectUnchangedMarkup(page, hostMarkup);
		});

		await test.step('Release Links searches by UPC, falls back on Artist pages, and preserves responsive styles', async () => {
			const name = 'mds-rm/release-links';
			const { artist, release, host } = fixture.state;
			const [title, artistTitle, upc] = readWpJson([
				'eval',
				`echo 'MDS_RM_TEST_JSON:' . wp_json_encode(array(get_the_title(${release}), get_the_title(${artist}), get_post_meta(${release}, 'upc_ean', true)));`,
			]);
			const styles = { desktop: { align: 'space-between' }, mobile: { align: 'center' } };
			const markup = `<!-- wp:${name} ${JSON.stringify({ releaseId: artist, styles })} /-->`;
			// An invalid releaseId on an Artist page falls back to the artist's latest release.
			saveMarkup(artist, markup);
			await page.goto(fixture.artistUrl);
			const wrapper = page.locator('.wp-block-mds-rm-release-links');
			await expect(wrapper.getByRole('link', { name: /spotify/i })).toHaveAttribute('href', fixture.spotify);
			await expect(wrapper.locator('.mds-release-links')).toHaveCSS('justify-content', 'space-between');

			saveMarkup(host, markup);
			await openBlockEditor(page, host);
			await expect.poll(() => page.evaluate(() => window.wp?.data?.select('core/block-editor')?.getBlocks()[0]?.clientId)).toBeTruthy();
			await page.evaluate(() => {
				const editor = wp.data.select('core/block-editor');
				wp.data.dispatch('core/block-editor').selectBlock(editor.getBlocks()[0].clientId);
			});
			await expect(page.getByRole('tabpanel', { name: 'Settings' }).getByText('The selected Release is unavailable.', { exact: false })).toBeVisible();
			const picker = page.getByRole('combobox', { name: 'Release', exact: true });
			await picker.fill(upc);
			await expect(page.getByRole('option', { name: `${title} — ${artistTitle} — ${upc}` })).toBeVisible();
			await picker.press('ArrowDown');
			await picker.press('Enter');
			const blockAttributes = () => page.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0].attributes);
			await expect.poll(async () => (await blockAttributes()).releaseId).toBe(release);
			await page.getByRole('tab', { name: 'Styles', exact: true }).click();
			await expect(page.getByLabel('Device', { exact: true })).toHaveCount(0);
			await page.getByRole('button', { name: 'Release Links layout', exact: true }).click();
			await page.getByLabel('Main-axis alignment', { exact: true }).selectOption('flex-end');
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			const stored = () => readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(parse_blocks(get_post_field('post_content', ${host}, 'raw'))[0]['attrs']);`]);
			await expect.poll(stored).toMatchObject({ releaseId: release, styles, linkStyles: { align: 'flex-end' } });

			await page.goto(fixture.hostUrl);
			const links = page.locator('.wp-block-mds-rm-release-links .mds-release-links');
			await expect(links).toHaveCSS('justify-content', /^(?:flex|flow)-end$/);
			const spotifyLink = links.getByRole('link', { name: /spotify/i });
			await spotifyLink.focus();
			await expect(spotifyLink).toHaveCSS('outline-width', '2px');
			const viewport = page.viewportSize();
			await page.setViewportSize({ width: 767, height: 800 });
			await expect(links).toHaveCSS('justify-content', 'center');
			await page.setViewportSize({ width: 768, height: 800 });
			await expect(links).toHaveCSS('justify-content', /^(?:flex|flow)-end$/);
			await page.setViewportSize(viewport);

			await openBlockEditor(page, host);
			await expect.poll(() => page.evaluate(() => window.wp?.data?.select('core/block-editor')?.getBlocks()[0]?.clientId)).toBeTruthy();
			await page.evaluate(() => wp.data.dispatch('core/block-editor').selectBlock(wp.data.select('core/block-editor').getBlocks()[0].clientId));
			await page.getByRole('tab', { name: 'Styles', exact: true }).click();
			await page.getByRole('button', { name: 'Legacy responsive styles' }).click();
			await page.getByRole('button', { name: 'Remove legacy tablet/mobile overrides' }).click();
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await expect.poll(() => stored().styles).toEqual({ desktop: styles.desktop });
			expect(stored().linkStyles).toEqual({ align: 'flex-end' });
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

			saveMarkup(fixture.state.host, markup);
			await openBlockEditor(page, fixture.state.host);
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
			}), { timeout: 15_000 }).toBe(fixture.releaseUrl);
			await expect.poll(() => page.evaluate(() => {
				const canvas = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				return !!canvas.querySelector('[data-type="core/paragraph"].mds-dynamic-block-active');
			})).toBe(true);
			await expectUnchangedMarkup(page, markup);
		});

		await test.step('Discography selects artists and applies table styles only to the styled block', async () => {
			const name = 'mds-rm/artist-discography';
			const artistId = fixture.state.artist;
			const postId = fixture.state.release;
			const hostId = fixture.state.host;
			const artistTitle = readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_the_title(${artistId}));`]);
			const invalidId = postId; // Exists as a Release, not an Artist.
			saveMarkup(hostId, `<!-- wp:${name} {"artistId":${invalidId}} /-->`);
			await openBlockEditor(page, hostId);
			await expect.poll(() => page.evaluate(() => window.wp?.data?.select('core/block-editor')?.getBlocks()[0]?.clientId)).toBeTruthy();
			await page.evaluate(() => {
				const block = wp.data.select('core/block-editor').getBlocks()[0];
				wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
			});
			await expect(page.getByText('The selected Artist is unavailable.', { exact: false })).toBeVisible();
			const picker = page.getByRole('combobox', { name: 'Artist', exact: true });
			await picker.fill(artistTitle);
			await expect(page.getByRole('option', { name: `${artistTitle} (ID ${artistId})` })).toBeVisible();
			await picker.press('ArrowDown');
			await picker.press('Enter');
			await expect.poll(() => page.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0].attributes.artistId)).toBe(artistId);
			await page.getByRole('button', { name: 'Use current Artist' }).click();
			await expect.poll(() => page.evaluate(() => {
				const canvas = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				return canvas.querySelector('[data-type="mds-rm/artist-discography"]')?.textContent;
			})).toContain('Select an Artist to preview the discography.');

			const tableStyles = { 'header-background': '#123456', 'cell-padding-top': '20px', 'table-margin-bottom': '12px' };
			const style = { typography: { fontSize: '22px' } };
			const styled = `<!-- wp:${name} ${JSON.stringify({ artistId, style, tableStyles })} /-->`;
			const unstyled = `<!-- wp:${name} {"artistId":${artistId}} /-->`;
			const shortcode = `<!-- wp:shortcode -->\n[artist-discography artist_id="${artistId}"]\n<!-- /wp:shortcode -->`;
			saveMarkup(postId, `${styled}\n\n${unstyled}\n\n${shortcode}`);
			saveMarkup(hostId, `${styled}\n\n${unstyled}\n\n${shortcode}`);
			await openBlockEditor(page, hostId);
			const computed = () => {
				// This function runs in Playwright's page context.
				const doc = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				return Array.from(doc.querySelectorAll('.mds-discography thead')).map((header) => [
					doc.defaultView.getComputedStyle(header).backgroundColor,
					doc.defaultView.getComputedStyle(header.querySelector('th')).paddingTop,
				]);
			};
			const nativeTypography = () => {
				const doc = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
				const table = doc.querySelector('.mds-discography table');
				const wrapper = table.closest('[data-type="mds-rm/artist-discography"]')
					|| table.closest('.wp-block-mds-rm-artist-discography');
				return {
					wrapperStyle: wrapper.style.fontSize,
					inherits: doc.defaultView.getComputedStyle(table).fontSize === doc.defaultView.getComputedStyle(wrapper).fontSize,
				};
			};
			await expect.poll(() => page.evaluate(computed), { timeout: 10000 }).toHaveLength(2);
			const preview = await page.evaluate(computed);
			expect(preview[0]).toEqual(['rgb(18, 52, 86)', '20px']);
			expect(preview[1]).not.toEqual(preview[0]);
			// Core may make a custom font size fluid; the table must follow the wrapper.
			expect(await page.evaluate(nativeTypography)).toEqual({ wrapperStyle: expect.stringContaining('22px'), inherits: true });
			await page.goto(fixture.releaseUrl);
			const published = await page.evaluate(computed);
			expect(published).toHaveLength(3);
			expect(published[0]).toEqual(preview[0]);
			expect(published[1]).not.toEqual(published[0]);
			expect(published[2]).toEqual(published[1]);
			expect(await page.evaluate(nativeTypography)).toEqual({ wrapperStyle: expect.stringContaining('22px'), inherits: true });
			const viewport = page.viewportSize();
			await page.setViewportSize({ width: 320, height: 800 });
			const scrollSurface = page.locator('.wp-block-mds-rm-artist-discography .mds-discography__scroll').first();
			await expect(scrollSurface).toHaveAttribute('tabindex', '0');
			await expect(scrollSurface).toHaveCSS('overflow-x', 'auto');
			expect(await scrollSurface.evaluate((element) => element.scrollWidth > element.clientWidth)).toBe(true);
			await page.setViewportSize(viewport);

			await openBlockEditor(page, hostId);
			const selectFirstBlock = async () => {
				await expect.poll(() => page.evaluate(() => window.wp?.data?.select('core/block-editor')?.getBlocks()[0]?.clientId)).toBeTruthy();
				await page.evaluate(() => {
					const block = wp.data.select('core/block-editor').getBlocks()[0];
					wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
				});
			};
			await selectFirstBlock();
			await page.getByRole('tab', { name: 'Styles', exact: true }).click();
			await page.getByRole('button', { name: 'Discography header', exact: true }).click();
			await page.getByLabel('Text alignment', { exact: true }).selectOption('center');
			const storedStyles = () => readWpJson([
				'eval',
				`echo 'MDS_RM_TEST_JSON:' . wp_json_encode(parse_blocks(get_post_field('post_content', ${hostId}, 'raw'))[0]['attrs']['tableStyles'] ?? null);`,
			]);
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await expect.poll(storedStyles).toEqual({ ...tableStyles, 'header-text-align': 'center' });
			await page.reload();
			await selectFirstBlock();
			await page.getByRole('tab', { name: 'Styles', exact: true }).click();
			await page.getByRole('button', { name: 'Reset discography table styles' }).click();
			await expectUnchangedMarkup(page, [
				`<!-- wp:${name} ${JSON.stringify({ artistId, style })} /-->`, unstyled, shortcode,
			].join('\n\n'));
			await expect.poll(() => page.evaluate(() => wp.data.select('core/editor').isEditedPostDirty())).toBe(true);
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await expect.poll(storedStyles, { timeout: 15_000 }).toBeNull();

			// The classic editor hosts Artists now, so the current-Artist context
			// fallback is verified on the artist's frontend instead.
			saveMarkup(artistId, `<!-- wp:${name} /-->`);
			await page.goto(fixture.artistUrl);
			await expect(page.locator('.wp-block-mds-rm-artist-discography').getByRole('link', { name: `${fixture.title} edited` }))
				.toHaveAttribute('href', fixture.releaseUrl);
		});

		await test.step('Re-saving the blocks leaves stored markup byte-identical', async () => {
			const markup = savedMarkup.join('\n\n');
			const postId = fixture.state.host;
			saveMarkup(postId, markup);
			await openBlockEditor(page, postId);
			await expectUnchangedMarkup(page, markup);
			const title = `${fixture.title} host re-saved`;
			await page.evaluate((newTitle) => window.wp.data.dispatch('core/editor').editPost({ title: newTitle }), title);
			await page.getByRole('button', { name: 'Save', exact: true }).click();
			await expect.poll(() => readWpJson([
				'eval',
				`echo 'MDS_RM_TEST_JSON:' . wp_json_encode(array(get_post_field('post_title', ${postId}, 'raw'), get_post_field('post_content', ${postId}, 'raw')));`,
			])).toEqual([title, markup]);
		});
});
