const { realpathSync } = require('node:fs');
const { isAbsolute, resolve, sep } = require('node:path');
const { getRewriteRules, readWpJson, runWpCli } = require('./wp-cli');
const verifyDeactivation = require('./global-teardown');

module.exports = async function globalSetup() {
	for (const name of ['MDS_RM_PHP', 'MDS_RM_WP_CLI', 'MDS_RM_TEST_URL', 'MDS_RM_WP_PATH']) {
		if (!process.env[name]) {
			throw new Error(`${name} must be set to run the smoke suite.`);
		}
	}

	const url = new URL(process.env.MDS_RM_TEST_URL);
	if (process.env.MDS_RM_TEST_ALLOW_MUTATIONS !== '1' ||
		!['http:', 'https:'].includes(url.protocol) || url.username || url.password ||
		!(/\.local$|\.test$/.test(url.hostname) || ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname))) {
		throw new Error('Run only against a disposable local site with MDS_RM_TEST_ALLOW_MUTATIONS=1.');
	}

	const root = process.env.MDS_RM_WP_PATH;
	if (!isAbsolute(root) || realpathSync(resolve(__dirname, '..', '..')).toLowerCase()
		.startsWith(`${realpathSync(root).toLowerCase()}${sep}`)) {
		throw new Error('Use an absolute WordPress root separate from the source checkout.');
	}

	const site = readWpJson(['eval', "echo 'MDS_RM_TEST_JSON:' . wp_json_encode(array(home_url(), site_url(), is_multisite(), count(get_sites(array('number' => 2)))));"]);
	const normalize = (value) => new URL(value).href.replace(/\/$/, '');
	if (!site[2] || site[3] !== 1 || normalize(site[0]) !== normalize(url.href) || normalize(site[1]) !== normalize(url.href)) {
		throw new Error('The configured root and URL must identify the same disposable one-site multisite network.');
	}

	runWpCli(['plugin', 'is-active', 'mds-release-manager', '--network']);
	runWpCli(['plugin', 'deactivate', 'mds-release-manager', '--network']);
	try {
		runWpCli(['rewrite', 'flush']);
		const staleRules = getRewriteRules().filter((rule) => /^(release|artist|release-tag|release-type|release-format)\//.test(rule));
		if (staleRules.length > 0) {
			throw new Error(`Could not clear custom rewrite rules before activation: ${staleRules.join(', ')}`);
		}
	} finally {
		runWpCli(['plugin', 'activate', 'mds-release-manager', '--network']);
	}

	return verifyDeactivation;
};
