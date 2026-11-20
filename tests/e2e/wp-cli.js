const { spawnSync } = require('node:child_process');

function spawnWpCli(args, env = {}) {
	return spawnSync(
		process.env.MDS_RM_PHP,
		[
			...(process.env.MDS_RM_PHP_INI ? ['-c', process.env.MDS_RM_PHP_INI] : []),
			process.env.MDS_RM_WP_CLI,
			`--path=${process.env.MDS_RM_WP_PATH}`,
			`--url=${process.env.MDS_RM_TEST_URL}`,
			...args,
		],
		{ cwd: process.env.MDS_RM_WP_PATH, encoding: 'utf8', env: { ...process.env, ...env } }
	);
}

function runWpCli(args, env) {
	const result = spawnWpCli(args, env);

	if (result.error) {
		throw result.error;
	}

	if (result.status !== 0) {
		throw new Error(`WP-CLI ${args.join(' ')} failed:\n${result.stderr || result.stdout}`);
	}

	return result.stdout.trim();
}

function readWpJson(args, env) {
	const marker = 'MDS_RM_TEST_JSON:';
	const output = runWpCli(args, env);
	const markerIndex = output.lastIndexOf(marker);

	if (markerIndex === -1) {
		throw new Error('WP-CLI did not return the test JSON marker.');
	}

	return JSON.parse(output.slice(markerIndex + marker.length));
}

function getRewriteRules() {
	const rules = readWpJson([
		'eval',
		"echo 'MDS_RM_TEST_JSON:' . wp_json_encode(array_keys(get_option('rewrite_rules')));",
	]);
	if (!Array.isArray(rules)) {
		throw new Error('WP-CLI returned an invalid rewrite-rule list.');
	}

	return rules;
}

module.exports = { getRewriteRules, readWpJson, runWpCli };
