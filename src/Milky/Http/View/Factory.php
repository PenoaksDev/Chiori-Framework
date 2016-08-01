<?php namespace Milky\Http\View;

use Closure;
use InvalidArgumentException;
use Milky\Binding\BindingBuilder;
use Milky\Framework;
use Milky\Helpers\Arr;
use Milky\Helpers\Str;
use Milky\Http\View\Compilers\BladeCompiler;
use Milky\Http\View\Engines\CompilerEngine;
use Milky\Http\View\Engines\EngineInterface;
use Milky\Http\View\Engines\EngineResolver;
use Milky\Http\View\Engines\PhpEngine;
use Milky\Impl\Arrayable;
use Milky\Services\ServiceFactory;

class Factory extends ServiceFactory
{
	/**
	 * The engine implementation.
	 *
	 * @var EngineResolver
	 */
	protected $engines;

	/**
	 * The view finder implementation.
	 *
	 * @var ViewFinderInterface
	 */
	protected $finder;

	/**
	 * Data that should be available to all templates.
	 *
	 * @var array
	 */
	protected $shared = [];

	/**
	 * Array of registered view name aliases.
	 *
	 * @var array
	 */
	protected $aliases = [];

	/**
	 * All of the registered view names.
	 *
	 * @var array
	 */
	protected $names = [];

	/**
	 * The extension to engine bindings.
	 *
	 * @var array
	 */
	protected $extensions = ['blade.php' => 'blade', 'php' => 'php'];

	/**
	 * The view composer events.
	 *
	 * @var array
	 */
	protected $composers = [];

	/**
	 * All of the finished, captured sections.
	 *
	 * @var array
	 */
	protected $sections = [];

	/**
	 * The stack of in-progress sections.
	 *
	 * @var array
	 */
	protected $sectionStack = [];

	/**
	 * All of the finished, captured push sections.
	 *
	 * @var array
	 */
	protected $pushes = [];

	/**
	 * The stack of in-progress push sections.
	 *
	 * @var array
	 */
	protected $pushStack = [];

	/**
	 * The number of active rendering operations.
	 *
	 * @var int
	 */
	protected $renderCount = 0;

	public static function build()
	{
		$fw = Framework::fw();

		$resolver = new EngineResolver();
		$fw['view.engine.resolver'] = $resolver;

		$resolver->register( 'php', function ()
		{
			return new PhpEngine;
		} );

		// The Compiler engine requires an instance of the CompilerInterface, which in
		// this case will be the Blade compiler, so we'll first create the compiler
		// instance to pass into the engine so it can compile the views properly.
		$fw['blade.compiler'] = function () use ( $fw )
		{
			$cache = $fw->config['view.compiled'];

			return new BladeCompiler( $fw['files'], $cache );
		};

		$resolver->register( 'blade', function () use ( $fw )
		{
			return new CompilerEngine( $fw['blade.compiler'] );
		} );

		$paths = $fw->config['view.paths'];
		$finder = new FileViewFinder( $fw['files'], $paths );
		$fw['view.finder'] = $finder;

		$factory = new Factory( $resolver, $finder );
		$fw['view.factory'] = $factory;
		return $factory;
	}

	/**
	 * Create a new view factory instance.
	 *
	 * @param  EngineResolver $engines
	 * @param  ViewFinderInterface $finder
	 *
	 */
	public function __construct( EngineResolver $engines, ViewFinderInterface $finder )
	{
		parent::__construct();

		$this->finder = $finder;
		$this->engines = $engines;

		$this->share( '__env', $this );
	}

	/**
	 * Get the evaluated view contents for the given view.
	 *
	 * @param  string $path
	 * @param  array $data
	 * @param  array $mergeData
	 * @return View
	 */
	public function file( $path, $data = [], $mergeData = [] )
	{
		$data = array_merge( $mergeData, $this->parseData( $data ) );

		$this->callCreator( $view = new View( $this, $this->getEngineFromPath( $path ), $path, $path, $data ) );

		return $view;
	}

