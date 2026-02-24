<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Task>
 */
class TaskMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorewars_tasks', Task::class);
	}

	/**
	 * Find task by ID
	 *
	 * @param int $id The task ID
	 * @throws DoesNotExistException if not found
	 */
	public function find(int $id): Task {
		return parent::find($id);
	}

	/**
	 * Get all tasks
	 *
	 * @return Task[]
	 */
	public function findAll(): array {
		return parent::findAll();
	}

	/**
	 * Get all task templates
	 *
	 * @return Task[]
	 */
	public function findTemplates(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_template', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('category', 'ASC')
			->addOrderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get tasks by category
	 *
	 * @param string $category The task category
	 * @return Task[]
	 */
	public function findByCategory(string $category): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('category', $qb->createNamedParameter($category)))
			->orderBy('priority', 'DESC')
			->addOrderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Search tasks by name
	 *
	 * @param string $query The search query
	 * @return Task[]
	 */
	public function searchByName(string $query): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->iLike('name', $qb->createNamedParameter('%' . $query . '%')))
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Create a new task
	 *
	 * @param string $name The task name
	 * @param string|null $description The task description
	 * @param int $energyCost The energy cost (1-5)
	 * @param int $priority The priority (1-3)
	 * @param string $frequencyType The frequency type
	 * @param int $frequencyValue The frequency value
	 * @param int $estimatedMinutes Estimated time in minutes
	 * @param string $category The task category
	 * @param string $scopeType The scope type (room, floor, house)
	 * @param string|null $scopeLabel Optional scope label
	 * @param string $recurrenceMode Recurrence mode (recurring, once, bounded)
	 * @param \DateTime|null $activeFrom Optional start date
	 * @param \DateTime|null $activeUntil Optional end date
	 * @param bool $isVoluntary Whether task is voluntary
	 * @param bool $isTemplate Whether this is a template
	 */
	public function create(
		string $name,
		?string $description,
		int $energyCost,
		int $priority,
		string $frequencyType,
		int $frequencyValue,
		int $estimatedMinutes,
		string $category,
		string $scopeType = 'room',
		?string $scopeLabel = null,
		string $recurrenceMode = 'recurring',
		?\DateTime $activeFrom = null,
		?\DateTime $activeUntil = null,
		bool $isVoluntary = false,
		bool $isTemplate = false
	): Task {
		$task = new Task();
		$task->setName($name);
		$task->setDescription($description);
		$task->setEnergyCost($energyCost);
		$task->setPriority($priority);
		$task->setFrequencyType($frequencyType);
		$task->setFrequencyValue($frequencyValue);
		$task->setEstimatedMinutes($estimatedMinutes);
		$task->setCategory($category);
		$task->setScopeType($scopeType);
		$task->setScopeLabel($scopeLabel);
		$task->setRecurrenceMode($recurrenceMode);
		$task->setActiveFrom($activeFrom);
		$task->setActiveUntil($activeUntil);
		$task->setIsVoluntary($isVoluntary);
		$task->setIsTemplate($isTemplate);
		$task->setCreatedAt(new \DateTime());

		return $this->insert($task);
	}

	/**
	 * Create task from template
	 *
	 * @param int $templateId The template ID
	 * @return Task The new task (not a template)
	 */
	public function createFromTemplate(int $templateId): Task {
		$template = $this->find($templateId);
		
		return $this->create(
			$template->getName(),
			$template->getDescription(),
			$template->getEnergyCost(),
			$template->getPriority(),
			$template->getFrequencyType(),
			$template->getFrequencyValue(),
			$template->getEstimatedMinutes(),
			$template->getCategory(),
			false // Not a template
		);
	}

	/**
	 * Get all categories
	 *
	 * @return string[]
	 */
	public function getCategories(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->selectDistinct('category')
			->from($this->getTableName())
			->orderBy('category', 'ASC');

		$result = $qb->executeQuery();
		$categories = [];
		
		while ($row = $result->fetch()) {
			$categories[] = $row['category'];
		}
		
		return $categories;
	}
}
