## Fibaro HC3 influx logs

Library provide Symfony multiple commands to export logs from Fibaro HC3 to InfluxDB.

### Commands

#### log:consumption
Logs consumption and actual power for compatible devices like Fibaro wall plug from **/consumption** API endpoint.
Data was stored into InfluxDB with **calculated timestamp** \[equation: (timestampTo - timestampFrom)/2]

**Params**

- *start_timestamp* - When command runs for first time (or after delete consumption storage file for device) this timestamp is used as start point.
- *span* - Time span used for generate from/to timestamps. Lower span provide higher current power precision. Higher span provide higher consumption precision.
- *max_calls* - How many maximum times will be consumption API called (or until last event from HC3 reached)

#### log:events
Loads events from HC3 **/panels/event** API endpoint and log them into InfluxDB.
Every event is stored into InfluxDB with **real timestamp** when event was triggered.

**Params**

- *limit* - How many events from API will be downloaded in one API call.
- *max_calls* - How many maximum times will be events API called (or until last event from HC3 reached)

#### log:refreshStates
Loads **/refreshStates** API data from HC3 and process data under changes key and store them into InfluxDB.
All events are stored with **timestamp from API response**.

#### log:weather
Simply loads weather data from HC3 **/weather** API endpoint and log them with **current timestamp** into InfluxDB.

#### log:diagnostics
Loads diagnostics data about HC3 system like - cpu load, memory, storage and store them into InfluxDB with **current timestamp**.