<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TitleService;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IL10N;

/**
 * API controller for user management
 */
class UserController extends OCSController
{
    private HouseUserMapper $houseUserMapper;
    private HouseService $houseService;
    private IUserManager $userManager;
    private AuthorizationService $authService;
    private TitleService $titleService;
    private IL10N $l;

    public function __construct(
        IRequest $request,
        HouseUserMapper $houseUserMapper,
        HouseService $houseService,
        IUserManager $userManager,
        AuthorizationService $authService,
        TitleService $titleService,
        IL10N $l
    ) {
        parent::__construct('chorestrike', $request);
        $this->houseUserMapper = $houseUserMapper;
        $this->houseService = $houseService;
        $this->userManager = $userManager;
        $this->authService = $authService;
        $this->titleService = $titleService;
        $this->l = $l;
    }

    /**
     * Serialize a HouseUser with its server-computed current title, so the
     * leaderboard and active-user views can render the title badge without a
     * separate round-trip. Delegates to TitleService (shared with
     * GameLogicService) so the payload shape cannot drift.
     */
    private function serializeUserWithTitle(HouseUser $user): array
    {
        return $this->titleService->enrichWithCurrentTitle($user->jsonSerialize(), $user);
    }

    /**
     * Get all users in the house
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $this->authService->ensureHouseMember($house->getId());

            $users = $this->houseUserMapper->findByHouseId($house->getId());

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn ($user) => $this->serializeUserWithTitle($user), $users)
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
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
     * Get the current authenticated house user.
     *
     * @NoAdminRequired
     */
    public function current(): JSONResponse
    {
        try {
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $houseUser = $this->authService->ensureHouseMember($house->getId());

            return new JSONResponse([
                'success' => true,
                'data' => $this->serializeUserWithTitle($houseUser),
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
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
     * @NoAdminRequired
     * @param string $email Email address of the user to invite.
     * @param string $role Role to assign to the invited user.
     */
    public function invite(string $email, string $role = 'member'): JSONResponse
    {
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

            // Find Nextcloud user by email (returns array of users)
            $ncUsers = $this->userManager->getByEmail($trimmedEmail);
            if (empty($ncUsers)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => [
                        'code' => 'USER_NOT_FOUND',
                        'message' => $this->l->t('No Nextcloud user found with this email address')
                    ]
                ], 404);
            }

            // Use the first matching user if multiple accounts share the same email
            $ncUser = $ncUsers[0];
            $nextcloudUserId = $ncUser->getUID();

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
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
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
    public function remove(int $id): JSONResponse
    {
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
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
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
    public function updateRole(int $id, string $role): JSONResponse
    {
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
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
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
     * Update user profile fields.
     *
     * @NoAdminRequired
     * @param int $id The internal house user identifier.
     * @param string|null $displayName Updated display name.
     * @param array|null $schedulingPreferences Updated scheduling preferences.
     */
    public function update(int $id, ?string $displayName = null, ?array $schedulingPreferences = null): JSONResponse
    {
        try {
            $houseUser = $this->houseUserMapper->find($id);
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $currentHouseUser = $this->authService->ensureHouseMember($house->getId());

            if ($houseUser->getHouseId() !== $house->getId()) {
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

            if ($displayName !== null) {
                $trimmedDisplayName = trim($displayName);
                if ($trimmedDisplayName === '') {
                    return new JSONResponse([
                        'success' => false,
                        'error' => [
                            'code' => 'VALIDATION_ERROR',
                            'message' => $this->l->t('Display name is required')
                        ]
                    ], 400);
                }

                $houseUser->setDisplayName($trimmedDisplayName);
            }

            if ($schedulingPreferences !== null) {
                $houseUser->setSchedulingPreferences($schedulingPreferences);
            }

            $updatedHouseUser = $this->houseUserMapper->update($houseUser);

            return new JSONResponse([
                'success' => true,
                'data' => $updatedHouseUser->jsonSerialize(),
            ]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => $this->l->t('User not found')
                ]
            ], 404);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_USER_ERROR',
                    'message' => $this->l->t('Failed to update user')
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
    public function titles(int $id): JSONResponse
    {
        try {
            $houseUser = $this->houseUserMapper->find($id);
            $this->authService->ensureHouseMember($houseUser->getHouseId());

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'currentTitle' => $this->titleService->getCurrentTitle($houseUser),
                    'unlockedTitles' => $this->titleService->getUnlockedTitles($houseUser),
                    'selectedTitlePack' => $houseUser->getSelectedTitlePack(),
                    'catalog' => $this->titleService->getCatalogView(),
                ],
            ]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => $this->l->t('User not found')
                ]
            ], 404);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
        } catch (\Exception $e) {
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
    public function selectTitle(int $id, string $pack): JSONResponse
    {
        try {
            $houseUser = $this->houseUserMapper->find($id);
            $instanceId = $this->houseService->getInstanceId();
            $house = $this->houseService->getOrCreateHouse($instanceId);
            $currentHouseUser = $this->authService->ensureHouseMember($house->getId());

            if ($houseUser->getHouseId() !== $house->getId()) {
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

            $this->titleService->selectPack($houseUser, $pack);

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'selectedTitlePack' => $houseUser->getSelectedTitlePack(),
                    'currentTitle' => $this->titleService->getCurrentTitle($houseUser),
                ],
            ]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => $this->l->t('User not found')
                ]
            ], 404);
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
            return new JSONResponse([
                'success' => false,
                'error' => [
                    'code' => 'SELECT_TITLE_ERROR',
                    'message' => $this->l->t('Failed to select title')
                ]
            ], 500);
        }
    }

    /**
     * Get user statistics
     *
     * @NoAdminRequired
     */
    public function stats(int $id): JSONResponse
    {
        try {
            $houseUser = $this->houseUserMapper->find($id);
            $this->authService->ensureHouseMember($houseUser->getHouseId());

            return new JSONResponse([
                'success' => true,
                'data' => [
                    'user' => $houseUser->jsonSerialize(),
                    'stats' => [
                        'contributionScore' => $houseUser->getContributionScore(),
                        'coins' => $houseUser->getCoins(),
                        'currentStreak' => $houseUser->getCurrentStreak(),
                        'lastActivity' => $houseUser->getLastActivity()?->format('c'),
                    ]
                ]
            ]);
        } catch (ForbiddenException $e) {
            return ControllerErrorFormatter::forbiddenResponse($e);
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
