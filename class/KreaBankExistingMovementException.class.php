<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** A new bank movement was refused because possible existing movements need review. */
class KreaBankExistingMovementException extends DomainException
{
	/** @var int[] */
	public $movementIds = array();
	/** @var bool */
	public $complete = true;

	/**
	 * @param int[] $movementIds
	 * @param bool  $complete False when the bounded search was truncated; never overridable.
	 */
	public function __construct(array $movementIds, $complete = true)
	{
		$this->movementIds = array_values(array_map('intval', $movementIds));
		$this->complete = (bool) $complete;
		parent::__construct('An existing bank movement requires review before creating another');
	}
}
