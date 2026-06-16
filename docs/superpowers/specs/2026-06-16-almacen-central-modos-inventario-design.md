# Almacén central + 3 modos de inventario — Diseño

**Fecha:** 2026-06-16
**Estado:** Aprobado el diseño en brainstorming; pendiente review del spec.

## Objetivo

Dar a El Gringo un **almacén central** que nunca vende (solo guarda y despacha) y una pantalla rápida de **3 modos de inventario** (Ingresos · Salidas · Conteo) — inspirada en el proyecto CDTP — operable sobre el almacén central y los restaurantes. Los despachos del central a un restaurante son **transferencias enlazadas** que acreditan el stock del destino. La salida masiva a eventos (foodtruck) pasa a poder elegir **de qué almacén/restaurante sale**.

## Contexto: lo que ya existe (no se reconstruye)

El Gringo ya tiene los cimientos del inventario por ubicación:

- **Stock por ubicación:** `insumo_stock(insumo_id, ubicacion_id, stock, stock_min)`.
- **Kardex (ledger):** `inventario_movimientos` con `tipo ENUM('ingreso','ajuste','merma','venta','evento','compra','transferencia')`, `cantidad` con signo, `costo_unitario`, `ref`, `pedido_id`, `ubicacion_id`, `insumo_id`, `user_id`.
- **Motor único:** `invMovimiento($ubicacionId, $insumoId, $tipo, $cantidad, $opts)` en `includes/inventario.php` — aplica el movimiento y actualiza `insumo_stock` (UPSERT). Los 3 modos son **capas finas de UI** sobre este motor.
- **Inventario = insumos** (no productos): carne, pan, papas, gas, **descartables**. Los productos se explotan a insumos vía recetas al vender. Los 3 modos operan sobre **insumos**.
- **Foodtruck/eventos:** módulo de Eventos completo (inventario inicial + conteo diario + liquidación). **No se toca** salvo el selector de origen (ver abajo).

## Los tres roles de ubicación

| Rol | Vende | Flag | Cómo entra stock | Cómo sale stock |
|---|---|---|---|---|
| **Almacén central** | Nunca | `activa=0`, `es_almacen=1` | Ingresos · Compras | **Salidas = transferencia a restaurante** · Salida a evento |
| **Restaurante** | Sí | `activa=1`, `es_almacen=0` | Recibe transferencia (+) · Ingresos · Compras | Venta (descuento KDS, ya existe) · Conteo (ajuste) · Salida a evento |
| **Foodtruck** | Sí (por evento) | `activa=1` (o como esté hoy) | Salida a evento (ya existe) | Venta por evento + conteo diario (ya existe) |

**Decisión central de arquitectura:** el almacén central se modela con **`activa=0` + `es_almacen=1`**. Como todos los contextos de venta ya filtran `WHERE activa=1`, el central queda **excluido automáticamente** de carta, selector, POS, KDS, caja, monitor, pedidos, gastos y analytics, **sin tocar esos archivos**. Solo los contextos de inventario necesitan cambio (ver "Ubicaciones con inventario").

## Cambios al modelo de datos

### Migración: `install/49_almacen_central.sql`

```sql
-- Almacén central: ubicación que guarda y despacha pero NUNCA vende.
ALTER TABLE `ubicaciones`
  ADD COLUMN `es_almacen` TINYINT(1) NOT NULL DEFAULT 0 AFTER `es_principal`;
```

No se crea ninguna tabla nueva. Un almacén es una `ubicaciones` más, marcada.

## Concepto "Ubicaciones con inventario"

Cualquier selector del mundo inventario lista las **ubicaciones que manejan stock** = `activa=1 OR es_almacen=1` (incluye restaurantes/foodtrucks activos + el central oculto). Esto reemplaza el actual `WHERE activa=1` en estos archivos (y SOLO en estos):

- `admin/inventory/stock.php:10`
- `admin/inventory/movimientos.php:10`
- `admin/inventory/salida_evento.php:10` ← así el central aparece como origen de la salida a evento
- `admin/inventory/compra_form.php:11` ← así se puede recibir compras al central

