<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WeeklyGoalReceiptMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Service\NotificationService;
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

class WeeklyGoalClosureTest extends IntegrationTestCase {
	private ?int $receiptHouseId = null;
	private ?int $medalUserId = null;

	protected function tearDown(): void {
		if ($this->medalUserId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_medals')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->medalUserId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		if ($this->receiptHouseId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_wgoal_receipts')
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->receiptHouseId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		parent::tearDown();
	}

	public function testClosedPlannedWeek_ClaimsOneBellAndCorrectsMedalWithoutRepeatingIt(): void {
		$houseId = $this->createHouse('weekly_closure_owner', 'Closed week house');
		$this->receiptHouseId = $houseId;
		$floorId = $this->createFloor($houseId);
		$roomId = $this->createRoom($houseId, $floorId);
		$taskId = $this->createTask($houseId, 'Closed week chore');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('recurrence_mode', $qb->createNamedParameter('once'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$planned = (new \DateTime('monday last week UTC'))->modify('+2 days');
		$weekKey = (clone $planned)->modify('monday this week')->format('Y-m-d');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'weekly_closure_owner', $planned->format('Y-m-d H:i:s'));
		$mapper = \OC::$server->get(OccurrenceMapper::class);
		$occurrences = $mapper->findByRoomTask($roomTaskId);
		self::assertCount(1, $occurrences);
		$occurrence = $occurrences[0];
		self::assertSame($planned->format('Y-m-d'), $occurrence->getPlannedDate()->format('Y-m-d'));
		$user = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'weekly_closure_owner');
		$user->setJoinedAt((clone $planned)->modify('-2 days'));
		\OC::$server->get(HouseUserMapper::class)->update($user);
		$this->medalUserId = $user->getId();
		$completionId = $this->insertCompletionRow(
			$roomTaskId, $user->getId(), $planned->format('Y-m-d 12:00:00'), 3, $planned->format('Y-m-d')
		);
		$occurrence->setCompletionId($completionId);
		$mapper->update($occurrence);

		$notify = $this->createMock(NotificationService::class);
		$notify->expects($this->once())->method('notifyWeeklyGoalReachedForHouse')
			->with($houseId, 100, $this->anything(), $this->anything());
		$service = new MedalService(
			\OC::$server->get(HouseUserMapper::class),
			\OC::$server->get(MedalMapper::class),
			$notify,
			\OC::$server->get(AppLogger::class),
			\OC::$server->get(WeeklyGoalService::class),
			\OC::$server->get(RhythmService::class),
			\OC::$server->get(HouseMapper::class),
			\OC::$server->get(WeeklyGoalReceiptMapper::class),
			\OC::$server->get(TransactionExecutor::class),
		);
		$closedAt = (clone $planned)->modify('+8 days');
		$service->reconcileClosedWeeksForHouse($houseId, $closedAt);
		$service->reconcileClosedWeeksForHouse($houseId, $closedAt);
		$receipt = \OC::$server->get(WeeklyGoalReceiptMapper::class);
		self::assertSame([$weekKey => true], $receipt->claimedWeeks($houseId, [$weekKey]));
		self::assertFalse($receipt->claim($houseId, $weekKey, $closedAt), 'a retry cannot claim the same planned week');

		$occurrence->setCompletionId(null);
		$mapper->update($occurrence);
		$service->reconcileClosedWeeksForHouse($houseId, $closedAt);
		self::assertSame([], $service->getMedals($houseId), 'unsupported weekly recognition is hidden');
		$occurrence->setCompletionId($completionId);
		$mapper->update($occurrence);
		$service->reconcileClosedWeeksForHouse($houseId, $closedAt);
		self::assertCount(1, $service->getMedals($houseId), 'the original award reactivates without another bell');
	}
}
