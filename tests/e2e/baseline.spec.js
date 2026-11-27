const { expect, test: base } = require('@playwright/test');
const { join } = require('node:path');
const { readFileSync } = require('node:fs');
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

async function openBlockEditor(page, postId) {
	await page.goto(`/wp-admin/post.php?post=${postId}&action=edit`);
	const welcome = page.getByRole('dialog', { name: 'Welcome to the editor' });
	const canvas = page.locator('iframe[name="editor-canvas"], .block-editor-block-list__layout').first();
	await expect(welcome.or(canvas).filter({ visible: true }).first()).toBeVisible();
	if (await welcome.isVisible()) {
		await welcome.getByRole('button', { name: 'Close', exact: true }).click();
	}
}

async function login(page, fixture) {
	await page.goto('/wp-login.php');
	await page.getByLabel('Username or Email Address').fill(fixture.username);
	await page.getByLabel('Password', { exact: true }).fill(fixture.password);
	await page.getByRole('button', { name: 'Log In' }).click();
	await expect(page).toHaveURL(/\/wp-admin\//);
}

async function setCustomColor(page, label, hex) {
	const button = page.getByRole('button', { name: `Choose color for ${label}`, exact: true }).first();
	await button.click();
	const picker = page.getByLabel(label, { exact: true }).filter({ has: page.getByLabel('Hex color') });
	await expect(picker.getByLabel('Alpha', { exact: true })).toBeVisible();
	await picker.getByLabel('Hex color').fill(hex);
	await expect(button).toContainText(`#${hex.toLowerCase()}`);
	await button.click();
}

test('provider controls preserve conflicts and saved release links across rendering paths', async ({ page, fixture }) => {
	test.setTimeout(90_000);
	runWpCli(['eval', `
		update_option('mds_rm_providers', array(
			array('name' => 'Spotify', 'icon' => 'fab fa-spotify', 'color' => '#1DB954'),
			array('name' => 'Apple Music', 'color' => '#FA243C')
		));
		update_option('mds_rm_block_defaults', array('release-links' => array('items' => array(
			array('name' => 'Spotify', 'enabled' => true, 'icon' => 'fas fa-star', 'title' => 'Listen here'),
			array('name' => 'Retired', 'enabled' => false, 'icon' => 'fas fa-music'),
			array('name' => '__buy_now', 'enabled' => true)
		))));
		update_post_meta(${fixture.state.release}, 'streaming_links', wp_slash(wp_json_encode(array('Spotify' => ${JSON.stringify(fixture.spotify)}, 'Retired' => 'https://example.com/retired'))));
		update_post_meta(${fixture.state.release}, 'buy_now_link', 'https://example.com/buy');
	`]);
	const storedLinks = readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_meta(${fixture.state.release}, 'streaming_links', true));`]);
	const readSettings = () => readWpJson(['eval', "echo 'MDS_RM_TEST_JSON:' . wp_json_encode(array('providers' => get_option('mds_rm_providers'), 'defaults' => get_option('mds_rm_block_defaults')));"]);
	const save = async () => {
		const [response] = await Promise.all([
			page.waitForResponse((res) => res.request().method() === 'POST' && res.url().includes('/mds/v1/settings')),
			page.locator('#tab-block-defaults').getByRole('button', { name: 'Save Settings' }).click(),
		]);
		expect(response.status()).toBe(200);
		expect(await response.json()).toMatchObject({ success: true });
	};

	await login(page, fixture);
	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-block-defaults');
	const tab = page.locator('#tab-block-defaults');
	await tab.getByRole('tab', { name: 'Release Links', exact: true }).click();
	const spotifyCard = tab.locator('.mds-repeater-card', { hasText: 'Spotify' }).first();
	await spotifyCard.getByRole('button', { name: /Customize/i }).click();
	await expect(spotifyCard.getByText(/saved block icon override is "fas fa-star"/)).toBeVisible();
	await setCustomColor(page, 'Background color for Spotify', '000000');
	await save();

	let settings = readSettings();
	expect(settings.providers[0]).toEqual({ name: 'Spotify', icon: 'fab fa-spotify', color: '#000000' });
	expect(settings.providers[1]).toEqual({ name: 'Apple Music', icon: '', color: '#FA243C' });
	expect(settings.defaults['release-links'].items).toEqual([
		{ name: 'Spotify', enabled: true, icon: 'fas fa-star', title: 'Listen here' },
		{ name: 'Retired', enabled: false, icon: 'fas fa-music' },
		{ name: '__buy_now', enabled: true },
	]);

	await page.goto(fixture.releaseUrl);
	const spotify = page.locator('.mds-release-links__link[title="Listen here"]');
	await expect(spotify.locator('i')).toHaveClass('fas fa-star');
	await expect(spotify).toHaveAttribute('style', /--mds-links-provider-background:\s*#000000/i);
	await expect(page.locator('.mds-release-links__link[title="Buy Now"]')).toBeVisible();

	const savedBlock = { releaseId: fixture.state.release, items: [{ name: 'Spotify', enabled: true, icon: 'far fa-heart', title: 'Saved block icon' }] };
	saveMarkup(fixture.state.host, `<!-- wp:mds-rm/release-links ${JSON.stringify(savedBlock)} /-->`);
	await page.goto(fixture.hostUrl);
	await expect(page.locator('.mds-release-links__link[title="Saved block icon"] i')).toHaveClass('far fa-heart');

	const rendered = readWpJson(['eval', `
		$GLOBALS['post'] = get_post(${fixture.state.release});
		eval('namespace Elementor; class Widget_Base { public function get_settings_for_display() { return $GLOBALS["mds_test_widget_settings"] ?? array(); } }');
		class MDS_RM_Test_Links_Widget extends \\MDS\\ReleaseManager\\Integrations\\Elementor\\LinksWidget {
			public function output() { ob_start(); $this->render(); return ob_get_clean(); }
		}
		$widget = new MDS_RM_Test_Links_Widget();
		$default_widget = $widget->output();
		$GLOBALS['mds_test_widget_settings'] = array('items' => array(array('name' => 'Spotify', 'enabled' => 'yes', 'icon' => 'fas fa-music')));
		echo 'MDS_RM_TEST_JSON:' . wp_json_encode(array(
			'shortcode' => do_shortcode('[release-links release_id="${fixture.state.release}"]'),
			'elementor' => $default_widget,
			'elementor_override' => $widget->output()
		));
	`]);
	for (const html of [rendered.shortcode, rendered.elementor]) {
		expect(html).toContain('fab fa-spotify');
		expect(html).toContain('--mds-links-provider-background:#000000');
		expect(html).not.toContain('fas fa-star');
	}
	expect(rendered.elementor_override).toContain('fas fa-music');

	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-block-defaults');
	await tab.getByRole('tab', { name: 'Release Links', exact: true }).click();
	await tab.locator('.mds-repeater-card', { hasText: 'Spotify' }).first().getByRole('button', { name: /Customize/i }).click();
	await tab.getByRole('button', { name: 'Use block icon for all outputs', exact: true }).click();
	await setCustomColor(page, 'Background color for Spotify', '17304A80');
	await tab.getByLabel('Provider Name', { exact: true }).fill('Retired');
	await tab.getByRole('button', { name: 'Add Provider', exact: true }).click();
	await save();

	settings = readSettings();
	expect(settings.providers).toEqual(expect.arrayContaining([
		{ name: 'Spotify', icon: 'fas fa-star', color: '#17304a80' },
		expect.objectContaining({ name: 'Retired' }),
	]));
	expect(readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_meta(${fixture.state.release}, 'streaming_links', true));`])).toBe(storedLinks);

	const beforeScriptFailure = readSettings();
	await page.route('**/js/settings-defaults.js*', (route) => route.abort());
	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-block-defaults');
	await expect(page.locator('#mds-provider-fields input[name="mds_rm_providers[name][]"]')).toHaveCount(3);
	await save();
	expect(readSettings()).toEqual(beforeScriptFailure);
});

