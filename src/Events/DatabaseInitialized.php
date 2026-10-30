<?php

declare(strict_types=1);

namespace Medas\PdoStorage\Events;

use Medas\PdoStorage\Database;

/**
 * A database got a (new) controller and connection. Sent again when a storage is replaced
 * under the same name (StorageManager::add()), so whoever keeps a controller by the
 * database's name lets go of the old one.
 */
class DatabaseInitialized
{
    public function __construct(
        public Database $database,
    )
    {
    }
}
