<?php

/**
 * Mock IUserSession interface for testing
 */

namespace OCP;

interface IUserSession
{
    public function getUser(): ?IUser;
}
