import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * Resolves selected category ids to their category objects for an "active
 * filters" chip row, and splits the translated chip label around its
 * placeholder. Shared by every view that renders a category filter chip row.
 *
 * @param {import('vue').Ref<Array<number>>} selectedCategoryIds - Currently selected category ids.
 * @param {(categoryId: number) => ?object} getCategory - From useCategories().
 * @return {{selectedCategoryChips: import('vue').ComputedRef<Array<object>>, categoryChipLabel: import('vue').ComputedRef<{before: string, after: string}>}}
 */
export function useCategoryFilterChips(selectedCategoryIds, getCategory) {
	// Stale ids (category deleted after being stored/selected) are dropped
	// rather than shown as an unresolvable chip.
	const selectedCategoryChips = computed(() => selectedCategoryIds.value
		.map((id) => getCategory(id))
		.filter(Boolean))

	// Deliberately unsubstituted: splitting the translated label at its own
	// placeholder puts the icon next to the name, in the translator's order.
	const categoryChipLabel = computed(() => {
		const [before = '', after = ''] = t('attendance', 'Category: {category}').split('{category}')
		return { before: before.trim(), after: after.trim() }
	})

	return { selectedCategoryChips, categoryChipLabel }
}
