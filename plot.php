<?php
declare(strict_types=1);

require_once("./creds.php");
require_once("./db.php");
require_once("./parse_functions.php");

$pdo = get_pdo();

// Grab the session number — $sids must already be set by get_sessions.php
if (isset($_GET['id']) && in_array($_GET['id'], $sids, true)) {
    $session_id = preg_replace('/\D/', '', $_GET['id']);

    // Get the torque key->val mappings.
    $js    = CSVtoJSON('./data/torque_keys.csv');
    $jsarr = json_decode($js, true) ?? [];

    // Build a whitelist of plottable column names from $coldata.
    $allowed_cols = array_column($coldata, 'colname');

    // The columns to plot — default to OBD speed and intake temp.
    $v1 = (isset($_GET['s1']) && in_array($_GET['s1'], $allowed_cols, true))
        ? $_GET['s1'] : 'kd';   // OBD Speed
    $v2 = (isset($_GET['s2']) && in_array($_GET['s2'], $allowed_cols, true))
        ? $_GET['s2'] : 'kf';   // Intake Air Temp

    // Grab the label for each PID.
    $v1_label = '"' . ($jsarr[$v1] ?? $v1) . '"';
    $v2_label = '"' . ($jsarr[$v2] ?? $v2) . '"';

    // Column names come from the whitelist — safe to backtick-quote.
    $q1 = '`' . str_replace('`', '``', $v1) . '`';
    $q2 = '`' . str_replace('`', '``', $v2) . '`';

    $stmt = $pdo->prepare(
        "SELECT time, {$q1}, {$q2}
         FROM `{$db_table}`
         WHERE session = :sid
         ORDER BY time DESC"
    );
    $stmt->execute([':sid' => $session_id]);

    // Speed conversion factors.
    if (!$source_is_miles && $use_miles) {
        $speed_factor    = 0.621371;
        $speed_measurand = ' [mph]';
    } elseif ($source_is_miles && $use_miles) {
        $speed_factor    = 1.0;
        $speed_measurand = ' [mph]';
    } elseif ($source_is_miles && !$use_miles) {
        $speed_factor    = 1.609344;
        $speed_measurand = ' [km/h]';
    } else {
        $speed_factor    = 1.0;
        $speed_measurand = ' [km/h]';
    }

    // Temperature conversion closures.
    if (!$source_is_fahrenheit && $use_fahrenheit) {
        $temp_func      = fn(float $t): float => $t * 9.0 / 5.0 + 32.0;
        $temp_measurand = ' [&deg;F]';
    } elseif ($source_is_fahrenheit && $use_fahrenheit) {
        $temp_func      = fn(float $t): float => $t;
        $temp_measurand = ' [&deg;F]';
    } elseif ($source_is_fahrenheit && !$use_fahrenheit) {
        $temp_func      = fn(float $t): float => ($t - 32.0) * 5.0 / 9.0;
        $temp_measurand = ' [&deg;C]';
    } else {
        $temp_func      = fn(float $t): float => $t;
        $temp_measurand = ' [&deg;C]';
    }

    $d1 = $d2 = $spark1 = $spark2 = [];

    // Convert data units.
    foreach ($stmt->fetchAll() as $row) {
        // data column #1
        if (substri_count($jsarr[$v1] ?? '', 'Speed') > 0) {
            $x            = (int) $row[$v1] * $speed_factor;
            $v1_measurand = $speed_measurand;
        } elseif (substri_count($jsarr[$v1] ?? '', 'Temp') > 0) {
            $x            = $temp_func((float) $row[$v1]);
            $v1_measurand = $temp_measurand;
        } else {
            $x            = (int) $row[$v1];
            $v1_measurand = '';
        }
        $d1[]     = [$row['time'], $x];
        $spark1[] = $x;

        // data column #2
        if (substri_count($jsarr[$v2] ?? '', 'Speed') > 0) {
            $x            = (int) $row[$v2] * $speed_factor;
            $v2_measurand = $speed_measurand;
        } elseif (substri_count($jsarr[$v2] ?? '', 'Temp') > 0) {
            $x            = $temp_func((float) $row[$v2]);
            $v2_measurand = $temp_measurand;
        } else {
            $x            = (int) $row[$v2];
            $v2_measurand = '';
        }
        $d2[]     = [$row['time'], $x];
        $spark2[] = $x;
    }

    $v1_label = '"' . ($jsarr[$v1] ?? $v1) . ($v1_measurand ?? '') . '"';
    $v2_label = '"' . ($jsarr[$v2] ?? $v2) . ($v2_measurand ?? '') . '"';

    if (!empty($spark1) && !empty($spark2)) {
        $sparkdata1  = implode(',', array_reverse($spark1));
        $sparkdata2  = implode(',', array_reverse($spark2));
        $max1        = round((float) max($spark1), 1);
        $max2        = round((float) max($spark2), 1);
        $min1        = round((float) min($spark1), 1);
        $min2        = round((float) min($spark2), 1);
        $avg1        = round((float) average($spark1), 1);
        $avg2        = round((float) average($spark2), 1);
        $pcnt25data1 = round((float) calc_percentile($spark1, 25), 1);
        $pcnt25data2 = round((float) calc_percentile($spark2, 25), 1);
        $pcnt75data1 = round((float) calc_percentile($spark1, 75), 1);
        $pcnt75data2 = round((float) calc_percentile($spark2, 75), 1);
    }
}

