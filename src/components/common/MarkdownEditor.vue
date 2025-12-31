<template>
	<div class="markdown-editor" :class="{ 'markdown-editor--focused': isFocused }">
		<label v-if="label" class="markdown-editor__label">{{ label }}</label>
		<div ref="editorContainer" class="markdown-editor__container" />
	</div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import EasyMDE from 'easymde'
import 'easymde/dist/easymde.min.css'

const props = defineProps({
	modelValue: {
		type: String,
		default: '',
	},
	label: {
		type: String,
		default: '',
	},
	placeholder: {
		type: String,
		default: '',
	},
	minHeight: {
		type: String,
		default: '150px',
	},
})

const emit = defineEmits(['update:modelValue'])

const editorContainer = ref(null)
const isFocused = ref(false)
let editor = null
let isUpdatingFromProp = false

const onFocusIn = () => {
	isFocused.value = true
}

const onFocusOut = (e) => {
	const container = editorContainer.value
	if (!container) {
		isFocused.value = false
		return
	}

	const next = e.relatedTarget
	if (next && container.contains(next)) {
		return
	}

	isFocused.value = false
}

onMounted(() => {
	const textarea = document.createElement('textarea')
	editorContainer.value.appendChild(textarea)

	// Track focus state for styling (covers CodeMirror + toolbar interactions)
	editorContainer.value.addEventListener('focusin', onFocusIn)
	editorContainer.value.addEventListener('focusout', onFocusOut)

	// Icon paths from vue-material-design-icons (same as Nextcloud uses)
	const iconPaths = {
		bold: 'M13.5,15.5H10V12.5H13.5A1.5,1.5 0 0,1 15,14A1.5,1.5 0 0,1 13.5,15.5M10,6.5H13A1.5,1.5 0 0,1 14.5,8A1.5,1.5 0 0,1 13,9.5H10M15.6,10.79C16.57,10.11 17.25,9 17.25,8C17.25,5.74 15.5,4 13.25,4H7V18H14.04C16.14,18 17.75,16.3 17.75,14.21C17.75,12.69 16.89,11.39 15.6,10.79Z',
		italic: 'M10,4V7H12.21L8.79,15H6V18H14V15H11.79L15.21,7H18V4H10Z',
		strikethrough: 'M3,14H21V12H3M5,4V7H10V10H14V7H19V4M10,19H14V16H10V19Z',
		bulletList: 'M7,5H21V7H7V5M7,13V11H21V13H7M4,4.5A1.5,1.5 0 0,1 5.5,6A1.5,1.5 0 0,1 4,7.5A1.5,1.5 0 0,1 2.5,6A1.5,1.5 0 0,1 4,4.5M4,10.5A1.5,1.5 0 0,1 5.5,12A1.5,1.5 0 0,1 4,13.5A1.5,1.5 0 0,1 2.5,12A1.5,1.5 0 0,1 4,10.5M7,19V17H21V19H7M4,16.5A1.5,1.5 0 0,1 5.5,18A1.5,1.5 0 0,1 4,19.5A1.5,1.5 0 0,1 2.5,18A1.5,1.5 0 0,1 4,16.5Z',
		numberedList: 'M7,13V11H21V13H7M7,19V17H21V19H7M7,7V5H21V7H7M3,8V5H2V4H4V8H3M2,17V16H5V20H2V19H4V18.5H3V17.5H4V17H2M4.25,10A0.75,0.75 0 0,1 5,10.75C5,10.95 4.92,11.14 4.79,11.27L3.12,13H5V14H2V13.08L4,11H2V10H4.25Z',
		link: 'M3.9,12C3.9,10.29 5.29,8.9 7,8.9H11V7H7A5,5 0 0,0 2,12A5,5 0 0,0 7,17H11V15.1H7C5.29,15.1 3.9,13.71 3.9,12M8,13H16V11H8V13M17,7H13V8.9H17C18.71,8.9 20.1,10.29 20.1,12C20.1,13.71 18.71,15.1 17,15.1H13V17H17A5,5 0 0,0 22,12A5,5 0 0,0 17,7Z',
		quote: 'M14,17H17L19,13V7H13V13H16M6,17H9L11,13V7H5V13H8L6,17Z',
	}

	editor = new EasyMDE({
		element: textarea,
		initialValue: props.modelValue,
		placeholder: props.placeholder,
		spellChecker: false,
		nativeSpellcheck: true,
		status: false,
		minHeight: props.minHeight,
		toolbar: [
			{ name: 'bold', action: EasyMDE.toggleBold, className: 'mde-btn-bold', title: t('attendance', 'Bold') + ' (Ctrl+B)' },
			{ name: 'italic', action: EasyMDE.toggleItalic, className: 'mde-btn-italic', title: t('attendance', 'Italic') + ' (Ctrl+I)' },
			{ name: 'strikethrough', action: EasyMDE.toggleStrikethrough, className: 'mde-btn-strikethrough', title: t('attendance', 'Strikethrough') },
			'|',
			{ name: 'unordered-list', action: EasyMDE.toggleUnorderedList, className: 'mde-btn-bullet', title: t('attendance', 'Bullet list') },
			{ name: 'ordered-list', action: EasyMDE.toggleOrderedList, className: 'mde-btn-numbered', title: t('attendance', 'Numbered list') },
			{ name: 'quote', action: EasyMDE.toggleBlockquote, className: 'mde-btn-quote', title: t('attendance', 'Quote') },
			'|',
			{ name: 'link', action: EasyMDE.drawLink, className: 'mde-btn-link', title: t('attendance', 'Insert link') + ' (Ctrl+K)' },
		],
		autosave: { enabled: false },
		uploadImage: false,
		shortcuts: { toggleSideBySide: null, toggleFullScreen: null },
	})

	// Inject SVG icons into toolbar buttons after editor is created
	const injectIcon = (className, path) => {
		const btn = editorContainer.value.querySelector(`.${className}`)
		if (btn) {
			btn.innerHTML = `<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="${path}"/></svg>`
		}
	}

	injectIcon('mde-btn-bold', iconPaths.bold)
	injectIcon('mde-btn-italic', iconPaths.italic)
	injectIcon('mde-btn-strikethrough', iconPaths.strikethrough)
	injectIcon('mde-btn-bullet', iconPaths.bulletList)
	injectIcon('mde-btn-numbered', iconPaths.numberedList)
	injectIcon('mde-btn-quote', iconPaths.quote)
	injectIcon('mde-btn-link', iconPaths.link)

	// Listen for changes
	editor.codemirror.on('change', () => {
		if (!isUpdatingFromProp) {
			emit('update:modelValue', editor.value())
		}
	})

	// Track focus state for styling
	editor.codemirror.on('focus', () => {
		isFocused.value = true
	})

	editor.codemirror.on('blur', () => {
		isFocused.value = false
	})
})

