<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB proofs for the WP3 §3.4 targeted correction contract
 * (CS-PLAN-070 WP3): attribution rewrites the selected allocation with
 * EXACT wallet union deltas, the frozen work facts (time/zone/occurrence/
 * total energy) and the room journal stay immutable, undo reverses the
 * LATEST selected allocation once, the deviation needs the explicit
 * review confirmation, and a departed participant's retained wallet is
 * part of the reversal union (WP3 §3.6 — RF-074-WP3-04 regression).
 */
class CompletionCorrectionTest extends IntegrationTestCase {
	private array $cleanupRoomTaskIds = [];
	private array $cleanupHouseIds = [];

	protected function tearDown(): void {
		// WP3 evidence rows are FK-free — clear them before the base cleanup
		// removes the structural rows (entries before wallets, shares/revs
		// before completions, room events before rooms).
		foreach ($this->cleanupRoomTaskIds as $roomTaskId) {
			$completionIds = $this->completionIdsForRoomTask($roomTaskId);
			if ($completionIds !== []) {
				$intArray = IQueryBuilder::PARAM_INT_ARRAY;
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_work_shares')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_completion_revs')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_room_events')
					->where($qb->expr()->in('source_completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_chk_progress')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		foreach ($this->cleanupHouseIds as $houseId) {
			$walletIds = $this->walletIdsForHouse($houseId);
			if ($walletIds !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_wallet_entries')
					->where($qb->expr()->in('wallet_id', $qb->createNamedParameter($walletIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_wallets')
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * @return int[]
	 */
	private function walletIdsForHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Attribute_RewritesSplitWithExactUnionDeltas
	 *
	 * Energy 3 at the frozen rate (1000 coin minor per energy): the initial
	 * 50/50 split credits 1500 minor each; the owner-corrected 100/0 split
	 * writes ONLY the deltas (owner +1500 on top of the reversed... exact
	 * union: owner 1500→3000, member 1500→0). The frozen work facts and the
	 * room journal stay byte-identical.
	 */
	public function testAttribute_RewritesSplitWithExactUnionDeltas(): void {
		$f = $this->seedSplitFixture();
		$service = \OC::$server->get(CompletionService::class);
		$owner = $this->houseUser($f['houseId'], 'cc_owner');

		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'cc_owner', $f['roomTaskId'],
			[['userId' => 'cc_owner', 'sharePct' => 50], ['userId' => 'cc_member', 'sharePct' => 50]]
		);

		$before = $this->completionRow($f['roomTaskId']);
		$roomEventsBefore = $this->countRoomEvents($f['roomId']);
		self::assertSame(1500, $this->bookedMinor($f['houseId'], 'cc_owner'));
		self::assertSame(1500, $this->bookedMinor($f['houseId'], 'cc_member'));

		$result = $service->attribute(
			$f['houseId'], $owner, (int)$before['id'], $this->uuid(),
			(int)$before['completion_version'],
			[['userId' => 'cc_owner', 'sharePct' => 100]],
			true, 'wrong_split'
		);

		// Version 0 (initial) → 1 by the attribution; the allocation
		// revision 1 → 2 selects the corrected shares.
		self::assertSame(1, $result['rewards']['completion']['version']);
		self::assertSame(2, $result['rewards']['completion']['allocationRevision']);
		self::assertSame(3000, $this->bookedMinor($f['houseId'], 'cc_owner'), 'owner delta is exactly +1500');
		self::assertSame(0, $this->bookedMinor($f['houseId'], 'cc_member'), 'the member delta is exactly -1500');

		// The selected allocation now names the owner only.
		$shares = $this->selectedShares((int)$before['id']);
		self::assertCount(1, $shares);
		self::assertSame(100, (int)$shares[0]['share_pct']);
		self::assertSame(300, (int)$shares[0]['energy_minor']);

		// The frozen work facts stay byte-identical; the room journal is
		// untouched by attribution (one completion event, still valid).
		$after = $this->completionRow($f['roomTaskId']);
		foreach (['completed_at', 'work_date', 'work_zone', 'total_energy', 'rate_minor', 'schedule_rev_id', 'occurrence_ordinal'] as $column) {
			self::assertSame($before[$column], $after[$column], "attribution must not rewrite {$column}");
		}
		self::assertSame($roomEventsBefore, $this->countRoomEvents($f['roomId']));

		// The audit chain: complete (rev 0) + attribution (rev 1).
		$revs = $this->revisionRows((int)$before['id']);
		self::assertCount(2, $revs);
		self::assertSame('complete', $revs[0]['action']);
		self::assertSame('attribution', $revs[1]['action']);
	}

	/**
	 * Attribute_DepartedParticipantWalletIsReversed
	 *
	 * RF-074-WP3-04 regression: a departed participant's retained wallet
	 * MUST join the reversal union — the share rows are the authority, not
	 * the live membership list. Without the departed wallet the completion
	 * would pay out beyond the frozen total.
	 */
	public function testAttribute_DepartedParticipantWalletIsReversed(): void {
		$f = $this->seedSplitFixture();
		$service = \OC::$server->get(CompletionService::class);

		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'cc_owner', $f['roomTaskId'],
			[['userId' => 'cc_owner', 'sharePct' => 50], ['userId' => 'cc_member', 'sharePct' => 50]]
		);
		$completion = $this->completionRow($f['roomTaskId']);
		$memberWalletId = (int)$this->walletOf($f['houseId'], 'cc_member')->getId();

		// Departure deletes the membership row but preserves the wallet.
		$this->actingAs('cc_owner');
		\OC::$server->get(HouseService::class)->removeHouseMember(
			(int)$this->houseUser($f['houseId'], 'cc_member')->getId()
		);
		self::assertSame(0, $this->countHouseUsers($f['houseId'], 'cc_member'));

		$owner = $this->houseUser($f['houseId'], 'cc_owner');
		$service->attribute(
			$f['houseId'], $owner, (int)$completion['id'], $this->uuid(),
			(int)$completion['completion_version'],
			[['userId' => 'cc_owner', 'sharePct' => 100]],
			true, 'departed_member'
		);

		// The departed wallet reversed exactly; the frozen total (3000) is
		// conserved across the retained wallet and the owner's wallet.
		$bookedDeparted = $this->bookedMinorByWalletId($memberWalletId);
		self::assertSame(0, $bookedDeparted, 'the departed wallet must be reversed by the correction');
		self::assertSame(3000, $this->bookedMinor($f['houseId'], 'cc_owner'));
		self::assertSame(3000, $bookedDeparted + $this->bookedMinor($f['houseId'], 'cc_owner'), 'the completion pays out exactly the frozen total');
	}

	/**
	 * Attribute_UnreviewedDeviation_Conflicts
	 *
	 * WP3 §3.2: an explicit allocation deviating from the recorded one
	 * requires the allocationReviewed confirmation.
	 */
	public function testAttribute_UnreviewedDeviation_Conflicts(): void {
		$f = $this->seedSplitFixture();
		$service = \OC::$server->get(CompletionService::class);

		$this->completeOccurrence($service, $f['houseId'], 'cc_owner', $f['roomTaskId']);
		$completion = $this->completionRow($f['roomTaskId']);
		$owner = $this->houseUser($f['houseId'], 'cc_owner');

		try {
			$service->attribute(
				$f['houseId'], $owner, (int)$completion['id'], $this->uuid(),
				(int)$completion['completion_version'],
				[['userId' => 'cc_owner', 'sharePct' => 50], ['userId' => 'cc_member', 'sharePct' => 50]],
				false, null
			);
			self::fail('an unreviewed deviation must conflict');
		} catch (ConflictException $e) {
			self::assertStringContainsString('must be confirmed', $e->getMessage());
		}

		// Nothing changed: the original allocation is still selected.
		$after = $this->completionRow($f['roomTaskId']);
		self::assertSame(0, (int)$after['completion_version']);
		self::assertSame(1, (int)$after['allocation_revision']);
		self::assertCount(1, $this->selectedShares((int)$completion['id']));
	}

	/**
	 * UndoAfterAttribute_ReversesTheLatestSelectedAllocationOnce
	 *
	 * The undo of a corrected completion reverses the LATEST selected
	 * revision (not the original one): 100% → attributed 25/75 → undo
	 * zeroes both wallets by the 25/75 amounts.
	 */
	public function testUndoAfterAttribute_ReversesTheLatestSelectedAllocationOnce(): void {
		$f = $this->seedSplitFixture();
		$service = \OC::$server->get(CompletionService::class);
		$owner = $this->houseUser($f['houseId'], 'cc_owner');

		$this->completeOccurrence($service, $f['houseId'], 'cc_owner', $f['roomTaskId']);
		$completion = $this->completionRow($f['roomTaskId']);
		self::assertSame(3000, $this->bookedMinor($f['houseId'], 'cc_owner'));

		$service->attribute(
			$f['houseId'], $owner, (int)$completion['id'], $this->uuid(),
			(int)$completion['completion_version'],
			[['userId' => 'cc_owner', 'sharePct' => 25], ['userId' => 'cc_member', 'sharePct' => 75]],
			true, 'wrong_split'
		);
		self::assertSame(750, $this->bookedMinor($f['houseId'], 'cc_owner'));
		self::assertSame(2250, $this->bookedMinor($f['houseId'], 'cc_member'));

		$corrected = $this->completionRow($f['roomTaskId']);
		$result = $service->undo(
			$f['houseId'], $owner, (int)$corrected['id'], $this->uuid(),
			(int)$corrected['completion_version'], 'not_done'
		);

		self::assertTrue($result['undone']);
		self::assertSame(0, $this->bookedMinor($f['houseId'], 'cc_owner'), 'the undo reverses the LATEST 25% share, not the original 100%');
		self::assertSame(0, $this->bookedMinor($f['houseId'], 'cc_member'), 'the undo reverses the LATEST 75% share');
	}

	/**
	 * Attribute_ByParticipantWithoutAuthority_Forbidden
	 *
	 * Participation alone is no correction authority (§3.4): the member
	 * took part in the work but is neither the completer nor an owner.
	 */
	public function testAttribute_ByParticipantWithoutAuthority_Forbidden(): void {
		$f = $this->seedSplitFixture();
		$service = \OC::$server->get(CompletionService::class);

		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'cc_owner', $f['roomTaskId'],
			[['userId' => 'cc_owner', 'sharePct' => 50], ['userId' => 'cc_member', 'sharePct' => 50]]
		);
		$completion = $this->completionRow($f['roomTaskId']);
		$member = $this->houseUser($f['houseId'], 'cc_member');

		try {
			$service->attribute(
				$f['houseId'], $member, (int)$completion['id'], $this->uuid(),
				(int)$completion['completion_version'],
				[['userId' => 'cc_member', 'sharePct' => 100]],
				true, null
			);
			self::fail('a participant without correction authority must be rejected');
		} catch (ForbiddenException $e) {
			self::assertStringContainsString('Only the completer or a house owner', $e->getMessage());
		}

		self::assertSame(1500, $this->bookedMinor($f['houseId'], 'cc_owner'));
		self::assertSame(1500, $this->bookedMinor($f['houseId'], 'cc_member'));
	}

	/**
	 * ShowCompletion_DepartedAuditActor_RendersRemovedMemberPlaceholder
	 *
	 * RF-074-WP3-05 regression: a departed (not erased) corrector leaves a
	 * dangling audit actor id — the read model renders the localized
	 * placeholder instead of crashing.
	 */
	public function testShowCompletion_DepartedAuditActor_RendersRemovedMemberPlaceholder(): void {
		$f = $this->seedSplitFixture();
		$service = \OC::$server->get(CompletionService::class);

		// The member completes their own work (explicit self-participation,
		// Q-074-02 — the task is assigned to the owner).
		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'cc_member', $f['roomTaskId'],
			[['userId' => 'cc_member', 'sharePct' => 100]]
		);
		$completion = $this->completionRow($f['roomTaskId']);

		// ...attributes their own completion (audit actor = the member)...
		$member = $this->houseUser($f['houseId'], 'cc_member');
		$service->attribute(
			$f['houseId'], $member, (int)$completion['id'], $this->uuid(),
			(int)$completion['completion_version'],
			[['userId' => 'cc_member', 'sharePct' => 100]],
			true, 'wrong_split'
		);

		// ...then departs. The audit actor id dangles.
		$this->actingAs('cc_owner');
		\OC::$server->get(HouseService::class)->removeHouseMember((int)$member->getId());

		$owner = $this->houseUser($f['houseId'], 'cc_owner');
		$view = $service->showCompletion($f['houseId'], $owner, (int)$completion['id']);

		self::assertSame('Removed member', $view['history'][1]['actorDisplayName']);
		self::assertNull($view['history'][1]['actor']);
		self::assertTrue($view['canCorrect'], 'the owner keeps correction authority');
	}

	/**
	 * A 100-person selected allocation is a legitimate response, not a
	 * receipt. All three command receipts retain only stable identities;
	 * replays rebuild the current, viewer-safe allocation and own wallet.
	 */
	public function testLargeAllocation_ReceiptsStayBoundedAndReplayReadsFreshState(): void {
		$f = $this->seedSplitFixture();
		$participants = [
			['userId' => 'cc_owner', 'sharePct' => 1],
			['userId' => 'cc_member', 'sharePct' => 1],
		];
		for ($i = 0; $i < 98; $i++) {
			$userId = sprintf('cc_extra_%02d', $i);
			$this->addHouseMember($f['houseId'], $userId, $userId);
			$participants[] = ['userId' => $userId, 'sharePct' => 1];
		}
		$service = \OC::$server->get(CompletionService::class);
		[$completeUuid, $occurrenceId, $version, $token] = $this->completionReceiptFor($f['roomTaskId']);
		$first = $service->complete($f['houseId'], 'cc_owner', $f['roomTaskId'], $completeUuid, $occurrenceId, $version, $token, $participants, true);
		self::assertCount(100, $first['rewards']['participants']);
		self::assertSame(['completionId' => $first['completionId']], $this->commandResultRefs($completeUuid));
		self::assertArrayNotHasKey('bookedMinor', $first['rewards']['participants'][0]);

		$owner = $this->houseUser($f['houseId'], 'cc_owner');
		$attributeUuid = $this->uuid();
		$attributed = $service->attribute(
			$f['houseId'], $owner, $first['completionId'], $attributeUuid, 0,
			[['userId' => 'cc_owner', 'sharePct' => 100]], true, 'wrong_split'
		);
		self::assertSame(['completionId' => $first['completionId'], 'noop' => false, 'version' => 1], $this->commandResultRefs($attributeUuid));
		self::assertCount(1, $attributed['rewards']['participants']);
		self::assertSame(3000, $attributed['rewards']['wallet']['bookedMinor']);

		$replayed = $service->complete($f['houseId'], 'cc_owner', $f['roomTaskId'], $completeUuid, $occurrenceId, $version, $token, $participants, true);
		self::assertTrue($replayed['replay']);
		self::assertSame($first['completionId'], $replayed['completionId']);
		self::assertCount(1, $replayed['rewards']['participants'], 'replay returns the selected correction, not stale original shares');
		self::assertSame(3000, $replayed['rewards']['wallet']['bookedMinor']);
		self::assertArrayNotHasKey('bookedMinor', $replayed['rewards']['participants'][0]);

		$undoUuid = $this->uuid();
		$undone = $service->undo($f['houseId'], $owner, $first['completionId'], $undoUuid, 1, 'not_done');
		self::assertTrue($undone['undone']);
		self::assertSame(['completionId' => $first['completionId'], 'noop' => false, 'version' => 2], $this->commandResultRefs($undoUuid));
		self::assertSame(0, $this->bookedMinor($f['houseId'], 'cc_owner'));
	}

	/**
	 * An absent obligated member must not keep a confirmed day after someone
	 * else records that day's work without giving them an actual share.
	 */
	public function testComplete_AbsentObligatedMember_NeutralizesStreakImmediately(): void {
		$houseId = $this->createHouse('cc_owner', 'Rhythm correction house');
		$this->addHouseMember($houseId, 'cc_member', 'Member');
		$this->cleanupHouseIds[] = $houseId;
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$anchor = (new \DateTime())->format('Y-m-d 00:00:00');
		$firstTask = $this->assignTaskToRoom($this->createTask($houseId, 'Member work'), $roomId, 'cc_member', $anchor);
		$this->cleanupRoomTaskIds[] = $firstTask;
		$service = \OC::$server->get(CompletionService::class);
		$this->completeOccurrence($service, $houseId, 'cc_member', $firstTask);
		self::assertSame(1, $this->houseUser($houseId, 'cc_member')->getCurrentStreak());

		$secondTask = $this->assignTaskToRoom($this->createTask($houseId, 'Owner takeover'), $roomId, 'cc_member', $anchor);
		$this->cleanupRoomTaskIds[] = $secondTask;
		$result = $this->completeOccurrenceWithParticipants(
			$service, $houseId, 'cc_owner', $secondTask,
			[['userId' => 'cc_owner', 'sharePct' => 100]]
		);

		self::assertSame(0, $this->houseUser($houseId, 'cc_member')->getCurrentStreak(), 'absent obligated member must be refreshed in the completion transaction');
		self::assertSame(3000, $this->bookedMinor($houseId, 'cc_member'), 'earlier earned credit survives; no second share is minted');
		self::assertSame('cc_owner', $result['rewards']['participants'][0]['userId']);
		self::assertCount(1, $result['rewards']['participants']);
	}

	/** @return array<string, array{bool}> */
	public static function correctionCommands(): array {
		return ['undo' => [false], 'attribution' => [true]];
	}

	/** @dataProvider correctionCommands */
	public function testCorrection_ReplayAfterOwnerDemotion_IsForbidden(bool $attribution): void {
		$f = $this->seedSplitFixture();
		$service = \OC::$server->get(CompletionService::class);
		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'cc_member', $f['roomTaskId'],
			[['userId' => 'cc_member', 'sharePct' => 100]]
		);
		$owner = $this->houseUser($f['houseId'], 'cc_owner');
		$id = (int)$this->completionRow($f['roomTaskId'])['id'];
		$uuid = $this->uuid();
		if ($attribution) {
			$service->attribute($f['houseId'], $owner, $id, $uuid, 0, [['userId' => 'cc_member', 'sharePct' => 100]], true, null);
		} else {
			$service->undo($f['houseId'], $owner, $id, $uuid, 0, null);
		}
		$demoted = clone $owner;
		$demoted->setRole('member');
		\OC::$server->get(HouseUserMapper::class)->update($demoted);

		$this->expectException(ForbiddenException::class);
		if ($attribution) {
			$service->attribute($f['houseId'], $owner, $id, $uuid, 0, [['userId' => 'cc_member', 'sharePct' => 100]], true, null);
		} else {
			$service->undo($f['houseId'], $owner, $id, $uuid, 0, null);
		}
	}

	/** @dataProvider correctionCommands */
	public function testCorrection_ReplayAfterVisibilityRevocation_IsNotFound(bool $attribution): void {
		$f = $this->seedSplitFixture();
		$service = \OC::$server->get(CompletionService::class);
		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'cc_member', $f['roomTaskId'],
			[['userId' => 'cc_member', 'sharePct' => 100]]
		);
		$member = $this->houseUser($f['houseId'], 'cc_member');
		$id = (int)$this->completionRow($f['roomTaskId'])['id'];
		$uuid = $this->uuid();
		if ($attribution) {
			$service->attribute($f['houseId'], $member, $id, $uuid, 0, [['userId' => 'cc_member', 'sharePct' => 100]], true, null);
		} else {
			$service->undo($f['houseId'], $member, $id, $uuid, 0, null);
		}
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId']);
		$taskMapper = \OC::$server->get(TaskMapper::class);
		$task = $taskMapper->find($roomTask->getTaskId());
		$task->setVisibilityType(\OCA\ChoreStrike\Db\Task::VISIBILITY_ADMIN_ONLY);
		$taskMapper->update($task);

		$this->expectException(NotFoundException::class);
		if ($attribution) {
			$service->attribute($f['houseId'], $member, $id, $uuid, 0, [['userId' => 'cc_member', 'sharePct' => 100]], true, null);
		} else {
			$service->undo($f['houseId'], $member, $id, $uuid, 0, null);
		}
	}

	// ========== Helpers ==========

	/**
	 * @return array{houseId: int, roomId: int, roomTaskId: int}
	 */
	private function seedSplitFixture(): array {
		$houseId = $this->createHouse('cc_owner', 'Correction House');
		$this->addHouseMember($houseId, 'cc_member', 'Member', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Correction task', '', 5, 3);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'cc_owner');

		$this->cleanupRoomTaskIds[] = $roomTaskId;
		$this->cleanupHouseIds[] = $houseId;

		return ['houseId' => $houseId, 'roomId' => $roomId, 'roomTaskId' => $roomTaskId];
	}

	private function houseUser(int $houseId, string $ncUserId): \OCA\ChoreStrike\Db\HouseUser {
		return \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $ncUserId);
	}

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	private function commandResultRefs(string $uuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('result_refs')
			->from('chorestrike_commands')
			->where($qb->expr()->eq('command_uuid', $qb->createNamedParameter($uuid)));
		$result = $qb->executeQuery();
		$encoded = $result->fetchOne();
		$result->closeCursor();
		self::assertIsString($encoded);
		self::assertLessThan(8192, strlen($encoded));
		return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function completionRow(int $roomTaskId): array {
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
	 * @return array<int, int>
	 */
	private function completionIdsForRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function selectedShares(int $completionId): array {
		$row = $this->completionRowById($completionId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_work_shares')
			->where($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('allocation_revision', $qb->createNamedParameter($row['allocation_revision'], IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function completionRowById(int $completionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row);
		return $row;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function revisionRows(int $completionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_completion_revs')
			->where($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	private function countRoomEvents(int $roomId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_room_events')
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function walletOf(int $houseId, string $ncUserId): \OCA\ChoreStrike\Db\Wallet {
		return \OC::$server->get(WalletMapper::class)->findByHouseAndUser($houseId, $ncUserId);
	}

	private function bookedMinor(int $houseId, string $ncUserId): int {
		return (int)$this->walletOf($houseId, $ncUserId)->getBookedMinor();
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
}
