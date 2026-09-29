import { computed, ref } from 'vue'

/**
 * The per-appointment columns an export run writes, plus the organizer row.
 *
 * Held as one object because the four flags travel together everywhere: into
 * ExportColumnOptions, into the export request (the keys are already the wire
 * names), and back to their defaults when a dialog closes. The defaults are
 * what the export produced before the columns became switchable, so a caller
 * that never touches them gets the same file as before.
 *
 * @return {{columns: import('vue').Ref<object>, hasAnyColumn: import('vue').ComputedRef<boolean>, resetColumns: () => void}}
 */
export function useExportColumns() {
	const columns = ref(defaultColumns())

	// All three off would produce a sheet of names against empty header groups,
	// which the server rejects — both dialogs disable their export button on it.
	const hasAnyColumn = computed(() => columns.value.includeRsvp || columns.value.includeCheckin || columns.value.includeComments)

	function resetColumns() {
		columns.value = defaultColumns()
	}

	return { columns, hasAnyColumn, resetColumns }
}

/**
 * @return {object} A fresh set of column flags at their defaults.
 */
function defaultColumns() {
	return {
		includeRsvp: true,
		includeCheckin: true,
		includeComments: false,
		includeOrganizers: false,
	}
}
