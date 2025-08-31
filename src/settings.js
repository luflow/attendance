/**
 * Admin settings Vue app entry point
 */

import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import AdminSettings from './views/AdminSettings.vue'

Vue.mixin({ methods: { t, n } })

const View = Vue.extend(AdminSettings)
new View().$mount('#attendance-admin-settings-vue')
