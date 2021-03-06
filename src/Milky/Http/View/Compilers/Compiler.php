<?php namespace Milky\Http\View\Compilers;

use InvalidArgumentException;
use Milky\Facades\Log;
use Milky\Filesystem\Filesystem;
use Milky\Framework;

abstract class Compiler
{
	/**
	 * The Filesystem instance.
	 *
	 * @var Filesystem
	 */
	protected $files;

	/**
	 * Get the cache path for the compiled views.
	 *
	 * @var string
	 */
	protected $cachePath;

	/**
	 * Create a new compiler instance.
	 *
	 * @param  Filesystem $files
	 * @param  string $cachePath
	 * @return void
	 *
	 * @throws \InvalidArgumentException
	 */
	public function __construct( Filesystem $files, $cachePath )
	{
		if ( !$cachePath )
			throw new InvalidArgumentException( 'The cache path is not properly configured.' );

		$this->files = $files;
		$this->cachePath = $cachePath;
	}

	/**
	 * Get the path to the compiled version of a view.
	 *
	 * @param  string $path
	 * @return string
	 */
	public function getCompiledPath( $path )
	{
		$views = Framework::fw()->buildPath( "__views" );

		if ( starts_with($path, $views) )
			$path = substr( $path, strlen( $views ) + 1 );

		if ( !ends_with( $path, '.php' ) )
			$path .= '.php';

		// $path = substr( $path, 0, strrpos( $path, "." ) );

		$path = $this->cachePath . DIRECTORY_SEPARATOR . $path;

		@mkdir( dirname( $path ), 0755, true );

		return $path;

		// return $this->cachePath . '/' . str_replace( ["/", "\\"], "-", dirname( $path ) ) . '-' . basename( $path ) . '.php';

		// return $this->cachePath . '/' . sha1( $path ) . '.php';
	}

	/**
	 * Determine if the view at the given path is expired.
	 *
	 * @param  string $path
	 * @return bool
	 */
	public function isExpired( $path )
	{
		$compiled = $this->getCompiledPath( $path );

		// If the compiled file doesn't exist we will indicate that the view is expired
		// so that it can be re-compiled. Else, we will verify the last modification
		// of the views is less than the modification times of the compiled views.
		if ( !$this->files->exists( $compiled ) )
		{
			return true;
		}

		$lastModified = $this->files->lastModified( $path );

		return $lastModified >= $this->files->lastModified( $compiled );
	}
}
