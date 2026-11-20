panel.plugin("devmount/kirby-updater", {
	components: {
		"k-updater-view": {
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
				confirm(text, button, endpoint) {
					this.$panel.dialog.open({
						component: "k-text-dialog",
						props: {
							text: text,
							submitButton: button
						},
						on: {
							submit: () => this.run(endpoint)
						}
					});
				},
				update() {
					this.confirm(
						`Update Kirby from <strong>${this.currentVersion}</strong> to <strong>${this.targetVersion}</strong>? A backup of the current kirby folder will be kept. Your content, site folder and plugins are not touched or backed up by the update.`,
						{ text: "Update", icon: "refresh", theme: "positive" },
						"updater/update"
					);
				},
				rollback() {
					this.confirm(
						`Restore the backup <strong>${this.latestBackup.name}</strong> (Kirby ${this.latestBackup.version})? The current kirby folder (Kirby ${this.currentVersion}) will be deleted without a backup.`,
						{ text: "Restore", icon: "undo", theme: "negative" },
						"updater/rollback"
					);
				},
				async run(endpoint) {
					this.$panel.dialog.close();
					this.isBusy = true;

					try {
						const result = await this.$api.post(endpoint);
						// reload to load the Panel assets of the new version,
						// the message is shown after the reload (see mounted)
						sessionStorage.setItem("kirby-updater", `Kirby is now on version ${result.newVersion}`);
						window.location.reload();
					} catch (error) {
						this.$panel.notification.error(error);
						this.isBusy = false;
					}
				}
			},
			template: `
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

					<k-button-group class="k-updater-buttons">
						<k-button
							v-if="canUpdate"
							:disabled="isBusy"
							:text="isBusy ? 'Working …' : 'Update to ' + targetVersion"
							icon="refresh"
							theme="positive"
							variant="filled"
							@click="update"
						/>
						<k-button
							v-if="latestBackup"
							:disabled="isBusy"
							:text="'Restore backup (' + latestBackup.version + ')'"
							icon="undo"
							variant="filled"
							@click="rollback"
						/>
					</k-button-group>
				</k-panel-inside>
			`
		}
	}
});