	/**
	 * Get the evaluated view contents for the given view.
	 *
	 * @param  string $view
	 * @param  array $data
	 * @param  array $mergeData
	 * @return View
	 */
	public function make( $view, $data = [], $mergeData = [] )
	{
		if ( isset( $this->aliases[$view] ) )
		{
			$view = $this->aliases[$view];
		}

		$view = $this->normalizeName( $view );

		$path = $this->finder->find( $view );

		$data = array_merge( $mergeData, $this->parseData( $data ) );

		$this->callCreator( $view = new View( $this, $this->getEngineFromPath( $path ), $view, $path, $data ) );

		return $view;
	}

	/**
	 * Normalize a view name.
	 *
	 * @param  string $name
	 * @return string
	 */
	protected function normalizeName( $name )
	{
		$delimiter = ViewFinderInterface::HINT_PATH_DELIMITER;

		if ( strpos( $name, $delimiter ) === false )
		{
			return str_replace( '/', '.', $name );
		}

		list( $namespace, $name ) = explode( $delimiter, $name );

		return $namespace . $delimiter . str_replace( '/', '.', $name );
	}

	/**
	 * Parse the given data into a raw array.
	 *
	 * @param  mixed $data
	 * @return array
	 */
	protected function parseData( $data )
	{
		return $data instanceof Arrayable ? $data->toArray() : $data;
	}

	/**
	 * Get the evaluated view contents for a named view.
	 *
	 * @param  string $view
	 * @param  mixed $data
	 * @return View
	 */
	public function of( $view, $data = [] )
	{
		return $this->make( $this->names[$view], $data );
	}

	/**
	 * Register a named view.
	 *
	 * @param  string $view
	 * @param  string $name
	 *
	 */
	public function name( $view, $name )
	{
		$this->names[$name] = $view;
	}

	/**
	 * Add an alias for a view.
	 *
	 * @param  string $view
	 * @param  string $alias
	 *
	 */
	public function alias( $view, $alias )
	{
		$this->aliases[$alias] = $view;
	}

	/**
	 * Determine if a given view exists.
	 *
	 * @param  string $view
	 * @return bool
	 */
	public function exists( $view )
	{
		try
		{
			$this->finder->find( $view );
		}
		catch ( InvalidArgumentException $e )
		{
			return false;
		}

		return true;
	}

	/**
	 * Get the rendered contents of a partial from a loop.
	 *
	 * @param  string $view
	 * @param  array $data
	 * @param  string $iterator
	 * @param  string $empty
	 * @return string
	 */
	public function renderEach( $view, $data, $iterator, $empty = 'raw|' )
	{
		$result = '';

		// If is actually data in the array, we will loop through the data and append
		// an instance of the partial view to the final result HTML passing in the
		// iterated value of this data array, allowing the views to access them.
		if ( count( $data ) > 0 )
		{
			foreach ( $data as $key => $value )
			{
				$data = ['key' => $key, $iterator => $value];

				$result .= $this->make( $view, $data )->render();
			}
		}

		// If there is no data in the array, we will render the contents of the empty
		// view. Alternatively, the "empty view" could be a raw string that begins
		// with "raw|" for convenience and to let this know that it is a string.
		else
		{
			if ( Str::startsWith( $empty, 'raw|' ) )
			{
				$result = substr( $empty, 4 );
			}
			else
			{
				$result = $this->make( $empty )->render();
			}
		}

		return $result;
	}

	/**
	 * Get the appropriate view engine for the given path.
	 *
	 * @param  string $path
	 * @return EngineInterface
	 *
	 * @throws \InvalidArgumentException
	 */
	public function getEngineFromPath( $path )
	{
		if ( !$extension = $this->getExtension( $path ) )
		{
			throw new InvalidArgumentException( "Unrecognized extension in file: $path" );
		}

		$engine = $this->extensions[$extension];

		return $this->engines->resolve( $engine );
	}

	/**
	 * Get the extension used by the view file.
	 *
	 * @param  string $path
	 * @return string
	 */
	protected function getExtension( $path )
	{
		$extensions = array_keys( $this->extensions );

		return Arr::first( $extensions, function ( $key, $value ) use ( $path )
		{
			return Str::endsWith( $path, '.' . $value );
		} );
	}

