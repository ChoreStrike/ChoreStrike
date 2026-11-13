<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\TaskCategoryService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;

/**
 * CS-PLAN-090 WP-I (FEAT-UX-053, D-090-65) on every provider: the list
 * round-trips through typed app config, a stale revision is refused, and a
 * deleted key leaves the stored task row alone while no write can use it.
 */
class TaskCategoryTest extends IntegrationTestCase {
	private const OWNER = 'tc_owner';

	protected function setUp(): void {
		parent::setUp();
		$this->categories()->resetToDefaults();
	}

	protected function tearDown(): void {
		$this->categories()->resetToDefaults();
		parent::tearDown();
	}

	private function categories(): TaskCategoryService {
		return \OC::$server->get(TaskCategoryService::class);
	}

	public function testTheListRoundTripsAndAStaleRevisionIsRefused(): void {
		$houseId = $this->createHouse(self::OWNER);
		$saved = $this->categories()->save($houseId, 0, [['label' => 'Garden'], ['key' => 'kitchen', 'label' => 'Kitchen & dining'], ['key' => 'other']]);

		\OC::$server->get(IAppConfig::class)->clearCache(true);
		self::assertSame($saved, $this->categories()->getList());

		$this->expectException(ConflictException::class);
		$this->categories()->save($houseId, 0, [['key' => 'other']]);
	}

	public function testADeletedKeyLeavesTheStoredTaskAloneAndCannotBeWritten(): void {
		$houseId = $this->createHouse(self::OWNER);
		$roomId = $this->createRoom($houseId, $this->createFloor($houseId), 'Kitchen');
		$taskId = $this->createTask($houseId, 'Wipe the counters');
		$this->assignTaskToRoom($taskId, $roomId, self::OWNER);

		$garden = $this->categories()->save($houseId, 0, [['label' => 'Garden'], ['key' => 'other']])['items'][0]['key'];
		self::assertSame('cleaning', $this->storedCategory($taskId), 'the save rewrites no task row');

		$this->actingAs(self::OWNER);
		$owner = \OC::$server->get(HouseUserMapper::class)->findByHouseAndUser($houseId, self::OWNER);
		$tasks = \OC::$server->get(TaskService::class);
		try {
			$tasks->updateTask($taskId, ['category' => 'cleaning'], $owner);
			self::fail('a deleted key cannot be written');
		} catch (ValidationException $e) {
			self::assertContains('Unknown task category', $e->getErrors());
		}
		self::assertSame('cleaning', $this->storedCategory($taskId));

		$tasks->updateTask($taskId, ['category' => $garden], $owner);
		self::assertSame($garden, $this->storedCategory($taskId));
	}

	private function storedCategory(int $taskId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('category')->from('chorestrike_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$category = (string)$result->fetchOne();
		$result->closeCursor();
		return $category;
	}
}
