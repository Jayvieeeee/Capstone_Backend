<?php

class SensorAutomation
{
    private const SENSOR_IDS = [
        'temperature',
        'humidity',
        'soil_moisture',
        'soil_ph',
        'light_intensity'
    ];

    public static function ensureReady(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS sensor_automation (
                sensor_id VARCHAR(50) NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                paused_until TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $insert = $db->prepare(
            'INSERT IGNORE INTO sensor_automation (sensor_id) VALUES (?)'
        );
        foreach (self::SENSOR_IDS as $sensorId) {
            $insert->execute([$sensorId]);
        }
    }

    public static function getAll(PDO $db): array
    {
        self::ensureReady($db);
        self::reactivateExpired($db);

        $rows = $db->query(
            'SELECT sensor_id, enabled, paused_until, updated_at,
                    CASE
                        WHEN paused_until IS NULL THEN NULL
                        ELSE UNIX_TIMESTAMP(paused_until)
                    END AS expires_at_epoch
             FROM sensor_automation
             ORDER BY sensor_id'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            return [
                'sensor_id' => $row['sensor_id'],
                'enabled' => (bool) $row['enabled'],
                'paused_until' => $row['paused_until'],
                'updated_at' => $row['updated_at'],
                'expires_at_epoch' => $row['expires_at_epoch'] === null
                    ? null
                    : (int) $row['expires_at_epoch']
            ];
        }, $rows);
    }

    public static function reactivateExpired(PDO $db): void
    {
        $db->exec(
            "UPDATE sensor_automation
             SET enabled = 1, paused_until = NULL
             WHERE enabled = 0 AND paused_until <= CURRENT_TIMESTAMP"
        );
    }

    public static function set(PDO $db, string $sensorId, bool $enabled): array
    {
        if (!in_array($sensorId, self::SENSOR_IDS, true)) {
            throw new InvalidArgumentException('Unknown sensor automation ID.');
        }

        self::ensureReady($db);
        $stmt = $db->prepare(
            'UPDATE sensor_automation
             SET enabled = ?,
                 paused_until = ' . ($enabled
                     ? 'NULL'
                     : 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)') . '
             WHERE sensor_id = ?'
        );
        $stmt->execute([$enabled ? 1 : 0, $sensorId]);

        foreach (self::getAll($db) as $state) {
            if ($state['sensor_id'] === $sensorId) {
                return $state;
            }
        }

        throw new RuntimeException('Unable to retrieve updated automation state.');
    }

    public static function isEnabled(PDO $db, string $sensorId): bool
    {
        if (!in_array($sensorId, self::SENSOR_IDS, true)) {
            throw new InvalidArgumentException('Unknown sensor automation ID.');
        }

        $expire = $db->prepare(
            "UPDATE sensor_automation
             SET enabled = 1, paused_until = NULL
             WHERE sensor_id = ? AND enabled = 0
               AND paused_until <= CURRENT_TIMESTAMP"
        );
        $expire->execute([$sensorId]);

        $stmt = $db->prepare(
            'SELECT enabled FROM sensor_automation WHERE sensor_id = ?'
        );
        $stmt->execute([$sensorId]);
        $enabled = $stmt->fetchColumn();
        if ($enabled === false) {
            throw new RuntimeException('Sensor automation state is missing.');
        }

        return (bool) $enabled;
    }
}
