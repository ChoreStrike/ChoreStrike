<?php

/**
 * Mock IDBConnection interface for testing
 */

namespace OCP;

interface IDBConnection
{
    public const PLATFORM_MYSQL = 'mysql';
    public const PLATFORM_ORACLE = 'oracle';
    public const PLATFORM_POSTGRES = 'postgres';
    public const PLATFORM_SQLITE = 'sqlite';

    public function getQueryBuilder();

    public function insert(string $table, array $values);

    public function update(string $table, array $values, array $condition);

    public function delete(string $table, array $condition);

    public function lastInsertId();

    public function beginTransaction();

    public function commit();

    public function rollBack();

    public function inTransaction(): bool;

    public function getDatabaseProvider(): string;

    /**
     * @return \OCP\DB\IResult
     */
    public function executeQuery(string $sql, array $params = [], $types = []);
}
