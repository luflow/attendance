import { marked } from 'marked'
import DOMPurify from 'dompurify'

// Configure marked for full markdown rendering
marked.setOptions({
	breaks: true,
	gfm: true,
})

/**
 * Render markdown text to HTML (full block-level support)
 * Supports: headers, lists, blockquotes, code blocks, tables, etc.
 * @param {string} text - The markdown text to render
 * @param {boolean} inline - Whether to use inline-only rendering (no block elements)
 * @return {string} - The rendered HTML
 */
export function renderMarkdown(text, inline = false) {
	if (!text) return ''

	// Use inline parsing for compact display, full parsing for detailed view
	if (inline) {
		return marked.parseInline(text)
	}

	// Full markdown parsing with block-level elements
	return marked.parse(text)
}

/**
 * Sanitize HTML output using DOMPurify
 * @param {string} html - The HTML string to sanitize
 * @return {string} - Sanitized HTML safe for v-html rendering
 */
export function sanitizeHtml(html) {
	return DOMPurify.sanitize(html)
}

/**
 * Strip all markdown formatting from text, returning plain text only
 * @param {string} text - The markdown text to strip
 * @return {string} - Plain text without any markdown formatting
 */
export function stripMarkdown(text) {
	if (!text) return ''

	return text
		// Remove code blocks (must be done first to avoid processing their contents)
		.replace(/```[\s\S]*?```/g, '')
		// Remove inline code
		.replace(/`([^`]+)`/g, '$1')
		// Remove images ![alt](url)
		.replace(/!\[([^\]]*)\]\([^)]+\)/g, '$1')
		// Remove links [text](url) - keep the text
		.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
		// Remove reference-style links [text][ref]
		.replace(/\[([^\]]+)\]\[[^\]]*\]/g, '$1')
		// Remove headers (# ## ### etc)
		.replace(/^#{1,6}\s+/gm, '')
		// Remove bold/italic (order matters: bold first)
		.replace(/\*\*\*([^*]+)\*\*\*/g, '$1') // Bold+italic
		.replace(/___([^_]+)___/g, '$1') // Bold+italic alt
		.replace(/\*\*([^*]+)\*\*/g, '$1') // Bold
		.replace(/__([^_]+)__/g, '$1') // Bold alt
		.replace(/\*([^*]+)\*/g, '$1') // Italic
		.replace(/_([^_]+)_/g, '$1') // Italic alt
		// Remove strikethrough
		.replace(/~~([^~]+)~~/g, '$1')
		// Remove blockquotes
		.replace(/^>\s*/gm, '')
		// Remove horizontal rules
		.replace(/^[-*_]{3,}\s*$/gm, '')
		// Remove unordered list markers
		.replace(/^[\s]*[-*+]\s+/gm, '')
		// Remove ordered list markers
		.replace(/^[\s]*\d+\.\s+/gm, '')
		// Remove task list markers
		.replace(/\[[ xX]\]\s*/g, '')
		// Remove HTML tags (basic)
		.replace(/<[^>]+>/g, '')
		// Normalize whitespace (multiple spaces/newlines to single space)
		.replace(/\s+/g, ' ')
		// Trim
		.trim()
}
