# Mesas POS — Sub-build E2: Fusionar cuentas + transferir a mesa libre

**Fecha:** 2026-06-20
**Módulo:** POS de restaurante — app del mozo (fusionar/mover)
**Estado:** diseño aprobado, pendiente de plan

## Contexto

Cierra la familia de agrupar/mover mesas. **E1** (en producción) dio la base `cuenta_mesas` (mesas secundarias), juntar mesa libre y separar. **E2** agrega: **fusionar dos cuentas abiertas** en una, y **transferir** una cuenta a una mesa libre. Reusa la base de E1; **sin migración nueva** (reusa `cuenta_mesas` y `cuentas.estado='cancelada'`).

## Decisiones tomadas (brainstorming)

1. **Picker unificado:** la acción "Juntar mesa" de la ficha lista las mesas del local fuera del grupo actual, marcando cada una **libre** o **ocupada**. Libre → agrupar (E1). Ocupada → **fusionar** (con confirmación). Una sola acción; el sistema decide según la mesa.
2. **Fusionar:** las comandas de la cuenta **origen** pasan a la **destino** (la de la ficha) **manteniendo su `mesa_id`** (el KDS sigue mostrando de qué mesa salió cada plato). Las mesas de la origen (principal + secundarias) pasan a ser secundarias de la destino. La origen se cierra como `'cancelada'`. Recalc del total de la destino. Transaccional. **Ambas sin pagos.**
3. **Transferir:** cambia la mesa principal de la cuenta a una mesa **libre**; la mesa vieja queda libre. Permitido en cualquier cuenta abierta (no mueve dinero).
4. Geocerca + CSRF en escrituras; cualquier mozo del local. Sin emojis; tokens de marca.

## E2.1 — Fusionar (`cuentaFusionar`)

`cuentaFusionar(int $cuentaDestino, int $mesaOrigen, int $ubicacionId): array`:
- Resuelve la cuenta **origen** desde `mesaOrigen` con `cuentaAbiertaDeMesa($mesaOrigen)`.
- Valida: destino existe, abierta, del local; origen existe, abierta, del local; **distintas**; ninguna con pagos (`cuentaTieneCobro`). Requiere `cuentaMesasListo()`.
- Transacción (`Database::getInstance()`):
  - Comandas: `UPDATE pedidos SET cuenta_id = :destino WHERE cuenta_id = :origen` (se mantiene `mesa_id`).
  - Secundarias de la origen → destino: `UPDATE cuenta_mesas SET cuenta_id = :destino WHERE cuenta_id = :origen` (las mesas de un grupo son únicas entre cuentas abiertas, no chocan con el UNIQUE).
  - Principal de la origen → secundaria de la destino: `INSERT IGNORE INTO cuenta_mesas (cuenta_id, mesa_id) VALUES (:destino, :origenMesaPrincipal)`.
  - Cerrar origen: `UPDATE cuentas SET estado = 'cancelada', cerrada_at = NOW() WHERE id = :origen`.
  - `cuentaTotalRecalc($cuentaDestino)`.
- Devuelve `['ok'=>true, 'mesas'=>cuentaMesasLista($cuentaDestino)]`.
- Las `cuenta_anulaciones` de la origen quedan como histórico (no se mueven; el total se recalcula desde `pedidos`).

## E2.2 — Transferir (`cuentaTransferir`)

`cuentaTransferir(int $cuentaId, int $mesaDestino, int $ubicacionId): array`:
- Valida: cuenta abierta, del local; `mesaDestino` activa, del **mismo local**, y **libre** (`cuentaAbiertaDeMesa($mesaDestino)` null); distinta de la principal actual.
- `UPDATE cuentas SET mesa_id = :mesaDestino WHERE id = :cuentaId` → la mesa vieja queda libre (ya no referenciada como principal ni secundaria). Si la cuenta estaba agrupada, las secundarias quedan igual.
- Devuelve `['ok'=>true, 'mesas'=>cuentaMesasLista($cuentaId)]`.

## E2.3 — Mesas para el picker (`mesasParaJuntar`)

`mesasParaJuntar(int $cuentaId, int $ubicacionId): array` → `[['id'=>int,'numero'=>string,'ocupada'=>bool], ...]`:
- Todas las mesas activas del local **menos** las del grupo actual (principal + secundarias de `$cuentaId`).
- `ocupada` = la mesa es principal o secundaria de **otra** cuenta abierta (vía `cuentaAbiertaDeMesa`). Libre = `false`.
- Orden: libres primero (para juntar rápido), luego ocupadas; dentro de cada una por número.

## E2.4 — API (`api/mozo.php`)

- `mesas_para_juntar` (lectura) → `mesasParaJuntar($cid, $ubi)` (recibe `cuenta_id`).
- `fusionar_cuenta` (escritura, CSRF + `geoGate`) → `cuentaFusionar($cuentaDestino, $mesaOrigen, $ubi)`.
- `transferir_cuenta` (escritura, CSRF + `geoGate`) → `cuentaTransferir($cuentaId, $mesaDestino, $ubi)`.
- (Se mantiene `juntar_mesa`/`separar_mesa` de E1.)

## E2.5 — UI (`mozo/index.php`)

- **"Juntar mesa"** (ficha) ahora usa `mesas_para_juntar`: lista cada mesa como "Mesa X" (libre) o "Mesa X · ocupada". Tap libre → `juntar_mesa` (E1, existente). Tap ocupada → **confirm** "Unir la cuenta de Mesa X a esta" → `fusionar_cuenta` → refresca ficha + plano.
- Nuevo botón **"Mover a mesa libre"** (ficha) → picker con `mesas_libres` → `transferir_cuenta` → refresca.
- Reusa el modal `#m-pick` de E1 y los helpers (`get`/`post`/`geo`/`withGeo`/`toast`/`esc`). Sin emojis; táctil ≥44px.

## Archivos

- `includes/cuentas.php`: `cuentaFusionar`, `cuentaTransferir`, `mesasParaJuntar` (reusan `cuentaAbiertaDeMesa`, `cuentaMesasLista`, `cuentaTieneCobro`, `cuentaTotalRecalc`, `cuentaMesasListo`).
- `api/mozo.php`: acciones `mesas_para_juntar`, `fusionar_cuenta`, `transferir_cuenta`.
- `mozo/index.php`: `openJuntar` usa `mesas_para_juntar` + ramo fusionar; botón/flow "Mover a mesa libre".

## Convenciones / seguridad
- SQL con `?`; scope por `ubicacion_id`; sesión de mozo; CSRF + geocerca en escrituras. Fusionar/transferir son transaccionales donde mueven varias filas (fusionar). Guard `cuentaMesasListo()`.
- Fusionar exige ambas **sin pagos**; transferir no mueve dinero. Sin emojis; tokens de marca; mobile-first.
- Verificación: `php -l` + checklist (sin framework de tests).

## Fuera de alcance de E2
Mover ítems sueltos entre cuentas; deshacer una fusión; indicador visual de "link" entre mesas del grupo en el plano (queda como mejora futura).
