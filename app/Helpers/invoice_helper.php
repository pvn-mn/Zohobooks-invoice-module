<?php

/**
 * Formatting helpers for the tax invoice pages.
 */

if (! function_exists('money')) {
    function money($n): string
    {
        return number_format((float) $n, 2);
    }
}

if (! function_exists('qty')) {
    function qty($n): string
    {
        return number_format((float) $n, 2);
    }
}

if (! function_exists('fdate')) {
    /**
     * Y-m-d -> d/m/Y ('' for empty).
     */
    function fdate($d): string
    {
        return $d ? date('d/m/Y', strtotime($d)) : '';
    }
}

if (! function_exists('valid_date')) {
    function valid_date($d): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', (string) $d);

        return $dt && $dt->format('Y-m-d') === $d;
    }
}

if (! function_exists('words_below_1000')) {
    function words_below_1000(int $n): string
    {
        static $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        static $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        $hundreds = intdiv($n, 100);
        $rest     = $n % 100;
        $out      = [];
        if ($hundreds) {
            $out[] = $ones[$hundreds] . ' hundred';
        }
        if ($rest) {
            $w     = $rest < 20 ? $ones[$rest] : $tens[intdiv($rest, 10)] . ($rest % 10 ? ' ' . $ones[$rest % 10] : '');
            $out[] = $hundreds ? 'and ' . $w : $w;
        }

        return implode(' ', $out);
    }
}

if (! function_exists('number_to_words')) {
    function number_to_words($n): string
    {
        $n = (int) $n;
        if ($n === 0) {
            return 'zero';
        }
        $parts = [];
        $units = 0;

        foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand', 1 => ''] as $div => $label) {
            $chunk = intdiv($n, $div);
            $n %= $div;
            if ($div === 1) {
                $units = $chunk;
            }
            if ($chunk) {
                $parts[] = trim(words_below_1000($chunk) . ' ' . $label);
            }
        }
        if (count($parts) > 1 && $units > 0 && $units < 100) {
            $last = array_pop($parts);

            return implode(', ', $parts) . ' and ' . $last;
        }

        return implode(', ', $parts);
    }
}

if (! function_exists('amount_in_words')) {
    /**
     * 62976.60 -> "Sixty two thousand, nine hundred and seventy six Rupees sixty cents only"
     */
    function amount_in_words($amount): string
    {
        $cents  = (int) round((float) $amount * 100);
        $rupees = intdiv($cents, 100);
        $cents %= 100;
        $text = ucfirst(number_to_words($rupees)) . ' Rupees';
        if ($cents) {
            $text .= ' ' . number_to_words($cents) . ' cents';
        }

        return $text . ' only';
    }
}
