**Torque Pro Realtime Web Upload Mechanism – Factual Specification for Parser Development**

Torque Pro sends realtime OBD2/sensor/GPS/accelerometer/calculated data as an **HTTP GET request** (query string) to the exact "Web-server URL" configured by the user in the app under **Settings → Data Logging & Upload → Realtime Web Upload**.  
The upload fires periodically according to the "Weblogging interval" setting and contains **only** the PIDs/sensors the user has selected under "Select what to log".  
The receiving endpoint **must** return exactly the plain-text string **`OK!`** (including the exclamation mark) for successful confirmation.

**Exact upload format (confirmed query-string parameters)**  
All data arrives as standard URL query parameters (`$_GET` equivalent). Observed and confirmed parameters include:  
- `v=` → protocol/version (integer, observed values: 3, 8, 9)  
- `eml=` → email address configured in Torque settings  
- `session=` → large integer session identifier  
- `id=` → unique hexadecimal Torque/device identifier  
- `time=` → timestamp of the reading in milliseconds since Unix epoch  
- Multiple `k*` or `kff*` parameters → each selected PID/sensor appears as `k[internal_code]=[numeric_value]`  

**Real observed example (from Torque developer/community forum)**:  
`?v=3&session=1499658078071&id=75d3b2a46f7bb28a58940a4296270a32&time=1499658087444&kff1005=78.52391974&kff1006=17.46141234&kff1001=28.856194`  
(plus additional `k*` entries for every selected PID).

In some payloads (especially newer versions), additional metadata parameters are also present:  
- `defaultUnit[kcode]` / `userUnit[kcode]` → units for that parameter  
- `userShortName[kcode]` / `userFullName[kcode]` → the short and long human-readable names defined for that PID.

**K-codes (internal parameter identifiers) – full explanation**  
Every value in the upload is keyed by a Torque-internal **k-code** (parameter name starting with `k` or `kff`).  
- These are **not** the raw OBD-II mode/PID hex values (e.g., 010C for RPM).  
- Short codes (`kc`, `k0d`, `k05`, `k0c`, etc.) typically correspond to standard OBD-II PIDs.  
- `kffXXXX` codes are Torque’s own built-in/calculated values (GPS latitude/longitude, accelerometer axes, battery voltage, trip computer data, fuel economy calculations, phone sensors, etc.).  
- When a user adds a **custom PID** via **Manage extra PIDs/Sensors** (manual entry or CSV import), Torque internally assigns it a unique k-code. The long name, short name, equation, and units defined by the user are stored locally in the app.  
- The web upload string contains **only** the assigned k-code and its current numeric value. Human-readable names are **not** guaranteed in every payload, but when present they appear as the separate `userShortName[kcode]` and `userFullName[kcode]` parameters (see example payload link below).  
- There is **no complete official/public master list** of every possible k-code; the set depends on the vehicle, the user’s selected PIDs, and Torque’s internal calculations. Partial community mappings exist for common built-ins (e.g., `kff1005` = GPS Longitude, `kff1006` = GPS Latitude, `kff1001` = GPS Speed, `kff1238` = battery voltage).

**How the uploaded string is structured and should be processed**  
The entire payload is a flat set of key-value pairs from the HTTP GET query string.  
- All sensor readings appear under keys that begin with `k`.  
- Metadata keys (`v`, `eml`, `session`, `id`, `time`) provide context for the timestamped data point.  
- Optional metadata keys (`userUnit*`, `defaultUnit*`, `userShortName*`, `userFullName*`, `profile*`) may appear and provide naming/units when Torque chooses to include them.  
- Values for `k*` parameters are always numeric (float or integer).  
- The format has remained stable across app versions; newer payloads may include the additional `user*` name/unit fields.

**Links to confirmed data examples and sources (all verified live)**  
1. Torque official forum thread with exact working GET example and developer confirmation: https://torque-bhp.com/community/main-forum/upload-to-webserver-how/  
2. Full example payload JSON (including userFullName*, userShortName*, units for many k-codes): https://github.com/JOHLC/ha-torque-2.0/blob/main/example-payload-data.md  
3. Comprehensive community k-code → description table (covers GPS, OBD, accelerometer, etc.): https://github.com/briancline/torque-satellite/blob/master/doc/codes-table.md  
4. Classic open-source receiver repository containing the upload_data.php that processes real Torque payloads: https://github.com/econpy/torque (see web/upload_data.php for the exact parameter handling used in production)  

This specification is derived exclusively from the Torque developer’s own forum statements, live observed payloads in active GitHub implementations, and community-verified k-code mappings. Use these facts and links directly to implement the parser.

known k codes:
https://raw.githubusercontent.com/briancline/torque-satellite/master/doc/codes-table.md