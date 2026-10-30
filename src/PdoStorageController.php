<?php

declare(strict_types=1);

namespace Medas\PdoStorage;

use Medas\Core\{Attributes\EventListener, Attributes\Service, Interfaces\Serializer};
use Medas\StorageManager\{
    Exceptions\NoDefaultStorageFound,
    Interfaces\ActionBuilders,
    Interfaces\ActionExecutor,
    Interfaces\RecordFetchers,
    Interfaces\Storage,
    Interfaces\StorageController,
    Interfaces\Store
};

#[Service]
class PdoStorageController implements StorageController
{
    private Database|null $defaultDatabase = null;

    /** @var DatabaseController[] */
    private array $controllers = [];

    public function __construct(
        private readonly DatabaseControllerInitializer $controllerInitializer,
        private readonly Queries\QueryExecutor         $queryExecutor,
    )
    {
    }

    public function getDatabaseController(Database $database): DatabaseController
    {
        if (!array_key_exists($database->name, $this->controllers)) {
            $this->initialize($database);
        }

        return $this->controllers[$database->name];
    }

    public function initialize(Storage $storage): void
    {
        /** @var Database $storage */
        $this->controllers[$storage->name] = $this->controllerInitializer->initialize($storage);

        if (count($this->controllers) === 1) {
            $this->defaultDatabase = $storage;
        }

        dispatch(new Events\DatabaseInitialized($storage));
    }

    public function handles(Storage $storage): bool
    {
        return $storage instanceof Database;
    }

    public function transaction(Storage|null $storage = null): Transaction
    {
        return $this->getDatabaseController($this->resolveDatabase($storage))->transaction;
    }

    public function store(string $name, Storage|null $storage = null): Table
    {
        $storage = $this->resolveDatabase($storage);

        return $this->getDatabaseController($storage)->driverHandler
            ->table($storage, $name);
    }

    public function deleteStore(Store $store): void
    {
        /** @var Table $store->storage() */
        $querySet = $this->getDatabaseController($store->storage())->driverHandler
            ->queryBuilders()->deleteStore()->build($store);

        $this->actionExecutor()->executeSet($querySet);
    }

    public function actionBuilders(): ActionBuilders
    {
        return $this->getDatabaseController($this->resolveDatabase())->driverHandler
            ->queryBuilders();
    }

    public function actionExecutor(): ActionExecutor
    {
        return $this->queryExecutor;
    }

    public function serializer(Storage|null $storage = null): Serializer
    {
        return $this->getDatabaseController($this->resolveDatabase($storage))->driverHandler
            ->serializer();
    }

    public function lastGeneratedValue(Storage|null $storage = null): int|null
    {
        $id = $this->getDatabaseController($this->resolveDatabase($storage))->pdo->lastInsertId();

        return $id === false ? null : (int) $id;
    }

    public function getStores(Storage|null $storage = null, string|null $nameFilter = null): array
    {
        $storage = $this->resolveDatabase($storage);
        $controller = $this->getDatabaseController($storage);
        $querySet = $controller->driverHandler
            ->queryBuilders()->showTables()->build($storage, $nameFilter);

        $this->actionExecutor()->executeSet($querySet);

        $stores = [];

        while ($record = $querySet->lastRecordSet->fetchRecord()) {
            $stores[] = $this->store(array_values($record->data())[0], $storage);
        }

        return $stores;
    }

    public function hasStore(Store $store, Storage|null $storage = null): bool
    {
        $storage ??= $store->storage();

        return (bool) $this->getStores($storage, $store->name());
    }

    public function recordFetchers(): RecordFetchers
    {
        return $this->getDatabaseController($this->resolveDatabase())->driverHandler
            ->recordFetchers();
    }

    private function resolveDatabase(Storage|null $storage = null): Database
    {
        if ($storage instanceof Database) {
            return $storage;
        }

        if ($this->defaultDatabase !== null) {
            return $this->defaultDatabase;
        }

        throw new NoDefaultStorageFound();
    }

    public function quote(Database $database, string $identifier): string
    {
        return $this->getDatabaseController($database)->driverHandler->quote(
            $database,
            $identifier
        );
    }

    public function escape(Database $database, mixed $value): string
    {
        return $this->getDatabaseController($database)->driverHandler->escape($database, $value);
    }

    #[EventListener]
    public function controllerRequestHandler(Events\DatabaseControllerRequest $request): void
    {
        $request->databaseController = $this->getDatabaseController($request->database);
    }

    #[EventListener]
    public function quoteRequestHandler(Events\QuoteIdentifierRequest $request): void
    {
        /** @var Database $storage */
        $storage = $request->storage;
        $request->quotedIdentifier = $this->quote($storage, $request->identifier);
    }

    #[EventListener]
    public function escapeRequestHandler(Events\EscapeValueRequest $request): void
    {
        /** @var Database $storage */
        $storage = $request->storage;
        $request->escapedValue = $this->escape($storage, $request->value);
    }

    #[EventListener]
    public function executeSetHandler(Events\ExecuteSetRequest $request): void
    {
        $this->actionExecutor()->executeSet($request->querySet);
    }

    #[EventListener]
    public function getStoreHandler(Events\GetStoreRequest $request): void
    {
        $request->store = $this->store($request->name, $request->storage);
    }
}
