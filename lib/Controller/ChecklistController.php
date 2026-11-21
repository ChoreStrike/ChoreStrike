<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\CurrentCycle;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * API controller for checklist-related endpoints
 *
 * Thin controller (CR-053-01): house preamble + input validation +
 * delegation to {@see ChecklistService}, which owns the authorization
 * guard (house linkage + task-visibility matrix, denials map to 404).
 */
class ChecklistController extends Controller {
	use ControllerLoggingTrait;

	private ChecklistService $checklistService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IL10N $l;
	private int $houseId = 0;

	public function __construct(
		IRequest $request,
		ChecklistService $checklistService,
		HouseService $houseService,
		AuthorizationService $authService,
		IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->checklistService = $checklistService;
		$this->houseService = $houseService;
		$this->authService = $authService;
		$this->l = $l;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Get checklist items for a task
	 *
	 * @NoAdminRequired
	 */
	public function getTaskItems(int $taskId): JSONResponse {
		try {
			$houseUser = $this->requireViewer();

			$items = $this->checklistService->getTaskItems($taskId, $this->houseId, $houseUser);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn ($item) => $item->jsonSerialize(), $items)
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'CHECKLIST_ITEMS_ERROR');
		}
	}

	/**
	 * Get the sub-task ticks of the current cycle of a room task
	 * (ADR-021 decision 3). `cycleKey` is the cycle the client shows;
	 * without it the current cycle is used. The response carries each
	 * row's cycle key and progress revision plus the resolved cycle.
	 *
	 * @NoAdminRequired
	 */
	public function getRoomTaskProgress(int $roomTaskId): JSONResponse {
		try {
			$houseUser = $this->requireViewer();
			$cycleKey = $this->request->getParam('cycleKey');
			$validationError = $this->validateCycleKey($cycleKey);
			if ($validationError !== null) {
				return $validationError;
			}

			$result = $this->checklistService->getRoomTaskProgress(
				$roomTaskId,
				is_string($cycleKey) && $cycleKey !== '' ? $cycleKey : null,
				$this->houseId,
				$houseUser
			);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'items' => array_map(fn ($p) => $p->jsonSerialize(), $result['items']),
					'stats' => $result['stats'],
					'cycle' => $result['cycle']
				]
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'CHECKLIST_PROGRESS_ERROR');
		}
	}

	/**
	 * Toggle a sub-task of the current cycle of a room task (ADR-021
	 * decision 3): `cycleKey` names the cycle the client shows (default:
	 * the current one), `expectedRevision` is the stale-mutation guard —
	 * a cycle or progress state that moved on answers 409 `STALE_TARGET`
	 * instead of leaking into a later cycle.
	 *
	 * @NoAdminRequired
	 */
	public function toggleItem(int $roomTaskId, int $itemId): JSONResponse {
		try {
			$houseUser = $this->requireViewer();
			$cycleKey = $this->request->getParam('cycleKey');
			$validationError = $this->validateCycleKey($cycleKey);
			if ($validationError !== null) {
				return $validationError;
			}
			$expectedRevision = $this->request->getParam('expectedRevision');
			if ($expectedRevision !== null && (!is_numeric($expectedRevision) || (int)$expectedRevision < 0)) {
				return ControllerErrorFormatter::validation(new \InvalidArgumentException(
					$this->l->t('expectedRevision must be a non-negative integer')
				));
			}

			$result = $this->checklistService->toggleItem(
				$roomTaskId,
				is_string($cycleKey) && $cycleKey !== '' ? $cycleKey : null,
				$itemId,
				$expectedRevision !== null ? (int)$expectedRevision : null,
				$this->houseId,
				$houseUser
			);

			return new JSONResponse([
				'success' => true,
				'data' => [
					'progress' => $result['progress']->jsonSerialize(),
					'stats' => $result['stats'],
					'cycle' => $result['cycle']
				]
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('checklist', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'TOGGLE_CHECKLIST_ITEM_ERROR');
		}
	}

	/**
	 * Validate a supplied cycle key at the controller boundary
	 * (RF-072-WP1-13): a malformed key is a clean 400, never an exception
	 * escaping into the generic 500 handler — the completion route applies
	 * the same contract.
	 */
	private function validateCycleKey(mixed $cycleKey): ?JSONResponse {
		if ($cycleKey === null || $cycleKey === '' || CurrentCycle::isKey($cycleKey)) {
			return null;
		}
		return ControllerErrorFormatter::validation(new \InvalidArgumentException($this->l->t('Invalid cycle key')));
	}

	/**
	 * Resolve the caller's house and membership (the shared preamble).
	 */
	private function requireViewer(): \OCA\ChoreStrike\Db\HouseUser {
		$instanceId = $this->houseService->getInstanceId();
		$house = $this->houseService->getOrCreateHouse($instanceId);
		$this->houseId = $house->getId();
		return $this->authService->assertHouseMember($this->houseId);
	}
}
