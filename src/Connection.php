<?php namespace Illuminate\Database;

use Illuminate\Database\Query\Grammars\Grammar;
use PDO;
use Closure;
use DateTime;

class Connection implements ConnectionInterface {

	/**
	 * The active PDO connection.
	 *
	 * @var PDO
	 */
	protected $pdo;

	/**
	 * The active PDO connection used for reads.
	 *
	 * @var PDO
	 */
	protected $readPdo;

	/**
	 * The reconnector instance for the connection.
	 *
	 * @var callable
	 */
	protected $reconnector;

	/**
	 * The query grammar implementation.
	 *
	 * @var \Illuminate\Database\Query\Grammars\Grammar
	 */
	protected $queryGrammar;

	/**
	 * The default fetch mode of the connection.
	 *
	 * @var int
	 */
	protected $fetchMode = PDO::FETCH_ASSOC;

	/**
	 * The number of active transactions.
	 *
	 * @var int
	 */
	protected $transactions = 0;

	/**
	 * All of the queries run against the connection.
	 *
	 * @var array
	 */
	protected $queryLog = array();

	/**
	 * Indicates whether queries are being logged.
	 *
	 * @var bool
	 */
	protected $loggingQueries = true;

	/**
	 * Indicates if the connection is in a "dry run".
	 *
	 * @var bool
	 */
	protected $pretending = false;


	/**
	 * The table prefix for the connection.
	 *
	 * @var string
	 */
	protected $tablePrefix = '';

	/**
	 * Create a new database connection instance.
	 *
	 * @param  \PDO     $pdo
	 * @param  string   $tablePrefix
	 * @return void
	 */
	public function __construct(PDO $pdo, Grammar $queryGrammar = null, $tablePrefix = '')
	{
		$this->pdo = $pdo;

		$this->queryGrammar = $queryGrammar ?: new Grammar();

        $this->tablePrefix = $tablePrefix;
	}

	/**
	 * Begin a fluent query against a database table.
	 *
	 * @param  string  $table
	 * @return \Illuminate\Database\Query\Builder
	 */
	public function table($table)
	{
		$query = new Query\Builder($this, $this->getQueryGrammar());

		return $query->from($table);
	}

	/**
	 * Get a new raw query expression.
	 *
	 * @param  mixed  $value
	 * @return \Illuminate\Database\Query\Expression
	 */
	public function raw($value)
	{
		return new Query\Expression($value);
	}

	/**
	 * Run a select statement and return a single result.
	 *
	 * @param  string  $query
	 * @param  array   $bindings
	 * @return mixed
	 */
	public function selectOne($query, $bindings = array())
	{
		$records = $this->select($query, $bindings);

		return count($records) > 0 ? reset($records) : null;
	}

	/**
	 * Run a select statement against the database.
	 *
	 * @param  string  $query
	 * @param  array   $bindings
	 * @return array
	 */
	public function selectFromWriteConnection($query, $bindings = array())
	{
		return $this->select($query, $bindings, false);
	}

	/**
	 * Run a select statement against the database.
	 *
	 * @param  string  $query
	 * @param  array  $bindings
	 * @param  bool  $useReadPdo
	 * @return array
	 */
	public function select($query, $bindings = array(), $useReadPdo = true)
	{
		return $this->run($query, $bindings, function($me, $query, $bindings) use ($useReadPdo)
		{
			if ($me->pretending()) return array();

			// For select statements, we'll simply execute the query and return an array
			// of the database result set. Each element in the array will be a single
			// row from the database table, and will either be an array or objects.
			$statement = $this->getPdoForSelect($useReadPdo)->prepare($query);

			$statement->execute($me->prepareBindings($bindings));

			return $statement->fetchAll($me->getFetchMode());
		});
	}

	/**
	 * Get the PDO connection to use for a select query.
	 *
	 * @param  bool  $useReadPdo
	 * @return \PDO
	 */
	protected function getPdoForSelect($useReadPdo = true)
	{
		return $useReadPdo ? $this->getReadPdo() : $this->getPdo();
	}

	/**
	 * Run an insert statement against the database.
	 *
	 * @param  string  $query
	 * @param  array   $bindings
	 * @return bool
	 */
	public function insert($query, $bindings = array())
	{
		return $this->statement($query, $bindings);
	}

