<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\CannotDemoteSelfException;
use OCA\ChoreStrike\Exception\CannotRemoveOwnerException;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TitleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
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
	#[UserRateLimit(limit: 60, period: 300)]
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
	#[UserRateLimit(limit: 60, period: 300)]
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
				// CS-PLAN-081 H3: the removal actor comes from the
				// authoritative service-side owner gate, not a parameter.
				$this->houseService->removeHouseMember($houseUser->getId());
			} catch (CannotRemoveOwnerException $e) {
				// The service's self-removal guard (FEAT-UX-015: another
				// owner may be removed) — map it to the endpoint-specific 403
				// code (response contract unchanged). A racing-demotion
				// denial surfaces as the generic 403 below.
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'CANNOT_REMOVE_OWNER',
						'message' => $this->l->t('You cannot remove yourself from the house')
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
	#[UserRateLimit(limit: 60, period: 300)]
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

			try {
				// CS-PLAN-081 H3: the self-demotion actor identity is derived
				// from the session inside the service, not passed by callers.
				$updatedUser = $this->houseService->updateUserRole($id, $role);
			} catch (\InvalidArgumentException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => $this->l->t('Invalid role. Must be owner, member, or child')
					]
				], 400);
			} catch (CannotDemoteSelfException $e) {
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
	 * Get the role-title state for a user: current title (always the
	 * highest qualifying retained rhythm tier — no pack selection since
	 * Q-075-02 A), all live-derived unlocked tiers, and the fixed catalog.
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

	// `selectTitle` was retired 2026-09-27 with the multi-pack surface
	// (CS-PLAN-070 WP4, Q-075-02 Option A): the current title is always the
	// highest qualifying retained rhythm tier, so there is nothing to
	// select.
}
