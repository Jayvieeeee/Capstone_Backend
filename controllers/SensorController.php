<?php

require_once __DIR__ . '/PumpAutomation.php';
require_once __DIR__ . '/SensorAutomation.php';

class SensorController
{
    private $db;
    private $table = 'sensor_readings';
    private const SENSOR_IDS = ['dht22', 'light_meter', 'soil_moisture', 'soil_ph'];
    private const SENSOR_STALE_SECONDS = 120;

    private const SENSOR_THRESHOLDS = [
        'soil_moisture' => [
            'optimal' => ['min' => 40, 'max' => 60],
            'attention' => [['min' => 25, 'max' => 40], ['min' => 60, 'max' => 70]],
            'description' => 'optimal 40–60%, attention 25–<40% or >60–70%, required <25% or >70%.',
            'unit' => '%'
        ],
        'soil_ph' => [
            'optimal' => ['min' => 5.5, 'max' => 6.9],
            'attention' => [['min' => 4.5, 'max' => 5.5], ['min' => 6.9, 'max' => 7.0]],
            'description' => 'optimal 5.5–6.9, attention 4.5–<5.5 or >6.9–7.0, required <4.5 or >7.0.',
            'unit' => ' pH'
        ],
        'temperature' => [
            'optimal' => ['min' => 20, 'max' => 30],
            'attention' => [['min' => 15, 'max' => 20], ['min' => 30, 'max' => 35]],
            'description' => 'optimal 20–30°C, attention 15–<20°C or >30–35°C, required <15°C or >35°C.',
            'unit' => '°C'
        ],
        'humidity' => [
            'optimal' => ['min' => 60, 'max' => 80],
            'attention' => [['min' => 45, 'max' => 60], ['min' => 80, 'max' => 90]],
            'description' => 'optimal 60–80%, attention 45–<60% or >80–90%, required <45% or >90%.',
            'unit' => '%'
        ],
        'light_intensity' => [
            'optimal' => ['min' => 4000, 'max' => null],
            'attention' => [['min' => 2000, 'max' => 4000]],
            'description' => 'optimal at least 4,000 lx, attention 2,000–<4,000 lx, required below 2,000 lx.',
            'unit' => 'lx'
        ],
    ];

    private const SUPPORTED_DEVICES = ['light', 'pump'];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function store(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            $this->respond([
                'success' => false,
                'message' => 'Invalid JSON request body.'
            ], 400);
        }

