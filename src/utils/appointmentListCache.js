// Each list view's last appointments, so that coming back renders at once (and
// can restore the scroll position) while the fresh copy loads.
export const appointmentListCache = new Map()
