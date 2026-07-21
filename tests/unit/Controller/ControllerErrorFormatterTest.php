<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

use OCA\ChoreStrike\Controller\ControllerErrorFormatter;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;

class ControllerErrorFormatterTest extends \PHPUnit\Framework\TestCase
{
    public function testInvalidArgumentExceptionMessageIsSurfaced(): void
    {
        $e = new \InvalidArgumentException('User is not a member of this house');

        $this->assertSame(
            'User is not a member of this house',
            ControllerErrorFormatter::safeMessage($e)
        );
    }

    public function testNotFoundExceptionMessageIsSurfaced(): void
    {
        $e = new NotFoundException('Room task not found');

        $this->assertSame('Room task not found', ControllerErrorFormatter::safeMessage($e));
    }

    public function testForbiddenExceptionMessageIsSurfaced(): void
    {
        $e = new ForbiddenException('Private tasks may only be completed by assigned users');

        $this->assertSame(
            'Private tasks may only be completed by assigned users',
            ControllerErrorFormatter::safeMessage($e)
        );
    }

    public function testGenericExceptionFallsBackToDefaultMessage(): void
    {
        // Internal/driver details (e.g. SQL errors) must never reach the client.
        $e = new \RuntimeException('SQLSTATE[HY000] connection refused');

        $this->assertSame('An unexpected error occurred', ControllerErrorFormatter::safeMessage($e));
    }

    public function testGenericExceptionFallsBackToCustomMessage(): void
    {
        $e = new \Exception('internal detail');

        $this->assertSame('Something went wrong', ControllerErrorFormatter::safeMessage($e, 'Something went wrong'));
    }
}
