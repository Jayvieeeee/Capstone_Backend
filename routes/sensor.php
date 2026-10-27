
<?php

require_once __DIR__ . '/../controllers/SensorController.php';

function handleSensorRoutes($method, $uri, $db)
{
    if ($uri === 'sensor' && $method === 'POST') {
        $controller = new SensorController($db);
        $controller->store();
    }
}
