# Control de Asistencia — Diseño

**Fecha:** 2026-06-15
**Estado:** aprobado en brainstorming, pendiente de plan de implementación

## Objetivo
Módulo de control de asistencia para el personal de El Gringo, con marcaje **anti-trampa Nivel 1** (selfie + GPS con geocerca + hora del servidor + pantalla de revisión), preparado para escalar a reconocimiento facial/liveness sin rehacer. Funciona en locales fijos (tablet compartida) y en food truck/delivery (celular propio).

## Contexto actual
- `users` (login del panel: name, email, password, role, active) + sistema de permisos `can()`/`requirePermission()`.
- `ubicaciones` tiene `direccion` y `maps_url` pero **no lat/lng**.
- No existe nada de asistencia.
- Patrón de foto con autoborrado ya existe en `gastos` (foto se borra a los 2 meses) — se reutiliza el mismo enfoque.

## Decisiones (tomadas en brainstorming)
- **Nivel de seguridad:** Nivel 1 — selfie + GPS + hora de servidor + revisión manual. Diseñado para enchufar facial/liveness después.
- **Dispositivo:** por local (`modo_marcaje`): `tablet` (compartida, local fijo) o `celular` (propio, truck/delivery).
- **Quién marca:** padrón propio de **empleados** (incluye gente sin login), con **vínculo opcional** a `users` para quien además tenga cuenta (no se duplica a nadie).
- **Fuera de geocerca:** permitir la marca pero **marcarla en rojo** (no bloquear; el GPS impreciso no debe dejar a nadie sin marcar).
- **PIN:** 4 dígitos por empleado, opcional, asignado por el admin, guardado con hash. Capa suave (el selfie es el anti-trampa real).
- **Autoborrado de fotos:** 2 meses (como `gastos`).
- **Marca:** solo **entrada/salida** en v1 (auto-detecta cuál según la última marca abierta). Refrigerios, reglas de tardanza y planilla → futuro.
- **Permiso nuevo:** `asistencia`.

## Modelo de datos

### Tabla `empleados` (padrón)
`id`, `nombre`, `foto_referencia` (ruta relativa, para futuro face-match), `ubicacion_id` (local asignado), `user_id` (NULL; vínculo opcional a `users`), `pin_hash` (NULL = sin PIN), `cargo` (opcional), `activo` (bool), `created_at`.

### Tabla `asistencia_marcas` (ledger)
`id`, `empleado_id`, `ubicacion_id`, `tipo ENUM('entrada','salida')`, `foto` (ruta relativa, autoborra a 2 meses), `lat`, `lng`, `distancia_m` (a la geocerca), `dentro_geocerca` (bool), `fuente ENUM('tablet','celular')`, `verificacion VARCHAR` (reservado para facial/liveness futuro; v1 vacío), `marcada_at DATETIME` (hora del **servidor**, `NOW()`).

### Cambios en `ubicaciones` (migración)
`lat DECIMAL(10,7) NULL`, `lng DECIMAL(10,7) NULL`, `geocerca_radio INT DEFAULT 100` (metros), `geocerca_activa TINYINT DEFAULT 0`, `modo_marcaje ENUM('tablet','celular') DEFAULT 'tablet'`, `asistencia_token VARCHAR(40)` (para la URL de marcaje; se genera una vez).

## Componentes

### 1. Página pública de marcaje (`asistencia/marcar.php?u=<slug>&t=<token>`)
- Validada por `asistencia_token` del local (no abierta a cualquiera). Sin login.
- Muestra el **padrón de empleados activos de ese local** (foto de referencia + nombre, grilla).
- Flujo: tocar su nombre → si tiene PIN, pedirlo → **selfie** (cámara, `getUserMedia`) → JS captura **GPS** (`navigator.geolocation`) → enviar a la API.
- Tablet vs celular: el mismo flujo; en `celular` el GPS es del teléfono del trabajador (geocerca aplica si activa), en `tablet` el GPS es el del equipo fijo.
- Si el GPS es denegado/no disponible: se permite marcar igual, se registra sin coordenadas y `dentro_geocerca=0` (rojo).

