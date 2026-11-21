<template>
	<div class="rating-request" data-test="rating-request">
		<StarIcon class="rating-request__icon" :size="24" />
		<p class="rating-request__text">
			{{ t('attendance', 'If you have an account in the Nextcloud App Store, a rating there helps other groups find Attendance.') }}
		</p>
		<div class="rating-request__actions">
			<NcButton variant="primary"
				:href="RATING_URL"
				target="_blank"
				rel="noopener"
				data-test="rating-request-link"
				@click="emit('dismiss')">
				{{ t('attendance', 'Rate the app') }}
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('attendance', 'Dismiss')"
				data-test="rating-request-dismiss"
				@click="emit('dismiss')">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { onMounted } from 'vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import StarIcon from 'vue-material-design-icons/StarOutline.vue'
import { useRatingRequest } from '../../composables/useRatingRequest.js'

const emit = defineEmits(['dismiss'])

// The "Rate app" button sits under this heading, for signed-in visitors only.
const RATING_URL = 'https://apps.nextcloud.com/apps/attendance#comments'

const { markShown } = useRatingRequest()

onMounted(markShown)
</script>

<style scoped>
.rating-request {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	flex-wrap: wrap;
	text-align: start;
}

.rating-request__icon {
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.rating-request__text {
	flex: 1 1 240px;
	margin: 0;
	color: var(--color-main-text);
}

.rating-request__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-inline-start: auto;
}
</style>
