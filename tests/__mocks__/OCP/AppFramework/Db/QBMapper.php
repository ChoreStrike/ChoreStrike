<?php

/**
 * Mock QBMapper for testing
 */

namespace OCP\AppFramework\Db;

use OCP\IDBConnection;

abstract class QBMapper {
    
    protected IDBConnection $db;
    protected string $tableName;
    
    public function __construct(IDBConnection $db, string $tableName) {
        $this->db = $db;
        $this->tableName = $tableName;
    }
    
    public function find(string $id) {
        return null;
    }
    
    public function findAll() {
        return [];
    }
    
    public function delete($entity) {
        return $entity;
    }
}
