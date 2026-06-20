# Mesas POS — Sub-build E1: Agrupar mesas (juntar mesa libre + separar)

**Fecha:** 2026-06-20
**Módulo:** POS de restaurante — app del mozo (agrupar mesas)
**Estado:** diseño aprobado, pendiente de plan

## Contexto y decomposición

Una cuenta hoy vive en **una** mesa (`cuentas.mesa_id`). Cuando llega un grupo grande, el mozo junta dos (o más) mesas físicas y quiere **una sola cuenta** que las ocupe. E1 agrega esa base + juntar/separar. La **fusión de dos cuentas ya abiertas** y **transferir a mesa libre** quedan para **Sub-build E2** (reusan la misma base, mueven comandas/mesas, menos frecuentes).

Decisión de faseo: la base multi-mesa toca el núcleo que pinta/rutea las mesas (el mismo de "pintadas pero vacías"); se mete acotada y bien probada antes de sumar el merge.

## Decisiones tomadas (brainstorming)

1. **Modelo mínimo y retrocompatible:** la mesa **principal** sigue en `cuentas.mesa_id`; una tabla nueva `cuenta_mesas` guarda **solo las mesas secundarias** (las juntadas). Cuenta de una mesa = sin filas en `cuenta_mesas` → idéntica a hoy, **sin backfill**.
2. **Juntar mesa libre:** desde una cuenta abierta, sumar una mesa **libre** al grupo (caso grupo grande). Fusionar dos cuentas abiertas = E2.
3. **Separar mesa:** quitar una mesa **secundaria** del grupo → vuelve a libre. La principal no se separa.
4. **Un grupo = una cuenta = un total.** Al cobrar se puede dividir igual (split de Sub-build C).
5. **Antes de cobrar:** juntar/separar solo si la cuenta no tiene pagos. Geocerca dura (son escrituras). Cualquier mozo del local.
6. **Visual:** las mesas del grupo se pintan ocupadas (mismo estado); la **ficha** (al tocar) muestra "Mesa 5 + 6". No se cambia `plano-render.js` (hoy dibuja solo el número por mesa); el agrupamiento se comunica en la ficha. Mejora visual de "link" entre mesas = futuro.
7. **Sin emojis**, tokens de marca.

## E1.1 — Modelo de datos

### `cuenta_mesas` (nueva — mesas secundarias de una cuenta)
```sql
id          INT UNSIGNED PK AUTO_INCREMENT
cuenta_id   INT UNSIGNED NOT NULL
mesa_id     INT UNSIGNED NOT NULL
created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
UNIQUE KEY (cuenta_id, mesa_id)
KEY (mesa_id)
```
Solo guarda **secundarias**; la principal vive en `cuentas.mesa_id`. Migración `install/59_cuenta_mesas.sql` (idempotente) + fila en `check_migraciones.sql`.

## E1.2 — Conciencia multi-mesa (núcleo)

Con guard `cuentaMesasListo()` (tabla existe; si no, todo se comporta como hoy):
- **`cuentaAbiertaDeMesa(int $mesaId)`** ahora encuentra la cuenta abierta cuya mesa es la **principal** (`cuentas.mesa_id`) **o** una **secundaria** (`cuenta_mesas`). Así, tocar cualquier mesa del grupo abre la misma cuenta, y `cuentaAbrir` reusa (no duplica) sobre cualquier mesa del grupo.
- **`mesaEstados(int $ubicacionId)`** pinta, por cada cuenta abierta, su mesa principal **y** sus secundarias con el mismo `estado`/`minutos` (y `monto` del total de la cuenta — solo se usa para el dato; el render dibuja solo el número). Mantiene la regla de "no pintar vacías" (E del fix anterior): el grupo se pinta solo si la cuenta tiene contenido (comandas/pago/precuenta).
- **`cuentaDetalle(...)`** devuelve además **`mesas`**: los `numero` de las mesas del grupo en orden (principal primero), para mostrar "Mesa 5 + 6". Si no hay secundarias, es `[mesa_numero]`.

