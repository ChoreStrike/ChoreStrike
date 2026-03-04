<?php

namespace OCP;

/**
 * Mock interface for Nextcloud user manager.
 */
interface IUserManager
{
    /**
     * Find user by UID.
     *
     * @param string $uid User identifier.
     */
    public function get($uid);

    /**
     * Check whether a user exists.
     *
     * @param string $uid User identifier.
     */
    public function userExists($uid);
}
