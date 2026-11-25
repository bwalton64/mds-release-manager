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

test('shortcodes expose block selection, options, and styling', async ({ fixture }) => {
	const { artist, release } = fixture.state;
	const output = readWpJson([
		'eval',
		`
			$release = get_post(${release});
			$artist = get_post(${artist});
			$GLOBALS['post'] = $release;
			setup_postdata($release);
			$meta_id = do_shortcode('[release-meta release_id="${release}" upc="000000000000" fields="record_label" gap="22px" font_style="italic" text_align_mobile="center" letter_spacing="1px"]');
			$meta_upc = do_shortcode('[release-meta release_id="${artist}" upc="' . get_post_meta(${release}, 'upc_ean', true) . '" fields="record_label"]');
			$meta_context = do_shortcode('[release-meta fields="record_label"]');
			$links = do_shortcode('[release-links release_id="${release}" upc="000000000000" order="Spotify" show_audio="0" new_tab="0" button_size="52px" button_size_mobile="44px"]');
			$links_context = do_shortcode('[release-links order="Spotify" show_audio="0"]');
			$discography_default_stripe = do_shortcode('[artist-discography artist_id="${artist}" striped="1"]');
			$discography = do_shortcode('[artist-discography artist_id="${artist}" striped="1" stripe_background="#eeeeee" table_background="#ffffff" cell_padding_top="12px" wrapper_text_color="#123456" wrapper_font_size="20px"]');
			echo 'MDS_RM_TEST_JSON:' . wp_json_encode(array(
				'meta_id' => $meta_id,
				'meta_upc' => $meta_upc,
				'meta_context' => $meta_context,
				'links' => $links,
				'links_context' => $links_context,
				'discography_default_stripe' => $discography_default_stripe,
				'discography' => $discography,
				'discography_style_enqueued' => wp_style_is('mds-rm-discography-block', 'enqueued'),
			));
		`,
	]);

	expect(output.meta_id).toContain(fixture.label);
	expect(output.meta_id).toContain('wp-block-mds-rm-release-meta');
	expect(output.meta_id).toContain('--mds-meta-gap-desktop:22px');
	expect(output.meta_id).toContain('--mds-meta-font-style-desktop:italic');
	expect(output.meta_id).toContain('--mds-meta-text-align-mobile:center');
	expect(output.meta_upc).toContain(fixture.label);
	expect(output.meta_context).toContain(fixture.label);

	expect(output.links).toContain(fixture.spotify);
	expect(output.links).toContain('--mds-links-button-size-desktop:52px');
	expect(output.links).toContain('--mds-links-button-size-mobile:44px');
	expect(output.links).not.toContain('target="_blank"');
	expect(output.links).not.toContain('mds-release-links__audio');
	expect(output.links_context).toContain(fixture.spotify);

	expect(output.discography_default_stripe).toMatch(/--mds-discography-stripe-background:(?:#[a-f0-9]{6}|rgba?\()/i);
	expect(output.discography).toContain(fixture.title);
	expect(output.discography).toContain('--mds-discography-table-background:#ffffff');
	expect(output.discography).toContain('--mds-discography-cell-padding-top:12px');
	expect(output.discography).toContain('--mds-discography-stripe-background:#eeeeee');
	expect(output.discography).toContain('has-text-color');
	expect(output.discography).toContain('font-size:20px');
	expect(output.discography_style_enqueued).toBe(true);
});
