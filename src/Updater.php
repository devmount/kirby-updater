<?php

namespace Devmount\KirbyUpdater;

use Composer\Semver\Semver;
use Kirby\Cms\App;
use Kirby\Exception\Exception;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Remote;
use Throwable;

/**
 * Updates the Kirby core folder to the latest
 * release within the currently installed major version
 */
class Updater
{
	public const DATA_URL     = 'https://getkirby.com/security.json';
	public const DOWNLOAD_URL = 'https://github.com/getkirby/kirby/archive/refs/tags/{version}.zip';

	protected array|null $data = null;

	/** @var resource|null */
	protected $lock = null;

	public function __construct(protected App $kirby)
	{
	}

	/**
	 * Creates an updater instance, only for admins
	 */
	public static function forAdmin(App|null $kirby = null): static
	{
		$kirby ??= App::instance();

		if ($kirby->user()?->isAdmin() !== true) {
			throw new PermissionException(message: 'Only admins can update Kirby');
		}

		return new static($kirby);
	}

	/**
	 * Returns all information needed by the Panel view
	 */
	public function status(): array
	{
		try {
			$target   = $this->targetVersion();
			$blockers = $this->blockers($target);
		} catch (Throwable $e) {
			$target   = null;
			$blockers = [$e->getMessage()];
		}

		// same items as in the security section of the system view
		$issues = [
			...$this->kirby->system()->updateStatus()?->messages() ?? [],
			...array_map(fn ($blocker) => [
				'text'  => $blocker,
				'icon'  => 'alert',
				'theme' => 'notice',
			], $blockers),
		];

		return [
			'currentVersion' => $this->kirby->version(),
			'targetVersion'  => $target,
			'canUpdate'      => $target !== null && $blockers === [],
			'issues'         => $issues,
			'changelog'      => $target ? 'https://getkirby.com/releases/' . $target : null,
			'backups'        => $this->backups(),
		];
	}

	/**
	 * Latest release of the current major version,
	 * `null` if the installation is up to date
	 */
	public function targetVersion(): string|null
	{
		$current = $this->kirby->version();
		$major   = explode('.', $current)[0];
		$latest  = $this->data()['versions'][$major . '.*']['latest'] ?? null;

		if (is_string($latest) === false || version_compare($latest, $current, '<=') === true) {
			return null;
		}

		return $latest;
	}

	/**
	 * Reasons that prevent an update
	 */
	public function blockers(string|null $target = null): array
	{
		$root     = $this->root();
		$parent   = dirname($root);
		$blockers = [];

		if ($this->isComposerInstall() === true) {
			$blockers[] = 'Kirby is managed by Composer. Please run `composer update getkirby/cms` instead.';
		}

		if (class_exists('ZipArchive') === false) {
			$blockers[] = 'The PHP zip extension (ZipArchive) is not available.';
		}

		if (is_writable($parent) === false || is_writable($root) === false) {
			$blockers[] = 'The kirby folder or its parent folder is not writable by the web server.';
		}

		if (
			$target !== null &&
			$this->option('allowMinor') === false &&
			$this->minor($target) !== $this->minor($this->kirby->version())
		) {
			$blockers[] = 'Minor updates are disabled by the `devmount.kirby-updater.allowMinor` option.';
		}

		$free = @disk_free_space($parent);
		$size = Dir::size($root);

		if ($free !== false && $size !== false && $free < 3 * $size) {
			$blockers[] = 'Not enough free disk space for the update and the backup.';
		}

		return $blockers;
	}

	/**
	 * Downloads the target version and swaps it with the current
	 * kirby folder; restores the old folder on any error
	 */
	public function update(): array
	{
		$this->acquireLock();

		// load classes possibly needed after the swap from the old folder
		class_exists(Exception::class);

		$root    = $this->root();
		$current = $this->kirby->version();
		$tmp     = $root . '.updater-tmp';
		$swapped = false;

		try {
			$target = $this->targetVersion() ?? throw new Exception(message: 'Kirby is already up to date');

			if (($blockers = $this->blockers($target)) !== []) {
				throw new Exception(message: implode(' ', $blockers));
			}

			$new    = $tmp . '/kirby';
			$backup = $root . '.backup-' . date('YmdHis') . '-' . $current;

			Dir::remove($tmp);
			Dir::make($tmp);

			$this->download($target, $tmp . '/kirby.zip');
			$this->extract($tmp . '/kirby.zip', $tmp . '/extract', $new);
			$this->validate($new, $target);

			// everything is prepared, so the swap is the last step
			if (rename($root, $backup) === false) {
				throw new Exception(message: 'Could not move the current kirby folder to the backup');
			}

			$swapped = true;

			if (rename($new, $root) === false) {
				throw new Exception(message: 'Could not move the new kirby folder in place');
			}

			$this->resetOpcache();
			$this->smokeTest();
		} catch (Throwable $e) {
			if ($swapped === true) {
				// the new folder may or may not have been moved in place yet
				if (is_dir($root) === true) {
					Dir::remove($root);
				}

				rename($backup, $root);
				$this->resetOpcache();
			}

			throw new Exception(
				message: 'The update failed, the current installation was left untouched: ' . $e->getMessage(),
				previous: $e
			);
		} finally {
			Dir::remove($tmp);
			$this->releaseLock();
		}

		$this->pruneBackups();

		return [
			'oldVersion' => $current,
			'newVersion' => $target,
			'backup'     => basename($backup),
		];
	}

