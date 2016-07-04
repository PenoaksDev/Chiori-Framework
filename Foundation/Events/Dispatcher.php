<?php
namespace Foundation\Events;

use Exception;
use ReflectionClass;
use Foundation\Support\Str;
use Foundation\Framework;
use Foundation\Contracts\Broadcasting\ShouldBroadcast;
use Foundation\Contracts\Broadcasting\ShouldBroadcastNow;
use Foundation\Contracts\Events\Dispatcher as DispatcherContract;
use Foundation\Contracts\Bindings\Bindings as BindingsContract;

class Dispatcher implements DispatcherContract
{
	/**
	 * The IoC bindings instance.
	 *
	 * @var \Foundation\Framework
	 */
	protected $bindings;

	/**
	 * The registered event listeners.
	 *
	 * @var array
	 */
	protected $listeners = [];

	/**
	 * The wildcard listeners.
	 *
	 * @var array
	 */
	protected $wildcards = [];

	/**
	 * The sorted event listeners.
	 *
	 * @var array
	 */
	protected $sorted = [];

	/**
	 * The event firing stack.
	 *
	 * @var array
	 */
	protected $firing = [];

	/**
	 * The queue resolver instance.
	 *
	 * @var callable
	 */
	protected $queueResolver;

	/**
	 * Create a new event dispatcher instance.
	 *
	 * @param  \Foundation\Framework|null  $bindings
	 * @return void
	 */
	public function __construct(BindingsContract $bindings = null)
	{
		$this->bindings = $bindings ?: new Bindings;
	}

	/**
	 * Scans the provided instance for methods that can receive events
	 */
	public function listenEvents( $listener, $priority = 0 )
	{
		$reflection = new ReflectionClass( $listener );
		$bindings = $this->bindings;

		foreach( $reflection->getMethods() as $method )
		{
			if ( preg_match( "/on.*Event/", $method->getName() ) )
			{
				if ( $method->getNumberOfParameters() > 0 )
				{
					foreach ( $method->getParameters() as $param )
					{
						if ( $param->getName() == "event" )
						{
							$method->setAccessible( true );
							$this->listen( $param->getClass()->getName(), function ( $event ) use ( $listener, $method, $bindings )
{
								$params = $method->getParameters();
								$provided = compact( 'event' );

								$provided = $bindings->keyParametersByArgument( $params, $provided );
								$args = $bindings->getDependencies( $params, $provided, get_class( $listener ) . ":" . $method->getName() );

								return $method->invokeArgs( $listener, $args );
							}, $priority);
							break;
						}
					}
				}
			}
		}
	}

	/**
	 * Register an event listener with the dispatcher.
	 *
	 * @param  string|array  $events
	 * @param  mixed  $listener
	 * @param  int  $priority
	 * @return void
	 */
	public function listen($events, $listener, $priority = 0)
	{
		foreach ((array) $events as $event)
{
			if (Str::contains($event, '*'))
{
				$this->setupWildcardListen($event, $listener);
			}
else
{
				$this->listeners[$event][$priority][] = $this->makeListener($listener);

				unset($this->sorted[$event]);
			}
		}
	}

	/**
	 * Setup a wildcard listener callback.
	 *
	 * @param  string  $event
	 * @param  mixed  $listener
	 * @return void
	 */
	protected function setupWildcardListen($event, $listener)
	{
		$this->wildcards[$event][] = $this->makeListener($listener);
	}

	/**
	 * Determine if a given event has listeners.
	 *
	 * @param  string  $eventName
	 * @return bool
	 */
	public function hasListeners($eventName)
	{
		return isset($this->listeners[$eventName]) || isset($this->wildcards[$eventName]);
	}

	/**
	 * Register an event and payload to be fired later.
	 *
	 * @param  string  $event
	 * @param  array  $payload
	 * @return void
	 */
	public function push($event, $payload = [])
	{
		$this->listen($event.'_pushed', function () use ($event, $payload)
{
			$this->fire($event, $payload);
		});
	}

	/**
	 * Register an event subscriber with the dispatcher.
	 *
	 * @param  object|string  $subscriber
	 * @return void
	 */
	public function subscribe($subscriber)
	{
		$subscriber = $this->resolveSubscriber($subscriber);

		$subscriber->subscribe($this);
	}

