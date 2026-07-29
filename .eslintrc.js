module.exports = {
	extends: [
		'@nextcloud',
	],
	overrides: [
		{
			files: ['**/*.vue'],
			parser: 'vue-eslint-parser',
			parserOptions: {
				parser: '@typescript-eslint/parser',
			},
		},
		{
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
