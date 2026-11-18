<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Transaction-only presentation schemas; summary/check tables have no list state. */
function kreabankMonthlyListDefinitions()
{
	$details = array('date'=>'date', 'description'=>'text', 'reference'=>'text', 'amount'=>'decimal', 'balance'=>'decimal', 'status'=>'status', 'age'=>'integer');
	return array(
		'bank_pending'=>$details, 'ledger_pending'=>$details, 'bank'=>$details, 'ledger'=>$details
	);
}

function kreabankMonthlyListPrefix($key)
{
	if (!isset(kreabankMonthlyListDefinitions()[$key])) { throw new InvalidArgumentException('Unknown monthly list'); }
	return 'ml_' . $key . '_';
}

/** Each table owns its filter buttons, native page jump, sort and page size. */
function kreabankMonthlyListState($request, $key, $defaultLimit = 25)
{
	$prefix = kreabankMonthlyListPrefix($key);
	$columns = kreabankMonthlyListDefinitions()[$key];
	$scalar = static function ($name, $default = '') use ($request, $prefix) {
		$value = $request[$prefix . $name] ?? $default;
		return is_scalar($value) ? trim((string) $value) : (string) $default;
	};
	$has = static function ($button) use ($request, $prefix) {
		foreach (array($button, $button . '_x', $button . '.x') as $suffix) {
			if (isset($request[$prefix . $suffix])) { return true; }
		}
		return false;
	};
	$clear = $has('button_removefilter');
	$filters = array();
	foreach ($columns as $field=>$type) {
		foreach ($type === 'date' ? array($field . '_from', $field . '_to') : array($field) as $filter) {
			$value = $clear ? '' : $scalar('search_' . $filter);
			$filters[$filter] = function_exists('mb_substr') ? mb_substr($value, 0, 256, 'UTF-8') : substr($value, 0, 256);
		}
	}
	$page = array_key_exists($prefix . 'pageplusone', $request) ? (int) $scalar('pageplusone') - 1 : (int) $scalar('page', '0');
	$jump = isset($request[$prefix . 'pageplusone'], $request[$prefix . 'pageplusoneold'])
		&& (int) $scalar('pageplusone') !== (int) $scalar('pageplusoneold');
	if ($clear || ($has('button_search') && !$jump)) { $page = 0; }
	$limit = (int) $scalar('limit');
	if ($limit <= 0) { $limit = max(1, (int) $defaultLimit); }
	$field = $scalar('sortfield');
	$defaultSort = isset($columns['date']) ? 'date' : 'position';
	if (!isset($columns[$field])) { $field = $defaultSort; }
	$order = strtoupper($scalar('sortorder', 'ASC'));
	return array('prefix'=>$prefix, 'filters'=>$filters, 'page'=>max(0, $page), 'limit'=>min(5000, $limit),
		'sortfield'=>$field, 'sortorder'=>in_array($order, array('ASC','DESC'), true) ? $order : 'ASC');
}

/** Canonical, action-free query state for the four transaction tables only. */
function kreabankMonthlyListRequestState($request, $defaultLimit = 25)
{
	$params = array();
	foreach (kreabankMonthlyListDefinitions() as $key=>$columns) {
		$list = kreabankMonthlyListState($request, $key, $defaultLimit);
		foreach (array('page','limit','sortfield','sortorder') as $field) { $params[$list['prefix'] . $field] = $list[$field]; }
		foreach ($list['filters'] as $field=>$value) { if ($value !== '') { $params[$list['prefix'] . 'search_' . $field] = $value; } }
	}
	return $params;
}

/** Compare signed decimal strings at eight places without binary floats or integer overflow. */
function kreabankMonthlyListDecimalCompare($left, $right)
{
	$parse = static function ($value) {
		if (!is_scalar($value) || !preg_match('/^([+-]?)(\d{1,16})(?:[.,](\d{1,8}))?$/D', (string) $value, $parts)) { return null; }
		$whole = ltrim($parts[2], '0');
		$fraction = str_pad($parts[3] ?? '', 8, '0');
		$digits = ($whole ?: '0') . $fraction;
		return array($parts[1] === '-' && trim($digits, '0') !== '', $whole ?: '0', $fraction);
	};
	$a = $parse($left); $b = $parse($right);
	if ($a === null || $b === null) { return null; }
	if ($a[0] !== $b[0]) { return $a[0] ? -1 : 1; }
	$result = strlen($a[1]) <=> strlen($b[1]);
	if ($result === 0) { $result = strcmp($a[1], $b[1]); }
	if ($result === 0) { $result = strcmp($a[2], $b[2]); }
	return ($result <=> 0) * ($a[0] ? -1 : 1);
}