	/**
	 * Resolve the subscriber instance.
	 *
	 * @param  object|string  $subscriber
	 * @return mixed
	 */
	protected function resolveSubscriber($subscriber)
	{
		if (is_string($subscriber))
{
			return $this->bindings->make($subscriber);
		}

		return $subscriber;
	}

	/**
	 * Fire an event until the first non-null response is returned.
	 *
	 * @param  string|object  $event
	 * @param  array  $payload
	 * @return mixed
	 */
	public function until($event, $payload = [])
	{
		return $this->fire($event, $payload, true);
	}

	/**
	 * Flush a set of pushed events.
	 *
	 * @param  string  $event
	 * @return void
	 */
	public function flush($event)
	{
		$this->fire($event.'_pushed');
	}

	/**
	 * Get the event that is currently firing.
	 *
	 * @return string
	 */
	public function firing()
	{
		return last($this->firing);
	}

	/**
	 * Fire an event and call the listeners.
	 *
	 * @param  string|object  $event
	 * @param  mixed  $payload
	 * @param  bool  $halt
	 * @return array|null
	 */
	public function fire($event, $payload = [], $halt = false)
	{
		// When the given "event" is actually an object we will assume it is an event
		// object and use the class as the event name and this event itself as the
		// payload to the handler, which makes object based events quite simple.
		if (is_object($event))
{
			list($payload, $event) = [[$event], get_class($event)];
		}

		$responses = [];

		// If an array is not given to us as the payload, we will turn it into one so
		// we can easily use call_user_func_array on the listeners, passing in the
		// payload to each of them so that they receive each of these arguments.
		if (! is_array($payload))
{
			$payload = [$payload];
		}

		$this->firing[] = $event;

		if (isset($payload[0]) && $payload[0] instanceof ShouldBroadcast)
{
			$this->broadcastEvent($payload[0]);
		}

		foreach ($this->getListeners($event) as $listener)
{
			$response = call_user_func_array($listener, $payload);

			// If a response is returned from the listener and event halting is enabled
			// we will just return this response, and not call the rest of the event
			// listeners. Otherwise we will add the response on the response list.
			if (! is_null($response) && $halt)
{
				array_pop($this->firing);

				return $response;
			}

			// If a boolean false is returned from a listener, we will stop propagating
			// the event to any further listeners down in the chain, else we keep on
			// looping through the listeners and firing every one in our sequence.
			if ($response === false)
{
				break;
			}

			$responses[] = $response;
		}

		array_pop($this->firing);

		return $halt ? null : $responses;
	}

	/**
	 * Broadcast the given event class.
	 *
	 * @param  \Foundation\Contracts\Broadcasting\ShouldBroadcast  $event
	 * @return void
	 */
	protected function broadcastEvent($event)
	{
		if ($this->queueResolver)
{
			$connection = $event instanceof ShouldBroadcastNow ? 'sync' : null;

			$queue = method_exists($event, 'onQueue') ? $event->onQueue() : null;

			$this->resolveQueue()->connection($connection)->pushOn($queue, 'Foundation\Broadcasting\BroadcastEvent', [
				'event' => serialize(clone $event),
			]);
		}
	}

	/**
	 * Get all of the listeners for a given event name.
	 *
	 * @param  string  $eventName
	 * @return array
	 */
	public function getListeners($eventName)
	{
		$wildcards = $this->getWildcardListeners($eventName);

		if (! isset($this->sorted[$eventName]))
{
			$this->sortListeners($eventName);
		}

		return array_merge($this->sorted[$eventName], $wildcards);
	}

	/**
	 * Get the wildcard listeners for the event.
	 *
	 * @param  string  $eventName
	 * @return array
	 */
	protected function getWildcardListeners($eventName)
	{
		$wildcards = [];

		foreach ($this->wildcards as $key => $listeners)
{
			if (Str::is($key, $eventName))
{
				$wildcards = array_merge($wildcards, $listeners);
			}
		}

		return $wildcards;
	}

	/**
	 * Sort the listeners for a given event by priority.
	 *
	 * @param  string  $eventName
	 * @return array
	 */
	protected function sortListeners($eventName)
	{
		$this->sorted[$eventName] = [];

		// If listeners exist for the given event, we will sort them by the priority
		// so that we can call them in the correct order. We will cache off these
		// sorted event listeners so we do not have to re-sort on every events.
		if (isset($this->listeners[$eventName]))
{
			krsort($this->listeners[$eventName]);

			$this->sorted[$eventName] = call_user_func_array(
				'array_merge', $this->listeners[$eventName]
			);
		}
	}

