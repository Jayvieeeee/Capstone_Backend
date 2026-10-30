CREATE TABLE IF NOT EXISTS sensor_automation (
    sensor_id VARCHAR(50) NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    paused_until TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO sensor_automation (sensor_id) VALUES
    ('temperature'),
    ('humidity'),
    ('soil_moisture'),
    ('soil_ph'),
    ('light_intensity');
