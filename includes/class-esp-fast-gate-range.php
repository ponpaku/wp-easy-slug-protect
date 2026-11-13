<?php
/** Shared strict single-range parser for standalone and WordPress media. */
final class ESP_Fast_Gate_Range {
    /** Returns null if no Range; else a status=206(start,end) or status=416. */
    public static function parse($header, $length) {
        if ($header === null) { return null; }
        if (!is_int($length) || $length <= 0 || !is_string($header) ||
            strlen($header) > 256 ||
            !preg_match('/^bytes=(\d*)-(\d*)$/D', $header, $matches) ||
            ($matches[1] === '' && $matches[2] === '')) {
            return ['status' => 416];
        }
        // PHP integer casts saturate silently; reject absurdly long values.
        if (strlen($matches[1]) > 19 || strlen($matches[2]) > 19) {
            return ['status' => 416];
        }
        if ($matches[1] === '') {
            $suffix = (int)$matches[2];
            if ($suffix <= 0) { return ['status' => 416]; }
            return ['status' => 206, 'start' => max(0, $length - $suffix),
                'end' => $length - 1];
        }
        $start = (int)$matches[1];
        $end = $matches[2] === '' ? $length - 1 :
            min($length - 1, (int)$matches[2]);
        if ($start >= $length || $start < 0 || $end < $start) {
            return ['status' => 416];
        }
        return ['status' => 206, 'start' => $start, 'end' => $end];
    }
}
