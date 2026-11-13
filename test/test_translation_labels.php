<?php
// No Dolibarr bootstrap or database access required.
require_once __DIR__ . '/../lib/kreabank.lib.php';

$values = array();
foreach (file(__DIR__ . '/../langs/de_DE/kreabank.lang', FILE_IGNORE_NEW_LINES) as $line) {
	if (strpos($line, '=') !== false && substr($line, 0, 1) !== '#') {
		list($key, $value) = explode('=', $line, 2);
		if (isset($values[$key])) {
			throw new RuntimeException('Duplicate translation: ' . $key);
		}
		$values[$key] = $value;
	}
}
$langs = new class($values) {
	private $values;
	public function __construct($values) { $this->values = $values; }
	public function transnoentities($key) { return isset($this->values[$key]) ? $this->values[$key] : $key; }
};

foreach (array('payment_supplier' => 'Lieferantenzahlung', 'payment_supplier_linked' => 'Lieferantenzahlung', 'invoice_supplier' => 'Lieferantenrechnung', 'native' => 'Nativer Bankabgleich', 'unknown_type' => 'unknown type', '' => '') as $type => $expected) {
	if (kreabankTypeLabel($type) !== $expected) {
		throw new RuntimeException('Wrong label for: ' . $type);
	}
}
echo "Translation labels: OK\n";
