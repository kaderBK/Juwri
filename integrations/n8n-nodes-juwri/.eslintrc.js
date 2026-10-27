// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * n8n community-node lint. `eslint-plugin-n8n-nodes-base` encodes the
 * verification guidelines — including the rule that a webhook/trigger node MUST
 * define all three lifecycle methods (checkExists/create/delete), the credential
 * naming/description rules, and the community package.json requirements.
 */
module.exports = {
	root: true,
	env: { browser: true, es6: true, node: true },
	parser: '@typescript-eslint/parser',
	parserOptions: {
		sourceType: 'module',
		extraFileExtensions: ['.json'],
	},
	ignorePatterns: [
		'.eslintrc.js',
		'**/*.js',
		'**/*.mjs',
		'**/node_modules/**',
		'**/dist/**',
		'test/**',
		'templates/**',
		'scripts/**',
	],
	overrides: [
		{
			files: ['package.json'],
			plugins: ['eslint-plugin-n8n-nodes-base'],
			extends: ['plugin:n8n-nodes-base/community'],
			rules: {
				// The published name is intentional (not the starter default).
				'n8n-nodes-base/community-package-json-name-still-default': 'off',
			},
		},
		{
			files: ['./credentials/**/*.ts'],
			plugins: ['eslint-plugin-n8n-nodes-base'],
			extends: ['plugin:n8n-nodes-base/credentials'],
			rules: {
				// `documentationUrl` is a full https URL (satisfies the companion
				// `-not-http-url` rule). This rule wrongly "camelCases" a full URL and
				// conflicts with it — disabled so the URL stays a real link.
				'n8n-nodes-base/cred-class-field-documentation-url-miscased': 'off',
			},
		},
		{
			files: ['./nodes/**/*.ts'],
			plugins: ['eslint-plugin-n8n-nodes-base'],
			extends: ['plugin:n8n-nodes-base/nodes'],
		},
	],
};
