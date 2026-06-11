<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();
$ubis   = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY nombre");
$cajero = currentUser();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>EL GRINGO · POS</title>
<style>
/* ── Reset & tokens ────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:        #161412;
  --surface:   #1f1c19;
  --surface2:  #2a2622;
  --border:    #332e29;
  --yellow:    #FFDF00;
  --yellow-dk: #d4b800;
  --green:     #16a34a;
  --green-dk:  #147040;
  --red:       #C8102E;
  --muted:     #8a8078;
  --text:      #f5f0ea;
  --text2:     #b8b0a6;
  --radius:    10px;
  --radius-sm: 7px;
  --topbar-h:  54px;
  --btmbar-h:  64px;
  --cart-w:    340px;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:15px;overflow:hidden}
button{cursor:pointer;border:none;background:none;font-family:inherit;color:inherit}
input,select{font-family:inherit;font-size:inherit;color:inherit}
a{color:inherit;text-decoration:none}

/* ── Topbar ────────────────────────────────────────────── */
#topbar{
  position:fixed;top:0;left:0;right:0;z-index:100;
  height:var(--topbar-h);
  background:var(--surface);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:12px;padding:0 14px;
}
#topbar .brand{
  font-weight:800;letter-spacing:.04em;font-size:15px;
  color:var(--yellow);white-space:nowrap;
}
#topbar .sep{color:var(--border);font-size:18px}
#ubi-select{
  background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);
  color:var(--text);padding:6px 10px;font-size:14px;flex:0 0 auto;max-width:180px;
  -webkit-appearance:none;appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238a8078' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 10px center;padding-right:28px;
}
#caja-estado{
  font-size:12px;font-weight:600;padding:4px 10px;border-radius:20px;white-space:nowrap;
  background:var(--surface2);color:var(--muted);border:1px solid var(--border);
}
#caja-estado.abierta{background:rgba(22,163,74,.15);color:#4ade80;border-color:rgba(22,163,74,.3)}
.spacer{flex:1}
#cajero-name{font-size:13px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px}

/* ── Layout body ───────────────────────────────────────── */
#app{
  position:fixed;top:var(--topbar-h);left:0;right:0;
  bottom:var(--btmbar-h);
  display:flex;overflow:hidden;
}

/* ── Pantalla abrir caja ───────────────────────────────── */
#screen-open{
  display:none;flex:1;align-items:center;justify-content:center;flex-direction:column;gap:18px;
  padding:24px;
}
#screen-open.active{display:flex}
.open-card{
  background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:32px 28px;max-width:360px;width:100%;
  display:flex;flex-direction:column;gap:16px;
}
.open-card h2{font-size:20px;font-weight:700}
.open-card p{font-size:13px;color:var(--text2)}
.open-card label{font-size:13px;font-weight:600;color:var(--text2)}
.open-card input[type=number]{
  width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:var(--radius);
  background:var(--surface2);color:var(--text);font-size:18px;font-weight:700;
  -moz-appearance:textfield;
}
.open-card input[type=number]::-webkit-inner-spin-button,
.open-card input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none}
.btn-open{
  background:var(--yellow);color:#000;font-weight:800;font-size:16px;
  padding:14px;border-radius:var(--radius);width:100%;
}
.btn-open:hover{background:var(--yellow-dk)}

/* ── Pantalla venta ────────────────────────────────────── */
#screen-sell{display:none;flex:1;overflow:hidden}
#screen-sell.active{display:flex}

/* ── Panel productos ───────────────────────────────────── */
#panel-prods{
  flex:1;min-width:0;display:flex;flex-direction:column;overflow:hidden;
  border-right:1px solid var(--border);
}
#prod-search-wrap{
  padding:10px 12px 8px;border-bottom:1px solid var(--border);
}
#prod-search{
  width:100%;background:var(--surface2);border:1px solid var(--border);
  border-radius:var(--radius-sm);color:var(--text);padding:9px 12px 9px 36px;
  font-size:14px;
  background-image:url("data:image/svg+xml,%3Csvg width='16' height='16' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='11' cy='11' r='7' stroke='%238a8078' stroke-width='2'/%3E%3Cpath d='M20 20l-3.5-3.5' stroke='%238a8078' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:10px center;
}
#prod-search:focus{outline:none;border-color:var(--yellow)}
/* Category tabs */
#cat-tabs{
  display:flex;gap:6px;overflow-x:auto;padding:8px 12px;border-bottom:1px solid var(--border);
  scrollbar-width:none;
}
#cat-tabs::-webkit-scrollbar{display:none}
.cat-tab{
  flex:0 0 auto;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;
  background:var(--surface2);color:var(--text2);border:1px solid transparent;
  white-space:nowrap;transition:all .12s;
}
.cat-tab.active{background:var(--yellow);color:#000;border-color:transparent}
/* Product grid */
#prod-grid-wrap{flex:1;overflow-y:auto;padding:10px 10px 6px}
#prod-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:9px;
}
.prod-tile{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  display:flex;flex-direction:column;overflow:hidden;cursor:pointer;
  transition:border-color .1s,transform .08s;
  -webkit-tap-highlight-color:transparent;
}
.prod-tile:active{transform:scale(.96);border-color:var(--yellow)}
.prod-tile img,.prod-tile .prod-img-ph{
  width:100%;aspect-ratio:4/3;object-fit:cover;
}
.prod-tile .prod-img-ph{
  display:flex;align-items:center;justify-content:center;
  background:var(--surface2);font-size:28px;
}
.prod-tile .prod-info{padding:7px 8px 8px;flex:1;display:flex;flex-direction:column;gap:2px}
.prod-tile .prod-nombre{font-size:12px;font-weight:600;line-height:1.25;color:var(--text)}
.prod-tile .prod-precio{font-size:13px;font-weight:700;color:var(--yellow)}
.prod-tile .prod-cat{font-size:10px;color:var(--muted)}
.prod-empty{
  grid-column:1/-1;padding:40px 20px;text-align:center;color:var(--muted);
  font-size:14px;
}

