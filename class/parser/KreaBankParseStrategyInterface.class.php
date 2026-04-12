<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Parse strategy contract for one or more statement formats.
 */
interface KreaBankParseStrategyInterface
{
	/**
	 * Tell whether this strategy supports the given format.
	 *
	 * @param string $format
	 * @return bool
	 */
	public function supportsFormat($format);

	/**
	 * Parse statement file with normalized parser output shape.
	 *
	 * @param KreaBankParser $parser
	 * @param string $filePath
	 * @param string $fileName
	 * @param string $defaultCurrency
	 * @return array<int,array<string,mixed>>
	 */
	public function parse($parser, $filePath, $fileName, $defaultCurrency);
}
