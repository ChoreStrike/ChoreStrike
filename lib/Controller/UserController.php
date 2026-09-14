<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TitleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for user management
 *
 * Thin endpoint (RF-058-04): all membership persistence (invite write,
 * scoped member lookups, member list) lives in HouseService — the
 * controller validates input, runs the authorization gates and shapes
 * responses.
 */
class UserController extends Controller {
	use ControllerLoggingTrait;

	private HouseService $houseService;
	private AuthorizationService $authService;
	private TitleService $titleService;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		HouseService $houseService,
		AuthorizationService $authService,
		TitleService $titleService,
		AppLogger $appLogger,
		IL10N $l,
	) {
		parent::__construct('chorestrike', $request);
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->titleService = $titleService;
		$this->initializeControllerLogger($appLogger);
		$this->l = $l;
	}

	/**
	 * Serialize a HouseUser with its server-computed current title, so the
	 * leaderboard and active-user views can render the title badge without a
	 * separate round-trip. Delegates to TitleService (shared with
	 * GameLogicService) so the payload shape cannot drift.
	 */
	private function serializeUserWithTitle(HouseUser $user): array {
		return $this->titleService->enrichWithCurrentTitle($user->jsonSerialize(), $user);
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
			$this->authService->assertHouseMember($house->getId());

			$users = $this->houseService->getHouseMembers($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($user) => $this->serializeUserWithTitle($user), $users)
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('index', $e);
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
	 * Get the current authenticated house user.
	 *
	 * @NoAdminRequired
	 */
	public function current(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			// Designated bootstrap endpoint (ADR-010, D1): the frontend boots
			// through this call, so the very first user of a fresh instance
			// becomes the owner here. Afterwards membership is invite-only.
			$houseUser = $this->authService->ensureHouseMember($house->getId());

			return new JSONResponse([
				'success' => true,
				'data' => $this->serializeUserWithTitle($houseUser),
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('current', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CURRENT_USER_ERROR',
					'message' => $this->l->t('Failed to retrieve current user')
				]
			], 500);
		}
	}

	/**
	 * Invite a user to the house by email address.
	 *
	 * Thin endpoint (RF-058-04): the gates run here, the email lookup and
	 * membership write live in HouseService::inviteMember.
	 *
	 * @NoAdminRequired
	 * @param string $email Email address of the user to invite.
	 * @param string $role Role to assign to the invited user.
	 */
	public function invite(string $email, string $role = 'member'): JSONResponse {
		try {
			$trimmedEmail = trim($email);
			if (empty($trimmedEmail)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Email is required')
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

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			// Designated bootstrap endpoint (ADR-010, D1): keeps the CR-SVC-47
			// preamble so the first user on a fresh instance becomes the owner
			// before the owner gate below.
			$this->authService->ensureHouseMember($house->getId());
			$this->authService->requireHouseOwner($house->getId());

			// Authorization gates run BEFORE the email lookup (CS-PLAN-061
			// review fix): a non-owner must not be able to probe which
			// Nextcloud accounts exist for a given email (404 vs 403 oracle).
			$houseUser = $this->houseService->inviteMember($house->getId(), $trimmedEmail, $role);

			return new JSONResponse([
				'success' => true,
				'data' => $houseUser->jsonSerialize(),
				'message' => $this->l->t('User invited to house')
			]);
		} catch (NotFoundException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'USER_NOT_FOUND',
					'message' => $e->getMessage()
				]
			], 404);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('invite', $e);
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
	 * Thin endpoint (CR-SVC-26): the owner gate runs here, the aggregate
	 * removal (membership row + task-assignee cleanup, transactionally)
	 * lives in HouseService::removeHouseMember.
	 *
	 * @NoAdminRequired
	 */
	public function remove(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());

			$houseUser = $this->houseService->findHouseUserScopedToHouse($id, $house->getId());
			if ($houseUser === null) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'USER_NOT_FOUND',
						'message' => $this->l->t('User not found')
					]
				], 404);
			}

			$this->authService->requireHouseOwner($house->getId());

			try {
				$this->houseService->removeHouseMember($houseUser->getId());
			} catch (ForbiddenException $e) {
				// The only ForbiddenException removeHouseMember throws is
				// its owner-target guard — map it to the endpoint-specific
				// 403 code (response contract unchanged). House-access
				// forbiddens are handled as 403 by the outer catch below.
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'CANNOT_REMOVE_OWNER',
						'message' => $this->l->t('Cannot remove house owner')
					]
				], 403);
			}

			return new JSONResponse([
				'success' => true,
				'message' => $this->l->t('User removed from house')
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('remove', $e, ['userId' => $id]);
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
	 * Thin endpoint (BUG-SEC-002): the owner gate runs here, the role
	 * mutation and its self-demotion / last-owner guards live in
	 * HouseService::updateUserRole.
	 *
	 * @NoAdminRequired
	 */
	public function updateRole(int $id, string $role): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());

			$houseUser = $this->houseService->findHouseUserScopedToHouse($id, $house->getId());
			if ($houseUser === null) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'USER_NOT_FOUND',
						'message' => $this->l->t('User not found')
					]
				], 404);
			}

			$this->authService->requireHouseOwner($house->getId());
			$callerUserId = $this->authService->requireCurrentUserId();

			try {
				$updatedUser = $this->houseService->updateUserRole($id, $role, $callerUserId);
			} catch (\InvalidArgumentException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Invalid role. Must be owner, member, or child')
					]
				], 400);
			} catch (ForbiddenException $e) {
				// The only ForbiddenException updateUserRole throws is its
				// self-demotion guard — map it to the endpoint-specific 403
				// code (mirrors the CANNOT_REMOVE_OWNER precedent).
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'CANNOT_DEMOTE_SELF',
						'message' => $this->l->t('You cannot change your own role')
					]
				], 403);
			} catch (ConflictException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'CANNOT_DEMOTE_LAST_OWNER',
						'message' => $this->l->t('Cannot demote the last house owner')
					]
				], 409);
			}

			return new JSONResponse([
				'success' => true,
				'data' => $updatedUser->jsonSerialize()
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('updateRole', $e, ['userId' => $id]);
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
	 * Get the role-title state for a user: current title, all unlocked
	 * tiers (live-derived from stats), and the full pack/tier catalog.
	 *
	 * @NoAdminRequired
	 */
	public function titles(int $id): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->assertHouseMember($house->getId());

			$houseUser = $this->houseService->findHouseUserScopedToHouse($id, $house->getId());
			if ($houseUser === null) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'USER_NOT_FOUND',
						'message' => $this->l->t('User not found')
					]
				], 404);
			}

			return new JSONResponse([
				'success' => true,
				'data' => [
					'currentTitle' => $this->titleService->getCurrentTitle($houseUser),
					'unlockedTitles' => $this->titleService->getUnlockedTitles($houseUser),
					'selectedTitlePack' => $houseUser->getSelectedTitlePack(),
					'catalog' => $this->titleService->getCatalogView(),
				],
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('titles', $e, ['userId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TITLES_ERROR',
					'message' => $this->l->t('Failed to retrieve titles')
				]
			], 500);
		}
	}

	/**
	 * Select the title pack the user displays.
	 *
	 * Titles are identity, not admin — a member picks their own pack. The
	 * requester must be the target user or a house owner.
	 *
	 * @NoAdminRequired
	 * @param int $id The internal house user identifier.
	 * @param string $pack Pack key to display.
	 */
	public function selectTitle(int $id, string $pack): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$currentHouseUser = $this->authService->assertHouseMember($house->getId());

			// Caller-scoped lookup (CS-PLAN-061 review fix): the membership
			// gate runs BEFORE the target lookup, and a nonexistent and a
			// foreign-house id both map to the same 404 — the old
			// find-before-gate order leaked which global house_user ids
			// exist to non-members (the selectTitle twin of the
			// remove/updateRole oracle closed in §3.1).
			$houseUser = $this->houseService->findHouseUserScopedToHouse($id, $house->getId());
			if ($houseUser === null) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'USER_NOT_FOUND',
						'message' => $this->l->t('User not found')
					]
				], 404);
			}

			$isOwner = $currentHouseUser->isOwner();
			$isSelf = $currentHouseUser->getNextcloudUserId() === $houseUser->getNextcloudUserId();
			if (!$isOwner && !$isSelf) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'FORBIDDEN',
						'message' => $this->l->t('You are not allowed to update this user')
					]
				], 403);
			}

			$this->titleService->selectPack($houseUser, $currentHouseUser, $pack);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'selectedTitlePack' => $houseUser->getSelectedTitlePack(),
					'currentTitle' => $this->titleService->getCurrentTitle($houseUser),
				],
			]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'VALIDATION_ERROR',
					'message' => $this->l->t('Invalid title pack')
				]
			], 400);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('selectTitle', $e, ['userId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SELECT_TITLE_ERROR',
					'message' => $this->l->t('Failed to select title')
				]
			], 500);
		}
	}
}