/* ── Panel carrito ─────────────────────────────────────── */
#panel-cart{
  width:var(--cart-w);flex:0 0 var(--cart-w);display:flex;flex-direction:column;
  background:var(--surface);overflow:hidden;
}
#cart-header{
  padding:11px 14px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:8px;
}
#cart-header span{font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em}
#cart-count{
  background:var(--yellow);color:#000;font-size:11px;font-weight:800;
  padding:2px 7px;border-radius:10px;min-width:22px;text-align:center;
}
#cart-btn-clear{
  margin-left:auto;font-size:12px;color:var(--muted);padding:4px 8px;
  border:1px solid var(--border);border-radius:var(--radius-sm);
  transition:color .1s;
}
#cart-btn-clear:hover{color:var(--text)}
#cart-lines{flex:1;overflow-y:auto;padding:6px 0}

/* Cart line */
.cart-line{
  display:flex;align-items:center;gap:10px;padding:9px 14px;
  border-bottom:1px solid var(--border);position:relative;overflow:hidden;
  background:var(--surface);transition:background .1s;
}
.cart-line.swiped{background:rgba(200,16,46,.15)}
.cart-line-info{flex:1;min-width:0}
.cart-line-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cart-line-sub{font-size:12px;color:var(--text2);margin-top:1px}
.cart-line-price{font-size:14px;font-weight:700;color:var(--yellow);white-space:nowrap}
.qty-controls{display:flex;align-items:center;gap:6px}
.qty-btn{
  width:28px;height:28px;border-radius:50%;background:var(--surface2);border:1px solid var(--border);
  font-size:16px;font-weight:700;display:flex;align-items:center;justify-content:center;
  color:var(--text);flex:0 0 28px;transition:background .1s;
}
.qty-btn:hover{background:var(--border)}
.qty-val{font-size:14px;font-weight:700;min-width:18px;text-align:center}
.cart-line-del{
  flex:0 0 auto;width:28px;height:28px;border-radius:50%;
  background:rgba(200,16,46,.2);color:#f87171;
  display:flex;align-items:center;justify-content:center;font-size:14px;
  transition:background .1s;
}
.cart-line-del:hover{background:rgba(200,16,46,.4)}

/* Cart empty state */
#cart-empty{
  flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:10px;color:var(--muted);padding:24px;text-align:center;
}
#cart-empty svg{opacity:.3}

/* Cart footer */
#cart-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:10px}
#cart-total-row{display:flex;justify-content:space-between;align-items:baseline}
#cart-total-label{font-size:13px;color:var(--text2);font-weight:600}
#cart-total-val{font-size:26px;font-weight:800;color:var(--text)}
/* Payment methods */
#metodos-row{display:flex;gap:7px;flex-wrap:wrap}
.metodo-btn{
  flex:1 1 auto;padding:8px 10px;border-radius:var(--radius-sm);font-size:12px;font-weight:700;
  background:var(--surface2);border:1.5px solid var(--border);color:var(--text2);
  transition:all .12s;white-space:nowrap;
}
.metodo-btn.active{background:rgba(255,223,0,.12);border-color:var(--yellow);color:var(--yellow)}
/* Cobrar button */
#btn-cobrar{
  width:100%;padding:16px;border-radius:var(--radius);font-size:17px;font-weight:800;
  background:var(--green);color:#fff;transition:background .12s;
  display:flex;align-items:center;justify-content:center;gap:8px;
}
#btn-cobrar:disabled{background:var(--surface2);color:var(--muted);cursor:not-allowed}
#btn-cobrar:not(:disabled):hover{background:var(--green-dk)}

