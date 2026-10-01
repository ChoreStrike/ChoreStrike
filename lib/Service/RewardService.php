<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RewardItem;
use OCA\ChoreStrike\Db\RewardItemMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Catalog owner: reads and writes of the family reward catalog
 * (CS-PLAN-070 WP7, CS-PLAN-078 §3.3/§3.4).
 *
 * Commands run through `CommandService::runCommand()` with the WP7
 * under-lock authorization guard — a replay re-checks fresh identity and
 * owner authority before the receipt answers. Reads never mutate.
 *
 * The household gate (`houses.catalog_enabled`) is owner-controlled and
 * default off: while off, the active scope reports the disabled state and
 * no new request can be created, but planning/recognition keep working
 * and owners retain the admin scope plus existing-request processing.
 * Personal catalog-visibility preferences are untouched by every write
 * here. Owners may prepare/administer items while the catalog is off.
 */
class RewardService {
	public const SCOPE_ACTIVE = 'active';
	public const SCOPE_ADMIN = 'admin';

	/** Bounded lists (plan §3.3 proposal): default 50, maximum 100. */
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 100;

	private const MAX_TITLE_LENGTH = 120;
	private const MAX_DESCRIPTION_LENGTH = 2000;

	private CommandService $commands;
	private RewardItemMapper $itemMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private AuthorizationService $authorization;
	private EffortAllocationService $allocation;
	private IL10N $l10n;
	private AppLogger $appLogger;

	public function __construct(
		CommandService $commands,
		RewardItemMapper $itemMapper,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		AuthorizationService $authorization,
		EffortAllocationService $allocation,
		IL10N $l10n,
		AppLogger $appLogger,
	) {
		$this->commands = $commands;
		$this->itemMapper = $itemMapper;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->authorization = $authorization;
		$this->allocation = $allocation;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
	}

	/**
	 * The viewer-safe catalog list (bounded stable-cursor page).
	 *
	 * scope=active (default): active items only while the household gate
	 * is enabled, otherwise the explicit disabled state plus an empty
	 * list. scope=admin: owner-only, includes archived items even while
	 * the gate is off.
	 *
	 * @return array{catalogEnabled: bool, items: list<array<string, mixed>>}
	 */
	public function listItems(int $houseId, HouseUser $viewer, string $scope, ?int $cursorId = null, int $limit = self::DEFAULT_LIMIT): array {
		if ($scope !== self::SCOPE_ACTIVE && $scope !== self::SCOPE_ADMIN) {
			throw new \InvalidArgumentException($this->l10n->t('scope must be active or admin'));
		}
		if ((int)$viewer->getHouseId() !== $houseId) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		$house = $this->houseMapper->find($houseId);
		$catalogEnabled = $house->getCatalogEnabled();

		if ($scope === self::SCOPE_ADMIN) {
			if (!$viewer->isOwner()) {
				throw new ForbiddenException($this->l10n->t('Owner role required'));
			}
			$items = $this->itemMapper->findByHouseId($houseId, null, $cursorId, $limit);
		} elseif ($catalogEnabled) {
			$items = $this->itemMapper->findByHouseId($houseId, false, $cursorId, $limit);
		} else {
			$items = [];
		}

		return [
			'catalogEnabled' => $catalogEnabled,
			'items' => array_values(array_map(static fn (RewardItem $item): array => $item->jsonSerialize(), $items)),
		];
	}

	/**
	 * Owner-only catalog gate toggle (CS-PLAN-078 §3.3): a narrow,
	 * versioned command — `expectedVersion` is the house `stateVersion`.
	 * Serializes with request creation; never resets personal visibility
	 * preferences and never touches existing requests.
	 *
	 * @return array{catalogEnabled: bool, stateVersion: int}
	 */
	public function setCatalogEnabled(int $houseId, bool $catalogEnabled, int $expectedVersion, string $commandId): array {
		$actor = $this->authorization->requireHouseOwner($houseId);

		$outcome = $this->commands->runCommand(
			$houseId,
			(int)$actor->getId(),
			strtolower($commandId),
			'reward.catalog.set',
			['catalogEnabled' => $catalogEnabled, 'expectedVersion' => $expectedVersion],
			function () use ($houseId, $actor, $catalogEnabled, $expectedVersion): array {
				$this->requireOwnerUnderLock($houseId, (int)$actor->getId());
				$house = $this->houseMapper->find($houseId);
				if ($house->getStateVersion() !== $expectedVersion) {
					throw new ConflictException($this->l10n->t('The house state changed since it was displayed'));
				}
				if ($house->getCatalogEnabled() !== $catalogEnabled) {
					$house->setCatalogEnabled($catalogEnabled);
					$house->touch();
					$this->houseMapper->update($house);
					// Every reward mutation advances the aggregate version —
					// a no-op toggle is not a mutation.
					$this->houseMapper->bumpStateVersion($houseId);
				}
				return ['catalogEnabled' => $catalogEnabled];
			},
			$this->ownerGuard(),
		);

		$house = $this->houseMapper->find($houseId);
		$this->appLogger->info('Catalog gate updated', [
			'event' => 'reward_catalog_toggled',
			'service' => 'RewardService',
			'houseId' => $houseId,
			'catalogEnabled' => $catalogEnabled,
			'replay' => $outcome['replay'],
		]);
		return ['catalogEnabled' => $house->getCatalogEnabled(), 'stateVersion' => $house->getStateVersion()];
	}

