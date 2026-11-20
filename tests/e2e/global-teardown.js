const { getRewriteRules, runWpCli } = require('./wp-cli');

module.exports = async function globalTeardown() {
	runWpCli(['plugin', 'deactivate', 'mds-release-manager', '--network']);
	try {
		const staleRules = getRewriteRules().filter((rule) => /^(release|artist|release-tag|release-format)(\/|\/?$)/.test(rule));

		if (staleRules.length > 0) {
			throw new Error(`Deactivation left custom rewrite rules behind: ${staleRules.join(', ')}`);
		}
	} finally {
		runWpCli(['plugin', 'activate', 'mds-release-manager', '--network']);
	}
};
