<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__ . '/KreaBankDecimal.class.php';
require_once __DIR__ . '/KreaBankFintableMapper.class.php';

/** Source identity only; the service still verifies both owned accounts and every financial fact. */
final class KreaBankOwnTransferEvidence
{
	/**
	 * A transfer rail and its direction must agree with the exact persisted sign.
	 * The counterparty must then identify the supplied owned IBAN or the complete company.
	 */
	public static function ownedIdentity(array $line, $companyName, $counterpartIban = '')
	{
		try { $amount = KreaBankDecimal::normalize($line['amount'] ?? ''); }
		catch (InvalidArgumentException $e) { return ''; }
		if ($amount === '0') { return ''; }
		$sign = $amount[0] === '-' ? -1 : 1;
		if (isset($line['direction'])) {
			if ((!is_int($line['direction']) && !is_string($line['direction']))
				|| !in_array((string) $line['direction'], array('-1', '0', '1'), true)
				|| ((int) $line['direction'] !== 0 && (int) $line['direction'] !== $sign)) { return ''; }
		}
		$text = self::fold($line['description'] ?? '');
		if ($text === '' || preg_match('/\b(?:ANUL(?:ACAO)?|ESTORNO|DEVOLUCAO|CANCELAMENTO|REFUND|CHARGEBACK|LOTE|SALARIOS?|VENCIMENTOS?|TAXAS?|IMPOSTOS?|COMISSAO|COMISSOES|JUROS|TSU|COBR(?:ANCA)?|DUC|PAGAMENTO|FEE|PAYROLL)\b/', $text)) {
			return '';
		}
		if (!preg_match('/^(?:TRANSFERENCIA|TRANSF|TRF)(?=[ .])(.*?)[ .](DE|PARA|P\/)\s+(.+)$/D', $text, $match)) { return ''; }
		if (($sign > 0 && $match[2] !== 'DE') || ($sign < 0 && $match[2] === 'DE')) { return ''; }
		// Before the direction marker, accept only known bank rail qualifiers and trace numbers.
		$qualifiers = preg_split('/[\s.+-]+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: array();
		foreach ($qualifiers as $word) {
			if (!in_array($word, array('CRED', 'CREDITO', 'IMED', 'IMEDIATA', 'SEPA', 'INTRAB', 'INTRABANC', 'INTRABANCARIA', 'INTERBANCARIA', 'ONLINE', 'INTERNET'), true)
				&& !preg_match('/^[0-9]{1,24}$/D', $word)) { return ''; }
		}
		// An explicit different internal display code is conflicting identity, not
		// punctuation to discard before falling back to the same company words.
		$prefix = '/^([A-Z]{1,8}[0-9]{1,6})\s+[-:]\s*/D';
		if (preg_match($prefix, $match[3], $sourceCode)
			&& (!preg_match($prefix, self::fold($companyName), $companyCode) || $sourceCode[1] !== $companyCode[1])) { return ''; }
		$providedIban = $line['counterparty_iban'] ?? '';
		if (!is_string($providedIban) || !is_string($counterpartIban)) { return ''; }
		if (trim($providedIban) !== '') {
			$provided = KreaBankFintableMapper::iban($providedIban);
			$owned = KreaBankFintableMapper::iban($counterpartIban);
			return $provided !== '' && $owned !== '' && $provided === $owned ? 'iban' : '';
		}
		// Strip only the bank's explicit trailing receipt/emission trace. Bare numbers
		// can be part of another legal identity and must not be guessed away.
		$counterparty = preg_replace('/\s*-\s*[RE][0-9]{4,20}\s*$/D', '', $match[3]);
		$company = self::companyKey($companyName);
		return $company !== '' && self::companyKey($counterparty) === $company ? 'company' : '';
	}

	/** Exact legal-name key, allowing native display prefixes and narrow Portuguese legal suffixes. */
	public static function companyKey($name)
	{
		$name = self::fold($name);
		if ($name === '') { return ''; }
		$name = preg_replace('/^[A-Z]{1,8}[0-9]{1,6}\s+[-:]\s*/D', '', $name);
		$name = str_replace('&', ' E ', $name);
		$name = trim(preg_replace('/[^A-Z0-9]+/', ' ', $name));
		$name = preg_replace('/\s+(?:(?:UNIPESSOAL\s+)?(?:LDA|LIMITADA)|S\s+A|SA)$/D', '', $name);
		$name = preg_replace('/(?<=\S)\s+E\s+(?=\S)/', ' ', $name);
		$name = trim(preg_replace('/\s+/', ' ', $name));
		return strlen(str_replace(' ', '', $name)) >= 8 && preg_match('/[A-Z]/', $name) ? $name : '';
	}

	private static function fold($value)
	{
		if (!is_string($value) || $value === '' || strlen($value) > 2000
			|| !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) { return ''; }
		$value = strtr($value, array('–'=>'-', '—'=>'-', '−'=>'-', '’'=>"'", '‘'=>"'"));
		if (function_exists('iconv')) {
			$ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
			if ($ascii === false || strpos($ascii, '?') !== false) { return ''; }
			$value = $ascii;
		} else {
			$value = strtr($value, array('À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
				'Ç'=>'C','ç'=>'c','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
				'Ñ'=>'N','ñ'=>'n','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ß'=>'ss'));
			if (preg_match('/[^\x00-\x7F]/', $value)) { return ''; }
		}
		return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
	}
}
