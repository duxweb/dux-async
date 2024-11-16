<?php

namespace Core\Database\Connection;

use Illuminate\Database\SQLiteConnection as SqliteConnectionBase;

class SqliteConnection extends SqliteConnectionBase
{
    use TraitConnection;

    public function __construct(string $connector, array $config)
    {
        if ($config['database'] !== ':memory:') {
            $config['database'] = base_path($config['database']);
        }
        parent::__construct(...$this->initialize($connector, $config));
    }
}