	/**
	 * Add a piece of shared data to the environment.
	 *
	 * @param  array|string $key
	 * @param  mixed $value
	 * @return mixed
	 */
	public function share( $key, $value = null )
	{
		if ( !is_array( $key ) )
			return $this->shared[$key] = $value;

		foreach ( $key as $innerKey => $innerValue )
			$this->share( $innerKey, $innerValue );

		return null;
	}

	/**
	 * Register a view creator event.
	 *
	 * @param  array|string $views
	 * @param  \Closure|string $callback
	 * @return array
	 */
	public function creator( $views, $callback )
	{
		$creators = [];

		foreach ( (array) $views as $view )
		{
			$creators[] = $this->addViewEvent( $view, $callback, 'creating: ' );
		}

		return $creators;
	}

	/**
	 * Register multiple view composers via an array.
	 *
	 * @param  array $composers
	 * @return array
	 */
	public function composers( array $composers )
	{
		$registered = [];

		foreach ( $composers as $callback => $views )
			$registered = array_merge( $registered, $this->composer( $views, $callback ) );

		return $registered;
	}

	/**
	 * Register a view composer event.
	 *
	 * @param  array|string $views
	 * @param  \Closure|string $callback
	 * @return array
	 */
	public function composer( $views, $callback )
	{
		$composers = [];

		foreach ( (array) $views as $view )
			$composers[] = $this->addViewEvent( $view, $callback, 'composing' );

		return $composers;
	}

	/**
	 * Add an event for a given view.
	 *
	 * @param  string $view
	 * @param  \Closure|string $callback
	 * @param  string $prefix
	 * @return \Closure|null
	 */
	protected function addViewEvent( $view, $callback, $prefix = 'composing' )
	{
		$view = $this->normalizeName( $view );

		if ( $callback instanceof Closure )
		{
			$this->addEventListener( 'view.' . $prefix . '.' . $view, $callback );

			return $callback;
		}
		elseif ( is_string( $callback ) )
			return $this->addClassEvent( $view, $callback, $prefix );

		return null;
	}

	/**
	 * Register a class based view composer.
	 *
	 * @param  string $view
	 * @param  string $class
	 * @param  string $prefix
	 * @return \Closure
	 */
	protected function addClassEvent( $view, $class, $prefix )
	{
		$name = 'view.' . $prefix . '.' . $view;

		// When registering a class based view "composer", we will simply resolve the
		// classes from the application IoC container then call the compose method
		// on the instance. This allows for convenient, testable view composers.
		$callback = $this->buildClassEventCallback( $class, $prefix );

		$this->addEventListener( $name, $callback );

		return $callback;
	}

	/**
	 * Add a listener to the event dispatcher.
	 *
	 * @param  string $name
	 * @param  \Closure $callback
	 */
	protected function addEventListener( $name, $callback )
	{
		Framework::hooks()->addHook( $name, $callback );
	}

	/**
	 * Build a class based container callback Closure.
	 *
	 * @param  string $class
	 * @param  string $prefix
	 * @return \Closure
	 */
	protected function buildClassEventCallback( $class, $prefix )
	{
		list( $class, $method ) = $this->parseClassEvent( $class, $prefix );

		// Once we have the class and method name, we can build the Closure to resolve
		// the instance out of the IoC container and call the method on it with the
		// given arguments that are passed to the Closure as the composer's data.
		return function () use ( $class, $method )
		{
			$callable = [BindingBuilder::resolveBinding( $class ), $method];

			return call_user_func_array( $callable, func_get_args() );
		};
	}

	/**
	 * Parse a class based composer name.
	 *
	 * @param  string $class
	 * @param  string $prefix
	 * @return array
	 */
	protected function parseClassEvent( $class, $prefix )
	{
		if ( Str::contains( $class, '@' ) )
			return explode( '@', $class );

		$method = Str::contains( $prefix, 'composing' ) ? 'compose' : 'create';

		return [$class, $method];
	}

	/**
	 * Call the composer for a given view.
	 *
	 * @param  View $view
	 *
	 */
	public function callComposer( View $view )
	{
		Framework::hooks()->trigger( 'view.composing.' . $view->getName(), $view );
	}

