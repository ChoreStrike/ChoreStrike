<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\BonusPeriod;
use OCA\ChoreStrike\Db\BonusPeriodMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * The two coin multipliers (CS-PLAN-091 WP-B5, ADR-021 decision 11): the
 * coin factor of a member and the bonus periods of a house. Only the
 * owner sets them, each through a receipt-protected command under the
 * house lock. A completion freezes both ({@see freeze()}), so they reach
 * coins only and never what was already paid.
 */
class CoinMultiplierService {
	public const ACTION_FACTOR = 'house.coin-factor';
	public const ACTION_PERIOD_START = 'house.bonus-period.start';
	public const ACTION_PERIOD_CANCEL = 'house.bonus-period.cancel';

	/** How far ahead a bonus period may start. */
	private const START_RANGE_DAYS = 366;

	public function __construct(
		private CommandService $commandService,
		private AuthorizationService $authorization,
		private HouseMapper $houseMapper,
		private HouseUserMapper $houseUserMapper,
		private BonusPeriodMapper $periodMapper,
		private CalendarService $calendar,
		private IL10N $l10n,
	) {
	}

	/**
	 * Set the coin factor of one member. It counts for completions recorded
	 * from now on.
	 *
	 * @param string $userId The member's Nextcloud user id
	 * @return HouseUser The member as it reads now
	 * @throws ForbiddenException the caller is not the owner
	 * @throws NotFoundException the target is no member of the house
	 * @throws \InvalidArgumentException a factor other than 1, 2 or 3
	 */
	public function setFactor(int $houseId, string $userId, int $factor, string $commandId): HouseUser {
		if (!in_array($factor, EffortAllocationService::COIN_FACTORS, true)) {
			throw new \InvalidArgumentException($this->l10n->t('The coin factor must be 1, 2 or 3'));
		}
		$this->ownerCommand($houseId, $commandId, self::ACTION_FACTOR, ['userId' => $userId, 'factor' => $factor], function () use ($houseId, $userId, $factor): array {
			$member = $this->houseUserMapper->findForUpdate((int)$this->memberOrNotFound($houseId, $userId)->getId());
			$member->setCoinFactor($factor);
			$this->houseUserMapper->update($member);
			return ['houseUserId' => (int)$member->getId()];
		});
		return $this->memberOrNotFound($houseId, $userId);
	}

	/**
	 * Start a bonus period of one to three house-local days, today or
	 * later. Periods of a house do not overlap. Answers, like the cancel,
	 * with the period the house shows now ({@see runningOrNext()}).
	 *
	 * @param string $startsOn First day (`Y-m-d`)
	 * @throws ForbiddenException the caller is not the owner
	 * @throws ConflictException the period would overlap another one
	 * @throws \InvalidArgumentException a day or length the period cannot take
	 */
	public function startPeriod(int $houseId, string $startsOn, int $days, string $commandId): ?BonusPeriod {
		$this->ownerCommand($houseId, $commandId, self::ACTION_PERIOD_START, ['startsOn' => $startsOn, 'days' => $days], function () use ($houseId, $startsOn, $days): array {
			$now = new \DateTime();
			$this->assertPeriod($startsOn, $days, $this->calendar->dayKey($now, $this->calendar->getTimeZone($this->houseMapper->find($houseId))));
			$endsOn = RecurrenceCalculator::offsetDays($startsOn, $days - 1);
			foreach ($this->periodMapper->findEndingFrom($houseId, $startsOn) as $other) {
				if ($other->getStartsOn()->format('Y-m-d') <= $endsOn) {
					throw new ConflictException($this->l10n->t('Bonus days are already planned for this time'));
				}
			}
			$period = new BonusPeriod();
			$period->setHouseId($houseId);
			$period->setStartsOn(RecurrenceCalculator::dayKeyToDate($startsOn));
			$period->setEndsOn(RecurrenceCalculator::dayKeyToDate($endsOn));
			$period->setCreatedAt($now);
			return ['periodId' => (int)$this->periodMapper->insert($period)->getId()];
		});
		return $this->runningOrNext($this->houseMapper->find($houseId));
	}

