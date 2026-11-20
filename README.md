# Kirby Updater

Update the Kirby core of a manually installed (non-Composer) Kirby 5 site to the latest patch or minor release, right from the Panel.

## How it works

1. Admins get an **Updater** entry in the Panel menu showing the installed and the latest available version of the current major release, plus known security issues.
2. On update, the release archive is downloaded from GitHub, extracted next to `kirby/` and validated (required files, version, PHP requirement).
3. Only then is the current `kirby/` folder renamed to `kirby.backup-<timestamp>-<version>/` and the new folder moved in place.
4. If anything fails after the swap, the backup is moved back automatically.
5. The latest backup can be restored manually from the Updater view.

Major upgrades are never offered and stay a manual task. Composer installs are detected and refused, use `composer update getkirby/cms` there.

## Requirements

- Kirby 5
- PHP `ZipArchive`
- Write access of the web server user to the `kirby/` folder and its parent folder

## Options

```php
// site/config/config.php
return [
  'devmount.kirby-updater' => [
    // number of kirby folder backups to keep
    'backups'    => 1,
    // false only allows patch updates
    'allowMinor' => true,
    // false, true (requests the Panel login page) or a URL
    // that is requested after the swap, rolling back on a 5xx response
    'smokeTest'  => false,
  ],
];
```

## Limitations

- Plugins may be incompatible with a new minor release. Use "Restore backup" in that case.
- GitHub archives have no published checksums, so the download is validated by structure and version only.