	/**
	 * Restores the most recent backup
	 */
	public function rollback(): array
	{
		$this->acquireLock();

		$root    = $this->root();
		$current = $this->kirby->version();
		$trash   = $root . '.updater-trash';

		try {
			$backup = $this->backups()[0] ?? throw new Exception(message: 'There is no backup to restore');
			$path   = dirname($root) . '/' . $backup['name'];

			Dir::remove($trash);

			if (rename($root, $trash) === false) {
				throw new Exception(message: 'Could not move the current kirby folder away');
			}

			if (rename($path, $root) === false) {
				rename($trash, $root);
				throw new Exception(message: 'Could not move the backup in place');
			}

			$this->resetOpcache();
			Dir::remove($trash);
		} finally {
			$this->releaseLock();
		}

		return [
			'oldVersion' => $current,
			'newVersion' => $backup['version'],
		];
	}

	/**
	 * Existing backups, newest first
	 */
	public function backups(): array
	{
		$dirs = glob($this->root() . '.backup-*', GLOB_ONLYDIR) ?: [];
		rsort($dirs);

		return array_map(fn ($dir) => [
			'name'    => basename($dir),
			'version' => $this->versionOf($dir),
		], $dirs);
	}

	protected function download(string $version, string $file): void
	{
		$url      = str_replace('{version}', $version, static::DOWNLOAD_URL);
		$response = Remote::get($url, ['timeout' => 120]);

		if ($response->code() !== 200) {
			throw new Exception(message: 'Could not download ' . $url . ' (HTTP ' . $response->code() . ')');
		}

		F::write($file, $response->content());
	}

	protected function extract(string $zip, string $to, string $target): void
	{
		if (F::unzip($zip, $to) === false) {
			throw new Exception(message: 'Could not extract the downloaded archive');
		}

		// the archive contains a single folder, e.g. `kirby-5.6.4/`
		$dirs = Dir::dirs($to);

		if (count($dirs) !== 1 || rename($to . '/' . $dirs[0], $target) === false) {
			throw new Exception(message: 'The downloaded archive has an unexpected structure');
		}
	}

	protected function validate(string $dir, string $version): void
	{
		foreach (['bootstrap.php', 'vendor/autoload.php', 'panel/dist/js/index.min.js'] as $file) {
			if (is_file($dir . '/' . $file) === false) {
				throw new Exception(message: 'The downloaded version is incomplete, missing ' . $file);
			}
		}

		if ($this->versionOf($dir) !== $version) {
			throw new Exception(message: 'The downloaded version does not match ' . $version);
		}

		$composer = json_decode(F::read($dir . '/composer.json'), true);
		$php      = $composer['require']['php'] ?? null;
		$current  = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;

		if ($php !== null && Semver::satisfies($current, $php) === false) {
			throw new Exception(message: 'Kirby ' . $version . ' requires PHP ' . $php . ', but ' . $current . ' is installed');
		}
	}

	/**
	 * Requests the new installation in a separate process,
	 * if enabled by the `smokeTest` option (true or a URL)
	 */
	protected function smokeTest(): void
	{
		$option = $this->option('smokeTest');

		if (empty($option) === true) {
			return;
		}

		$url      = is_string($option) ? $option : $this->kirby->url('panel') . '/login';
		$response = Remote::get($url, ['timeout' => 30]);

		if ($response->code() === 0 || $response->code() >= 500) {
			throw new Exception(message: 'The smoke test request to ' . $url . ' failed (HTTP ' . $response->code() . ')');
		}
	}

	protected function pruneBackups(): void
	{
		$keep = max(1, (int)$this->option('backups'));

		foreach (array_slice($this->backups(), $keep) as $backup) {
			Dir::remove(dirname($this->root()) . '/' . $backup['name']);
		}
	}

	protected function isComposerInstall(): bool
	{
		$base = dirname($this->root());

		// the root composer.json is not checked, since starterkit
		// and plainkit ship one that requires getkirby/cms
		foreach (['/vendor/composer/installed.json', '/composer.lock'] as $file) {
			if (str_contains(F::read($base . $file) ?: '', '"getkirby/cms"') === true) {
				return true;
			}
		}

		return false;
	}

	protected function acquireLock(): void
	{
		$file = $this->kirby->root('cache') . '/kirby-updater.lock';
		Dir::make(dirname($file));

		$this->lock = fopen($file, 'c');

		if ($this->lock === false || flock($this->lock, LOCK_EX | LOCK_NB) === false) {
			throw new Exception(message: 'Another update is already running');
		}
	}

	protected function releaseLock(): void
	{
		if (is_resource($this->lock) === true) {
			flock($this->lock, LOCK_UN);
			fclose($this->lock);
		}

		$this->lock = null;
	}

	protected function resetOpcache(): void
	{
		if (function_exists('opcache_reset') === true) {
			@opcache_reset();
		}
	}

	protected function data(): array
	{
		if ($this->data !== null) {
			return $this->data;
		}

		$response = Remote::get(static::DATA_URL, ['timeout' => 10]);
		$data     = $response->code() === 200 ? $response->json() : null;

		if (is_array($data) === false) {
			throw new Exception(message: 'Could not load the update data from getkirby.com');
		}

		return $this->data = $data;
	}

	protected function versionOf(string $dir): string|null
	{
		$composer = json_decode(F::read($dir . '/composer.json') ?: '', true);
		return $composer['version'] ?? null;
	}

	protected function minor(string $version): string
	{
		return implode('.', array_slice(explode('.', $version), 0, 2));
	}

	protected function option(string $key): mixed
	{
		return $this->kirby->option('devmount.kirby-updater.' . $key);
	}

	protected function root(): string
	{
		return $this->kirby->root('kirby');
	}
}