	/**
	 * Call the creator for a given view.
	 *
	 * @param  View $view
	 *
	 */
	public function callCreator( View $view )
	{
		Framework::hooks()->trigger( 'view.creating.' . $view->getName(), $view );
	}

	/**
	 * Start injecting content into a section.
	 *
	 * @param  string $section
	 * @param  string $content
	 */
	public function startSection( $section, $content = '' )
	{
		if ( $content === '' )
		{
			if ( ob_start() )
			{
				$this->sectionStack[] = $section;
			}
		}
		else
		{
			$this->extendSection( $section, $content );
		}
	}

	/**
	 * Inject inline content into a section.
	 *
	 * @param  string $section
	 * @param  string $content
	 */
	public function inject( $section, $content )
	{
		$this->startSection( $section, $content );
	}

	/**
	 * Stop injecting content into a section and return its contents.
	 *
	 * @return string
	 */
	public function yieldSection()
	{
		if ( empty( $this->sectionStack ) )
		{
			return '';
		}

		return $this->yieldContent( $this->stopSection() );
	}

	/**
	 * Stop injecting content into a section.
	 *
	 * @param  bool $overwrite
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	public function stopSection( $overwrite = false )
	{
		if ( empty( $this->sectionStack ) )
		{
			throw new InvalidArgumentException( 'Cannot end a section without first starting one.' );
		}

		$last = array_pop( $this->sectionStack );

		if ( $overwrite )
		{
			$this->sections[$last] = ob_get_clean();
		}
		else
		{
			$this->extendSection( $last, ob_get_clean() );
		}

		return $last;
	}

	/**
	 * Stop injecting content into a section and append it.
	 *
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	public function appendSection()
	{
		if ( empty( $this->sectionStack ) )
		{
			throw new InvalidArgumentException( 'Cannot end a section without first starting one.' );
		}

		$last = array_pop( $this->sectionStack );

		if ( isset( $this->sections[$last] ) )
		{
			$this->sections[$last] .= ob_get_clean();
		}
		else
		{
			$this->sections[$last] = ob_get_clean();
		}

		return $last;
	}

	/**
	 * Append content to a given section.
	 *
	 * @param  string $section
	 * @param  string $content
	 *
	 */
	protected function extendSection( $section, $content )
	{
		if ( isset( $this->sections[$section] ) )
		{
			$content = str_replace( '@parent', $content, $this->sections[$section] );
		}

		$this->sections[$section] = $content;
	}

	/**
	 * Get the string contents of a section.
	 *
	 * @param  string $section
	 * @param  string $default
	 * @return string
	 */
	public function yieldContent( $section, $default = '' )
	{
		$sectionContent = $default;

		if ( isset( $this->sections[$section] ) )
		{
			$sectionContent = $this->sections[$section];
		}

		$sectionContent = str_replace( '@@parent', '--parent--holder--', $sectionContent );

		return str_replace( '--parent--holder--', '@parent', str_replace( '@parent', '', $sectionContent ) );
	}

	/**
	 * Start injecting content into a push section.
	 *
	 * @param  string $section
	 * @param  string $content
	 *
	 */
	public function startPush( $section, $content = '' )
	{
		if ( $content === '' )
		{
			if ( ob_start() )
			{
				$this->pushStack[] = $section;
			}
		}
		else
		{
			$this->extendPush( $section, $content );
		}
	}

	/**
	 * Stop injecting content into a push section.
	 *
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	public function stopPush()
	{
		if ( empty( $this->pushStack ) )
		{
			throw new InvalidArgumentException( 'Cannot end a section without first starting one.' );
		}

		$last = array_pop( $this->pushStack );

		$this->extendPush( $last, ob_get_clean() );

		return $last;
	}

	/**
	 * Append content to a given push section.
	 *
	 * @param  string $section
	 * @param  string $content
	 *
	 */
	protected function extendPush( $section, $content )
	{
		if ( !isset( $this->pushes[$section] ) )
		{
			$this->pushes[$section] = [];
		}
		if ( !isset( $this->pushes[$section][$this->renderCount] ) )
		{
			$this->pushes[$section][$this->renderCount] = $content;
		}
		else
		{
			$this->pushes[$section][$this->renderCount] .= $content;
		}
	}