        $fields = [
            'temperature',
            'humidity',
            'soil_moisture',
            'soil_ph',
            'light_intensity'
        ];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || !is_numeric($data[$field])) {
                $this->respond([
                    'success' => false,
                    'message' => "Missing or invalid field: $field"
                ], 400);
            }
        }

        $temperature = (float) $data['temperature'];
        $humidity = (float) $data['humidity'];
        $soilMoisture = (float) $data['soil_moisture'];
        $soilPh = (float) $data['soil_ph'];
        $lightIntensity = (float) $data['light_intensity'];

        if (
            $temperature < -50 || $temperature > 100 ||
            $humidity < 0 || $humidity > 100 ||
            $soilMoisture < 0 || $soilMoisture > 100 ||
            $soilPh < 0 || $soilPh > 14 ||
            $lightIntensity < 0
        ) {
            $this->respond([
                'success' => false,
                'message' => 'One or more sensor readings are out of range.'
            ], 400);
        }

        try {
            $sensorStates = $this->validatedSensorStates(
                $data['sensor_states'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }

        try {
            PumpAutomation::ensureReady($this->db);
            SensorAutomation::ensureReady($this->db);
            SensorAutomation::reactivateExpired($this->db);
            $this->db->beginTransaction();
            $sql = "INSERT INTO {$this->table}
                    (temperature, humidity, soil_moisture,
                     soil_ph, light_intensity)
                    VALUES
                    (:temperature, :humidity, :soil_moisture,
                     :soil_ph, :light_intensity)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':temperature' => $temperature,
                ':humidity' => $humidity,
                ':soil_moisture' => $soilMoisture,
                ':soil_ph' => $soilPh,
                ':light_intensity' => $lightIntensity
            ]);
            $this->saveSensorStates($sensorStates);
            if (SensorAutomation::isEnabled($this->db, 'soil_moisture')) {
                PumpAutomation::applyReading(
                    $this->db,
                    $soilMoisture,
                    $sensorStates['soil_moisture'] === 'Online'
                );
            }
            if (SensorAutomation::isEnabled($this->db, 'light_intensity')) {
                PumpAutomation::applyLightReading(
                    $this->db,
                    $lightIntensity,
                    $sensorStates['light_meter'] === 'Online'
                );
            }
            $readingId = $this->db->lastInsertId();
            $this->db->commit();

            $this->respond([
                'success' => true,
                'message' => 'Sensor readings saved successfully.',
                'reading_id' => $readingId
            ], 201);
        } catch (PDOException | RuntimeException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Sensor insert failed: ' . $e->getMessage());
            $this->respond([
                'success' => false,
                'message' => 'Failed to save sensor readings.'
            ], 500);
        }
    }

    public function states(): void
    {
        try {
            $this->ensureSensorStateRows();
            $rows = $this->db->query(
                'SELECT sensor_id, status, last_updated
                 FROM sensor_states
                 ORDER BY sensor_id'
            )->fetchAll(PDO::FETCH_ASSOC);

            $now = time();
            foreach ($rows as &$row) {
                $updatedAt = strtotime($row['last_updated']);
                $isStale = $updatedAt === false ||
                    ($now - $updatedAt) > self::SENSOR_STALE_SECONDS;
                if ($isStale && $row['status'] !== 'Not working') {
                    $update = $this->db->prepare(
                        "UPDATE sensor_states
                         SET status = 'Not working', last_updated = last_updated
                         WHERE sensor_id = ? AND last_updated = ?"
                    );
                    $update->execute([$row['sensor_id'], $row['last_updated']]);
                    $row['status'] = 'Not working';
                }
            }
            unset($row);

            $this->respond([
                'success' => true,
                'data' => [
                    'sensors' => $rows,
                    'stale_after_seconds' => self::SENSOR_STALE_SECONDS
                ]
            ], 200);
        } catch (PDOException $e) {
            error_log('Sensor state query failed: ' . $e->getMessage());
            $this->respond([
                'success' => false,
                'message' => 'Unable to load sensor states.'
            ], 500);
        }
    }

    public function reportStates(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->respond([
                'success' => false,
                'message' => 'Invalid JSON request body.'
            ], 400);
        }

        try {
            $sensorStates = $this->validatedSensorStates(
                $data['sensor_states'] ?? null,
                false
            );
        } catch (InvalidArgumentException $e) {
            $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }

        if (!$sensorStates) {
            $this->respond([
                'success' => false,
                'message' => 'At least one sensor state is required.'
            ], 400);
        }

        try {
            $this->saveSensorStates($sensorStates);
            $this->respond([
                'success' => true,
                'message' => 'Sensor states updated successfully.'
            ], 200);
        } catch (PDOException $e) {
            error_log('Sensor state update failed: ' . $e->getMessage());
            $this->respond([
                'success' => false,
                'message' => 'Unable to update sensor states.'
            ], 500);
        }
    }

    public function automation(): void
    {
        try {
            $states = SensorAutomation::getAll($this->db);
            $this->respond([
                'success' => true,
                'data' => ['automations' => $states]
            ], 200);
        } catch (PDOException $e) {
            error_log('Sensor automation query failed: ' . $e->getMessage());
            $this->respond([
                'success' => false,
                'message' => 'Unable to load sensor automation settings.'
            ], 500);
        }
    }

    public function updateAutomation(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (
            !is_array($data) ||
            !isset($data['sensor_id']) ||
            !is_string($data['sensor_id']) ||
            !array_key_exists('enabled', $data) ||
            !is_bool($data['enabled'])
        ) {
            $this->respond([
                'success' => false,
                'message' => 'sensor_id and boolean enabled are required.'
            ], 400);
        }

        try {
            $state = SensorAutomation::set(
                $this->db,
                $data['sensor_id'],
                $data['enabled']
            );
            $this->respond([
                'success' => true,
                'data' => ['automation' => $state]
            ], 200);
        } catch (InvalidArgumentException $e) {
            $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (PDOException | RuntimeException $e) {
            error_log('Sensor automation update failed: ' . $e->getMessage());
            $this->respond([
                'success' => false,
                'message' => 'Unable to update sensor automation.'
            ], 500);
        }
    }

    public function dashboard(): void
    {
        try {
            $reading = $this->fetchLatestReading();
            $deviceRows = $this->fetchSupportedDeviceStates();
            $lastSeen = $this->findLastSeen($reading, $deviceRows);
            $online = $lastSeen !== null && (time() - strtotime($lastSeen)) <= 120;
            $metrics = $reading === null ? [] : $this->buildMetrics($reading);

            $optimalCount = 0;
            foreach ($metrics as $metric) {
                if ($metric['status'] === 'optimal') {
                    $optimalCount++;
                }
            }

            $alerts = $this->buildAlerts($reading, $metrics, $deviceRows, $lastSeen, $online);
            $this->respond([
                'success' => true,
                'data' => [
                    'reading' => $reading,
                    'metrics' => $metrics,
                    'summary' => [
                        'optimal_count' => $optimalCount,
                        'total_count' => count(self::SENSOR_THRESHOLDS)
                    ],
                    'alerts' => $alerts,
                    'device' => [
                        'node_name' => 'SPROUT ESP32 Sensor & Relay Station',
                        'connectivity' => $online ? 'online' : 'offline',
                        'last_seen' => $lastSeen,
                        'sample_interval_seconds' => $this->sampleIntervalSeconds(),
                        'last_calibration_at' => null,
                        'devices' => $deviceRows
                    ]
                ]
            ], 200);
        } catch (PDOException $e) {
            error_log('Sensor dashboard query failed: ' . $e->getMessage());
            $this->respond([
                'success' => false,
                'error' => 'Unable to load sensor dashboard data.'
            ], 500);
        }
    }

    public function history(): void
    {
        $days = filter_var($_GET['days'] ?? 7, FILTER_VALIDATE_INT);
        $limit = filter_var($_GET['limit'] ?? 1000, FILTER_VALIDATE_INT);

        if ($days === false || $days < 1 || $days > 30) {
            $this->respond([
                'success' => false,
                'error' => 'days must be between 1 and 30.'
            ], 400);
        }

        if ($limit === false || $limit < 1 || $limit > 5000) {
            $this->respond([
                'success' => false,
                'error' => 'limit must be between 1 and 5000.'
            ], 400);
        }

        try {
            $sql = "SELECT reading_id, timestamp, temperature, humidity,
                           soil_moisture, soil_ph, light_intensity
                    FROM {$this->table}
                    WHERE timestamp >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$days} DAY)
                    ORDER BY timestamp DESC, reading_id DESC
                    LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $records = array_map([$this, 'normalizeReading'], $stmt->fetchAll(PDO::FETCH_ASSOC));
            $countStmt = $this->db->query(
                "SELECT COUNT(*)
                 FROM {$this->table}
                 WHERE timestamp >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$days} DAY)"
            );

            $this->respond([
                'success' => true,
                'data' => [
                    'days' => $days,
                    'limit' => $limit,
                    'total_records' => (int) $countStmt->fetchColumn(),
                    'records' => $records
                ]
            ], 200);
        } catch (PDOException $e) {
            error_log('Sensor history query failed: ' . $e->getMessage());
            $this->respond([
                'success' => false,
                'error' => 'Unable to load sensor history.'
            ], 500);
        }
    }

    public function trends(): void
    {
        $days = filter_var($_GET['days'] ?? 7, FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 30) {
            $this->respond([
                'success' => false,
                'error' => 'days must be between 1 and 30.'
            ], 400);
        }

        try {
            $sql = "SELECT DATE(timestamp) AS day,
                           AVG(temperature) AS temperature,
                           AVG(humidity) AS humidity,
                           AVG(soil_moisture) AS soil_moisture,
                           AVG(soil_ph) AS soil_ph,
                           AVG(light_intensity) AS light_intensity
                    FROM {$this->table}
                    WHERE timestamp >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$days} DAY)
                    GROUP BY DATE(timestamp)
                    ORDER BY day ASC";
            $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $this->respond([
                'success' => true,
                'data' => [
                    'days' => $days,
                    'records' => array_map(static function (array $row): array {
                        foreach ([
                            'temperature',
                            'humidity',
                            'soil_moisture',
                            'soil_ph',
                            'light_intensity'
                        ] as $field) {
                            $row[$field] = (float) $row[$field];
                        }
                        return $row;
                    }, $rows)
                ]
            ], 200);
        } catch (PDOException $e) {
            error_log('Sensor trends query failed: ' . $e->getMessage());
            $this->respond([
                'success' => false,
                'error' => 'Unable to load sensor trends.'
            ], 500);
        }
    }

    public function latestForAssistant(): ?array
    {
        return $this->fetchLatestReading();
    }

    public function historyForAssistant(int $limit = 1000): array
    {
        $stmt = $this->db->prepare(
            "SELECT reading_id, timestamp, temperature, humidity,
                    soil_moisture, soil_ph, light_intensity
             FROM {$this->table}
             ORDER BY timestamp DESC, reading_id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'normalizeReading'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countForAssistant(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM {$this->table}"
        )->fetchColumn();
    }

    private function fetchLatestReading(): ?array
    {
        $stmt = $this->db->query(
            "SELECT reading_id, timestamp, temperature, humidity,
                    soil_moisture, soil_ph, light_intensity
             FROM {$this->table}
             ORDER BY timestamp DESC, reading_id DESC
             LIMIT 1"
        );
        $reading = $stmt->fetch(PDO::FETCH_ASSOC);
        return $reading ? $this->normalizeReading($reading) : null;
    }

    private function normalizeReading(array $reading): array
    {
        foreach ([
            'reading_id',
            'temperature',
            'humidity',
            'soil_moisture',
            'soil_ph',
            'light_intensity'
        ] as $field) {
            $reading[$field] = $field === 'reading_id'
                ? (int) $reading[$field]
                : (float) $reading[$field];
        }
        return $reading;
    }

    private function buildMetrics(array $reading): array
    {
        $definitions = [
            ['soil_moisture', 'soil-moisture', 'Soil moisture', '%'],
            ['soil_ph', 'soil-ph', 'Soil pH', ''],
            ['temperature', 'temperature', 'Temperature', '°C'],
            ['humidity', 'humidity', 'Humidity', '%'],
            ['light_intensity', 'light-intensity', 'Light intensity', 'lux']
        ];

        $metrics = [];
        foreach ($definitions as [$field, $id, $label, $unit]) {
            $threshold = self::SENSOR_THRESHOLDS[$field] ?? null;
            $value = $reading[$field];
            $status = 'unknown';
            if ($threshold !== null) {
                $optimal = $threshold['optimal'];
                if ($value >= $optimal['min'] && ($optimal['max'] === null || $value <= $optimal['max'])) {
                    $status = 'optimal';
                } else {
                    $inAttentionRange = false;
                    foreach ($threshold['attention'] as $range) {
                        if ($value >= $range['min'] && $value <= $range['max']) {
                            $inAttentionRange = true;
                            break;
                        }
                    }
                    $status = $inAttentionRange ? 'attention' : 'required';
                }
            }

            $metrics[] = [
                'id' => $id,
                'field' => $field,
                'label' => $label,
                'value' => $value,
                'unit' => $unit,
                'status' => $status
            ];
        }

        return $metrics;
    }

    private function fetchSupportedDeviceStates(): array
    {
        $stmt = $this->db->query(
            "SELECT device_name, desired_state, actual_state, updated_at, reported_at
             FROM device_states
             WHERE device_name IN ('light', 'pump')
             ORDER BY device_name"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findLastSeen(?array $reading, array $deviceRows): ?string
    {
        $timestamps = [];
        if ($reading !== null) {
            $timestamps[] = $reading['timestamp'];
        }

        foreach ($deviceRows as $device) {
            if (!empty($device['reported_at'])) {
                $timestamps[] = $device['reported_at'];
            }
        }

        if (!$timestamps) {
            return null;
        }

        usort($timestamps, static function (string $left, string $right): int {
            return strtotime($right) <=> strtotime($left);
        });

        return $timestamps[0];
    }

    private function sampleIntervalSeconds(): ?int
    {
        $timestamps = $this->db->query(
            "SELECT timestamp
             FROM {$this->table}
             ORDER BY timestamp DESC, reading_id DESC
             LIMIT 12"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (count($timestamps) < 2) {
            return null;
        }

        $intervals = [];
        for ($index = 0; $index < count($timestamps) - 1; $index++) {
            $interval = strtotime($timestamps[$index]) - strtotime($timestamps[$index + 1]);
            if ($interval > 0) {
                $intervals[] = $interval;
            }
        }

        if (!$intervals) {
            return null;
        }

        sort($intervals);
        $middle = intdiv(count($intervals), 2);
        if (count($intervals) % 2 === 0) {
            return (int) round(($intervals[$middle - 1] + $intervals[$middle]) / 2);
        }

        return $intervals[$middle];
    }

    private function buildAlerts(
        ?array $reading,
        array $metrics,
        array $deviceRows,
        ?string $lastSeen,
        bool $online
    ): array {
        $alerts = [];
        if ($reading === null) {
            $alerts[] = [
                'id' => 'no-sensor-data',
                'message' => 'No sensor readings have been received yet.',
                'severity' => 'danger',
                'time_ago' => 'No data'
            ];
            return $alerts;
        }

        if (!$online) {
            $alerts[] = [
                'id' => 'sensor-offline',
                'message' => 'The ESP32 has not reported recently. Check its Wi-Fi and server connection.',
                'severity' => 'danger',
                'time_ago' => $this->timeAgo($lastSeen)
            ];
        }

        foreach ($metrics as $metric) {
            if ($metric['status'] !== 'attention' && $metric['status'] !== 'required') {
                continue;
            }

            $threshold = self::SENSOR_THRESHOLDS[$metric['field']];
            $alerts[] = [
                'id' => 'sensor-' . $metric['id'],
                'message' => sprintf(
                    '%s is %.2f%s and %s. %s',
                    $metric['label'],
                    $metric['value'],
                    $threshold['unit'],
                    $metric['status'] === 'required' ? 'outside the required range' : 'needs attention',
                    $threshold['description']
                ),
                'severity' => $metric['status'] === 'required' ? 'danger' : 'attention',
                'time_ago' => $this->timeAgo($reading['timestamp'])
            ];
        }

        foreach ($deviceRows as $device) {
            if (
                $device['actual_state'] !== 'UNKNOWN' &&
                $device['desired_state'] !== $device['actual_state']
            ) {
                $alerts[] = [
                    'id' => 'device-' . $device['device_name'],
                    'message' => ucfirst($device['device_name']) .
                        ' state does not match the requested state.',
                    'severity' => 'danger',
                    'time_ago' => $this->timeAgo($device['reported_at'] ?? $device['updated_at'])
                ];
            }
        }

        return $alerts;
    }

    private function timeAgo(?string $timestamp): string
    {
        if ($timestamp === null || strtotime($timestamp) === false) {
            return 'Unknown time';
        }

        $seconds = max(0, time() - strtotime($timestamp));
        if ($seconds < 60) {
            return 'Just now';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . ' min ago';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . ' hr ago';
        }
        return floor($seconds / 86400) . ' days ago';
    }

    private function validatedSensorStates($states, bool $includeMissing = true): array
    {
        if ($states === null && $includeMissing) {
            return array_fill_keys(self::SENSOR_IDS, 'Online');
        }
        if (!is_array($states)) {
            throw new InvalidArgumentException('sensor_states must be an object.');
        }

        $validated = [];
        foreach ($states as $sensorId => $status) {
            if (
                !in_array($sensorId, self::SENSOR_IDS, true) ||
                !in_array($status, ['Online', 'Not working'], true)
            ) {
                throw new InvalidArgumentException('Invalid sensor ID or status.');
            }
            $validated[$sensorId] = $status;
        }

        return $validated;
    }

    private function saveSensorStates(array $states): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO sensor_states (sensor_id, status, last_updated)
             VALUES (?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 last_updated = CURRENT_TIMESTAMP'
        );

        foreach ($states as $sensorId => $status) {
            $stmt->execute([$sensorId, $status]);
        }
    }

    private function ensureSensorStateRows(): void
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO sensor_states (sensor_id, status)
             VALUES (?, 'Not working')"
        );
        foreach (self::SENSOR_IDS as $sensorId) {
            $stmt->execute([$sensorId]);
        }
    }

    private function respond(array $data, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }
}