// Watch for external value changes
watch(() => props.modelValue, (newValue) => {
	if (editor && editor.value() !== newValue) {
		isUpdatingFromProp = true
		const cursor = editor.codemirror.getCursor()
		editor.value(newValue || '')
		editor.codemirror.setCursor(cursor)
		isUpdatingFromProp = false
	}
})

onBeforeUnmount(() => {
	if (editor) {
		editor.toTextArea()
		editor = null
	}

	if (editorContainer.value) {
		editorContainer.value.removeEventListener('focusin', onFocusIn)
		editorContainer.value.removeEventListener('focusout', onFocusOut)
	}
})
</script>

<style lang="scss">
.markdown-editor.markdown-editor--focused {
	.EasyMDEContainer {
		border-color: var(--color-main-text);
	}
}

.markdown-editor {
	width: 100%;

	&__label {
		display: block;
		font-weight: 600;
		font-size: 14px;
		color: var(--color-main-text);
		margin-bottom: 4px;
	}

	&__container {
		border-radius: var(--border-radius-large);
		overflow: hidden;
	}

	// EasyMDE overrides to match Nextcloud style
	.EasyMDEContainer {
		border: 1px solid var(--color-border-maxcontrast);
		border-radius: var(--border-radius-large);

		.CodeMirror {
			border: none;
			border-radius: 0 0 var(--border-radius-large) var(--border-radius-large);
			background-color: var(--color-main-background);
			color: var(--color-main-text);
			font-family: var(--font-face);
			padding: 4px;

			&-cursor {
				border-left-color: var(--color-main-text);
			}

			&-selected {
				background: var(--color-primary-element-light) !important;
			}
		}

		.CodeMirror-placeholder {
			color: var(--color-text-maxcontrast);
		}

		.editor-toolbar {
			border: none;
			border-bottom: 1px solid var(--color-border);
			background-color: var(--color-background-hover);
			border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
			padding: 4px 6px;

			&::before,
			&::after {
				display: none;
			}

			button {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 32px;
				height: 32px;
				border-radius: var(--border-radius) !important;
				background: transparent !important;
				cursor: pointer;
				color: var(--color-main-text);

				svg {
					width: 20px;
					height: 20px;
					display: block;
				}

				&:hover {
					background-color: var(--color-background-dark) !important;
				}

				&.active {
					background-color: var(--color-primary-element-light) !important;
				}
			}

			i.separator {
				display: inline-block;
				width: 1px;
				height: 20px;
				margin: 6px 6px !important;
				background-color: var(--color-border);
				border: none;
			}
		}

		// Preview mode styling
		.editor-preview {
			background-color: var(--color-main-background);
			color: var(--color-main-text);
			padding: 12px;

			h1, h2, h3, h4, h5, h6 {
				color: var(--color-main-text);
				margin: 15px 0 10px 0;
				font-weight: 600;
			}

			h1 { font-size: 1.5em; }
			h2 { font-size: 1.3em; }
			h3 { font-size: 1.15em; }

			a {
				color: var(--color-primary-element);
			}

			code {
				background-color: var(--color-background-dark);
				padding: 2px 6px;
				border-radius: var(--border-radius-small);
				font-family: monospace;
			}

			pre {
				background-color: var(--color-background-dark);
				padding: 12px;
				border-radius: var(--border-radius);
				overflow-x: auto;

				code {
					background: none;
					padding: 0;
				}
			}

			blockquote {
				border-left: 3px solid var(--color-primary-element);
				margin: 10px 0;
				padding-left: 15px;
				color: var(--color-text-maxcontrast);
			}

			ul, ol {
				margin: 10px 0;
				padding-left: 25px;
			}

			li {
				margin: 5px 0;
			}

			hr {
				border: none;
				border-top: 1px solid var(--color-border);
				margin: 15px 0;
			}

			table {
				border-collapse: collapse;
				width: 100%;
				margin: 10px 0;
			}

			th, td {
				border: 1px solid var(--color-border);
				padding: 8px 12px;
				text-align: left;
			}

			th {
				background-color: var(--color-background-dark);
				font-weight: 600;
			}

			// Task lists
			.task-list-item {
				list-style-type: none;
				margin-left: -20px;

				input[type="checkbox"] {
					margin-right: 8px;
				}
			}
		}
	}
}
</style>
