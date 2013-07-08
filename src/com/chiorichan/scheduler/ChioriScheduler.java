package com.chiorichan.scheduler;

import java.util.ArrayList;
import java.util.Comparator;
import java.util.Iterator;
import java.util.List;
import java.util.PriorityQueue;
import java.util.concurrent.Callable;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.Executor;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicReference;
import java.util.logging.Level;

import org.apache.commons.lang3.Validate;

import com.chiorichan.plugin.Plugin;

/**
 * The fundamental concepts for this implementation: <li>Main thread owns {@link #head} and {@link #currentTick}, but it
 * may be read from any thread</li> <li>Main thread exclusively controls {@link #temp} and {@link #pending}. They are
 * never to be accessed outside of the main thread; alternatives exist to prevent locking.</li> <li>{@link #head} to
 * {@link #tail} act as a linked list/queue, with 1 consumer and infinite producers. Adding to the tail is atomic and
 * very efficient; utility method is {@link #handle(ChioriTask, long)} or {@link #addTask(ChioriTask)}.</li> <li>
 * Changing the period on a task is delicate. Any future task needs to notify waiting threads. Async tasks must be
 * synchronized to make sure that any thread that's finishing will remove itself from {@link #runners}. Another utility
 * method is provided for this, {@link #cancelTask(ChioriTask)}</li> <li>{@link #runners} provides a moderately
 * up-to-date view of active tasks. If the linked head to tail set is read, all remaining tasks that were active at the
 * time execution started will be located in runners.</li> <li>Async tasks are responsible for removing themselves from
 * runners</li> <li>Sync tasks are only to be removed from runners on the main thread when coupled with a removal from
 * pending and temp.</li> <li>Most of the design in this scheduler relies on queuing special tasks to perform any data
 * changes on the main thread. When executed from inside a synchronous method, the scheduler will be updated before next
 * execution by virtue of the frequent {@link #parsePending()} calls.</li>
 */
public class ChioriScheduler implements FrameworkScheduler
{
	
	/**
	 * Counter for IDs. Order doesn't matter, only uniqueness.
	 */
	private final AtomicInteger ids = new AtomicInteger( 1 );
	/**
	 * Current head of linked-list. This reference is always stale, {@link ChioriTask#next} is the live reference.
	 */
	private volatile ChioriTask head = new ChioriTask();
	/**
	 * Tail of a linked-list. AtomicReference only matters when adding to queue
	 */
	private final AtomicReference<ChioriTask> tail = new AtomicReference<ChioriTask>( head );
	/**
	 * Main thread logic only
	 */
	private final PriorityQueue<ChioriTask> pending = new PriorityQueue<ChioriTask>( 10, new Comparator<ChioriTask>()
	{
		public int compare( final ChioriTask o1, final ChioriTask o2 )
		{
			return (int) ( o1.getNextRun() - o2.getNextRun() );
		}
	} );
	/**
	 * Main thread logic only
	 */
	private final List<ChioriTask> temp = new ArrayList<ChioriTask>();
	/**
	 * These are tasks that are currently active. It's provided for 'viewing' the current state.
	 */
	private final ConcurrentHashMap<Integer, ChioriTask> runners = new ConcurrentHashMap<Integer, ChioriTask>();
	private volatile int currentTick = -1;
	private final Executor executor = Executors.newCachedThreadPool();
	private ChioriAsyncDebugger debugHead = new ChioriAsyncDebugger( -1, null, null )
	{
		@Override
		StringBuilder debugTo( StringBuilder string )
		{
			return string;
		}
	};
	private ChioriAsyncDebugger debugTail = debugHead;
	private static final int RECENT_TICKS;
	
	static
	{
		RECENT_TICKS = 30;
	}
	
	public int scheduleSyncDelayedTask( final Plugin plugin, final Runnable task )
	{
		return this.scheduleSyncDelayedTask( plugin, task, 0l );
	}
	
	public FrameworkTask runTask( Plugin plugin, Runnable runnable )
	{
		return runTaskLater( plugin, runnable, 0l );
	}
	
