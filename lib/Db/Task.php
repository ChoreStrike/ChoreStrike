<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int getEnergyCost()
 * @method void setEnergyCost(int $energyCost)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method string getFrequencyType()
 * @method void setFrequencyType(string $frequencyType)
 * @method int getFrequencyValue()
 * @method void setFrequencyValue(int $frequencyValue)
 * @method int getEstimatedMinutes()
 * @method void setEstimatedMinutes(int $estimatedMinutes)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method bool getIsTemplate()
 * @method void setIsTemplate(bool $isTemplate)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Task extends Entity implements JsonSerializable {

	/** @var string */
	protected $name;

	/** @var string|null */
	protected $description;

	/** @var int */
	protected $energyCost = 1;

	/** @var int */
	protected $priority = 2;

	/** @var string */
	protected $frequencyType = 'weekly';

	/** @var int */
	protected $frequencyValue = 1;

	/** @var int */
	protected $estimatedMinutes = 15;

	/** @var string */
	protected $category = 'cleaning';

	/** @var bool */
	protected $isTemplate = false;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('name', 'string');
		$this->addType('description', 'string');
		$this->addType('energyCost', 'integer');
		$this->addType('priority', 'integer');
		$this->addType('frequencyType', 'string');
		$this->addType('frequencyValue', 'integer');
		$this->addType('estimatedMinutes', 'integer');
		$this->addType('category', 'string');
		$this->addType('isTemplate', 'boolean');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * Convert task to JSON representation
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'energyCost' => $this->energyCost,
			'priority' => $this->priority,
			'frequencyType' => $this->frequencyType,
			'frequencyValue' => $this->frequencyValue,
			'estimatedMinutes' => $this->estimatedMinutes,
			'category' => $this->category,
			'isTemplate' => $this->isTemplate,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}

	/**
	 * Get priority as string
	 */
	public function getPriorityLabel(): string {
		return match($this->priority) {
			1 => 'low',
			2 => 'medium',
			3 => 'high',
			default => 'medium'
		};
	}

	/**
	 * Get frequency as human readable string
	 */
	public function getFrequencyLabel(): string {
		return match($this->frequencyType) {
			'daily' => 'Daily',
			'weekly' => $this->frequencyValue === 1 ? 'Weekly' : "Every {$this->frequencyValue} weeks",
			'monthly' => $this->frequencyValue === 1 ? 'Monthly' : "Every {$this->frequencyValue} months",
			'custom' => 'Custom',
			default => 'Weekly'
		};
	}

	/**
	 * Validate task data
	 */
	public function validate(): array {
		$errors = [];

		if (empty(trim($this->name))) {
			$errors[] = 'Task name is required';
		}

		if ($this->energyCost < 1 || $this->energyCost > 5) {
			$errors[] = 'Energy cost must be between 1 and 5';
		}

		if ($this->priority < 1 || $this->priority > 3) {
			$errors[] = 'Priority must be between 1 and 3';
		}

		if ($this->estimatedMinutes < 1 || $this->estimatedMinutes > 480) {
			$errors[] = 'Estimated time must be between 1 and 480 minutes';
		}

		if (!in_array($this->frequencyType, ['daily', 'weekly', 'monthly', 'custom'])) {
			$errors[] = 'Invalid frequency type';
		}

		if ($this->frequencyValue < 1) {
			$errors[] = 'Frequency value must be at least 1';
		}

		return $errors;
	}
}
