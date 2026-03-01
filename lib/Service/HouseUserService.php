<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Service for managing house users
 */
class HouseUserService {

	public function __construct(
		private HouseUserMapper $houseUserMapper,
	) {
	}

	/**
	 * Add user to house
	 */
	public function addUserToHouse(int $houseId, string $userId, string $displayName, string $role = 'member'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setHouseId($houseId);
		$houseUser->setNextcloudUserId($userId);
		$houseUser->setDisplayName($displayName);
		$houseUser->setRole($role);
		$houseUser->setJoinedAt(new \DateTime());
		return $this->houseUserMapper->insert($houseUser);
	}

	/**
	 * Remove user from house
	 */
	public function removeUserFromHouse(int $houseId, string $userId): void {
		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		$this->houseUserMapper->delete($houseUser);
	}

	/**
	 * Update user role
	 */
	public function updateUserRole(int $houseId, string $userId, string $role): HouseUser {
		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		$houseUser->setRole($role);
		return $this->houseUserMapper->update($houseUser);
	}

	/**
	 * Get user in house
	 */
	public function getUserInHouse(int $houseId, string $userId): HouseUser {
		return $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
	}

	/**
	 * Get all users in house
	 *
	 * @return HouseUser[]
	 */
	public function getHouseUsers(int $houseId): array {
		return $this->houseUserMapper->findByHouse($houseId);
	}

	/**
	 * Get active users in house
	 *
	 * @return HouseUser[]
	 */
	public function getActiveUsers(int $houseId): array {
		return $this->houseUserMapper->findActiveByHouse($houseId);
	}

	/**
	 * Check if user is owner of house
	 */
	public function isOwner(int $houseId, string $userId): bool {
		try {
			$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
			return $houseUser->isOwner();
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return false;
		}
	}

	/**
	 * Check if user is member of house
	 */
	public function isMember(int $houseId, string $userId): bool {
		try {
			$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
			return $houseUser->getRole() === 'member';
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return false;
		}
	}

	/**
	 * Check if user is child in house
	 */
	public function isChild(int $houseId, string $userId): bool {
		try {
			$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
			return $houseUser->isChild();
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return false;
		}
	}

	/**
	 * Get house owners
	 *
	 * @return HouseUser[]
	 */
	public function getOwners(int $houseId): array {
		return $this->houseUserMapper->findByHouseAndRole($houseId, 'owner');
	}

	/**
	 * Get house members (excluding owner)
	 *
	 * @return HouseUser[]
	 */
	public function getMembers(int $houseId): array {
		return $this->houseUserMapper->findByHouseAndRole($houseId, 'member');
	}

	/**
	 * Get house children
	 *
	 * @return HouseUser[]
	 */
	public function getChildren(int $houseId): array {
		return $this->houseUserMapper->findByHouseAndRole($houseId, 'child');
	}
}