/* ── Bottombar ─────────────────────────────────────────── */
#bottombar{
  position:fixed;bottom:0;left:0;right:0;z-index:100;
  height:var(--btmbar-h);
  background:var(--surface);border-top:1px solid var(--border);
  display:flex;align-items:stretch;
}
.nav-btn{
  flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:4px;font-size:11px;font-weight:600;color:var(--muted);
  border-right:1px solid var(--border);transition:color .12s,background .12s;
}
.nav-btn:last-child{border-right:none}
.nav-btn.active{color:var(--yellow)}
.nav-btn:hover:not(:disabled){color:var(--text);background:var(--surface2)}
.nav-btn:disabled{opacity:.35;cursor:not-allowed}
.nav-btn svg{width:22px;height:22px;stroke-width:1.8}

/* ── Modal overlay ─────────────────────────────────────── */
#overlay{
  display:none;position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.6);
  align-items:center;justify-content:center;padding:20px;
}
#overlay.active{display:flex}
.modal{
  background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:24px;width:100%;max-width:400px;display:flex;flex-direction:column;gap:16px;
}
.modal h3{font-size:18px;font-weight:700}
.modal p{font-size:14px;color:var(--text2)}
.modal label{font-size:13px;font-weight:600;color:var(--text2)}
.modal input[type=number]{
  width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:var(--radius);
  background:var(--surface2);color:var(--text);font-size:20px;font-weight:700;
  -moz-appearance:textfield;
}
.modal input[type=number]::-webkit-inner-spin-button,
.modal input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none}
.modal input[type=number]:focus{outline:none;border-color:var(--yellow)}
.modal-row{display:flex;gap:10px}
.btn-modal-cancel{
  flex:1;padding:12px;border-radius:var(--radius);background:var(--surface2);
  border:1px solid var(--border);color:var(--text2);font-weight:700;font-size:14px;
}
.btn-modal-ok{
  flex:2;padding:12px;border-radius:var(--radius);background:var(--yellow);
  color:#000;font-weight:800;font-size:14px;
}
.btn-modal-ok:hover{background:var(--yellow-dk)}
.btn-modal-cancel:hover{color:var(--text)}
.vuelto-box{
  background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);
  padding:12px 14px;display:flex;justify-content:space-between;align-items:center;
}
.vuelto-box span:first-child{font-size:13px;color:var(--text2)}
.vuelto-box span:last-child{font-size:22px;font-weight:800;color:#4ade80}
.vuelto-box.negativo span:last-child{color:#f87171}

/* ── Caja panel ────────────────────────────────────────── */
#panel-caja{
  display:none;position:absolute;bottom:var(--btmbar-h);left:0;right:0;
  background:var(--surface);border-top:1px solid var(--border);z-index:90;
  padding:18px 20px;flex-direction:column;gap:14px;
  max-height:calc(100vh - var(--topbar-h) - var(--btmbar-h));overflow-y:auto;
}
#panel-caja.active{display:flex}
.caja-row{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:10px}
.caja-row:last-of-type{border:none}
.caja-row span:first-child{font-size:13px;color:var(--text2)}
.caja-row span:last-child{font-size:16px;font-weight:700}
#btn-cerrar-turno{
  background:var(--red);color:#fff;font-weight:800;font-size:15px;padding:13px;
  border-radius:var(--radius);width:100%;
}
#btn-cerrar-turno:hover{background:#a80d25}

/* ── Toast ─────────────────────────────────────────────── */
#toast{
  position:fixed;bottom:calc(var(--btmbar-h) + 16px);left:50%;transform:translateX(-50%) translateY(20px);
  background:#1e1b17;border:1px solid var(--border);border-radius:var(--radius);
  padding:10px 18px;font-size:13px;font-weight:600;
  opacity:0;transition:opacity .2s,transform .2s;pointer-events:none;z-index:300;
  white-space:nowrap;max-width:90vw;
}
#toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
#toast.ok{border-color:rgba(22,163,74,.4);color:#4ade80}
#toast.err{border-color:rgba(200,16,46,.4);color:#f87171}

