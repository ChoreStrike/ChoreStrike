<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CommandReceipt;
use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\CompletionRevisionMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Service\ActivityEventService;
use OCA\ChoreStrike\Service\PostponeService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The events of the recent activity (CS-PLAN-091 WP-B3, D-091-52): an
 * absence shows only once it was acknowledged, the member filter runs in
 * the reads before their limit, and a task event shows only the title its
 * caller decided the viewer may read (ADR-010).
 */
class ActivityEventServiceTest extends TestCase {
	private CommandReceiptMapper $receiptMapper;
	private CompletionRevisionMapper $revisionMapper;
	private PauseMapper $pauseMapper;
	private ActivityEventService $service;
	private \DateTime $since;

	protected function setUp(): void {
		parent::setUp();
		$this->receiptMapper = $this->createMock(CommandReceiptMapper::class);
		$this->revisionMapper = $this->createMock(CompletionRevisionMapper::class);
		$this->pauseMapper = $this->createMock(PauseMapper::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->service = new ActivityEventService($this->receiptMapper, $this->revisionMapper, $this->pauseMapper, $l10n);
		$this->since = new \DateTime('2026-11-01');
	}

	private function revision(int $group, int $revision, string $state, string $createdAt): Pause {
		$pause = new Pause();
		$pause->setPauseGroupId($group);
		$pause->setRevision($revision);
		$pause->setState($state);
		$pause->setScope(Pause::SCOPE_PERSONAL);
		$pause->setSubjectWalletId(70);
		$pause->setRequesterHouseUserId(7);
		$pause->setStartDate(new \DateTime('2026-11-20'));
		$pause->setEndDate(new \DateTime('2026-11-22'));
		$pause->setCreatedAt(new \DateTime($createdAt));
		return $pause;
	}

	/**
	 * The feed's events without task events, for the absence rules.
	 *
	 * @param Pause[] $revisions What the period's pause groups hold
	 * @return list<array<string, mixed>>
	 */
	private function absences(array $revisions, ?int $actorHouseUserId = null, array $members = []): array {
		$this->pauseMapper->expects($this->once())->method('findGroupsRevisedSince')->with(1, $this->since)->willReturn($revisions);
		return $this->service->present(1, $this->since, 50, $actorHouseUserId, [], [], $members);
	}

	public function testAbsence_ARequestThatWasNeverAcknowledged_ShowsNeitherWay(): void {
		// A retained request (before ADR-020), withdrawn: the requester's and the owner's alone.
		self::assertSame([], $this->absences([
			$this->revision(1, 1, Pause::STATE_REQUESTED, '2026-11-17 08:00:00'),
			$this->revision(1, 2, Pause::STATE_CANCELLED, '2026-11-17 09:00:00'),
		]));
	}

	public function testAbsence_ARequestAcknowledgedLater_ShowsWhenItWasAcknowledged(): void {
		$events = $this->absences([
			$this->revision(1, 1, Pause::STATE_REQUESTED, '2026-11-17 08:00:00'),
			$this->revision(1, 2, Pause::STATE_ACKNOWLEDGED, '2026-11-17 09:00:00'),
			$this->revision(1, 3, Pause::STATE_CANCELLED, '2026-11-17 10:00:00'),
		]);

		self::assertSame([ActivityEventService::TYPE_ABSENCE_REMOVED, ActivityEventService::TYPE_ABSENCE], array_column($events, 'type'));
		self::assertSame((new \DateTime('2026-11-17 09:00:00'))->format('c'), $events[1]['at']);
	}

	public function testAbsence_AnEditOfAnAcknowledgedAbsence_IsNoSecondEntry(): void {
		$events = $this->absences([
			$this->revision(1, 1, Pause::STATE_ACKNOWLEDGED, '2026-11-17 08:00:00'),
			$this->revision(1, 2, Pause::STATE_ACKNOWLEDGED, '2026-11-17 09:00:00'),
		]);

		self::assertSame([ActivityEventService::TYPE_ABSENCE], array_column($events, 'type'));
	}

	public function testAbsence_RemovedInThePeriod_ShowsAlthoughItWasEnteredBeforeIt(): void {
		// The group's whole chain is read, so the removal knows the absence was acknowledged.
		$events = $this->absences([
			$this->revision(1, 1, Pause::STATE_ACKNOWLEDGED, '2026-10-20 08:00:00'),
			$this->revision(1, 2, Pause::STATE_CANCELLED, '2026-11-17 09:00:00'),
		]);

		self::assertSame([ActivityEventService::TYPE_ABSENCE_REMOVED], array_column($events, 'type'));
	}

	public function testAbsence_NamesTheMemberItIsFor_AndThePlaceholderForAFormerActor(): void {
		$subject = new HouseUser();
		$subject->setId(12);
		$subject->setNextcloudUserId('kim');
		$subject->setDisplayName('Kim');
		$subject->setWalletId(70);

		$event = $this->absences([$this->revision(1, 1, Pause::STATE_ACKNOWLEDGED, '2026-11-17 08:00:00')], null, [$subject])[0];

		self::assertSame(['kim', 'Kim', 'Former member'], [$event['subjectUserId'], $event['subjectDisplayName'], $event['displayName']]);
		self::assertArrayNotHasKey('subjectWalletId', $event, 'the wallet id stays internal');
	}

	public function testMemberFilter_RunsInTheReadsBeforeTheirLimit(): void {
		$this->receiptMapper->expects($this->once())->method('findByHouseActionSince')
			->with(1, PostponeService::ACTION, $this->since, 50, 7)
			->willReturn([]);
		$this->revisionMapper->expects($this->once())->method('findByHouseAndActionsSince')
			->with(1, $this->anything(), $this->since, 50, 7)
			->willReturn([]);
		self::assertSame([], $this->service->taskEventsSince(1, $this->since, 50, 7));

		$other = $this->revision(2, 1, Pause::STATE_ACKNOWLEDGED, '2026-11-17 08:00:00');
		$other->setRequesterHouseUserId(8);
		$events = $this->absences([$this->revision(1, 1, Pause::STATE_ACKNOWLEDGED, '2026-11-17 08:00:00'), $other], 7);

		self::assertSame([7], array_column($events, 'houseUserId'), 'only the filtered member\'s absence');
	}

	public function testTaskEvents_ShowOnlyTheTitlesTheCallerHandsIn(): void {
		$receipts = [];
		foreach ([21, 22] as $index => $roomTaskId) {
			$receipt = new CommandReceipt();
			$receipt->setActorHouseUserId(7);
			$receipt->setResultRefs(['roomTaskId' => $roomTaskId, 'postponeCount' => 1]);
			$receipt->setCreatedAt(new \DateTime('2026-11-17 0' . ($index + 8) . ':00:00'));
			$receipts[] = $receipt;
		}
		$this->receiptMapper->method('findByHouseActionSince')->willReturn($receipts);
		$this->revisionMapper->method('findByHouseAndActionsSince')->willReturn([]);
		$this->pauseMapper->method('findGroupsRevisedSince')->willReturn([]);

		$taskEvents = $this->service->taskEventsSince(1, $this->since, 50, null);
		self::assertSame([21, 22], array_column($taskEvents, 'roomTaskId'));
		self::assertArrayNotHasKey('taskTitle', $taskEvents[0], 'the reads carry no title');

		// One private definition mounted in two rooms; the viewer may read room task 21 only.
		$events = $this->service->present(1, $this->since, 50, null, $taskEvents, [21 => 'Secret'], []);

		self::assertSame([22 => null, 21 => 'Secret'], array_column($events, 'taskTitle', 'roomTaskId'), 'newest first');
	}
}
