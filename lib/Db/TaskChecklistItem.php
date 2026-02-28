<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTaskId()
 * @method void setTaskId(int $taskId)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class TaskChecklistItem extends Entity implements JsonSerializable {

	/** @var int */
	protected $taskId;

	/** @var string */
	protected $label;

	/** @var int */
	protected $sortOrder = 0;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('taskId', 'integer');
		$this->addType('label', 'string');
		$this->addType('sortOrder', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * Convert checklist item to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'taskId' => $this->taskId,
			'label' => $this->label,
			'sortOrder' => $this->sortOrder,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}

	/**
	 * Validate checklist item data
	 */
	public function validate(): array {
		$errors = [];

		if (empty(trim($this->label))) {
			$errors[] = 'Checklist item label is required';
		}

		if ($this->taskId < 1) {
			$errors[] = 'Valid task ID is required';
		}

		return $errors;
	}
}
