<?php

namespace Foundation\Bus;

use Foundation\Support\ServiceProvider;

class BusServiceProvider extends ServiceProvider
{
	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = true;

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->fw->bindings->singleton('Foundation\Bus\Dispatcher', function ($fw)
{
			return new Dispatcher($fw, function ($connection = null) use ($fw)
{
				return $fw->bindings['Foundation\Contracts\Queue\Factory']->connection($connection);
			});
		});

		$this->fw->bindings->alias(
			'Foundation\Bus\Dispatcher', 'Foundation\Contracts\Bus\Dispatcher'
		);

		$this->fw->bindings->alias(
			'Foundation\Bus\Dispatcher', 'Foundation\Contracts\Bus\QueueingDispatcher'
		);
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return [
			'Foundation\Bus\Dispatcher',
			'Foundation\Contracts\Bus\Dispatcher',
			'Foundation\Contracts\Bus\QueueingDispatcher',
		];
	}
}
