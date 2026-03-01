<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Combo;
use OCA\ChoreStrike\Db\ComboMapper;
use OCA\ChoreStrike\Service\ComboService;
use PHPUnit\Framework\TestCase;

class ComboServiceTest extends TestCase {
	private ComboService $service;
	private ComboMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(ComboMapper::class);
		$this->service = new ComboService($this->mapper);
	}

	public function testStartComboCreatesNewCombo(): void {
		$newCombo = new Combo();
		$newCombo->setId(1);
		$newCombo->setHouseId(1);
		$newCombo->setUserId('user1');
		$newCombo->setTaskCount(0);
		$newCombo->setIsActive(true);

		$this->mapper->expects($this->once())
			->method('insert')
			->willReturn($newCombo);

		$result = $this->service->startCombo(1, 'user1');

		$this->assertEquals(1, $result->getId());
		$this->assertTrue($result->getIsActive());
	}

	public function testGetOrCreateComboReturnsExistingCombo(): void {
		$existingCombo = new Combo();
		$existingCombo->setId(1);
		$existingCombo->setHouseId(1);
		$existingCombo->setUserId('user1');
		$existingCombo->setIsActive(true);
		$existingCombo->setLastTaskAt(new \DateTime());

		$this->mapper->expects($this->once())
			->method('findActiveByUser')
			->with(1, 'user1')
			->willReturn($existingCombo);

		$result = $this->service->getOrCreateCombo(1, 'user1');

		$this->assertEquals(1, $result->getId());
		$this->assertTrue($result->getIsActive());
	}

	public function testAddTaskToComboIncrementsCount(): void {
		$combo = new Combo();
		$combo->setId(1);
		$combo->setHouseId(1);
		$combo->setUserId('user1');
		$combo->setTaskCount(2);
		$combo->setIsActive(true);
		$combo->setLastTaskAt(new \DateTime());
		$combo->setComboBonusPoints(20);

		$this->mapper->expects($this->once())
			->method('findActiveByUser')
			->with(1, 'user1')
			->willReturn($combo);

		$this->mapper->expects($this->once())
			->method('update')
			->willReturn($combo);

		$result = $this->service->addTaskToCombo(1, 'user1', 10);

		$this->assertEquals(3, $result->getTaskCount());
		$this->assertEquals(30, $result->getComboBonusPoints());
	}

	public function testEndComboReturnsBonus(): void {
		$combo = new Combo();
		$combo->setId(1);
		$combo->setHouseId(1);
		$combo->setUserId('user1');
		$combo->setTaskCount(5);
		$combo->setComboBonusPoints(50);
		$combo->setIsActive(true);

		$this->mapper->expects($this->once())
			->method('findActiveByUser')
			->with(1, 'user1')
			->willReturn($combo);

		$this->mapper->expects($this->once())
			->method('update');

		$bonus = $this->service->endCombo(1, 'user1');

		$this->assertEquals(50, $bonus);
		$this->assertFalse($combo->getIsActive());
	}

	public function testGetActiveComboReturnsCombo(): void {
		$combo = new Combo();
		$combo->setId(1);
		$combo->setIsActive(true);

		$this->mapper->expects($this->once())
			->method('findActiveByUser')
			->with(1, 'user1')
			->willReturn($combo);

		$result = $this->service->getActiveCombo(1, 'user1');

		$this->assertNotNull($result);
		$this->assertTrue($result->getIsActive());
	}

	public function testGetComboProgressReturnsCorrectData(): void {
		$combo = new Combo();
		$combo->setTaskCount(3);
		$combo->setComboBonusPoints(30);
		$combo->setIsActive(true);

		$this->mapper->expects($this->once())
			->method('findActiveByUser')
			->with(1, 'user1')
			->willReturn($combo);

		$progress = $this->service->getComboProgress(1, 'user1');

		$this->assertTrue($progress['hasCombo']);
		$this->assertEquals(3, $progress['taskCount']);
		$this->assertEquals(30, $progress['bonusPoints']);
		$this->assertEquals(1.3, $progress['multiplier']);
	}

	public function testHasActiveComboDeterminesStatus(): void {
		$combo = new Combo();
		$combo->setIsActive(true);

		$this->mapper->expects($this->once())
			->method('findActiveByUser')
			->with(1, 'user1')
			->willReturn($combo);

		$result = $this->service->hasActiveCombo(1, 'user1');

		$this->assertTrue($result);
	}

	public function testGetComboStatsReturnsAggregateData(): void {
		$stats = [
			['user_id' => 'user1', 'combo_count' => 5, 'max_combo' => 10, 'total_bonus' => 150],
			['user_id' => 'user2', 'combo_count' => 3, 'max_combo' => 7, 'total_bonus' => 100],
		];

		$this->mapper->expects($this->once())
			->method('getComboStats')
			->with(1)
			->willReturn($stats);

		$result = $this->service->getComboStats(1);

		$this->assertCount(2, $result);
		$this->assertEquals('user1', $result[0]['user_id']);
		$this->assertEquals(5, $result[0]['combo_count']);
	}

	public function testGetTotalComboBonusCalculatesTotal(): void {
		$combo1 = new Combo();
		$combo1->setComboBonusPoints(50);

		$combo2 = new Combo();
		$combo2->setComboBonusPoints(30);

		$this->mapper->expects($this->once())
			->method('findCompletedByUser')
			->with(1, 'user1', 100)
			->willReturn([$combo1, $combo2]);

		$total = $this->service->getTotalComboBonus(1, 'user1');

		$this->assertEquals(80, $total);
	}
}