/* ── Spinner ───────────────────────────────────────────── */
.spin{
  display:inline-block;width:18px;height:18px;
  border:2px solid rgba(255,255,255,.2);border-top-color:#fff;
  border-radius:50%;animation:spin .6s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Responsive: narrow (< 700px) ─────────────────────── */
@media(max-width:699px){
  :root{--cart-w:100%}
  #panel-prods{display:none}
  #panel-cart{width:100%;border-top:1px solid var(--border)}
  #screen-sell.active{flex-direction:column}
  #screen-sell.active.show-prods #panel-prods{display:flex}
  #screen-sell.active.show-prods #panel-cart{display:none}
}
</style>
</head>
<body>

<!-- ── Topbar ─────────────────────────────────────────── -->
<div id="topbar">
  <span class="brand">EL GRINGO · POS</span>
  <span class="sep">|</span>
  <select id="ubi-select">
    <option value="">Ubicación…</option>
    <?php foreach ($ubis as $u): ?>
    <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
    <?php endforeach; ?>
  </select>
  <span id="caja-estado">Caja cerrada</span>
  <span class="spacer"></span>
  <span id="cajero-name"></span>
</div>

<!-- ── App body ───────────────────────────────────────── -->
<div id="app">

  <!-- Pantalla: sin ubicación -->
  <div id="screen-pick" style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:var(--muted);padding:20px;text-align:center">
    <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
    <p style="font-size:16px;font-weight:600">Selecciona una ubicación para comenzar</p>
  </div>

  <!-- Pantalla: abrir caja -->
  <div id="screen-open">
    <div class="open-card">
      <div>
        <h2>Abrir caja</h2>
        <p id="open-ubi-name" style="color:var(--muted);margin-top:4px;font-size:13px"></p>
      </div>
      <div>
        <label>Monto inicial en caja (efectivo)</label>
        <input type="number" id="input-monto-inicial" min="0" step="0.01" value="0" placeholder="0.00">
      </div>
      <button class="btn-open" id="btn-abrir-caja">Abrir caja</button>
    </div>
  </div>

  <!-- Pantalla: venta -->
  <div id="screen-sell">

    <!-- Panel izquierdo: productos -->
    <div id="panel-prods">
      <div id="prod-search-wrap">
        <input type="text" id="prod-search" placeholder="Buscar producto…" autocomplete="off" autocorrect="off" spellcheck="false">
      </div>
      <div id="cat-tabs"></div>
      <div id="prod-grid-wrap">
        <div id="prod-grid">
          <div class="prod-empty">Cargando productos…</div>
        </div>
      </div>
    </div>

    <!-- Panel derecho: carrito -->
    <div id="panel-cart">
      <div id="cart-header">
        <span>Pedido</span>
        <span id="cart-count">0</span>
        <button id="cart-btn-clear">Vaciar</button>
      </div>
      <div id="cart-lines">
        <div id="cart-empty">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.962-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/></svg>
          <span style="font-size:14px">Sin productos</span>
          <span style="font-size:12px;color:var(--muted)">Toca un producto para agregar</span>
        </div>
      </div>
      <div id="cart-footer">
        <div id="cart-total-row">
          <span id="cart-total-label">TOTAL</span>
          <span id="cart-total-val">S/ 0.00</span>
        </div>
        <div id="metodos-row"></div>
        <button id="btn-cobrar" disabled>COBRAR</button>
      </div>
    </div>

  </div><!-- /screen-sell -->

  <!-- Panel caja (slide up sobre bottombar) -->
  <div id="panel-caja">
    <h3 style="font-size:16px;font-weight:700">Resumen de caja</h3>
    <div id="caja-detalle"></div>
    <button id="btn-cerrar-turno">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:6px"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
      Cerrar turno
    </button>
  </div>

</div><!-- /app -->

<!-- ── Bottombar ──────────────────────────────────────── -->
<div id="bottombar">
  <button class="nav-btn active" id="nav-vender" title="Vender">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.962-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/></svg>
    Vender
  </button>
  <button class="nav-btn" id="nav-caja" title="Caja" disabled>
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/></svg>
    Caja
  </button>
  <button class="nav-btn" id="nav-historial" disabled title="Historial">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    Historial
  </button>
  <button class="nav-btn" id="nav-clientes" disabled title="Clientes">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
    Clientes
  </button>
</div>

<!-- ── Modal overlay ──────────────────────────────────── -->
<div id="overlay">
  <div class="modal" id="modal-content"></div>
</div>

<!-- ── Toast ──────────────────────────────────────────── -->
<div id="toast"></div>

<script>
var CSRF = <?= json_encode(csrfToken()) ?>;
var API  = '<?= APP_URL ?>/api/pos.php';
var UPLOAD_URL = <?= json_encode(UPLOAD_URL) ?>;
var UBIS  = <?= json_encode($ubis) ?>;
var CAJERO = <?= json_encode($cajero['name'] ?? 'Cajero') ?>;

// ── State ──────────────────────────────────────────────
var state = {
  ubicacionId: 0,
  turno: null,
  productos: [],
  metodos: [],
  cart: [],
  activeCat: 'Todos',
  searchQ: '',
  activeMetodoId: 0,
  cajaOpen: false
};

// ── Helpers ────────────────────────────────────────────
function esc(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function fmt(n) {
  return 'S/ ' + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function apiGet(action, params) {
  var qs = new URLSearchParams(Object.assign({ action: action }, params || {})).toString();
  return fetch(API + '?' + qs, { credentials: 'same-origin' }).then(function(r) { return r.json(); });
}

function apiPost(action, data) {
  var body = new URLSearchParams(Object.assign({ action: action }, data || {})).toString();
  return fetch(API, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-Token': CSRF
    },
    body: body
  }).then(function(r) { return r.json(); });
}

var _toastTimer = null;
function toast(msg, type) {
  var el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'show ' + (type || 'ok');
  if (_toastTimer) clearTimeout(_toastTimer);
  _toastTimer = setTimeout(function() { el.className = ''; }, 3000);
}

// ── Screen management ──────────────────────────────────
function showScreen(name) {
  ['screen-pick','screen-open','screen-sell'].forEach(function(id) {
    var el = document.getElementById(id);
    el.style.display = '';
    el.classList.remove('active');
  });
  var target = document.getElementById('screen-' + name);
  if (target) {
    target.classList.add('active');
    if (name === 'pick') target.style.display = 'flex';
    if (name === 'open') target.style.display = 'flex';
    if (name === 'sell') target.style.display = 'flex';
  }
}

// ── Init ───────────────────────────────────────────────
function init() {
  document.getElementById('cajero-name').textContent = CAJERO;
  showScreen('pick');
  if (UBIS.length === 1) {
    var sel = document.getElementById('ubi-select');
    sel.value = UBIS[0].id;
    onUbiChange(UBIS[0].id);
  }
  document.getElementById('ubi-select').addEventListener('change', function() {
    onUbiChange(parseInt(this.value, 10) || 0);
  });
  document.getElementById('prod-search').addEventListener('input', function() {
    state.searchQ = this.value.trim().toLowerCase();
    renderProductos();
  });
  document.getElementById('cart-btn-clear').addEventListener('click', function() {
    state.cart = [];
    renderCart();
  });
  document.getElementById('btn-abrir-caja').addEventListener('click', function() {
    abrirCaja();
  });
  document.getElementById('btn-cobrar').addEventListener('click', function() {
    cobrar();
  });
  document.getElementById('nav-vender').addEventListener('click', function() {
    setNavActive('nav-vender');
    closeCajaPanel();
    showScreen('sell');
  });
  document.getElementById('nav-caja').addEventListener('click', function() {
    setNavActive('nav-caja');
    toggleCajaPanel();
  });
  document.getElementById('btn-cerrar-turno').addEventListener('click', function() {
    promptCerrarTurno();
  });
  document.getElementById('overlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
  });
}