	/**
	 * Run an update statement against the database.
	 *
	 * @param  string  $query
	 * @param  array   $bindings
	 * @return int
	 */
	public function update($query, $bindings = array())
	{
		return $this->affectingStatement($query, $bindings);
	}

	/**
	 * Run a delete statement against the database.
	 *
	 * @param  string  $query
	 * @param  array   $bindings
	 * @return int
	 */
	public function delete($query, $bindings = array())
	{
		return $this->affectingStatement($query, $bindings);
	}

	/**
	 * Execute an SQL statement and return the boolean result.
	 *
	 * @param  string  $query
	 * @param  array   $bindings
	 * @return bool
	 */
	public function statement($query, $bindings = array())
	{
		return $this->run($query, $bindings, function($me, $query, $bindings)
		{
			if ($me->pretending()) return true;

			$bindings = $me->prepareBindings($bindings);

			return $me->getPdo()->prepare($query)->execute($bindings);
		});
	}

	/**
	 * Run an SQL statement and get the number of rows affected.
	 *
	 * @param  string  $query
	 * @param  array   $bindings
	 * @return int
	 */
	public function affectingStatement($query, $bindings = array())
	{
		return $this->run($query, $bindings, function($me, $query, $bindings)
		{
			if ($me->pretending()) return 0;

			// For update or delete statements, we want to get the number of rows affected
			// by the statement and return that back to the developer. We'll first need
			// to execute the statement and then we'll use PDO to fetch the affected.
			$statement = $me->getPdo()->prepare($query);

			$statement->execute($me->prepareBindings($bindings));

			return $statement->rowCount();
		});
	}

	/**
	 * Run a raw, unprepared query against the PDO connection.
	 *
	 * @param  string  $query
	 * @return bool
	 */
	public function unprepared($query)
	{
		return $this->run($query, array(), function($me, $query)
		{
			if ($me->pretending()) return true;

			return (bool) $me->getPdo()->exec($query);
		});
	}

	/**
	 * Prepare the query bindings for execution.
	 *
	 * @param  array  $bindings
	 * @return array
	 */
	public function prepareBindings(array $bindings)
	{
		$grammar = $this->getQueryGrammar();

		foreach ($bindings as $key => $value)
		{
			// We need to transform all instances of the DateTime class into an actual
			// date string. Each query grammar maintains its own date string format
			// so we'll just ask the grammar for the format to get from the date.
			if ($value instanceof DateTime)
			{
				$bindings[$key] = $value->format($grammar->getDateFormat());
			}
			elseif ($value === false)
			{
				$bindings[$key] = 0;
			}
		}

		return $bindings;
	}

	/**
	 * Execute a Closure within a transaction.
	 *
	 * @param  \Closure  $callback
	 * @return mixed
	 *
	 * @throws \Exception
	 */
	public function transaction(Closure $callback)
	{
		$this->beginTransaction();

		// We'll simply execute the given callback within a try / catch block
		// and if we catch any exception we can rollback the transaction
		// so that none of the changes are persisted to the database.
		try
		{
			$result = $callback($this);

			$this->commit();
		}

		// If we catch an exception, we will roll back so nothing gets messed
		// up in the database. Then we'll re-throw the exception so it can
		// be handled how the developer sees fit for their applications.
		catch (\Exception $e)
		{
			$this->rollBack();

			throw $e;
		}

		return $result;
	}

	/**
	 * Start a new database transaction.
	 *
	 * @return void
	 */
	public function beginTransaction()
	{
		++$this->transactions;

		if ($this->transactions == 1)
		{
			$this->pdo->beginTransaction();
		}
	}

	/**
	 * Commit the active database transaction.
	 *
	 * @return void
	 */
	public function commit()
	{
		if ($this->transactions == 1) $this->pdo->commit();

		--$this->transactions;
	}

	/**
	 * Rollback the active database transaction.
	 *
	 * @return void
	 */
	public function rollBack()
	{
		if ($this->transactions == 1)
		{
			$this->transactions = 0;

			$this->pdo->rollBack();
		}
		else
		{
			--$this->transactions;
		}
	}

	/**
	 * Get the number of active transactions.
	 *
	 * @return int
	 */
	public function transactionLevel()
	{
		return $this->transactions;
	}

