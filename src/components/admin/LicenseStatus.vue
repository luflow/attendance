<template>
	<div class="license-status" data-test="license-status">
		<h4>{{ t('attendance', 'License') }}</h4>

		<NcLoadingIcon v-if="loading && !status" :size="20" />
		<template v-else-if="note">
			<NcNoteCard :type="note.type" data-test="license-status-note">
				<p v-for="line in note.lines" :key="line">
					{{ line }}
				</p>
			</NcNoteCard>
			<p v-if="status.checkedAt" class="license-status__checked">
				{{ t('attendance', 'Last checked: {datetime}', { datetime: formatDateTimeMedium(status.checkedAt) }) }}
			</p>
		</template>

		<p v-if="!licensed && status?.license?.purchaseInterestAt" class="license-status__interest" data-test="license-purchase-interest">
			{{ t('attendance', 'A member of your organization would like to use the mobile app.') }}
		</p>
		<p v-if="!licensed" class="license-status__invoice">
			{{ t('attendance', 'Buying for a club or organization? Order the group license by invoice on the website. The invoice is made out to your organization, and nobody has to pay with a private app store account.') }}
		</p>
		<div class="license-status__actions">
			<NcButton v-if="!licensed"
				variant="primary"
				:href="INVOICE_ORDER_URL"
				target="_blank"
				rel="noopener"
				data-test="button-order-by-invoice">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
				{{ t('attendance', 'Order by invoice') }}
			</NcButton>
			<NcButton variant="tertiary"
				:disabled="loading"
				data-test="button-license-refresh"
				@click="load(true)">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('attendance', 'Check again') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { computed, onMounted, ref } from 'vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { formatDate, formatDateTimeMedium } from '../../utils/datetime.js'
import { INVOICE_ORDER_URL } from '../../utils/mobileApp.js'

const status = ref(null)
const loading = ref(false)

// A paying instance needs no pitch; the status and the refresh stay.
const licensed = computed(() => status.value?.license?.status === 'active')

const PLAN_NAMES = { basic: 'Basic', standard: 'Standard', premium: 'Premium' }

function planName(tier) {
	return PLAN_NAMES[tier] ?? t('attendance', 'Group')
}

function devicesLine(count) {
	return n('attendance', '{count} device uses the mobile app.', '{count} devices use the mobile app.', count, { count })
}

const unavailable = { type: 'info', lines: [t('attendance', 'The license status is not available right now.')] }

const note = computed(() => {
	if (!status.value) {
		return null
	}
	const { state, license } = status.value
	if (state === 'no_devices') {
		return { type: 'info', lines: [t('attendance', 'No device has connected the mobile app for push notifications yet, so there is no license status to show. The 30-day trial starts with the first device.')] }
	}
	if (state === 'unreachable') {
		return { type: 'info', lines: [t('attendance', 'The license status cannot be shown here: the push relay cannot reach this Nextcloud from the internet.')] }
	}
	if (state !== 'ok' || !license) {
		return unavailable
	}

	const devices = devicesLine(license.deviceCount)
	switch (license.status) {
		case 'trial': {
			const lines = [t('attendance', 'The trial runs until {date}.', { date: formatDate(license.trialEndsAt) }), devices]
			if (license.groupTrialAvailable) {
				lines.push(n(
					'attendance',
					'As soon as {count} person uses the app, the trial starts over for the whole group.',
					'As soon as {count} people use the app, the trial starts over for the whole group.',
					license.groupTrialDevices,
					{ count: license.groupTrialDevices },
				))
			}
			return { type: 'info', lines }
		}
		case 'active': {
			const plan = planName(license.tier)
			const lines = [license.expiresAt
				? t('attendance', '{plan} license, valid until {date}.', { plan, date: formatDate(license.expiresAt) })
				: t('attendance', '{plan} license, with no end date.', { plan })]
			if (license.memberLimit) {
				lines.push(n('attendance', 'It covers up to {count} member.', 'It covers up to {count} members.', license.memberLimit, { count: license.memberLimit }))
			}
			lines.push(devices)
			return { type: 'success', lines }
		}
		case 'expired': {
			const ended = license.tier && license.expiresAt
				? t('attendance', 'The {plan} license expired on {date}.', { plan: planName(license.tier), date: formatDate(license.expiresAt) })
				: license.trialEndsAt
					? t('attendance', 'The trial ended on {date}.', { date: formatDate(license.trialEndsAt) })
					: t('attendance', 'The trial has ended.')
			return { type: 'warning', lines: [ended, t('attendance', 'The mobile app needs a license to keep working.'), devices] }
		}
		default:
			return unavailable
	}
})

async function load(refresh = false) {
	loading.value = true
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/admin/license'), { params: refresh ? { refresh: true } : {} })
		status.value = response.data
	} catch (error) {
		console.error('Failed to load the license status:', error)
		status.value = { state: 'unavailable', license: null, checkedAt: null }
	} finally {
		loading.value = false
	}
}

onMounted(() => load())
</script>

<style scoped>
.license-status h4 {
	margin: 0 0 8px 0;
	font-size: 15px;
	font-weight: 600;
}

.license-status__checked {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.license-status__interest {
	margin-top: 12px;
	font-weight: 600;
}

.license-status__interest + .license-status__invoice {
	margin-top: 4px;
}

.license-status__invoice {
	margin-top: 12px;
}

.license-status__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}
</style>