function setNavActive(id) {
  document.querySelectorAll('.nav-btn').forEach(function(b) { b.classList.remove('active'); });
  var btn = document.getElementById(id);
  if (btn) btn.classList.add('active');
}

// ── Ubicación change ───────────────────────────────────
function onUbiChange(ubiId) {
  state.ubicacionId = ubiId;
  state.turno = null;
  state.cart = [];
  closeCajaPanel();
  if (!ubiId) {
    showScreen('pick');
    updateCajaEstado(null);
    disableNavCaja(true);
    return;
  }
  loadTurno();
}

// ── Load turno ─────────────────────────────────────────
function loadTurno() {
  apiGet('turno_actual', { ubicacion_id: state.ubicacionId }).then(function(res) {
    if (!res.ok) { toast('Error al consultar turno', 'err'); return; }
    state.turno = res.turno || null;
    if (!state.turno) {
      showScreen('open');
      var ubiName = (UBIS.find(function(u) { return u.id == state.ubicacionId; }) || {}).nombre || '';
      document.getElementById('open-ubi-name').textContent = ubiName;
      updateCajaEstado(null);
      disableNavCaja(true);
    } else {
      openTerminal();
    }
  }).catch(function() { toast('Error de red', 'err'); });
}

function openTerminal() {
  updateCajaEstado(state.turno);
  disableNavCaja(false);
  showScreen('sell');
  setNavActive('nav-vender');
  if (!state.productos.length) loadProductos();
  if (!state.metodos.length) loadMetodos();
}

function updateCajaEstado(turno) {
  var el = document.getElementById('caja-estado');
  if (turno) {
    el.textContent = 'Caja abierta';
    el.className = 'caja-estado abierta';
  } else {
    el.textContent = 'Caja cerrada';
    el.className = 'caja-estado';
  }
}

function disableNavCaja(off) {
  var btn = document.getElementById('nav-caja');
  btn.disabled = off;
}

// ── Abrir caja ─────────────────────────────────────────
function abrirCaja() {
  var monto = parseFloat(document.getElementById('input-monto-inicial').value) || 0;
  var btn = document.getElementById('btn-abrir-caja');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>';
  apiPost('abrir_turno', {
    ubicacion_id: state.ubicacionId,
    monto_inicial: monto.toFixed(2)
  }).then(function(res) {
    btn.disabled = false;
    btn.textContent = 'Abrir caja';
    if (!res.ok) { toast('Error al abrir caja', 'err'); return; }
    loadTurno();
  }).catch(function() {
    btn.disabled = false;
    btn.textContent = 'Abrir caja';
    toast('Error de red', 'err');
  });
}

// ── Productos ──────────────────────────────────────────
function loadProductos() {
  apiGet('productos', { ubicacion_id: state.ubicacionId }).then(function(res) {
    if (!res.ok) return;
    state.productos = res.data || [];
    renderCatTabs();
    renderProductos();
  }).catch(function() { toast('Error cargando productos', 'err'); });
}

function loadMetodos() {
  apiGet('metodos').then(function(res) {
    if (!res.ok) return;
    state.metodos = res.data || [];
    if (state.metodos.length) state.activeMetodoId = state.metodos[0].id;
    renderMetodos();
    renderCobrarBtn();
  }).catch(function() { toast('Error cargando métodos', 'err'); });
}