	/**
	 * Execute the given callback in "dry run" mode.
	 *
	 * @param  \Closure  $callback
	 * @return array
	 */
	public function pretend(Closure $callback)
	{
		$this->pretending = true;

		$this->queryLog = array();

		// Basically to make the database connection "pretend", we will just return
		// the default values for all the query methods, then we will return an
		// array of queries that were "executed" within the Closure callback.
		$callback($this);

		$this->pretending = false;

		return $this->queryLog;
	}

	/**
	 * Run a SQL statement and log its execution context.
	 *
	 * @param  string    $query
	 * @param  array     $bindings
	 * @param  \Closure  $callback
	 * @return mixed
	 *
	 * @throws \Illuminate\Database\QueryException
	 */
	protected function run($query, $bindings, Closure $callback)
	{
		$this->reconnectIfMissingConnection();

		$start = microtime(true);

		// Here we will run this query. If an exception occurs we'll determine if it was
		// caused by a connection that has been lost. If that is the cause, we'll try
		// to re-establish connection and re-run the query with a fresh connection.
		try
		{
			$result = $this->runQueryCallback($query, $bindings, $callback);
		}
		catch (QueryException $e)
		{
			$result = $this->tryAgainIfCausedByLostConnection(
				$e, $query, $bindings, $callback
			);
		}

		// Once we have run the query we will calculate the time that it took to run and
		// then log the query, bindings, and execution time so we will report them on
		// the event that the developer needs them. We'll log time in milliseconds.
		$time = $this->getElapsedTime($start);

		$this->logQuery($query, $bindings, $time);

		return $result;
	}

	/**
	 * Run a SQL statement.
	 *
	 * @param  string    $query
	 * @param  array     $bindings
	 * @param  \Closure  $callback
	 * @return mixed
	 *
	 * @throws \Illuminate\Database\QueryException
	 */
	protected function runQueryCallback($query, $bindings, Closure $callback)
	{
		// To execute the statement, we'll simply call the callback, which will actually
		// run the SQL against the PDO connection. Then we can calculate the time it
		// took to execute and log the query SQL, bindings and time in our memory.
		try
		{
			$result = $callback($this, $query, $bindings);
		}

		// If an exception occurs when attempting to run a query, we'll format the error
		// message to include the bindings with SQL, which will make this exception a
		// lot more helpful to the developer instead of just the database's errors.
		catch (\Exception $e)
		{
			throw new QueryException(
				$query, $this->prepareBindings($bindings), $e
			);
		}

		return $result;
	}

	/**
	 * Handle a query exception that occurred during query execution.
	 *
	 * @param  \Illuminate\Database\QueryException  $e
	 * @param  string    $query
	 * @param  array     $bindings
	 * @param  \Closure  $callback
	 * @return mixed
	 *
	 * @throws \Illuminate\Database\QueryException
	 */
	protected function tryAgainIfCausedByLostConnection(QueryException $e, $query, $bindings, Closure $callback)
	{
		if ($this->causedByLostConnection($e))
		{
			$this->reconnect();

			return $this->runQueryCallback($query, $bindings, $callback);
		}

		throw $e;
	}

	/**
	 * Determine if the given exception was caused by a lost connection.
	 *
	 * @param  \Illuminate\Database\QueryException
	 * @return bool
	 */
	protected function causedByLostConnection(QueryException $e)
	{
		return str_contains($e->getPrevious()->getMessage(), 'server has gone away');
	}

	/**
	 * Disconnect from the underlying PDO connection.
	 *
	 * @return void
	 */
	public function disconnect()
	{
		$this->setPdo(null)->setReadPdo(null);
	}

	/**
	 * Reconnect to the database.
	 *
	 * @return void
	 *
	 * @throws \LogicException
	 */
	public function reconnect()
	{
		if (is_callable($this->reconnector))
		{
			return call_user_func($this->reconnector, $this);
		}

		throw new \LogicException("Lost connection and no reconnector available.");
	}

	/**
	 * Reconnect to the database if a PDO connection is missing.
	 *
	 * @return void
	 */
	protected function reconnectIfMissingConnection()
	{
		if (is_null($this->getPdo()) || is_null($this->getReadPdo()))
		{
			$this->reconnect();
		}
	}

	/**
	 * Log a query in the connection's query log.
	 *
	 * @param  string  $query
	 * @param  array   $bindings
	 * @param  $time
	 * @return void
	 */
	public function logQuery($query, $bindings, $time = null)
	{
		if ( ! $this->loggingQueries) return;

		$this->queryLog[] = compact('query', 'bindings', 'time');
	}

