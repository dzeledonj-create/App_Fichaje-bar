-- ============================================================
--  BAR FICHAJE - Esquema PostgreSQL
--  Version: 1.0
-- ============================================================

-- Extensión para UUIDs (opcional, usamos SERIAL por defecto)
-- CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ============================================================
-- TABLA: usuarios
-- ============================================================
CREATE TABLE IF NOT EXISTS usuarios (
    id           SERIAL PRIMARY KEY,
    nombre       VARCHAR(100)  NOT NULL,
    apellidos    VARCHAR(150)  NOT NULL,
    dni_nie      VARCHAR(20)   NOT NULL UNIQUE,
    email        VARCHAR(255)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol          VARCHAR(20)   NOT NULL DEFAULT 'empleado'
                     CHECK (rol IN ('empleado', 'admin')),
    activo       BOOLEAN       NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at   TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Índices de búsqueda frecuente
CREATE INDEX IF NOT EXISTS idx_usuarios_email   ON usuarios(email);
CREATE INDEX IF NOT EXISTS idx_usuarios_dni_nie ON usuarios(dni_nie);
CREATE INDEX IF NOT EXISTS idx_usuarios_rol     ON usuarios(rol);

-- ============================================================
-- TABLA: registros_horario
-- Un registro = un turno completo (entrada + salida)
-- La salida se actualiza en el mismo registro cuando el empleado ficha la salida
-- ============================================================
CREATE TABLE IF NOT EXISTS registros_horario (
    id                      SERIAL PRIMARY KEY,
    usuario_id              INTEGER NOT NULL
                                REFERENCES usuarios(id) ON DELETE CASCADE,

    -- Datos de ENTRADA
    hora_entrada            TIMESTAMP WITH TIME ZONE NOT NULL,
    firma_entrada_nombre    VARCHAR(100) NOT NULL,
    firma_entrada_apellidos VARCHAR(150) NOT NULL,
    firma_entrada_dni       VARCHAR(20)  NOT NULL,

    -- Datos de SALIDA (NULL hasta que el empleado fiche salida)
    hora_salida             TIMESTAMP WITH TIME ZONE,
    firma_salida_nombre     VARCHAR(100),
    firma_salida_apellidos  VARCHAR(150),
    firma_salida_dni        VARCHAR(20),

    -- Columna calculada: fecha del turno (para filtros por mes)
    fecha                   DATE GENERATED ALWAYS AS (hora_entrada::DATE) STORED,

    -- Minutos trabajados (calculado al cerrar turno)
    minutos_trabajados      INTEGER,

    created_at              TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at              TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Índices
CREATE INDEX IF NOT EXISTS idx_registros_usuario_id  ON registros_horario(usuario_id);
CREATE INDEX IF NOT EXISTS idx_registros_fecha        ON registros_horario(fecha);
CREATE INDEX IF NOT EXISTS idx_registros_hora_entrada ON registros_horario(hora_entrada);
-- Para detectar turno abierto (sin salida)
CREATE INDEX IF NOT EXISTS idx_registros_turno_abierto
    ON registros_horario(usuario_id, hora_salida)
    WHERE hora_salida IS NULL;

-- ============================================================
-- TABLA: sesiones (gestión de tokens de sesión)
-- ============================================================
CREATE TABLE IF NOT EXISTS sesiones (
    id          SERIAL PRIMARY KEY,
    usuario_id  INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    token       VARCHAR(128) NOT NULL UNIQUE,
    ip          VARCHAR(45),
    user_agent  TEXT,
    expires_at  TIMESTAMP WITH TIME ZONE NOT NULL,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sesiones_token     ON sesiones(token);
CREATE INDEX IF NOT EXISTS idx_sesiones_usuario   ON sesiones(usuario_id);
CREATE INDEX IF NOT EXISTS idx_sesiones_expires   ON sesiones(expires_at);

-- ============================================================
-- FUNCIÓN: actualizar updated_at automáticamente
-- ============================================================
CREATE OR REPLACE FUNCTION fn_update_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_usuarios_updated_at
    BEFORE UPDATE ON usuarios
    FOR EACH ROW EXECUTE FUNCTION fn_update_updated_at();

CREATE TRIGGER trg_registros_updated_at
    BEFORE UPDATE ON registros_horario
    FOR EACH ROW EXECUTE FUNCTION fn_update_updated_at();

-- ============================================================
-- FUNCIÓN: calcular minutos_trabajados al cerrar turno
-- ============================================================
CREATE OR REPLACE FUNCTION fn_calcular_minutos()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.hora_salida IS NOT NULL AND OLD.hora_salida IS NULL THEN
        NEW.minutos_trabajados =
            EXTRACT(EPOCH FROM (NEW.hora_salida - NEW.hora_entrada))::INTEGER / 60;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_calcular_minutos
    BEFORE UPDATE ON registros_horario
    FOR EACH ROW EXECUTE FUNCTION fn_calcular_minutos();

-- ============================================================
-- DATOS INICIALES: Usuario administrador por defecto
-- Contraseña: password (cambiar en producción)
-- Hash bcrypt de 'password'
-- ============================================================
INSERT INTO usuarios (nombre, apellidos, dni_nie, email, password_hash, rol)
VALUES (
    'Administrador',
    'Principal',
    '00000000A',
    'admin@bar.com',
    '$2y$10$0f6MB2nQn6QIPrmpfJQx4O.vxJR5AiyJN4d.qOI8iLkywAim6/Utu',
    'admin'
) ON CONFLICT (email) DO NOTHING;

-- ============================================================
-- VISTA útil para el admin: registros con datos de usuario
-- ============================================================
CREATE OR REPLACE VIEW v_registros_completos AS
SELECT
    rh.id,
    u.id                        AS usuario_id,
    u.nombre                    AS usuario_nombre,
    u.apellidos                 AS usuario_apellidos,
    u.dni_nie                   AS usuario_dni,
    u.email                     AS usuario_email,
    rh.fecha,
    rh.hora_entrada,
    rh.firma_entrada_nombre,
    rh.firma_entrada_apellidos,
    rh.firma_entrada_dni,
    rh.hora_salida,
    rh.firma_salida_nombre,
    rh.firma_salida_apellidos,
    rh.firma_salida_dni,
    rh.minutos_trabajados,
    CASE
        WHEN rh.hora_salida IS NULL THEN 'abierto'
        ELSE 'cerrado'
    END                         AS estado_turno,
    rh.created_at
FROM registros_horario rh
JOIN usuarios u ON u.id = rh.usuario_id
ORDER BY rh.hora_entrada DESC;