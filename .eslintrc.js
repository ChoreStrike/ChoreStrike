module.exports = {
	extends: [
		'@nextcloud',
	],
	overrides: [
		// TypeScript support for Vue SFCs with <script setup lang="ts">
		{
			files: ['**/*.vue'],
			parser: 'vue-eslint-parser',
			parserOptions: {
				parser: '@typescript-eslint/parser',
			},
		},
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'jsdoc/tag-lines': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
}