function renderCatTabs() {
  var cats = ['Todos'];
  state.productos.forEach(function(p) {
    var c = p.categoria || 'Sin categoría';
    if (cats.indexOf(c) === -1) cats.push(c);
  });
  var html = cats.map(function(c) {
    return '<button class="cat-tab' + (c === state.activeCat ? ' active' : '') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>';
  }).join('');
  var el = document.getElementById('cat-tabs');
  el.innerHTML = html;
  el.querySelectorAll('.cat-tab').forEach(function(btn) {
    btn.addEventListener('click', function() {
      state.activeCat = this.dataset.cat;
      el.querySelectorAll('.cat-tab').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      renderProductos();
    });
  });
}

function renderProductos() {
  var q = state.searchQ;
  var cat = state.activeCat;
  var prods = state.productos.filter(function(p) {
    var matchCat = (cat === 'Todos') || (p.categoria || 'Sin categoría') === cat;
    var matchQ = !q || p.nombre.toLowerCase().indexOf(q) !== -1;
    return matchCat && matchQ;
  });
  var grid = document.getElementById('prod-grid');
  if (!prods.length) {
    grid.innerHTML = '<div class="prod-empty">Sin productos para mostrar</div>';
    return;
  }
  grid.innerHTML = prods.map(function(p) {
    var imgHtml;
    if (p.foto) {
      imgHtml = '<img src="' + esc(UPLOAD_URL + p.foto) + '" alt="' + esc(p.nombre) + '" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">'
              + '<div class="prod-img-ph" style="display:none">&#127828;</div>';
    } else {
      imgHtml = '<div class="prod-img-ph">&#127828;</div>';
    }
    return '<div class="prod-tile" data-id="' + esc(p.id) + '" data-nombre="' + esc(p.nombre) + '" data-precio="' + esc(p.precio) + '">'
      + imgHtml
      + '<div class="prod-info">'
      + '<div class="prod-nombre">' + esc(p.nombre) + '</div>'
      + '<div class="prod-precio">' + fmt(p.precio) + '</div>'
      + '</div></div>';
  }).join('');
  grid.querySelectorAll('.prod-tile').forEach(function(tile) {
    tile.addEventListener('click', function() {
      addToCart({
        id: parseInt(this.dataset.id, 10),
        nombre: this.dataset.nombre,
        precio: parseFloat(this.dataset.precio),
        modificadores: []
      });
    });
  });
}

// ── Cart ───────────────────────────────────────────────
function addToCart(prod) {
  var existing = state.cart.find(function(l) { return l.id === prod.id; });
  if (existing) {
    existing.qty++;
  } else {
    state.cart.push({ id: prod.id, qty: 1, nombre: prod.nombre, precio: prod.precio, modificadores: [] });
  }
  renderCart();
}

function cartTotal() {
  return state.cart.reduce(function(acc, l) { return acc + l.qty * l.precio; }, 0);
}

function renderCart() {
  var linesEl = document.getElementById('cart-lines');
  var emptyEl = document.getElementById('cart-empty');
  var count = state.cart.reduce(function(a, l) { return a + l.qty; }, 0);
  document.getElementById('cart-count').textContent = count;
  var total = cartTotal();
  document.getElementById('cart-total-val').textContent = fmt(total);

  if (!state.cart.length) {
    linesEl.innerHTML = '';
    linesEl.appendChild(emptyEl);
    emptyEl.style.display = 'flex';
    renderCobrarBtn();
    return;
  }
  emptyEl.style.display = 'none';
  linesEl.innerHTML = state.cart.map(function(line, idx) {
    return '<div class="cart-line" data-idx="' + idx + '">'
      + '<div class="cart-line-info">'
      + '<div class="cart-line-name">' + esc(line.nombre) + '</div>'
      + '<div class="cart-line-sub">' + fmt(line.precio) + ' c/u</div>'
      + '</div>'
      + '<div class="qty-controls">'
      + '<button class="qty-btn btn-minus" data-idx="' + idx + '" aria-label="Menos">−</button>'
      + '<span class="qty-val">' + line.qty + '</span>'
      + '<button class="qty-btn btn-plus" data-idx="' + idx + '" aria-label="Mas">+</button>'
      + '</div>'
      + '<span class="cart-line-price">' + fmt(line.qty * line.precio) + '</span>'
      + '<button class="cart-line-del btn-del" data-idx="' + idx + '" aria-label="Eliminar">'
      + '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>'
      + '</button>'
      + '</div>';
  }).join('');

  // Event listeners
  linesEl.querySelectorAll('.btn-minus').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var idx = parseInt(this.dataset.idx, 10);
      if (state.cart[idx].qty > 1) { state.cart[idx].qty--; } else { state.cart.splice(idx, 1); }
      renderCart();
    });
  });
  linesEl.querySelectorAll('.btn-plus').forEach(function(btn) {
    btn.addEventListener('click', function() {
      state.cart[parseInt(this.dataset.idx, 10)].qty++;
      renderCart();
    });
  });
  linesEl.querySelectorAll('.btn-del').forEach(function(btn) {
    btn.addEventListener('click', function() {
      state.cart.splice(parseInt(this.dataset.idx, 10), 1);
      renderCart();
    });
  });

  // Swipe to delete
  linesEl.querySelectorAll('.cart-line').forEach(function(row) {
    attachSwipe(row);
  });

  renderCobrarBtn();
}

