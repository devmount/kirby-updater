# Kirby Updater

Update the Kirby core of a manually installed (non-Composer) Kirby 6 site to the latest patch or minor release, right from the Panel.

![Kirby Updater](.github/screenshot.png)

## Installation

Download the [latest release](https://github.com/devmount/kirby-updater/releases/latest), extract it to `site/plugins/kirby-updater` (replace the folder when updating), or install it with Composer:

```bash
composer require devmount/kirby-updater
```

## The Updater view

Admins get an **Updater** entry in the Panel menu. Other users see neither the menu entry nor the view, and the update API is restricted to admins.

The view shows the installed and latest version, the support status of the current major version and the size of all backups, plus the known security issues and anything preventing an update. A table lists each backup with its Kirby version, size and path, and lets you restore or delete it.

## How it works

1. On update, the release archive is downloaded from GitHub, extracted into `site/kirby-updater/tmp/` and validated (required files, version, PHP requirement).
2. Only then is the current `kirby/` folder moved to `site/kirby-updater/backup-<timestamp>-<version>/` and the new folder moved in place.
3. If anything fails after the swap, the backup is moved back automatically.
4. After a successful update, the Panel reloads with the new version and confirms the update. Older backups are removed, keeping as many as set by the `backups` option.
5. Any backup of the installed major version can be restored from the backup table. This deletes the current `kirby/` folder without a backup.
6. Backups of another major version, e.g. Kirby 5 backups after a manual upgrade to Kirby 6, can't be restored from the Panel, since the Updater view only works with the major version it's built for. They are kept as a manual fallback until you delete them or the next update removes them.

**Only the `kirby/` folder (the Kirby core) is backed up and replaced. Content, the `site/` folder (including plugins and accounts) and `media/` are neither touched nor backed up, so keep your regular site backups.**

Backups and temporary files are kept in `site/kirby-updater/`, which Kirby's server configs block from web access, so the old (possibly vulnerable) core is never publicly reachable. Add this folder to your `.gitignore`.

Major upgrades are never offered and stay a manual task. The update is refused for:

- Composer installs, use `composer update getkirby/cms` there
- a symlinked `kirby/` folder, since it may be shared with other sites
- a `kirby/` folder managed by git (e.g. a submodule)

## Requirements

- Kirby 6 (for Kirby 5, use [v1.1.1](https://github.com/devmount/kirby-updater/releases/tag/v1.1.1))
- PHP 8.3 or newer
- PHP `ZipArchive`
- Write access of the web server user to the `kirby/` folder, its parent folder and the `site/` folder
- `kirby/` and `site/` on the same filesystem

## Options

```php
// site/config/config.php
return [
  'devmount.kirby-updater' => [
    // number of kirby folder backups to keep (at least 1)
    'backups'    => 1,
    // false only allows patch updates of the installed minor version
    'allowMinor' => true,
    // false, true (requests the Panel login page) or a URL
    // that is requested after the swap, rolling back on a 5xx response
    'smokeTest'  => false,
  ],
];
```

## Limitations

- Plugins may be incompatible with a new minor release. Restore the previous backup from the backup table in that case.
- Any changes made inside the `kirby/` folder are replaced by the update. They only survive in the backup until the next update removes it.
- GitHub archives have no published checksums, so the download is validated by structure and version only.

## Development

The Panel view is built with [kirbyup](https://github.com/johannschopplich/kirbyup), so it works without Kirby's Vue template compiler:

```bash
pnpm install
pnpm dev       # development build with hot module replacement
pnpm build     # production build to index.js and index.css
```

Commit the built `index.js` and `index.css` with your changes.

## License

This plugin is licensed under the [MIT License](LICENSE).
