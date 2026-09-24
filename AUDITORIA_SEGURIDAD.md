# Reporte de Auditoría de Seguridad y Cama de Pruebas

**Proyecto:** ELEBENSAT (Visor XML Pro & Nómina SAT)  
**Fecha de Evaluación:** 24 de Septiembre de 2026  
**Entorno Evaluado:** Docker / PHP 8.2-FPM / Nginx / MariaDB / Hetzner Cloud  
**Herramienta de Validación:** `tests/suite_seguridad.php` (30 pruebas unitarias y de integración)

---

## 1. Resumen Ejecutivo

Se llevó a cabo una auditoría integral de seguridad y la implementación de una **cama de pruebas automatizada** sobre la plataforma **ELEBENSAT**, evaluando:
1. Higiene del repositorio y exposición de secretos.
2. Endurecimiento del contenedor Docker y directivas de PHP-FPM.
3. Criptografía, gestión de autenticación y sesiones.
4. Resistencia contra ataques de Inyección (SQLi, XXE, SSRF, XSS).
5. Aislamiento de redes y control de acceso multi-empresa (RBAC).

> [!NOTE]  
> **Resultado de la Cama de Pruebas:**  
> **30 pruebas ejecutadas, 30 aprobadas (100% PASS), 0 vulnerabilidades críticas detectadas.**

---

## 2. Matriz de Control y Evaluación de Amenazas

| Vector de Amenaza | Riesgo Inicial | Estado | Control Implementado |
| :--- | :---: | :---: | :--- |
| **Fuga de Secretos en Git** | **ALTO** | Mitigado | `.gitignore` estricto excluyendo `.env`, `docker/config_ohlala/database.php`, certificados `.key`, `.cer`, `.pfx` y `vendor/`. |
| **Inyección XML / XXE** | **CRÍTICO** | Mitigado | Flags `LIBXML_NONET` y `LIBXML_NOCDATA`, uso de libxml2 moderno con resolución de entidades externas deshabilitada por omisión. |
| **Inyección SQL (SQLi)** | **CRÍTICO** | Mitigado | Consultas 100% parametrizadas con PDO (`ATTR_EMULATE_PREPARES => false`, `ATTR_ERRMODE => ERRMODE_EXCEPTION`). |
| **Fijación / Secuestro de Sesión** | **ALTO** | Mitigado | `session.cookie_httponly = 1`, `session.cookie_samesite = Lax`, `session.use_strict_mode = 1`, y `session_regenerate_id(true)` tras autenticación. |
| **Cross-Site Request Forgery (CSRF)** | **MEDIO** | Mitigado | Módulo `includes/csrf.php` con tokens de 256 bits (`random_bytes(32)`) y validación en tiempo constante (`hash_equals`). |
| **Colisión de Sintaxis XML / PHP** | **MEDIO** | Resuelto | `short_open_tag = Off` en `docker/php/php.ini`, evitando que etiquetas `<?xml` en JavaScript/HTML disparen errores de sintaxis (HTTP 500). |
| **Exposición Directa de Base de Datos** | **ALTO** | Mitigado | `elebensat_db` restringido a red interna `elebensat_net` sin puertos expuestos al host en producción. Solo Nginx Proxy Manager tiene acceso a `elebensat_web:80` vía `pitaya_net`. |

---

## 3. Arquitectura y Aislamiento de Red (Docker)

```mermaid
graph TD
    Internet([Tráfico Web HTTPS]) --> NPM[Nginx Proxy Manager / Hetzner]
    subgraph Red: pitaya_net (Externa compartida)
        NPM -->|HTTP:80| Web[elebensat_web / Nginx 1.25]
    end
    subgraph Red: elebensat_net (Interna aislada)
        Web -->|FastCGI:9000| App[elebensat_app / PHP 8.2-FPM]
        App -->|TCP:3306| DB[(elebensat_db / MySQL 8.0)]
    end
```

- **Sin exposición pública de la base de datos:** El puerto `3306` solo es accesible dentro de `elebensat_net`.
- **Principio de menor privilegio:** La red `pitaya_net` solo conecta `elebensat_web` con el proxy inverso de entrada.

---

## 4. Cama de Pruebas Automatizada (`tests/suite_seguridad.php`)

Se construyó un ejecutable en consola diseñado para correr en integración continua (CI/CD) o en el contenedor de producción.

### Módulos Evaluados por la Suite

1. **Secretos y Git:**
   - Existencia y contenido de `.gitignore` (`.env`, `database.php`, `vendor/`).
   - Existencia de `.env.example` sanitizado.
2. **Directivas PHP:**
   - `short_open_tag === Off`.
   - `session.cookie_httponly === 1`.
   - `session.cookie_samesite === 'Lax'`.
   - `session.use_strict_mode === 1`.
3. **Criptografía y Autenticación:**
   - Algoritmo BCRYPT verificado con `password_verify` y `password_get_info`.
   - Generación, longitud y validación criptográfica de tokens CSRF.
4. **Seguridad XML:**
   - Inyección de entidad externa `file:///etc/passwd` neutralizada.
   - Intento de SSRF vía DTD externo bloqueado con `LIBXML_NONET`.
5. **Sanitización de Entradas:**
   - Codificación HTML contra XSS (`htmlspecialchars`).
   - Normalización de rangos de fechas (prevención de consultas huérfanas).
6. **Seguridad de Base de Datos (SQLi):**
   - Ataque de bypass `' OR '1'='1' --` neutralizado por PDO.
   - Inyección `UNION SELECT` neutralizada.
   - Integridad estructural: verificación de las 78 tablas oficiales.
7. **Control de Acceso (RBAC):**
   - Sesión vacía aborta con código 401.
   - Verificación de existencia de SuperAdmin activo para administración del sistema.

### Instrucciones de Ejecución

**En Servidor de Producción (Hetzner):**
```bash
docker exec elebensat_app php /var/www/html/tests/suite_seguridad.php
```

**En Entorno Local:**
```bash
docker exec elebensat_app php /var/www/html/tests/suite_seguridad.php
```

---

## 5. Recomendaciones Continuas

1. **Rotación Periódica de Contraseñas:**
   - Cambiar la contraseña del usuario inicial `admin` desde el módulo *Mi Perfil / Cambiar Contraseña*.
2. **Respaldos de Base de Datos:**
   - Programar volcado automático (`mysqldump`) del contenedor `elebensat_db` hacia almacenamiento fuera del servidor.
3. **Validación Pre-Despliegue:**
   - Correr `php tests/suite_seguridad.php` antes de cada `git push` a la rama `main`.