function attachSwipe(row) {
  var startX = 0, startY = 0, isDragging = false;
  row.addEventListener('touchstart', function(e) {
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
    isDragging = true;
  }, { passive: true });
  row.addEventListener('touchmove', function(e) {
    if (!isDragging) return;
    var dx = e.touches[0].clientX - startX;
    var dy = Math.abs(e.touches[0].clientY - startY);
    if (dy > 30) { isDragging = false; return; }
    if (dx < -20) row.classList.add('swiped');
    else row.classList.remove('swiped');
  }, { passive: true });
  row.addEventListener('touchend', function() {
    if (!isDragging) return;
    isDragging = false;
    if (row.classList.contains('swiped')) {
      var idx = parseInt(row.dataset.idx, 10);
      state.cart.splice(idx, 1);
      renderCart();
    }
  });
}

// ── Métodos de pago ────────────────────────────────────
function renderMetodos() {
  var row = document.getElementById('metodos-row');
  if (!state.metodos.length) { row.innerHTML = ''; return; }
  row.innerHTML = state.metodos.map(function(m) {
    var active = m.id == state.activeMetodoId ? ' active' : '';
    return '<button class="metodo-btn' + active + '" data-id="' + esc(m.id) + '" data-tipo="' + esc(m.tipo) + '">' + esc(m.nombre) + '</button>';
  }).join('');
  row.querySelectorAll('.metodo-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      state.activeMetodoId = parseInt(this.dataset.id, 10);
      renderMetodos();
      renderCobrarBtn();
    });
  });
}

function activeMetodo() {
  return state.metodos.find(function(m) { return m.id == state.activeMetodoId; }) || null;
}

function renderCobrarBtn() {
  var btn = document.getElementById('btn-cobrar');
  var total = cartTotal();
  if (!state.cart.length || !state.metodos.length) {
    btn.disabled = true;
    btn.innerHTML = 'COBRAR';
    return;
  }
  btn.disabled = false;
  btn.innerHTML = 'COBRAR &nbsp;·&nbsp; ' + esc(fmt(total));
}

// ── Cobrar ─────────────────────────────────────────────
function cobrar() {
  if (!state.cart.length) return;
  var metodo = activeMetodo();
  if (!metodo) { toast('Selecciona un método de pago', 'err'); return; }
  if (metodo.tipo === 'efectivo') {
    showModalEfectivo(metodo);
  } else {
    confirmarVenta(metodo);
  }
}

function showModalEfectivo(metodo) {
  var total = cartTotal();
  var modal = document.getElementById('modal-content');
  modal.innerHTML = '<h3>Cobro en ' + esc(metodo.nombre) + '</h3>'
    + '<p>Total a cobrar: <strong>' + esc(fmt(total)) + '</strong></p>'
    + '<div><label>Monto recibido (S/)</label>'
    + '<input type="number" id="modal-recibido" min="0" step="0.10" value="' + total.toFixed(2) + '" inputmode="decimal"></div>'
    + '<div class="vuelto-box" id="modal-vuelto-box"><span>Vuelto</span><span id="modal-vuelto">' + fmt(0) + '</span></div>'
    + '<div class="modal-row">'
    + '<button class="btn-modal-cancel" id="modal-cancel">Cancelar</button>'
    + '<button class="btn-modal-ok" id="modal-confirm">Confirmar cobro</button>'
    + '</div>';
  document.getElementById('overlay').classList.add('active');
  var input = document.getElementById('modal-recibido');
  input.focus();
  input.select();
  function updateVuelto() {
    var rec = parseFloat(input.value) || 0;
    var vuelto = rec - total;
    var box = document.getElementById('modal-vuelto-box');
    var span = document.getElementById('modal-vuelto');
    span.textContent = fmt(Math.abs(vuelto));
    if (vuelto < 0) { box.classList.add('negativo'); span.textContent = '−' + fmt(Math.abs(vuelto)); }
    else { box.classList.remove('negativo'); }
  }
  updateVuelto();
  input.addEventListener('input', updateVuelto);
  document.getElementById('modal-cancel').addEventListener('click', closeModal);
  document.getElementById('modal-confirm').addEventListener('click', function() {
    closeModal();
    confirmarVenta(metodo);
  });
}

function closeModal() {
  document.getElementById('overlay').classList.remove('active');
  document.getElementById('modal-content').innerHTML = '';
}

