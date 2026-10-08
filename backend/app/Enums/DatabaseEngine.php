<?php

namespace App\Enums;

enum DatabaseEngine: string
{
    case PostgreSQL = 'postgresql';
    case MySQL = 'mysql';
    case MariaDB = 'mariadb';
    case SQLite = 'sqlite';
    case SQLServer = 'sqlserver';
    case MongoDB = 'mongodb';
    case Redis = 'redis';
    case Other = 'other';
}
