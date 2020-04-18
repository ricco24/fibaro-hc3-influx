## Fibaro HC3 influx logs

Library provide Symfony multiple commands to export logs from Fibaro HC3 to InfluxDB.

### Commands

#### log:consumption
Logs consumption and actual power for compatible devices like Fibaro wall plug from **/consumption** API endpoint.

**Params**

- *start_timestamp* - When command runs for first time (or after delete consumption storage file for device) this timestamp is used as start point.
- *span* - Time span used for generate from/to timestamps. Lower span provide higher current power precision. Higher span provide higher consumption precision.
- *max_calls* - How many maximum times will be consumption API called (or until last event from HC3 reached)

#### log:events
Loads events from HC3 **/panels/event** API endpoint and log them into InfluxDB.
Every event is stored to InfluxDB with timestamp when event was triggered.

**Params**

- *limit* - How many events from API will be downloaded in one API call.
- *max_calls* - How many maximum times will be events API called (or until last event from HC3 reached)

#### log:refreshStates
#### log:weather
#### log:diagnostics