
<?php

require_once __DIR__ . '/../controllers/DeviceController.php';

function handleDeviceRoutes(
    string $method,
    string $uri,
    PDO $db
): bool {
    if ($uri === 'device-control' && $method === 'POST') {
        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (!is_array($input)) {
            deviceJsonResponse(400, [
                'success' => false,
                'message' => 'Invalid JSON body.'
            ]);
            return true;
        }

        setDeviceState($db, $input);
        return true;
    }

    if ($uri === 'device-status' && $method === 'GET') {
        getDeviceStates($db);
        return true;
    }

    if ($uri === 'device-report' && $method === 'POST') {
        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (!is_array($input)) {
            deviceJsonResponse(400, [
                'success' => false,
                'message' => 'Invalid JSON body.'
            ]);
            return true;
        }

        reportDeviceStates($db, $input);
        return true;
    }

    return false;
}
