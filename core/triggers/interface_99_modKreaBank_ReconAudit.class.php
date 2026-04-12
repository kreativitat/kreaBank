<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

/**
 * Trigger class for KreaBank.
 */
class InterfaceReconAudit extends DolibarrTriggers
{
	/** @var string */
	public $family = 'kreabank';

	/** @var string */
	public $description = 'KreaBank audit synchronization trigger';

	/** @var string */
	public $version = '1.1.0';

	/**
	 * Run trigger.
	 *
	 * @param string $action Event code
	 * @param CommonObject $object Triggering object
	 * @param User $user User
	 * @param Translate $langs Translations
	 * @param Conf $conf Config
	 * @return int
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('kreabank')) {
			return 0;
		}

		$watchedActions = array(
			'PAYMENT_CUSTOMER_CREATE',
			'PAYMENT_SUPPLIER_CREATE',
			'PAYMENT_CUSTOMER_DELETE',
			'PAYMENT_SUPPLIER_DELETE',
			'BILL_PAYED',
			'SUPPLIER_BILL_PAYED',
		);

		if (!in_array($action, $watchedActions, true)) {
			return 0;
		}

		$payload = array(
			'action' => $action,
			'element' => isset($object->element) ? $object->element : null,
			'object_id' => isset($object->id) ? (int) $object->id : null,
			'ref' => isset($object->ref) ? (string) $object->ref : '',
		);

		$sql = 'INSERT INTO ' . $this->db->prefix() . 'kreabank_recon_audit (';
		$sql .= 'entity, audit_type, payload_json, fk_user, datec';
		$sql .= ') VALUES (';
		$sql .= ((int) $conf->entity);
		$sql .= ", 'trigger_event'";
		$sql .= ", '" . $this->db->escape(json_encode($payload)) . "'";
		$sql .= ', ' . ((int) $user->id > 0 ? (int) $user->id : 'NULL');
		$sql .= ", '" . $this->db->idate(dol_now()) . "'";
		$sql .= ')';

		$this->db->query($sql);

		return 0;
	}
}

/**
 * Backward compatibility for custom code that still instantiates the old class name.
 */
class InterfaceKreaBankReconAudit extends InterfaceReconAudit {}