(`admin/inventory/recetas.php` se deja igual: su `ubiPrincipal` es solo para mostrar costeo y el central no aporta ahí.)

Los contextos de venta (carta/selector/index/menu/banner, pos/terminal, kds, pos/caja, pos/monitor, pedidos, gastos/form, analytics, reportes) **NO se tocan**: su `activa=1` ya excluye al central.

## Pantalla nueva: 3 modos de inventario

**Archivo:** `admin/inventory/operar.php` · **Permiso:** `inv_stock` (es la contraparte de escritura de la página Stock) · **`$activePage`:** `inv-operar`.

### Layout (ver mockup `docs/superpowers/specs/mockups/inventario-modos-almacen.html`)

- **Header:** título + **selector de ubicación** (lista "ubicaciones con inventario"). Badge amarillo "Almacén" si `es_almacen`, rosa "Restaurante" si no.
- **3 tabs de modo** con color: Ingresos (verde) · Salidas (rojo) · Conteo (amarillo).
- **Barra de aviso** que cambia de color y texto según el modo.
- **Tabla de insumos** (todos los `insumos` activos): nombre · badge tipo (ingrediente/descartable) · stock actual en esa ubicación · input de cantidad. En **Conteo** aparece columna **Diferencia** en vivo.
- **Footer:** resumen ("N insumos con cantidad") + botón Guardar.

La ubicación y el modo viajan por GET/POST; el server re-renderiza con el modo activo (no SPA). Datos por insumo se mandan como `cant[insumo_id]=valor` (solo los llenos).

### Modo Ingresos (verde)
- Columna: **"Cantidad recibida"**. Solo valores > 0.
- Por cada insumo lleno → `invMovimiento($ubi, $insumoId, 'ingreso', +$cant, ['motivo'=>'Ingreso · recepción'])`.
- Leyenda: "Para compras con proveedor y costo, usa el módulo de Compras."

### Modo Salidas (rojo)
- **Si la ubicación es el almacén central** (`es_almacen=1`): aparece selector **"Despachar a:"** (destino = ubicaciones con inventario, `id <> origen`). Columna **"Cantidad salida"**. Por cada insumo lleno → `invTransferir($origen, $destino, [$insumoId=>$cant], $ref)` (transferencia enlazada, baja origen + sube destino).
- **Si la ubicación es un restaurante:** sin destino; salida simple → `invMovimiento($ubi, $insumoId, 'merma', -$cant, ['motivo'=>'Salida / merma'])`. (Cubre mermas; el despacho normal nace en el central.)
- Validación: no permitir despachar/sacar más que el stock disponible (mensaje, no descontar).

### Modo Conteo (amarillo)
- Columna: **"Stock real (conteo)"** + columna **Diferencia** = `(conteo − stock_actual)` calculada en JS (verde si +, rojo si −).
- Solo se guardan insumos cuyo conteo **difiere** del stock (`abs(diff) >= 0.001`).
- Por cada uno → `invMovimiento($ubi, $insumoId, 'ajuste', $diff, ['motivo'=>'Conteo de inventario'])` (diff con signo: el ajuste deja el stock igual al conteo).

## Helper nuevo: `invTransferir`

**Archivo:** `includes/inventario.php`.

```php
if (!function_exists('invTransferir')) {
    /**
     * Transferencia de insumos entre ubicaciones: baja en origen y sube en destino,
     * en una transacción, con una referencia común que enlaza ambas patas.
     * $items = [insumo_id => cantidad(positiva)]. Devuelve la ref usada (o '' si nada).
     * No revierte ventas históricas; valida stock suficiente en origen por insumo.
     */
    function invTransferir(int $origen, int $destino, array $items, string $motivo = 'Despacho'): string
    {
        if ($origen <= 0 || $destino <= 0 || $origen === $destino) return '';
        $ref = 'TRF-' . date('YmdHis') . '-' . $origen . '-' . $destino;
        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();
            foreach ($items as $insumoId => $cant) {
                $insumoId = (int)$insumoId; $cant = (float)$cant;
                if ($insumoId <= 0 || $cant <= 0) continue;
                // baja en origen (negativo) + sube en destino (positivo), tipo 'transferencia'
                $idOut = invMovimiento($origen,  $insumoId, 'transferencia', -$cant, ['motivo'=>$motivo,                'ref'=>$ref]);
                $idIn  = invMovimiento($destino, $insumoId, 'transferencia', +$cant, ['motivo'=>$motivo . ' (recibido)', 'ref'=>$ref]);
                // invMovimiento traga sus excepciones y devuelve 0; forzamos rollback si falló una pata
                if (!$idOut || !$idIn) throw new \RuntimeException('Falló una pata de la transferencia');
            }
            $pdo->commit();
            return $ref;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return '';
        }
    }
}
```

