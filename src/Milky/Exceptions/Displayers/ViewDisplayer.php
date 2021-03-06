<?php namespace Milky\Exceptions\Displayers;

use Exception;
use Milky\Exceptions\ExceptionInfo;
use Milky\Http\View\ViewFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * This is the view displayer class.
 */
class ViewDisplayer implements DisplayerInterface
{
	/**
	 * The exception info instance.
	 *
	 * @var ExceptionInfo
	 */
	protected $info;

	/**
	 * The view factory instance.
	 *
	 * @var ViewFactory
	 */
	protected $factory;

	/**
	 * Create a new view displayer instance.
	 *
	 * @param ExceptionInfo $info
	 * @param ViewFactory $factory
	 */
	public function __construct( ExceptionInfo $info, ViewFactory $factory )
	{
		$this->info = $info;
		$this->factory = $factory;
	}

	/**
	 * Get the error response associated with the given exception.
	 *
	 * @param \Exception $exception
	 * @param string $id
	 * @param int $code
	 * @param string[] $headers
	 *
	 * @return Response
	 */
	public function display( Exception $exception, $id, $code, array $headers )
	{
		$info = $this->info->generate( $exception, $id, $code );

		return new Response( $this->factory->make( "errors.{$code}", $info ), $code, array_merge( $headers, ['Content-Type' => $this->contentType()] ) );
	}

	/**
	 * Get the supported content type.
	 *
	 * @return string
	 */
	public function contentType()
	{
		return 'text/html';
	}

	/**
	 * Can we display the exception?
	 *
	 * @param \Exception $original
	 * @param \Exception $transformed
	 * @param int $code
	 *
	 * @return bool
	 */
	public function canDisplay( Exception $original, Exception $transformed, $code )
	{
		return $this->factory->exists( "errors.{$code}" );
	}

	/**
	 * Do we provide verbose information about the exception?
	 *
	 * @return bool
	 */
	public function isVerbose()
	{
		return false;
	}
}
