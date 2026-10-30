
<?php

require_once __DIR__ . '/../controllers/SensorController.php';

function handleSensorRoutes($method, $uri, $db)
{
    $controller = new SensorController($db);

    if ($method === 'POST' && $uri === 'sensor') {
        $controller->store();
        return;
    }

    if ($method === 'GET' && $uri === 'sensor/states') {
        $controller->states();
        return;
    }

    if ($method === 'POST' && $uri === 'sensor/states') {
        $controller->reportStates();
        return;
    }

    if ($method === 'GET' && $uri === 'sensor/automation') {
        $controller->automation();
        return;
    }

    if ($method === 'POST' && $uri === 'sensor/automation') {
        $controller->updateAutomation();
        return;
    }

    if ($method === 'GET' && $uri === 'sensor/dashboard') {
        $controller->dashboard();
        return;
    }

    if ($method === 'GET' && $uri === 'sensor/history') {
        $controller->history();
        return;
    }

    if ($method === 'GET' && $uri === 'sensor/trends') {
        $controller->trends();
    }
}
