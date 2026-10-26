<?php
/**
 * Helpers shared by the Add/Edit/Import/Export employee pages.
 */

/**
 * Normalize a Philippine mobile number to the 10 digits that follow "+63"
 * (always starting with 9). Accepts 9XXXXXXXXX, 09XXXXXXXXX, 639XXXXXXXXX
 * and +639XXXXXXXXX. Returns null if it can't be read as a valid number.
 *
 * Excel drops the leading 0 of "0917..." when it opens a CSV, so the
 * 10-digit "9XXXXXXXXX" form is the one that survives a round trip.
 */
function normalize_ph_mobile($raw): ?string {
    $d = preg_replace('/\D/', '', (string)$raw);
    if (strlen($d) === 12 && str_starts_with($d, '63')) $d = substr($d, 2);
    elseif (strlen($d) === 11 && str_starts_with($d, '0')) $d = substr($d, 1);
    return preg_match('/^9\d{9}$/', $d) ? $d : null;
}

/**
 * Wrap a value as ="value" so Excel shows it as text instead of turning
 * long numbers into 2.35E+12 (and dropping leading zeros).
 */
function excel_text($value): string {
    $value = (string)$value;
    return $value === '' ? '' : '="' . str_replace('"', '""', $value) . '"';
}

/** Undo excel_text() when reading a CSV back in (also trims). */
function clean_excel_text($value): string {
    $value = trim((string)$value);
    if (preg_match('/^="(.*)"$/s', $value, $m)) $value = $m[1];
    return trim($value);
}

/** True if a value looks like Excel's scientific notation (e.g. 2.35E+12). */
function looks_like_sci_notation($value): bool {
    return (bool)preg_match('/^\d+(\.\d+)?[eE][+-]?\d+$/', trim((string)$value));
}
