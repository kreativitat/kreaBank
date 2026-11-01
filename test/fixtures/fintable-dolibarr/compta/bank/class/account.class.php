<?php
/* Test-only Account boundary. No native financial-write methods are provided. */
class Account
{
	const STATUS_CLOSED = 1;
	public $status;
	public $currency_code;
	private $db;
	public function __construct($db) { $this->db = $db; }
	public function fetch($id)
	{
		$row = $this->db->fetch_object($this->db->query('SELECT entity,clos,currency_code FROM kbtest_bank_account WHERE rowid=' . (int) $id));
		if (!$row) { return 0; }
		$this->status = (int) $row->clos;
		$this->currency_code = $row->currency_code;
		return 1;
	}
}
