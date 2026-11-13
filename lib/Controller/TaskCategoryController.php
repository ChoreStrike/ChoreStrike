<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskCategoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The household's task categories (CS-PLAN-090 WP-I, D-090-65). Members
 * read the list with the house payload; only an owner saves it here.
 */
class TaskCategoryController extends Controller {
	use ControllerLoggingTrait;

	public function __construct(
		IRequest $request,
		private HouseService $houseService,
		private AuthorizationService $authService,
		private TaskCategoryService $categories,
		private IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Save the whole ordered list against the revision the client read
	 * (409 when it changed meanwhile).
	 *
	 * @NoAdminRequired
	 */
	public function update(): JSONResponse {
		try {
			$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
			$this->authService->assertHouseMember($house->getId());
			$this->authService->requireHouseOwner($house->getId());

			$revision = $this->request->getParam('revision');
			$items = $this->request->getParam('items');
			if (!is_int($revision) || !is_array($items) || !array_is_list($items)) {
				return new JSONResponse([
					'success' => false,
					'error' => ['code' => 'VALIDATION_ERROR', 'message' => $this->l->t('revision must be an integer and items a list')],
				], 400);
			}

			return new JSONResponse([
				'success' => true,
				'data' => $this->categories->save($house->getId(), $revision, $items),
			]);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (ValidationException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (\Exception $e) {
			$this->logControllerException('update', $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'TASK_CATEGORIES_ERROR');
		}
	}
}
