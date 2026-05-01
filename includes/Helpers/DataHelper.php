<?php
declare(strict_types=1);

namespace TorqueLogs\Helpers;

/**
 * Pure data-transformation utilities.
 *
 * All methods are static and side-effect free.
 * No database access, no output.
 *
 * Origin: parse_functions.php
 */
final class DataHelper
{
    /**
     * Private constructor — prevents instantiation of a utility class.
     */
    private function __construct() {}

    /**
     * Parse a two-column CSV file into a JSON-encoded key→value map.
     *
     * The first row is treated as a header and skipped by default.
     * Column 0 becomes the key, column 1 becomes the value.
     *
     * Origin: parse_functions.php → CSVtoJSON()
     *
     * @param  string $csvFile     Absolute path to the CSV file.
     * @param  bool   $skipHeader  Whether to skip the first row. Default true.
     * @return string              JSON-encoded associative object, or '{}' on failure.
     */
    public static function csvToJson(string $csvFile, bool $skipHeader = true): string
    {
        $map = [];

        $handle = fopen($csvFile, 'r');
        if ($handle === false) {
            return '{}';
        }

        $first = true;

        try {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                if ($skipHeader && $first) {
                    $first = false;
                    continue;
                }
                $first = false;

                if (isset($row[0], $row[1])) {
                    $map[$row[0]] = $row[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return json_encode($map) ?: '{}';
    }

    /**
     * Parse a two-column CSV file into an associative string map.
     *
     * The first row is treated as a header and skipped by default.
     * Column 0 becomes the map key, column 1 becomes the map value.
     *
     * @param  string $csvFile     Absolute path to the CSV file.
     * @param  bool   $skipHeader  Whether to skip the first row. Default true.
     * @return array<string, string>
     */
    public static function csvToMap(string $csvFile, bool $skipHeader = true): array
    {
        $map = [];

        $handle = fopen($csvFile, 'r');
        if ($handle === false) {
            return $map;
        }

        $first = true;

        try {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                if ($skipHeader && $first) {
                    $first = false;
                    continue;
                }
                $first = false;

                if (isset($row[0], $row[1])) {
                    $map[(string) $row[0]] = (string) $row[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return $map;
    }

    /**
     * Calculate the arithmetic mean of a numeric array.
     *
     * Returns 0 if the array is empty.
     *
     * Origin: parse_functions.php → average()
     *
     * @param  list<int|float> $values
     * @return float
     */
    public static function average(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        return array_sum($values) / $count;
    }

    /**
     * Calculate a percentile value from an unsorted numeric array.
     *
     * $percentile may be expressed as a fraction (0–1) or as a percentage (1–100).
     * Returns null for invalid input or an empty array.
     *
     * Origin: parse_functions.php → calc_percentile()
     *
     * @param  list<int|float> $data
     * @param  float           $percentile  0 < p < 1  OR  1 < p <= 100
     * @return float|null
     */
    public static function calcPercentile(array $data, float $percentile): ?float
    {
        if ($percentile > 0 && $percentile < 1) {
            $p = $percentile;
        } elseif ($percentile > 1 && $percentile <= 100) {
            $p = $percentile * 0.01;
        } else {
            return null;
        }

        $count = count($data);
        if ($count === 0) {
            return null;
        }

        sort($data);

        $allIndex   = ($count - 1) * $p;
        $intIndex   = (int) $allIndex;
        $floatPart  = $allIndex - $intIndex;

        if ($floatPart === 0.0) {
            return (float) $data[$intIndex];
        }

        if ($count > $intIndex + 1) {
            return (float) ($floatPart * ($data[$intIndex + 1] - $data[$intIndex]) + $data[$intIndex]);
        }

        return (float) $data[$intIndex];
    }

    /**
     * Convert an array of values into a comma-separated sparkline string.
     *
     * The array is reversed so the most-recent value appears last (rightmost
     * in a typical left-to-right sparkline).
     *
     * Origin: parse_functions.php → make_spark_data()
     *
     * @param  list<int|float|string> $values
     * @return string  e.g. "12,14,13,15"
     */
    public static function makeSparkData(array $values): string
    {
        return implode(',', array_reverse($values));
    }

    /**
     * Normalize a raw Torque unit string into a canonical unit key.
     *
     * @param  string $unit
     * @return string
     */
    public static function normalizeUnitKey(string $unit): string
    {
        $normalized = strtolower(trim($unit));

        return match ($normalized) {
            'km/h', 'kmh', 'kph' => 'kmh',
            'mph'                => 'mph',
            'm/s', 'ms'          => 'ms',
            '°c', '*c', 'c', 'celsius' => 'celsius',
            '°f', '*f', 'f', 'fahrenheit' => 'fahrenheit',
            'bar'                => 'bar',
            'psi'                => 'psi',
            'kpa'                => 'kpa',
            'mbar'               => 'mbar',
            'v'                  => 'volt',
            'volt'               => 'volt',
            '%', 'percent'       => 'percent',
            'rpm'                => 'rpm',
            'l/h', 'lt/h'        => 'lh',
            'cc/min'             => 'ccmin',
            'mg/cp'              => 'mgcp',
            'm3/h', 'm³/h'       => 'm3h',
            'degree'             => 'degree',
            default              => $normalized,
        };
    }

    /**
     * Case-insensitive substring count.
     *
     * Origin: parse_functions.php → substri_count()
     *
     * @param  string $haystack
     * @param  string $needle
     * @return int
     */
    public static function substriCount(string $haystack, string $needle): int
    {
        return substr_count(strtoupper($haystack), strtoupper($needle));
    }
}
