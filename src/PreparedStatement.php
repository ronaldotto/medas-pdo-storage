<?php

declare(strict_types=1);

namespace Medas\PdoStorage;

// A statement prepared once and executed for every query with the same SQL, so the
// database parses and plans it once. Each execution is read through its own Statement.
// Rows are often read after other queries have run, so before the statement runs again,
// the reader of the previous execution takes over its unread rows and keeps its result.
class PreparedStatement
{
    private \WeakReference|null $lastReader = null;

    /** @var string[]|null */
    private array|null $columnNames = null;

    /** @var string[]|null */
    private array|null $uniqueColumnNames = null;

    public function __construct(
        public readonly \PDOStatement $pdoStatement,
    )
    {
    }

    public function execute(array $arguments): Statement
    {
        $this->release();
        $this->pdoStatement->execute($arguments);

        $statement = new Statement($this);
        $this->lastReader = \WeakReference::create($statement);

        return $statement;
    }

    // Hands the unread rows of the last execution to its reader, which finishes the statement
    // on the database. SQLite refuses to change the schema while a statement is unfinished.
    public function release(): void
    {
        $lastReader = $this->lastReader?->get();

        if ($lastReader !== null) {
            $lastReader->takeOverUnreadRows();
        }
        else {
            // Nobody reads the rows anymore
            $this->pdoStatement->closeCursor();
        }
    }

    // The columns are the same for every execution, so the driver is asked once.
    /** @return string[] As the driver names them, so a name can occur more than once. */
    public function columnNames(): array
    {
        if ($this->columnNames === null) {
            $this->columnNames = [];

            for ($column = 0; $column < $this->pdoStatement->columnCount(); ++$column) {
                $this->columnNames[] = $this->pdoStatement->getColumnMeta($column)['name'];
            }
        }

        return $this->columnNames;
    }

    /** @return string[] With a repeated name prefixed by its table, so every column keeps its value. */
    public function uniqueColumnNames(): array
    {
        if ($this->uniqueColumnNames === null) {
            $this->uniqueColumnNames = [];

            foreach ($this->columnNames() as $column => $name) {
                if (in_array($name, $this->uniqueColumnNames, true)) {
                    /** @noinspection PhpArrayKeyDoesNotMatchArrayShapeInspection */
                    $name = $this->pdoStatement->getColumnMeta($column)['table'] . '.' . $name;
                }

                $this->uniqueColumnNames[] = $name;
            }
        }

        return $this->uniqueColumnNames;
    }
}
