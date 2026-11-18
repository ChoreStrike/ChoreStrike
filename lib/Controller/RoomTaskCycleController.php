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
use OCA\ChoreStrike\Service\CycleReceipt;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\PostponeService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\SleepService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * What members do with the current cycle of a room task besides completing
 * it (CS-PLAN-091 WP-B3 and WP-B4, ADR-021 decisions 6, 7 and 9): postpone
 * it, read the task's history and put the task to sleep. Postponing and
 * the history are open to every member who may see the task, children
 * included; sleeping is for adults and the owner, decided in the service.
 */
class RoomTaskCycleController extends Controller {
	use ControllerLoggingTrait;

	public function __construct(
		IRequest $request,
		private HouseService $houseService,
		private AuthorizationService $authService,
		private PostponeService $postponeService,
		private SleepService $sleepService,
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

			$receipt = CycleReceipt::fromPayload($this->body(), $this->l, CycleReceipt::KEY_REQUIRED);
			return $this->cycleResponse((int)$house->getId(), $this->postponeService->postpone(
				(int)$house->getId(), $houseUser, $id, $receipt->commandId, $receipt->cycleKey, $receipt->expectedVersion
			));
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
	 * Put a room task to sleep, move its wake date, or wake it. Closed
	 * body: `commandId`, `expectedVersion` from the row's `cycle` block,
	 * `sleeping` and, with it, an optional `wakeOn` day.
	 *
	 * @NoAdminRequired
	 */
	public function sleep(int $id): JSONResponse {
		try {
			$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
			$houseUser = $this->authService->assertHouseMember($house->getId());

			$payload = $this->body();
			$receipt = CycleReceipt::fromPayload($payload, $this->l, CycleReceipt::KEY_NONE, ['sleeping', 'wakeOn']);
			$sleeping = $payload['sleeping'] ?? null;
			$wakeOn = $payload['wakeOn'] ?? null;
			if (!is_bool($sleeping)) {
				throw new \InvalidArgumentException($this->l->t('sleeping must be a boolean'));
			}
			if ($wakeOn !== null && !is_string($wakeOn)) {
				throw new \InvalidArgumentException($this->l->t('A valid date (Y-m-d) is required'));
			}

			return $this->cycleResponse((int)$house->getId(), $this->sleepService->setSleeping(
				(int)$house->getId(), $houseUser, $id, $receipt->commandId, $receipt->expectedVersion, $sleeping, $wakeOn
			));
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\InvalidArgumentException $e) {
			// Curated, translated messages: the body's shape and the wake date rule.
			return ControllerErrorFormatter::validation($e);
		} catch (\Exception $e) {
			$this->logControllerException('sleep', $e, ['roomTaskId' => $id]);
			return ControllerErrorFormatter::serverErrorResponse($e, 'SLEEP_TASK_ERROR');
		}
	}

	/** The request body without the framework keys. */
	private function body(): array {
		$payload = $this->request->getParams();
		unset($payload['id'], $payload['_route']);
		return $payload;
	}

	/**
	 * What a cycle command answers: the room task with the status of its
	 * fresh `cycle` block.
	 *
	 * @param array{roomTask: \OCA\ChoreStrike\Db\RoomTask, cycle: ?array<string, mixed>, replay: bool} $result
	 */
	private function cycleResponse(int $houseId, array $result): JSONResponse {
		return new JSONResponse(['success' => true, 'data' => [
			'roomTask' => $this->roomTaskReadService->roomTaskPayload(
				$result['roomTask'], $this->roomTaskReadService->timeZoneForHouse($houseId), $result['cycle']
			),
			'cycle' => $result['cycle'],
			'replay' => $result['replay'],
		]]);
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
