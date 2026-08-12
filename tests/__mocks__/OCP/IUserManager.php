<?php

namespace OCP;

/**
 * Mock interface for Nextcloud user manager.
 */
interface IUserManager {
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

	/**
	 * Find users by email address.
	 *
	 * @param string $email Email address.
	 * @return array Array of IUser objects.
	 */
	public function getByEmail($email);
}
