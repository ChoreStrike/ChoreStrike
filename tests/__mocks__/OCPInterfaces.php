<?php
/**
 * Mock file for Nextcloud core interfaces
 * These are used when running tests outside of the full Nextcloud environment
 */

namespace OCP;

/**
 * Mock interface for Nextcloud User Manager
 */
interface IUserManager {
	public function get($uid);
	public function userExists($uid);
}

/**
 * Mock interface for Nextcloud Request
 */
interface IRequest {
	public function getParam($key, $default = null);
	public function getMethod();
}

/**
 * Mock interface for Nextcloud User
 */
interface IUser {
	public function getUID();
	public function getDisplayName();
}