	/**
	 * Cancel a bonus period that has not ended. Completions recorded while
	 * it ran keep the percent frozen on them. Cancelling a cancelled period
	 * again changes nothing. Answers with the period the house shows now.
	 *
	 * @throws ForbiddenException the caller is not the owner
	 * @throws NotFoundException an unknown period
	 * @throws ConflictException the period has ended
	 */
	public function cancelPeriod(int $houseId, int $periodId, string $commandId): ?BonusPeriod {
		$this->ownerCommand($houseId, $commandId, self::ACTION_PERIOD_CANCEL, ['periodId' => $periodId], function () use ($houseId, $periodId): array {
			$period = $this->periodOrNotFound($houseId, $periodId);
			$now = new \DateTime();
			$today = $this->calendar->dayKey($now, $this->calendar->getTimeZone($this->houseMapper->find($houseId)));
			if ($period->getCancelledAt() === null) {
				if ($period->getEndsOn()->format('Y-m-d') < $today) {
					throw new ConflictException($this->l10n->t('These bonus days are already over'));
				}
				$period->setCancelledAt($now);
				$this->periodMapper->update($period);
			}
			return ['periodId' => $periodId];
		});
		return $this->runningOrNext($this->houseMapper->find($houseId));
	}

	/**
	 * The bonus period `GET /house` shows: the running one, else the next
	 * planned one.
	 */
	public function runningOrNext(House $house, ?\DateTimeInterface $now = null): ?BonusPeriod {
		$today = $this->calendar->dayKey($now ?? new \DateTime(), $this->calendar->getTimeZone($house));
		return $this->periodMapper->findEndingFrom((int)$house->getId(), $today)[0] ?? null;
	}

	/**
	 * What a completion freezes (decision 11): the coin factor of every
	 * member of the house by wallet id, and the percent of its work day.
	 * Call under the house lock, which every command above holds too.
	 *
	 * @param HouseUser[] $members The members of the house
	 * @param array<string, Wallet> $walletsByUserId The wallets just bound for the people credited
	 * @param string $workDayKey The house-local work day (`Y-m-d`)
	 * @return array{factors: array<int, int>, percent: int}
	 */
	public function freeze(int $houseId, array $members, array $walletsByUserId, string $workDayKey): array {
		$factors = [];
		foreach ($members as $member) {
			$wallet = $walletsByUserId[$member->getNextcloudUserId()] ?? null;
			$walletId = $wallet !== null ? (int)$wallet->getId() : $member->getWalletId();
			if ($walletId !== null) {
				$factors[(int)$walletId] = $member->getCoinFactor();
			}
		}
		ksort($factors);

		$percent = BonusPeriod::PERCENT_NONE;
		foreach ($this->periodMapper->findEndingFrom($houseId, $workDayKey) as $period) {
			if ($period->getStartsOn()->format('Y-m-d') <= $workDayKey) {
				$percent = BonusPeriod::PERCENT;
			}
		}
		return ['factors' => $factors, 'percent' => $percent];
	}

	/**
	 * One owner command: the role is checked before the lock, again under
	 * it on execution and replay, and the acting owner is the one the
	 * receipt belongs to.
	 *
	 * @param array<string, mixed> $payload
	 * @param callable(): array<string, mixed> $aggregate
	 * @return array{result: array<string, mixed>, replay: bool}
	 */
	private function ownerCommand(int $houseId, string $commandId, string $action, array $payload, callable $aggregate): array {
		$owner = $this->authorization->requireHouseOwner($houseId);
		return $this->commandService->runCommand($houseId, (int)$owner->getId(), $commandId, $action, $payload, $aggregate,
			function (int $lockedHouseId, int $actorId): void {
				if ((int)$this->authorization->requireHouseOwner($lockedHouseId)->getId() !== $actorId) {
					throw new ForbiddenException($this->l10n->t('Not a member of this house'));
				}
			}, audited: true);
	}

	/** @throws \InvalidArgumentException */
	private function assertPeriod(string $startsOn, int $days, string $today): void {
		if (!$this->calendar->isRealDay($startsOn)) {
			throw new \InvalidArgumentException($this->l10n->t('A valid date (Y-m-d) is required'));
		}
		if ($days < BonusPeriod::MIN_DAYS || $days > BonusPeriod::MAX_DAYS) {
			throw new \InvalidArgumentException($this->l10n->t('Bonus days last one to three days'));
		}
		if ($startsOn < $today || RecurrenceCalculator::daysBetween($today, $startsOn) > self::START_RANGE_DAYS) {
			throw new \InvalidArgumentException($this->l10n->t('Bonus days start today or later, within one year'));
		}
	}

	/** @throws NotFoundException */
	private function memberOrNotFound(int $houseId, string $userId): HouseUser {
		return $this->houseUserMapper->findByHouseAndUser($houseId, $userId)
			?? throw new NotFoundException($this->l10n->t('User is not a member of this house'));
	}

	/** @throws NotFoundException */
	private function periodOrNotFound(int $houseId, int $periodId): BonusPeriod {
		return $this->periodMapper->findInHouse($periodId, $houseId)
			?? throw new NotFoundException($this->l10n->t('Bonus days not found'));
	}
}
