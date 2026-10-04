<template>
	<NcSelect
		v-model="model"
		:options="options"
		:loading="isSearching"
		:multiple="true"
		keepOpen
		:filterable="false"
		label="label"
		:placeholder="t('attendance', 'Search users, groups or teams …')"
		@search="onSearch">
		<template #option="{ label, type, isGuest }">
			<span class="audience-option" :title="typeLabel(type, isGuest)">
				<component :is="typeIcon(type, isGuest)" :size="20" />
				<span>{{ label }}</span>
			</span>
		</template>
		<template #selected-option="{ label, type, isGuest }">
			<span class="audience-option" :title="typeLabel(type, isGuest)">
				<component :is="typeIcon(type, isGuest)" :size="16" />
				<span>{{ label }}</span>
			</span>
		</template>
	</NcSelect>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { computed, ref } from 'vue'
import Account from 'vue-material-design-icons/Account.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import { searchDirectory } from '../../utils/audience.js'

/** The selected users, groups and teams, as utils/audience.js shapes them. */
const model = defineModel({ type: Array, default: () => [] })

const props = defineProps({
	/**
	 * Offer a `create-guest` entry for an email address no user matches. The
	 * parent turns a selected one into an account.
	 */
	guestInvitation: {
		type: Boolean,
		default: false,
	},
})

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

// Null while nothing is typed: the dropdown then lists the selection itself.
const searchResults = ref(null)
const isSearching = ref(false)
const options = computed(() => searchResults.value ?? model.value)

function typeLabel(type, isGuest) {
	switch (type) {
		case 'user':
			return isGuest ? t('attendance', 'Guest account') : t('attendance', 'User')
		case 'group':
			return t('attendance', 'Group')
		case 'team':
			return t('attendance', 'Team')
		default:
			return ''
	}
}

function typeIcon(type, isGuest) {
	switch (type) {
		case 'team':
			return AccountStar
		case 'group':
			return AccountGroup
		case 'create-guest':
			return AccountPlus
		default:
			return isGuest ? AccountQuestion : Account
	}
}

async function onSearch(query) {
	if (!query) {
		searchResults.value = null
		return
	}

	isSearching.value = true
	try {
		const results = await searchDirectory(query, model.value)

		const email = query.trim()
		if (props.guestInvitation
			&& EMAIL_REGEX.test(email)
			&& !results.some((r) => r.type === 'user' && (r.value === email || r.label === email))) {
			results.push({
				id: `create-guest:${email.toLowerCase()}`,
				value: email,
				label: t('attendance', 'Create guest account for {email}', { email }),
				type: 'create-guest',
				email,
				isGuest: false,
			})
		}

		searchResults.value = results
	} catch (error) {
		console.error('Failed to search:', error)
		searchResults.value = null
	} finally {
		isSearching.value = false
	}
}
</script>

<style scoped>
.audience-option {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