function kreabankMonthlyListNumberMatches($value, $filter, $integer = false)
{
	if ($filter === '—') { return $value === null || $value === ''; }
	$pattern = $integer ? '/^(<=|>=|<>|!=|=|<|>)?\s*([+-]?\d{1,16})$/D' : '/^(<=|>=|<>|!=|=|<|>)?\s*([+-]?\d{1,16}(?:[.,]\d{1,8})?)$/D';
	if (!preg_match($pattern, $filter, $parts)) { return false; }
	$comparison = kreabankMonthlyListDecimalCompare($value, $parts[2]);
	if ($comparison === null) { return false; }
	switch ($parts[1] ?: '=') {
		case '>': return $comparison > 0;
		case '>=': return $comparison >= 0;
		case '<': return $comparison < 0;
		case '<=': return $comparison <= 0;
		case '<>': case '!=': return $comparison !== 0;
		default: return $comparison === 0;
	}
}

/** Literal, case-insensitive text search; %, _ and quotes never become query operators. */
function kreabankMonthlyListTextMatches($value, $filter)
{
	$fold = static function ($text) { return function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text); };
	return strpos($fold($value), $fold($filter)) !== false;
}

/** Filter/sort a complete prepared array, then slice. Financial inputs remain untouched. */
function kreabankMonthlyListPrepare($rows, $key, $state, $values)
{
	global $conf;
	$list = kreabankMonthlyListState($state, $key, !empty($conf->liste_limit) ? $conf->liste_limit : 25);
	$columns = kreabankMonthlyListDefinitions()[$key];
	$prepared = array();
	foreach ($rows as $position=>$row) {
		$facts = $values($row);
		$matches = true;
		foreach ($columns as $field=>$type) {
			$value = $facts[$field] ?? null;
			if ($type === 'date') {
				foreach (array('from','to') as $bound) {
					$filter = $list['filters'][$field . '_' . $bound];
					if ($filter === '') { continue; }
					if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $filter, $parts)
						|| !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || !$value
						|| ($bound === 'from' ? strcmp($value, $filter) < 0 : strcmp($value, $filter) > 0)) { $matches = false; break; }
				}
				if (!$matches) { break; }
				continue;
			}
			$filter = $list['filters'][$field];
			if ($filter === '') { continue; }
			if (in_array($type, array('decimal','integer'), true)) { $matches = kreabankMonthlyListNumberMatches($value, $filter, $type === 'integer'); }
			elseif (in_array($type, array('status','check_status'), true)) { $matches = (string) $value === $filter; }
			elseif ($type === 'mixed' && (preg_match('/^[<>=!]|^[+-]?\d+(?:[.,]\d+)?$/D', $filter) || $filter === '—')) { $matches = kreabankMonthlyListNumberMatches($value, $filter); }
			else { $matches = kreabankMonthlyListTextMatches($value, $filter); }
			if (!$matches) { break; }
		}
		if ($matches) { $prepared[] = array('row'=>$row,'facts'=>$facts,'position'=>$position); }
	}
	if ($list['sortfield'] !== 'position') {
		$field = $list['sortfield']; $type = $columns[$field];
		usort($prepared, static function ($a, $b) use ($field, $type, $list) {
			$left = $a['facts'][$field] ?? null; $right = $b['facts'][$field] ?? null;
			if ($left === null || $left === '' || $right === null || $right === '') {
				$result = ($left === null || $left === '') <=> ($right === null || $right === '');
				if ($result) { return $result; } // Unknown values stay last in either direction.
			}
			$result = in_array($type, array('decimal','integer','mixed'), true) ? kreabankMonthlyListDecimalCompare($left, $right) : null;
			if ($result === null) { $result = strnatcasecmp((string) $left, (string) $right); }
			return $result ? ($list['sortorder'] === 'DESC' ? -$result : $result) : ($a['position'] <=> $b['position']);
		});
	}
	$list['total'] = count($prepared);
	if ($list['page'] > ($list['total'] ? intdiv($list['total'] - 1, $list['limit']) : 0)) { $list['page'] = 0; }
	$offset = $list['page'] * $list['limit'];
	$list['num'] = min($list['limit'] + 1, max(0, $list['total'] - $offset));
	$list['rows'] = array_column(array_slice($prepared, $offset, $list['limit']), 'row');
	return $list;
}

