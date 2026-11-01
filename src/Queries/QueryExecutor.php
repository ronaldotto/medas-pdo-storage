<?php

declare(strict_types=1);

namespace Medas\PdoStorage\Queries;

use Medas\Core\{
    Attributes\ConfigValue,
    Attributes\EventListener,
    Attributes\Service,
    ConfigOptions\DispatchDebugInformation,
    Events\DebugInformation,
    Interfaces\Serializer
};
use Medas\PdoStorage\{
    Database,
    DatabaseController,
    Events\DatabaseControllerRequest,
    Events\DatabaseInitialized,
    Exceptions\PdoDatabase,
    PreparedStatement
};
use Medas\StorageManager\{
    Entities\LastInsertIdPlaceholder,
    Interfaces\ActionExecutor,
    UnitOfWork\Action,
    UnitOfWork\ActionSet
};

#[Service]
class QueryExecutor implements ActionExecutor
{
    // Each one keeps a statement open on the database server, so a long-running process
    // keeps the ones it used most recently rather than every query it ever ran.
    private const int MAX_PREPARED_STATEMENTS_PER_CONNECTION = 100;

    /** @var DatabaseController[] The controller per database, keyed by the database's name. */
    private array $databaseControllers = [];

    /** @var \WeakMap<\PDO, array<string, PreparedStatement>> Per connection, keyed by the SQL. */
    private \WeakMap $preparedStatements;

    public function __construct(
        #[ConfigValue(DispatchDebugInformation::class)]
        private readonly bool $dispatchDebugInformation = false,
    )
    {
        $this->preparedStatements = new \WeakMap();
    }

    public function execute(Action $action, ActionSet|null $actionSet = null): void
    {
        /** @var Database $storage */
        $storage = $action->storage();

        /** @var Query $action */
        $databaseController = $this->databaseController($storage);
        $pdo = $databaseController->pdo;

        $this->serializeArguments($action, $databaseController->driverHandler->serializer());

        try {
            if ($this->changesSchema($action->query)) {
                $this->releasePreparedStatements($pdo);
            }

            $preparedStatement = $this->preparedStatement($pdo, $action->query);

            if ($this->dispatchDebugInformation) {
                dispatch(new DebugInformation(
                    '[pdo-storage] executing query %s with arguments %s',
                    $action->query,
                    $action->serializedArguments
                ));
            }

            $action->statement = $preparedStatement->execute($action->serializedArguments);
            $lastInsertId = $pdo->lastInsertId();

            if ($lastInsertId && $actionSet) {
                $actionSet->lastInsertId = $lastInsertId;
            }
        }
        catch (\Exception|\Error $e) {
            throw new PdoDatabase(
                $e->getMessage(),
                $action,
                $e,
                $databaseController->driverHandler->exceptionTypeFinder()->find($e)
            );
        }

        if ($onComplete = $action->onComplete()) {
            $onComplete($action->storage(), $actionSet ? $actionSet->lastInsertId : $lastInsertId);
        }
    }

    /**
     * The controller cannot be injected: PdoStorageController depends on this class, so it
     * is asked for by the event. It is asked for once per database and then kept, because this
     * runs for every statement. PdoStorageController keeps its controllers by the same key.
     */
    private function databaseController(Database $database): DatabaseController
    {
        return $this->databaseControllers[$database->name] ??= dispatch(new DatabaseControllerRequest($database))->databaseController;
    }

    // The database was replaced under the same name: its next statement asks for the new
    // controller.
    #[EventListener]
    public function forgetController(DatabaseInitialized $event): void
    {
        $previous = $this->databaseControllers[$event->database->name] ?? null;

        // Its prepared statements are dropped explicitly. Each one holds its connection,
        // so the weak map's key would never be let go, and every replaced connection
        // would stay open until the process ends ("Too many connections").
        if ($previous !== null) {
            $this->releasePreparedStatements($previous->pdo);
            unset($this->preparedStatements[$previous->pdo]);
        }

        unset($this->databaseControllers[$event->database->name]);
    }

    /**
     * Serializes with the driver's own serializer, taken from the controller that is already
     * in hand, and not by dispatching an event per argument: a statement has many arguments
     * and a request has many statements.
     */
    private function serializeArguments(Query $query, Serializer $serializer): void
    {
        foreach ($query->arguments as $key => $argument) {
            if ($argument instanceof LastInsertIdPlaceholder) {
                $argument = $argument->value();
            }

            $query->serializedArguments[$key] = $serializer->serialize($argument);
        }
    }

    private function changesSchema(string $sql): bool
    {
        return (bool) preg_match('/^\s*(?:create|alter|drop)\b/i', $sql);
    }

    /**
     * Finishes every cached statement of the connection that still has unread rows. Their
     * readers keep the rows. SQLite refuses to drop or alter a table while a statement on
     * the same connection is unfinished ("database table is locked").
     */
    private function releasePreparedStatements(\PDO $pdo): void
    {
        foreach ($this->preparedStatements[$pdo] ?? [] as $preparedStatement) {
            $preparedStatement->release();
        }
    }

    /**
     * Prepares a query's SQL on its first use on a connection and reuses it after that, which
     * saves a round trip to the database server per query (prepares are not emulated).
     */
    private function preparedStatement(\PDO $pdo, string $sql): PreparedStatement
    {
        $statements = $this->preparedStatements[$pdo] ?? [];

        if (array_key_exists($sql, $statements)) {
            $preparedStatement = $statements[$sql];

            // Moved to the end, so the limit drops the one used longest ago
            unset($statements[$sql]);
        }
        else {
            $preparedStatement = new PreparedStatement($pdo->prepare($sql));

            if (count($statements) >= self::MAX_PREPARED_STATEMENTS_PER_CONNECTION) {
                unset($statements[array_key_first($statements)]);
            }
        }

        $statements[$sql] = $preparedStatement;
        $this->preparedStatements[$pdo] = $statements;

        return $preparedStatement;
    }

    public function executeSet(ActionSet $actionSet): void
    {
        foreach ($actionSet as $action) {
            $this->execute($action, $actionSet);

            $actionSet->lastRecordSet = $action->recordSet();
        }
    }
}
