/**
 * ESLint flat configuration (CS-PLAN-088 / TECH-LINT-008).
 *
 * Builds on the shared Nextcloud preset; everything below is a deliberate,
 * reviewed project policy carried over from the former `.eslintrc.js`.
 */
const { recommended } = require('@nextcloud/eslint-config')

// The preset sorts side-effect imports behind all others. `src/main.js` must
// run `public-path.js` first (it sets the webpack public path before any other
// module is evaluated), so that one import leads there; the stylesheets stay
// behind the application imports and every other sorting option stays the
// preset's own.
const [severity, sortImportsOptions] = recommended
	.map((config) => config.rules?.['perfectionist/sort-imports'])
	.find(Boolean)

module.exports = [
	...recommended,
	{
		files: ['src/main.js'],
		rules: {
			'perfectionist/sort-imports': [severity, {
				...sortImportsOptions,
				groups: ['runtimePublicPath', ...sortImportsOptions.groups],
				customGroups: [
					...sortImportsOptions.customGroups,
					{ groupName: 'runtimePublicPath', elementNamePattern: ['^\\./public-path\\.js$'] },
				],
			}],
		},
	},
	{
		rules: {
			// Policy kept from the legacy configuration: no mandatory JSDoc
			// blocks or tag-line spacing, and no forced attribute line break.
			'jsdoc/require-jsdoc': 'off',
			'jsdoc/tag-lines': 'off',
			'vue/first-attribute-linebreak': 'off',
		},
	},
	{
		// Injected by webpack, not declared anywhere.
		files: ['src/public-path.js'],
		languageOptions: { globals: { __webpack_public_path__: 'writable' } },
	},
	{
		// The sanctioned console wrapper (project rules: no raw `console.*`
		// elsewhere); `process.env.NODE_ENV` is replaced by webpack.
		files: ['src/utils/logger.js'],
		languageOptions: { globals: { process: 'readonly' } },
		rules: { 'no-console': 'off' },
	},
	{
		// The only Jest spec inside `src/`; the others live in `tests/`.
		files: ['src/**/*.spec.js'],
		languageOptions: {
			globals: {
				describe: 'readonly',
				expect: 'readonly',
				it: 'readonly',
				jest: 'readonly',
			},
		},
	},
	{
		// Playwright suites: console output and loose typing are acceptable,
		// they never ship with the app.
		files: ['tests/e2e/**/*.ts', 'tests/docs-screenshots/**/*.ts'],
		rules: {
			'@typescript-eslint/no-explicit-any': 'off',
			'@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
			'no-console': 'off',
		},
	},
]
