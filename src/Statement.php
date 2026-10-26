<?php

declare(strict_types=1);

namespace Medas\PdoStorage;

use Medas\StorageManager\{
    Entities\Record,
    Interfaces\RecordSet,
    Interfaces\RecordSetMetaData,
    Type
};

// The result of one execution of a PreparedStatement. Rows are read from the driver
// until the statement runs again for another query: then the rows not read yet are
// taken over first (see PreparedStatement) and read from here.
class Statement implements RecordSet
{
    /** @var array<int, array<int, mixed>>|null */
    private array|null $takenOverRows = null;

    private int|null $takenOverRowCount = null;

    public function __construct(
        private readonly PreparedStatement $preparedStatement,
    )
    {
    }

    public function takeOverUnreadRows(): void
    {
        // Taken over before: the statement may have run again since, for another reader
        if ($this->takenOverRows !== null) {
            return;
        }

        $pdoStatement = $this->preparedStatement->pdoStatement;
        $this->takenOverRowCount = $pdoStatement->rowCount();

        // A statement without a result set, such as an insert, has no rows to take over
        $this->takenOverRows = $pdoStatement->columnCount() > 0
            ? $pdoStatement->fetchAll(\PDO::FETCH_NUM)
            : [];
    }

    public function fetchRecord(): Record|null
    {
        if ($this->takenOverRows === null) {
            $data = $this->preparedStatement->pdoStatement->fetch(\PDO::FETCH_ASSOC);

            return is_array($data) ? new Record($data) : null;
        }

        $row = array_shift($this->takenOverRows);

        // Keyed like PDO::FETCH_ASSOC: of a repeated column name, the last value stays
        return $row === null
            ? null
            : new Record(array_combine($this->preparedStatement->columnNames(), $row));
    }

    public function fetchRecords(): array
    {
        $rows = $this->takenOverRows ?? $this->preparedStatement->pdoStatement->fetchAll(\PDO::FETCH_NUM);
        $columnNames = $this->preparedStatement->uniqueColumnNames();
        $records = [];

        if ($this->takenOverRows !== null) {
            $this->takenOverRows = [];
        }

        foreach ($rows as $row) {
            $records[] = new Record(array_combine($columnNames, $row));
        }

        return $records;
    }

    public function fetchFirstColumn(): array
    {
        if ($this->takenOverRows === null) {
            return $this->preparedStatement->pdoStatement->fetchAll(\PDO::FETCH_COLUMN);
        }

        $values = array_column($this->takenOverRows, 0);
        $this->takenOverRows = [];

        return $values;
    }

    public function hasRecords(): bool
    {
        return $this->preparedStatement->pdoStatement->columnCount() > 0;
    }

    public function fetchMetaData(): RecordSetMetaData
    {
        $pdoStatement = $this->preparedStatement->pdoStatement;
        $metaData = new MetaData\MetaData();

        for ($column = 0; $column < $pdoStatement->columnCount(); ++$column) {
            $data = $pdoStatement->getColumnMeta($column);
            $type = match ($data['native_type']) {
                'BOOLEAN' => Type::Boolean,
                'DOUBLE', 'LONG', 'TINY', 'FLOAT', 'NEWDECIMAL', 'INT24' => Type::Integer,
                'BLOB', 'LONGBLOB' => Type::Binary,
                'DATETIME', 'TIMESTAMP' => Type::DateTime,
                'DATE' => Type::Date,
                default => Type::Text,
            };

            $metaData->fields[] = new MetaData\ColumnData(
                $data['name'],
                $type,
                $data['len'],
                $data['precision'],
                true,
            );

            $metaData->fieldNames[] = $data['name'];
        }

        $metaData->rowCount = $this->takenOverRowCount ?? $pdoStatement->rowCount();

        return $metaData;
    }
}
