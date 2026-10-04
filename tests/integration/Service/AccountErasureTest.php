<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Service\AccountErasureService;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * CS-PLAN-071 WP0 T0: the account-erasure gate (Q-070-02 / RF-070-17 WP0
 * portion) on the real DB.
 *
 * Matrix (parent §4.3 "Q-070-02 erasure matrix full pass"):
 *  - for every membership of the deleted UID: house_users, task_assignees,
 *    medals and minigame_scores rows are gone, the wallet with its
 *    wallet_entries is gone, commands receipts lose the actor but survive,
 *    completed_tasks actor references are nulled while the anonymous shared
 *    work facts survive;
 *  - already-departed accounts (wallet without membership) are swept;
 *  - ordinary departure preserves the wallet; re-invitation reattaches it;
 *  - a recreated same-UID account gets FRESH membership/wallet ids and
 *    cannot rebind prior data (no personal read leakage);
 *  - activity reads render anonymized actors through the localized
 *    placeholder.
 */
class AccountErasureTest extends IntegrationTestCase {
	/** @var int[] */
	private array $cleanupWalletIds = [];
	/** @var int[] */
	private array $cleanupCompletionIds = [];
	/** @var string[] */
	private array $cleanupScoreUserIds = [];
	/** @var int[] Houses whose WP2 pause/agreement facts this test created. */
	private array $cleanupPauseHouseIds = [];

