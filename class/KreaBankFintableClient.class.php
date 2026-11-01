<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Fixed-origin client. Tokens, response bodies and bank payloads never enter diagnostics. */
class KreaBankFintableClient
{
	const BASE_URL = 'https://fintable.io/api/v2/';
	private $token;
	private $workspace;
	private $transport;

	public function __construct($token, $workspace, $transport = null)
	{
		if (!is_string($token) || strlen($token) < 16 || preg_match('/[\r\n]/', $token)) {
			throw new RuntimeException('Fintable credential unavailable');
		}
		self::assertId($workspace, 'ws_');
		$this->token = $token;
		$this->workspace = $workspace;
		$this->transport = $transport;
	}

	public static function assertId($id, $prefix)
	{
		if (!is_string($id) || strlen($id) > 128 || strpos($id, $prefix) !== 0 || !preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
			throw new RuntimeException('Invalid Fintable resource identifier');
		}
	}

	public function get($path, $query = array())
	{
		return $this->request('GET', $path, $query);
	}

	/** The included cached-data sync only. Never calls a realtime or payment endpoint. */
	public function requestSync()
	{
		return $this->request('POST', 'sync');
	}

	public function transactionPages($accountId, $updatedSince = '')
	{
		self::assertId($accountId, 'acc_');
		$query = array('limit' => 100);
		if ($updatedSince !== '') {
			$query['updated_since'] = $updatedSince;
		}
		$seen = array();
		for ($page = 0; $page < 1000; $page++) {
			$response = $this->get('accounts/' . $accountId . '/transactions', $query);
			if (!isset($response['data']) || !is_array($response['data'])) {
				throw new RuntimeException('Invalid Fintable transaction page');
			}
			yield $response['data'];
			$cursor = isset($response['next_cursor']) ? $response['next_cursor'] : null;
			if ($cursor === null || $cursor === '') {
				return;
			}
			if (!is_string($cursor) || strlen($cursor) > 4096 || isset($seen[$cursor])) {
				throw new RuntimeException('Invalid or repeated Fintable pagination cursor');
			}
			$seen[$cursor] = true;
			$query['cursor'] = $cursor;
		}
		throw new RuntimeException('Fintable pagination limit reached; watermark retained');
	}

	private function request($method, $path, $query = array())
	{
		if (!preg_match('~^(me|accounts|connections|sync|accounts/acc_[A-Za-z0-9_-]+/transactions)$~D', $path)
			|| ($method !== 'GET' && !($method === 'POST' && $path === 'sync'))) {
			throw new RuntimeException('Fintable operation is not allowed');
		}
		$query['workspace_id'] = $this->workspace;
		$url = self::BASE_URL . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		if ($this->transport !== null) {
			$response = call_user_func($this->transport, $method, $url);
		} else {
			if (!function_exists('curl_init')) {
				throw new RuntimeException('Fintable requires the PHP cURL extension');
			}
			$curl = curl_init($url);
			$body = '';
			curl_setopt_array($curl, array(
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $this->token, 'Accept: application/json'),
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT => 45,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body) {
					if (strlen($body) + strlen($chunk) > 8 * 1024 * 1024) {
						return 0;
					}
					$body .= $chunk;
					return strlen($chunk);
				},
			));
			$ok = curl_exec($curl);
			$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
			curl_close($curl);
			if ($ok === false) {
				throw new RuntimeException('Fintable transport failed; retry on the next collection attempt');
			}
			$response = array('status' => $status, 'body' => $body);
		}
		$status = (int) $response['status'];
		if ($status < 200 || $status >= 300) {
			$hint = in_array($status, array(401, 403), true) ? 'check token scope, access and subscription' : 'retry later';
			throw new RuntimeException('Fintable HTTP ' . $status . ': ' . $hint);
		}
		$data = json_decode($response['body'], true);
		if (!is_array($data) || !array_key_exists('data', $data)) {
			throw new RuntimeException('Invalid Fintable JSON response');
		}
		if (isset($data['workspace_id']) && $data['workspace_id'] !== $this->workspace) {
			throw new RuntimeException('Fintable returned a different Workspace');
		}
		return $data;
	}
}
