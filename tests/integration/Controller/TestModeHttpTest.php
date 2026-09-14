<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/** Real HTTP middleware, with test-owned server environment (not PHPUnit's DI session). */
class TestModeHttpTest extends IntegrationTestCase {
	public function testTestRoutes_AnonymousAndDisabledServer_DenyBeforeMutation(): void {
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		self::assertIsResource($socket, 'A test-owned HTTP port is required');
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$root = \OC::$SERVERROOT;
		$process = proc_open([PHP_BINARY, '-S', $address, '-t', $root],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root,
			array_merge(getenv(), ['NEXTCLOUD_TEST_MODE' => 'false']));
		self::assertIsResource($process, 'The disabled-mode HTTP process must start');
		try {
			$this->waitForServer($address);
			$houseId = $this->createBareHouse('HTTP denial survivor');
			foreach (['profiles' => 'GET', 'seed/family' => 'POST', 'reset' => 'POST'] as $route => $method) {
				$anonymous = $this->request($address, $route, $method, false);
				self::assertSame(401, $anonymous['status'], $route . ' requires real HTTP authentication');
				$disabled = $this->request($address, $route, $method, true);
				self::assertSame(403, $disabled['status'], $route . ' is disabled in the web-server environment');
				self::assertSame('NOT_TEST_MODE', $disabled['body']['error']['code']);
				self::assertSame($houseId, \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId)->getId());
			}
		} finally {
			proc_terminate($process);
			foreach ($pipes as $pipe) {
				fclose($pipe);
			}
			proc_close($process);
		}
	}

	private function waitForServer(string $address): void {
		$curl = curl_init('http://' . $address . '/status.php');
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 100, CURLOPT_TIMEOUT_MS => 250]);
		for ($attempt = 0; $attempt < 100; $attempt++) {
			if (curl_exec($curl) !== false) {
				curl_close($curl);
				return;
			}
			self::assertContains(curl_errno($curl), [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT]);
			usleep(100000);
		}
		curl_close($curl);
		self::fail('The test-owned HTTP server did not become ready');
	}

	private function request(string $address, string $route, string $method, bool $authenticated): array {
		$curl = curl_init('http://' . $address . '/index.php/apps/chorestrike/api/v1/test/' . $route);
		curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ['Accept: application/json', 'Host: localhost'], CURLOPT_TIMEOUT => 30]);
		if ($authenticated) {
			// Disposable canonical Docker credentials; never print request headers.
			curl_setopt($curl, CURLOPT_USERPWD, 'admin:admin');
		}
		$body = curl_exec($curl);
		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		self::assertNotFalse($body, 'The test HTTP request must complete');
		return ['status' => $status, 'body' => json_decode($body, true)];
	}
}
