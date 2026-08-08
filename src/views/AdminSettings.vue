<template>
	<div id="attendance-admin-settings" data-test="admin-settings">
		<NcSettingsSection :name="t('attendance', 'Attendance')"
			:description="t('attendance', 'Configure attendance management and reminders. Changes are saved automatically.')">
			<nav class="anchor-nav" :aria-label="t('attendance', 'Settings sections')">
				<a v-for="section in navSections"
					:key="section.id"
					:href="`#${section.id}`"
					class="anchor-nav__link"
					@click.prevent="scrollToSection(section.id)">
					{{ section.label }}
				</a>
			</nav>
		</NcSettingsSection>

		<LoadingState v-if="loadingData" :text="t('attendance', 'Loading settings …')" />

		<template v-else>
			<NcSettingsSection id="setup-wizard"
				:name="t('attendance', 'Setup wizard')"
				:description="t('attendance', 'A guided walk through the settings that matter most on a new installation: who may create appointments, who checks attendees in, who sees the replies, and how reminders work.')">
				<NcButton variant="primary"
					data-test="button-start-onboarding"
					@click="showOnboardingWizard = true">
					<template #icon>
						<RocketLaunchIcon :size="20" />
					</template>
					{{ t('attendance', 'Start setup wizard') }}
				</NcButton>
			</NcSettingsSection>

			<!-- TRANSLATORS: Admin settings section title. The "Response summary" is the main feature of this app - it shows attendance statistics on the appointment detail page, counting users by their Nextcloud group membership. Groups selected here will have their own sections in the summary; users not in these groups appear under "Others". -->
			<NcSettingsSection id="response-summary"
				:name="t('attendance', 'Response summary groups')"
				:description="t('attendance', 'Select which groups to include in response summaries. Users outside these groups will appear under Others. Leave empty to include all groups.')"
				data-test="section-tracking-groups">
				<GroupSelect
					v-model="selectedGroups"
					:options="availableGroups"
					:placeholder="t('attendance', 'Select groups …')"
					data-test="select-whitelisted-groups" />
				<p class="hint-text">
					{{ n('attendance', '%n group selected', '%n groups selected', selectedGroups.length, { n: selectedGroups.length }) }}
				</p>
			</NcSettingsSection>

			<!-- TRANSLATORS: Admin settings section title. Similar to groups above, but for Nextcloud Teams (formerly Circles). Teams selected here will have their own sections in the attendance statistics on the appointment detail page, showing how many team members responded yes/no/maybe. -->
			<NcSettingsSection v-if="teamsAvailable"
				:name="t('attendance', 'Response summary teams')"
				:description="t('attendance', 'Select which teams to include in response summaries. Team members will be grouped together like regular groups.')"
				data-test="section-tracking-teams">
				<NcSelect
					v-model="selectedTeams"
					:options="teamSearchResults"
					:placeholder="t('attendance', 'Search and select teams …')"
					:multiple="true"
					:loading="isSearchingTeams"
					:filterable="false"
					label="label"
					trackBy="id"
					data-test="select-whitelisted-teams"
					@search="searchTeams">
					<template #option="{ label }">
						<span style="display: flex; align-items: center; gap: 8px;">
							<AccountStar :size="20" />
							<span>{{ label }}</span>
						</span>
					</template>
					<template #selected-option="{ label }">
						<span style="display: flex; align-items: center; gap: 8px;">
							<AccountStar :size="16" />
							<span>{{ label }}</span>
						</span>
					</template>
				</NcSelect>
				<p class="hint-text">
					{{ n('attendance', '%n team selected', '%n teams selected', selectedTeams.length, { n: selectedTeams.length }) }}
				</p>
			</NcSettingsSection>

			<NcSettingsSection id="categories"
				:name="t('attendance', 'Categories')"
				:description="t('attendance', 'Define categories appointments can be classified under. Each one gets an icon, shown wherever the category appears.')"
				data-test="section-categories">
				<LoadingState v-if="categoriesLoading" :size="24" />
				<template v-else>
					<ul v-if="categories.length > 0" class="category-list">
						<li v-for="category in categories" :key="category.id" class="category-list__item">
							<template v-if="editingCategoryId === category.id">
								<CategoryIconPicker v-model="editingCategory.icon" data-test="input-edit-category-icon" />
								<NcInputField
									v-model="editingCategory.name"
									:label="categoryNameLabel"
									:labelOutside="true"
									:aria-label="categoryNameLabel"
									class="category-list__edit-field"
									@keydown.enter="saveEditingCategory"
									@keydown.escape="cancelEditingCategory" />
								<NcButton variant="primary"
									:disabled="!editingCategory.name.trim() || savingCategoryEdit"
									@click="saveEditingCategory">
									{{ t('attendance', 'Save') }}
								</NcButton>
								<NcButton variant="tertiary" @click="cancelEditingCategory">
									{{ t('attendance', 'Cancel') }}
								</NcButton>
							</template>
							<template v-else>
								<component :is="categoryIconComponent(category.icon)" :size="18" class="category-list__icon" />
								<span class="category-list__name">{{ category.name }}</span>
								<NcButton variant="tertiary"
									:aria-label="t('attendance', 'Rename category')"
									data-test="button-edit-category"
									@click="startEditingCategory(category)">
									<template #icon>
										<Pencil :size="18" />
									</template>
								</NcButton>
								<NcButton variant="tertiary"
									:aria-label="t('attendance', 'Delete category')"
									data-test="button-delete-category"
									@click="confirmDeleteCategory(category)">
									<template #icon>
										<TrashCan :size="18" />
									</template>
								</NcButton>
							</template>
						</li>
					</ul>
					<p v-else class="hint-text">
						{{ t('attendance', 'No categories yet.') }}
					</p>

					<div class="category-add">
						<CategoryIconPicker v-model="newCategory.icon" data-test="input-new-category-icon" />
						<NcInputField
							v-model="newCategory.name"
							:label="newCategoryNameLabel"
							:labelOutside="true"
							:aria-label="newCategoryNameLabel"
							:placeholder="t('attendance', 'e.g. Rehearsal')"
							class="category-add__name-field"
							data-test="input-new-category"
							@keydown.enter="createCategory" />
						<NcButton variant="primary"
							:disabled="!newCategory.name.trim() || creatingCategory"
							data-test="button-add-category"
							@click="createCategory">
							{{ t('attendance', 'Add category') }}
						</NcButton>
					</div>
				</template>
			</NcSettingsSection>

			<NcDialog v-if="categoryToDelete"
				:name="t('attendance', 'Delete category')"
				@closing="categoryToDelete = null">
				<p>
					{{ t('attendance', 'Do you want to delete the category "{name}"? Appointments using it keep their other data but lose the category.', { name: categoryToDelete.name }) }}
				</p>
				<template #actions>
					<NcButton variant="tertiary" @click="categoryToDelete = null">
						{{ t('attendance', 'Cancel') }}
					</NcButton>
					<NcButton variant="error" @click="deleteCategory">
						{{ t('attendance', 'Delete') }}
					</NcButton>
				</template>
			</NcDialog>

			<NcSettingsSection id="permissions"
				:name="t('attendance', 'Permissions')"
				:description="t('attendance', 'Control who can do what. Every permission is either open to all users, limited to specific groups, or granted to nobody.')">
				<div v-for="group in permissionGroups" :key="group.key" class="permission-group">
					<h4 class="permission-group__title">
						{{ group.label }}
					</h4>
					<PermissionRow v-for="name in group.names"
						:key="name"
						:modelValue="permissions[name]"
						:title="PERMISSION_ROWS[name].title"
						:hint="PERMISSION_ROWS[name].hint"
						:implication="PERMISSION_ROWS[name].implication"
						:implicationLink="implicationLinkFor(name)"
						:warningWhenAll="PERMISSION_ROWS[name].warningWhenAll"
						:options="availableGroups"
						:dataTest="`permission-${name}`"
						@navigate="scrollToSection"
						@update:modelValue="onPermissionChange(name, $event)" />
				</div>
			</NcSettingsSection>

			<NcSettingsSection id="self-checkin"
				:name="t('attendance', 'Self-check-in')"
				:description="t('attendance', 'Attendees check themselves in via QR code or NFC tag. One code works for all appointments — the app matches by time.')">
				<NcNoteCard type="info">
					<p>
						{{ t('attendance', 'Self-check-in only works with the Attendance mobile app.') }}
						<SectionLink sectionId="mobile-apps" :label="t('attendance', 'Go to the mobile apps section.')" @navigate="scrollToSection" />
					</p>
				</NcNoteCard>

				<!-- Wrapper div: scoped attrs don't reach the NcInputField root,
				     so the spacing lives on an own template element. -->
				<div class="self-checkin-window-field">
					<NcInputField
						v-model.number="selfCheckinWindowMinutes"
						type="number"
						:label="t('attendance', 'Check-in window (minutes before start)')"
						:helperText="t('attendance', 'How many minutes before an appointment starts attendees can check in. The window always closes when the appointment ends.')"
						data-test="input-self-checkin-window"
						:inputProps="{ min: 0, max: 1440 }" />
				</div>

				<div class="self-checkin-qr">
					<h6>{{ t('attendance', 'QR code') }}</h6>
					<p class="subsection-hint">
						{{ t('attendance', 'Print this QR code and put it up at the entrance.') }}
					</p>
					<img v-if="qrDataUrl"
						:src="qrDataUrl"
						:alt="t('attendance', 'Self-check-in QR code')"
						class="self-checkin-qr__image"
						data-test="self-checkin-qr">
					<div class="self-checkin-qr__actions">
						<NcButton variant="secondary" @click="downloadQrCode">
							<template #icon>
								<Download :size="20" />
							</template>
							{{ t('attendance', 'Download QR code') }}
						</NcButton>
					</div>

					<h6>{{ t('attendance', 'NFC tag') }}</h6>
					<p class="subsection-hint">
						{{ t('attendance', 'Write the check-in URL to NFC tags and stick them at the entrance.') }}
					</p>
					<div class="self-checkin-qr__actions">
						<NcButton variant="secondary" @click="copySelfCheckinUrl">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							{{ t('attendance', 'Copy URL for NFC tags') }}
						</NcButton>
					</div>
					<p class="hint-text">
						{{ t('attendance', 'NFC tag shopping advice: use NXP NTAG213 tags (or newer) of at least 25 mm, and on-metal tags for metal surfaces. Avoid MIFARE Classic tags — they do not work with iPhones.') }}
					</p>
					<p class="hint-text">
						<SectionLink sectionId="mobile-apps"
							:label="t('attendance', 'You can also write NFC tags directly with the Attendance mobile app.')"
							@navigate="scrollToSection" />
					</p>
				</div>
			</NcSettingsSection>

			<NcSettingsSection id="reminders"
				:name="t('attendance', 'Appointment reminders')"
				:description="reminderSectionDescription">
				<NcNoteCard v-if="!notificationsAppEnabled" type="warning">
					<p>{{ t('attendance', 'The Notifications app is not enabled. Please enable it to use appointment reminders.') }}</p>
					<p class="hint-text">
						{{ t('attendance', 'You can enable it in the Apps section of your Nextcloud settings.') }}
					</p>
				</NcNoteCard>

				<template v-else>
					<NcCheckboxRadioSwitch
						v-model="remindersEnabled"
						type="switch"
						data-test="switch-reminders-enabled">
						{{ t('attendance', 'Enable automatic reminders') }}
					</NcCheckboxRadioSwitch>

					<div v-if="remindersEnabled" class="reminder-config">
						<NcInputField
							v-model.number="reminderDays"
							type="number"
							:label="t('attendance', 'Days before appointment')"
							:helperText="t('attendance', 'Send reminders this many days before the appointment (1-30 days)')"
							data-test="input-reminder-days"
							:inputProps="{ min: 1, max: 30 }" />

						<NcInputField
							v-model.number="reminderFrequency"
							type="number"
							class="reminder-frequency-field"
							:label="t('attendance', 'Reminder frequency (days)')"
							:helperText="t('attendance', 'How often to remind users who haven\'t responded. Set to 0 to only remind once, or 1-30 to repeat reminders every N days.')"
							data-test="input-reminder-frequency"
							:inputProps="{ min: 0, max: 30 }" />

						<div class="reminder-target-section">
							<label class="reminder-target-label">
								{{ t('attendance', 'Remind recipients') }}
							</label>
							<NcCheckboxRadioSwitch v-for="target in REMINDER_TARGETS"
								:key="target.value"
								v-model="reminderTarget"
								type="radio"
								:value="target.value"
								name="reminder-target"
								:data-test="`radio-reminder-target-${target.value}`">
								{{ target.label }}
							</NcCheckboxRadioSwitch>
						</div>
					</div>

					<div v-if="remindersEnabled" class="reminder-preview" data-test="reminder-preview">
						<h4>{{ t('attendance', 'Next reminder run') }}</h4>
						<p v-if="nextReminderRun" class="reminder-preview-context">
							{{ t('attendance', 'Approximately {datetime}. The exact time depends on when the server background job runs.', { datetime: formatDateTimeMedium(nextReminderRun + 'Z') }) }}
						</p>
						<p v-else class="reminder-preview-context">
							{{ t('attendance', 'As soon as the background job has run for the first time, the next approximate run time will be displayed here.') }}
						</p>
						<h4>{{ t('attendance', 'Preview') }}</h4>
						<template v-if="nextAppointment">
							<p class="reminder-preview-context">
								{{ t('attendance', 'Based on your next appointment: {name} ({date})', {
									name: nextAppointment.name,
									date: formatDateTimeMedium(nextAppointment.startDatetime),
								}) }}
							</p>
							<template v-if="reminderPreviewDates.length > 0">
								<ul class="reminder-preview-list">
									<li v-for="(entry, index) in reminderPreviewDates" :key="index">
										<strong>{{ formatDate(entry.date) }}</strong>
										<span class="reminder-preview-label">
											{{ entry.daysBefore === 0
												? t('attendance', '(day of appointment)')
												: n('attendance', '({count} day before)', '({count} days before)', entry.daysBefore, { count: entry.daysBefore })
											}}
										</span>
									</li>
								</ul>
								<NcButton
									variant="tertiary"
									:disabled="sendingTestReminder"
									class="test-reminder-button"
									data-test="button-test-reminder"
									@click="sendTestReminder">
									<template #icon>
										<BellRingIcon :size="20" />
									</template>
									{{ t('attendance', 'Send test reminder to myself') }}
								</NcButton>
							</template>
							<p v-else class="reminder-preview-context">
								{{ t('attendance', 'The reminder window for this appointment has already passed.') }}
							</p>
						</template>
						<p v-else class="reminder-preview-context">
							{{ t('attendance', 'No upcoming appointments. The preview will appear when an appointment is scheduled.') }}
						</p>
					</div>
				</template>
			</NcSettingsSection>

			<NcSettingsSection id="calendar-sync"
				:name="t('attendance', 'Calendar sync')"
				:description="t('attendance', 'Keep appointments that were imported from a calendar in sync with their calendar event.')">
				<NcCheckboxRadioSwitch
					v-model="calendarSyncEnabled"
					type="switch"
					data-test="switch-calendar-sync-enabled">
					{{ t('attendance', 'Enable automatic calendar sync') }}
				</NcCheckboxRadioSwitch>
				<p class="hint-text">
					{{ t('attendance', 'This only affects appointments created with Import from calendar. When the calendar event changes, its title, description and date are updated on the appointment.') }}
				</p>
			</NcSettingsSection>

			<NcSettingsSection id="org-calendar"
				:name="t('attendance', 'Organization calendar')"
				:description="t('attendance', 'Automatically create and update events in a shared calendar for every appointment, so everyone can see them in the Calendar app.')">
				<NcNoteCard v-if="!calendarAvailable" type="warning">
					<p>{{ t('attendance', 'The Calendar app is not enabled. Enable it to use the organization calendar.') }}</p>
				</NcNoteCard>

				<template v-else>
					<NcCheckboxRadioSwitch
						v-model="orgCalendarEnabled"
						type="switch"
						data-test="switch-org-calendar-enabled">
						<!-- TRANSLATORS: Switch label for the organization calendar sync feature described below. Use the same word for a synced calendar entry here and in the two related strings "Creates or updates the calendar events for all upcoming appointments…" and "Events are created for all appointments…" further down — they describe the same feature and should read consistently. -->
						{{ t('attendance', 'Create calendar events for appointments') }}
					</NcCheckboxRadioSwitch>

					<div v-if="orgCalendarEnabled" class="subsection">
						<h4>{{ t('attendance', 'Target calendar') }}</h4>
						<NcNoteCard v-if="!orgCalendarOptions.length" type="warning">
							<p>{{ t('attendance', 'No writable calendar found. Create one in the Calendar app first.') }}</p>
						</NcNoteCard>
						<NcSelect v-else
							v-model="selectedOrgCalendar"
							:options="orgCalendarOptions"
							label="displayName"
							:placeholder="t('attendance', 'Select a calendar …')"
							data-test="select-org-calendar" />
						<p class="hint-text">
							{{ t('attendance', 'Share the selected calendar with your groups in the Calendar app so everyone can see the events.') }}
						</p>
						<p class="hint-text">
							{{ t('attendance', 'When you select a calendar, all upcoming appointments are transferred to it. Past appointments are not transferred.') }}
						</p>
						<p class="hint-text">
							<!-- TRANSLATORS: Same calendar-sync feature as "Create calendar events for appointments" above — use the same word for a synced calendar entry in both. -->
							{{ t('attendance', 'Events are created for all appointments, regardless of their visibility restrictions. Changing the target calendar does not move events that were already created.') }}
						</p>
						<p v-if="orgCalendarUserId" class="hint-text">
							{{ t('attendance', 'Events are written using the account of {user}.', { user: orgCalendarUserId }) }}
						</p>
						<NcButton
							v-if="selectedOrgCalendar"
							class="org-calendar-sync-button"
							variant="secondary"
							:disabled="syncingOrgCalendar"
							data-test="button-sync-org-calendar"
							@click="syncOrgCalendar">
							<template #icon>
								<NcLoadingIcon v-if="syncingOrgCalendar" :size="20" />
								<CalendarSyncIcon v-else :size="20" />
							</template>
							{{ t('attendance', 'Sync upcoming appointments now') }}
						</NcButton>
						<p class="hint-text">
							<!-- TRANSLATORS: Describes the manual "Sync upcoming appointments now" button above — same calendar-sync feature as "Create calendar events for appointments" further up, use the same word for a synced calendar entry. -->
							{{ t('attendance', 'Creates or updates the calendar events for all upcoming appointments. This also runs automatically when you enable the feature or change the calendar.') }}
						</p>
					</div>
				</template>
			</NcSettingsSection>

			<NcSettingsSection id="audit-log"
				:name="t('attendance', 'Audit log')"
				:description="t('attendance', 'Records who responded what and when, and surfaces a timeline on every appointment. Also drives the response-change push notifications that managers can opt into in their personal settings.')">
				<NcCheckboxRadioSwitch v-model="auditLogEnabled"
					type="switch"
					data-test="switch-audit-log-enabled">
					{{ t('attendance', 'Enable audit log') }}
				</NcCheckboxRadioSwitch>
				<p class="hint-text">
					{{ t('attendance', 'Disabling stops new events from being recorded and silences response notifications. Existing entries are kept and reappear once you re-enable.') }}
				</p>

				<template v-if="auditLogEnabled">
					<div class="subsection">
						<h4>{{ t('attendance', 'Who can see the audit log?') }}</h4>
						<NcCheckboxRadioSwitch v-for="visibility in AUDIT_VISIBILITIES"
							:key="visibility.value"
							v-model="auditLogVisibility"
							:value="visibility.value"
							name="audit_log_visibility"
							type="radio"
							:data-test="`radio-audit-visibility-${visibility.value}`">
							{{ visibility.label }}
						</NcCheckboxRadioSwitch>
					</div>
				</template>
			</NcSettingsSection>

			<!-- TRANSLATORS: Admin settings section title for the scheduling feature: managers give people who answered "yes" a place in the appointment ("schedule someone in"). German: the feature/noun is "Planung", the per-person action is "einplanen" and a scheduled person is "eingeplant" — not "planen"/"geplant". The description and the "Enable scheduling" switch below use the same meaning. -->
			<NcSettingsSection id="scheduling"
				:name="t('attendance', 'Scheduling')"
				:description="schedulingSectionDescription">
				<NcCheckboxRadioSwitch v-model="bookingEnabled"
					type="switch"
					data-test="switch-booking-enabled">
					<!-- TRANSLATORS: Switch label — turns the scheduling feature on (German: "Planung aktivieren"). -->
					{{ t('attendance', 'Enable scheduling') }}
				</NcCheckboxRadioSwitch>
			</NcSettingsSection>

			<NcSettingsSection id="display-options"
				:name="t('attendance', 'Display options')"
				:description="t('attendance', 'Choose how appointments are displayed across the app.')">
				<NcCheckboxRadioSwitch
					v-model="displayOrder"
					value="name_first"
					name="display_order"
					type="radio"
					data-test="radio-name-first">
					{{ t('attendance', 'Name first') }}
					<template #subtext>
						{{ t('attendance', 'Show appointment name prominently, with date below') }}
					</template>
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="displayOrder"
					value="date_first"
					name="display_order"
					type="radio"
					data-test="radio-date-first">
					{{ t('attendance', 'Date first') }}
					<template #subtext>
						{{ t('attendance', 'Show date and time prominently, with name below') }}
					</template>
				</NcCheckboxRadioSwitch>
			</NcSettingsSection>

			<NcSettingsSection id="mobile-apps"
				:name="t('attendance', 'Mobile apps')"
				:description="t('attendance', 'Share these links with your colleagues to install the Attendance mobile app.')">
				<div class="mobile-app-links">
					<div v-for="store in MOBILE_APP_STORES" :key="store.id" class="mobile-app-link">
						<label class="mobile-app-link__label">
							<component :is="store.icon" :size="20" />
							{{ store.longLabel }}
						</label>
						<div class="mobile-app-link__row">
							<code class="mobile-app-link__url" :data-test="`input-${store.id}-store-url`">{{ store.url }}</code>
							<NcButton variant="secondary"
								:aria-label="t('attendance', 'Copy URL')"
								:data-test="`button-copy-${store.id}-url`"
								@click="copyStoreUrl(store.url)">
								<template #icon>
									<ContentCopy :size="20" />
								</template>
								{{ t('attendance', 'Copy') }}
							</NcButton>
							<NcButton variant="tertiary"
								:href="store.url"
								target="_blank"
								rel="noopener"
								:data-test="`button-open-${store.id}-url`">
								<template #icon>
									<OpenInNew :size="20" />
								</template>
								<!-- TRANSLATORS: Verb (imperative) — button that opens the app-store link in a new browser tab. -->
								{{ t('attendance', 'Open') }}
							</NcButton>
						</div>
					</div>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'Mobile App promotion banner') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Show a banner at the top of the web app advertising the mobile apps. Users can dismiss the banner, and users who already have a push device connected will not see it.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-model="mobileAppBannerEnabled"
						type="switch"
						data-test="switch-mobile-app-banner-enabled">
						{{ t('attendance', 'Show promotion banner') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="subsection">
					<h4>{{ t('attendance', 'Push notifications') }}</h4>
					<p class="subsection-hint">
						{{ t('attendance', 'Enable push notifications for the mobile app.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-model="pushEnabled"
						type="switch"
						data-test="switch-push-enabled">
						{{ t('attendance', 'Enable push notifications') }}
					</NcCheckboxRadioSwitch>

					<template v-if="pushEnabled">
						<div class="push-device-status">
							<NcNoteCard v-if="pushDeviceCount === 0" type="warning">
								<p>{{ t('attendance', 'No push device registered for your account. Connect the Attendance mobile app to receive push notifications.') }}</p>
							</NcNoteCard>
							<template v-else>
								<p class="push-device-info">
									<CellphoneCheck :size="20" />
									{{ n('attendance', '{count} device registered for push notifications', '{count} devices registered for push notifications', pushDeviceCount, { count: pushDeviceCount }) }}
								</p>
								<NcButton
									variant="secondary"
									:disabled="sendingTestReminder"
									class="test-reminder-button"
									data-test="button-test-push"
									@click="sendTestReminder">
									<template #icon>
										<BellRingIcon :size="20" />
									</template>
									{{ t('attendance', 'Send test notification') }}
								</NcButton>
							</template>
						</div>
					</template>
				</div>
			</NcSettingsSection>

			<NcSettingsSection id="guests"
				:name="t('attendance', 'Guest invitation')"
				:description="t('attendance', 'Invite people without a Nextcloud account by integrating with the Nextcloud Guests app.')"
				data-test="section-guests-warning">
				<template v-if="guestsHintVariant === 'install'">
					<NcNoteCard type="info" data-test="guests-install-hint">
						{{ t('attendance', 'Want to invite guests? Install the Nextcloud Guests app — once enabled, organizers can create guest accounts directly from the appointment editor.') }}
					</NcNoteCard>
					<p class="guests-warning-actions">
						<NcButton variant="primary"
							:href="guestsAppStoreUrl"
							target="_blank"
							rel="noopener"
							data-test="button-open-guests-app-store">
							<template #icon>
								<OpenInNew :size="20" />
							</template>
							{{ t('attendance', 'Open in app store') }}
						</NcButton>
					</p>
				</template>
				<template v-else-if="guestsHintVariant === 'whitelist'">
					<NcNoteCard type="warning" data-test="guests-whitelist-warning">
						{{ t('attendance', 'The Guests app is enabled but Attendance is not in its allowed apps list. Invited guests will not see this app.') }}
					</NcNoteCard>
					<div class="guests-occ-row">
						<code class="guests-occ-row__command" data-test="input-guests-occ">{{ guestsWhitelistOccCommand }}</code>
						<NcButton variant="secondary"
							:aria-label="t('attendance', 'Copy occ command')"
							data-test="button-copy-guests-occ"
							@click="copyGuestsOccCommand">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							{{ t('attendance', 'Copy') }}
						</NcButton>
					</div>
					<p class="guests-warning-or">
						{{ t('attendance', 'or') }}
					</p>
					<p class="guests-warning-actions">
						<NcButton variant="primary"
							:href="guestsAdminUrl"
							data-test="button-open-guests-settings">
							<template #icon>
								<OpenInNew :size="20" />
							</template>
							{{ t('attendance', 'Open Guests app settings') }}
						</NcButton>
					</p>
				</template>

				<template v-if="guestsApp.enabled">
					<div class="guests-info-block">
						<h4>{{ t('attendance', 'How guest accounts are restricted') }}</h4>
						<p>
							{{ t('attendance', 'Guest users can submit RSVPs and self-check-in but can never manage appointments or check in other attendees. This is enforced server-side and cannot be granted via group permissions.') }}
						</p>
					</div>

					<div class="guests-info-block">
						<h4>{{ t('attendance', 'Converting guests to full accounts') }}</h4>
						<p>
							{{ t('attendance', 'When a guest later registers a full Nextcloud account with the same email (for example via SAML or LDAP), the Guests app converts them automatically. Past attendance responses remain attached to the original guest user ID — they are not migrated to the new account.') }}
						</p>
					</div>
				</template>
			</NcSettingsSection>
		</template>

		<!-- Writes the same settings this page shows, so reload when it saved -->
		<OnboardingWizard v-if="showOnboardingWizard"
			:open="showOnboardingWizard"
			:notificationsAppEnabled="notificationsAppEnabled"
			@close="showOnboardingWizard = false"
			@saved="loadSettings" />
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcSettingsSection,
} from '@nextcloud/vue'
import QRCode from 'qrcode'
import { computed, defineAsyncComponent, nextTick, onMounted, reactive, ref, watch } from 'vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import CalendarSyncIcon from 'vue-material-design-icons/CalendarSync.vue'
import CellphoneCheck from 'vue-material-design-icons/CellphoneCheck.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Download from 'vue-material-design-icons/Download.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import RocketLaunchIcon from 'vue-material-design-icons/RocketLaunch.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import CategoryIconPicker from '../components/admin/CategoryIconPicker.vue'
import PermissionRow from '../components/admin/PermissionRow.vue'
import SectionLink from '../components/admin/SectionLink.vue'
import GroupSelect from '../components/common/GroupSelect.vue'
import LoadingState from '../components/common/LoadingState.vue'
import { categoryIconComponent, DEFAULT_CATEGORY_ICON } from '../utils/categoryIcons.js'
import { copyToClipboard } from '../utils/clipboard.js'
import { formatDate, formatDateTimeMedium } from '../utils/datetime.js'
import { toGroupObjects } from '../utils/groups.js'
import { MOBILE_APP_STORES } from '../utils/mobileApp.js'
import { AUDIT_VISIBILITIES, emptyPermissionState, PERMISSION_NAMES, PERMISSION_ROWS, REMINDER_TARGETS } from '../utils/permissions.js'

const navSections = [
	{ id: 'setup-wizard', label: t('attendance', 'Setup wizard') },
	{ id: 'response-summary', label: t('attendance', 'Response summary') },
	{ id: 'categories', label: t('attendance', 'Categories') },
	{ id: 'permissions', label: t('attendance', 'Permissions') },
	{ id: 'self-checkin', label: t('attendance', 'Self-check-in') },
	{ id: 'reminders', label: t('attendance', 'Appointment reminders') },
	{ id: 'calendar-sync', label: t('attendance', 'Calendar sync') },
	{ id: 'org-calendar', label: t('attendance', 'Organization calendar') },
	{ id: 'audit-log', label: t('attendance', 'Audit log') },
	{ id: 'scheduling', label: t('attendance', 'Scheduling') },
	{ id: 'display-options', label: t('attendance', 'Display options') },
	{ id: 'mobile-apps', label: t('attendance', 'Mobile apps') },
	{ id: 'guests', label: t('attendance', 'Guest invitation') },
]

// Anchor plus label for a SectionLink, taken from the nav so a link can never
// name a section differently than the chip that jumps to the same place.
function sectionLink(sectionId) {
	return { sectionId, label: navSections.find((section) => section.id === sectionId).label }
}

const categories = ref([])
const categoriesLoading = ref(true)
const newCategory = reactive({ name: '', icon: DEFAULT_CATEGORY_ICON })
const creatingCategory = ref(false)
const editingCategoryId = ref(null)
const editingCategory = reactive({ name: '', icon: DEFAULT_CATEGORY_ICON })
// TRANSLATORS: Label of the input field holding a category's name — "name" is what the field expects, not part of a compound noun.
const categoryNameLabel = t('attendance', 'Category name')
// TRANSLATORS: Label of the empty input field for creating a category — the name the new category is about to get, not the name of an existing one.
const newCategoryNameLabel = t('attendance', 'New category name')
const savingCategoryEdit = ref(false)
const categoryToDelete = ref(null)

function sortCategories() {
	categories.value.sort((a, b) => a.name.localeCompare(b.name))
}

async function loadCategories() {
	categoriesLoading.value = true
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/categories'))
		categories.value = response.data
	} catch (error) {
		console.error('Failed to load categories:', error)
		showError(t('attendance', 'Could not load categories'))
	} finally {
		categoriesLoading.value = false
	}
}

async function createCategory() {
	const name = newCategory.name.trim()
	if (!name || creatingCategory.value) {
		return
	}
	creatingCategory.value = true
	try {
		const response = await axios.post(generateUrl('/apps/attendance/api/admin/categories'), { name, icon: newCategory.icon })
		categories.value.push(response.data)
		sortCategories()
		Object.assign(newCategory, { name: '', icon: DEFAULT_CATEGORY_ICON })
	} catch (error) {
		showError(error.response?.data?.error || t('attendance', 'Could not create category'))
	} finally {
		creatingCategory.value = false
	}
}

function startEditingCategory(category) {
	editingCategoryId.value = category.id
	Object.assign(editingCategory, { name: category.name, icon: category.icon })
}

function cancelEditingCategory() {
	editingCategoryId.value = null
	editingCategory.name = ''
}

async function saveEditingCategory() {
	const name = editingCategory.name.trim()
	if (!name || editingCategoryId.value === null || savingCategoryEdit.value) {
		return
	}
	savingCategoryEdit.value = true
	try {
		const response = await axios.put(
			generateUrl(`/apps/attendance/api/admin/categories/${editingCategoryId.value}`),
			{ name, icon: editingCategory.icon },
		)
		const index = categories.value.findIndex((category) => category.id === editingCategoryId.value)
		if (index !== -1) {
			categories.value.splice(index, 1, response.data)
		}
		sortCategories()
		cancelEditingCategory()
	} catch (error) {
		showError(error.response?.data?.error || t('attendance', 'Could not rename category'))
	} finally {
		savingCategoryEdit.value = false
	}
}

function confirmDeleteCategory(category) {
	categoryToDelete.value = category
}

async function deleteCategory() {
	const category = categoryToDelete.value
	if (!category) {
		return
	}
	try {
		await axios.delete(generateUrl(`/apps/attendance/api/admin/categories/${category.id}`))
		categories.value = categories.value.filter((c) => c.id !== category.id)
	} catch {
		showError(t('attendance', 'Could not delete category'))
	} finally {
		categoryToDelete.value = null
	}
}

const permissionGroups = [
	{ key: 'appointments', label: t('attendance', 'Appointments'), names: ['manage_appointments', 'create_appointments'] },
	{ key: 'responses', label: t('attendance', 'Responses'), names: ['see_response_overview', 'see_response_counts', 'see_comments', 'respond_for_others'] },
	{ key: 'checkin', label: t('attendance', 'Check-in'), names: ['checkin', 'self_checkin'] },
]

// Only this screen can jump between sections, so the link lives here rather
// than in the shared catalogue.
function implicationLinkFor(name) {
	const section = PERMISSION_ROWS[name].implicationSection
	return section ? sectionLink(section) : null
}

// State
const availableGroups = ref([])
const selectedGroups = ref([])
const selectedTeams = ref([])
const teamSearchResults = ref([])
const isSearchingTeams = ref(false)
const teamsAvailable = ref(false)
const permissions = ref(emptyPermissionState())
const selfCheckinWindowMinutes = ref(30)
const qrDataUrl = ref(null)
const selfCheckinUrl = window.location.origin + generateUrl('/apps/attendance/self-checkin')
const remindersEnabled = ref(false)
const reminderDays = ref(7)
const reminderFrequency = ref(0)
const reminderTarget = ref('non_responders')
const notificationsAppEnabled = ref(true)
const nextAppointment = ref(null)
const nextReminderRun = ref(null)
const calendarSyncEnabled = ref(false)
const calendarAvailable = ref(false)
const orgCalendarEnabled = ref(false)
const selectedOrgCalendar = ref(null)
const orgCalendarUserId = ref(null)
const writableCalendars = ref([])
const auditLogEnabled = ref(true)
const auditLogVisibility = ref('managers')
const pushEnabled = ref(true)
const mobileAppBannerEnabled = ref(true)
const bookingEnabled = ref(false)
const displayOrder = ref('name_first')
const pushDeviceCount = ref(0)
const loadingData = ref(true)
const sendingTestReminder = ref(false)
const syncingOrgCalendar = ref(false)
const guestsApp = ref({ enabled: false, whitelistEnabled: false, attendanceInWhitelist: false })
const showOnboardingWizard = ref(false)
// Only reachable from the button below — no reason to ship it with the page.
const OnboardingWizard = defineAsyncComponent(() => import('../components/onboarding/OnboardingWizard.vue'))

// Computed
// 'install' = Guests app missing (offer to install)
// 'whitelist' = Guests app enabled but `attendance` missing from its whitelist
// null = nothing to surface (either fully configured or a state we don't act on)
const guestsHintVariant = computed(() => {
	if (!guestsApp.value.enabled) {
		return 'install'
	}
	if (guestsApp.value.whitelistEnabled && !guestsApp.value.attendanceInWhitelist) {
		return 'whitelist'
	}
	return null
})

// Keep a stored calendar selectable even when the current admin cannot see it
// (it was picked by a different admin) — otherwise saving would silently drop it.
const orgCalendarOptions = computed(() => {
	const options = [...writableCalendars.value]
	const selected = selectedOrgCalendar.value
	if (selected?.uri && !options.some((c) => c.uri === selected.uri)) {
		options.unshift(selected)
	}
	return options
})

const guestsAdminUrl = computed(() => generateUrl('/settings/admin/guests'))
const guestsAppStoreUrl = 'https://apps.nextcloud.com/apps/guests'
const guestsWhitelistOccCommand = 'occ config:app:set guests whitelist --value=$(occ config:app:get guests whitelist),attendance'

const schedulingSectionDescription = computed(() => {
	// TRANSLATORS: Describes what the "Scheduling" switch below does — it's a capability being toggled on/off for managers ("this becomes possible"), not an instruction telling managers what to do.
	return t('attendance', 'Let managers mark yes-responders as scheduled for an appointment. When off, no scheduling controls are shown anywhere.')
})

const reminderSectionDescription = computed(() => {
	// TRANSLATORS: {groupsSection} and {teamsSection} are replaced with the translated headings of two settings sections on this page ("Response summary groups" and "Response summary teams") — translate those headings identically so admins find the sections referenced here.
	return t('attendance', 'Reminders are sent to users in the groups and teams configured under {groupsSection} and {teamsSection}. If an appointment has restricted access, only users matching that restriction will be reminded.', {
		groupsSection: t('attendance', 'Response summary groups'),
		teamsSection: t('attendance', 'Response summary teams'),
	})
})

const reminderPreviewDates = computed(() => {
	if (!nextAppointment.value) return []

	const appointmentDate = new Date(nextAppointment.value.startDatetime)
	const today = new Date()
	today.setHours(0, 0, 0, 0)

	const days = reminderDays.value || 7
	const frequency = reminderFrequency.value || 0

	// Window start = appointmentDate - reminderDays, clamped to today
	const windowStart = new Date(appointmentDate)
	windowStart.setDate(windowStart.getDate() - days)
	windowStart.setHours(0, 0, 0, 0)

	const effectiveStart = windowStart < today ? today : windowStart

	// If the window has already passed entirely
	const appointmentDay = new Date(appointmentDate)
	appointmentDay.setHours(0, 0, 0, 0)
	if (effectiveStart > appointmentDay) return []

	const dates = []
	if (frequency === 0) {
		// Single reminder at window start
		const daysBefore = Math.round((appointmentDay - effectiveStart) / (1000 * 60 * 60 * 24))
		dates.push({ date: new Date(effectiveStart), daysBefore, isFirst: true, isSingle: true })
	} else {
		// Repeated reminders every N days
		const current = new Date(effectiveStart)
		let isFirst = true

		while (current <= appointmentDay) {
			const daysBefore = Math.round((appointmentDay - current) / (1000 * 60 * 60 * 24))
			dates.push({ date: new Date(current), daysBefore, isFirst, isSingle: false })
			current.setDate(current.getDate() + frequency)
			isFirst = false
		}
	}

	return dates
})

// Auto-save
// Every mutation posts a partial payload; the backend leaves omitted settings
// untouched. Saves are debounced per key so rapid edits collapse into one.
let suppressSaves = true
const saveTimers = {}

function queueSave(key, payloadFactory, delay = 0) {
	if (suppressSaves) return
	clearTimeout(saveTimers[key])
	saveTimers[key] = setTimeout(async () => {
		delete saveTimers[key]
		const payload = payloadFactory()
		if (!payload) return
		try {
			await axios.post(generateUrl('/apps/attendance/api/admin/settings'), payload)
			showSuccess(window.t('attendance', 'Settings saved'))
		} catch (error) {
			console.error('Error saving settings:', error)
			showError(window.t('attendance', 'Failed to save settings'))
		}
	}, delay)
}

function autoSave(source, key, payloadFactory, delay = 0) {
	watch(source, () => queueSave(key, payloadFactory, delay), { deep: true })
}

const SELECT_DEBOUNCE = 800

autoSave(
	selectedGroups,
	'whitelistedGroups',
	() => ({ whitelistedGroups: selectedGroups.value.map((g) => g.id) }),
	SELECT_DEBOUNCE,
)
autoSave(
	selectedTeams,
	'whitelistedTeams',
	() => ({ whitelistedTeams: selectedTeams.value.map((team) => team.id) }),
	SELECT_DEBOUNCE,
)
// Per permission, not the whole map: the backend leaves omitted permissions
// untouched, so concurrent admins and stale local state cannot overwrite
// settings the user never edited.
function onPermissionChange(name, value) {
	permissions.value[name] = value
	queueSave(`permission:${name}`, () => ({
		permissions: {
			[name]: {
				mode: permissions.value[name].mode,
				groups: permissions.value[name].groups.map((g) => g.id),
			},
		},
	}), SELECT_DEBOUNCE)
}
autoSave(selfCheckinWindowMinutes, 'selfCheckinWindowMinutes', () => {
	if (!Number.isFinite(selfCheckinWindowMinutes.value)) return null
	return { selfCheckinWindowMinutes: selfCheckinWindowMinutes.value }
}, SELECT_DEBOUNCE)
autoSave([remindersEnabled, reminderDays, reminderFrequency, reminderTarget], 'reminders', () => {
	const reminders = {
		enabled: remindersEnabled.value,
		reminderTarget: reminderTarget.value,
	}
	if (Number.isFinite(reminderDays.value)) reminders.reminderDays = reminderDays.value
	if (Number.isFinite(reminderFrequency.value)) reminders.reminderFrequency = reminderFrequency.value
	return { reminders }
}, SELECT_DEBOUNCE)
autoSave(
	calendarSyncEnabled,
	'calendarSync',
	() => ({ calendarSync: { enabled: calendarSyncEnabled.value } }),
)
// Debounced although these are discrete clicks: saving triggers the calendar
// backfill server-side, so enable + calendar pick should collapse into one run.
autoSave([orgCalendarEnabled, selectedOrgCalendar], 'orgCalendar', () => ({
	orgCalendar: {
		enabled: orgCalendarEnabled.value,
		...(selectedOrgCalendar.value?.uri ? { calendarUri: selectedOrgCalendar.value.uri } : {}),
	},
}), SELECT_DEBOUNCE)
autoSave(
	[auditLogEnabled, auditLogVisibility],
	'audit',
	() => ({ audit: { enabled: auditLogEnabled.value, visibility: auditLogVisibility.value } }),
)
autoSave(displayOrder, 'displayOrder', () => ({ displayOrder: displayOrder.value }))
autoSave(pushEnabled, 'pushEnabled', () => ({ pushEnabled: pushEnabled.value }))
autoSave(
	mobileAppBannerEnabled,
	'mobileAppBannerEnabled',
	() => ({ mobileAppBannerEnabled: mobileAppBannerEnabled.value }),
)
autoSave(bookingEnabled, 'bookingEnabled', () => ({ bookingEnabled: bookingEnabled.value }))

// Methods
function scrollToSection(id) {
	const element = document.getElementById(id)
	if (element) {
		element.scrollIntoView({ behavior: 'smooth' })
		window.history.replaceState(null, '', `#${id}`)
	}
}

async function loadSettings() {
	loadingData.value = true
	suppressSaves = true

	try {
		const [settingsRes, capabilitiesRes] = await Promise.all([
			axios.get(generateUrl('/apps/attendance/api/admin/settings')),
			axios.get(generateUrl('/apps/attendance/api/capabilities')),
		])

		const { config, status, groups } = settingsRes.data
		const caps = capabilitiesRes.data

		availableGroups.value = groups
		selectedGroups.value = toGroupObjects(config.whitelistedGroups, groups)

		// Load teams settings
		teamsAvailable.value = caps.teamsAvailable || false
		if (config.whitelistedTeams) {
			selectedTeams.value = config.whitelistedTeams
			// Also add to search results so they appear in the dropdown
			teamSearchResults.value = [...config.whitelistedTeams]
		}

		// Load permission settings
		if (config.permissions) {
			for (const name of PERMISSION_NAMES) {
				const setting = config.permissions[name]
				if (!setting) continue
				permissions.value[name] = {
					mode: setting.mode,
					groups: toGroupObjects(setting.groups, groups),
				}
			}
		}

		selfCheckinWindowMinutes.value = config.selfCheckinWindowMinutes ?? 30

		// Load reminder settings
		remindersEnabled.value = config.reminders.enabled || false
		reminderDays.value = config.reminders.reminderDays || 7
		reminderFrequency.value = config.reminders.reminderFrequency || 0
		reminderTarget.value = config.reminders.reminderTarget || 'non_responders'
		notificationsAppEnabled.value = caps.notificationsAppEnabled !== false
		nextAppointment.value = status.nextAppointment || null
		nextReminderRun.value = status.nextReminderRun || null

		// Load calendar sync settings
		calendarSyncEnabled.value = config.calendarSync.enabled || false
		calendarAvailable.value = caps.calendarAvailable || false

		// Load organization calendar settings
		writableCalendars.value = settingsRes.data.writableCalendars || []
		if (config.orgCalendar) {
			orgCalendarEnabled.value = config.orgCalendar.enabled || false
			orgCalendarUserId.value = config.orgCalendar.userId || null
			const storedUri = config.orgCalendar.calendarUri
			if (storedUri) {
				selectedOrgCalendar.value = writableCalendars.value.find((c) => c.uri === storedUri)
					|| { uri: storedUri, displayName: storedUri }
			}
		}

		// Load audit log settings
		if (config.audit) {
			auditLogEnabled.value = config.audit.enabled !== false
			auditLogVisibility.value = config.audit.visibility || 'managers'
		}

		// Load display order
		displayOrder.value = config.displayOrder || 'name_first'

		// Load push notifications
		pushEnabled.value = config.pushEnabled !== false
		pushDeviceCount.value = status.pushDeviceCount || 0

		// Load mobile app banner setting
		mobileAppBannerEnabled.value = config.mobileAppBannerEnabled !== false

		// Load planning / booking setting (opt-in, defaults off)
		bookingEnabled.value = config.bookingEnabled === true

		// Load guests app status (for whitelist warning)
		if (config.guestsApp) {
			guestsApp.value = config.guestsApp
		}
	} catch (error) {
		console.error('Error loading settings:', error)
		showError(window.t('attendance', 'Failed to load settings'))
	} finally {
		loadingData.value = false
		// Let the watchers flush the load mutations before arming auto-save
		await nextTick()
		suppressSaves = false
	}
}

async function searchTeams(query) {
	if (!query || query.length < 1) {
		// Keep selected teams visible in results
		teamSearchResults.value = [...selectedTeams.value]
		return
	}

	isSearchingTeams.value = true
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/search/users-groups-teams'),
			{ params: { search: query } },
		)
		// Filter to only show teams
		const teams = response.data
			.filter((item) => item.type === 'team')
			.map((item) => ({ id: item.id, label: item.label, type: 'team' }))

		// Merge with selected teams to keep them visible
		const selectedIds = selectedTeams.value.map((t) => t.id)
		const newTeams = teams.filter((t) => !selectedIds.includes(t.id))
		teamSearchResults.value = [...selectedTeams.value, ...newTeams]
	} catch (error) {
		console.error('Error searching teams:', error)
	} finally {
		isSearchingTeams.value = false
	}
}

async function generateQrCode() {
	try {
		qrDataUrl.value = await QRCode.toDataURL(selfCheckinUrl, { width: 512, margin: 2 })
	} catch (error) {
		console.error('Error generating QR code:', error)
	}
}

function downloadQrCode() {
	if (!qrDataUrl.value) return
	const link = document.createElement('a')
	link.href = qrDataUrl.value
	link.download = 'attendance-self-checkin-qr.png'
	link.click()
}

const copySelfCheckinUrl = () => copyStoreUrl(selfCheckinUrl)

function copyStoreUrl(url) {
	return copyToClipboard(url, {
		successMessage: window.t('attendance', 'Link copied'),
		errorMessage: window.t('attendance', 'Failed to copy link'),
	})
}

function copyGuestsOccCommand() {
	return copyToClipboard(
		guestsWhitelistOccCommand,
		{
			successMessage: window.t('attendance', 'Command copied'),
			errorMessage: window.t('attendance', 'Failed to copy command'),
		},
	)
}

async function syncOrgCalendar() {
	syncingOrgCalendar.value = true
	try {
		const response = await axios.post(generateUrl('/apps/attendance/api/admin/org-calendar/sync'))
		const count = response.data.synced ?? 0
		showSuccess(window.n('attendance', '%n appointment synced to the calendar', '%n appointments synced to the calendar', count))
	} catch (error) {
		console.error('Error syncing organization calendar:', error)
		showError(window.t('attendance', 'Failed to sync appointments to the calendar'))
	} finally {
		syncingOrgCalendar.value = false
	}
}

async function sendTestReminder() {
	sendingTestReminder.value = true
	try {
		const response = await axios.post(generateUrl('/apps/attendance/api/admin/test-reminder'))
		const name = response.data.appointmentName || ''
		showSuccess(window.t('attendance', 'Test reminder sent for {name}', { name }))
	} catch (error) {
		if (error.response?.status === 404) {
			showError(window.t('attendance', 'No upcoming appointment found'))
		} else {
			console.error('Error sending test reminder:', error)
			showError(window.t('attendance', 'Failed to send test reminder'))
		}
	} finally {
		sendingTestReminder.value = false
	}
}

// Lifecycle
onMounted(async () => {
	generateQrCode()
	await Promise.all([loadSettings(), loadCategories()])

	// Handle hash navigation after content is loaded
	await nextTick()
	if (window.location.hash) {
		scrollToSection(window.location.hash.slice(1))
	}
})
</script>

<style scoped>
#attendance-admin-settings {
	padding: 20px;
	max-width: 900px;
}