	/**
	 * Owner-only item creation (whitelisted title/description/priceMinor).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed> The created catalog DTO
	 */
	public function createItem(int $houseId, array $payload): array {
		$actor = $this->authorization->requireHouseOwner($houseId);
		$commandId = $this->requireCommandId($payload);
		$terms = $this->normalizeTerms($payload, requireAll: true);

		$outcome = $this->commands->runCommand(
			$houseId,
			(int)$actor->getId(),
			strtolower($commandId),
			'reward.item.create',
			$terms,
			function () use ($houseId, $actor, $terms): array {
				$this->requireOwnerUnderLock($houseId, (int)$actor->getId());
				$now = new \DateTime();
				$item = new RewardItem();
				$item->setHouseId($houseId);
				$item->setTitle($terms['title']);
				$item->setDescription($terms['description']);
				$item->setPriceMinor($terms['priceMinor']);
				$item->setArchived(false);
				$item->setVersion(0);
				$item->setCreatedByHouseUserId((int)$actor->getId());
				$item->setCreatedByWalletId($actor->getWalletId());
				$item->setCreatedAt($now);
				$item->setUpdatedAt($now);
				$inserted = $this->itemMapper->insert($item);
				$this->houseMapper->bumpStateVersion($houseId);
				return ['itemId' => (int)$inserted->getId()];
			},
			$this->ownerGuard(),
		);

		$itemId = (int)$outcome['result']['itemId'];
		return $this->itemMapper->find($itemId)->jsonSerialize();
	}

	/**
	 * Owner-only item edit/archive. `expectedVersion` is the item
	 * version; unknown fields are rejected; a stale version is 409.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed> The updated catalog DTO
	 */
	public function updateItem(int $houseId, int $itemId, array $payload): array {
		$actor = $this->authorization->requireHouseOwner($houseId);
		$commandId = $this->requireCommandId($payload);
		$expectedVersion = $this->requireExpectedVersion($payload);
		$terms = $this->normalizeTerms($payload, requireAll: false);
		if (array_key_exists('archived', $payload)) {
			if (!is_bool($payload['archived'])) {
				throw new \InvalidArgumentException($this->l10n->t('archived must be a boolean'));
			}
			$terms['archived'] = $payload['archived'];
		}
		if ($terms === []) {
			throw new \InvalidArgumentException($this->l10n->t('At least one item field is required'));
		}
		$commandPayload = $terms;
		$commandPayload['expectedVersion'] = $expectedVersion;

		$outcome = $this->commands->runCommand(
			$houseId,
			(int)$actor->getId(),
			strtolower($commandId),
			'reward.item.update',
			$commandPayload,
			function () use ($houseId, $actor, $itemId, $expectedVersion, $terms): array {
				$this->requireOwnerUnderLock($houseId, (int)$actor->getId());
				$item = $this->requireHouseItem($itemId, $houseId);
				if ($item->getVersion() !== $expectedVersion) {
					throw new ConflictException($this->l10n->t('The item changed since it was displayed'));
				}
				if (array_key_exists('title', $terms)) {
					$item->setTitle($terms['title']);
				}
				if (array_key_exists('description', $terms)) {
					$item->setDescription($terms['description']);
				}
				if (array_key_exists('priceMinor', $terms)) {
					$item->setPriceMinor($terms['priceMinor']);
				}
				if (array_key_exists('archived', $terms)) {
					$item->setArchived($terms['archived']);
				}
				$item->setVersion($item->getVersion() + 1);
				$item->setUpdatedAt(new \DateTime());
				$this->itemMapper->update($item);
				$this->houseMapper->bumpStateVersion($houseId);
				return ['itemId' => $itemId, 'itemVersion' => $item->getVersion()];
			},
			$this->ownerGuard(),
		);

		$itemId = (int)$outcome['result']['itemId'];
		return $this->itemMapper->find($itemId)->jsonSerialize();
	}

	/**
	 * Authorized privacy erasure (called from AccountErasureService
	 * INSIDE its house-first transaction): purge the erased person's own
	 * catalog wishes. Items created by OTHERS are retained and carry no
	 * reference to the erased actor. Repeated erasure is harmless.
	 */
	public function eraseActorItemsUnderLock(?int $houseUserId, ?int $walletId): void {
		$this->itemMapper->deleteByCreator($houseUserId, $walletId);
	}