	public int scheduleAsyncDelayedTask( final Plugin plugin, final Runnable task )
	{
		return this.scheduleAsyncDelayedTask( plugin, task, 0l );
	}
	
	public FrameworkTask runTaskAsynchronously( Plugin plugin, Runnable runnable )
	{
		return runTaskLaterAsynchronously( plugin, runnable, 0l );
	}
	
	public int scheduleSyncDelayedTask( final Plugin plugin, final Runnable task, final long delay )
	{
		return this.scheduleSyncRepeatingTask( plugin, task, delay, -1l );
	}
	
	public FrameworkTask runTaskLater( Plugin plugin, Runnable runnable, long delay )
	{
		return runTaskTimer( plugin, runnable, delay, -1l );
	}
	
	public int scheduleAsyncDelayedTask( final Plugin plugin, final Runnable task, final long delay )
	{
		return this.scheduleAsyncRepeatingTask( plugin, task, delay, -1l );
	}
	
	public FrameworkTask runTaskLaterAsynchronously( Plugin plugin, Runnable runnable, long delay )
	{
		return runTaskTimerAsynchronously( plugin, runnable, delay, -1l );
	}
	
	public int scheduleSyncRepeatingTask( final Plugin plugin, final Runnable runnable, long delay, long period )
	{
		return runTaskTimer( plugin, runnable, delay, period ).getTaskId();
	}
	
	public FrameworkTask runTaskTimer( Plugin plugin, Runnable runnable, long delay, long period )
	{
		validate( plugin, runnable );
		if ( delay < 0l )
		{
			delay = 0;
		}
		if ( period == 0l )
		{
			period = 1l;
		}
		else if ( period < -1l )
		{
			period = -1l;
		}
		return handle( new ChioriTask( plugin, runnable, nextId(), period ), delay );
	}
	
	public int scheduleAsyncRepeatingTask( final Plugin plugin, final Runnable runnable, long delay, long period )
	{
		return runTaskTimerAsynchronously( plugin, runnable, delay, period ).getTaskId();
	}
	
	public FrameworkTask runTaskTimerAsynchronously( Plugin plugin, Runnable runnable, long delay, long period )
	{
		validate( plugin, runnable );
		if ( delay < 0l )
		{
			delay = 0;
		}
		if ( period == 0l )
		{
			period = 1l;
		}
		else if ( period < -1l )
		{
			period = -1l;
		}
		return handle( new ChioriAsyncTask( runners, plugin, runnable, nextId(), period ), delay );
	}
	
	public <T> Future<T> callSyncMethod( final Plugin plugin, final Callable<T> task )
	{
		validate( plugin, task );
		final ChioriFuture<T> future = new ChioriFuture<T>( task, plugin, nextId() );
		handle( future, 0l );
		return future;
	}
	
	public void cancelTask( final int taskId )
	{
		if ( taskId <= 0 )
		{
			return;
		}
		ChioriTask task = runners.get( taskId );
		if ( task != null )
		{
			task.cancel0();
		}
		task = new ChioriTask( new Runnable()
		{
			public void run()
			{
				if ( !check( ChioriScheduler.this.temp ) )
				{
					check( ChioriScheduler.this.pending );
				}
			}
			
			private boolean check( final Iterable<ChioriTask> collection )
			{
				final Iterator<ChioriTask> tasks = collection.iterator();
				while ( tasks.hasNext() )
				{
					final ChioriTask task = tasks.next();
					if ( task.getTaskId() == taskId )
					{
						task.cancel0();
						tasks.remove();
						if ( task.isSync() )
						{
							runners.remove( taskId );
						}
						return true;
					}
				}
				return false;
			}
		} );
		handle( task, 0l );
		for ( ChioriTask taskPending = head.getNext(); taskPending != null; taskPending = taskPending.getNext() )
		{
			if ( taskPending == task )
			{
				return;
			}
			if ( taskPending.getTaskId() == taskId )
			{
				taskPending.cancel0();
			}
		}
	}
	
