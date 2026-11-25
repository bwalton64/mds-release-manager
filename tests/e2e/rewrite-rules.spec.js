const { expect, test } = require('@playwright/test');
const { readWpJson } = require('./wp-cli');

test('network activation makes Release and Artist archives routable', async ({ page }) => {
	const archives = readWpJson([
		'eval',
		"echo 'MDS_RM_TEST_JSON:' . wp_json_encode(array('release' => get_post_type_archive_link('release'), 'artist' => get_post_type_archive_link('artist')));",
	]);
	for (const [archive, archiveUrl] of Object.entries(archives)) {
		expect(archiveUrl, `${archive} archive link should be generated`).toBeTruthy();
		const response = await page.goto(archiveUrl);
		expect(response, `${archive} archive should respond`).not.toBeNull();
		expect(response.status(), `${archive} archive should resolve after activation`).toBe(200);
	}
});