test('network extension tab names are not treated as site tab aliases', async ({ page, fixture }) => {
	await login(page, fixture);
	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings');
	await page.evaluate(() => {
		document.body.innerHTML = '<div class="mds-settings-wrap"><div id="mds-settings-tabs"><a href="#tab-network">Network</a><a href="#tab-integrations">Extension</a></div><div id="tab-network" class="mds-tab-pane"></div><div id="tab-integrations" class="mds-tab-pane"></div></div>';
	});
	await page.addScriptTag({ content: readFileSync(join(__dirname, '..', '..', 'js', 'settings-tabs.js'), 'utf8') });
	await expect(page.getByRole('link', { name: 'Network', exact: true })).toHaveClass(/nav-tab-active/);
	await page.getByRole('link', { name: 'Extension', exact: true }).click();
	await expect(page.getByRole('link', { name: 'Extension', exact: true })).toHaveClass(/nav-tab-active/);
	await expect(page.locator('#tab-integrations')).toHaveClass(/active/);
});

test('fallback blocks reflect site-wide settings saved in the admin', async ({ page, fixture }) => {
	test.setTimeout(90_000);
	const origin = new URL(process.env.MDS_RM_TEST_URL).origin;
	await page.route('**/*', (route) => new URL(route.request().url()).origin === origin
		? route.continue()
		: route.abort());
	await login(page, fixture);
	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings');
	expect((await page.locator('#mds-settings-tabs > a').allTextContents()).slice(0, 3)).toEqual([
		'General Settings', 'Default Templates', 'Block Defaults',
	]);

	const extensionOrder = readWpJson(['eval', `
		add_filter('mds_rm_site_settings_tabs', function($tabs) {
			$tab = array('label' => 'Extension', 'render' => function() {});
			return array('aa-extension' => $tab, 'general-settings' => $tab, 'block-defaults' => $tab) + $tabs;
		});
		ob_start();
		\\MDS\\ReleaseManager\\Admin\\AdminInterface::settings_page();
		$html = ob_get_clean();
		preg_match_all('/href="#tab-([^"]+)"/', $html, $matches);
		echo 'MDS_RM_TEST_JSON:' . wp_json_encode($matches[1]);
	`]);
	expect(extensionOrder.slice(0, 4)).toEqual(['general-settings', 'default-templates', 'block-defaults', 'aa-extension']);

	await page.getByRole('link', { name: 'Block Defaults', exact: true }).click();
	const tab = page.locator('#tab-block-defaults');
	await expect(tab.locator('.mds-theme-preset-bar')).toBeVisible();
	const showPreviewBtn = tab.getByRole('button', { name: 'Show Live Preview', exact: true });
	if (await showPreviewBtn.isVisible()) {
		await showPreviewBtn.click();
	}
	await expect(tab.locator('#mds-bd-live-preview-release-meta')).toBeVisible();

	// Release Meta background
	await tab.getByRole('button', { name: 'Container & Layout', exact: true }).click();
	await setCustomColor(page, 'Container Background Color', '17304A80');

	// Release Links background + Open in same tab
	await tab.getByRole('tab', { name: 'Release Links', exact: true }).click();
	await expect(tab.locator('#mds-bd-live-preview-release-links')).toBeVisible();
	await tab.getByRole('button', { name: 'Behavior & Display', exact: true }).click();
	await tab.getByLabel('Open Links in a New Tab', { exact: true }).uncheck();
	await tab.getByRole('button', { name: 'Container & Layout', exact: true }).click();
	await setCustomColor(page, 'Container Background Color', '25403880');

	// Release Player live preview presence
	await tab.getByRole('tab', { name: 'Release Player', exact: true }).click();
	await expect(tab.locator('#mds-bd-live-preview-release-player')).toBeVisible();

	// Artist Discography outer + table background
	await tab.getByRole('tab', { name: 'Artist Discography', exact: true }).click();
	await expect(tab.locator('#mds-bd-live-preview-artist-discography')).toBeVisible();
	await setCustomColor(page, 'Background Color', 'DCE6F080');
	await tab.getByRole('button', { name: 'Table Frame & Base Text', exact: true }).click();
	await setCustomColor(page, 'Table Background Color', 'E9D8C280');

	const [response] = await Promise.all([
		page.waitForResponse((res) => res.request().method() === 'POST' && res.url().includes('/mds/v1/settings')),
		tab.getByRole('button', { name: 'Save Settings' }).click(),
	]);
	expect(response.status()).toBe(200);
	expect(await response.json()).toMatchObject({ success: true });

	await page.goto(fixture.releaseUrl);
	const release = page.locator('.mds-release-fallback');
	await expect(release.locator('.mds-release-meta')).toHaveCSS('background-color', 'rgba(23, 48, 74, 0.5)');
	await expect(release.locator('.mds-release-links')).toHaveCSS('background-color', 'rgba(37, 64, 56, 0.5)');
	const spotify = release.getByRole('link', { name: /spotify/i });
	await expect(spotify).toHaveAttribute('href', fixture.spotify);
	await expect(spotify).not.toHaveAttribute('target');

	await page.goto(fixture.artistUrl);
	const discography = page.locator('.mds-artist-fallback .wp-block-mds-rm-artist-discography');
	await expect(discography).toHaveCSS('background-color', 'rgba(220, 230, 240, 0.5)');
	await expect(discography.locator('table')).toHaveCSS('background-color', 'rgba(233, 216, 194, 0.5)');
	await expect(discography.getByRole('link', { name: fixture.title })).toHaveAttribute('href', fixture.releaseUrl);
});

