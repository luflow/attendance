import { marked } from 'marked'

// Configure marked for inline rendering (no paragraphs)
marked.setOptions({
	breaks: true,
	gfm: true,
})

/**
 * Render markdown text to HTML
 * @param {string} text - The markdown text to render
 * @param {boolean} removeNewlines - Whether to remove newlines for compact display
 * @returns {string} - The rendered HTML
 */
export function renderMarkdown(text, removeNewlines = false) {
	if (!text) return ''
	
	// Parse markdown
	let html = marked.parseInline(text)
	
	// Remove newlines if requested (for widget compact display)
	if (removeNewlines) {
		html = html.replace(/\n/g, ' ')
	}
	
	return html
}

/**
 * Sanitize markdown output (basic XSS protection)
 * @param {string} html - The HTML string to sanitize
 * @returns {string} - Sanitized HTML
 */
export function sanitizeHtml(html) {
	// Remove script tags and on* attributes
	return html
		.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
		.replace(/\son\w+\s*=\s*["'][^"']*["']/gi, '')
}
