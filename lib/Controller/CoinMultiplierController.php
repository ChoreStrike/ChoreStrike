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
use OCA\ChoreStrike\Service\CoinMultiplierService;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The owner's two coin multipliers (CS-PLAN-091 WP-B5, ADR-021 decision
 * 11): the coin factor of a member and the bonus periods of the house.
 * Every route is owner-only, checked here and again in the service, and
 * receipt-protected by its `commandId`.
 */
class CoinMultiplierController extends Controller {
	use ControllerLoggingTrait;

	public function __construct(
		IRequest $request,
		private HouseService $houseService,
		private AuthorizationService $authService,
		private CoinMultiplierService $multipliers,
		private IL10N $l,
		AppLogger $appLogger,
	) {
		parent::__construct('chorestrike', $request);
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Set a member's coin factor: `{ "commandId": "<uuid>", "factor": 1 | 2 | 3 }`.
	 * Answers with the member.
	 *
	 * @NoAdminRequired
	 * @param string $userId The member's Nextcloud user id
	 */
	public function setFactor(string $userId): JSONResponse {
		return $this->ownerCommand('setFactor', ['factor'], function (int $houseId, array $payload, string $commandId) use ($userId): array {
			$factor = $payload['factor'] ?? null;
			if (!is_int($factor)) {
				throw new \InvalidArgumentException($this->l->t('The coin factor must be 1, 2 or 3'));
			}
			return $this->multipliers->setFactor($houseId, $userId, $factor, $commandId)->jsonSerialize();
		});
	}

	/**
	 * Start a bonus period: `{ "commandId": "<uuid>", "startsOn": "Y-m-d", "days": 1–3 }`.
	 * Answers with `{ bonusPeriod }`, the period the house shows now.
	 *
	 * @NoAdminRequired
	 */
	public function startPeriod(): JSONResponse {
		return $this->ownerCommand('startPeriod', ['startsOn', 'days'], function (int $houseId, array $payload, string $commandId): array {
			$startsOn = $payload['startsOn'] ?? null;
			$days = $payload['days'] ?? null;
			if (!is_string($startsOn)) {
				throw new \InvalidArgumentException($this->l->t('A valid date (Y-m-d) is required'));
			}
			if (!is_int($days)) {
				throw new \InvalidArgumentException($this->l->t('Bonus days last one to three days'));
			}
			return ['bonusPeriod' => $this->multipliers->startPeriod($houseId, $startsOn, $days, $commandId)?->toPayload()];
		});
	}

	/**
	 * Cancel a bonus period that has not ended: `{ "commandId": "<uuid>" }`.
	 * Answers with `{ bonusPeriod }`, the period the house shows now.
	 *
	 * @NoAdminRequired
	 * @param int $id The period
	 */
	public function cancelPeriod(int $id): JSONResponse {
		return $this->ownerCommand('cancelPeriod', [], function (int $houseId, array $payload, string $commandId) use ($id): array {
			return ['bonusPeriod' => $this->multipliers->cancelPeriod($houseId, $id, $commandId)?->toPayload()];
		});
	}

	/**
	 * Shared envelope: membership and owner role first (403), the closed
	 * body with its command id (400), then the command.
	 *
	 * @param list<string> $fields The fields of the body besides `commandId`
	 * @param callable(int, array<string, mixed>, string): array $command Receives the house id, the body and the command id
	 */
	private function ownerCommand(string $action, array $fields, callable $command): JSONResponse {
		try {
			$houseId = (int)$this->houseService->getOrCreateHouse($this->houseService->getInstanceId())->getId();
			$this->authService->assertHouseMember($houseId);
			$this->authService->requireHouseOwner($houseId);

			$payload = $this->request->getParams();
			unset($payload['id'], $payload['userId'], $payload['_route']);
			CommandService::assertClosedBody($payload, ['commandId', ...$fields], $this->l);
			$commandId = strtolower(CommandService::requireCommandUuid($payload, $this->l));

			return new JSONResponse(['success' => true, 'data' => $command($houseId, $payload, $commandId)]);
		} catch (\InvalidArgumentException $e) {
			return ControllerErrorFormatter::validation($e);
		} catch (ForbiddenException $e) {
			return ControllerErrorFormatter::forbiddenResponse($e);
		} catch (NotFoundException $e) {
			return ControllerErrorFormatter::notFoundResponse($e);
		} catch (ConflictException $e) {
			return ControllerErrorFormatter::conflictResponse($e);
		} catch (\Exception $e) {
			$this->logControllerException($action, $e);
			return ControllerErrorFormatter::serverErrorResponse($e, 'COIN_MULTIPLIER_ERROR');
		}
	}
}