test('block editor custom color pickers save transparent per-block styles', async ({ page, fixture }) => {
	test.setTimeout(120_000);
	const origin = new URL(process.env.MDS_RM_TEST_URL).origin;
	await page.route('**/*', (route) => new URL(route.request().url()).origin === origin
		? route.continue()
		: route.abort());
	await login(page, fixture);
	const cases = [
		{ name: 'mds-rm/release-meta', attrs: { releaseId: fixture.state.release }, panel: 'Container & Layout', label: 'Container Background Color', key: 'metaStyles', field: 'background', selector: '.wp-block-mds-rm-release-meta .mds-release-meta' },
		{ name: 'mds-rm/release-links', attrs: { releaseId: fixture.state.release }, panel: 'Container & Layout', label: 'Container Background Color', key: 'linkStyles', field: 'background', selector: '.wp-block-mds-rm-release-links .mds-release-links' },
		{ name: 'mds-rm/artist-discography', attrs: { artistId: fixture.state.artist }, panel: 'Table Frame & Base Text', label: 'Table Background Color', key: 'tableStyles', field: 'table-background', selector: '.wp-block-mds-rm-artist-discography table' },
	];
	for (const item of cases) {
		saveMarkup(fixture.state.host, `<!-- wp:${item.name} ${JSON.stringify(item.attrs)} /-->`);
		await openBlockEditor(page, fixture.state.host);
		await expect.poll(() => page.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0]?.clientId)).toBeTruthy();
		await page.evaluate(() => wp.data.dispatch('core/block-editor').selectBlock(wp.data.select('core/block-editor').getBlocks()[0].clientId));
		await page.getByRole('tab', { name: 'Styles', exact: true }).click();
		await page.getByRole('button', { name: item.panel, exact: true }).click();
		await setCustomColor(page, item.label, '12345680');
		await page.getByRole('button', { name: 'Save', exact: true }).click();
		await expect.poll(() => readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(parse_blocks(get_post_field('post_content', ${fixture.state.host}, 'raw'))[0]['attrs']);`]))
			.toMatchObject({ [item.key]: { [item.field]: '#12345680' } });
		await page.goto(fixture.hostUrl);
		await expect(page.locator(item.selector)).toHaveCSS('background-color', 'rgba(18, 52, 86, 0.5)');
	}
});

test('baseline: frontend fallbacks, Gutenberg metadata, and settings save', async ({ page, fixture }) => {
	test.setTimeout(120_000);
	const origin = new URL(process.env.MDS_RM_TEST_URL).origin;
	await page.route('**/*', (route) => new URL(route.request().url()).origin === origin
		? route.continue()
		: route.abort());

	await test.step('Release and Artist fallbacks render metadata, links, and discography', async () => {
		expect((await page.goto(fixture.releaseUrl)).status()).toBe(200);
		const fallback = page.locator('.mds-release-fallback');
		await expect(fallback.getByText(fixture.label, { exact: true })).toBeVisible();
		await expect(fallback.getByRole('link', { name: /spotify/i })).toHaveAttribute('href', fixture.spotify);

		expect((await page.goto(fixture.artistUrl)).status()).toBe(200);
		const artistFallback = page.locator('.mds-artist-fallback');
		await expect(artistFallback.getByRole('link', { name: fixture.title })).toHaveAttribute('href', fixture.releaseUrl);
	});

	await test.step('Release workspace saves metadata and excerpt', async () => {
		await login(page, fixture);
		await page.goto(`/wp-admin/post.php?post=${fixture.state.release}&action=edit`);
		await expect(page.locator('#postcustom')).toHaveCount(0);
		const labelField = page.getByLabel('Record Label', { exact: true });
		await expect(labelField).toBeVisible();
		const label = `${fixture.label} edited`;
		const excerpt = `Smoke excerpt ${fixture.label}`;
		await labelField.fill(label);
		await page.locator('#title').fill(`${fixture.title} edited`);
		await page.locator('#excerpt').fill(excerpt);
		await page.getByRole('button', { name: 'Update', exact: true }).click();
		await expect.poll(() => readWpJson([
			'eval',
			`echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_meta(${fixture.state.release}, 'record_label', true));`,
		])).toBe(label);
		expect(readWpJson([
			'eval',
			`echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_field('post_excerpt', ${fixture.state.release}, 'raw'));`,
		])).toBe(excerpt);
	});

	await test.step('Settings form saves through REST and persists defaults + provider', async () => {
		await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-block-defaults');
		const tab = page.locator('#tab-block-defaults');
		await tab.getByRole('tab', { name: 'Artist Discography', exact: true }).click();
		await tab.getByRole('button', { name: 'Table Rows & Zebra Striping', exact: true }).click();
		await tab.getByLabel('Enable Zebra Striping', { exact: true }).check();

		await tab.getByRole('tab', { name: 'Release Links', exact: true }).click();
		await tab.getByRole('button', { name: 'Behavior & Display', exact: true }).click();
		await tab.getByLabel('Open Links in a New Tab', { exact: true }).uncheck();
		await tab.getByLabel('Provider Name', { exact: true }).fill(fixture.label);
		await tab.getByRole('button', { name: 'Add Provider', exact: true }).click();

		const [response] = await Promise.all([
			page.waitForResponse((res) => res.request().method() === 'POST' && res.url().includes('/mds/v1/settings'), { timeout: 15_000 }),
			tab.getByRole('button', { name: 'Save Settings' }).click(),
		]);
		expect(response.status()).toBe(200);
		expect(await response.json()).toMatchObject({ success: true });
		const providers = readWpJson(['eval', "echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_option('mds_rm_providers'));"]);
		expect(providers).toEqual(expect.arrayContaining([expect.objectContaining({ name: fixture.label })]));
		expect(readWpJson(['eval', "echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_option('mds_rm_block_defaults'));"]))
			.toMatchObject({ 'release-links': { newTab: false }, 'artist-discography': { tableStyles: { striped: true } } });
	});

	await test.step('Existing blocks inherit defaults while explicit overrides win', async () => {
		const name = 'mds-rm/release-links';
		const inherited = { releaseId: fixture.state.release };
		const explicit = { ...inherited, newTab: true };
		saveMarkup(fixture.state.host, `<!-- wp:${name} ${JSON.stringify(inherited)} /--><!-- wp:${name} ${JSON.stringify(explicit)} /-->`);
		await page.goto(fixture.hostUrl);
		const links = page.locator('.wp-block-mds-rm-release-links .mds-release-links__link[aria-label="Spotify"]');
		await expect(links).toHaveCount(2);
		await expect(links.nth(0)).not.toHaveAttribute('target');
		await expect(links.nth(1)).toHaveAttribute('target', '_blank');
		runWpCli(['eval', "update_option('mds_rm_block_defaults', array());"]);
	});

	await test.step('CSV, network settings, and Artist admin views remain usable', async () => {
		await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-csv');
		await expect(page.getByRole('button', { name: 'Export to CSV' })).toBeVisible();
		await expect(page.getByLabel('CSV File (or JSON / XML)')).toBeVisible();

		runWpCli(['super-admin', 'add', fixture.username]);
		await page.goto('/wp-admin/network/settings.php?page=mds-release-network-settings');
		await expect(page.getByRole('heading', { name: 'Network Architecture' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Save Network Settings' })).toBeVisible();
		runWpCli(['super-admin', 'remove', fixture.username]);

		await page.goto(`/wp-admin/post.php?post=${fixture.state.artist}&action=edit`);
		await expect(page.getByLabel(/Homepage URL/i).first()).toBeVisible();
	});
});
