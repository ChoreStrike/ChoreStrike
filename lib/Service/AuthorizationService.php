<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCP\IUserSession;

/**
 * Service for authorization checks across the application.
 */
class AuthorizationService {

	private HouseUserMapper $houseUserMapper;
	private IUserSession $userSession;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		IUserSession $userSession
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->userSession = $userSession;
	}

	/**
	 * Check if the current user is authenticated.
	 */
	public function isAuthenticated(): bool {
		$user = $this->userSession->getUser();
		return $user !== null;
	}

	/**
	 * Get the current Nextcloud user ID.
	 */
	public function getCurrentUserId(): ?string {
		$user = $this->userSession->getUser();
		return $user?->getUID();
	}

	/**
	 * Check if the current user is a member of the given house.
	 */
	public function isHouseMember(int $houseId): bool {
		$userId = $this->getCurrentUserId();
		if ($userId === null) {
			return false;
		}

		try {
			$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
			return $houseUser !== null;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Require the current user to be a house member.
	 *
	 * @throws \OCP\AppFramework\Http\NotFoundException
	 */
	public function requireHouseMember(int $houseId): HouseUser {
		$userId = $this->getCurrentUserId();
		if ($userId === null) {
			throw new \OCP\AppFramework\Http\NotFoundException('Not authenticated');
		}

		$houseUser = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		if ($houseUser === null) {
			throw new \OCP\AppFramework\Http\NotFoundException('Not a house member');
		}

		return $houseUser;
	}

	/**
	 * Check if the current user has the owner role in the given house.
	 */
	public function isHouseOwner(int $houseId): bool {
		try {
			$houseUser = $this->requireHouseMember($houseId);
			return $houseUser->isOwner();
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Require the current user to be a house owner.
	 *
	 * @throws \OCP\AppFramework\Http\NotFoundException
	 * @throws \OCP\AppFramework\Http\ForbiddenException
	 */
	public function requireHouseOwner(int $houseId): HouseUser {
		$houseUser = $this->requireHouseMember($houseId);

		if (!$houseUser->isOwner()) {
			throw new \OCP\AppFramework\Http\ForbiddenException('Owner role required');
		}

		return $houseUser;
	}
}
