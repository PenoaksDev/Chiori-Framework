<?php

namespace Foundation\Console;

use Foundation\Console\Command;

class EnvironmentCommand extends Command
{
	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'env';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Display the current framework environment';

	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	public function fire()
	{
		$this->line('<info>Current application environment:</info> <comment>'.$this->framework['env'].'</comment>');
	}
}