function refreshTurno() {
  apiGet('turno_actual', { ubicacion_id: state.ubicacionId }).then(function(res) {
    if (!res.ok || !res.turno) return;
    state.turno = res.turno;
    if (state.cajaOpen) renderCajaDetalle();
  });
}

function confirmarVenta(metodo) {
  var total = cartTotal();
  var btn = document.getElementById('btn-cobrar');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>';
  apiPost('registrar_venta', {
    ubicacion_id: state.ubicacionId,
    turno_id: state.turno.id,
    metodo_pago: metodo.nombre,
    total: total.toFixed(2),
    items: JSON.stringify(state.cart)
  }).then(function(res) {
    btn.disabled = false;
    renderCobrarBtn();
    if (!res.ok) { toast('Error al registrar venta: ' + (res.error || ''), 'err'); return; }
    state.cart = [];
    renderCart();
    refreshTurno();
    toast('Venta #' + res.id + ' registrada', 'ok');
  }).catch(function() {
    btn.disabled = false;
    renderCobrarBtn();
    toast('Error de red', 'err');
  });
}

// ── Panel caja ─────────────────────────────────────────
function toggleCajaPanel() {
  state.cajaOpen = !state.cajaOpen;
  if (state.cajaOpen) openCajaPanel(); else closeCajaPanel();
}

function openCajaPanel() {
  if (!state.turno) return;
  state.cajaOpen = true;
  renderCajaDetalle();
  document.getElementById('panel-caja').classList.add('active');
}

function closeCajaPanel() {
  state.cajaOpen = false;
  document.getElementById('panel-caja').classList.remove('active');
  if (document.getElementById('nav-caja').classList.contains('active')) {
    setNavActive('nav-vender');
  }
}

function renderCajaDetalle() {
  if (!state.turno) return;
  var t = state.turno;
  var rows = [
    ['Monto inicial', fmt(t.monto_inicial || 0)],
    ['Ventas del turno', fmt(t.total_ventas || 0)],
    ['Pedidos del turno', t.total_pedidos || 0],
    ['Efectivo vendido', fmt(t.total_efectivo || 0)],
    ['Tarjeta vendido', fmt(t.total_tarjeta || 0)],
    ['QR vendido', fmt(t.total_qr || 0)]
  ];
  document.getElementById('caja-detalle').innerHTML = rows.map(function(r) {
    return '<div class="caja-row"><span>' + esc(r[0]) + '</span><span>' + esc(String(r[1])) + '</span></div>';
  }).join('');
}

// ── Cerrar turno ───────────────────────────────────────
function promptCerrarTurno() {
  var modal = document.getElementById('modal-content');
  var expected = (parseFloat(state.turno.monto_inicial) || 0) + (parseFloat(state.turno.total_efectivo) || 0);
  modal.innerHTML = '<h3>Cerrar turno</h3>'
    + '<p>Cuenta el efectivo en caja e ingresa el total contado.</p>'
    + '<p style="margin-top:4px;color:var(--text2)">Esperado en efectivo: <strong>' + esc(fmt(expected)) + '</strong></p>'
    + '<div><label>Monto contado (S/)</label>'
    + '<input type="number" id="modal-monto-final" min="0" step="0.01" value="' + expected.toFixed(2) + '" inputmode="decimal"></div>'
    + '<div class="modal-row">'
    + '<button class="btn-modal-cancel" id="modal-cancel">Cancelar</button>'
    + '<button class="btn-modal-ok" id="modal-confirm" style="background:var(--red);color:#fff">Cerrar turno</button>'
    + '</div>';
  document.getElementById('overlay').classList.add('active');
  document.getElementById('modal-monto-final').focus();
  document.getElementById('modal-cancel').addEventListener('click', closeModal);
  document.getElementById('modal-confirm').addEventListener('click', function() {
    var mf = parseFloat(document.getElementById('modal-monto-final').value) || 0;
    closeModal();
    cerrarTurno(mf);
  });
}

function cerrarTurno(montoFinal) {
  apiPost('cerrar_turno', {
    turno_id: state.turno.id,
    monto_final: montoFinal.toFixed(2)
  }).then(function(res) {
    if (!res.ok) { toast('Error al cerrar turno: ' + (res.error || ''), 'err'); return; }
    state.turno = null;
    state.cart = [];
    state.productos = [];
    state.metodos = [];
    state.activeCat = 'Todos';
    state.cajaOpen = false;
    closeCajaPanel();
    disableNavCaja(true);
    updateCajaEstado(null);
    toast('Turno cerrado', 'ok');
    // Show open screen
    var ubiName = (UBIS.find(function(u) { return u.id == state.ubicacionId; }) || {}).nombre || '';
    document.getElementById('open-ubi-name').textContent = ubiName;
    document.getElementById('input-monto-inicial').value = '0';
    showScreen('open');
    setNavActive('nav-vender');
  }).catch(function() { toast('Error de red', 'err'); });
}

// ── Boot ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', init);
</script>
</body>
</html>
