<?php

/**
 * Mock QBMapper for testing
 * Executes queries against the mocked IQueryBuilder/IResult and maps rows to entities.
 */

namespace OCP\AppFramework\Db;

use OCP\IDBConnection;

abstract class QBMapper {
	protected IDBConnection $db;
	protected string $tableName;
	protected string $entityClass;

	public function __construct(IDBConnection $db, string $tableName, string $entityClass = Entity::class) {
		$this->db = $db;
		$this->tableName = $tableName;
		$this->entityClass = $entityClass;
	}

	public function getTableName(): string {
		return $this->tableName;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id) {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity($qb);
	}

	public function findAll() {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName);

		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findEntity($query) {
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new DoesNotExistException('Entity not found');
		}

		return $this->mapRowToEntity($row);
	}

	public function findEntities($query) {
		$result = $query->executeQuery();
		$entities = [];

		while ($row = $result->fetch()) {
			$entities[] = $this->mapRowToEntity($row);
		}

		$result->closeCursor();

		return $entities;
	}

	public function insert($entity) {
		return $entity;
	}

	public function update($entity) {
		return $entity;
	}

	public function delete($entity) {
		return $entity;
	}

	protected function mapRowToEntity(array $row) {
		$entity = new $this->entityClass();

		foreach ($row as $column => $value) {
			$setter = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $column)));
			$entity->$setter($value);
		}

		return $entity;
	}
}
