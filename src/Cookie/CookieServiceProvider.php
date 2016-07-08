<?php

namesapce Penoaks\Cookie;

use Foundation\Support\ServiceProvider;

class CookieServiceProvider extends ServiceProvider
{
	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->fw->bindings->singleton('cookie', function ($fw)
{
			$config = $fw->bindings['config']['session'];

			return (new CookieJar)->setDefaultPathAndDomain($config['path'], $config['domain'], $config['secure']);
		});
	}
}