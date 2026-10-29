<?php

namespace App\Support;

use Illuminate\Database\QueryException;

class UniqueViolation
{
    /**
     * PostgreSQL reports 23505, SQLite reports 23000 for a unique index conflict.
     */
    public static function matches(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23505', '23000'], true);
    }
}