	/**
	 * Get the elapsed time since a given starting point.
	 *
	 * @param  int    $start
	 * @return float
	 */
	protected function getElapsedTime($start)
	{
		return round((microtime(true) - $start) * 1000, 2);
	}

	/**
	 * Get the current PDO connection.
	 *
	 * @return \PDO
	 */
	public function getPdo()
	{
		return $this->pdo;
	}

	/**
	 * Get the current PDO connection used for reading.
	 *
	 * @return \PDO
	 */
	public function getReadPdo()
	{
		if ($this->transactions >= 1) return $this->getPdo();

		return $this->readPdo ?: $this->pdo;
	}

	/**
	 * Set the PDO connection.
	 *
	 * @param  \PDO|null  $pdo
	 * @return $this
	 */
	public function setPdo($pdo)
	{
		$this->pdo = $pdo;

		return $this;
	}

	/**
	 * Set the PDO connection used for reading.
	 *
	 * @param  \PDO|null  $pdo
	 * @return $this
	 */
	public function setReadPdo($pdo)
	{
		$this->readPdo = $pdo;

		return $this;
	}

	/**
	 * Set the reconnect instance on the connection.
	 *
	 * @param  callable  $reconnector
	 * @return $this
	 */
	public function setReconnector(callable $reconnector)
	{
		$this->reconnector = $reconnector;

		return $this;
	}

	/**
	 * Get the PDO driver name.
	 *
	 * @return string
	 */
	public function getDriverName()
	{
		return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
	}

	/**
	 * Get the query grammar used by the connection.
	 *
	 * @return \Illuminate\Database\Query\Grammars\Grammar
	 */
	public function getQueryGrammar()
	{
		return $this->queryGrammar;
	}

	/**
	 * Set the query grammar used by the connection.
	 *
	 * @param  \Illuminate\Database\Query\Grammars\Grammar
	 * @return void
	 */
	public function setQueryGrammar(Query\Grammars\Grammar $grammar)
	{
		$this->queryGrammar = $grammar;
	}

	/**
	 * Determine if the connection in a "dry run".
	 *
	 * @return bool
	 */
	public function pretending()
	{
		return $this->pretending === true;
	}

	/**
	 * Get the default fetch mode for the connection.
	 *
	 * @return int
	 */
	public function getFetchMode()
	{
		return $this->fetchMode;
	}

	/**
	 * Set the default fetch mode for the connection.
	 *
	 * @param  int  $fetchMode
	 * @return int
	 */
	public function setFetchMode($fetchMode)
	{
		$this->fetchMode = $fetchMode;
	}

	/**
	 * Get the connection query log.
	 *
	 * @return array
	 */
	public function getQueryLog()
	{
		return $this->queryLog;
	}

	/**
	 * Clear the query log.
	 *
	 * @return void
	 */
	public function flushQueryLog()
	{
		$this->queryLog = array();
	}

	/**
	 * Enable the query log on the connection.
	 *
	 * @return void
	 */
	public function enableQueryLog()
	{
		$this->loggingQueries = true;
	}

	/**
	 * Disable the query log on the connection.
	 *
	 * @return void
	 */
	public function disableQueryLog()
	{
		$this->loggingQueries = false;
	}

	/**
	 * Determine whether we're logging queries.
	 *
	 * @return bool
	 */
	public function logging()
	{
		return $this->loggingQueries;
	}

	/**
	 * Get the table prefix for the connection.
	 *
	 * @return string
	 */
	public function getTablePrefix()
	{
		return $this->tablePrefix;
	}

	/**
	 * Set the table prefix in use by the connection.
	 *
	 * @param  string  $prefix
	 * @return void
	 */
	public function setTablePrefix($prefix)
	{
		$this->tablePrefix = $prefix;

		$this->getQueryGrammar()->setTablePrefix($prefix);
	}

	/**
	 * Set the table prefix and return the grammar.
	 *
	 * @param  \Illuminate\Database\Grammar  $grammar
	 * @return \Illuminate\Database\Grammar
	 */
	public function withTablePrefix(Grammar $grammar)
	{
		$grammar->setTablePrefix($this->tablePrefix);

		return $grammar;
	}

}