<template>
	<k-panel-inside class="k-updater-view">
		<k-header>Kirby Updater</k-header>

		<k-section
			headline="Status"
			:buttons="[{ text: $t('system.info.copy'), icon: 'copy', responsive: true, click: copy }]"
		>
			<k-stats :reports="reports" size="medium" />
		</k-section>

		<k-section v-if="issues.length" headline="Issues">
			<k-items :items="issueItems" />
		</k-section>

		<k-section v-if="backups.length" headline="Backups">
			<k-table :columns="backupColumns" :rows="backups" :index="false" class="k-updater-backups">
				<template #options="{ row }">
					<k-button-group>
						<k-button
							:disabled="isBusy || !row.restorable"
							:title="row.restorable ? null : 'Backups of another major version can\'t be restored from the Panel'"
							icon="undo"
							size="xs"
							text="Restore"
							variant="filled"
							@click="rollback(row)"
						/>
						<k-button
							:disabled="isBusy"
							icon="trash"
							size="xs"
							text="Delete"
							variant="filled"
							@click="remove(row)"
						/>
					</k-button-group>
				</template>
			</k-table>
		</k-section>

		<k-button-group v-if="canUpdate" class="k-updater-buttons">
			<k-button
				:disabled="isBusy"
				:text="isBusy ? 'Working …' : 'Update to ' + targetVersion"
				icon="refresh"
				theme="positive"
				variant="filled"
				@click="update"
			/>
		</k-button-group>
	</k-panel-inside>
</template>

<script>
export default {
	props: {
		currentVersion: String,
		targetVersion: String,
		canUpdate: Boolean,
		issues: Array,
		changelog: String,
		backups: Array,
		backupSize: String,
		support: Object
	},
	data() {
		return {
			isBusy: false
		};
	},
	computed: {
		reports() {
			return [
				{
					label: "Installed",
					value: this.currentVersion,
					icon: "box"
				},
				{
					label: this.targetVersion ? "Update available" : "Up to date",
					value: this.targetVersion ?? this.currentVersion,
					theme: this.targetVersion ? "info" : "positive",
					icon: this.targetVersion ? "download" : "check",
					link: this.changelog
				},
				...(this.supportReport ? [this.supportReport] : []),
				{
					label: this.backupLabel,
					value: this.latestBackup ? this.backupSize : "–",
					icon: "archive"
				}
			];
		},
		supportReport() {
			if (!this.support) {
				return null;
			}

			const statuses = {
				"active-support": { text: "active support", theme: "positive" },
				"security-support": { text: "security fixes only", theme: "notice" },
				"end-of-life": { text: "end of life", theme: "negative" }
			};
			const status = statuses[this.support.status] ?? { text: this.support.status };
			const date = this.support.endOfLife
				? new Date(this.support.endOfLife).toLocaleDateString(this.$panel.translation.code, { month: "short", year: "numeric" })
				: null;

			return {
				label: `Kirby ${this.support.major} – ${status.text}`,
				value: date ? (this.support.status === "end-of-life" ? `since ${date}` : date) : "–",
				theme: status.theme,
				icon: "shield",
				link: "https://getkirby.com/security"
			};
		},
		latestBackup() {
			return this.backups[0];
		},
		backupLabel() {
			if (this.backups.length > 1) {
				return `${this.backups.length} core backups (newest: ${this.latestBackup.version})`;
			}

			return this.latestBackup ? `Core backup of ${this.latestBackup.version}` : "No backup";
		},
		backupColumns() {
			return {
				version: { label: "Kirby version", mobile: true, width: "1/6" },
				size: { label: "Size", mobile: true, width: "1/6" },
				path: { label: "Path" }
			};
		},
		// same item style as the security section of the system view
		issueItems() {
			return this.issues.map((issue) => ({
				theme: "negative",
				image: {
					back: "light-dark(var(--theme-color-200), var(--theme-color-900))",
					icon: issue.icon ?? "alert",
					color: "var(--theme-color-icon)"
				},
				target: "_blank",
				...issue
			}));
		}
	},
	mounted() {
		const message = sessionStorage.getItem("kirby-updater");

		if (message) {
			sessionStorage.removeItem("kirby-updater");
			this.$panel.notification.success(message);
		}
	},
	methods: {
		// same as the copy button of the system view
		copy() {
			const info = JSON.stringify(
				{
					installed: this.currentVersion,
					available: this.targetVersion,
					support: this.support,
					backups: this.backups.map((backup) => backup.version),
					issues: this.issues.map((issue) => issue.text)
				},
				null,
				2
			);

			this.$helper.clipboard.write(info);
			this.$panel.notification.success({ message: this.$t("system.info.copied") });
		},
		confirm(text, button, endpoint, data) {
			this.$panel.dialog.open({
				component: "k-text-dialog",
				props: {
					// Kirby 6 escapes plain strings, so mark the text as HTML
					text: this.$panel.html(text),
					submitButton: button
				},
				on: {
					submit: () => this.run(endpoint, data)
				}
			});
		},
		update() {
			this.confirm(
				`Update Kirby from <strong>${this.$esc(this.currentVersion)}</strong> to <strong>${this.$esc(this.targetVersion)}</strong>? A backup of the current kirby folder will be kept. Your content, site folder and plugins are not touched or backed up by the update.`,
				{ text: "Update", icon: "refresh", theme: "positive" },
				"updater/update"
			);
		},
		rollback(backup) {
			this.confirm(
				`Restore the backup <strong>${this.$esc(backup.name)}</strong> (Kirby ${this.$esc(backup.version)})? The current kirby folder (Kirby ${this.$esc(this.currentVersion)}) will be deleted without a backup.`,
				{ text: "Restore", icon: "undo", theme: "negative" },
				"updater/rollback",
				{ name: backup.name }
			);
		},
		remove(backup) {
			this.confirm(
				`Delete the backup <strong>${this.$esc(backup.name)}</strong> (Kirby ${this.$esc(backup.version)})? This cannot be undone.`,
				{ text: "Delete", icon: "trash", theme: "negative" },
				"updater/delete",
				{ name: backup.name }
			);
		},
		async run(endpoint, data) {
			this.$panel.dialog.close();
			this.isBusy = true;

			try {
				const result = await this.$api.post(endpoint, data);
				// reload to load the Panel assets of a new version and the
				// current backups, the message is shown after the reload (see mounted)
				sessionStorage.setItem(
					"kirby-updater",
					result.newVersion ? `Kirby is now on version ${result.newVersion}` : `Backup ${result.backup} deleted`
				);
				window.location.reload();
			} catch (error) {
				this.$panel.notification.error(error);
				this.isBusy = false;
			}
		}
	}
};
</script>

<style>
.k-updater-buttons {
	margin-top: var(--spacing-6);
}

/* room for the inline restore and delete buttons */
.k-updater-backups .k-table-options-column {
	width: 12rem;
	padding-inline: var(--spacing-2);
}
.k-updater-backups .k-table-options-column .k-button-group {
	flex-wrap: nowrap;
	justify-content: center;
}

/* colored labels like in the system view */
.k-updater-view .k-stat-label {
	color: var(--theme-color-text, currentColor);
}
</style>
