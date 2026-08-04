/**
 * Shared NcPopover trigger config for hover/focus tooltips on small icon
 * badges. popperTriggers keeps the tooltip open while the pointer moves onto
 * it — without it a tooltip with a link closes before the pointer reaches it.
 */
export const HOVER_TOOLTIP = {
	triggers: ['hover', 'focus'],
	popperTriggers: ['hover'],
	delay: { show: 100, hide: 300 },
	popupRole: 'tooltip',
}