.anchor-nav {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 12px;
}

.anchor-nav__link {
	padding: 6px 14px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 13px;
	text-decoration: none;
	white-space: nowrap;
}

.anchor-nav__link:hover,
.anchor-nav__link:focus {
	background-color: var(--color-primary-element-light);
}

.hint-text {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.category-list {
	list-style: none;
	margin: 0 0 16px 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.category-list__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
}

.category-list__icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.category-list__name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.category-list__edit-field {
	flex: 1;
	min-width: 0;
}

.category-add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.category-add__name-field {
	flex: 1;
}

.permission-group {
	margin-bottom: 32px;
}

.permission-group:last-child {
	margin-bottom: 0;
}

.permission-group__title {
	margin: 0 0 4px 0;
	padding-bottom: 6px;
	border-bottom: 1px solid var(--color-border-dark);
	font-size: 16px;
	font-weight: 700;
}

.guests-warning-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 12px;
}

.guests-occ-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 8px;
}

.guests-warning-or {
	margin: 8px 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-style: italic;
}

.guests-info-block {
	margin-top: 20px;

	h4 {
		margin: 0 0 6px 0;
		font-size: 14px;
		font-weight: 600;
	}

	p {
		margin: 0 0 8px 0;
		color: var(--color-text-lighter);
		font-size: 13px;
		line-height: 1.5;
	}
}

