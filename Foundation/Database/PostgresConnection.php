<?php

namespace Foundation\Database;

use Foundation\Database\Schema\PostgresBuilder;
use Doctrine\DBAL\Driver\PDOPgSql\Driver as DoctrineDriver;
use Foundation\Database\Query\Processors\PostgresProcessor;
use Foundation\Database\Query\Grammars\PostgresGrammar as QueryGrammar;
use Foundation\Database\Schema\Grammars\PostgresGrammar as SchemaGrammar;

class PostgresConnection extends Connection
{
	/**
	 * Get a schema builder instance for the connection.
	 *
	 * @return \Foundation\Database\Schema\PostgresBuilder
	 */
	public function getSchemaBuilder()
	{
		if (is_null($this->schemaGrammar))
{
			$this->useDefaultSchemaGrammar();
		}

		return new PostgresBuilder($this);
	}

	/**
	 * Get the default query grammar instance.
	 *
	 * @return \Foundation\Database\Query\Grammars\PostgresGrammar
	 */
	protected function getDefaultQueryGrammar()
	{
		return $this->withTablePrefix(new QueryGrammar);
	}

	/**
	 * Get the default schema grammar instance.
	 *
	 * @return \Foundation\Database\Schema\Grammars\PostgresGrammar
	 */
	protected function getDefaultSchemaGrammar()
	{
		return $this->withTablePrefix(new SchemaGrammar);
	}

	/**
	 * Get the default post processor instance.
	 *
	 * @return \Foundation\Database\Query\Processors\PostgresProcessor
	 */
	protected function getDefaultPostProcessor()
	{
		return new PostgresProcessor;
	}

	/**
	 * Get the Doctrine DBAL driver.
	 *
	 * @return \Doctrine\DBAL\Driver\PDOPgSql\Driver
	 */
	protected function getDoctrineDriver()
	{
		return new DoctrineDriver;
	}
}
