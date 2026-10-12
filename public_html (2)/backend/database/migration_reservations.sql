USE hotel_satisfaccion_demo;

CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_number VARCHAR(20) NOT NULL UNIQUE,
  room_type VARCHAR(80) NOT NULL,
  capacity INT NOT NULL DEFAULT 2,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('disponible', 'ocupada', 'mantenimiento') NOT NULL DEFAULT 'disponible',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_room_status (status),
  INDEX idx_room_type (room_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  whatsapp VARCHAR(30) NOT NULL,
  email VARCHAR(120) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_guest_phone (whatsapp),
  INDEX idx_guest_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_code VARCHAR(30) NOT NULL UNIQUE,
  guest_id INT NOT NULL,
  room_id INT NOT NULL,
  check_in DATE NOT NULL,
  check_out DATE NOT NULL,
  status ENUM('pendiente', 'confirmada', 'cancelada', 'finalizada') NOT NULL DEFAULT 'confirmada',
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservation_guest
    FOREIGN KEY (guest_id) REFERENCES guests(id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_reservation_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON DELETE RESTRICT,
  INDEX idx_reservation_code (reservation_code),
  INDEX idx_reservation_dates (check_in, check_out),
  INDEX idx_reservation_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rooms (room_number, room_type, capacity, price, status) VALUES
('101', 'Sencilla', 2, 1200.00, 'disponible'),
('102', 'Sencilla', 2, 1200.00, 'disponible'),
('201', 'Doble', 4, 1800.00, 'disponible'),
('202', 'Doble', 4, 1800.00, 'disponible'),
('301', 'Suite', 2, 2800.00, 'disponible')
ON DUPLICATE KEY UPDATE
  room_type = VALUES(room_type),
  capacity = VALUES(capacity),
  price = VALUES(price);

-- Si tu tabla survey_responses ya existía antes, ejecuta este ALTER una sola vez.
-- Si marca error porque la columna ya existe, no pasa nada; significa que ya fue aplicada.
ALTER TABLE survey_responses
  ADD COLUMN reservation_id INT DEFAULT NULL AFTER id;

ALTER TABLE survey_responses
  ADD INDEX idx_survey_reservation (reservation_id);

ALTER TABLE survey_responses
  ADD CONSTRAINT fk_survey_reservation
  FOREIGN KEY (reservation_id) REFERENCES reservations(id)
  ON DELETE SET NULL;
