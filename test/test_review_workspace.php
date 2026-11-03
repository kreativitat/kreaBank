#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Exercise the shipped controller/view using only synthetic dependencies.
 */
$checks = 0;
function checkReviewWorkspace($condition, $message) {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
foreach (array('single','bulk') as $mode) {
	foreach (array('ready','invoice','unmatched','empty','loading','readonly','unsafe') as $case) {
		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test_matched_review.php') . ' --html ' . escapeshellarg($mode) . ' ' . escapeshellarg($case);
		exec($command, $output, $status);$html=implode("\n",$output);$output=array();
		checkReviewWorkspace($status === 0 && strpos($html, '</html>') !== false, $mode . '/' . $case . ': complete render');
		checkReviewWorkspace(strpos($html, 'Native banking menu') !== false && strpos($html, 'kb-primary') !== false, 'Native and shared navigation survive');
		checkReviewWorkspace(strpos($html, 'KreaBankReview') === false, 'Workspace translations resolve');
		if ($case === 'unsafe') {
			checkReviewWorkspace(strpos($html, '<img src=x') === false && strpos($html,'&lt;img src=x') !== false && strpos($html,'<script>untrusted') === false, 'Source and target evidence cannot become markup');
			checkReviewWorkspace(strpos($html,'1970') === false && substr_count($html,'<dd>—</dd>') >= 2, 'Invalid source and target dates render a placeholder');
		}
		if ($case === 'unmatched') {
			checkReviewWorkspace(strpos($html,'workflow=create') !== false && strpos($html,'Criar um novo registo e reconciliar') !== false, 'No correspondence exposes creation');
		}
		if ($case === 'empty' || $case === 'loading') {
			checkReviewWorkspace(strpos($html,'workflow=create') === false && strpos($html,'id="kreaBulkMatchForm"') === false, 'Loading and empty queues cannot offer financial confirmation');
		}
		if (in_array($case, array('ready','invoice','readonly'), true)) {
			checkReviewWorkspace(strpos($html,'kb-review-source-11') !== false && strpos($html,'TARGET-BANK') !== false, 'Source and actual target account stay separate');
			checkReviewWorkspace(strpos($html,'kb-review-evidence') !== false && strpos($html,'Diferença por alocar') !== false, 'Evidence and allocation difference are available');
			checkReviewWorkspace(strpos($html,'name="review_signatures[11]"') !== false && strpos($html,'name="review_sources[11]" value="staged"') !== false, 'Original signed approval contract remains present');
			if ($case === 'readonly') {
				checkReviewWorkspace(strpos($html,'type="submit" class="kb-review-submit"') === false && strpos($html,'type="checkbox"') === false, 'Read-only users have no approval controls');
			} elseif ($mode === 'single') {
				checkReviewWorkspace(strpos($html,'name="review_confirmed"') === false && strpos($html,'type="checkbox"') === false, 'Individual approval remains the sole explicit button');
			} else {
				checkReviewWorkspace(strpos($html,'name="review_confirmed" value="1" required') !== false && strpos($html,'id="kb-review-selection"') !== false, 'Bulk approval and live selection summary remain distinct');
			}
		}
		if ($case === 'invoice') { checkReviewWorkspace(strpos($html,'Regista um pagamento desta fatura existente') !== false, 'Invoice confirmation explicitly records a payment instead of another invoice'); }
	}
}
echo 'OK: ' . $checks . " review workspace rendering checks (synthetic, no database)\n";
