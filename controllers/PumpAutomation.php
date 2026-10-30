<?php

class PumpAutomation
{
    private const START_BELOW = 40.0;
    private const STOP_AT = 40.0;
    private const MAX_RUN_SECONDS = 300;
    private const COOLDOWN_SECONDS = 600;
    private const LIGHT_ON_BELOW_LUX = 2000.0;
    private const LIGHT_OFF_AT_LUX = 4000.0;

    public static function ensureReady(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS pump_automation_state (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                timeout_lockout TINYINT(1) NOT NULL DEFAULT 0,
                pump_started_at TIMESTAMP NULL DEFAULT NULL,
                cooldown_until TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $column = $db->query(
            "SHOW COLUMNS FROM pump_automation_state LIKE 'cooldown_until'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            $db->exec(
                'ALTER TABLE pump_automation_state
                 ADD COLUMN cooldown_until TIMESTAMP NULL DEFAULT NULL'
            );
        }
        $db->exec(
            'INSERT IGNORE INTO pump_automation_state (id) VALUES (1)'
        );
    }

    public static function applyReading(PDO $db, float $soilMoisture, bool $sensorOnline): void
    {
        $state = $db->query(
            'SELECT timeout_lockout, pump_started_at, cooldown_until
             FROM pump_automation_state
             WHERE id = 1
             FOR UPDATE'
        )->fetch(PDO::FETCH_ASSOC);
        $desired = $db->query(
            "SELECT desired_state FROM device_states WHERE device_name = 'pump'"
        )->fetchColumn();

        if (!$state || $desired === false) {
            throw new RuntimeException('Pump automation state is not initialized.');
        }

        $lockout = (bool) $state['timeout_lockout'];
        $startedAt = $state['pump_started_at'];

        if (!$sensorOnline) {
            if ($desired === 'ON') {
                self::setPumpDesiredState($db, 'OFF');
            }
            self::setAutomationState($db, true, null);
            return;
        }

        if ($soilMoisture >= self::STOP_AT) {
            self::setPumpDesiredState($db, 'OFF');
            self::setAutomationState($db, false, null);
            return;
        }

        if ($lockout) {
            if ($state['cooldown_until'] === null) {
                if ($desired === 'ON') {
                    self::setPumpDesiredState($db, 'OFF');
                }
                return;
            }

            $cooldownRemaining = $db->query(
                'SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, cooldown_until)
                 FROM pump_automation_state
                 WHERE id = 1'
            )->fetchColumn();
            if ((int) $cooldownRemaining > 0) {
                if ($desired === 'ON') {
                    self::setPumpDesiredState($db, 'OFF');
                }
                return;
            }

            self::setAutomationState($db, false, null);
            $lockout = false;
            $startedAt = null;
            $desired = 'OFF';
        }

        if ($desired === 'ON') {
            if ($startedAt === null) {
                self::setAutomationState($db, $lockout, 'CURRENT_TIMESTAMP');
                return;
            }

            $elapsed = $db->query(
                'SELECT TIMESTAMPDIFF(SECOND, pump_started_at, CURRENT_TIMESTAMP)
                 FROM pump_automation_state
                 WHERE id = 1'
            )->fetchColumn();
            if ((int) $elapsed >= self::MAX_RUN_SECONDS) {
                self::setPumpDesiredState($db, 'OFF');
                self::setAutomationState($db, true, null, self::COOLDOWN_SECONDS);
            }
            return;
        }

        if ($soilMoisture < self::START_BELOW && !$lockout) {
            self::setPumpDesiredState($db, 'ON');
            self::setAutomationState($db, false, 'CURRENT_TIMESTAMP');
        }
    }