Las transferencias quedan visibles en `admin/inventory/movimientos.php` (tipo "transferencia", misma `ref` en ambas patas). **No** se construye una vista dedicada de "Despachos" en esta iteración (YAGNI; el kardex ya las muestra).

## Salida a evento: origen elegible

`admin/inventory/salida_evento.php` ya carga ubicaciones y deja elegir "Almacén origen" (`$_POST['ubicacion_id']`). El único cambio: su query pasa a "ubicaciones con inventario" (`activa=1 OR es_almacen=1`) para que el **almacén central aparezca** junto a los restaurantes. El resto del flujo (descuento al origen, devolución de sobrantes al cerrar) no cambia.

## Admin de ubicaciones: marcar almacén

`admin/locations/form.php` + handler:
- Checkbox **"Almacén central (no vende — solo guarda y despacha)"** → `es_almacen`.
- Hint: al marcarlo, conviene dejar **"Activa"** en off (no vende). El form no fuerza, solo sugiere.
- `admin/locations/index.php` (lista): badge "Almacén" en las filas con `es_almacen=1`.

## Permisos

Se reutiliza `inv_stock` para `operar.php` (no se agrega permiso nuevo; la operación de stock cae bajo el módulo Stock). El item de menú "Operar" se gatea con `can('inv_stock')`.

## Fuera de alcance (no-goals)

- Vista dedicada de historial de despachos (el kardex ya los lista).
- Recepción con acuse de recibo del destino (se decidió transferencia automática).
- Cambiar foodtruck/eventos más allá del selector de origen.
- Tocar el descuento de venta del KDS, POS, carta, Izipay o facturación.
- Costeo/valuación del traslado (la transferencia mueve cantidad; el costo del insumo es global y no cambia por mover stock).

## Casos borde

- **Despacho > stock disponible:** validar por insumo antes de transferir; si falta, no se descuenta y se avisa cuáles.
- **Conteo sin cambios:** no genera movimientos.
- **Tablas de inventario no migradas:** `operar.php` usa `inventarioListo()` y muestra aviso "aplica el SQL", como las demás páginas de inventario.
- **Origen = destino** en transferencia: bloqueado en `invTransferir` y en el selector (destino excluye el origen).
- **Insumo inexistente / cantidad ≤ 0:** se ignora la fila.
- **Central con `activa=1` por error:** seguiría oculto de venta solo si activa=0; el form sugiere apagarlo. (No es un bug de datos crítico: si quedara activa, podría aparecer en selectores de venta — por eso el hint.)

## Pruebas (manuales, estilo del proyecto)

1. Migración aplica; `es_almacen` existe; `check_migraciones.sql` la detecta.
2. Crear "Almacén Central" (es_almacen=1, activa=0). No aparece en carta/selector/POS/KDS. Sí aparece en Operar, Stock, Movimientos, Salida a evento, Compra.
3. **Ingresos** al central: stock sube; movimiento 'ingreso' en kardex.
4. **Salidas** desde central → restaurante: stock baja en central y **sube** en el restaurante; dos movimientos 'transferencia' con la misma ref.
5. **Conteo** en un restaurante: cambiar un valor → ajuste +/-; stock queda igual al conteo; insumos sin cambio no generan movimiento.
6. Despachar más que el stock: avisa y no descuenta.
7. **Salida a evento**: el selector de origen ofrece Central + restaurantes; elegir Central descuenta del central.
8. `php -l` limpio en todos los archivos tocados.