	public void cancelTasks( final Plugin plugin )
	{
		Validate.notNull( plugin, "Cannot cancel tasks of null plugin" );
		final ChioriTask task = new ChioriTask( new Runnable()
		{
			public void run()
			{
				check( ChioriScheduler.this.pending );
				check( ChioriScheduler.this.temp );
			}
			
			void check( final Iterable<ChioriTask> collection )
			{
				final Iterator<ChioriTask> tasks = collection.iterator();
				while ( tasks.hasNext() )
				{
					final ChioriTask task = tasks.next();
					if ( task.getOwner().equals( plugin ) )
					{
						task.cancel0();
						tasks.remove();
						if ( task.isSync() )
						{
							runners.remove( task.getTaskId() );
						}
					}
				}
			}
		} );
		handle( task, 0l );
		for ( ChioriTask taskPending = head.getNext(); taskPending != null; taskPending = taskPending.getNext() )
		{
			if ( taskPending == task )
			{
				return;
			}
			if ( taskPending.getTaskId() != -1 && taskPending.getOwner().equals( plugin ) )
			{
				taskPending.cancel0();
			}
		}
		for ( ChioriTask runner : runners.values() )
		{
			if ( runner.getOwner().equals( plugin ) )
			{
				runner.cancel0();
			}
		}
	}
	
	public void cancelAllTasks()
	{
		final ChioriTask task = new ChioriTask( new Runnable()
		{
			public void run()
			{
				Iterator<ChioriTask> it = ChioriScheduler.this.runners.values().iterator();
				while ( it.hasNext() )
				{
					ChioriTask task = it.next();
					task.cancel0();
					if ( task.isSync() )
					{
						it.remove();
					}
				}
				ChioriScheduler.this.pending.clear();
				ChioriScheduler.this.temp.clear();
			}
		} );
		handle( task, 0l );
		for ( ChioriTask taskPending = head.getNext(); taskPending != null; taskPending = taskPending.getNext() )
		{
			if ( taskPending == task )
			{
				break;
			}
			taskPending.cancel0();
		}
		for ( ChioriTask runner : runners.values() )
		{
			runner.cancel0();
		}
	}
	
	public boolean isCurrentlyRunning( final int taskId )
	{
		final ChioriTask task = runners.get( taskId );
		if ( task == null || task.isSync() )
		{
			return false;
		}
		final ChioriAsyncTask asyncTask = (ChioriAsyncTask) task;
		synchronized ( asyncTask.getWorkers() )
		{
			return asyncTask.getWorkers().isEmpty();
		}
	}
	
	public boolean isQueued( final int taskId )
	{
		if ( taskId <= 0 )
		{
			return false;
		}
		for ( ChioriTask task = head.getNext(); task != null; task = task.getNext() )
		{
			if ( task.getTaskId() == taskId )
			{
				return task.getPeriod() >= -1l; // The task will run
			}
		}
		ChioriTask task = runners.get( taskId );
		return task != null && task.getPeriod() >= -1l;
	}
	
	public List<FrameworkWorker> getActiveWorkers()
	{
		final ArrayList<FrameworkWorker> workers = new ArrayList<FrameworkWorker>();
		for ( final ChioriTask taskObj : runners.values() )
		{
			// Iterator will be a best-effort (may fail to grab very new values) if called from an async thread
			if ( taskObj.isSync() )
			{
				continue;
			}
			final ChioriAsyncTask task = (ChioriAsyncTask) taskObj;
			synchronized ( task.getWorkers() )
			{
				// This will never have an issue with stale threads; it's state-safe
				workers.addAll( task.getWorkers() );
			}
		}
		return workers;
	}
	
