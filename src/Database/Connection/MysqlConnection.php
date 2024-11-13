<?php

namespace Core\Database\Connection;

use Illuminate\Database\MySqlConnection as MysqlConnectionBase;

class MysqlConnection extends MysqlConnectionBase
{
    use TraitConnection;

    public function __construct(string $connector, array $config)
    {
        parent::__construct(...$this->initialize($connector, $config));
    }

}
