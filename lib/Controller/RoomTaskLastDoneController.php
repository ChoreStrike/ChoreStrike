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
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskLastDoneService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * "Last done" of a room task (FEAT-UX-045, CS-PLAN-088 D-088-20).
 *
 * Thin endpoint: it validates the closed input (`commandId` UUID, `date`
 * `Y-m-d`); authorization, the house lock and the effects live in
 * {@see RoomTaskLastDoneService}.
 */
class RoomTaskLastDoneController extends Controller {
	use ControllerLoggingTrait;

	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	public function __construct(
		IRequest $request,
		private RoomTaskLastDoneService $lastDoneService,
		private RoomTaskReadService $readService,
		private HouseService $houseService,
		private IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Record the day the room task was last done.
	 *
	 * @NoAdminRequired
	 */
	public function update(int $id): JSONResponse {
		$commandId = $this->request->getParam('commandId');
		$date = $this->request->getParam('date');
		if (!is_string($commandId) || !preg_match(self::UUID_PATTERN, $commandId) || !is_string($date)) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'VALIDATION_ERROR',
					'message' => $this->l->t('A valid commandId (UUID) and date are required'),
				],
			], 400);
		}
		try {
			$houseId = (int)$this->houseService->getOrCreateHouse($this->houseService->getInstanceId())->getId();
			$roomTask = $this->lastDoneService->recordLastDone($id, $houseId, $date, strtolower($commandId));
			return new JSONResponse([
				'success' => true,
				'data' => $this->readService->committedRoomTaskPayload($houseId, $roomTask, $this->readService->timeZoneForHouse($houseId), 'recordLastDone'),
			]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (ValidationException $e) {
			// Curated, localized date message; any other invalid argument is internal (500).
			return ControllerErrorFormatter::validation($e);
		} catch (\Exception $e) {
			$this->logControllerException('update', $e, ['roomTaskId' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'LAST_DONE_ERROR',
					'message' => ControllerErrorFormatter::safeMessage($e),
				],
			], 500);
		}
	}
}