/** Native header styling with an explicit query namespace (the CSS prefix is not one). */
function kreabankMonthlyListHeader($label, $field, $list, $state, $right = false)
{
	$html = getTitleFieldOfList($label, 0, '', $field, '', '', 'scope="col"', $list['sortfield'], $list['sortorder'], $right ? 'right ' : '', 1);
	$url = dol_escape_htmltag(kreabankMonthlyReportUrl($state, array($list['prefix'] . 'sortfield'=>$field,
		$list['prefix'] . 'sortorder'=>$list['sortfield'] === $field && $list['sortorder'] === 'ASC' ? 'DESC' : 'ASC', $list['prefix'] . 'page'=>0)));
	return preg_replace_callback('/(<th\b[^>]*>)(.*)(<\/th>)/s', static function ($parts) use ($url) {
		return $parts[1] . '<a class="reposition" href="' . $url . '">' . $parts[2] . '</a>' . $parts[3];
	}, $html);
}

/** Adapt only native pager control names/links; keep the installed theme and helpers. */
function kreabankMonthlyListPager($title, $list, $state)
{
	$prefix = $list['prefix'];
	$path = dol_buildpath('/custom/kreabank/monthlyreport.php', 1);
	$params = parse_url(kreabankMonthlyReportUrl($state), PHP_URL_QUERY);
	ob_start();
	print_barre_liste($title, $list['page'], $path, '&' . $params, '', '', '', $list['num'], $list['total'], '', 0, '', '', $list['limit'], $prefix . 'limit', 0, 1);
	$html = ob_get_clean();
	$html = str_replace('jQuery(".selectlimit")', 'jQuery("#kb-monthly-list-' . substr($prefix, 3, -1) . ' .selectlimit")', $html);
	$html = str_replace(array('name="pageplusone"','name="pageplusoneold"','name="limit"','id="limit"','list="limitlist"','id="limitlist"'),
		array('name="' . $prefix . 'pageplusone"','name="' . $prefix . 'pageplusoneold"','name="' . $prefix . 'limit"','id="' . $prefix . 'limit"','list="' . $prefix . 'limitlist"','id="' . $prefix . 'limitlist"'), $html);
	return preg_replace_callback('/href="([^"]*)"/', static function ($match) use ($prefix, $state) {
		$url = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
		parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
		if (!isset($params['page'])) { return $match[0]; }
		return 'href="' . dol_escape_htmltag(kreabankMonthlyReportUrl($state, array($prefix . 'page'=>(int) $params['page']))) . '"';
	}, $html);
}

