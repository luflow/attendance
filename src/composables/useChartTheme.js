import { onBeforeUnmount, onMounted, reactive } from 'vue'

const VARIABLES = {
	accent: '--color-primary-element',
	text: '--color-main-text',
	border: '--color-border',
}

// Only reached if a variable resolves to nothing, which a themed Nextcloud
// never does. `accent` is Nextcloud's own default primary.
const FALLBACKS = {
	accent: '#0082c9',
	text: '#222222',
	border: '#dbdbdb',
}

// A full-strength complement beside the accent reads as a warning rather than
// as a second series, so it keeps the opposite hue but neither its intensity
// nor an extreme lightness — the latter also keeps it legible in both themes.
const COMPLEMENT_SATURATION = 0.45
const COMPLEMENT_LIGHTNESS = 0.45

/**
 * Theme colours for chart.js, kept in sync with the running theme.
 *
 * chart.js bakes colours into its config at draw time and knows nothing about
 * CSS variables, so switching to dark mode would otherwise leave the charts in
 * the colours of the theme they were first drawn in.
 *
 * @return {{colors: object}} Reactive colour set. Besides the theme's own
 *   colours it carries `present`, the second series' colour.
 */
export function useChartTheme() {
	const colors = reactive({ ...FALLBACKS, present: complementOf(FALLBACKS.accent) })

	const read = () => {
		const styles = getComputedStyle(document.documentElement)
		for (const [name, variable] of Object.entries(VARIABLES)) {
			colors[name] = styles.getPropertyValue(variable).trim() || FALLBACKS[name]
		}
		colors.present = complementOf(colors.accent)
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
	const rgb = parseHex(color)
	if (!rgb) {
		return color
	}
	const [red, green, blue] = rgb
	return `rgba(${red}, ${green}, ${blue}, ${alpha})`
}

/**
 * The second series' colour, opposite the accent on the colour wheel.
 *
 * An instance whose primary is green would otherwise be drawing two green
 * series, so this is derived from the accent rather than fixed.
 *
 * @param {string} accent - The theme's primary colour.
 * @return {string} A hex colour.
 */
export function complementOf(accent) {
	const hue = hueOf(accent) ?? hueOf(FALLBACKS.accent)
	return hslToHex((hue + 180) % 360, COMPLEMENT_SATURATION, COMPLEMENT_LIGHTNESS)
}

/**
 * @param {string} color - A CSS colour value.
 * @return {?Array<number>} Its channels 0-255, or null when it is not `#rrggbb`.
 */
function parseHex(color) {
	const hex = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color.trim())
	return hex ? hex.slice(1).map((part) => parseInt(part, 16)) : null
}

/**
 * @param {string} color - A CSS colour value.
 * @return {?number} Its hue in degrees, or null when it cannot be parsed.
 */
function hueOf(color) {
	const rgb = parseHex(color)
	if (!rgb) {
		return null
	}

	const [red, green, blue] = rgb.map((part) => part / 255)
	const max = Math.max(red, green, blue)
	const span = max - Math.min(red, green, blue)
	// Grey has no hue of its own; treating it as red gives a teal complement,
	// which is as distinguishable as any other choice would be.
	if (span === 0) {
		return 0
	}
	if (max === red) {
		return (60 * ((green - blue) / span) + 360) % 360
	}
	if (max === green) {
		return 60 * ((blue - red) / span) + 120
	}
	return 60 * ((red - green) / span) + 240
}

/**
 * @param {number} hue - Degrees on the colour wheel.
 * @param {number} saturation - Between 0 and 1.
 * @param {number} lightness - Between 0 and 1.
 * @return {string} The colour as `#rrggbb`.
 */
function hslToHex(hue, saturation, lightness) {
	const chroma = saturation * Math.min(lightness, 1 - lightness)
	const channel = (offset) => {
		const position = (offset + hue / 30) % 12
		const value = lightness - chroma * Math.max(-1, Math.min(position - 3, 9 - position, 1))
		return Math.round(255 * value).toString(16).padStart(2, '0')
	}
	return `#${channel(0)}${channel(8)}${channel(4)}`
}
