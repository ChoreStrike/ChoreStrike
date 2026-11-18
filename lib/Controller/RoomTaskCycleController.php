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
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\CurrentCycle;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\PostponeService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * What members do with the current cycle of a room task besides completing
 * it (CS-PLAN-091 WP-B3, ADR-021 decisions 6 and 9): postpone it, and read
 * the task's history. Both are open to every member who may see the task,
 * children included.
 */
class RoomTaskCycleController extends Controller {
	use ControllerLoggingTrait;

	public function __construct(
		IRequest $request,
		private HouseService $houseService,
		private AuthorizationService $authService,
		private PostponeService $postponeService,
		private ActivityService $activityService,
		private RoomTaskReadService $roomTaskReadService,
		private IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Postpone the current cycle. Closed body: `commandId`, `cycleKey` and
	 * `expectedVersion` from the row's `cycle` block.
	 *
	 * @NoAdminRequired
	 */
	public function postpone(int $id): JSONResponse {
		try {
			$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$payload = $this->request->getParams();
			unset($payload['id'], $payload['_route']);
			foreach (array_keys($payload) as $key) {
				if (!in_array($key, ['commandId', 'cycleKey', 'expectedVersion'], true)) {
					throw new \InvalidArgumentException($this->l->t('Unknown field: %1$s', [$key]));
				}
			}
			$commandId = CommandService::requireCommandUuid($payload, $this->l);
			$expectedVersion = CommandService::requireExpectedVersion($payload, $this->l);
			if (!CurrentCycle::isKey($payload['cycleKey'] ?? null)) {
				throw new \InvalidArgumentException($this->l->t('Invalid cycle key'));
			}

			$result = $this->postponeService->postpone(
				(int)$house->getId(), $houseUser, $id, strtolower($commandId), $payload['cycleKey'], $expectedVersion
			);
			return new JSONResponse(['success' => true, 'data' => [
				'roomTask' => $this->roomTaskReadService->roomTaskPayload(
					$result['roomTask'],
					$this->roomTaskReadService->timeZoneForHouse((int)$house->getId()),
					$result['cycle']
				),
				'cycle' => $result['cycle'],
				'replay' => $result['replay'],
			]]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => ['code' => 'VALIDATION_ERROR', 'message' => ControllerErrorFormatter::safeMessage($e)],
			], 400);
		} catch (\Exception $e) {
			$this->logControllerException('postpone', $e, ['roomTaskId' => $id]);
			return ControllerErrorFormatter::serverErrorResponse($e, 'POSTPONE_TASK_ERROR');
		}
	}

	/**
	 * The history of one task: its valid entries, newest first, with the
	 * viewer's correction rights.
	 *
	 * @NoAdminRequired
	 */
	public function history(int $id, ?int $limit = null): JSONResponse {
		try {
			$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$data = $this->activityService->getRoomTaskHistory(
				(int)$house->getId(), $id, $limit ?? ActivityService::HISTORY_LIMIT, $houseUser
			);
			return new JSONResponse(['success' => true, 'data' => $data]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException('history', $e, ['roomTaskId' => $id]);
			return ControllerErrorFormatter::serverErrorResponse($e, 'TASK_HISTORY_ERROR');
		}
	}
}