    public static function applyLightReading(PDO $db, float $lightIntensity, bool $sensorOnline): void
    {
        $desired = $db->query(
            "SELECT desired_state FROM device_states WHERE device_name = 'light' FOR UPDATE"
        )->fetchColumn();
        if ($desired === false) {
            throw new RuntimeException('Light device state is not initialized.');
        }

        if (!$sensorOnline) {
            if ($desired === 'ON') {
                self::setDeviceDesiredState($db, 'light', 'OFF');
            }
            return;
        }

        if ($lightIntensity < self::LIGHT_ON_BELOW_LUX) {
            self::setDeviceDesiredState($db, 'light', 'ON');
        } elseif ($lightIntensity >= self::LIGHT_OFF_AT_LUX) {
            self::setDeviceDesiredState($db, 'light', 'OFF');
        }
    }

    public static function recordManualCommand(PDO $db, string $action): void
    {
        if ($action === 'ON') {
            $db->exec(
                "UPDATE pump_automation_state
                 SET pump_started_at = CURRENT_TIMESTAMP
                 WHERE id = 1 AND timeout_lockout = 0"
            );
            return;
        }

        $db->exec(
            'UPDATE pump_automation_state
             SET pump_started_at = NULL
             WHERE id = 1'
        );
    }

    public static function isTimedOut(PDO $db): bool
    {
        return (bool) $db->query(
            'SELECT timeout_lockout
             FROM pump_automation_state
             WHERE id = 1'
        )->fetchColumn();
    }

    public static function enforceTimeout(PDO $db): void
    {
        $state = $db->query(
            'SELECT timeout_lockout, pump_started_at, cooldown_until
             FROM pump_automation_state
             WHERE id = 1
             FOR UPDATE'
        )->fetch(PDO::FETCH_ASSOC);
        $desired = $db->query(
            "SELECT desired_state FROM device_states WHERE device_name = 'pump' FOR UPDATE"
        )->fetchColumn();

        if (!$state || $desired === false || $desired !== 'ON') {
            return;
        }

        if ((bool) $state['timeout_lockout']) {
            self::setPumpDesiredState($db, 'OFF');
            if ($state['cooldown_until'] === null) {
                self::setAutomationState($db, true, null);
            }
            return;
        }

        if ($state['pump_started_at'] === null) {
            self::setAutomationState($db, (bool) $state['timeout_lockout'], 'CURRENT_TIMESTAMP');
            return;
        }

        $elapsed = $db->query(
            'SELECT TIMESTAMPDIFF(SECOND, pump_started_at, CURRENT_TIMESTAMP)
             FROM pump_automation_state
             WHERE id = 1'
        )->fetchColumn();
        if ((int) $elapsed >= self::MAX_RUN_SECONDS) {
            self::setPumpDesiredState($db, 'OFF');
            self::setAutomationState($db, true, null, self::COOLDOWN_SECONDS);
        }
    }

    private static function setPumpDesiredState(PDO $db, string $state): void
    {
        self::setDeviceDesiredState($db, 'pump', $state);
    }

    private static function setDeviceDesiredState(PDO $db, string $device, string $state): void
    {
        $stmt = $db->prepare(
            "UPDATE device_states
             SET desired_state = ?, updated_at = CURRENT_TIMESTAMP
             WHERE device_name = ?"
        );
        $stmt->execute([$state, $device]);
    }

    private static function setAutomationState(
        PDO $db,
        bool $lockout,
        ?string $startedAtExpression,
        ?int $cooldownSeconds = null
    ): void {
        $startedAt = $startedAtExpression === null
            ? 'NULL'
            : 'CURRENT_TIMESTAMP';
        $cooldownUntil = $cooldownSeconds === null
            ? 'NULL'
            : 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . self::COOLDOWN_SECONDS . ' SECOND)';
        $stmt = $db->prepare(
            "UPDATE pump_automation_state
             SET timeout_lockout = ?, pump_started_at = {$startedAt},
                 cooldown_until = {$cooldownUntil}
             WHERE id = 1"
        );
        $stmt->execute([$lockout ? 1 : 0]);
    }
}
