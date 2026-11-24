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

async function setCustomColor(page, label, hex) {
	const button = page.getByRole('button', { name: `Choose custom color for ${label}`, exact: true });
	await button.click();
	const picker = page.getByLabel(label, { exact: true }).filter({ has: page.getByLabel('Hex color') });
	await expect(picker.getByLabel('Alpha', { exact: true })).toBeVisible();
	await picker.getByLabel('Hex color').fill(hex);
	await expect(button).toContainText(`#${hex.toLowerCase()}`);
	await button.click();
}

test('provider controls preserve conflicts and saved release links across rendering paths', async ({ page, fixture }) => {
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
			page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/mds/v1/settings')),
			page.locator('#tab-release-links-block').getByRole('button', { name: 'Save Settings' }).click(),
		]);
		expect(response.status()).toBe(200);
		expect(await response.json()).toMatchObject({ success: true });
	};
	await page.goto('/wp-login.php');
	await page.getByLabel('Username or Email Address').fill(fixture.username);
	await page.getByLabel('Password', { exact: true }).fill(fixture.password);
	await page.getByRole('button', { name: 'Log In' }).click();
	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-release-links-block');
	const tab = page.locator('#tab-release-links-block');
	await tab.getByRole('button', { name: 'Link buttons and providers', exact: true }).click();
	await tab.getByRole('button', { name: 'Spotify', exact: true }).click();
	await expect(tab.getByText(/saved block icon override is "fas fa-star"/)).toBeVisible();
	await expect(tab.getByText(/Provider names cannot be changed/)).toBeVisible();
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
		// PSR-4 autoloading + legacy class aliases resolve the Elementor widgets once the stub base class exists.
		class_exists('MDS_RM_Elementor_Links_Widget');
		class MDS_RM_Test_Links_Widget extends MDS_RM_Elementor_Links_Widget {
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

	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-release-links-block');
	await tab.getByRole('button', { name: 'Link buttons and providers', exact: true }).click();
	await tab.getByRole('button', { name: 'Spotify', exact: true }).click();
	await tab.getByRole('button', { name: 'Use block icon for all outputs', exact: true }).click();
	await setCustomColor(page, 'Background color for Spotify', '17304A80');
	await tab.getByLabel('Show link in blocks', { exact: true }).uncheck();
	await tab.getByRole('button', { name: 'Move Spotify down', exact: true }).click();
	await tab.getByLabel('New provider name').fill('Retired');
	await tab.getByRole('button', { name: 'Add provider', exact: true }).click();
	await save();
	settings = readSettings();
	expect(settings.providers).toEqual(expect.arrayContaining([
		{ name: 'Spotify', icon: 'fas fa-star', color: '#17304a80' },
		expect.objectContaining({ name: 'Retired' }),
	]));
	expect(settings.defaults['release-links'].items).toEqual(expect.arrayContaining([
		expect.objectContaining({ name: 'Spotify', enabled: false, title: 'Listen here' }),
		expect.objectContaining({ name: 'Retired', enabled: false, icon: 'fas fa-music' }),
	]));
	expect(settings.defaults['release-links'].items[0].name).toBe('Retired');
	expect(settings.defaults['release-links'].items.find((item) => item.name === 'Spotify')).not.toHaveProperty('icon');
	expect(readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_meta(${fixture.state.release}, 'streaming_links', true));`])).toBe(storedLinks);
	await page.goto(fixture.releaseUrl);
	await expect(page.locator('.mds-release-links__link[href="' + fixture.spotify + '"]')).toHaveCount(0);
	expect(readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(do_shortcode('[release-links release_id="${fixture.state.release}"]'));`]))
		.toContain('--mds-links-provider-background:#17304a80');

	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-release-links-block');
	await tab.getByRole('button', { name: 'Link buttons and providers', exact: true }).click();
	await tab.getByRole('button', { name: 'Retired', exact: true }).click();
	page.once('dialog', (dialog) => dialog.accept());
	await tab.getByRole('button', { name: 'Remove provider', exact: true }).click();
	await save();
	settings = readSettings();
	expect(settings.providers.some((provider) => provider.name === 'Retired')).toBe(false);
	expect(settings.defaults['release-links'].items).toContainEqual({ name: 'Retired', enabled: false, icon: 'fas fa-music' });
	await page.goto(`/wp-admin/post.php?post=${fixture.state.release}&action=edit`);
	const rows = page.locator('#mds_streaming_links_wrapper .mds-streaming-row');
	await expect(rows.nth(0).locator('select')).toHaveValue('Spotify');
	await expect(rows.nth(1).locator('select')).toHaveValue('Retired');
	await expect(rows.nth(1).locator('input[type="url"]')).toHaveValue('https://example.com/retired');
	await page.getByRole('button', { name: 'Update', exact: true }).click();
	await expect.poll(() => readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_meta(${fixture.state.release}, 'streaming_links', true));`])).toBe(storedLinks);
	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-release-links-block');
	await tab.getByRole('button', { name: 'Link buttons and providers', exact: true }).click();
	await tab.getByLabel('New provider name').fill('Retired');
	await tab.getByRole('button', { name: 'Add provider', exact: true }).click();
	const spotifyPanel = tab.getByRole('button', { name: 'Spotify', exact: true }).locator('xpath=ancestor::div[contains(@class, "components-panel__body")][1]');
	await tab.getByRole('button', { name: 'Spotify', exact: true }).click();
	await spotifyPanel.getByLabel('Show link in blocks', { exact: true }).check();
	await save();
	await page.goto(fixture.releaseUrl);
	await expect(page.locator('.mds-release-links__link[title="Listen here"]')).toHaveAttribute('style', /--mds-links-provider-background:\s*#17304a80/i);
	await expect(page.locator('.mds-release-links__link[title="Listen here"]')).toHaveCSS('background-color', 'rgba(23, 48, 74, 0.5)');
	expect(readWpJson(['eval', `echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_post_meta(${fixture.state.release}, 'streaming_links', true));`])).toBe(storedLinks);

	runWpCli(['eval', `
		$defaults = get_option('mds_rm_block_defaults');
		foreach ($defaults['release-links']['items'] as &$item) {
			if ('Spotify' === $item['name']) $item['icon'] = 'far fa-heart';
		}
		update_option('mds_rm_block_defaults', $defaults);
	`]);
	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-release-links-block');
	await tab.getByRole('button', { name: 'Link buttons and providers', exact: true }).click();
	await tab.getByRole('button', { name: 'Spotify', exact: true }).click();
	await tab.getByRole('button', { name: 'Use provider icon in blocks', exact: true }).click();
	await save();
	settings = readSettings();
	expect(settings.providers[0].icon).toBe('fas fa-star');
	expect(settings.defaults['release-links'].items.find((item) => item.name === 'Spotify')).not.toHaveProperty('icon');

	const beforeScriptFailure = readSettings();
	await page.route('**/js/settings-defaults.js*', (route) => route.abort());
	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-release-links-block');
	await expect(page.locator('#mds-provider-fields input[name="mds_rm_providers[name][]"]')).toHaveCount(3);
	await save();
	expect(readSettings()).toEqual(beforeScriptFailure);
});

test('network extension tab names are not treated as site tab aliases', async ({ page, fixture }) => {
	await page.goto('/wp-login.php');
	await page.getByLabel('Username or Email Address').fill(fixture.username);
	await page.getByLabel('Password', { exact: true }).fill(fixture.password);
	await page.getByRole('button', { name: 'Log In' }).click();
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
	const origin = new URL(process.env.MDS_RM_TEST_URL).origin;
	await page.route('**/*', (route) => new URL(route.request().url()).origin === origin
		? route.continue()
		: route.abort());
	await page.goto('/wp-login.php');
	await page.getByLabel('Username or Email Address').fill(fixture.username);
	await page.getByLabel('Password', { exact: true }).fill(fixture.password);
	await page.getByRole('button', { name: 'Log In' }).click();
	await page.goto('/wp-admin/edit.php?post_type=release&page=mds-release-settings');
	expect((await page.locator('#mds-settings-tabs > a').allTextContents()).slice(0, 5)).toEqual([
		'General Settings', 'Audio Player Manager', 'Release Meta Block', 'Release Links Block', 'Artist Discography Block',
	]);
	const extensionOrder = readWpJson(['eval', `
		add_filter('mds_rm_site_settings_tabs', function($tabs) {
			$tab = array('label' => 'Extension', 'render' => function() {});
			return array('aa-extension' => $tab, 'general-settings' => $tab, 'audio-player-manager' => $tab, 'release-meta-block' => $tab, 'integrations' => $tab) + $tabs;
		});
		ob_start();
		MDS_RM_Admin_Interface::settings_page();
		$html = ob_get_clean();
		preg_match_all('/href="#tab-([^"]+)"/', $html, $matches);
		echo 'MDS_RM_TEST_JSON:' . wp_json_encode($matches[1]);
	`]);
	expect(extensionOrder).toEqual(['general-settings', 'audio-player-manager', 'release-meta-block', 'release-links-block', 'artist-discography-block', 'aa-extension']);
	await expect(page.getByRole('link', { name: 'General Settings', exact: true })).toHaveClass(/nav-tab-active/);
	for (const [old, label] of [
		['integrations', 'General Settings'], ['release-links', 'Release Links Block'],
		['shortcode', 'Release Links Block'], ['artist-discography', 'Artist Discography Block'],
		['release-meta', 'Release Meta Block'],
	]) {
		await page.goto(`/wp-admin/edit.php?post_type=release&page=mds-release-settings#tab-${old}`);
		await expect(page.getByRole('link', { name: label, exact: true })).toHaveClass(/nav-tab-active/);
	}
	const metaTab = page.locator('#tab-release-meta-block');
	const metaContainer = metaTab.getByRole('button', { name: 'Metadata container', exact: true });
	const metaTypography = metaTab.getByRole('button', { name: 'Release Meta typography', exact: true });
	const metaItems = metaTab.getByRole('button', { name: 'Metadata fields: visibility, order, and icons', exact: true });
	await expect(metaContainer).toHaveAttribute('aria-expanded', 'false');
	await expect(metaTypography).toHaveAttribute('aria-expanded', 'false');
	await expect(metaTab.getByRole('button', { name: 'Release Meta icons', exact: true })).toHaveAttribute('aria-expanded', 'false');
	await metaItems.click();
	await expect(metaItems).toHaveAttribute('aria-expanded', 'true');
	await metaContainer.press('Enter');
	await expect(metaItems).toHaveAttribute('aria-expanded', 'false');
	await expect(metaTab.getByText(/This is the box around all metadata fields/)).toBeVisible();
	const preset = metaTab.getByLabel('Metadata container background preset', { exact: true });
	await expect(preset).toBeVisible();
	const presetValue = await preset.locator('option').nth(1).getAttribute('value');
	await preset.selectOption(presetValue);
	expect(JSON.parse(await page.locator('#mds-rm-block-defaults').inputValue())['release-meta'].metaStyles.background).toBe(presetValue);
	await setCustomColor(page, 'Metadata container background', '17304A00');
	await metaTab.getByRole('button', { name: 'Choose custom color for Metadata container background', exact: true }).click();
	await page.getByRole('button', { name: 'Clear / use default', exact: true }).click();
	await expect(metaTab.getByRole('button', { name: 'Choose custom color for Metadata container background', exact: true })).toHaveText('Choose custom color');
	await metaTab.getByRole('button', { name: 'Choose custom color for Metadata container background', exact: true }).click();
	await setCustomColor(page, 'Metadata container background', '17304A80');
	await metaTypography.click();
	await expect(metaTypography).toHaveAttribute('aria-expanded', 'true');
	await expect(metaContainer).toHaveAttribute('aria-expanded', 'false');
	await expect(metaItems).toHaveAttribute('aria-expanded', 'false');
	await metaTypography.press('Space');
	await expect(metaTypography).toHaveAttribute('aria-expanded', 'false');
	await metaItems.click();
	await expect(metaItems).toHaveAttribute('aria-expanded', 'true');
	await metaContainer.click();
	await expect(metaItems).toHaveAttribute('aria-expanded', 'false');
	await expect(metaContainer).toHaveAttribute('aria-expanded', 'true');

	await page.getByRole('link', { name: 'Release Links Block', exact: true }).click();
	const linksTab = page.locator('#tab-release-links-block');
	const linksContainer = linksTab.getByRole('button', { name: 'Links and audio container', exact: true });
	const linkButtons = linksTab.getByRole('button', { name: 'Streaming and Buy Now buttons', exact: true });
	const audioPlayer = linksTab.getByRole('button', { name: 'Audio preview player', exact: true });
	const behavior = linksTab.getByRole('button', { name: 'Link and audio behavior', exact: true });
	const linkItems = linksTab.getByRole('button', { name: 'Link buttons and providers', exact: true });
	for (const panel of [linksContainer, linkButtons, audioPlayer]) {
		await expect(panel).toHaveAttribute('aria-expanded', 'false');
	}
	await linkItems.click();
	await behavior.click();
	await expect(linkItems).toHaveAttribute('aria-expanded', 'false');
	await linksContainer.click();
	await expect(behavior).toHaveAttribute('aria-expanded', 'false');
	const containerBorder = linksTab.getByLabel('Links and audio container: Border style', { exact: true });
	await expect(containerBorder).toBeVisible();
	await setCustomColor(page, 'Links and audio container background', '25403880');
	await linkButtons.click();
	await expect(containerBorder).toBeHidden();
	await expect(linksTab.getByLabel('Link button: Border style', { exact: true })).toBeVisible();
	await expect(linksTab.getByText(/A button background set here overrides provider colors/)).toBeVisible();
	await audioPlayer.click();
	await expect(linkButtons).toHaveAttribute('aria-expanded', 'false');
	await expect(audioPlayer).toHaveAttribute('aria-expanded', 'true');
	await expect(behavior).toHaveAttribute('aria-expanded', 'false');

	await page.getByRole('link', { name: 'Artist Discography Block', exact: true }).click();
	const discographyTab = page.locator('#tab-artist-discography-block');
	const discographyPanels = [
		['Outer discography block', 'Outer block'],
		['Table frame and base styles', 'Table'],
		['Column headings', 'Column headings'],
		['Release rows and striping', 'Release rows'],
		['Cell grid and release links'],
	].map(([name, target]) => [discographyTab.getByRole('button', { name, exact: true }), target]);
	for (const [panel] of discographyPanels) {
		await expect(panel).toHaveAttribute('aria-expanded', 'false');
	}
	for (const [panel, target] of discographyPanels) {
		await panel.click();
		for (const [other] of discographyPanels) {
			await expect(other).toHaveAttribute('aria-expanded', other === panel ? 'true' : 'false');
		}
		if (target) await expect(discographyTab.getByLabel(`${target}: Background color preset`, { exact: true })).toBeVisible();
		if (target === 'Outer block') await setCustomColor(page, 'Outer block: Background color', 'DCE6F080');
		if (target === 'Table') await setCustomColor(page, 'Table: Background color', 'E9D8C280');
		if (target === 'Table') await expect(discographyTab.getByText(/Frame padding adds space around the table/)).toBeVisible();
	}
	await page.getByRole('link', { name: 'Release Meta Block', exact: true }).click();

	await page.getByRole('link', { name: 'Release Links Block', exact: true }).click();
	await behavior.click();
	await expect(audioPlayer).toHaveAttribute('aria-expanded', 'false');
	await linksTab.getByLabel('Open links in a new tab', { exact: true }).uncheck();
	expect(JSON.parse(await page.locator('#mds-rm-block-defaults').inputValue())).toMatchObject({
		'release-meta': { metaStyles: { background: '#17304a80' } },
		'release-links': { linkStyles: { background: '#25403880' } },
		'artist-discography': {
			style: { color: { background: '#dce6f080' } },
			tableStyles: { 'table-background': '#e9d8c280' },
		},
	});
	const [response] = await Promise.all([
		page.waitForResponse((res) => res.request().method() === 'POST' && res.url().includes('/mds/v1/settings')),
		linksTab.getByRole('button', { name: 'Save Settings' }).click(),
	]);
	expect(response.status()).toBe(200);
	expect(await response.json()).toMatchObject({ success: true });
	expect(readWpJson(['eval', "echo 'MDS_RM_TEST_JSON:' . wp_json_encode(get_option('mds_rm_block_defaults'));"]))
		.toMatchObject({ 'release-meta': { metaStyles: { background: '#17304a80' } } });

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
	await page.goto('/wp-login.php');
	await page.getByLabel('Username or Email Address').fill(fixture.username);
	await page.getByLabel('Password', { exact: true }).fill(fixture.password);
	await page.getByRole('button', { name: 'Log In' }).click();
	const cases = [
		{ name: 'mds-rm/release-meta', attrs: { releaseId: fixture.state.release }, panel: 'Metadata container', label: 'Metadata container background', key: 'metaStyles', field: 'background', selector: '.wp-block-mds-rm-release-meta .mds-release-meta' },
		{ name: 'mds-rm/release-links', attrs: { releaseId: fixture.state.release }, panel: 'Links and audio container', label: 'Links and audio container background', key: 'linkStyles', field: 'background', selector: '.wp-block-mds-rm-release-links .mds-release-links' },
		{ name: 'mds-rm/artist-discography', attrs: { artistId: fixture.state.artist }, panel: 'Table frame and base styles', label: 'Table: Background color', key: 'tableStyles', field: 'table-background', selector: '.wp-block-mds-rm-artist-discography table' },
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
			await expect(page.getByRole('link', { name: 'General Settings', exact: true })).toHaveClass(/nav-tab-active/);
			await page.getByRole('link', { name: 'Artist Discography Block', exact: true }).click();
			const discographySettings = page.locator('#tab-artist-discography-block');
			await expect(discographySettings.getByRole('button', { name: 'Outer discography block', exact: true })).toBeVisible();
			await expect(discographySettings.getByRole('button', { name: 'Table frame and base styles', exact: true })).toBeVisible();
			await expect(discographySettings.getByRole('button', { name: 'Save Settings' })).toBeVisible();
			await discographySettings.getByRole('button', { name: 'Release rows and striping', exact: true }).click();
			await discographySettings.getByLabel('Striped rows').check();
			await page.getByRole('link', { name: 'Release Links Block', exact: true }).click();
			await expect(page.locator('#mds-settings-form')).toHaveCSS('margin-top', '20px');
			await page.getByRole('button', { name: 'Link and audio behavior' }).click();
			await page.getByLabel('Open links in a new tab', { exact: true }).uncheck();
			await page.getByRole('button', { name: 'Link buttons and providers' }).click();
			await page.getByLabel('New provider name').fill(fixture.label);
			await page.getByRole('button', { name: 'Add provider', exact: true }).click();
			const [response] = await Promise.all([
				page.waitForResponse((response) =>
					response.request().method() === 'POST' && response.url().includes('/mds/v1/settings'), { timeout: 15_000 }),
				page.locator('#tab-release-links-block').getByRole('button', { name: 'Save Settings' }).click(),
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
			await page.getByRole('button', { name: 'Metadata container', exact: true }).click();
			await expect(page.getByLabel('Metadata container background preset', { exact: true })).toBeVisible();
			await page.getByRole('button', { name: 'Release Meta typography', exact: true }).click();
			await expect(page.getByText(/Styles the displayed metadata values/)).toBeVisible();
			await page.getByRole('button', { name: 'Release Meta icons', exact: true }).click();
			await expect(page.getByText(/open that field’s panel in the block’s Settings tab/)).toBeVisible();
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
			await page.getByRole('button', { name: 'Links and audio container', exact: true }).click();
			await page.getByRole('button', { name: 'Streaming and Buy Now buttons', exact: true }).click();
			await expect(page.getByLabel('Links and audio container: Border style', { exact: true })).toBeVisible();
			await expect(page.getByLabel('Link button: Border style', { exact: true })).toBeVisible();
			await page.getByRole('button', { name: 'Audio preview player', exact: true }).click();
			await expect(page.getByText(/Sets the width of the audio player/)).toBeVisible();
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
			await page.getByRole('button', { name: 'Outer discography block', exact: true }).click();
			await expect(page.getByText(/built-in Color, Typography, Dimensions, and Border controls/)).toBeVisible();
			for (const panel of ['Table frame and base styles', 'Column headings', 'Release rows and striping', 'Cell grid and release links']) {
				await page.getByRole('button', { name: panel, exact: true }).click();
			}
			await expect(page.getByLabel('Table: Background color preset', { exact: true })).toBeVisible();
			await expect(page.getByLabel('Release rows: Background color preset', { exact: true })).toBeVisible();
			await expect(page.getByText(/not the pagination links/)).toBeVisible();
			await page.getByLabel('Column headings: Text alignment', { exact: true }).selectOption('center');
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
