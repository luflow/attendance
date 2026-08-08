<template>
	<div class="statistics-charts">
		<section v-if="timelineData" class="statistics-charts__chart">
			<h3>{{ t("attendance", "Over time") }}</h3>
			<div class="statistics-charts__canvas">
				<Line :data="timelineData" :options="lineOptions" />
			</div>
		</section>

		<section v-if="sectionData" class="statistics-charts__chart">
			<h3>
				{{
					groupBy === "teams"
						? t("attendance", "By team")
						: t("attendance", "By group")
				}}
			</h3>
			<div class="statistics-charts__canvas">
				<Bar :data="sectionData" :options="barOptions" />
			</div>
		</section>

		<section v-if="categoryData" class="statistics-charts__chart">
			<h3>{{ t("attendance", "By category") }}</h3>
			<div class="statistics-charts__canvas">
				<Bar :data="categoryData" :options="barOptions" />
			</div>
		</section>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import {
	BarController,
	BarElement,
	CategoryScale,
	Chart,
	Legend,
	LinearScale,
	LineController,
	LineElement,
	PointElement,
	Tooltip,
} from 'chart.js'
import { computed } from 'vue'
import { Bar, Line } from 'vue-chartjs'
import { useChartTheme, withAlpha } from '../../composables/useChartTheme.js'

const props = defineProps({
	statistics: { type: Object, required: true },
	groupBy: { type: String, default: 'groups' },
})

// Only what these three charts draw — registering chart.js' full `registerables`
// would pull in every controller, scale and plugin it ships with.
Chart.register(
	BarController,
	BarElement,
	LineController,
	LineElement,
	PointElement,
	CategoryScale,
	LinearScale,
	Tooltip,
	Legend,
)

const { colors } = useChartTheme()

const percent = (rate) => (rate === null || rate === undefined ? null : Math.round(rate * 1000) / 10)

const timelineData = computed(() => {
	const points = props.statistics.timeline ?? []
	if (points.length === 0) {
		return null
	}

	return {
		labels: points.map((point) => formatDate(point.startDatetime)),
		datasets: [
			{
				label: t('attendance', 'Acceptance rate'),
				data: points.map((point) => percent(rate(point.yes, point.targetCount))),
				borderColor: colors.accent,
				backgroundColor: withAlpha(colors.accent, 0.2),
				tension: 0.3,
			},
			{
				label: t('attendance', 'Attendance rate'),
				data: points.map((point) => (point.attendanceRecorded
					? percent(rate(point.present, point.targetCount))
					: null)),
				borderColor: colors.success,
				backgroundColor: withAlpha(colors.success, 0.2),
				tension: 0.3,
				spanGaps: false,
			},
		],
	}
})

const sectionData = computed(() => buildBars(props.statistics.sections ?? []))
const categoryData = computed(() => buildBars(props.statistics.byCategory ?? []))

const lineOptions = computed(() => baseOptions())
const barOptions = computed(() => baseOptions())

/**
 * @param {number} part - Numerator.
 * @param {number} total - Denominator.
 * @return {?number} The ratio, or null without a basis.
 */
function rate(part, total) {
	return total > 0 ? part / total : null
}

/**
 * @param {?string} value - An ISO timestamp.
 * @return {string} Short local date.
 */
function formatDate(value) {
	if (!value) {
		return ''
	}
	return new Date(value).toLocaleDateString(undefined, { day: '2-digit', month: '2-digit', year: '2-digit' })
}

/**
 * @param {Array<object>} entries - Sections or categories carrying rates.
 * @return {?object} chart.js data, or null when there is nothing to draw.
 */
function buildBars(entries) {
	if (entries.length === 0) {
		return null
	}

	return {
		labels: entries.map((entry) => entry.displayName),
		datasets: [
			{
				label: t('attendance', 'Acceptance rate'),
				data: entries.map((entry) => percent(entry.acceptRate)),
				backgroundColor: colors.accent,
			},
			{
				label: t('attendance', 'Attendance rate'),
				data: entries.map((entry) => percent(entry.attendanceRate)),
				backgroundColor: colors.success,
			},
		],
	}
}

/**
 * @return {object} chart.js options in the current theme.
 */
function baseOptions() {
	return {
		responsive: true,
		maintainAspectRatio: false,
		interaction: { intersect: false, mode: 'index' },
		plugins: {
			legend: { labels: { color: colors.text } },
			tooltip: {
				callbacks: {
					label: (context) => `${context.dataset.label}: ${context.parsed.y ?? '–'} %`,
				},
			},
		},
		scales: {
			x: {
				ticks: { color: colors.text, autoSkip: true, maxRotation: 0 },
				grid: { color: withAlpha(colors.border, 0.5) },
			},
			y: {
				min: 0,
				max: 100,
				ticks: { color: colors.text, callback: (value) => `${value} %` },
				grid: { color: withAlpha(colors.border, 0.5) },
			},
		},
	}
}
</script>

<style scoped>
.statistics-charts {
    display: grid;
    gap: 24px;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    margin-bottom: 24px;
}

.statistics-charts__chart h3 {
    font-size: 1rem;
    margin-bottom: 8px;
}

.statistics-charts__canvas {
    background-color: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    height: 260px;
    padding: 12px;
}
</style>
