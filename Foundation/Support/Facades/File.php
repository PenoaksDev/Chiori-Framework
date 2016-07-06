<?php

namespace Foundation\Support\Facades;

/**
 * @see \Foundation\Filesystem\Filesystem
 */
class File extends Facade
{
	/**
	 * Get the registered name of the component.
	 *
	 * @return string
	 */
	protected static function getFacadeAccessor()
	{
		return 'files';
	}
}