	/**
	 * Get the string contents of a push section.
	 *
	 * @param  string $section
	 * @param  string $default
	 * @return string
	 */
	public function yieldPushContent( $section, $default = '' )
	{
		if ( !isset( $this->pushes[$section] ) )
		{
			return $default;
		}

		return implode( array_reverse( $this->pushes[$section] ) );
	}

	/**
	 * Flush all of the section contents.
	 *
	 *
	 */
	public function flushSections()
	{
		$this->renderCount = 0;

		$this->sections = [];
		$this->sectionStack = [];

		$this->pushes = [];
		$this->pushStack = [];
	}

	/**
	 * Flush all of the section contents if done rendering.
	 *
	 *
	 */
	public function flushSectionsIfDoneRendering()
	{
		if ( $this->doneRendering() )
		{
			$this->flushSections();
		}
	}

	/**
	 * Increment the rendering counter.
	 *
	 *
	 */
	public function incrementRender()
	{
		$this->renderCount++;
	}

	/**
	 * Decrement the rendering counter.
	 *
	 *
	 */
	public function decrementRender()
	{
		$this->renderCount--;
	}

	/**
	 * Check if there are no active render operations.
	 *
	 * @return bool
	 */
	public function doneRendering()
	{
		return $this->renderCount == 0;
	}

	/**
	 * Add a location to the array of view locations.
	 *
	 * @param  string $location
	 *
	 */
	public function addLocation( $location )
	{
		$this->finder->addLocation( $location );
	}

	/**
	 * Add a new namespace to the loader.
	 *
	 * @param  string $namespace
	 * @param  string|array $hints
	 *
	 */
	public function addNamespace( $namespace, $hints )
	{
		$this->finder->addNamespace( $namespace, $hints );
	}

	/**
	 * Prepend a new namespace to the loader.
	 *
	 * @param  string $namespace
	 * @param  string|array $hints
	 *
	 */
	public function prependNamespace( $namespace, $hints )
	{
		$this->finder->prependNamespace( $namespace, $hints );
	}

	/**
	 * Register a valid view extension and its engine.
	 *
	 * @param  string $extension
	 * @param  string $engine
	 * @param  \Closure $resolver
	 *
	 */
	public function addExtension( $extension, $engine, $resolver = null )
	{
		$this->finder->addExtension( $extension );

		if ( isset( $resolver ) )
		{
			$this->engines->register( $engine, $resolver );
		}

		unset( $this->extensions[$extension] );

		$this->extensions = array_merge( [$extension => $engine], $this->extensions );
	}

	/**
	 * Get the extension to engine bindings.
	 *
	 * @return array
	 */
	public function getExtensions()
	{
		return $this->extensions;
	}

	/**
	 * Get the engine resolver instance.
	 *
	 * @return EngineResolver
	 */
	public function getEngineResolver()
	{
		return $this->engines;
	}

	/**
	 * Get the view finder instance.
	 *
	 * @return ViewFinderInterface
	 */
	public function getFinder()
	{
		return $this->finder;
	}

	/**
	 * Set the view finder instance.
	 *
	 * @param  ViewFinderInterface $finder
	 */
	public function setFinder( ViewFinderInterface $finder )
	{
		$this->finder = $finder;
	}

	/**
	 * Get an item from the shared data.
	 *
	 * @param  string $key
	 * @param  mixed $default
	 * @return mixed
	 */
	public function shared( $key, $default = null )
	{
		return Arr::get( $this->shared, $key, $default );
	}

	/**
	 * Get all of the shared data for the environment.
	 *
	 * @return array
	 */
	public function getShared()
	{
		return $this->shared;
	}

	/**
	 * Check if section exists.
	 *
	 * @param  string $name
	 * @return bool
	 */
	public function hasSection( $name )
	{
		return array_key_exists( $name, $this->sections );
	}

	/**
	 * Get the entire array of sections.
	 *
	 * @return array
	 */
	public function getSections()
	{
		return $this->sections;
	}

	/**
	 * Get all of the registered named views in environment.
	 *
	 * @return array
	 */
	public function getNames()
	{
		return $this->names;
	}
}