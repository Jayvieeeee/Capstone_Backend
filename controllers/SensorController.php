
<?php

class SensorController
{
    private $db;

    // Change this if your actual MySQL table has a different name.
    private $table = 'sensor_readings';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function store()
    {
        header('Content-Type: application/json; charset=UTF-8');

        // Read the JSON sent by the ESP32.
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

        // Check that every reading exists and is numeric.
        foreach ($fields as $field) {
            if (
                !array_key_exists($field, $data) ||
                !is_numeric($data[$field])
            ) {
                $this->respond([
                    'success' => false,
                    'message' => "Missing or invalid field: $field"
                ], 400);
            }
        }

        // Validate values before saving them.
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

            $this->respond([
                'success' => true,
                'message' => 'Sensor readings saved successfully.',
                'reading_id' => $this->db->lastInsertId()
            ], 201);

        } catch (PDOException $e) {
            error_log('Sensor insert failed: ' . $e->getMessage());

            $this->respond([
                'success' => false,
                'message' => 'Failed to save sensor readings.'
            ], 500);
        }
    }

    private function respond($data, $statusCode)
    {
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
