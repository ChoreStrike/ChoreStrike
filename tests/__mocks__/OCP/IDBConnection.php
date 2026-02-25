<?php

/**
 * Mock IDBConnection interface for testing
 */

namespace OCP;

interface IDBConnection {
    
    public function getQueryBuilder();
    
    public function insert(string $table, array $values);
    
    public function update(string $table, array $values, array $condition);
    
    public function delete(string $table, array $condition);
    
    public function lastInsertId();
}