/** Render a native, read-only list; printing deliberately bypasses all presentation state. */
function kreabankMonthlyListHtml($rows, $key, $title, $labels, $state, $langs, $values, $render, $print = false)
{
	global $db, $conf;
	$columns = kreabankMonthlyListDefinitions()[$key];
	$list = kreabankMonthlyListPrepare($print ? array() : $rows, $key, $print ? array() : $state, $values);
	$html = '';
	if (!$print) {
		$form = new Form($db);
		$formId = 'kb-monthly-list-' . $key;
		// Carry other lists independently; this list's native controls own its size and filters.
		$context = parse_url(kreabankMonthlyReportUrl($state), PHP_URL_QUERY);
		parse_str((string) $context, $params);
		$params[$list['prefix'] . 'page'] = $list['page'];
		$params[$list['prefix'] . 'sortfield'] = $list['sortfield'];
		$params[$list['prefix'] . 'sortorder'] = $list['sortorder'];
		unset($params[$list['prefix'] . 'limit']);
		$html .= '<form class="kb-monthly-list-form" id="' . $formId . '" method="get" action="' . dol_escape_htmltag(dol_buildpath('/custom/kreabank/monthlyreport.php', 1)) . '">';
		foreach ($params as $name=>$value) {
			if (strpos($name, $list['prefix'] . 'search_') === 0) { continue; }
			$html .= '<input type="hidden" name="' . $name . '" value="' . dol_escape_htmltag((string) $value) . '">';
		}
		$html .= kreabankMonthlyListPager($title, $list, array_merge($state, array($list['prefix'] . 'page'=>$list['page'], $list['prefix'] . 'limit'=>$list['limit'])));
	}
	$details = isset($columns['date']);
	$html .= '<div class="div-table-responsive"><table id="kb-monthly-table-' . $key . '" class="tagtable liste centpercent' . ($details ? ' kb-monthly-detail-table' : '') . '">';
	if ($details) {
		$html .= '<colgroup>';
		foreach ($columns as $field=>$type) { $html .= '<col class="kb-monthly-col-' . $field . '">'; }
		if (!$print) { $html .= '<col class="kb-monthly-col-actions">'; }
		$html .= '</colgroup>';
	}
	$html .= '<thead>';
	if (!$print) {
		$html .= '<tr class="liste_titre_filter">';
		foreach ($columns as $field=>$type) {
			$html .= '<td class="liste_titre' . (in_array($type,array('decimal','integer','mixed'),true) ? ' right' : '') . '">';
			$input = static function ($filter, $inputType = 'search', $suffix = '') use ($list, $labels, $field) {
				return '<input class="flat ' . ($inputType === 'date' ? 'width125' : 'width100') . '" type="' . $inputType . '" maxlength="256" name="' . $list['prefix'] . 'search_' . $filter . '" value="' . dol_escape_htmltag($list['filters'][$filter]) . '" aria-label="' . dol_escape_htmltag($labels[$field] . $suffix) . '" title="' . dol_escape_htmltag($labels[$field] . $suffix) . '">';
			};
			if ($type === 'date') { $html .= '<div class="kb-monthly-date-filter">' . $input($field . '_from','date',' — ' . $langs->transnoentities('From')) . $input($field . '_to','date',' — ' . $langs->transnoentities('To')) . '</div>'; }
			elseif (in_array($type,array('status','check_status'),true)) {
				$options = $type === 'status' ? array('matched','opening','pending','review','duplicate') : array('ok','warning','error');
				$html .= '<select class="flat maxwidth150" name="' . $list['prefix'] . 'search_' . $field . '" aria-label="' . dol_escape_htmltag($labels[$field]) . '"><option value="">' . $langs->trans('All') . '</option>';
				foreach ($options as $option) { $html .= '<option value="' . $option . '"' . ($list['filters'][$field] === $option ? ' selected' : '') . '>' . $langs->trans('KreaBankMonthly' . ($type === 'status' ? 'State' : 'CheckState') . ucfirst($option)) . '</option>'; }
				$html .= '</select>';
			} else { $html .= $input($field); }
			$html .= '</td>';
		}
		$buttons = preg_replace('/name="(button_(?:search|removefilter)(?:_x)?)"/', 'name="' . $list['prefix'] . '$1"', $form->showFilterButtons());
		$html .= '<td class="liste_titre center maxwidthsearch">' . $buttons . '</td></tr>';
	}
	$html .= '<tr class="liste_titre">';
	foreach ($columns as $field=>$type) {
		$right = in_array($type,array('decimal','integer','mixed'),true);
		$html .= $print ? '<th scope="col"' . ($right ? ' class="right"' : '') . '>' . dol_escape_htmltag($labels[$field]) . '</th>' : kreabankMonthlyListHeader($labels[$field], $field, $list, $state, $right);
	}
	if (!$print) { $html .= '<th scope="col"></th>'; }
	$html .= '</tr></thead><tbody>';
	$visible = $print ? $rows : $list['rows'];
	foreach ($visible as $row) { $html .= $render($row, $print ? '' : '<td></td>'); }
	if (!$visible) { $html .= '<tr><td colspan="' . (count($columns) + ($print ? 0 : 1)) . '">' . $langs->trans('KreaBankMonthlyEmpty') . '</td></tr>'; }
	return $html . '</tbody></table></div>' . ($print ? '' : '</form>');
}
