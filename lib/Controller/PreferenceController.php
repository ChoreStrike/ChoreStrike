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
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\ColorSchemeService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\PreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for the SELF-ONLY personal preferences
 * (CS-PLAN-076 §3.1, C5h).
 *
 * Thin endpoint: authorization gates run here; validation and the
 * versioned update live in PreferenceService, the colour scheme in
 * ColorSchemeService (CS-PLAN-090 WP-K). There is deliberately no route
 * for an owner to read or write another member's preferences —
 * `/preferences/me` is the only surface, children included.
 */
class PreferenceController extends Controller {
	use ControllerLoggingTrait;

	private PreferenceService $preferenceService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private ColorSchemeService $colorSchemes;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		PreferenceService $preferenceService,
		HouseService $houseService,
		AuthorizationService $authService,
		ColorSchemeService $colorSchemes,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->preferenceService = $preferenceService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->colorSchemes = $colorSchemes;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * The calling member's own preference payload (versioned own settings
	 * incl. the effective ranking start, plus the colour scheme).
	 *
	 * @NoAdminRequired
	 */
	public function show(): JSONResponse {
		try {
			[$houseId, $houseUser] = $this->currentMember();

			$payload = $this->preferenceService->getOwnPreferences($houseId, $houseUser);
			$payload['colorScheme'] = $this->colorSchemes->get((string)$houseUser->getNextcloudUserId());

			return new JSONResponse([
				'success' => true,
				'data' => $payload,
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('show', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'PREFERENCES_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Update the calling member's own delivery preferences. Unknown
	 * fields are a 400 before any side effect; a stale `expectedVersion`
	 * is a 409 that preserves the client draft; a reused command key
	 * with different input conflicts.
	 *
	 * @NoAdminRequired
	 */
	public function update(): JSONResponse {
		try {
			[$houseId, $houseUser] = $this->currentMember();

			$input = $this->request->getParams();
			unset($input['_route']);
			$commandId = $input['commandId'] ?? null;
			if (!is_string($commandId) || trim($commandId) === '' || strlen($commandId) > 36) {
				throw new \InvalidArgumentException($this->l->t('A valid commandId (UUID) is required'));
			}
			unset($input['commandId']);

			$payload = $this->preferenceService->updateOwnPreferences(
				$houseId,
				$houseUser,
				$input,
				$commandId
			);

			return new JSONResponse([
				'success' => true,
				'data' => $payload,
				'message' => $this->l->t('Preferences saved'),
			]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e, 'PREFERENCES_VALIDATION_ERROR');
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('update', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'PREFERENCES_UPDATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Set the calling member's own colour scheme (CS-PLAN-090 WP-K,
	 * D-090-61). A plain per-user setting outside the versioned row: the
	 * last save wins on every device, and an unknown scheme is a 400
	 * before any write.
	 *
	 * @NoAdminRequired
	 */
	public function updateColorScheme(): JSONResponse {
		try {
			[, $houseUser] = $this->currentMember();

			$scheme = $this->colorSchemes->setOwn($houseUser, $this->request->getParam('colorScheme'));

			return new JSONResponse([
				'success' => true,
				'data' => ['colorScheme' => $scheme],
			]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e, 'COLOR_SCHEME_VALIDATION_ERROR');
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('updateColorScheme', $e);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'COLOR_SCHEME_UPDATE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * The instance house and the caller's membership in it (read-only
	 * gate, never auto-joins).
	 *
	 * @return array{0: int, 1: HouseUser}
	 * @throws ForbiddenException for a non-member
	 */
	private function currentMember(): array {
		$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
		$houseUser = $this->authService->assertHouseMember($house->getId());
		return [(int)$house->getId(), $houseUser];
	}
}