	/**
	 * The under-lock owner guard handed to every catalog command: fresh
	 * actor identity + current owner authority (replay-safe). No business
	 * version/state checks — a committed receipt already answered those.
	 */
	private function ownerGuard(): \Closure {
		return function (int $houseId, int $actorHouseUserId): void {
			$this->requireOwnerUnderLock($houseId, $actorHouseUserId);
		};
	}

	/**
	 * Fresh actor identity + owner authority under the held house lock.
	 *
	 * @throws ForbiddenException on a foreign-house or demoted actor
	 */
	private function requireOwnerUnderLock(int $houseId, int $actorHouseUserId): HouseUser {
		try {
			$actor = $this->houseUserMapper->findForUpdate($actorHouseUserId);
		} catch (DoesNotExistException $e) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		if ((int)$actor->getHouseId() !== $houseId || !$actor->isOwner()) {
			throw new ForbiddenException($this->l10n->t('Owner role required'));
		}
		return $actor;
	}

	/**
	 * The item scoped to the house (nonexistent/foreign → 404;
	 * anti-enumeration).
	 *
	 * @throws NotFoundException
	 */
	private function requireHouseItem(int $itemId, int $houseId): RewardItem {
		try {
			$item = $this->itemMapper->find($itemId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException($this->l10n->t('Reward item not found'));
		}
		if ((int)$item->getHouseId() !== $houseId) {
			throw new NotFoundException($this->l10n->t('Reward item not found'));
		}
		return $item;
	}

	/**
	 * Validate and normalize the whitelisted item terms: exact integer
	 * minor units, bounded plain text, unknown fields rejected (the
	 * command envelope keys are not terms).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function normalizeTerms(array $payload, bool $requireAll): array {
		$allowed = ['title', 'description', 'priceMinor'];
		$envelope = ['commandId', 'expectedVersion'];
		foreach (array_keys($payload) as $field) {
			if (!in_array($field, $allowed, true) && !in_array($field, $envelope, true) && !($field === 'archived' && !$requireAll)) {
				throw new \InvalidArgumentException($this->l10n->t('Unknown field in the request'));
			}
		}
		$terms = [];
		$hasTitle = array_key_exists('title', $payload);
		$hasPrice = array_key_exists('priceMinor', $payload);
		if ($requireAll && (!$hasTitle || !$hasPrice)) {
			throw new \InvalidArgumentException($this->l10n->t('A title and a positive priceMinor are required'));
		}
		if ($hasTitle) {
			$terms['title'] = $this->normalizeText($payload['title'], self::MAX_TITLE_LENGTH, true);
		}
		if (array_key_exists('description', $payload)) {
			$terms['description'] = $payload['description'] === null
				? null
				: $this->normalizeText($payload['description'], self::MAX_DESCRIPTION_LENGTH, false);
		}
		if ($hasPrice) {
			$terms['priceMinor'] = $this->normalizePrice($payload['priceMinor']);
		}
		return $terms;
	}

	/**
	 * Plain-text normalization: string, trimmed bounds, control
	 * characters rejected.
	 */
	private function normalizeText(mixed $value, int $maxLength, bool $required): string {
		if (!is_string($value)) {
			throw new \InvalidArgumentException($this->l10n->t('A text field must be a string'));
		}
		$value = trim($value);
		if ($value === '') {
			if ($required) {
				throw new \InvalidArgumentException($this->l10n->t('A title is required'));
			}
			return '';
		}
		if (mb_strlen($value) > $maxLength) {
			throw new \InvalidArgumentException($this->l10n->t('%1$s must not exceed %2$d characters', [$this->l10n->t('Text'), $maxLength]));
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
			throw new \InvalidArgumentException($this->l10n->t('Control characters are not allowed'));
		}
		return $value;
	}

	/**
	 * Exact money boundary: reject floats, numeric strings, zero/negative
	 * values and unsafe integers (WP7 §3.2). Decimal parsing belongs to
	 * WP8 — the API carries integer hundredths only.
	 *
	 * @return int Positive minor units
	 */
	private function normalizePrice(mixed $value): int {
		if (!is_int($value)) {
			throw new \InvalidArgumentException($this->l10n->t('priceMinor must be a positive whole number of hundredths'));
		}
		if ($value <= 0) {
			throw new \InvalidArgumentException($this->l10n->t('priceMinor must be a positive whole number of hundredths'));
		}
		try {
			$this->allocation->assertSafeAmount($value, 'priceMinor');
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException($this->l10n->t('priceMinor must be a positive whole number of hundredths'));
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requireCommandId(array $payload): string {
		return CommandService::requireCommandUuid($payload, $this->l10n);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requireExpectedVersion(array $payload): int {
		return CommandService::requireExpectedVersion($payload, $this->l10n);
	}
}