	public List<FrameworkTask> getPendingTasks()
	{
		final ArrayList<ChioriTask> truePending = new ArrayList<ChioriTask>();
		for ( ChioriTask task = head.getNext(); task != null; task = task.getNext() )
		{
			if ( task.getTaskId() != -1 )
			{
				// -1 is special code
				truePending.add( task );
			}
		}
		
		final ArrayList<FrameworkTask> pending = new ArrayList<FrameworkTask>();
		for ( ChioriTask task : runners.values() )
		{
			if ( task.getPeriod() >= -1l )
			{
				pending.add( task );
			}
		}
		
		for ( final ChioriTask task : truePending )
		{
			if ( task.getPeriod() >= -1l && !pending.contains( task ) )
			{
				pending.add( task );
			}
		}
		return pending;
	}
	
	/**
	 * This method is designed to never block or wait for locks; an immediate execution of all current tasks.
	 */
	public void mainThreadHeartbeat( final int currentTick )
	{
		this.currentTick = currentTick;
		final List<ChioriTask> temp = this.temp;
		parsePending();
		while ( isReady( currentTick ) )
		{
			final ChioriTask task = pending.remove();
			if ( task.getPeriod() < -1l )
			{
				if ( task.isSync() )
				{
					runners.remove( task.getTaskId(), task );
				}
				parsePending();
				continue;
			}
			if ( task.isSync() )
			{
				try
				{
					task.run();
				}
				catch ( final Throwable throwable )
				{
					task.getOwner().getLogger().log( Level.WARNING, String.format( "Task #%s for %s generated an exception", task.getTaskId(), task.getOwner().getDescription().getFullName() ), throwable );
				}
				parsePending();
			}
			else
			{
				debugTail = debugTail.setNext( new ChioriAsyncDebugger( currentTick + RECENT_TICKS, task.getOwner(), task.getTaskClass() ) );
				executor.execute( task );
				// We don't need to parse pending
				// (async tasks must live with race-conditions if they attempt to cancel between these few lines of code)
			}
			final long period = task.getPeriod(); // State consistency
			if ( period > 0 )
			{
				task.setNextRun( currentTick + period );
				temp.add( task );
			}
			else if ( task.isSync() )
			{
				runners.remove( task.getTaskId() );
			}
		}
		pending.addAll( temp );
		temp.clear();
		debugHead = debugHead.getNextHead( currentTick );
	}
	
	private void addTask( final ChioriTask task )
	{
		final AtomicReference<ChioriTask> tail = this.tail;
		ChioriTask tailTask = tail.get();
		while ( !tail.compareAndSet( tailTask, task ) )
		{
			tailTask = tail.get();
		}
		tailTask.setNext( task );
	}
	
	private ChioriTask handle( final ChioriTask task, final long delay )
	{
		task.setNextRun( currentTick + delay );
		addTask( task );
		return task;
	}
	
	private static void validate( final Plugin plugin, final Object task )
	{
		Validate.notNull( plugin, "Plugin cannot be null" );
		Validate.notNull( task, "Task cannot be null" );
	}
	
	private int nextId()
	{
		return ids.incrementAndGet();
	}
	
	private void parsePending()
	{
		ChioriTask head = this.head;
		ChioriTask task = head.getNext();
		ChioriTask lastTask = head;
		for ( ; task != null; task = ( lastTask = task ).getNext() )
		{
			if ( task.getTaskId() == -1 )
			{
				task.run();
			}
			else if ( task.getPeriod() >= -1l )
			{
				pending.add( task );
				runners.put( task.getTaskId(), task );
			}
		}
		// We split this because of the way things are ordered for all of the async calls in ChioriScheduler
		// (it prevents race-conditions)
		for ( task = head; task != lastTask; task = head )
		{
			head = task.getNext();
			task.setNext( null );
		}
		this.head = lastTask;
	}
	
	private boolean isReady( final int currentTick )
	{
		return !pending.isEmpty() && pending.peek().getNextRun() <= currentTick;
	}
	
	@Override
	public String toString()
	{
		int debugTick = currentTick;
		StringBuilder string = new StringBuilder( "Recent tasks from " ).append( debugTick - RECENT_TICKS ).append( '-' ).append( debugTick ).append( '{' );
		debugHead.debugTo( string );
		return string.append( '}' ).toString();
	}
}