import { onBeforeUnmount, onMounted, reactive } from 'vue'

const VARIABLES = {
	accent: '--color-primary-element',
	success: '--color-success',
	text: '--color-main-text',
	border: '--color-border',
	background: '--color-main-background',
}

const FALLBACKS = {
	accent: '#0082c9',
	success: '#2d7b41',
	text: '#222222',
	border: '#dbdbdb',
	background: '#ffffff',
}

/**
 * Nextcloud theme colours for chart.js, kept in sync with the running theme.
 *
 * chart.js bakes colours into its config at draw time and knows nothing about
 * CSS variables, so switching to dark mode would otherwise leave the charts in
 * the colours of the theme they were first drawn in.
 *
 * @return {{colors: object}} Reactive colour set.
 */
export function useChartTheme() {
	const colors = reactive({ ...FALLBACKS })

	const read = () => {
		const styles = getComputedStyle(document.documentElement)
		for (const [name, variable] of Object.entries(VARIABLES)) {
			colors[name] = styles.getPropertyValue(variable).trim() || FALLBACKS[name]
		}
	}

	const media = window.matchMedia('(prefers-color-scheme: dark)')
	const observer = new MutationObserver(read)

	onMounted(() => {
		read()
		media.addEventListener('change', read)
		observer.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme'] })
	})

	onBeforeUnmount(() => {
		media.removeEventListener('change', read)
		observer.disconnect()
	})

	return { colors }
}

/**
 * @param {string} color - A CSS colour value.
 * @param {number} alpha - Opacity between 0 and 1.
 * @return {string} The colour with the given opacity, or the input when it cannot be parsed.
 */
export function withAlpha(color, alpha) {
	const hex = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color.trim())
	if (!hex) {
		return color
	}
	const [red, green, blue] = hex.slice(1).map((part) => parseInt(part, 16))
	return `rgba(${red}, ${green}, ${blue}, ${alpha})`
}