	protected function tearDown(): void {
		foreach ($this->cleanupPauseHouseIds as $houseId) {
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*chorestrike_pauses WHERE house_id = ?',
				[$houseId]
			);
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*chorestrike_agreements WHERE occurrence_id IN'
				. ' (SELECT id FROM *PREFIX*chorestrike_occurrences WHERE house_id = ?)',
				[$houseId]
			);
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*chorestrike_occ_members WHERE occurrence_id IN'
				. ' (SELECT id FROM *PREFIX*chorestrike_occurrences WHERE house_id = ?)',
				[$houseId]
			);
		}
		$this->cleanupPauseHouseIds = [];
		foreach ($this->cleanupWalletIds as $id) {
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*chorestrike_wallet_entries WHERE wallet_id = ?',
				[$id]
			);
			$this->deleteById('chorestrike_wallets', $id);
		}
		foreach ($this->cleanupCompletionIds as $id) {
			$this->db->executeStatement(
				'DELETE FROM *PREFIX*chorestrike_work_shares WHERE completion_id = ?',
				[$id]
			);
			$this->deleteById('chorestrike_completed_tasks', $id);
		}
		foreach ($this->cleanupScoreUserIds as $uid) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_minigame_scores')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
				->executeStatement();
		}
		parent::tearDown();
	}

	private function deleteById(string $table, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	// ========== Fixture ==========

	/**
	 * Seed a fully connected house: owner + erased member with wallet,
	 * assignment, medal, score, receipt and completions.
	 *
	 * @return array<string, int>
	 */
	private function seedHouseWithTarget(): array {
		$houseId = $this->createHouse('ae_owner', 'AE House');
		$this->addHouseMember($houseId, 'ae_target', 'AE Target', 'member');

		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'AE task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'ae_target');

		$targetHouseUserId = $this->findHouseUserId($houseId, 'ae_target');
		$ownerHouseUserId = $this->findHouseUserId($houseId, 'ae_owner');

		// Wallet + binding + one ledger entry.
		$walletId = $this->createWallet($houseId, 'ae_target');
		$this->bindWallet($targetHouseUserId, $walletId);
		$this->createWalletEntry($walletId, $ownerHouseUserId, $this->houseUserFor($houseId, 'ae_owner')->getWalletId());

		// Medal row.
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_medals')
			->values([
				'user_id' => $qb->createNamedParameter($targetHouseUserId, IQueryBuilder::PARAM_INT),
				'medal_type' => $qb->createNamedParameter('weekly_top'),
				'week_start' => $qb->createNamedParameter('2026-09-14'),
				'awarded_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();

		// Minigame score row (raw UID).
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_minigame_scores')
			->values([
				'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter('ae_target'),
				'game_id' => $qb->createNamedParameter('snake'),
				'score' => $qb->createNamedParameter(42, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();

		// A command receipt recorded by the target.
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_commands')
			->values([
				'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
				'actor_house_user_id' => $qb->createNamedParameter($targetHouseUserId, IQueryBuilder::PARAM_INT),
				'command_uuid' => $qb->createNamedParameter('22222222-2222-4222-8222-222222222222'),
				'action' => $qb->createNamedParameter('test.erasure'),
				'payload_hash' => $qb->createNamedParameter(str_repeat('c', 64)),
				'result_refs' => $qb->createNamedParameter('{"ok":true}'),
				'status' => $qb->createNamedParameter('committed'),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
		$receiptId = (int)$this->db->lastInsertId('chorestrike_commands');

		// Completions: one BY the target, one by the owner (combo partner
		// facts live in the deltas JSON since the CS-PLAN-064 reshape —
		// there is no combo_with_user_id column in the WP0 schema).
		$completionByTarget = $this->createCompletion($roomTaskId, $targetHouseUserId);
		$ownerCompletion = $this->createCompletion($roomTaskId, $ownerHouseUserId);

		return [
			'houseId' => $houseId,
			'roomTaskId' => $roomTaskId,
			'targetHouseUserId' => $targetHouseUserId,
			'walletId' => $walletId,
			'receiptId' => $receiptId,
			'completionByTarget' => $completionByTarget,
			'ownerCompletion' => $ownerCompletion,
		];
	}

	private function createWallet(int $houseId, string $uid): int {
		$walletMapper = \OC::$server->get(WalletMapper::class);
		$wallet = $walletMapper->createOrGet($houseId, $uid);
		$this->cleanupWalletIds[] = $wallet->getId();
		return $wallet->getId();
	}

	private function bindWallet(int $houseUserId, int $walletId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function createWalletEntry(int $walletId, int $actorHouseUserId, ?int $actorWalletId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_wallet_entries')
			->values([
				'wallet_id' => $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT),
				'source_kind' => $qb->createNamedParameter('completion'),
				'source_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'source_revision' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'delta_minor' => $qb->createNamedParameter(250, IQueryBuilder::PARAM_INT),
				'reason_code' => $qb->createNamedParameter('test.reason'),
				'actor_house_user_id' => $qb->createNamedParameter($actorHouseUserId, IQueryBuilder::PARAM_INT),
				'actor_wallet_id' => $qb->createNamedParameter($actorWalletId, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
	}

	private function createCompletion(int $roomTaskId, ?int $userId): int {
		// One hour back: the activity window is half-open [start, end) — a
		// same-second insert would fall outside the exclusive end.
		$completedAt = date('Y-m-d H:i:s', time() - 3600);
		$id = $this->insertCompletionRow($roomTaskId, $userId, $completedAt);
		$this->cleanupCompletionIds[] = $id;
		return $id;
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

	// ========== Query helpers ==========

	private function countBy(string $table, string $column, string $value): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function countIntBy(string $table, string $column, int $value): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function fetchCompletionRow(int $id): ?array {
		$result = $this->db->executeQuery(
			'SELECT user_id, total_energy, undone_at FROM *PREFIX*chorestrike_completed_tasks WHERE id = ?',
			[$id]
		);
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function houseUserFor(int $houseId, string $uid): HouseUser {
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$member = $houseUserMapper->findByHouseAndUser($houseId, $uid);
		self::assertNotNull($member, "member {$uid} must exist");
		return $member;
	}

	private function ensureUserWithEmail(string $userId, string $email): void {
		$this->actingAs($userId);
		$userManager = \OC::$server->get(\OCP\IUserManager::class);
		$user = $userManager->get($userId);
		if ($user === null) {
			$user = $userManager->createUser($userId, 'test-password-' . $userId);
		}
		$user->setEMailAddress($email);
	}

	// ========== Tests ==========

	/**
	 * Erasure_RemovesEveryWP0StageReference_AndRetainsAnonymousFacts
	 */
	public function testErasure_RemovesEveryWP0StageReference_AndRetainsAnonymousFacts(): void {
		$f = $this->seedHouseWithTarget();

		$erasureService = \OC::$server->get(AccountErasureService::class);
		$erasureService->eraseAccount('ae_target');

		// Membership row gone.
		self::assertSame(0, $this->countBy('chorestrike_house_users', 'nextcloud_user_id', 'ae_target'), 'the membership row must be deleted');

		// Assignees gone.
		self::assertSame(0, $this->countBy('chorestrike_task_assignees', 'user_id', 'ae_target'), 'task_assignees rows must be deleted');

		// Medals gone.
		self::assertSame(0, $this->countIntBy('chorestrike_medals', 'user_id', $f['targetHouseUserId']), 'medal rows must be deleted');

		// Scores gone.
		self::assertSame(0, $this->countBy('chorestrike_minigame_scores', 'user_id', 'ae_target'), 'minigame score rows must be deleted');

		// Wallet + entries gone.
		self::assertSame(0, $this->countIntBy('chorestrike_wallets', 'id', $f['walletId']), 'the wallet row must be deleted');
		self::assertSame(0, $this->countIntBy('chorestrike_wallet_entries', 'wallet_id', $f['walletId']), 'wallet_entries must be deleted with the wallet');

		// Receipt survives, actor nulled.
		$result = $this->db->executeQuery(
			'SELECT actor_house_user_id, status FROM *PREFIX*chorestrike_commands WHERE id = ?',
			[$f['receiptId']]
		);
		$receipt = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($receipt, 'the receipt row must survive erasure');
		self::assertNull($receipt['actor_house_user_id'], 'the receipt actor must be nulled');
		self::assertSame('committed', $receipt['status']);

		// Completions survive with NULL actors, facts retained.
		$byTarget = $this->fetchCompletionRow($f['completionByTarget']);
		self::assertIsArray($byTarget, 'the completion row must survive erasure');
		self::assertNull($byTarget['user_id'], 'the actor reference must be nulled');
		self::assertSame(3, (int)$byTarget['total_energy'], 'the anonymous shared work fact survives');

		$ownerRow = $this->fetchCompletionRow($f['ownerCompletion']);
		self::assertIsArray($ownerRow);
		self::assertNotNull($ownerRow['user_id'], 'the other actor keeps their reference');

		// WP5 (CS-PLAN-076 §3.7): the membership-keyed digest rows are
		// purged with the membership — never rebound, never surviving.
		self::assertSame(0, $this->countIntBy('chorestrike_digest_slots', 'house_user_id', $f['targetHouseUserId']), 'digest slot history is purged with the membership');
		self::assertSame(0, $this->countIntBy('chorestrike_digest_changes', 'house_user_id', $f['targetHouseUserId']), 'queued digest changes are purged with the membership');
	}

	/**
	 * Erasure_PurgesDigestRows_QueuedHistoryNeverSurvives
	 * (CS-PLAN-070 WP5 §3.7, Q-075-01 A): seeded digest rows die with the
	 * account's membership, including rows for OTHER houses of the same
	 * account.
	 */
	public function testErasure_PurgesDigestRows_QueuedHistoryNeverSurvives(): void {
		$f = $this->seedHouseWithTarget();

		$slotMapper = \OC::$server->get(\OCA\ChoreStrike\Db\DigestSlotMapper::class);
		$slot = new \OCA\ChoreStrike\Db\DigestSlot();
		$slot->setHouseId($f['houseId']);
		$slot->setHouseUserId($f['targetHouseUserId']);
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
		$change->setHouseId($f['houseId']);
		$change->setHouseUserId($f['targetHouseUserId']);
		$change->setObjectType('takeover');
		$change->setObjectId('9');
		$change->setCategory(\OCA\ChoreStrike\Db\DigestChange::CATEGORY_ORG);
		$change->setChangeTime(new \DateTime());
		$change->setTargetSlot('2026-09-29');
		$change->setDisposition(\OCA\ChoreStrike\Db\DigestChange::DISPOSITION_QUEUED);
		$change->setCreatedAt(new \DateTime());
		$change->setUpdatedAt(new \DateTime());
		$changeMapper->queueOrCoalesce($change);

		self::assertNotNull($slotMapper->findByHouseUserAndDay($f['houseId'], $f['targetHouseUserId'], '2026-09-29'));
		self::assertCount(1, $changeMapper->findBySlot($f['targetHouseUserId'], '2026-09-29'));

		\OC::$server->get(AccountErasureService::class)->eraseAccount('ae_target');

		self::assertNull($slotMapper->findByHouseUserAndDay($f['houseId'], $f['targetHouseUserId'], '2026-09-29'), 'the slot history is swept');
		self::assertSame([], $changeMapper->findBySlot($f['targetHouseUserId'], '2026-09-29'), 'the queued history is swept');
	}

	/**
	 * Erasure_AnonymizesActorInOtherWallets_WhileKeepingLedgerFacts
	 */
	public function testErasure_AnonymizesActorInOtherWallets_WhileKeepingLedgerFacts(): void {
		$f = $this->seedHouseWithTarget();
		$ownerWalletId = $this->createWallet($f['houseId'], 'ae_owner');
		$this->createWalletEntry($ownerWalletId, $f['targetHouseUserId'], $f['walletId']);
		self::assertSame(1, $this->countIntBy('chorestrike_wallet_entries', 'wallet_id', $ownerWalletId));

		\OC::$server->get(AccountErasureService::class)->eraseAccount('ae_target');

		$result = $this->db->executeQuery(
			'SELECT actor_house_user_id, actor_wallet_id, delta_minor FROM *PREFIX*chorestrike_wallet_entries WHERE wallet_id = ?',
			[$ownerWalletId]
		);
		$rows = $result->fetchAll();
		$result->closeCursor();
		self::assertCount(1, $rows, 'the other member keeps their immutable ledger movement');
		self::assertNull($rows[0]['actor_house_user_id'], 'the erased actor is detached from the surviving entry');
		self::assertNull($rows[0]['actor_wallet_id'], 'the wallet-keyed provenance is detached too');
		self::assertSame(250, (int)$rows[0]['delta_minor']);
		self::assertSame(0, $this->countIntBy('chorestrike_wallet_entries', 'wallet_id', $f['walletId']));
	}

	/**
	 * Erasure_ScrubsUserIdsFromAgreementMemberSnapshots (CS-PLAN-072 WP1,
	 * RF-072-WP1-04): agreement evidence rows survive as anonymous facts,
	 * but the member snapshots inside old_state/new_state must lose the
	 * erased account's userId binding — exactly like the schedule_revs
	 * rule-default snapshots.
	 */
	public function testErasure_ScrubsUserIdsFromAgreementMemberSnapshots(): void {
		$f = $this->seedHouseWithTarget();

		// The agreement writer records member snapshots carrying plain
		// Nextcloud userIds (participant amendment on the pending target).
		$actor = $this->houseUserFor($f['houseId'], 'ae_owner');
		\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class)
			->setAssignees($f['roomTaskId'], ['ae_target', 'ae_owner'], $f['houseId'], $actor);

		$statesBefore = $this->fetchAgreementStates($f['roomTaskId']);
		self::assertNotSame([], $statesBefore, 'the participant amendment recorded agreement evidence');
		$sawTargetBinding = false;
		foreach ($statesBefore as $state) {
			foreach ($state['members'] ?? [] as $member) {
				if (($member['userId'] ?? null) === 'ae_target') {
					$sawTargetBinding = true;
				}
			}
		}
		self::assertTrue($sawTargetBinding, 'the pre-erasure snapshot binds the target userId');

		$erasureService = \OC::$server->get(AccountErasureService::class);
		$erasureService->eraseAccount('ae_target');

		$scrubbedWalletFact = false;
		foreach ($this->fetchAgreementStates($f['roomTaskId']) as $state) {
			foreach ($state['members'] ?? [] as $member) {
				self::assertNotSame('ae_target', $member['userId'] ?? null, 'the erased userId must not survive in agreement evidence');
				if ((int)($member['walletId'] ?? 0) === $f['walletId']) {
					self::assertNull($member['userId'] ?? null, 'the account binding is stripped, the wallet fact stays');
					$scrubbedWalletFact = true;
				}
			}
		}
		self::assertTrue($scrubbedWalletFact, "the erased wallet's member snapshot survived as an anonymous fact");
	}

	/**
	 * Decoded old_state/new_state member snapshots of a room task's
	 * agreement evidence rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fetchAgreementStates(int $roomTaskId): array {
		$result = $this->db->executeQuery(
			'SELECT old_state, new_state FROM *PREFIX*chorestrike_agreements'
			. ' WHERE occurrence_id IN (SELECT id FROM *PREFIX*chorestrike_occurrences WHERE room_task_id = ?)',
			[$roomTaskId]
		);
		$rows = $result->fetchAll();
		$result->closeCursor();

		$states = [];
		foreach ($rows as $row) {
			foreach (['old_state', 'new_state'] as $column) {
				$decoded = json_decode((string)$row[$column], true);
				if (is_array($decoded)) {
					$states[] = $decoded;
				}
			}
		}
		return $states;
	}

	/**
	 * Erasure_PauseReferences_PersonalRowsRemovedAndSharedFactsAnonymized
	 * (CS-PLAN-073 WP2 §3.6, RF-073-WP2-10): the erased account's PERSONAL
	 * pause rows are removed with the wallet, no pause row keeps the
	 * account as requester/decider, the shared house-pause facts survive
	 * intact, and the recorded consent evidence loses its userId binding
	 * while the anonymous wallet fact stays.
	 */
	public function testErasure_PauseReferences_PersonalRowsRemovedAndSharedFactsAnonymized(): void {
		$f = $this->seedHouseWithTarget();
		$this->cleanupPauseHouseIds[] = $f['houseId'];
		$pauseService = \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class);
		$todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$uuid = static fn (): string => sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());

		// An owner-recorded handover onto the target — the consent evidence
		// binds the target's userId + wallet. Recorded BEFORE the pauses so
		// the target is not yet suspended (a paused member cannot take a
		// new commitment).
		$occurrenceService = \OC::$server->get(\OCA\ChoreStrike\Service\OccurrenceService::class);
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($f['roomTaskId']);
		$selection = $occurrenceService->selectionFor($roomTask, new \DateTime(), $f['houseId']);
		self::assertNotNull($selection);
		\OC::$server->get(\OCA\ChoreStrike\Service\AgreementService::class)->amend($f['houseId'], 'ae_owner', $f['roomTaskId'], [
			'commandId' => $uuid(),
			'occurrenceId' => $selection['occurrence']->toPublicKey(),
			'expectedVersion' => $selection['occurrence']->getVersion(),
			'selectionToken' => $occurrenceService->selectionToken(
				$selection['occurrence'],
				$selection['revision'],
				$selection['classification'],
				$f['houseId'],
				null,
				$selection['workAppointment']
			),
			'consent' => true,
			'participants' => [
				['userId' => 'ae_target', 'role' => 'responsible', 'sharePct' => 100],
			],
		]);
		$consentBefore = $this->fetchConsents($f['roomTaskId']);
		self::assertStringContainsString('ae_target', json_encode($consentBefore), 'the pre-erasure consent binds the target userId');

		// Two personal pauses of the target (self-requested, acknowledged)
		// and one shared house pause created by the owner.
		$pauseService->requestForSelf($f['houseId'], 'ae_target', [
			'commandId' => $uuid(),
			'startDate' => $todayKey,
			'endDate' => (new \DateTime($todayKey))->modify('+3 days')->format('Y-m-d'),
			'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_TRAVEL,
		]);
		$pauseService->requestForSelf($f['houseId'], 'ae_target', [
			'commandId' => $uuid(),
			'startDate' => (new \DateTime($todayKey))->modify('+5 days')->format('Y-m-d'),
			'endDate' => (new \DateTime($todayKey))->modify('+8 days')->format('Y-m-d'),
			'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_ILLNESS,
		]);
		$housePause = $pauseService->createHousePause($f['houseId'], 'ae_owner', [
			'commandId' => $uuid(),
			'startDate' => $todayKey,
			'endDate' => (new \DateTime($todayKey))->modify('+3 days')->format('Y-m-d'),
			'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_OTHER,
		]);

		\OC::$server->get(AccountErasureService::class)->eraseAccount('ae_target');

		// The target's personal pause rows are gone with the wallet.
		self::assertSame(0, $this->countIntBy('chorestrike_pauses', 'subject_wallet_id', $f['walletId']), 'personal pause rows are removed with the erased wallet');
		$result = $this->db->executeQuery(
			'SELECT COUNT(*) FROM *PREFIX*chorestrike_pauses WHERE requester_house_user_id = ? OR decider_house_user_id = ?',
			[$f['targetHouseUserId'], $f['targetHouseUserId']]
		);
		self::assertSame(0, (int)$result->fetchOne(), 'no pause row keeps the erased account as requester/decider');
		$result->closeCursor();

		// The shared house-pause facts survive intact.
		$result = $this->db->executeQuery(
			'SELECT scope, state, start_date, end_date FROM *PREFIX*chorestrike_pauses WHERE pause_group_id = ?',
			[$housePause['pauseGroupId']]
		);
		$houseRow = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($houseRow, 'the house pause survives as a shared anonymous fact');
		self::assertSame('house', $houseRow['scope']);
		self::assertSame(\OCA\ChoreStrike\Db\Pause::STATE_ACKNOWLEDGED, $houseRow['state']);
		self::assertSame($todayKey, substr((string)$houseRow['start_date'], 0, 10));

		// The consent evidence lost the userId binding, kept the wallet fact.
		$consents = json_encode($this->fetchConsents($f['roomTaskId']));
		self::assertStringNotContainsString('ae_target', (string)$consents, 'the erased userId must not survive in consent evidence');
		self::assertStringContainsString((string)$f['walletId'], (string)$consents, 'the anonymous wallet fact stays');
	}

	/**
	 * Decoded consent payloads of a room task's agreement evidence rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fetchConsents(int $roomTaskId): array {
		$result = $this->db->executeQuery(
			'SELECT consent FROM *PREFIX*chorestrike_agreements'
			. ' WHERE occurrence_id IN (SELECT id FROM *PREFIX*chorestrike_occurrences WHERE room_task_id = ?)',
			[$roomTaskId]
		);
		$consents = [];
		foreach ($result->fetchAll() as $row) {
			$decoded = json_decode((string)$row['consent'], true);
			if (is_array($decoded)) {
				$consents[] = $decoded;
			}
		}
		$result->closeCursor();
		return $consents;
	}

	/**
	 * DepartedAccount_WalletPreserved_ReinvitationReattaches_ErasureSweepsIt
	 */
	public function testDepartedAccount_WalletPreserved_ReinvitationReattaches_ErasureSweepsIt(): void {
		$houseService = \OC::$server->get(HouseService::class);
		$houseId = $this->createHouse('ae_dep_owner', 'AE Departure House');
		$this->actingAs('ae_dep_owner');
		$this->ensureUserWithEmail('ae_dep_member', 'ae_dep_member@example.com');
		// CS-PLAN-081 H3: ensureUserWithEmail leaves the invited account in
		// the session — the owner session must be explicit again.
		$this->actingAs('ae_dep_owner');

		// Invite (creates membership + wallet).
		$houseService->inviteMember($houseId, 'ae_dep_member@example.com', 'member');
		$memberHouseUserId = $this->findHouseUserId($houseId, 'ae_dep_member');
		$qb = $this->db->getQueryBuilder();
		$qb->select('wallet_id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($memberHouseUserId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$firstWalletId = $result->fetchOne();
		$result->closeCursor();
		self::assertNotNull($firstWalletId, 'the invite must create and bind the wallet');
		$this->cleanupWalletIds[] = (int)$firstWalletId;

		// Ordinary departure: the wallet is PRESERVED (financial identity).
		$houseService->removeHouseMember($memberHouseUserId);
		self::assertSame(1, $this->countIntBy('chorestrike_wallets', 'id', (int)$firstWalletId), 'departure must preserve the wallet row');
		self::assertSame(0, $this->countIntBy('chorestrike_house_users', 'id', $memberHouseUserId), 'the membership row must be gone');

		// Re-invitation reattaches the SAME wallet id.
		$reattached = $houseService->inviteMember($houseId, 'ae_dep_member@example.com', 'member');
		self::assertSame((int)$firstWalletId, $reattached->getWalletId(), 'the re-invitation must reattach the preserved wallet');

		// Erasure of the (now member) account removes everything.
		$erasureService = \OC::$server->get(AccountErasureService::class);
		$erasureService->eraseAccount('ae_dep_member');
		self::assertSame(0, $this->countIntBy('chorestrike_wallets', 'id', (int)$firstWalletId), 'erasure removes the wallet');

		// Erasure of an ALREADY-DEPARTED account sweeps the orphan wallet too.
		$orphanWalletId = $this->createWallet($houseId, 'ae_orphan');
		$erasureService->eraseAccount('ae_orphan');
		self::assertSame(0, $this->countIntBy('chorestrike_wallets', 'id', $orphanWalletId), 'erasure of a departed account sweeps the preserved wallet');
	}

	/**
	 * DepartedOnlyAccount_MinigameScoresAndWalletAreSwept
	 *
	 * A departed member has NO house_users row when their account is
	 * deleted — the membership loop never runs for them. The raw-UID
	 * minigame scores and the preserved wallet must still be erased
	 * (review finding: UID-keyed purge gap).
	 */
	public function testDepartedOnlyAccount_MinigameScoresAndWalletAreSwept(): void {
		$houseService = \OC::$server->get(HouseService::class);
		$houseId = $this->createHouse('ae_dp2_owner', 'AE Departed-Only House');
		$this->actingAs('ae_dp2_owner');
		$this->ensureUserWithEmail('ae_dp2_member', 'ae_dp2_member@example.com');
		// CS-PLAN-081 H3: restore the owner session after provisioning the
		// invited account (ensureUserWithEmail switches the session).
		$this->actingAs('ae_dp2_owner');

		$houseService->inviteMember($houseId, 'ae_dp2_member@example.com', 'member');
		$memberHouseUserId = $this->findHouseUserId($houseId, 'ae_dp2_member');
		$walletId = $this->createWallet($houseId, 'ae_dp2_member');
		$this->bindWallet($memberHouseUserId, $walletId);
		$ownerWalletId = $this->createWallet($houseId, 'ae_dp2_owner');
		$this->createWalletEntry($ownerWalletId, $memberHouseUserId, $walletId);
		$floorId = $this->createFloor($houseId, 'Departed Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Departed Room');
		$taskId = $this->createTask($houseId, 'Departed work');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId);
		$ownerId = $this->findHouseUserId($houseId, 'ae_dp2_owner');
		$completionId = $this->createCompletion($roomTaskId, $ownerId);
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_work_shares')
			->values([
				'completion_id' => $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT),
				'allocation_revision' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'wallet_id' => $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT),
				'share_pct' => $qb->createNamedParameter(100, IQueryBuilder::PARAM_INT),
				'energy_minor' => $qb->createNamedParameter(300, IQueryBuilder::PARAM_INT),
				'coin_minor' => $qb->createNamedParameter(3000, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();

		$insertScore = function (string $uid) use ($houseId): void {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('chorestrike_minigame_scores')
				->values([
					'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
					'user_id' => $qb->createNamedParameter($uid),
					'game_id' => $qb->createNamedParameter('snake'),
					'score' => $qb->createNamedParameter(10, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
				])
				->executeStatement();
		};
		$insertScore('ae_dp2_member');
		$insertScore('ae_dp2_owner');
		$this->cleanupScoreUserIds[] = 'ae_dp2_member';
		$this->cleanupScoreUserIds[] = 'ae_dp2_owner';

		// Depart first — the wallet survives as dormant identity.
		$houseService->removeHouseMember($memberHouseUserId);
		self::assertSame(1, $this->countIntBy('chorestrike_work_shares', 'wallet_id', $walletId), 'departure keeps the wallet share');

		$erasureService = \OC::$server->get(AccountErasureService::class);
		$erasureService->eraseAccount('ae_dp2_member');

		self::assertSame(0, $this->countBy('chorestrike_minigame_scores', 'user_id', 'ae_dp2_member'), 'the departed-only account\'s raw-UID scores must be swept');
		self::assertSame(1, $this->countBy('chorestrike_minigame_scores', 'user_id', 'ae_dp2_owner'), 'other members keep their scores');
		self::assertSame(0, $this->countIntBy('chorestrike_wallets', 'id', $walletId), 'the preserved wallet must be swept');
		$result = $this->db->executeQuery(
			'SELECT wallet_id, share_pct, energy_minor FROM *PREFIX*chorestrike_work_shares WHERE completion_id = ?',
			[$completionId]
		);
		$share = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($share, 'shared work evidence survives erasure');
		self::assertNull($share['wallet_id'], 'departed wallet identity must be detached');
		self::assertSame(100, (int)$share['share_pct']);
		self::assertSame(300, (int)$share['energy_minor']);
		$result = $this->db->executeQuery(
			'SELECT actor_house_user_id, actor_wallet_id, delta_minor FROM *PREFIX*chorestrike_wallet_entries WHERE wallet_id = ?',
			[$ownerWalletId]
		);
		$entry = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($entry, 'another member\'s wallet entry survives the departed account erasure');
		self::assertNull($entry['actor_house_user_id'], 'the departed actor reference is removed');
		self::assertNull($entry['actor_wallet_id'], 'the departed wallet provenance is removed');
		self::assertSame(250, (int)$entry['delta_minor']);
	}

	/**
	 * UndoAfterErasure_OwnerCorrectionSucceeds_NeverATypeError500
	 *
	 * WP3 §3.4: an erased completer (NULL actor) leaves the OWNER route —
	 * the owner's targeted correction of the erased member's completion
	 * must complete cleanly (never a TypeError 500 from feeding the NULL
	 * actor into an int-typed mapper).
	 */
	public function testUndoAfterErasure_YieldsClean403_NeverATypeError500(): void {
		$f = $this->seedHouseWithTarget();
		$houseId = $f['houseId'];

		// Make the target's completion the newest revertible row: the
		// owner's completion (same second, higher id) must drop out of
		// the LIFO unit first.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_completed_tasks')
			->set('undone_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['ownerCompletion'], IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$erasureService = \OC::$server->get(AccountErasureService::class);
		$erasureService->eraseAccount('ae_target');

		$this->actingAs('ae_owner');
		$result = \OC::$server->get(CompletionService::class)->undo(
			$houseId,
			$this->houseUserFor($houseId, 'ae_owner'),
			(int)$f['completionByTarget'],
			sprintf('c3d4e5f6-a7b8-4c9d-8e0f-%012d', random_int(0, 999999999999)),
			0,
			null
		);

		self::assertTrue($result['undone'], 'the owner corrects the erased completer\'s completion');
		$row = $this->fetchCompletionRow((int)$f['completionByTarget']);
		self::assertIsArray($row);
		self::assertNotNull($row['undone_at'], 'the correction marks the evidence row undone');
	}

	/**
	 * RecreatedSameUidAccount_GetsFreshIdentity_NoRebindingOfPriorData
	 */
	public function testRecreatedSameUidAccount_GetsFreshIdentity_NoRebindingOfPriorData(): void {
		$f = $this->seedHouseWithTarget();
		$houseId = $f['houseId'];
		$oldHouseUserId = $f['targetHouseUserId'];
		$oldWalletId = $f['walletId'];

		$erasureService = \OC::$server->get(AccountErasureService::class);
		$erasureService->eraseAccount('ae_target');

		// "Recreate" the account: a fresh membership + wallet binding.
		$this->addHouseMember($houseId, 'ae_target', 'AE Target Recreated', 'member');
		$recreatedHouseUserId = $this->findHouseUserId($houseId, 'ae_target');
		$recreatedWalletId = $this->createWallet($houseId, 'ae_target');
		$this->bindWallet($recreatedHouseUserId, $recreatedWalletId);

		self::assertNotSame($oldHouseUserId, $recreatedHouseUserId, 'the recreated membership gets a fresh id');
		self::assertNotSame($oldWalletId, $recreatedWalletId, 'the recreated account gets a fresh wallet id');

		// The old anonymous completions still point at NOBODY — the recreated
		// identity cannot claim them.
		$claimed = $this->fetchCompletionRow($f['completionByTarget']);
		self::assertIsArray($claimed);
		self::assertNull($claimed['user_id'], 'the recreated account must not rebind the erased completions');
	}

	/**
	 * ErasureActiveMembership_PurgesRewardRequestsEventsWishesAndQueuedRefs
	 * (CS-PLAN-070 WP7, CS-PLAN-078 §3.4, Q-070-02): holds released, the
	 * person's requests/events/wishes purged, queued reward references
	 * removed from ALL recipients and the actor anonymized on OTHER
	 * retained records. Repeated erasure is harmless.
	 */
	public function testErasureActiveMembership_PurgesRewardRequestsEventsWishesAndQueuedRefs(): void {
		$f = $this->seedRewardErasureHouse();

		$erasureService = \OC::$server->get(AccountErasureService::class);
		$erasureService->eraseAccount('ae_rw_owner');
		// Repeated erasure is harmless.
		$erasureService->eraseAccount('ae_rw_owner');

		// The erased owner's own requests/events are purged...
		self::assertSame(0, $this->countBy('chorestrike_reward_requests', 'id', (string)$f['ownerRequestId']), 'the erased owner\'s request row is purged');
		self::assertSame(0, $this->countBy('chorestrike_request_events', 'request_id', (string)$f['ownerRequestId']), 'the erased owner\'s audit events are purged');
		// ...their created wishes are purged (both items)...
		self::assertSame(0, $this->countBy('chorestrike_reward_items', 'id', (string)$f['ownerItemId']), 'the erased person\'s own wish is purged');
		// ...and every queued reference to the purged request is gone for ALL recipients.
		self::assertSame(0, $this->countBy('chorestrike_digest_changes', 'object_id', (string)$f['ownerRequestId']), 'queued references to the purged request die everywhere');
		// The CHILD's cancelled request survives (third party) with the
		// erased owner's actor reference detached.
		$childRequest = $this->fetchRewardRequest((int)$f['requestId']);
		self::assertIsArray($childRequest);
		self::assertSame('cancelled', $childRequest['state']);
		$events = $this->db->executeQuery(
			'SELECT action, actor_house_user_id, actor_wallet_id FROM *PREFIX*chorestrike_request_events WHERE request_id = ? ORDER BY revision',
			[$f['requestId']]
		)->fetchAll();
		self::assertCount(2, $events);
		// The child's own create event keeps the live actor; the erased
		// owner's cancel event is detached.
		self::assertSame('create', $events[0]['action']);
		self::assertNotNull($events[0]['actor_house_user_id']);
		self::assertSame('cancel', $events[1]['action']);
		self::assertNull($events[1]['actor_house_user_id'], 'the erased actor is detached from the retained event');
		self::assertNull($events[1]['actor_wallet_id']);
	}

	/**
	 * Seed a reward-erasure scenario: the OWNER created two items, their
	 * own adult request, and cancelled the CHILD's request (owner-actor
	 * event on a retained third-party record); a queued digest row for
	 * the child references the owner's request.
	 *
	 * @return array{houseId: int, requestId: int, ownerItemId: int, ownerRequestId: int}
	 */
	private function seedRewardErasureHouse(): array {
		$houseId = $this->createHouse('ae_rw_owner', 'AE Reward House');
		$this->addHouseMember($houseId, 'ae_rw_target', 'Target', 'child');

		$this->actingAs('ae_rw_owner');
		$houseService = \OC::$server->get(HouseService::class);
		$fixtureHouse = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		$rewardService = \OC::$server->get(\OCA\ChoreStrike\Service\RewardService::class);
		$rewardService->setCatalogEnabled($houseId, true, $fixtureHouse->getStateVersion(), $this->uuidAe());
		$ownerItem = $rewardService->createItem($houseId, [
			'title' => 'Owner prize', 'priceMinor' => 500, 'commandId' => $this->uuidAe(),
		]);

		// The child requests the owner's item (pending).
		$target = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($houseId, 'ae_rw_target');
		$this->creditTestWallet($houseId, (int)$target->getWalletId(), 5000, null);
		$requests = \OC::$server->get(\OCA\ChoreStrike\Service\RewardRequestService::class);
		$created = $requests->create($houseId, 'ae_rw_target', [
			'itemId' => (int)$ownerItem['id'], 'itemVersion' => 0, 'priceMinor' => 500, 'commandId' => $this->uuidAe(),
		]);
		$requestId = (int)$created['id'];

		// The OWNER cancels the child's request — an owner-actor event on
		// a RETAINED third-party record.
		$requests->cancel($houseId, 'ae_rw_owner', $requestId, ['commandId' => $this->uuidAe(), 'expectedVersion' => 0]);

		// The owner's own adult request (approved at creation) plus a
		// queued digest reference to it for the CHILD.
		$this->actingAs('ae_rw_owner');
		$owner = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($houseId, 'ae_rw_owner');
		$this->creditTestWallet($houseId, (int)$owner->getWalletId(), 5000, null);
		$ownerRequest = $requests->create($houseId, 'ae_rw_owner', [
			'itemId' => (int)$ownerItem['id'], 'itemVersion' => 0, 'priceMinor' => 500, 'commandId' => $this->uuidAe(),
		]);
		$changeMapper = \OC::$server->get(\OCA\ChoreStrike\Db\DigestChangeMapper::class);
		$change = new \OCA\ChoreStrike\Db\DigestChange();
		$change->setHouseId($houseId);
		$change->setHouseUserId((int)$target->getId());
		$change->setObjectType('reward_request');
		$change->setObjectId((string)(int)$ownerRequest['id']);
		$change->setObjectRevision(0);
		$change->setCategory(\OCA\ChoreStrike\Db\DigestChange::CATEGORY_ORG);
		$change->setChangeTime(new \DateTime());
		$change->setTargetSlot('2099-01-01');
		$change->setDisposition(\OCA\ChoreStrike\Db\DigestChange::DISPOSITION_QUEUED);
		$change->setCreatedAt(new \DateTime());
		$change->setUpdatedAt(new \DateTime());
		$changeMapper->queueOrCoalesce($change);

		return [
			'houseId' => $houseId,
			'requestId' => $requestId,
			'ownerItemId' => (int)$ownerItem['id'],
			'ownerRequestId' => (int)$ownerRequest['id'],
		];
	}

	private function fetchRewardRequest(int $requestId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_reward_requests')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function uuidAe(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * ActivityReads_AnonymizedActors_RenderLocalizedPlaceholder
	 */
	public function testActivityReads_AnonymizedActors_RenderLocalizedPlaceholder(): void {
		$f = $this->seedHouseWithTarget();
		$houseId = $f['houseId'];

		$erasureService = \OC::$server->get(AccountErasureService::class);
		$erasureService->eraseAccount('ae_target');

		$this->actingAs('ae_owner');
		$activityService = \OC::$server->get(ActivityService::class);
		$activity = $activityService->getActivity(
			$houseId,
			ActivityService::DEFAULT_DAYS,
			ActivityService::DEFAULT_LIMIT,
			null,
			$this->houseUserFor($houseId, 'ae_owner')
		);

		$targetRows = array_values(array_filter(
			$activity['completions'],
			static fn (array $row): bool => (int)$row['id'] === $f['completionByTarget']
		));
		self::assertCount(1, $targetRows, 'the anonymized completion stays visible as an anonymous fact');
		self::assertNull($targetRows[0]['houseUserId'], 'no actor id leaks');
		self::assertSame('Former member', $targetRows[0]['displayName'], 'the anonymized actor renders through the localized placeholder');
	}
}
