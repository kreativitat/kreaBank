<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later */

/** Bounded presentation identities only; never an authority to read or reconcile. */
function kreabankReconcileQueueIds($value)
{
	if (!is_string($value) || strlen($value) > 2211) { return array(); }
	$ids = array();
	foreach (array_slice(explode(',', $value), 0, 201) as $id) {
		if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) && (float) $id <= 2147483647) { $ids[(int) $id] = (int) $id; }
	}
	return array_values($ids);
}

/** Order only the already company/account-scoped pending rows, then continue. */
function kreabankReconcileQueueState($lines, $order, $next, $advance)
{
	$remaining = array();
	foreach ($lines as $line) { $remaining[(int) $line['rowid']] = $line; }
	$ordered = array();
	foreach ($order as $id) {
		if (isset($remaining[$id])) { $ordered[$id] = $remaining[$id]; unset($remaining[$id]); }
	}
	$ordered += $remaining;
	$target = 0;
	$visibleNext = false;
	if ($advance) {
		foreach ($next as $id) {
			if (isset($ordered[$id])) { $target = $id; $visibleNext = true; break; }
		}
		if (!$target && $ordered) { $target = (int) key($ordered); }
		if ($target) {
			$ids = array_keys($ordered);
			$offset = array_search($target, $ids, true);
			$ordered = array_slice($ordered, $offset, null, true) + array_slice($ordered, 0, $offset, true);
		}
	}
	return array('lines'=>array_values($ordered), 'line_id'=>$target, 'visible_next'=>$visibleNext);
}

/** Carry only navigation state across the successful POST/redirect/GET. */
function kreabankReconcileQueueParameters($order, $next, $search)
{
	return array('queue_order'=>implode(',', $order), 'queue_next'=>implode(',', $next),
		'queue_search'=>$search, 'queue_continue'=>1);
}

/** No-JavaScript defaults; the browser replaces these with the displayed order. */
function kreabankReconcileQueueHiddenFields($lines, $selected, $sort, $search)
{
	$ids = array_map('intval', array_column($lines, 'rowid'));
	$index = array_search((int) $selected, $ids, true);
	$next = $index === false ? $ids : array_merge(array_slice($ids, $index + 1), array_slice($ids, 0, $index));
	$params = array('queue_order'=>implode(',', $ids), 'queue_next'=>implode(',', $next),
		'queue_search'=>$search, 'sortfield'=>$sort['field'], 'sortorder'=>$sort['order']);
	$html = '';
	foreach ($params as $name=>$value) {
		$html .= '<input type="hidden" name="' . $name . '" value="' . dol_escape_htmltag((string) $value) . '">';
	}
	return $html;
}
