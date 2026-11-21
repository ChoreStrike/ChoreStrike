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
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskEditService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\TaskEdit;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The one form's save on an existing task (CS-PLAN-091 WP-B6): one
 * request, one atomic write of the definition, its sub-tasks, the room,
 * the assignees, the first date and the sleep switch. Adults and the
 * owner edit, decided in the service.
 */
class RoomTaskEditController extends Controller {
	use ControllerLoggingTrait;

	public function __construct(
		IRequest $request,
		private HouseService $houseService,
		private AuthorizationService $authService,
		private RoomTaskEditService $editService,
		private RoomTaskReadService $roomTaskReadService,
		private CalendarService $calendar,
		private IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Save the form. Closed body: `commandId`, `expectedVersion` from the
	 * row's `cycle` block, and the form's fields (API reference, Room Tasks).
	 *
	 * @NoAdminRequired
	 */
	public function update(int $id): JSONResponse {
		try {
			$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
			$houseUser = $this->authService->assertHouseMember($house->getId());
			$payload = $this->request->getParams();
			unset($payload['id'], $payload['_route']);
			$edit = TaskEdit::fromPayload($payload, $this->calendar, $this->l);

			$result = $this->editService->edit((int)$house->getId(), $houseUser, $id, $edit);
			$roomTask = $this->roomTaskReadService->roomTaskPayload($result['roomTask'], $result['timeZone'], $result['cycle']);
			$roomTask['assignees'] = $result['assignees'];
			$roomTask['cycle'] = $result['cycle'];
			return new JSONResponse(['success' => true, 'data' => [
				'task' => $result['task']->jsonSerialize(),
				'roomTask' => $roomTask,
				'assignees' => $result['assignees'],
				'checklistItems' => array_map(static fn ($item): array => $item->jsonSerialize(), $result['checklistItems']),
				'cycle' => $result['cycle'],
				'replay' => $result['replay'],
			]]);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (ValidationException $e) {
			return new JSONResponse(['success' => false, 'error' => [
				'code' => 'VALIDATION_ERROR', 'message' => $e->getMessage(), 'details' => $e->getErrors(),
			]], 400);
		} catch (\InvalidArgumentException $e) {
			// Curated, translated messages: the body's shape, the dates, the members.
			return ControllerErrorFormatter::validation($e);
		} catch (\Exception $e) {
			$this->logControllerException('update', $e, ['roomTaskId' => $id]);
			return ControllerErrorFormatter::serverErrorResponse($e, 'ROOM_TASK_UPDATE_ERROR');
		}
	}
}
