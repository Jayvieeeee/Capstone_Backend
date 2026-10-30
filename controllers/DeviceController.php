
<?php

require_once __DIR__ . '/PumpAutomation.php';

function deviceJsonResponse(
    int $statusCode,
    array $data
): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');

    echo json_encode($data);
}

function setDeviceState(PDO $db, array $input): void
{
    $device = strtolower(trim($input['device'] ?? ''));
    $action = strtoupper(trim($input['action'] ?? ''));

    $allowedDevices = ['light', 'pump'];
    $allowedActions = ['ON', 'OFF'];

    if (!in_array($device, $allowedDevices, true)) {
        deviceJsonResponse(400, [
            'success' => false,
            'message' => 'Invalid device.'
        ]);
        return;
    }

    if (!in_array($action, $allowedActions, true)) {
        deviceJsonResponse(400, [
            'success' => false,
            'message' => 'Action must be ON or OFF.'
        ]);
        return;
    }

    try {
        if ($device === 'pump') {
            PumpAutomation::ensureReady($db);
            if ($action === 'ON' && PumpAutomation::isTimedOut($db)) {
                deviceJsonResponse(409, [
                    'success' => false,
                    'message' => 'The pump timed out and is locked off until soil moisture reaches the optimal range (40%).'
                ]);
                return;
            }
        }

        $sql = "UPDATE device_states
                SET desired_state = ?
                WHERE device_name = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$action, $device]);

        if ($stmt->rowCount() === 0) {
            $check = $db->prepare(
                "SELECT device_name
                 FROM device_states
                 WHERE device_name = ?"
            );
            $check->execute([$device]);
            if (!$check->fetch(PDO::FETCH_ASSOC)) {
                deviceJsonResponse(404, [
                    'success' => false,
                    'message' => 'Device not found.'
                ]);
                return;
            }
        }

        if ($device === 'pump') {
            PumpAutomation::recordManualCommand($db, $action);
        }
    } catch (PDOException $e) {
        error_log('Device command update failed: ' . $e->getMessage());
        deviceJsonResponse(500, [
            'success' => false,
            'message' => 'Unable to save the device command.'
        ]);
        return;
    }

    deviceJsonResponse(200, [
        'success' => true,
        'message' => 'Device command saved.',
        'device' => $device,
        'desired_state' => $action
    ]);
}

function getDeviceStates(PDO $db): void
{
    try {
        PumpAutomation::ensureReady($db);
        $db->beginTransaction();
        PumpAutomation::enforceTimeout($db);
        $stmt = $db->query(
            "SELECT device_name, desired_state, actual_state,
                    updated_at, reported_at
             FROM device_states
             WHERE device_name IN ('light', 'pump')
             ORDER BY device_name"
        );
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $db->commit();

        deviceJsonResponse(200, [
            'success' => true,
            'devices' => $devices
        ]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Device status query failed: ' . $e->getMessage());
        deviceJsonResponse(500, [
            'success' => false,
            'message' => 'Unable to load device states.'
        ]);
    }
}

function reportDeviceStates(PDO $db, array $input): void
{
    $states = $input['devices'] ?? null;
    $allowedDevices = ['light', 'pump'];
    $allowedStates = ['ON', 'OFF'];

    if (!is_array($states)) {
        deviceJsonResponse(400, [
            'success' => false,
            'message' => 'The devices object is required.'
        ]);
        return;
    }

    $stmt = $db->prepare(
        "UPDATE device_states
         SET actual_state = ?, reported_at = CURRENT_TIMESTAMP
         WHERE device_name = ?"
    );

    foreach ($states as $device => $state) {
        $device = strtolower((string) $device);
        $state = strtoupper((string) $state);

        if (
            !in_array($device, $allowedDevices, true) ||
            !in_array($state, $allowedStates, true)
        ) {
            deviceJsonResponse(400, [
                'success' => false,
                'message' => 'Invalid device state report.'
            ]);
            return;
        }

        $stmt->execute([$state, $device]);
    }

    deviceJsonResponse(200, [
        'success' => true,
        'message' => 'Device states reported successfully.'
    ]);
}
