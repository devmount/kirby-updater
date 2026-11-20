<?php

use Devmount\KirbyUpdater\Updater;
use Kirby\Cms\App;

load([
	'Devmount\\KirbyUpdater\\Updater' => __DIR__ . '/src/Updater.php'
]);

App::plugin('devmount/kirby-updater', [
	'options' => [
		// number of kirby folder backups to keep
		'backups'    => 1,
		// false only allows patch updates
		'allowMinor' => true,
		// false, true (requests the Panel login) or a URL to request after the swap
		'smokeTest'  => false,
	],
	'areas' => [
		'updater' => fn (App $kirby) => [
			'label' => 'Updater',
			'icon'  => 'refresh',
			'menu'  => fn () => $kirby->user()?->isAdmin() === true,
			'link'  => 'updater',
			'views' => [
				[
					'pattern' => 'updater',
					'action'  => fn () => [
						'component' => 'k-updater-view',
						'title'     => 'Updater',
						'props'     => Updater::forAdmin($kirby)->status(),
					]
				]
			]
		]
	],
	'api' => [
		'routes' => [
			[
				'pattern' => 'updater/update',
				'method'  => 'POST',
				'action'  => fn () => Updater::forAdmin()->update()
			],
			[
				'pattern' => 'updater/rollback',
				'method'  => 'POST',
				'action'  => fn () => Updater::forAdmin()->rollback()
			]
		]
	]
]);
