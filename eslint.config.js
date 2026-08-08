import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		rules: {
			'jsdoc/require-jsdoc': 'off',
			// Single-line guard clauses (`if (x) return`) are the
			// established idiom in this codebase.
			curly: ['error', 'multi-line'],
			'no-console': ['error', { allow: ['error', 'warn', 'debug'] }],
		},
	},
	{
		files: ['**/*.vue'],
		rules: {
			'vue/first-attribute-linebreak': 'off',
			// Single-word view names that predate the rule.
			'vue/multi-word-component-names': ['error', { ignores: ['Widget', 'Checkin'] }],
			// Nextcloud core paints a centred spinner behind anything carrying
			// these classes, so own content ends up sitting on top of it.
			'vue/no-restricted-class': ['error', 'loading', 'loading-small'],
		},
	},
]
