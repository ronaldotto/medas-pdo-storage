<?php

declare(strict_types=1);

namespace Medas\PdoStorageTest\Functional;

use Medas\PdoStorage\PreparedStatement;
use PHPUnit\Framework\TestCase;

// A prepared statement runs again for the next query with the same SQL. The reader of an
// earlier execution must keep its own result, also when it had not read all of it yet.
// Runs on SQLite in memory, which reads rows from the database as they are fetched.
class PreparedStatementTest extends TestCase
{
    private \PDO $pdo;

    public function testAReaderKeepsItsRowsWhenTheStatementRunsAgain(): void
    {
        $preparedStatement = $this->prepare('select id, name from authors where id >= ? order by id');
        $earlier = $preparedStatement->execute([1]);

        self::assertSame(['id' => 1, 'name' => 'Ada'], $earlier->fetchRecord()->data());

        $later = $preparedStatement->execute([3]);

        self::assertSame([['id' => 3, 'name' => 'Edsger']], $this->dataOf($later->fetchRecords()));
        self::assertSame(['id' => 2, 'name' => 'Grace'], $earlier->fetchRecord()->data());
        self::assertSame(['id' => 3, 'name' => 'Edsger'], $earlier->fetchRecord()->data());
        self::assertNull($earlier->fetchRecord());
    }

    public function testTakenOverRowsCanBeReadAllAtOnce(): void
    {
        $preparedStatement = $this->prepare('select id from authors where id >= ? order by id');
        $earlier = $preparedStatement->execute([2]);

        $preparedStatement->execute([1]);

        self::assertSame([['id' => 2], ['id' => 3]], $this->dataOf($earlier->fetchRecords()));
        self::assertSame([], $earlier->fetchRecords());
    }

    public function testTakenOverRowsCanBeReadAsAColumn(): void
    {
        $preparedStatement = $this->prepare('select name from authors order by id');
        $earlier = $preparedStatement->execute([]);

        $preparedStatement->execute([]);

        self::assertSame(['Ada', 'Grace', 'Edsger'], $earlier->fetchFirstColumn());
    }

    // fetchRecords() keeps both values of a repeated column name, the second under its
    // table's name, before and after the rows are taken over.
    public function testARepeatedColumnNameIsKeptApart(): void
    {
        $preparedStatement
            = $this->prepare('select authors.name, books.name from books join authors on authors.id = books.author where books.id = ?');

        $earlier = $preparedStatement->execute([1]);
        $later = $preparedStatement->execute([1]);
        $expected = [['name' => 'Ada', 'books.name' => 'Notes']];

        self::assertSame($expected, $this->dataOf($later->fetchRecords()));
        self::assertSame($expected, $this->dataOf($earlier->fetchRecords()));
    }

    /** @return array<int, array<string, mixed>> */
    private function dataOf(array $records): array
    {
        return array_map(fn($record) => $record->data(), $records);
    }

    public function testAStatementWithoutAResultSetCanRunAgain(): void
    {
        $preparedStatement = $this->prepare('insert into authors (id, name) values (?, ?)');
        $first = $preparedStatement->execute([4, 'Barbara']);

        $preparedStatement->execute([5, 'Donald']);

        self::assertFalse($first->hasRecords());
        self::assertSame(5, (int) $this->pdo->query('select count(*) from authors')->fetchColumn());
    }

    private function prepare(string $sql): PreparedStatement
    {
        return new PreparedStatement($this->pdo->prepare($sql));
    }

    protected function setUp(): void
    {
        $this->pdo = new \PDO(
            'sqlite::memory:',
            options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $this->pdo->exec('create table authors (id integer primary key, name text)');
        $this->pdo->exec('create table books (id integer primary key, name text, author integer)');
        $this->pdo->exec("insert into authors (id, name) values (1, 'Ada'), (2, 'Grace'), (3, 'Edsger')");
        $this->pdo->exec("insert into books (id, name, author) values (1, 'Notes', 1)");
    }
}