## E1.3 — Juntar mesa libre

`cuentaJuntarMesaLibre(int $cuentaId, int $mesaId, int $ubicacionId): array`:
- Valida: la cuenta existe, está **abierta**, del local, y **sin pagos** (si `cuentaPagosListo()` y hay `cuenta_pagos` de esa cuenta → error "ya tiene pagos").
- Valida la mesa destino: existe, activa, del **mismo local**, y **libre** (no es principal ni secundaria de ninguna cuenta abierta) — si no, error ("la mesa no está libre").
- No permite juntar la mesa principal consigo misma.
- Inserta fila en `cuenta_mesas` (idempotente por el UNIQUE).
- Devuelve `['ok'=>true, 'mesas'=>[...numeros...]]`.

## E1.4 — Separar mesa

`cuentaSepararMesa(int $cuentaId, int $mesaId, int $ubicacionId): array`:
- Valida cuenta abierta del local; **sin pagos**.
- Solo separa una **secundaria** (existe en `cuenta_mesas` para esa cuenta). La **principal no se separa** (error "no se puede separar la mesa principal").
- Borra la fila de `cuenta_mesas` → la mesa vuelve a libre.
- Devuelve `['ok'=>true, 'mesas'=>[...]]`.

## E1.5 — API (`api/mozo.php`)

Acciones nuevas (sesión de mozo; escrituras con `verifyCsrf()` + `geoGate($ubi)`):
- `mesas_libres` (lectura) → lista de mesas **libres** del local (id + numero) para el selector de "juntar". Libre = activa, del local, y no principal/secundaria de ninguna cuenta abierta.
- `juntar_mesa` (escritura, geocerca) → `cuentaJuntarMesaLibre($cid, $mesaId, $ubi)`.
- `separar_mesa` (escritura, geocerca) → `cuentaSepararMesa($cid, $mesaId, $ubi)`.

## E1.6 — UI (`mozo/index.php`)

En la **ficha de la mesa** (`openMesaInfo`, el modal al tocar una mesa con cuenta):
- Mostrar las mesas del grupo: "Mesa 5 + 6" (de `cuenta.mesas`).
- Botón **"Juntar mesa"** → abre un selector con las **mesas libres** del local (`mesas_libres`); al elegir, `juntar_mesa` (con geocerca) → refresca ficha + plano.
- Si el grupo tiene secundarias, botón **"Separar mesa"** → elegir cuál secundaria quitar → `separar_mesa` → refresca.
- Ambos solo si la cuenta no tiene pagos (si tiene, ocultar/avisar).
- Sin emojis; botones táctiles ≥44px; tokens de marca.

## Archivos

- Migración `install/59_cuenta_mesas.sql` (+ `check_migraciones.sql`).
- `includes/cuentas.php`: `cuentaMesasListo()`, `cuentaJuntarMesaLibre()`, `cuentaSepararMesa()`, `mesasLibres($ubicacionId)`; modificar `cuentaAbiertaDeMesa` y `mesaEstados` (conciencia multi-mesa); `cuentaDetalle` devuelve `mesas`.
- `api/mozo.php`: acciones `mesas_libres`, `juntar_mesa`, `separar_mesa`.
- `mozo/index.php`: ficha de mesa con grupo + botones juntar/separar + selector.

## Convenciones / seguridad
- SQL siempre con `?`. Scope multi-local por `ubicacion_id`. Sesión de mozo; `verifyCsrf()` + geocerca en escrituras.
- Guard `cuentaMesasListo()` para tolerar la migración 59 pendiente (el plano no se rompe; se comporta como hoy, una mesa por cuenta).
- Sin emojis; tokens de marca; mobile-first táctil. Sin framework de tests: `php -l` + checklist + captura si aplica.

## Fuera de alcance de E1 (va en E2)
Fusionar dos cuentas **abiertas** (mover comandas + cerrar una + absorber mesas), transferir/mover una cuenta a una mesa libre, e indicador visual de "link" entre mesas del grupo en el plano.
