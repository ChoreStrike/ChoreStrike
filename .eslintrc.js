module.exports = {
	extends: [
		'@nextcloud',
	],
	overrides: [
		{
			files: ['src/public-path.js'],
			globals: { __webpack_public_path__: 'writable' },
			rules: { camelcase: ['error', { allow: ['__webpack_public_path__'] }] },
		},
		{
			files: ['**/*.vue'],
			parser: 'vue-eslint-parser',
			parserOptions: {
				parser: '@typescript-eslint/parser',
			},
		},
		{
			// eslint-plugin-n@16 (TECH-LINT-006 peer graph) does not track
			// Node's experimental `localStorage`, so the browser global needs
			// no ignore; Node's `crypto` builtin is supported on all targeted
			// versions, so Web Crypto usage needs none either.
			files: ['tests/e2e/**/*.ts'],
			parser: '@typescript-eslint/parser',
			parserOptions: {
				ecmaVersion: 2022,
				sourceType: 'module',
			},
			plugins: ['@typescript-eslint'],
			rules: {
				'@typescript-eslint/func-call-spacing': 'off',
				'@typescript-eslint/no-explicit-any': 'off',
				'@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
				'jsdoc/require-jsdoc': 'off',
				'jsdoc/require-param': 'off',
				'jsdoc/check-tag-names': 'off',
				'jsdoc/multiline-blocks': 'off',
				'n/no-unsupported-features/node-builtins': 'off',
				// E2E specs are never published; devDependencies (e.g. axe) are their imports.
				'n/no-unpublished-import': 'off',
				'no-console': 'off',
				'no-unused-vars': 'off',
				'padded-blocks': 'off',
			},
		},
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'jsdoc/tag-lines': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
}
