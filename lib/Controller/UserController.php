<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IL10N;

/**
 * API controller for user management
 */
class UserController extends OCSController {

	private HouseUserMapper $houseUserMapper;
	private HouseService $houseService;
	private IUserManager $userManager;
	private AuthorizationService $authService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		HouseUserMapper $houseUserMapper,
		HouseService $houseService,
		IUserManager $userManager,
		AuthorizationService $authService,
		IL10N $l
	) {
		parent::__construct('chorestrike', $request);
		$this->houseUserMapper = $houseUserMapper;
		$this->houseService = $houseService;
		$this->userManager = $userManager;
		$this->authService = $authService;
		$this->l = $l;
	}

	/**
	 * Get all users in the house
	 *
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());
			
			$users = $this->houseUserMapper->findByHouseId($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($user) => $user->jsonSerialize(), $users)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'USERS_ERROR',
					'message' => $this->l->t('Failed to retrieve users')
				]
			], 500);
		}
	}

	/**
	 * Invite a user to the house
	 *
	 * @NoAdminRequired
	 */
	public function invite(string $nextcloudUserId, string $role = 'member'): JSONResponse {
		try {
			if (empty(trim($nextcloudUserId))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('User ID is required')
					]
				], 400);
			}

			if (!in_array($role, ['owner', 'member', 'child'], true)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Invalid role. Must be owner, member, or child')
					]
				], 400);
			}

			$ncUser = $this->userManager->get($nextcloudUserId);
			if ($ncUser === null) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'USER_NOT_FOUND',
						'message' => $this->l->t('Nextcloud user not found')
					]
				], 404);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseOwner($house->getId());

			$houseUser = $this->houseUserMapper->createOrGet(
				$house->getId(),
				$nextcloudUserId,
				$ncUser->getDisplayName(),
				$role
			);

			return new JSONResponse([
				'success' => true,
				'data' => $houseUser->jsonSerialize(),
				'message' => $this->l->t('User invited to house')
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'INVITE_ERROR',
					'message' => $this->l->t('Failed to invite user')
				]
			], 500);
		}
	}

	/**
	 * Remove a user from the house
	 *
	 * @NoAdminRequired
	 */
	public function remove(int $id): JSONResponse {
		try {
			$houseUser = $this->houseUserMapper->find($id);
			$this->authService->requireHouseOwner($houseUser->getHouseId());

			if ($houseUser->isOwner()) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'CANNOT_REMOVE_OWNER',
						'message' => $this->l->t('Cannot remove house owner')
					]
				], 403);
			}

			$this->houseUserMapper->delete($houseUser);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l->t('User removed from house')
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'REMOVE_ERROR',
					'message' => $this->l->t('Failed to remove user')
				]
			], 500);
		}
	}

	/**
	 * Update user role
	 *
	 * @NoAdminRequired
	 */
	public function updateRole(int $id, string $role): JSONResponse {
		try {
			$houseUser = $this->houseUserMapper->find($id);
			$this->authService->requireHouseOwner($houseUser->getHouseId());
			
			if (!in_array($role, ['owner', 'member', 'child'], true)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Invalid role. Must be owner, member, or child')
					]
				], 400);
			}

			$houseUser->setRole($role);
			$this->houseUserMapper->update($houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => $houseUser->jsonSerialize()
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'UPDATE_ROLE_ERROR',
					'message' => $this->l->t('Failed to update user role')
				]
			], 500);
		}
	}

	/**
	 * Get user statistics
	 *
	 * @NoAdminRequired
	 */
	public function stats(int $id): JSONResponse {
		try {
			$houseUser = $this->houseUserMapper->find($id);
			$this->authService->requireHouseMember($houseUser->getHouseId());

			return new JSONResponse([
				'success' => true,
				'data' => [
					'user' => $houseUser->jsonSerialize(),
					'stats' => [
						'contributionScore' => $houseUser->getContributionScore(),
						'teamSparks' => $houseUser->getTeamSparks(),
						'currentStreak' => $houseUser->getCurrentStreak(),
						'lastActivity' => $houseUser->getLastActivity()?->format('c'),
					]
				]
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'STATS_ERROR',
					'message' => $this->l->t('Failed to retrieve user stats')
				]
			], 500);
		}
	}
}