### 2. API de marcaje (`api/asistencia.php`)
- `requireLogin()` NO aplica (es público con token). Valida `asistencia_token` del local.
- Acción `marcar`: recibe `empleado_id`, `pin` (si aplica), `foto` (base64 → guardar como archivo), `lat`, `lng`, `fuente`.
- Verifica: empleado pertenece al local y está activo; PIN correcto si tiene; calcula `distancia_m` al centro de la geocerca (fórmula haversine) y `dentro_geocerca` (si `geocerca_activa`); determina `tipo` (si la última marca del empleado hoy es `entrada` sin `salida` → esta es `salida`; si no → `entrada`); guarda con `marcada_at = NOW()`.
- Honeypot/anti-spam básico (campo oculto), como los forms públicos.

### 3. Admin `admin/asistencia/` (permiso `asistencia`)
- **Empleados** (`index` + `form`): alta/edición — nombre, foto de referencia (subida), local, vínculo opcional a usuario, PIN (4 dígitos, opcional), cargo, activo.
- **Marcajes** (`index`): tabla del día/periodo con **foto (thumbnail)**, hora, empleado, local, entrada/salida, y **bandera roja** si `dentro_geocerca=0`. Filtros por empleado/local/fecha. Click en foto → ver grande.
- **Reporte de horas:** por empleado y periodo, suma de pares entrada→salida.
- Autoborrado de fotos >2 meses al entrar al módulo (como `gastos`).

### 4. Config de geocerca (en `admin/locations/form.php`)
- Campos `lat`, `lng`, `geocerca_radio` (default 100), checkbox `geocerca_activa`, selector `modo_marcaje`.
- Botón **"📍 Capturar mi ubicación actual"** (usa `navigator.geolocation` para rellenar lat/lng — el admin se para en el local).
- Hint: pegar coordenadas desde Google Maps; intentar prefijar desde `maps_url` si contiene coords.
- Sin APIs de pago.

## Anti-trampa (Nivel 1) — resumen
1. **Selfie obligatorio** guardado con cada marca → revisión manual cacha al "vivo".
2. **GPS + geocerca** por local (haversine vs centro/radio). Fuera del radio → permitir + **rojo**. Food truck → `geocerca_activa=0` (registra GPS, no marca rojo).
3. **Hora del servidor** (`NOW()`), nunca la del cliente.
4. **PIN** opcional como capa suave (evita marcar al nombre equivocado en tablet).
5. **Preparado para escalar:** se guarda `foto_referencia` por empleado y `verificacion` por marca → un job futuro de reconocimiento facial/liveness se enchufa sin rehacer el modelo.

## Privacidad (Ley de Protección de Datos – Perú)
- Aviso de consentimiento visible en la página de marcaje (se toma y guarda una foto para control de asistencia).
- Fotos con **autoborrado a 2 meses**.

## Qué NO incluye (v1, para no inflar)
Refrigerios/breaks, reglas de tardanza/horario, cálculo de planilla, reconocimiento facial automático, liveness. Todo queda como mejora futura (el modelo lo soporta).

## Permisos / integración
- Nueva clave de permiso `asistencia` (grupo a definir en el sidebar; probablemente junto a operación o un grupo "Personal").
- La página de marcaje es **pública con token** (como `solicitud.php`/`reserva.php`): **sin** `verifyCsrf` (honeypot), porque puede correr en kiosco/tablet sin sesión.

## Pruebas (manual, no hay framework de tests)
- Marcar entrada y salida (auto-detección del tipo) en modo tablet y celular.
- Marca fuera de geocerca → aparece en rojo; dentro → normal; food truck (geocerca off) → nunca rojo por ubicación.
- PIN correcto/incorrecto.
- Empleado con y sin vínculo a usuario.
- Reporte de horas suma correctamente pares entrada/salida.
- Foto se guarda; verificar autoborrado >2 meses.
- Hora siempre del servidor (cambiar hora del dispositivo no afecta).
