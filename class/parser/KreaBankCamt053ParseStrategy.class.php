<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/KreaBankParseStrategyInterface.class.php';

/**
 * Parse strategy for CAMT.053 XML format.
 */
class KreaBankCamt053ParseStrategy implements KreaBankParseStrategyInterface
{
	/**
	 * @inheritDoc
	 */
	public function supportsFormat($format)
	{
		return ((string) $format) === 'camt053';
	}

	/**
	 * @inheritDoc
	 */
	public function parse($parser, $filePath, $fileName, $defaultCurrency)
	{
		$xmlContent = file_get_contents($filePath);
		if ($xmlContent === false) {
			throw new Exception('Unable to read CAMT.053 file');
		}

		$xml = @simplexml_load_string($xmlContent);
		if (!$xml) {
			throw new Exception('Invalid CAMT.053 XML file');
		}

		$namespaces = $xml->getDocNamespaces(true);
		if (!empty($namespaces)) {
			$defaultNs = reset($namespaces);
			if ($defaultNs) {
				$xml->registerXPathNamespace('ns', $defaultNs);
			}
		}

		$entries = $xml->xpath('//ns:Ntry');
		if (!is_array($entries) || empty($entries)) {
			$entries = $xml->xpath('//Ntry');
		}

		$lines = array();
		$rank = 0;
		foreach ((array) $entries as $entry) {
			$amount = (float) ((string) $entry->Amt);
			$currency = (string) $entry->Amt['Ccy'];
			$direction = strtoupper((string) $entry->CdtDbtInd) === 'DBIT' ? -1 : 1;
			$amount = $direction < 0 ? -abs($amount) : abs($amount);

			$bookDate = (string) $entry->BookgDt->Dt;
			if (empty($bookDate)) {
				$bookDate = (string) $entry->BookgDt->DtTm;
			}
			$valueDate = (string) $entry->ValDt->Dt;
			if (empty($valueDate)) {
				$valueDate = (string) $entry->ValDt->DtTm;
			}

			$details = isset($entry->NtryDtls->TxDtls) ? $entry->NtryDtls->TxDtls : null;
			$counterpartyName = '';
			$counterpartyIban = '';
			$description = (string) $entry->AddtlNtryInf;
			$paymentReference = (string) $entry->AcctSvcrRef;
			$bankReference = (string) $entry->NtryRef;

			if ($details) {
				if (isset($details->RltdPties->Cdtr->Nm)) {
					$counterpartyName = (string) $details->RltdPties->Cdtr->Nm;
				}
				if (isset($details->RltdPties->Dbtr->Nm)) {
					$counterpartyName = (string) $details->RltdPties->Dbtr->Nm;
				}
				if (isset($details->RltdPties->CdtrAcct->Id->IBAN)) {
					$counterpartyIban = (string) $details->RltdPties->CdtrAcct->Id->IBAN;
				}
				if (isset($details->RltdPties->DbtrAcct->Id->IBAN)) {
					$counterpartyIban = (string) $details->RltdPties->DbtrAcct->Id->IBAN;
				}
				if (isset($details->RmtInf->Ustrd)) {
					$description = trim($description . ' ' . (string) $details->RmtInf->Ustrd);
				}
				if (isset($details->Refs->EndToEndId) && (string) $details->Refs->EndToEndId !== '') {
					$paymentReference = (string) $details->Refs->EndToEndId;
				}
			}

			$lines[] = array(
				'line_rank' => $rank,
				'operation_date' => $parser->callHelper('parseDate', $bookDate),
				'value_date' => $parser->callHelper('parseDate', $valueDate),
				'amount' => $amount,
				'currency' => strtoupper($currency ?: $defaultCurrency),
				'running_balance' => null,
				'counterparty_name' => $counterpartyName,
				'counterparty_iban' => $parser->callHelper('normalizeIban', $counterpartyIban),
				'description' => $description,
				'payment_reference' => $paymentReference,
				'bank_reference' => $bankReference,
				'direction' => $direction,
				'line_uid' => sha1(implode('|', array($rank, $bookDate, $amount, $paymentReference, $bankReference))),
			);

			$rank++;
		}

		return $lines;
	}
}
