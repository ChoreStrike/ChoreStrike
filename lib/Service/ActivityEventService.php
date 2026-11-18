<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\CompletionRevision;
use OCA\ChoreStrike\Db\CompletionRevisionMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCP\IL10N;

/**
 * What the recent activity shows next to completions (CS-PLAN-091 WP-B3,
 * simplified core: "this visibility replaces approval"): postponements,
 * date changes, deletions and absences.
 *
 * Nothing is stored for the feed. Each kind is read from the fact its
 * command already writes: a postponement from its command receipt, a date
 * change and a deletion from the completion's audit chain, an absence from
 * its pause revisions. Pure reads. Which task titles the viewer may read is
 * the caller's decision ({@see ActivityService}), taken once for the
 * completions and the events together.
 */
class ActivityEventService {
	public const TYPE_POSTPONED = 'postponed';
	public const TYPE_DATE_CHANGED = 'date_changed';
	public const TYPE_DELETED = 'deleted';
	public const TYPE_ABSENCE = 'absence';
	public const TYPE_ABSENCE_REMOVED = 'absence_removed';

	public function __construct(
		private CommandReceiptMapper $receiptMapper,
		private CompletionRevisionMapper $revisionMapper,
		private PauseMapper $pauseMapper,
		private IL10N $l10n,
	) {
	}

	/**
	 * Postponements, date changes and deletions of a house since an
	 * instant, each with the room task it concerns and without its title.
	 * The member filter runs in the reads, before their limit.
	 *
	 * @param int|null $actorHouseUserId Only the events of this member
	 * @return list<array<string, mixed>>
	 */
	public function taskEventsSince(int $houseId, \DateTimeInterface $since, int $limit, ?int $actorHouseUserId): array {
		$events = [];
		foreach ($this->receiptMapper->findByHouseActionSince($houseId, PostponeService::ACTION, $since, $limit, $actorHouseUserId) as $receipt) {
			$refs = $receipt->getResultRefs() ?? [];
			$events[] = [
				'type' => self::TYPE_POSTPONED,
				'at' => $receipt->getCreatedAt(),
				'houseUserId' => $receipt->getActorHouseUserId(),
				'roomTaskId' => (int)($refs['roomTaskId'] ?? 0),
				'postponeCount' => (int)($refs['postponeCount'] ?? 0),
			];
		}
		$kinds = [CompletionRevision::ACTION_DATE => self::TYPE_DATE_CHANGED, CompletionRevision::ACTION_UNDO => self::TYPE_DELETED];
		foreach ($this->revisionMapper->findByHouseAndActionsSince($houseId, array_keys($kinds), $since, $limit, $actorHouseUserId) as $row) {
			$events[] = [
				'type' => $kinds[$row['action']],
				'at' => $row['createdAt'],
				'houseUserId' => $row['actorHouseUserId'],
				'roomTaskId' => $row['roomTaskId'],
				'completionId' => $row['completionId'],
			];
		}
		return $events;
	}

	/**
	 * The feed's events, newest first: the given task events with the
	 * titles the viewer may read, and the absences of the period.
	 *
	 * @param list<array<string, mixed>> $taskEvents {@see taskEventsSince()}
	 * @param array<int, string> $titles Room task id to the title the viewer may read; any other title is withheld
	 * @param HouseUser[] $members The members of the house
	 * @param int|null $actorHouseUserId Only the absences this member entered or removed
	 * @return list<array<string, mixed>>
	 */
	public function present(int $houseId, \DateTimeInterface $since, int $limit, ?int $actorHouseUserId, array $taskEvents, array $titles, array $members): array {
		$absences = $this->absenceEvents($houseId, $since);
		if ($actorHouseUserId !== null) {
			$absences = array_filter($absences, static fn (array $event): bool => $event['houseUserId'] === $actorHouseUserId);
		}
		$events = [
			...array_map(static fn (array $event): array => $event + ['taskTitle' => $titles[$event['roomTaskId']] ?? null], $taskEvents),
			...$absences,
		];
		usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

		$membersById = [];
		$membersByWallet = [];
		foreach ($members as $member) {
			$membersById[(int)$member->getId()] = $member;
			if ($member->getWalletId() !== null) {
				$membersByWallet[(int)$member->getWalletId()] = $member;
			}
		}
		return array_map(function (array $event) use ($membersById, $membersByWallet): array {
			// An erased or departed actor renders through the placeholder.
			$event['displayName'] = ($membersById[$event['houseUserId'] ?? 0] ?? null)?->getDisplayName() ?? $this->l10n->t('Former member');
			$event['at'] = $event['at']->format('c');
			if (array_key_exists('subjectWalletId', $event)) {
				// Null for the whole household, and for a member who left.
				$subject = $membersByWallet[(int)($event['subjectWalletId'] ?? 0)] ?? null;
				$event['subjectUserId'] = $subject?->getNextcloudUserId();
				$event['subjectDisplayName'] = $subject?->getDisplayName();
				unset($event['subjectWalletId']);
			}
			return $event;
		}, array_slice($events, 0, $limit));
	}

	/**
	 * An absence shows when it is entered and when it is removed: the
	 * first acknowledged revision of its group and a cancelling one after
	 * it. A request that was never acknowledged shows neither way: it is
	 * the requester's and the owner's alone (PauseService::listForViewer).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function absenceEvents(int $houseId, \DateTimeInterface $since): array {
		$events = [];
		$entered = [];
		// Only the groups with a revision in the period, each with its whole
		// chain: whether an absence was ever acknowledged is decided from it.
		foreach ($this->pauseMapper->findGroupsRevisedSince($houseId, $since) as $revision) {
			$group = $revision->getPauseGroupId();
			$recognized = $entered[$group] ?? false;
			$type = match (true) {
				$revision->isRecognized() && !$recognized => self::TYPE_ABSENCE,
				$revision->getState() === Pause::STATE_CANCELLED && $recognized => self::TYPE_ABSENCE_REMOVED,
				default => null,
			};
			$entered[$group] = $recognized || $revision->isRecognized();
			if ($type === null || $revision->getCreatedAt() < $since) {
				continue;
			}
			$events[] = [
				'type' => $type,
				'at' => $revision->getCreatedAt(),
				'houseUserId' => $revision->getRequesterHouseUserId(),
				'scope' => $revision->getScope(),
				'subjectWalletId' => $revision->getSubjectWalletId(),
				'from' => $revision->getStartDate()->format('Y-m-d'),
				'to' => $revision->getEndDate()->format('Y-m-d'),
			];
		}
		return $events;
	}
}
