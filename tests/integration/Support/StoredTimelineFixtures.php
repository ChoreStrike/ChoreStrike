<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Support;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Raw rows of the occurrence timeline that the derived windows replaced
 * (ADR-021 decision 12), and room journal entries of the retired quick
 * clean. Nothing writes them any more, but a retained deployment carries
 * them: they stay readable evidence, so the tests of what still reads
 * them seed the rows as they were stored.
 *
 * The owning test case cleans them with the room task, and the journal
 * entries with the room
 * ({@see \OCA\ChoreStrike\Tests\Integration\IntegrationTestCase}).
 */
trait StoredTimelineFixtures {
	/**
	 * One stored occurrence of a room task under its (single, raw) schedule
	 * revision. The window is the planned day unless overridden.
	 *
	 * @param array<string, mixed> $overrides Column values (`window_start`, `window_end`, `completion_id`, `suspension_state`, …)
	 */
	protected function insertStoredOccurrence(int $houseId, int $roomTaskId, string $plannedKey, array $overrides = []): int {
		$revisionId = $this->storedRevisionId($roomTaskId, $plannedKey);
		$dayStart = $plannedKey . ' 00:00:00';
		$values = array_merge([
			'house_id' => $houseId,
			'room_task_id' => $roomTaskId,
			'schedule_rev_id' => $revisionId,
			'ordinal' => $this->nextStoredOrdinal($revisionId),
			'kind' => 'scheduled',
			'planned_date' => $plannedKey,
			'week_start' => (new \DateTime($plannedKey . ' 00:00:00 UTC'))->modify('monday this week')->format('Y-m-d'),
			'timezone' => 'UTC',
			'day_start' => $dayStart,
			'window_start' => $dayStart,
			'window_end' => (new \DateTime($dayStart . ' UTC'))->modify('+1 day')->format('Y-m-d H:i:s'),
			'energy' => 3,
			'is_public' => true,
			'version' => 0,
			'suspension_state' => 'none',
			'created_at' => $dayStart,
		], $overrides);
		return $this->insertStoredRow('chorestrike_occurrences', $values);
	}

	/**
	 * A member of a stored occurrence (responsible and active by default).
	 *
	 * @param array<string, mixed> $overrides
	 */
	protected function insertStoredMember(int $occurrenceId, int $walletId, array $overrides = []): int {
		return $this->insertStoredRow('chorestrike_occ_members', array_merge([
			'occurrence_id' => $occurrenceId,
			'wallet_id' => $walletId,
			'role' => 'responsible',
			'obligated' => true,
			'share_pct' => 100,
			'disposition' => 'active',
			'created_at' => date('Y-m-d H:i:s'),
		], $overrides));
	}

	/**
	 * Agreement evidence of a stored occurrence, with its JSON snapshots.
	 *
	 * @param array<string, mixed>|null $oldState
	 * @param array<string, mixed>|null $newState
	 * @param array<string, mixed>|null $consent
	 */
	protected function insertStoredAgreement(int $occurrenceId, string $kind, ?array $oldState, ?array $newState, ?array $consent = null, ?int $actorHouseUserId = null): int {
		return $this->insertStoredRow('chorestrike_agreements', [
			'occurrence_id' => $occurrenceId,
			'revision' => 1,
			'kind' => $kind,
			'actor_house_user_id' => $actorHouseUserId,
			'old_state' => $oldState === null ? null : json_encode($oldState),
			'new_state' => $newState === null ? null : json_encode($newState),
			'consent' => $consent === null ? null : json_encode($consent),
			'created_at' => date('Y-m-d H:i:s'),
		]);
	}

	/**
	 * The live schedule revision of a room task as the retired writers
	 * stored it (an interval rule in weeks unless overridden).
	 *
	 * @param array<string, mixed> $overrides Column values (`cadence_mode`, `cadence_unit`, `lifetime`, `closed_at`, …)
	 */
	protected function insertStoredRevision(int $roomTaskId, string $anchorKey, array $overrides = []): int {
		return $this->insertStoredRow('chorestrike_schedule_revs', array_merge([
			'room_task_id' => $roomTaskId,
			'revision' => 1,
			'effective_from' => $anchorKey . ' 00:00:00',
			'agreed_through' => $anchorKey,
			'cadence_mode' => 'interval',
			'cadence_unit' => 'week',
			'cadence_interval' => 1,
			'anchor_date' => $anchorKey,
			'lifetime' => 'recurring',
			'early_window_days' => 0,
			'late_window_days' => 0,
			'timezone' => 'UTC',
			'energy' => 3,
			'is_public' => true,
			'is_voluntary' => false,
			'created_at' => $anchorKey . ' 00:00:00',
		], $overrides));
	}

	private function storedRevisionId(int $roomTaskId, string $anchorKey): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id')->from('chorestrike_schedule_revs')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'DESC')
			->setMaxResults(1)
			->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id !== false ? (int)$id : $this->insertStoredRevision($roomTaskId, $anchorKey);
	}

	private function nextStoredOrdinal(int $revisionId): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->max('ordinal'))->from('chorestrike_occurrences')
			->where($qb->expr()->eq('schedule_rev_id', $qb->createNamedParameter($revisionId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return (int)$max + 1;
	}

	/**
	 * A checklist tick keyed by a stored occurrence (the progress table the
	 * per-cycle ticks replaced).
	 */
	protected function insertStoredTick(int $roomTaskId, int $occurrenceId, int $checklistItemId): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('schedule_rev_id', 'ordinal')->from('chorestrike_occurrences')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($occurrenceId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$occurrence = $result->fetch();
		$result->closeCursor();
		return $this->insertStoredRow('chorestrike_chk_progress', [
			'room_task_id' => $roomTaskId,
			'schedule_rev_id' => (int)$occurrence['schedule_rev_id'],
			'ordinal' => (int)$occurrence['ordinal'],
			'revision' => 1,
			'checklist_item_id' => $checklistItemId,
			'is_checked' => true,
		]);
	}

	/**
	 * A `manual_delta` entry of the room journal, as the retired quick
	 * clean recorded it: the requested delta under its command identity.
	 * The room cache follows on the next replay.
	 */
	protected function insertStoredManualDelta(int $roomId, int $requestedDelta, ?string $at = null): int {
		$at ??= date('Y-m-d H:i:s');
		$commandUuid = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
		return $this->insertStoredRow('chorestrike_room_events', [
			'room_id' => $roomId,
			'kind' => 'manual_delta',
			'effective_at' => $at,
			'stable_id' => 'manual:' . $commandUuid,
			'delta_cleanliness' => $requestedDelta,
			'command_uuid' => $commandUuid,
			'created_at' => $at,
		]);
	}

	/** @param array<string, mixed> $values */
	private function insertStoredRow(string $table, array $values): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table)->values(array_map(
			static fn (mixed $value): mixed => $qb->createNamedParameter($value, match (true) {
				is_bool($value) => IQueryBuilder::PARAM_BOOL,
				is_int($value) => IQueryBuilder::PARAM_INT,
				$value === null => IQueryBuilder::PARAM_NULL,
				default => IQueryBuilder::PARAM_STR,
			}),
			$values
		))->executeStatement();
		return (int)$this->db->lastInsertId($table);
	}
}
