<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Normalize list sorting against the list's own columns, never request SQL. */
function kreabankListSort($columns, $default, $direction = 'ASC', $prefix = '')
{
	$field = (string) GETPOST($prefix . 'sortfield', 'alphanohtml');
	$order = strtoupper((string) GETPOST($prefix . 'sortorder', 'alpha'));
	return array('field' => isset($columns[$field]) ? $field : $default,
		'order' => in_array($order, array('ASC', 'DESC'), true) ? $order : $direction, 'prefix' => $prefix);
}

/** Retain view/filter state, but never replay a write action, token or selection. */
function kreabankListParameters()
{
	$params = array();
	foreach (array_merge($_GET, $_POST) as $key => $value) {
		if (is_scalar($value) && (in_array($key, array('mainmenu', 'leftmenu', 'account_context', 'ref', 'limit', 'show_unidentified', 'line_id', 'open_doc_search', 'history_page'), true)
			|| preg_match('/^(hist_|audit_|search_)/', $key) || preg_match('/^(\w+_)?sort(field|order)$/', $key))) {
			$params[$key] = (string) $value;
		}
	}
	return $params;
}

/** Native pagination uses a one-based page input and the configured default size. */
function kreabankListPagination($total, $maximum = 5000)
{
	global $conf;
	$limit = (int) GETPOST('limit', 'int');
	if ($limit <= 0) { $limit = max(1, (int) $conf->liste_limit); }
	$limit = min($maximum, $limit);
	$page = GETPOSTISSET('pageplusone') ? (int) GETPOST('pageplusone', 'int') - 1 : (int) GETPOST('page', 'int');
	$page = max(0, $page);
	if ($page > ($total > 0 ? intdiv((int) $total - 1, $limit) : 0)) { $page = 0; }
	$offset = $page * $limit;
	return array('page'=>$page, 'limit'=>$limit, 'offset'=>$offset, 'num'=>min($limit + 1, max(0, $total - $offset)));
}

/** Link changes only read-only list state and starts at its first page. */
function kreabankListSortUrl($sort, $field, $params = null)
{
	$params = $params === null ? kreabankListParameters() : $params;
	unset($params['page'], $params['pageplusone']);
	$prefix = $sort['prefix'];
	if ($prefix === 'audit_') {
		$params['audit_nav'] = '1';
		$params['audit_page'] = '0';
	} else {
		unset($params['audit_nav']);
	}
	$params[$prefix . 'sortfield'] = $field;
	$params[$prefix . 'sortorder'] = $sort['field'] === $field && $sort['order'] === 'ASC' ? 'DESC' : 'ASC';
	return $_SERVER['PHP_SELF'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/** Accessible, keyboard-operable column header with explicit alignment. */
function kreabankListHeader($label, $field, $sort, $class = '', $params = null)
{
	$active = $sort['field'] === $field;
	return '<th scope="col" class="kb-list-heading ' . dol_escape_htmltag($class) . '" aria-sort="'
		. ($active ? ($sort['order'] === 'ASC' ? 'ascending' : 'descending') : 'none') . '"><a href="'
		. dol_escape_htmltag(kreabankListSortUrl($sort, $field, $params)) . '">' . dol_escape_htmltag($label)
		. ' <span aria-hidden="true">' . ($active ? ($sort['order'] === 'ASC' ? '↑' : '↓') : '↕') . '</span></a></th>';
}

/** Stable ordering of already scoped rows using raw amounts/dates, before slicing. */
function kreabankListOrderRows($rows, $sort, $value)
{
	$decorated = array();
	foreach ($rows as $index => $row) {
		$decorated[] = array('row' => $row, 'value' => $value($row, $sort['field']), 'index' => $index);
	}
	usort($decorated, static function ($a, $b) use ($sort) {
		$av = $a['value']; $bv = $b['value'];
		if ($av === null || $bv === null) {
			// Missing values stay last in both directions.
			return $av === $bv ? $a['index'] <=> $b['index'] : ($av === null ? 1 : -1);
		}
		$comparison = is_numeric($av) && is_numeric($bv) ? $av <=> $bv : strnatcasecmp((string) $av, (string) $bv);
		return $comparison ? ($sort['order'] === 'DESC' ? -$comparison : $comparison) : $a['index'] <=> $b['index'];
	});
	return array_column($decorated, 'row');
}

/** Sort links for card lists, sharing the same request contract as table headers. */
function kreabankListSortLinks($columns, $sort, $params = null)
{
	global $langs;
	$html = '<nav class="kb-list-sort" aria-label="' . dol_escape_htmltag($langs->trans('KreaBankSortList')) . '">';
	foreach ($columns as $field => $label) {
		$html .= '<a href="' . dol_escape_htmltag(kreabankListSortUrl($sort, $field, $params)) . '"'
			. ($sort['field'] === $field ? ' aria-current="true"' : '') . '>' . dol_escape_htmltag($langs->trans($label))
			. ' <span aria-hidden="true">' . ($sort['field'] === $field ? ($sort['order'] === 'ASC' ? '↑' : '↓') : '↕') . '</span></a>';
	}
	return $html . '</nav>';
}

/** Local sorting is opt-in and used only for fully rendered, unpaginated tables. */
function kreabankListAssets()
{
	$root = dol_buildpath('/custom/kreabank/', 1);
	return '<link rel="stylesheet" href="' . dol_escape_htmltag($root . 'css/lists.css?v=' . filemtime(__DIR__ . '/../css/lists.css')) . '">'
		. '<script defer src="' . dol_escape_htmltag($root . 'js/lists.js?v=' . filemtime(__DIR__ . '/../js/lists.js')) . '"></script>';
}
