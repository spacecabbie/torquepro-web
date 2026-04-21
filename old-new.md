# Torque Logs — File Traceability: Original → New Structure

Every file that existed before the restructure is listed here with exactly
where its code went. Files added during this project (not in the original
GitHub source at https://github.com/econpy/torque) are marked **[added]**.

---

## `auth_functions.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| `get_user()` — reads username from `$_POST`/`$_GET` | `includes/Auth/Auth.php` — `TorqueLogs\Auth\Auth` (method removed; credentials read inline inside `login()`, `$_GET` source dropped) |
| `get_pass()` — reads password from `$_POST`/`$_GET` | `includes/Auth/Auth.php` — same as above |
| `get_id()` — reads and validates Torque ID (32-char hex) | `includes/Auth/Auth.php` — `Auth::requireApp()` |
| `auth_user()` — compares user+pass against creds (`==`) | `includes/Auth/Auth.php` — `Auth::login()`, comparison changed to `hash_equals()` |
| `auth_id()` — validates Torque ID against allowed list | `includes/Auth/Auth.php` — `Auth::requireApp()` |

---

## `auth_user.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| Session start + `$_SESSION['torque_logged_in']` check | `includes/Auth/Auth.php` — `Auth::requireUser()` |
| User+pass login flow | `includes/Auth/Auth.php` — `Auth::requireUser()` → `Auth::login()` |
| `$_SESSION['torque_logged_in'] = $logged_in` save | `includes/Auth/Auth.php` — `Auth::login()` |
| HTML login form (entire page rendered on auth failure) | `login.php` — dedicated login page (BS5, CSRF token, `hash_equals()`) |
| Login form action pointed at `session.php` | `login.php` — now posts to `login.php` with `?redirect=` param |
| Old Bootstrap 3 / dead netdna CDN references | `login.php` — replaced with Bootstrap 5 from cdnjs |

---

## `auth_app.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| Torque ID auth flow (no session, no cookies) | `includes/Auth/Auth.php` — `Auth::requireApp()` |
| Plain-text error response on auth failure | `includes/Auth/Auth.php` — `Auth::requireApp()` |
| `$auth_user_with_torque_id = true` default | `includes/Auth/Auth.php` — `Auth::requireApp()` |

---

## `creds.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| `$db_host`, `$db_user`, `$db_pass`, `$db_name`, `$db_table` | `includes/config.php` |
| `$auth_user`, `$auth_pass` | `includes/config.php` |
| `$torque_id`, `$torque_id_hash` | `includes/config.php` |
| `$source_is_fahrenheit`, `$use_fahrenheit` | `includes/config.php` |
| `$source_is_miles`, `$use_miles` | `includes/config.php` |
| `$hide_empty_variables`, `$show_session_length` | `includes/config.php` |

---

## `creds-sample.php`
**Origin:** Original GitHub source  
**Status:** KEPT (reference file for new installations, not executed)

No code moves — file stays as-is but updated to reference `includes/config.php`.

---

## `db.php`
**Origin:** Added during upgrade from `mysql_*` to PDO  
**Status:** DELETED — **[added]**

| Code | Destination |
|------|-------------|
| `get_pdo()` — PDO singleton factory | `includes/Database/Connection.php` — `TorqueLogs\Database\Connection::get()` |
| PDO options (`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`) | `includes/Database/Connection.php` |
| Safe error handling (no credential exposure) | `includes/Database/Connection.php` |

---

## `get_sessions.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| `session_start()` guard | `includes/Auth/Auth.php` — handled by `Auth::requireUser()` |
| `$timezone = $_SESSION['time']` | `includes/Data/SessionRepository.php` — passed in as parameter |
| SQL query: `SELECT COUNT, MIN(time), MAX(time), session … GROUP BY session` | `includes/Data/SessionRepository.php` — `SessionRepository::findAll()` |
| Session size filter (`>= 2 data points`) | `includes/Data/SessionRepository.php` — `SessionRepository::findAll()` |
| `$sids`, `$seshdates`, `$seshsizes` bare variable side-effects | `includes/Data/SessionRepository.php` — `findAll()` returns structured array instead |
| `date('F d, Y H:i', ...)` 24h format | `includes/Data/SessionRepository.php` — preserved |
| `gmdate('H:i:s', ...)` session duration | `includes/Data/SessionRepository.php` — preserved |

---

## `get_columns.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| `INFORMATION_SCHEMA.COLUMNS` query for `k*` plottable columns | `includes/Data/ColumnRepository.php` — `ColumnRepository::findPlottable()` |
| `$plottable_types` whitelist | `includes/Data/ColumnRepository.php` — class constant |
| `$coldata` bare variable side-effect | `includes/Data/ColumnRepository.php` — `findPlottable()` returns array |
| `$numcols` count | `includes/Data/ColumnRepository.php` — caller counts the returned array |
| Per-session empty-column check (`COUNT(DISTINCT col) < 2`) | `includes/Data/ColumnRepository.php` — `ColumnRepository::findEmpty(sid)` |
| `$coldataempty` bare variable side-effect | `includes/Data/ColumnRepository.php` — `findEmpty()` returns array |

---

## `plot.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| `CSVtoJSON('./data/torque_keys.csv')` — load PID name map | `includes/Data/PlotRepository.php` — calls `DataHelper::csvToJson()` |
| `$v1`, `$v2` column selection from `$_GET` + whitelist check | `includes/Data/PlotRepository.php` — `PlotRepository::load(sid, v1, v2)` |
| `$v1_label`, `$v2_label` PID friendly names | `includes/Data/PlotRepository.php` — returned in result array |
| Speed conversion factors (`$speed_factor`, `$speed_measurand`) | `includes/Data/PlotRepository.php` — internal logic |
| Temperature conversion closures | `includes/Data/PlotRepository.php` — internal logic |
| `SELECT time, col1, col2 … WHERE session` | `includes/Data/PlotRepository.php` — prepared statement |
| `$d1`, `$d2` time-series arrays | `includes/Data/PlotRepository.php` — returned in result array |
| `$spark1`, `$spark2` sparkline arrays | `includes/Data/PlotRepository.php` — returned in result array |
| `$max1/2`, `$min1/2`, `$avg1/2`, `$pcnt25/75` stats | `includes/Data/PlotRepository.php` — returned in result array |
| `substri_count()` calls | `includes/Data/PlotRepository.php` — calls `DataHelper::substriCount()` |
| `average()`, `calc_percentile()` calls | `includes/Data/PlotRepository.php` — calls `DataHelper::average()` / `calcPercentile()` |

---

## `del_session.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| `DELETE FROM raw_logs WHERE session = :sid` | `includes/Session/SessionManager.php` — `SessionManager::delete(sid)` |
| `$_GET`/`$_POST['deletesession']` reading | Moved to `dashboard.php` — action detection, passes sid to `SessionManager::delete()` |

---

## `merge_sessions.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| `UPDATE raw_logs SET session = :sid WHERE session = :with` | `includes/Session/SessionManager.php` — `SessionManager::merge(sid, with)` |
| Neighbour validation (`$idx1 !== ($idx2 + 1)`) | `includes/Session/SessionManager.php` — `SessionManager::merge()` |
| `$_GET`/`$_POST['mergesession']` reading | Moved to `dashboard.php` — action detection, passes sids to `SessionManager::merge()` |

---

## `parse_functions.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| `CSVtoJSON($csvFile)` | `includes/Helpers/DataHelper.php` — `DataHelper::csvToJson()` |
| `substri_count($haystack, $needle)` | `includes/Helpers/DataHelper.php` — `DataHelper::substriCount()` |
| `average($arr)` | `includes/Helpers/DataHelper.php` — `DataHelper::average()` |
| `calc_percentile($data, $percentile)` | `includes/Helpers/DataHelper.php` — `DataHelper::calcPercentile()` |
| `make_spark_data($sparkarray)` | `includes/Helpers/DataHelper.php` — `DataHelper::makeSparkData()` |

---

## `upload_data.php`
**Origin:** Original GitHub source  
**Status:** UPDATED (kept as public entry point)

| Code | Destination |
|------|-------------|
| `require_once('auth_app.php')` — app auth bootstrap | `upload_data.php` — replaced with `Auth::requireApp()` call |
| `log_torque_request()` — file logger function | `includes/Logging/FileLogger.php` — `TorqueLogs\Logging\FileLogger` (PSR-3) |
| `audit_torque_request()` — DB audit function | `includes/Logging/FileLogger.php` or stays inline in `upload_data.php` as `AuditLogger` |
| `is_valid_column_name()` | `includes/Helpers/SqlHelper.php` — `SqlHelper::isValidColumnName()` |
| `quote_identifier()` | `includes/Helpers/SqlHelper.php` — `SqlHelper::quoteIdentifier()` |
| `UPLOAD_LOG_ENABLED`, `UPLOAD_LOG_DIR` constants | `includes/config.php` |
| Sensor data loop, `INSERT`, `ALTER TABLE` logic | `upload_data.php` — stays, refactored to use new classes |

---

## `export.php`
**Origin:** Original GitHub source  
**Status:** UPDATED (kept as public entry point)

| Code | Destination |
|------|-------------|
| No auth check (security bug) | `export.php` — `Auth::requireUser()` added at top |
| CSV export logic | `export.php` — stays, no logic class needed for simple output |
| JSON export logic | `export.php` — stays |

---

## `session.php`
**Origin:** Original GitHub source  
**Status:** DELETED (temporary redirect added first)

| Code | Destination |
|------|-------------|
| PHP bootstrap (creds, db, auth, sessions, columns, plot) | `dashboard.php` — uses new classes |
| Session picker dropdown | `dashboard.php` — preserved in sidebar |
| Merge / Delete session forms | `dashboard.php` — preserved, calls `SessionManager` |
| Variable selector form | `dashboard.php` — preserved in sidebar |
| GPS data query + `google.maps.LatLng` array | `includes/Data/GpsRepository.php` — `GpsRepository::findTrack()` + Leaflet in `dashboard.php` |
| Google Maps `initialize()` JS function | `dashboard.php` — replaced with Leaflet (free, no API key) |
| Flot chart JS | `dashboard.php` — preserved |
| Data summary table | `dashboard.php` — preserved, restyled as BS5 cards |
| Export buttons | `dashboard.php` — preserved in sidebar |

---

## `timezone.php`
**Origin:** Original GitHub source  
**Status:** DELETED — inlined

| Code | Destination |
|------|-------------|
| `session_start()` | `dashboard.php` — handled by `Auth::requireUser()` |
| `$_SESSION['time'] = $_GET['time']` | `dashboard.php` — early-exit block at top of file if `?set_timezone` param present |

---

## `url.php`
**Origin:** Original GitHub source  
**Status:** DELETED

| Code | Destination |
|------|-------------|
| URL builder redirecting to `session.php` | **Dropped entirely** — `dashboard.php` handles variable selection via direct GET params (`?id=&s1=&s2=`), no redirect router needed |

---

## `live_log.php` **[added]**
**Origin:** Added during this project (not in original GitHub source)  
**Status:** UPDATED — absorbs `live_log_data.php`

| Code | Destination |
|------|-------------|
| HTML/JS live monitor page | `live_log.php` — stays |
| `fetch('live_log_data.php?since_id=…')` poll call | `live_log.php` — URL changed to `live_log.php?data=1&since_id=…` |

---

## `live_log_data.php` **[added]**
**Origin:** Added during this project (not in original GitHub source)  
**Status:** DELETED — inlined

| Code | Destination |
|------|-------------|
| Session auth check (JSON 401 on failure) | `live_log.php` — early-exit block using `Auth::requireJson()` |
| `SELECT … FROM upload_requests WHERE id > :since_id` | `live_log.php` — early-exit block |
| `INFORMATION_SCHEMA` column comment fetch | `live_log.php` — early-exit block |
| JSON response with `rows`, `col_names`, `ts` | `live_log.php` — early-exit block |

---

## `dashboard.php` **[added]**
**Origin:** Created during this project as replacement for `session.php`  
**Status:** UPDATED — uses new classes, absorbs `timezone.php`

New file — no old code maps to it except what is listed under `session.php` above.

---

## `backfill_sensor_names.php` **[added]**
**Origin:** One-time utility script created during this project  
**Status:** DELETED — already executed, no longer needed

| Code | Destination |
|------|-------------|
| Known PID → name map | `includes/Auth/Auth.php` KNOWN_SENSORS reference in `live_log.php` JS constant |
| `ALTER TABLE … MODIFY … COMMENT` statements | Already applied to DB — not needed again |

---

## New files with no direct predecessor

| New file | Why it exists |
|---|---|
| `includes/Psr/Log/LoggerInterface.php` | PSR-3 interface — manual copy, replaces need for Composer `psr/log` |
| `includes/Psr/Log/LogLevel.php` | PSR-3 log level constants — manual copy |
| `includes/Auth/Auth.php` | Consolidates all three old auth files into one PSR-4 class |
| `includes/Data/GpsRepository.php` | Extracted from inline DB query in `dashboard.php` |
| `includes/Helpers/SqlHelper.php` | Extracted from inline functions in `upload_data.php` |
| `login.php` | Login form was embedded inside `auth_user.php` — now a proper dedicated page |
| `includes/.htaccess` | Denies direct web access to all files in `includes/` |
| `restructure.md` | This restructure plan document |
| `old-new.md` | This traceability document |
