<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Integration tests for transactional house-member removal (CR-SVC-26).
 *
 * Removing a member must clean up every task reference scoped to that
 * house — the `task_assignees` join rows (the canonical assignee store,
 * CS-PLAN-060 WP1 D2) — together with the `house_users` row, in one
 * transaction. Another house's assignments for the same Nextcloud user
 * must remain untouched.
 */
class HouseMemberRemovalIntegrationTest extends IntegrationTestCase {
	private int $houseAId = 0;
	private int $houseBId = 0;
	/** The WP3 attribution fixture house (work/wallet evidence cleanup). */
	private int $attributionHouseId = 0;

	protected function tearDown(): void {
		// The WP3 attribution test writes completion/work-share/revision/
		// room-event/wallet evidence — clear it before the structural rows
		// go (FK-free schema).
		if ($this->attributionHouseId !== 0) {
			$completionIds = $this->completionIdsByHouse($this->attributionHouseId);
			if ($completionIds !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_work_shares')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_completion_revs')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->in('room_task_id', $qb->createFunction($this->roomTasksSubquery($qb, $this->attributionHouseId)->getSQL())))
				->executeStatement();

			$walletIds = $this->walletIdsByHouse($this->attributionHouseId);
			if ($walletIds !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_wallet_entries')
					->where($qb->expr()->in('wallet_id', $qb->createNamedParameter($walletIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_wallets')
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->attributionHouseId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		// The base cleanup removes houses/floors/rooms/tasks/house_users by
		// tracked ids, but not task_assignees rows created here. Clear them
		// first (FK-free schema) so the shared Docker DB stays clean.
		foreach ([$this->houseAId, $this->houseBId] as $houseId) {
			if ($houseId === 0) {
				continue;
			}

			// Same subquery pattern as TaskAssigneeMapper: the house
			// parameter is bound on the OUTER builder (createNamedParameter
			// returns the placeholder string embedded into the subquery
			// SQL), never as a positional ? on the never-executed subquery
			// builder.
			$qb = $this->db->getQueryBuilder();
			$houseIdParam = $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT);

			$roomTasks = $this->db->getQueryBuilder();
			$roomTasks->select('rt.id')
				->from('chorestrike_room_tasks', 'rt')
				->innerJoin('rt', 'chorestrike_rooms', 'r', $roomTasks->expr()->eq('rt.room_id', 'r.id'))
				->innerJoin('r', 'chorestrike_floors', 'f', $roomTasks->expr()->eq('r.floor_id', 'f.id'))
				->where($roomTasks->expr()->eq('f.house_id', $houseIdParam));

			$qb->delete('chorestrike_task_assignees')
				->where($qb->expr()->in('room_task_id', $qb->createFunction($roomTasks->getSQL())));
			$qb->executeStatement();

			// WP2 pause rows of the fixture houses (FK-free schema).
			$pauses = $this->db->getQueryBuilder();
			$pauses->delete('chorestrike_pauses')
				->where($pauses->expr()->eq('house_id', $pauses->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
			$pauses->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * RemoveHouseMember_CleansUpHouseScopedReferences_AndDeletesMembership
	 */
	public function testRemoveHouseMember_CleansUpHouseScopedReferences_AndDeletesMembership(): void {
		$fixture = $this->seedFixture();

		$this->actingAs('rmr_owner_a');
		$houseService = \OC::$server->get(HouseService::class);
		$houseService->removeHouseMember($fixture['memberHouseUserIdA']);

		// Membership row gone.
		self::assertSame(0, $this->countHouseUsers($fixture['houseAId'], 'rmr_member'), 'the house_users row must be deleted');

		// House A: join-table assignee rows deleted.
		self::assertSame(0, $this->countAssignees($fixture['roomTaskAId'], 'rmr_member'), 'task_assignees rows must be deleted in house A');

		// House B (control): the same Nextcloud user's references survive.
		self::assertSame(1, $this->countAssignees($fixture['roomTaskBId'], 'rmr_member'), 'another house\'s assignee rows must be untouched');
		self::assertSame(1, $this->countHouseUsers($fixture['houseBId'], 'rmr_member'), 'another house\'s membership must be untouched');
	}

	/**
	 * RemoveHouseMember_SweepsMembershipKeyedPreferenceAndDigestRows
	 * (CS-PLAN-070 WP4/WP5, Q-075-01 A + CS-PLAN-076 §3.7): the
	 * preference row, the digest slot history and the queued changes die
	 * with the membership; another house's rows for the same Nextcloud
	 * user are untouched.
	 */
	public function testRemoveHouseMember_SweepsMembershipKeyedPreferenceAndDigestRows(): void {
		$fixture = $this->seedFixture();

		$prefMapper = \OC::$server->get(\OCA\ChoreStrike\Db\MemberPreferenceMapper::class);
		$pref = new \OCA\ChoreStrike\Db\MemberPreference();
		$pref->setHouseId($fixture['houseAId']);
		$pref->setHouseUserId($fixture['memberHouseUserIdA']);
		$pref->setVersion(1);
		$pref->setTaskDigestEnabled(true);
		$pref->setDigestTime('18:00');
		$pref->setCreatedAt(new \DateTime());
		$pref->setUpdatedAt(new \DateTime());
		$prefMapper->insertOrGet($pref);
		self::assertNotNull($prefMapper->findByHouseAndUser($fixture['houseAId'], $fixture['memberHouseUserIdA']));

		$slotMapper = \OC::$server->get(\OCA\ChoreStrike\Db\DigestSlotMapper::class);
		$slot = new \OCA\ChoreStrike\Db\DigestSlot();
		$slot->setHouseId($fixture['houseAId']);
		$slot->setHouseUserId($fixture['memberHouseUserIdA']);
		$slot->setLocalDay('2026-09-29');
		$slot->setZone('UTC');
		$slot->setPrefRevision(1);
		$slot->setScheduledUtc(new \DateTime('2026-09-29 18:00:00 UTC'));
		$slot->setWindowEndUtc(new \DateTime('2026-09-29 19:00:00 UTC'));
		$slot->setStatus(\OCA\ChoreStrike\Db\DigestSlot::STATUS_SENT);
		$slot->setCreatedAt(new \DateTime());
		$slot->setUpdatedAt(new \DateTime());
		$slotMapper->insertOrGet($slot);

		$changeMapper = \OC::$server->get(\OCA\ChoreStrike\Db\DigestChangeMapper::class);
		$change = new \OCA\ChoreStrike\Db\DigestChange();
		$change->setHouseId($fixture['houseAId']);
		$change->setHouseUserId($fixture['memberHouseUserIdA']);
		$change->setObjectType('takeover');
		$change->setObjectId('11');
		$change->setTargetSlot('2026-09-29');
		$change->setCategory(\OCA\ChoreStrike\Db\DigestChange::CATEGORY_ORG);
		$change->setChangeTime(new \DateTime());
		$change->setDisposition(\OCA\ChoreStrike\Db\DigestChange::DISPOSITION_QUEUED);
		$change->setCreatedAt(new \DateTime());
		$change->setUpdatedAt(new \DateTime());
		$changeMapper->queueOrCoalesce($change);

		$this->actingAs('rmr_owner_a');
		\OC::$server->get(HouseService::class)->removeHouseMember($fixture['memberHouseUserIdA']);

		self::assertNull($prefMapper->findByHouseAndUser($fixture['houseAId'], $fixture['memberHouseUserIdA']), 'the preference row dies with the membership');
		self::assertNull($slotMapper->findByHouseUserAndDay($fixture['houseAId'], $fixture['memberHouseUserIdA'], '2026-09-29'), 'the slot history dies with the membership');
		self::assertSame([], $changeMapper->findBySlot($fixture['memberHouseUserIdA'], '2026-09-29'), 'the queued changes die with the membership');
	}

	/**
	 * RemoveHouseMember_SelfTarget_ThrowsForbidden — leaving the house is
	 * the separate self-service departure (FEAT-UX-015).
	 */
	public function testRemoveHouseMember_SelfTarget_ThrowsForbidden(): void {
		$fixture = $this->seedFixture();

		$this->actingAs('rmr_owner_a');
		$houseService = \OC::$server->get(HouseService::class);

		try {
			$houseService->removeHouseMember($fixture['ownerHouseUserIdA']);
			self::fail('removing oneself must be rejected');
		} catch (ForbiddenException $e) {
			self::assertStringContainsString('yourself', $e->getMessage());
		}

		// Nothing was removed: owner membership and all references survive.
		self::assertSame(1, $this->countHouseUsers($fixture['houseAId'], 'rmr_owner_a'));
		self::assertSame(1, $this->countHouseUsers($fixture['houseAId'], 'rmr_member'));
		self::assertSame(1, $this->countAssignees($fixture['roomTaskAId'], 'rmr_member'));
	}

	/**
	 * FEAT-UX-015 (CS-PLAN-088 D-088-19): an owner removes ANOTHER owner —
	 * e.g. the bootstrap admin account — with the ordinary departure
	 * cleanup; the acting owner stays, so the house keeps an owner.
	 */
	public function testRemoveHouseMember_OtherOwner_RemovedWithTheMemberCleanup(): void {
		$fixture = $this->seedFixture();
		$this->addHouseMember($fixture['houseAId'], 'rmr_admin', 'Admin', 'owner');
		$floorId = $this->createFloor($fixture['houseAId'], 'Upstairs', 2);
		$roomTaskId = $this->assignTaskToRoom(
			$this->createTask($fixture['houseAId'], 'Windows'),
			$this->createRoom($fixture['houseAId'], $floorId, 'Office'),
			'rmr_admin'
		);

		$this->actingAs('rmr_owner_a');
		\OC::$server->get(HouseService::class)->removeHouseMember($this->findHouseUserId($fixture['houseAId'], 'rmr_admin'));

		self::assertSame(0, $this->countHouseUsers($fixture['houseAId'], 'rmr_admin'), 'the other owner\'s membership is deleted');
		self::assertSame(0, $this->countAssignees($roomTaskId, 'rmr_admin'), 'its assignee rows are deleted');
		self::assertSame(1, $this->countHouseUsers($fixture['houseAId'], 'rmr_owner_a'), 'the acting owner remains');
		self::assertSame(1, \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->countOwnersForHouse($fixture['houseAId']));

		// The mutual-removal race serializes on the house lock: the loser is
		// no longer a member when its own removal runs, so the house can
		// never end up without an owner.
		$this->actingAs('rmr_admin');
		try {
			\OC::$server->get(HouseService::class)->removeHouseMember($fixture['ownerHouseUserIdA']);
			self::fail('a removed owner must not remove the remaining owner');
		} catch (ForbiddenException) {
			self::addToAssertionCount(1);
		}
		self::assertSame(1, $this->countHouseUsers($fixture['houseAId'], 'rmr_owner_a'));
	}

	/**
	 * RemoveHouseMember_Pauses_CancelPendingRetainHistoryStopFutureEffects
	 * (CS-PLAN-073 Q-073-03, RF-073-WP2-10): departure cancels the
	 * departing member's PENDING personal pause requests, preserves the
	 * already-acknowledged neutral history (state/dates survive), and
	 * stops FUTURE personal-pause effects — the former member's personal
	 * pause no longer suspends occurrences once they left the house.
	 */
	public function testRemoveHouseMember_Pauses_CancelPendingRetainHistoryStopFutureEffects(): void {
		$fixture = $this->seedFixture();
		$houseAId = $fixture['houseAId'];
		$this->addHouseMember($houseAId, 'rmr_child', 'Child', 'child');

		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$walletService = \OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class);
		foreach (['rmr_owner_a', 'rmr_member', 'rmr_child'] as $userId) {
			$walletService->ensureBinding($houseAId, $houseUserMapper->findByNextcloudUserId($houseAId, $userId));
		}
		// The fixture assignment predates the wallet binding — re-record it
		// so the revision defaults and the occurrence member rows carry the
		// member's wallet (the suspension derivation resolves per wallet).
		\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class)->setAssignees(
			$fixture['roomTaskAId'],
			['rmr_member'],
			$houseAId,
			$houseUserMapper->findByNextcloudUserId($houseAId, 'rmr_owner_a')
		);

		$pauseService = \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class);
		$todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$range = static function (string $reason) use ($todayKey): array {
			return [
				'startDate' => $todayKey,
				'endDate' => (new \DateTime($todayKey))->modify('+5 days')->format('Y-m-d'),
				'reasonCode' => $reason,
			];
		};

		// The member's acknowledged personal pause suspends their task.
		$memberPause = $pauseService->requestForSelf($houseAId, 'rmr_member', array_merge(['commandId' => $this->uuidRmr()], $range(\OCA\ChoreStrike\Db\Pause::REASON_TRAVEL)));
		self::assertSame(\OCA\ChoreStrike\Db\Pause::STATE_ACKNOWLEDGED, $memberPause['state']);
		$occurrenceMapper = \OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class);
		$memberOccurrence = null;
		foreach ($occurrenceMapper->findByRoomTask($fixture['roomTaskAId']) as $row) {
			if ($row->getCompletionId() === null) {
				$memberOccurrence = $row;
			}
		}
		self::assertNotNull($memberOccurrence);
		self::assertSame(\OCA\ChoreStrike\Db\Occurrence::SUSPENSION_SUSPENDED, $memberOccurrence->getSuspensionState(), 'the acknowledged pause suspends the covered obligation');

		// The child's request stays pending.
		$childPause = $pauseService->requestForSelf($houseAId, 'rmr_child', array_merge(['commandId' => $this->uuidRmr()], $range(\OCA\ChoreStrike\Db\Pause::REASON_OTHER)));
		self::assertSame(\OCA\ChoreStrike\Db\Pause::STATE_REQUESTED, $childPause['state']);

		$this->actingAs('rmr_owner_a');
		$houseService = \OC::$server->get(HouseService::class);
		$houseService->removeHouseMember($fixture['memberHouseUserIdA']);
		$houseService->removeHouseMember($this->findHouseUserId($houseAId, 'rmr_child'));

		// The child's pending request was cancelled — never silently kept.
		self::assertSame(\OCA\ChoreStrike\Db\Pause::STATE_CANCELLED, $this->currentPauseState($childPause['pauseGroupId']));

		// The member's acknowledged history is preserved (neutral facts).
		self::assertSame(\OCA\ChoreStrike\Db\Pause::STATE_ACKNOWLEDGED, $this->currentPauseState($memberPause['pauseGroupId']));
		self::assertSame($todayKey, $this->currentPauseStart($memberPause['pauseGroupId']));

		// Future personal-pause effects stopped at departure: the former
		// member's pause no longer keeps the occurrence suspended.
		$after = $occurrenceMapper->find((int)$memberOccurrence->getId());
		self::assertSame(\OCA\ChoreStrike\Db\Occurrence::SUSPENSION_NONE, $after->getSuspensionState(), 'the departed member\'s personal pause has no further effect');
	}

	/**
	 * RemoveHouseMember_CancelsUnfulfilledRewardRequestsAndReleasesHolds
	 * (CS-PLAN-070 WP7, CS-PLAN-078 §3.4, C4.6): departure cancels every
	 * pending/approved request of the departing wallet and releases its
	 * reservation in the SAME aggregate, before access ends. Booked value
	 * and terminal history are preserved for the retained wallet; another
	 * house's requests for the same Nextcloud user are untouched.
	 */
	public function testRemoveHouseMember_CancelsUnfulfilledRewardRequestsAndReleasesHolds(): void {
		$fixture = $this->seedFixture();
		$houseAId = $fixture['houseAId'];
		$this->addHouseMember($houseAId, 'rmr_child', 'Child A', 'child');

		$rewardService = \OC::$server->get(\OCA\ChoreStrike\Service\RewardService::class);
		$requests = \OC::$server->get(\OCA\ChoreStrike\Service\RewardRequestService::class);
		$this->actingAs('rmr_owner_a');
		$houseService = \OC::$server->get(HouseService::class);
		$fixtureHouse = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseAId);
		$rewardService->setCatalogEnabled($houseAId, true, $fixtureHouse->getStateVersion(), $this->uuidRmr());
		$item = $rewardService->createItem($houseAId, [
			'title' => 'Trip', 'priceMinor' => 500, 'commandId' => $this->uuidRmr(),
		]);
		// Fund the child wallet through the real ledger path.
		$this->creditTestWallet($houseAId, $this->walletIdByUser($houseAId, 'rmr_child'), 5000, null);
		$created = $requests->create($houseAId, 'rmr_child', [
			'itemId' => (int)$item['id'], 'itemVersion' => 0, 'priceMinor' => 500, 'commandId' => $this->uuidRmr(),
		]);
		$requestId = (int)$created['id'];

		// The CHILD departs (not the fixture's own member).
		$this->actingAs('rmr_owner_a');
		$houseService->removeHouseMember($this->findHouseUserId($houseAId, 'rmr_child'));

		$row = $this->rewardRequestRow($requestId);
		self::assertSame('cancelled', $row['state'], 'the unfulfilled request was cancelled at departure');
		$walletId = $this->walletIdByUser($houseAId, 'rmr_child');
		self::assertSame(0, $this->reservedMinorByWalletId($walletId), 'the reservation was released');
		$events = $this->rewardEventActions($requestId);
		self::assertContains('departure_cancel', $events, 'the departure append is audited');
	}

	private function rewardRequestRow(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_reward_requests')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row, "reward request $requestId must exist");
		return $row;
	}

	/**
	 * @return string[]
	 */
	private function rewardEventActions(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('action')
			->from('chorestrike_request_events')
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');
		$result = $qb->executeQuery();
		$actions = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $actions;
	}

	private function walletIdByUser(int $houseId, string $ncUserId): int {
		return (int)\OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class)
			->findByHouseAndUser($houseId, $ncUserId)
			->getId();
	}

	private function reservedMinorByWalletId(int $walletId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('reserved_minor')
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = (int)$result->fetchOne();
		$result->closeCursor();
		return $value;
	}

	private function uuidRmr(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	private function currentPauseState(int $pauseGroupId): string {
		return (string)$this->currentPauseColumn($pauseGroupId, 'state');
	}

	private function currentPauseStart(int $pauseGroupId): string {
		return substr((string)$this->currentPauseColumn($pauseGroupId, 'start_date'), 0, 10);
	}

	private function currentPauseColumn(int $pauseGroupId, string $column): mixed {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)
			->from('chorestrike_pauses')
			->where($qb->expr()->eq('pause_group_id', $qb->createNamedParameter($pauseGroupId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		self::assertNotFalse($value, 'the pause group must still own its current revision');
		return $value;
	}

	/**
	 * Seed two houses, both with a room task assigned (join table) to the
	 * same Nextcloud user 'rmr_member'.
	 *
	 * @return array{houseAId: int, houseBId: int, memberHouseUserIdA: int, ownerHouseUserIdA: int, roomTaskAId: int, roomTaskBId: int}
	 */
	private function seedFixture(): array {
		// House A: owner + the member under removal.
		$houseAId = $this->createHouse('rmr_owner_a', 'Removal House A');
		$this->addHouseMember($houseAId, 'rmr_member', 'Member A', 'member');
		$floorAId = $this->createFloor($houseAId, 'Ground Floor', 1);
		$roomIdA = $this->createRoom($houseAId, $floorAId, 'Kitchen');
		$taskIdA = $this->createTask($houseAId, 'Dishes', 'Do the dishes', 5, 3);
		$roomTaskAId = $this->assignTaskToRoom($taskIdA, $roomIdA, 'rmr_member');

		// House B (control): same Nextcloud user, own membership + assignment.
		$houseBId = $this->createHouse('rmr_owner_b', 'Removal House B');
		$this->addHouseMember($houseBId, 'rmr_member', 'Member B', 'member');
		$floorBId = $this->createFloor($houseBId, 'Ground Floor', 1);
		$roomIdB = $this->createRoom($houseBId, $floorBId, 'Bathroom');
		$taskIdB = $this->createTask($houseBId, 'Tiles', 'Scrub the tiles', 5, 3);
		$roomTaskBId = $this->assignTaskToRoom($taskIdB, $roomIdB, 'rmr_member');

		$this->houseAId = $houseAId;
		$this->houseBId = $houseBId;

		return [
			'houseAId' => $houseAId,
			'houseBId' => $houseBId,
			'memberHouseUserIdA' => $this->findHouseUserId($houseAId, 'rmr_member'),
			'ownerHouseUserIdA' => $this->findHouseUserId($houseAId, 'rmr_owner_a'),
			'roomTaskAId' => $roomTaskAId,
			'roomTaskBId' => $roomTaskBId,
		];
	}

	/**
	 * RemoveHouseMember_LaterAttribution_ReversesTheDepartedWallet
	 * (WP3 §3.6; RF-074-WP3-04 regression)
	 *
	 * Departure preserves the wallet but deletes the membership row. A
	 * later owner correction MUST still reverse the departed participant's
	 * frozen share — the share rows are the reversal authority, never the
	 * live membership list — so the completion pays out exactly the
	 * frozen total and no more.
	 */
	public function testRemoveHouseMember_LaterAttribution_ReversesTheDepartedWallet(): void {
		$houseId = $this->createHouse('rmr_owner_a', 'Removal Attribution House');
		$this->addHouseMember($houseId, 'rmr_member', 'Member A', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Attribution task', 'Do the thing', 5, 3);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'rmr_owner_a');
		$this->houseAId = $houseId;
		$this->attributionHouseId = $houseId;

		$completionService = \OC::$server->get(CompletionService::class);
		$this->completeOccurrenceWithParticipants(
			$completionService,
			$houseId,
			'rmr_owner_a',
			$roomTaskId,
			[['userId' => 'rmr_owner_a', 'sharePct' => 50], ['userId' => 'rmr_member', 'sharePct' => 50]]
		);
		$memberWalletId = (int)\OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class)
			->findByHouseAndUser($houseId, 'rmr_member')
			->getId();

		// Departure: membership gone, wallet retained.
		$this->actingAs('rmr_owner_a');
		\OC::$server->get(HouseService::class)->removeHouseMember($this->findHouseUserId($houseId, 'rmr_member'));
		self::assertSame(0, $this->countHouseUsers($houseId, 'rmr_member'));

		// The owner corrects the split to 100/0 after the departure.
		$completion = $this->latestCompletionRow($roomTaskId);
		$owner = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($houseId, 'rmr_owner_a');
		$completionService->attribute(
			$houseId,
			$owner,
			(int)$completion['id'],
			$this->uuidRmr(),
			(int)$completion['completion_version'],
			[['userId' => 'rmr_owner_a', 'sharePct' => 100]],
			true,
			'departed_member'
		);

		self::assertSame(0, $this->bookedMinorByWalletId($memberWalletId), 'the departed wallet must be reversed by the correction');
		self::assertSame(3000, $this->bookedMinorByHouseAndUser($houseId, 'rmr_owner_a'));
		self::assertSame(
			3000,
			$this->bookedMinorByWalletId($memberWalletId) + $this->bookedMinorByHouseAndUser($houseId, 'rmr_owner_a'),
			'the completion pays out exactly the frozen total'
		);
	}

	/**
	 * The completion ids of one house (room task → room → floor chain).
	 *
	 * @return int[]
	 */
	private function completionIdsByHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->in('room_task_id', $qb->createFunction($this->roomTasksSubquery($qb, $houseId)->getSQL())));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	/**
	 * The never-executed room-task id subquery of one house (the house
	 * parameter binds on the OUTER builder of the caller).
	 */
	private function roomTasksSubquery(\OCP\DB\QueryBuilder\IQueryBuilder $outer, int $houseId): \OCP\DB\QueryBuilder\IQueryBuilder {
		$sub = $this->db->getQueryBuilder();
		$sub->select('rt.id')
			->from('chorestrike_room_tasks', 'rt')
			->innerJoin('rt', 'chorestrike_rooms', 'r', $sub->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $sub->expr()->eq('r.floor_id', 'f.id'))
			->where($sub->expr()->eq('f.house_id', $outer->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		return $sub;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function latestCompletionRow(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row);
		return $row;
	}

	/**
	 * @return int[]
	 */
	private function walletIdsByHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	private function bookedMinorByWalletId(int $walletId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('booked_minor')
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = (int)$result->fetchOne();
		$result->closeCursor();
		return $value;
	}

	private function bookedMinorByHouseAndUser(int $houseId, string $ncUserId): int {
		return (int)\OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class)
			->findByHouseAndUser($houseId, $ncUserId)
			->getBookedMinor();
	}

	private function findHouseUserId(int $houseId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();

		self::assertGreaterThan(0, $id, "house user row for {$ncUserId} must exist");
		return $id;
	}

	private function countHouseUsers(int $houseId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	private function countAssignees(int $roomTaskId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_task_assignees')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