	/**
	 * Register an event listener with the dispatcher.
	 *
	 * @param  mixed  $listener
	 * @return mixed
	 */
	public function makeListener($listener)
	{
		return is_string($listener) ? $this->createClassListener($listener) : $listener;
	}

	/**
	 * Create a class based listener using the IoC bindings.
	 *
	 * @param  mixed  $listener
	 * @return \Closure
	 */
	public function createClassListener($listener)
	{
		$bindings = $this->bindings;

		return function () use ($listener, $bindings)
{
			return call_user_func_array(
				$this->createClassCallable($listener, $bindings), func_get_args()
			);
		};
	}

	/**
	 * Create the class based event callable.
	 *
	 * @param  string  $listener
	 * @param  \Foundation\Framework  $bindings
	 * @return callable
	 */
	protected function createClassCallable($listener, $bindings)
	{
		list($class, $method) = $this->parseClassCallable($listener);

		if ($this->handlerShouldBeQueued($class))
{
			return $this->createQueuedHandlerCallable($class, $method);
		}
else
{
			return [$bindings->make($class), $method];
		}
	}

	/**
	 * Parse the class listener into class and method.
	 *
	 * @param  string  $listener
	 * @return array
	 */
	protected function parseClassCallable($listener)
	{
		$segments = explode('@', $listener);

		return [$segments[0], count($segments) == 2 ? $segments[1] : 'handle'];
	}

	/**
	 * Determine if the event handler class should be queued.
	 *
	 * @param  string  $class
	 * @return bool
	 */
	protected function handlerShouldBeQueued($class)
	{
		try
{
			return (new ReflectionClass($class))->implementsInterface(
				'Foundation\Contracts\Queue\ShouldQueue'
			);
		} catch (Exception $e)
{
			return false;
		}
	}

	/**
	 * Create a callable for putting an event handler on the queue.
	 *
	 * @param  string  $class
	 * @param  string  $method
	 * @return \Closure
	 */
	protected function createQueuedHandlerCallable($class, $method)
	{
		return function () use ($class, $method)
{
			$arguments = $this->cloneArgumentsForQueueing(func_get_args());

			if (method_exists($class, 'queue'))
{
				$this->callQueueMethodOnHandler($class, $method, $arguments);
			}
else
{
				$this->resolveQueue()->push('Foundation\Events\CallQueuedHandler@call', [
					'class' => $class, 'method' => $method, 'data' => serialize($arguments),
				]);
			}
		};
	}

	/**
	 * Clone the given arguments for queueing.
	 *
	 * @param  array  $arguments
	 * @return array
	 */
	protected function cloneArgumentsForQueueing(array $arguments)
	{
		return array_map(function ($a)
{
			return is_object($a) ? clone $a : $a;
		}, $arguments);
	}

	/**
	 * Call the queue method on the handler class.
	 *
	 * @param  string  $class
	 * @param  string  $method
	 * @param  array  $arguments
	 * @return void
	 */
	protected function callQueueMethodOnHandler($class, $method, $arguments)
	{
		$handler = (new ReflectionClass($class))->newInstanceWithoutFramework();

		$handler->queue($this->resolveQueue(), 'Foundation\Events\CallQueuedHandler@call', [
			'class' => $class, 'method' => $method, 'data' => serialize($arguments),
		]);
	}

	/**
	 * Remove a set of listeners from the dispatcher.
	 *
	 * @param  string  $event
	 * @return void
	 */
	public function forget($event)
	{
		if (Str::contains($event, '*'))
{
			unset($this->wildcards[$event]);
		}
else
{
			unset($this->listeners[$event], $this->sorted[$event]);
		}
	}

	/**
	 * Forget all of the pushed listeners.
	 *
	 * @return void
	 */
	public function forgetPushed()
	{
		foreach ($this->listeners as $key => $value)
{
			if (Str::endsWith($key, '_pushed'))
{
				$this->forget($key);
			}
		}
	}

	/**
	 * Get the queue implementation from the resolver.
	 *
	 * @return \Foundation\Contracts\Queue\Queue
	 */
	protected function resolveQueue()
	{
		return call_user_func($this->queueResolver);
	}

	/**
	 * Set the queue resolver implementation.
	 *
	 * @param  callable  $resolver
	 * @return $this
	 */
	public function setQueueResolver(callable $resolver)
	{
		$this->queueResolver = $resolver;

		return $this;
	}
}
