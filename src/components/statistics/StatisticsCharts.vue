<template>
	<div class="statistics-charts">
		<section v-if="timelineData" class="statistics-charts__chart">
			<h3>{{ t("attendance", "Over time") }}</h3>
			<div class="statistics-charts__canvas">
				<Line :data="timelineData" :options="timelineOptions" />
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
				<Bar :data="sectionData" :options="sectionOptions" />
			</div>
		</section>

		<section v-if="categoryData" class="statistics-charts__chart">
			<h3>{{ t("attendance", "By category") }}</h3>
			<div class="statistics-charts__canvas">
				<Bar :data="categoryData" :options="categoryOptions" />
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
import { formatDate } from '../../utils/datetime.js'

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

// Long group names have to give way to their neighbours; the full name stays in
// the tooltip, which reads the data label rather than the tick.
const TICK_MAX_CHARS = 16

const LINE_STYLE = { tension: 0.3, borderWidth: 2, pointRadius: 3, pointHoverRadius: 5 }
const BAR_STYLE = { borderRadius: 4, borderSkipped: false, maxBarThickness: 48, categoryPercentage: 0.7 }

const percent = (rate) => (rate === null || rate === undefined ? null : Math.round(rate * 1000) / 10)

const timelineData = computed(() => {
	const points = props.statistics.timeline ?? []
	if (points.length === 0) {
		return null
	}

	return {
		labels: points.map((point) => formatDate(point.startDatetime, 'short')),
		datasets: [
			{
				label: t('attendance', 'Acceptance rate'),
				data: points.map((point) => percent(rate(point.yes, point.targetCount))),
				...LINE_STYLE,
				borderColor: colors.accent,
				backgroundColor: colors.accent,
			},
			{
				label: t('attendance', 'Attendance rate'),
				data: points.map((point) => (point.attendanceRecorded
					? percent(rate(point.present, point.targetCount))
					: null)),
				...LINE_STYLE,
				borderColor: colors.present,
				backgroundColor: colors.present,
				spanGaps: false,
			},
		],
	}
})

const sectionData = computed(() => buildBars(props.statistics.sections ?? []))
const categoryData = computed(() => buildBars(props.statistics.byCategory ?? []))

const timelineOptions = computed(() => baseOptions())
const sectionOptions = computed(() => baseOptions(sectionData.value?.labels))
const categoryOptions = computed(() => baseOptions(categoryData.value?.labels))

/**
 * @param {number} part - Numerator.
 * @param {number} total - Denominator.
 * @return {?number} The ratio, or null without a basis.
 */
function rate(part, total) {
	return total > 0 ? part / total : null
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
				...BAR_STYLE,
				backgroundColor: colors.accent,
			},
			{
				label: t('attendance', 'Attendance rate'),
				data: entries.map((entry) => percent(entry.attendanceRate)),
				...BAR_STYLE,
				backgroundColor: colors.present,
			},
		],
	}
}

/**
 * @param {string} label - Full axis label.
 * @return {string} The label, shortened to fit one tick.
 */
function truncate(label) {
	return label.length > TICK_MAX_CHARS ? `${label.slice(0, TICK_MAX_CHARS - 1)}…` : label
}

/**
 * @param {Array<string>} [labels] - Bar labels; omitted for the date axis of the timeline.
 * @return {object} chart.js options in the current theme.
 */
function baseOptions(labels = null) {
	return {
		responsive: true,
		maintainAspectRatio: false,
		interaction: { intersect: false, mode: 'index' },
		layout: { padding: { top: 4, right: 4 } },
		plugins: {
			legend: {
				// chart.js centres over the whole canvas, and the y-axis labels
				// are part of that — centred reads as shifted left of the plot.
				align: 'end',
				labels: {
					color: colors.text,
					boxHeight: 12,
					boxWidth: 12,
					padding: 16,
					pointStyle: 'rectRounded',
					usePointStyle: true,
				},
			},
			tooltip: {
				callbacks: {
					label: (context) => `${context.dataset.label}: ${context.parsed.y ?? '–'} %`,
				},
			},
		},
		scales: {
			x: {
				// chart.js' autoSkip drops labels that do not fit, leaving bars
				// nobody can name — so the bar charts shorten instead.
				ticks: labels === null
					? { color: colors.text, autoSkip: true, maxRotation: 0 }
					: { color: colors.text, autoSkip: false, callback: (value) => truncate(labels[value] ?? '') },
				grid: { display: false },
				border: { color: withAlpha(colors.border, 0.5) },
			},
			y: {
				min: 0,
				max: 100,
				ticks: { color: colors.text, padding: 4, stepSize: 25, callback: (value) => `${value} %` },
				grid: { color: withAlpha(colors.border, 0.5) },
				border: { display: false },
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
    margin-bottom: 12px;
}

.statistics-charts__canvas {
    background-color: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    /* Room for the legend, the axis labels and a plot area that still has a
       readable shape once the other two have taken their share. */
    height: 280px;
    padding: 12px 16px;
}
</style>
