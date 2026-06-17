# Planes por suscripción + gating de módulos — Design

**Fecha:** 2026-06-17 · **Estado:** spec (sin implementar) · **Relacionado:** SaaS MiPOS Pro (pausado), objetivo multi-empresa

## Objetivo

Vender la plataforma en **3 planes** (`basico`, `pro`, `catering`) gateando qué módulos ve cada instancia. Una sola palanca de configuración por empresa, apoyada en el sistema de permisos que ya existe. **El Gringo (instancia actual) queda en `catering` por defecto → no pierde nada.**

## Los 3 planes

| Plan | "Mundo" | A quién |
|---|---|---|
| `basico` | Vender + facturar (1 tienda) | Local/dark kitchen que solo cobra y emite SUNAT |
| `pro` | Restaurante completo (multi-local, cocina, stock) | Marca con varios locales |
| `catering` | Pro + eventos B2B + foodtrucks | Quien además vende catering/eventos y opera trucks |

Los planes son **acumulativos**: `pro` incluye todo `basico`; `catering` incluye todo `pro`.

## Mapa módulo → plan

El gating se hace sobre las **claves de permiso** que ya existen (29). Cada clave pertenece al plan mínimo que la desbloquea. Claves "siempre activas" (no dependen del plan): núcleo operativo + administración.

```php
// includes/permissions.php — nuevo

// Claves disponibles en todos los planes (núcleo: vender + facturar + administrar la cuenta)
const PLAN_CORE = [
    'dashboard', 'products', 'categories', 'modifiers',
    'pedidos', 'pos_terminal', 'pos_metodos', 'pos_caja', 'pos_clientes',
    // settings/users/facturacion se gatean por isAdmin(), no por plan
];

// Lo que SUMA cada plan sobre el anterior
const PLAN_ADDS = [
    'basico'   => [],  // = PLAN_CORE
    'pro'      => [
        'locations', 'packages', 'clients',          // multi-local + catálogo avanzado
        'kds', 'pos_monitor',                         // cocina + monitor en vivo
        'inv_insumos', 'inv_stock', 'inv_recetas',
        'inv_movimientos', 'inv_compras',             // inventario (sin salida a evento)
        'reservas', 'gastos', 'analytics',
        'landing', 'qr',
    ],
    'catering' => [
        'quotes', 'events', 'calendar', 'requests',   // cotizaciones / eventos B2B
        'inv_evento',                                  // salida masiva a eventos / foodtruck
        'cartas_pdf',                                  // banners a medida
    ],
];

const PLAN_ORDER = ['basico', 'pro', 'catering'];
```

> **Carta online:** es pública (sin permiso). En `basico` se limita a **1 tienda** (la principal); el selector multi-local y `location_products` se habilitan con `pro` (gate sobre `can('locations')`).

## Cómo se aplica (una sola palanca)

### 1. El plan vive en `company_settings`

```php
function getPlan(): string {
    $p = getSetting('plan', 'catering');           // default catering → El Gringo intacto
    return in_array($p, PLAN_ORDER, true) ? $p : 'catering';
}

// Conjunto acumulado de claves que el plan permite
function planKeys(): array {
    $keys = PLAN_CORE;
    foreach (PLAN_ORDER as $p) {
        $keys = array_merge($keys, PLAN_ADDS[$p]);
        if ($p === getPlan()) break;               // acumula hasta el plan contratado
    }
    return $keys;
}

function planAllows(string $key): bool {
    return in_array($key, planKeys(), true);
}
```

### 2. `can()` respeta el plan (incluido el admin)

El plan acota a **toda** la empresa, también al admin: un admin en `basico` no debe ver cotizaciones. Las claves de administración (`settings`/`users`/`facturacion`) siguen por `isAdmin()` y NO se gatean por plan.

```php
function can(string $key): bool {
    if (!planAllows($key)) return false;           // ← nuevo: el plan manda primero
    if (isAdmin()) return true;
    return in_array($key, $_SESSION['user_permissions'] ?? [], true);
}
```

Como `requirePermission()`, el sidebar (`layout-top.php`) y cada API ya usan `can()`, **el gating se propaga solo**: los grupos del menú se ocultan, las páginas redirigen, las APIs cortan. No hay que tocar cada pantalla.

### 3. Carta multi-local

En `carta/selector.php` y la lógica de tienda: si `!can('locations')` (plan `basico`), saltar el selector e ir directo a la tienda `es_principal`. El alta de tiendas en `admin/locations/` ya queda gateada por `can('locations')`.

### 4. Aviso de upsell (opcional, recomendado)

Cuando un usuario llega por URL a un módulo fuera de su plan, en vez de solo redirigir, mostrar una pantalla "Este módulo está en el plan **Pro/Catering** — Mejora tu plan". Helper:

```php
function requirePermission(string $key): void {
    if (can($key)) return;
    if (!planAllows($key)) { renderUpsell($key); exit; }   // módulo existe pero plan no lo incluye
    redirect(firstAllowedPath());                          // sí está en el plan pero el usuario no tiene permiso
}
```

## Add-ons (sin crear un 4º plan)

Flags sueltos en `company_settings`, leídos junto al plan:

- `addon_facturacion` → habilita SUNAT/NubeFact sobre `basico` (si se vende como complemento).
- `addon_usuarios_extra` → sube el cupo de usuarios.

`planAllows()` puede aceptar también la clave si el add-on correspondiente está activo.

## Migración

`install/55_plan.sql`:

```sql
-- plan de suscripción por instancia (company_settings es key/value)
INSERT INTO company_settings (`key`, `value`)
VALUES ('plan', 'catering')
ON DUPLICATE KEY UPDATE `value` = `value`;   -- no pisa si ya existe
```

(Si `company_settings` no es key/value sino columnas, agregar `ALTER TABLE company_settings ADD COLUMN plan VARCHAR(20) NOT NULL DEFAULT 'catering';` — verificar el esquema real antes.)

## Cupos por plan (límites además de módulos)

| Límite | basico | pro | catering |
|---|---|---|---|
| Tiendas | 1 | hasta N | hasta N |
| Usuarios | 2 | 8 | ilimitado |
| Almacén central | — | ✅ | ✅ |

Los cupos se chequean al **crear** (en `admin/locations/`, `admin/users/`): `if (count(tiendas) >= planMaxTiendas()) → bloquear + upsell`. Helpers `planMaxTiendas()`, `planMaxUsuarios()` con un mapa por plan.

## Riesgos / cuidados

- **No romper El Gringo:** default `catering`; la migración no pisa el valor si ya existe.
- **Doble verificación:** el plan debe chequearse **en servidor** (en `can()`), nunca solo ocultando en el menú — un usuario podría escribir la URL.
- **Facturación:** `facturacion` se gatea por `isAdmin()`, no por plan; si se vende como add-on del básico, mover ese gate a `planAllows('facturacion') || addon`.
- **Carta pública:** el límite de 1 tienda del `basico` se aplica en la resolución de slug, no solo en el admin.

## Resumen de archivos a tocar

- `includes/permissions.php` — `PLAN_CORE`/`PLAN_ADDS`/`getPlan`/`planKeys`/`planAllows`; ajustar `can()` y `requirePermission()`.
- `admin/settings/index.php` — (solo super-admin / panel SaaS) selector de plan + add-ons.
- `carta/selector.php` + resolución de tienda — gate `can('locations')`.
- `admin/locations/`, `admin/users/` — cupos.
- `install/55_plan.sql` + `install/check_migraciones.sql`.
- Nuevo `renderUpsell()` (vista simple de "mejora tu plan").