.guests-occ-row__command {
	flex: 1 1 300px;
	padding: 8px 12px;
	background-color: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
	word-break: break-all;
	user-select: all;
}

.subsection {
	margin: 24px 0;
	padding-bottom: 24px;
	border-bottom: 1px solid var(--color-border);
}

.subsection:last-child {
	border-bottom: none;
}

.subsection h4 {
	margin: 0 0 4px 0;
	font-size: 15px;
	font-weight: 600;
}

.org-calendar-sync-button {
	margin-top: 12px;
}

.self-checkin-window-field {
	max-width: 400px;
	/* The floating label sits above the input border, so it needs extra
	   room to not collide with the note card above. */
	margin-top: 24px;
}

.self-checkin-qr {
	margin-top: 20px;

	h6 {
		font-weight: 600;
		margin: 16px 0 4px;
	}

	.self-checkin-qr__image {
		width: 180px;
		height: 180px;
		border-radius: var(--border-radius-large);
		background: #fff;
		padding: 8px;
		box-sizing: content-box;
	}

	.self-checkin-qr__actions {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
		margin: 8px 0;
	}
}

.subsection-hint {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.reminder-config {
	margin-top: 16px;
	margin-bottom: 16px;
	max-width: 300px;
}

.input-field.reminder-frequency-field {
	margin-block-start: 40px;
}

.reminder-target-section {
	margin-top: 24px;
}

.reminder-target-label {
	display: block;
	font-weight: 600;
	margin-bottom: 8px;
	font-size: 14px;
}

.reminder-preview {
	margin-top: 20px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.reminder-preview h4 {
	margin: 0 0 8px 0;
	font-size: 15px;
	font-weight: 600;
}

.reminder-preview h4 + p + h4 {
	margin-top: 20px;
}

.reminder-preview-context {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
}

.reminder-preview-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.reminder-preview-list li {
	padding: 6px 0;
	display: flex;
	align-items: center;
	gap: 8px;
}

.reminder-preview-label {
	color: var(--color-text-maxcontrast);
}

.test-reminder-button {
	margin-top: 12px;
}

.push-device-status {
	margin-top: 16px;
}

.push-device-info {
	display: flex;
	align-items: center;
	gap: 8px;
}

.mobile-app-links {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.mobile-app-link__label {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
	font-weight: 600;
}

.mobile-app-link__row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.mobile-app-link__url {
	flex: 1 1 300px;
	padding: 8px 12px;
	background-color: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
	word-break: break-all;
	user-select: all;
}
</style>
