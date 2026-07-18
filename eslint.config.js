import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		rules: {
			'jsdoc/require-jsdoc': 'off',
			'vue/first-attribute-linebreak': 'off',
			// Single-line guard clauses (`if (x) { return }`) are the
			// established idiom in this codebase.
			'@stylistic/max-statements-per-line': 'off',
			// Loose equality against null intentionally covers undefined.
			eqeqeq: ['error', 'always', { null: 'ignore' }],
			'no-console': ['error', { allow: ['error', 'warn', 'debug'] }],
			// Single-word view names (Widget, Checkin) are established routes.
			'vue/multi-word-component-names': 'off',
		},
	},
]
