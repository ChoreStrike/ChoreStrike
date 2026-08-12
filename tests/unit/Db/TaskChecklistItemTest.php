<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\TaskChecklistItem;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskChecklistItem entity logic
 */
class TaskChecklistItemTest extends TestCase {
	public function testValidate_ValidItem_ReturnsEmptyArray(): void {
		$item = new TaskChecklistItem();
		$item->setTaskId(1);
		$item->setLabel('Clean sink');

		$this->assertEmpty($item->validate());
	}

	public function testValidate_EmptyLabel_ReturnsError(): void {
		$item = new TaskChecklistItem();
		$item->setTaskId(1);
		$item->setLabel('   ');

		$errors = $item->validate();

		$this->assertNotEmpty($errors);
	}

	public function testValidate_InvalidTaskId_ReturnsError(): void {
		$item = new TaskChecklistItem();
		$item->setTaskId(0);
		$item->setLabel('Valid label');

		$errors = $item->validate();

		$this->assertNotEmpty($errors);
	}

	public function testJsonSerialize_IncludesAllFields(): void {
		$item = new TaskChecklistItem();
		$item->setId(1);
		$item->setTaskId(5);
		$item->setLabel('Wipe counter');
		$item->setSortOrder(2);
		$item->setCreatedAt(new \DateTime('2026-01-15'));

		$json = $item->jsonSerialize();

		$this->assertEquals(1, $json['id']);
		$this->assertEquals(5, $json['taskId']);
		$this->assertEquals('Wipe counter', $json['label']);
		$this->assertEquals(2, $json['sortOrder']);
		$this->assertArrayHasKey('createdAt', $json);
	}
}
