panel.plugin("devmount/kirby-updater", {
	components: {
		"k-updater-view": {
			props: {
				currentVersion: String,
				targetVersion: String,
				canUpdate: Boolean,
				blockers: Array,
				changelog: String,
				vulnerabilities: Array,
				backups: Array
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
							label: "Available",
							value: this.targetVersion ?? this.currentVersion,
							info: this.targetVersion ? "Update available" : "Up to date",
							theme: this.targetVersion ? "info" : "positive",
							icon: this.targetVersion ? "download" : "check",
							link: this.changelog
						}
					];
				},
				latestBackup() {
					return this.backups[0];
				}
			},
			methods: {
				vulnerabilityText(vulnerability) {
					return `<strong>Security issue (${vulnerability.severity}):</strong> ${vulnerability.description} <a href="${vulnerability.link}" target="_blank">Details</a>`;
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
						`Update Kirby from <strong>${this.currentVersion}</strong> to <strong>${this.targetVersion}</strong>? A backup of the current kirby folder will be kept.`,
						{ text: "Update", icon: "refresh", theme: "positive" },
						"updater/update"
					);
				},
				rollback() {
					this.confirm(
						`Restore the backup <strong>${this.latestBackup.name}</strong> (Kirby ${this.latestBackup.version})? The current kirby folder will be removed.`,
						{ text: "Restore", icon: "undo", theme: "negative" },
						"updater/rollback"
					);
				},
				async run(endpoint) {
					this.$panel.dialog.close();
					this.isBusy = true;

					try {
						const result = await this.$api.post(endpoint);
						this.$panel.notification.success(`Kirby is now on version ${result.newVersion}`);
						// reload to load the Panel assets of the new version
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

					<k-stats :reports="reports" size="large" />

					<k-box
						v-for="vulnerability in vulnerabilities"
						:key="vulnerability.link"
						:html="true"
						:text="vulnerabilityText(vulnerability)"
						theme="negative"
						icon="alert"
						class="k-updater-box"
					/>

					<k-box
						v-for="blocker in blockers"
						:key="blocker"
						:text="blocker"
						theme="notice"
						icon="alert"
						class="k-updater-box"
					/>

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
