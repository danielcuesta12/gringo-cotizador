<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
requirePermission('pos_terminal');
$ubis   = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY nombre");
$cajero = currentUser();
$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="POS">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#161412">
<link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/img/favicon-180.png">
<link rel="manifest" href="<?= APP_URL ?>/manifest.php?app=pos">
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
input,select,textarea{font-family:inherit;font-size:inherit;color:inherit}
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
  display:flex;flex-direction:row;align-items:center;
  justify-content:flex-start;white-space:nowrap;flex:0 0 auto;
  gap:8px;
}
#topbar .brand-logo{
  height:26px;width:auto;display:block;
}
#topbar .brand-text{
  font-weight:800;letter-spacing:.04em;font-size:15px;
  color:var(--yellow);line-height:1;
}
#topbar .brand-pos{
  font-size:17px;font-weight:800;letter-spacing:.08em;
  color:var(--yellow);text-transform:uppercase;line-height:1;
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
#btn-fullscreen{flex:0 0 auto;width:34px;height:34px;margin-left:10px;display:flex;align-items:center;justify-content:center;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text2);cursor:pointer;padding:0}
#btn-fullscreen:hover{color:var(--text);border-color:var(--muted)}
#btn-fullscreen svg{width:18px;height:18px}

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
.cart-line-info{flex:1;min-width:0;cursor:pointer}
.cart-line-info:hover .cart-line-name{color:var(--yellow)}
.cart-line-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:color .1s}
.cart-line-sub{font-size:11px;color:var(--text2);margin-top:2px;line-height:1.4}
.cart-line-mods{font-size:11px;color:var(--muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cart-line-nota{font-size:11px;color:var(--muted);font-style:italic;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cart-line-disc{font-size:11px;color:#4ade80;font-weight:600;margin-top:1px;white-space:nowrap}
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
#cart-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:8px}
#cart-disc-row{font-size:13px;color:var(--text2);display:flex;justify-content:space-between;align-items:center}
#cart-disc-row .disc-label{color:var(--muted)}
#cart-disc-row .disc-val{color:#f87171;font-weight:700}
#cart-total-row{display:flex;justify-content:space-between;align-items:baseline}
#cart-total-label{font-size:13px;color:var(--text2);font-weight:600}
#cart-total-val{font-size:26px;font-weight:800;color:var(--text)}
/* Cart actions row */
#cart-actions-row{display:flex;gap:7px}
.cart-action-btn{
  flex:1;padding:7px 8px;border-radius:var(--radius-sm);font-size:12px;font-weight:600;
  background:var(--surface2);border:1px solid var(--border);color:var(--text2);
  display:flex;align-items:center;justify-content:center;gap:4px;
  transition:all .12s;
}
.cart-action-btn:hover{color:var(--text);border-color:var(--muted)}
.cart-action-btn.active{background:rgba(255,223,0,.1);border-color:var(--yellow);color:var(--yellow)}
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
  overflow-y:auto;
}
#overlay.active{display:flex}
.modal{
  background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:24px;width:100%;max-width:400px;display:flex;flex-direction:column;gap:16px;
  margin:auto;
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

/* ── Item modal specifics ──────────────────────────────── */
.item-modal-name{font-size:18px;font-weight:800;color:var(--text)}
.item-qty-row{display:flex;align-items:center;gap:12px}
.item-qty-btn{
  width:38px;height:38px;border-radius:50%;background:var(--surface2);border:1px solid var(--border);
  font-size:20px;font-weight:700;display:flex;align-items:center;justify-content:center;
  color:var(--text);transition:background .1s;flex:0 0 38px;
}
.item-qty-btn:hover{background:var(--border)}
.item-qty-val{font-size:22px;font-weight:800;min-width:36px;text-align:center}
.item-price-live{font-size:14px;font-weight:700;color:var(--yellow);margin-left:auto}
.item-nota{
  width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);
  background:var(--surface2);color:var(--text);font-size:13px;resize:none;
  min-height:60px;
}
.item-nota:focus{outline:none;border-color:var(--yellow)}
.item-disc-row{display:flex;align-items:center;gap:8px}
.disc-toggle{
  display:flex;border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;
  flex:0 0 auto;
}
.disc-toggle button{
  padding:7px 11px;font-size:13px;font-weight:700;background:var(--surface2);color:var(--text2);
  transition:all .1s;border:none;
}
.disc-toggle button.active{background:var(--yellow);color:#000}
.disc-input{
  flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);
  background:var(--surface2);color:var(--text);font-size:15px;font-weight:700;
}
.disc-input:focus{outline:none;border-color:var(--yellow)}
.mod-group{display:flex;flex-direction:column;gap:8px}
.mod-group-name{font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.05em}
.mod-chips{display:flex;flex-wrap:wrap;gap:6px}
.mod-chip{
  padding:6px 12px;border-radius:20px;font-size:13px;font-weight:600;
  background:var(--surface2);border:1.5px solid var(--border);color:var(--text2);
  transition:all .12s;cursor:pointer;-webkit-tap-highlight-color:transparent;
}
.mod-chip.selected{background:rgba(255,223,0,.15);border-color:var(--yellow);color:var(--yellow)}
.btn-del-line{
  padding:10px;border-radius:var(--radius-sm);background:rgba(200,16,46,.15);
  border:1px solid rgba(200,16,46,.3);color:#f87171;font-weight:700;font-size:13px;
  transition:all .12s;
}
.btn-del-line:hover{background:rgba(200,16,46,.3)}

/* ── Comprobante & cliente ─────────────────────────────── */
.comp-tabs{display:flex;border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden}
.comp-tab{
  flex:1;padding:9px 6px;font-size:13px;font-weight:700;text-align:center;
  background:var(--surface2);color:var(--text2);border:none;
  transition:all .12s;
}
.comp-tab.active{background:var(--yellow);color:#000}
.cliente-fields{display:flex;flex-direction:column;gap:8px;margin-top:4px}
.cliente-fields .cf-row{display:flex;gap:8px}
.cliente-fields input,.cliente-fields select{
  width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);
  background:var(--surface2);color:var(--text);font-size:14px;
  -webkit-appearance:none;appearance:none;
}
.cliente-fields input:focus,.cliente-fields select:focus{outline:none;border-color:var(--yellow)}
.btn-buscar-cliente{
  padding:9px 12px;border-radius:var(--radius-sm);background:var(--surface2);
  border:1px solid var(--border);color:var(--muted);font-size:13px;font-weight:600;
  white-space:nowrap;cursor:not-allowed;opacity:.5;
}

/* ── Nota general ──────────────────────────────────────── */
.nota-general-area{
  width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);
  background:var(--surface2);color:var(--text);font-size:12px;resize:none;min-height:48px;
}
.nota-general-area:focus{outline:none;border-color:var(--yellow)}

/* ── Caja panel ────────────────────────────────────────── */
#panel-caja, #panel-historial{
  display:none;position:absolute;bottom:var(--btmbar-h);left:0;right:0;
  background:var(--surface);border-top:1px solid var(--border);z-index:90;
  padding:18px 20px;flex-direction:column;gap:14px;
  max-height:calc(100vh - var(--topbar-h) - var(--btmbar-h));overflow-y:auto;
}
#panel-caja.active, #panel-historial.active{display:flex}
.caja-row{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:10px}
.caja-row:last-of-type{border:none}
.caja-row span:first-child{font-size:13px;color:var(--text2)}
.caja-row span:last-child{font-size:16px;font-weight:700}
.hist-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)}
.hist-row:last-of-type{border:none}
.hist-info{flex:1;min-width:0}
.hist-top{display:flex;gap:8px;align-items:baseline}
.hist-id{font-size:14px;font-weight:700}
.hist-hora{font-size:11px;color:var(--text2)}
.hist-meta{font-size:11px;color:var(--text2);margin-top:2px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.hist-total{font-size:15px;font-weight:700;color:var(--yellow);white-space:nowrap}
.hist-actions{display:flex;gap:6px;flex-shrink:0}
.hist-actions button,.hist-actions a{font-size:11px;font-weight:700;padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--surface2);color:var(--text2);cursor:pointer;text-decoration:none}
.hist-comp{font-size:10px;font-weight:700;padding:1px 7px;border-radius:6px}
.hist-comp.emitido{background:rgba(74,222,128,.15);color:#4ade80}
.hist-comp.pend{background:rgba(255,223,0,.15);color:var(--yellow)}
.hist-comp.err{background:rgba(248,113,113,.15);color:#f87171}
.hist-comp.ticket{background:var(--surface2);color:var(--text2)}
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

/* ── Print snackbar ────────────────────────────────────── */
#print-snack{
  display:none;position:fixed;bottom:calc(var(--btmbar-h) + 16px);left:50%;
  transform:translateX(-50%);z-index:301;
  background:var(--surface);border:1px solid rgba(22,163,74,.4);border-radius:var(--radius);
  padding:10px 14px;display:none;align-items:center;gap:8px;
  font-size:13px;font-weight:600;color:#4ade80;white-space:nowrap;max-width:95vw;
  box-shadow:0 4px 16px rgba(0,0,0,.4);
}
#print-snack.show{display:flex}
/* Botón primario: Imprimir vía RawBT */
#print-snack-rawbt-btn{
  padding:6px 14px;border-radius:var(--radius-sm);background:var(--green);
  color:#fff;font-size:12px;font-weight:700;border:none;cursor:pointer;
  flex:0 0 auto;transition:background .12s;
}
#print-snack-rawbt-btn:hover{background:var(--green-dk)}
/* Botón secundario: Ver ticket HTML */
#print-snack-btn{
  padding:6px 12px;border-radius:var(--radius-sm);background:var(--surface2);
  border:1px solid var(--border);color:var(--text2);font-size:12px;font-weight:700;cursor:pointer;
  flex:0 0 auto;transition:all .12s;
}
#print-snack-btn:hover{color:var(--text);border-color:var(--muted)}
#print-snack-email-btn,#print-snack-view-btn{
  padding:6px 12px;border-radius:var(--radius-sm);background:var(--surface2);
  border:1px solid var(--border);color:var(--text2);font-size:12px;font-weight:700;cursor:pointer;
  flex:0 0 auto;transition:all .12s;
}
#print-snack-email-btn:hover,#print-snack-view-btn:hover{color:var(--text);border-color:var(--muted)}
/* ── Ticket embebido en modal ──────────────────────────── */
.ticket-embed-wrap{
  background:#fff;border-radius:var(--radius-sm);margin-bottom:12px;
  max-height:56vh;overflow:auto;display:flex;justify-content:center;padding:6px;
}
#ticket-frame{border:none;width:236px;max-width:100%;background:#fff;display:block}
#print-snack-close{
  font-size:16px;color:var(--muted);cursor:pointer;padding:0 2px;
  transition:color .1s;flex:0 0 auto;
}
#print-snack-close:hover{color:var(--text)}

/* ── Spinner ───────────────────────────────────────────── */
.spin{
  display:inline-block;width:18px;height:18px;
  border:2px solid rgba(255,255,255,.2);border-top-color:#fff;
  border-radius:50%;animation:spin .6s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── "Ver pedido" sticky bar (phone only, hidden on desktop) */
#ver-pedido-bar{
  display:none; /* shown only at ≤699px */
}
/* "Back to products" button — hidden on desktop */
#cart-mobile-back{
  display:none;
}

/* ── Responsive: narrow (< 700px) ─────────────────────── */
@media(max-width:699px){
  /* Topbar compact */
  #topbar{
    flex-wrap:nowrap;
    gap:8px;padding:0 10px;
  }
  #topbar .brand-logo{height:22px}
  #topbar .brand-text{font-size:13px}
  #topbar .brand-pos{font-size:14px}
  #topbar .sep{display:none}
  #ubi-select{font-size:13px;padding:5px 28px 5px 8px;max-width:130px}
  #caja-estado{font-size:11px;padding:3px 8px}
  #cajero-name{display:none}

  /* Screen-sell: single-panel view */
  :root{--cart-w:100%}
  #screen-sell.active{flex-direction:column}

  /* Default: show products, hide cart */
  #panel-prods{display:flex;flex:1;border-right:none}
  #panel-cart{display:none;width:100%}

  /* mobile-cart class: show cart, hide products */
  #screen-sell.mobile-cart #panel-prods{display:none}
  #screen-sell.mobile-cart #panel-cart{display:flex;flex:1}

  /* "Ver pedido" sticky bar above bottombar */
  #ver-pedido-bar{
    display:flex;
    position:fixed;bottom:var(--btmbar-h);left:0;right:0;z-index:95;
    height:52px;
    background:var(--green);color:#fff;
    align-items:center;justify-content:center;
    font-size:15px;font-weight:800;
    gap:10px;
    border-top:2px solid var(--green-dk);
    -webkit-tap-highlight-color:transparent;
    cursor:pointer;
    transition:background .12s;
  }
  #ver-pedido-bar:active{background:var(--green-dk)}
  #ver-pedido-bar.empty{
    background:var(--surface2);color:var(--muted);
    border-top-color:var(--border);
    pointer-events:none;
  }
  #ver-pedido-bar .vpb-count{
    background:rgba(0,0,0,.25);border-radius:12px;
    padding:2px 9px;font-size:13px;font-weight:800;
  }
  /* Cart "back" header button */
  #cart-mobile-back{
    display:flex;align-items:center;gap:6px;
    padding:10px 14px;border-bottom:1px solid var(--border);
    font-size:14px;font-weight:700;color:var(--text2);
    background:none;border-left:none;border-right:none;border-top:none;
    cursor:pointer;-webkit-tap-highlight-color:transparent;
    width:100%;text-align:left;
  }
  #cart-mobile-back:active{background:var(--surface2)}

  /* Show cart back-button on phone */
  #cart-mobile-back{display:flex}

  /* Adjust app bottom to make room for ver-pedido-bar */
  #app{bottom:calc(var(--btmbar-h) + 52px)}

  /* Toast & snackbar above ver-pedido-bar */
  #toast{bottom:calc(var(--btmbar-h) + 52px + 10px)}
  #print-snack{bottom:calc(var(--btmbar-h) + 52px + 10px)}

  /* Full-screen modals */
  .modal{
    width:96vw;max-width:none;max-height:92vh;
    overflow-y:auto;
  }
  /* Picker list taller on phone */
  .picker-list{max-height:45vh}

  /* Product grid: ~3 columns on phone */
  #prod-grid{grid-template-columns:repeat(auto-fill,minmax(100px,1fr))}
  #fav-grid{grid-template-columns:repeat(auto-fill,minmax(100px,1fr))}

  /* Touch targets */
  .qty-btn{width:36px;height:36px;flex:0 0 36px}
  .metodo-btn{padding:12px 10px;font-size:13px}
  #btn-cobrar{padding:18px}
  .nav-btn{font-size:10px;gap:3px}
  .cat-tab{padding:8px 14px}
  .mod-chip{padding:9px 14px}
}

/* ── Favorites board ───────────────────────────────────── */
#fav-board-wrap{
  display:none;flex:1;overflow-y:auto;padding:10px;
  flex-direction:column;gap:0;
}
#fav-board-wrap.active{display:flex}
#fav-board-toolbar{
  display:flex;align-items:center;justify-content:flex-end;
  padding:0 2px 8px;gap:8px;
}
#fav-board-toolbar span{
  font-size:12px;color:var(--muted);flex:1;
}
#btn-fav-edit{
  padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;
  background:var(--surface2);border:1px solid var(--border);color:var(--text2);
  transition:all .12s;
}
#btn-fav-edit.active{background:rgba(255,223,0,.12);border-color:var(--yellow);color:var(--yellow)}
#fav-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(120px,1fr));
  gap:9px;
}
.fav-cell{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  display:flex;flex-direction:column;overflow:hidden;cursor:pointer;position:relative;
  transition:border-color .1s,transform .08s;
  -webkit-tap-highlight-color:transparent;
}
.fav-cell:active{transform:scale(.96)}
.fav-cell.filled{border-color:var(--border)}
.fav-cell.filled:active{border-color:var(--yellow)}
.fav-cell.empty{border-style:dashed;cursor:default;}
.fav-cell.empty.editable{cursor:pointer}
.fav-cell.empty.editable:hover{border-color:var(--muted)}
/* Empty cell placeholder — match prod-tile aspect */
.fav-cell.empty .fav-empty-img{
  width:100%;aspect-ratio:4/3;background:var(--surface2);
  display:flex;align-items:center;justify-content:center;
}
.fav-cell .fav-plus{
  font-size:22px;color:var(--border);line-height:1;
}
.fav-cell.empty.editable .fav-plus{color:var(--muted)}
.fav-cell img{
  width:100%;aspect-ratio:4/3;object-fit:cover;
}
.fav-cell .fav-img-ph{
  width:100%;aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;
  background:var(--surface2);font-size:28px;
}
.fav-cell .fav-nombre{
  font-size:12px;font-weight:600;color:var(--text);
  padding:7px 8px 8px;line-height:1.25;
  overflow:hidden;display:-webkit-box;
  -webkit-line-clamp:2;-webkit-box-orient:vertical;
}
/* Edit-mode overlay badge */
.fav-cell.edit-mode.filled::after{
  content:'✕';position:absolute;top:3px;right:4px;
  font-size:11px;font-weight:800;color:#f87171;
  background:rgba(200,16,46,.25);border-radius:4px;
  padding:1px 4px;pointer-events:none;
}

/* ── Picker modal (product search inside modal) ────────── */
.picker-search{
  width:100%;padding:9px 12px 9px 36px;border:1px solid var(--border);
  border-radius:var(--radius-sm);background:var(--surface2);color:var(--text);
  font-size:14px;
  background-image:url("data:image/svg+xml,%3Csvg width='16' height='16' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='11' cy='11' r='7' stroke='%238a8078' stroke-width='2'/%3E%3Cpath d='M20 20l-3.5-3.5' stroke='%238a8078' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:10px center;
}
.picker-search:focus{outline:none;border-color:var(--yellow)}
.picker-list{
  max-height:260px;overflow-y:auto;
  display:flex;flex-direction:column;gap:4px;
  margin-top:2px;
}
.picker-item{
  display:flex;align-items:center;gap:10px;
  padding:9px 10px;border-radius:var(--radius-sm);
  background:var(--surface2);border:1px solid var(--border);
  cursor:pointer;transition:border-color .1s,background .1s;
  -webkit-tap-highlight-color:transparent;
}
.picker-item:hover,.picker-item:active{border-color:var(--yellow);background:rgba(255,223,0,.06)}
.picker-item-img{
  width:36px;height:36px;border-radius:6px;object-fit:cover;flex:0 0 36px;
}
.picker-item-ph{
  width:36px;height:36px;border-radius:6px;background:var(--surface);
  display:flex;align-items:center;justify-content:center;font-size:16px;flex:0 0 36px;
}
.picker-item-info{flex:1;min-width:0}
.picker-item-nombre{font-size:13px;font-weight:600;color:var(--text)}
.picker-item-precio{font-size:12px;color:var(--yellow);font-weight:700}
.picker-empty{font-size:13px;color:var(--muted);text-align:center;padding:20px 0}
</style>
</head>
<body>

<!-- ── Topbar ─────────────────────────────────────────── -->
<div id="topbar">
  <div class="brand">
    <?php if ($logoUrl): ?>
      <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="brand-logo">
    <?php else: ?>
      <span class="brand-text">EL GRINGO</span>
    <?php endif; ?>
    <span class="brand-pos">POS</span>
  </div>
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
  <button id="btn-fullscreen" title="Pantalla completa" aria-label="Pantalla completa"></button>
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
      <!-- Favorites board (replaces prod-grid-wrap when Favoritos tab is active) -->
      <div id="fav-board-wrap">
        <div id="fav-board-toolbar">
          <span id="fav-board-hint"></span>
          <button id="btn-fav-edit">Editar</button>
        </div>
        <div id="fav-grid"></div>
      </div>
    </div>

    <!-- Panel derecho: carrito -->
    <div id="panel-cart">
      <button id="cart-mobile-back" onclick="setMobileView('prods')">
        ← Seguir agregando
      </button>
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
        <div id="cart-disc-row" style="display:none">
          <span class="disc-label">Descuento</span>
          <span class="disc-val" id="cart-disc-val"></span>
        </div>
        <div id="cart-total-row">
          <span id="cart-total-label">TOTAL</span>
          <span id="cart-total-val">S/ 0.00</span>
        </div>
        <div id="cart-actions-row">
          <button class="cart-action-btn" id="btn-desc-global" title="Descuento global">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m-6.375.375a.375.375 0 11-.75 0 .375.375 0 01.75 0zm6.75 6a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25z"/></svg>
            Descuento
          </button>
          <button class="cart-action-btn" id="btn-nota-general" title="Nota general">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/></svg>
            Nota
          </button>
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

  <!-- Panel historial (slide up sobre bottombar) -->
  <div id="panel-historial">
    <h3 style="font-size:16px;font-weight:700">Historial del turno</h3>
    <div id="historial-lista"><div style="color:var(--text2);font-size:13px;text-align:center;padding:20px">Cargando…</div></div>
  </div>

</div><!-- /app -->

<!-- ── Ver pedido bar (phone only) ────────────────────── -->
<div id="ver-pedido-bar" class="empty" role="button" tabindex="0" aria-label="Ver pedido">
  <span id="vpb-label">Sin productos</span>
</div>

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

<!-- ── Print snackbar ─────────────────────────────────── -->
<div id="print-snack">
  <span id="print-snack-msg">Venta registrada</span>
  <button id="print-snack-rawbt-btn" title="Imprimir vía RawBT / Bluetooth">Imprimir</button>
  <button id="print-snack-view-btn" title="Ver ticket">Ver</button>
  <button id="print-snack-email-btn">Correo</button>
  <span id="print-snack-close" title="Cerrar">✕</span>
</div>

<script>
var CSRF = <?= json_encode(csrfToken()) ?>;
var API  = '<?= APP_URL ?>/api/pos.php';
var TICKET_BASE  = '<?= APP_URL ?>/pos/ticket.php';
var ESCPOS_BASE  = '<?= APP_URL ?>/pos/escpos.php';
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
  cajaOpen: false,
  descuento: null,
  notas: '',
  // Favorites board
  favMap: {},       // posicion (int) → {producto_id, nombre, foto}
  favEditMode: false,
  FAV_TOTAL: 40     // positions 0..39
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

// ── Mobile view toggle (products ↔ cart) ───────────────
function setMobileView(view) {
  var ss = document.getElementById('screen-sell');
  if (!ss) return;
  if (view === 'cart') {
    ss.classList.add('mobile-cart');
  } else {
    ss.classList.remove('mobile-cart');
  }
}

function updateVerPedidoBar() {
  var bar = document.getElementById('ver-pedido-bar');
  var lbl = document.getElementById('vpb-label');
  if (!bar || !lbl) return;
  var count = state.cart.reduce(function(a, l) { return a + l.qty; }, 0);
  if (!count) {
    bar.className = 'empty';
    lbl.textContent = 'Sin productos';
  } else {
    bar.className = '';
    lbl.innerHTML = 'Ver pedido &nbsp;<span class="vpb-count">' + count + '</span>&nbsp; · &nbsp;' + esc(fmt(cartTotal())) + ' →';
  }
}

// ── Screen management ──────────────────────────────────
function showScreen(name) {
  ['screen-pick','screen-open','screen-sell'].forEach(function(id) {
    var el = document.getElementById(id);
    el.classList.remove('active');
    el.style.display = 'none';
  });
  var target = document.getElementById('screen-' + name);
  if (target) {
    target.classList.add('active');
    target.style.display = 'flex';
  }
  // Reset to products view when entering sell screen on phone
  if (name === 'sell') setMobileView('prods');
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
    state.descuento = null;
    state.notas = '';
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
  document.getElementById('nav-historial').addEventListener('click', function() {
    setNavActive('nav-historial');
    toggleHistorialPanel();
  });
  document.getElementById('btn-cerrar-turno').addEventListener('click', function() {
    promptCerrarTurno();
  });
  document.getElementById('overlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
  });
  document.getElementById('btn-desc-global').addEventListener('click', function() {
    abrirDescuentoGlobal();
  });
  document.getElementById('btn-nota-general').addEventListener('click', function() {
    abrirNotaGeneral();
  });
  document.getElementById('btn-fav-edit').addEventListener('click', function() {
    state.favEditMode = !state.favEditMode;
    updateFavEditBtn();
    renderFavBoard();
  });

  // "Ver pedido" bar — tap to switch to cart view on phone
  var vpbBar = document.getElementById('ver-pedido-bar');
  if (vpbBar) {
    vpbBar.addEventListener('click', function() {
      if (!state.cart.length) return;
      setMobileView('cart');
    });
    vpbBar.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); vpbBar.click(); }
    });
  }
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
  state.descuento = null;
  state.notas = '';
  state.favMap = {};
  state.favEditMode = false;
  state.activeCat = 'Todos';
  hideFavBoard();
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
  document.getElementById('nav-caja').disabled = off;
  document.getElementById('nav-historial').disabled = off;
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
  // Append Favoritos tab at the end
  cats.push('__favoritos__');
  var el = document.getElementById('cat-tabs');
  el.innerHTML = cats.map(function(c) {
    if (c === '__favoritos__') {
      return '<button class="cat-tab' + (state.activeCat === '__favoritos__' ? ' active' : '') + '" data-cat="__favoritos__" style="display:flex;align-items:center;gap:5px">'
        + '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/></svg>'
        + 'Favoritos</button>';
    }
    return '<button class="cat-tab' + (c === state.activeCat ? ' active' : '') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>';
  }).join('');
  el.querySelectorAll('.cat-tab').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var cat = this.dataset.cat;
      state.activeCat = cat;
      el.querySelectorAll('.cat-tab').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      if (cat === '__favoritos__') {
        showFavBoard();
      } else {
        hideFavBoard();
        renderProductos();
      }
    });
  });
}

function showFavBoard() {
  document.getElementById('prod-grid-wrap').style.display = 'none';
  document.getElementById('prod-search-wrap').style.display = 'none';
  var wrap = document.getElementById('fav-board-wrap');
  wrap.classList.add('active');
  state.favEditMode = false;
  updateFavEditBtn();
  loadFavoritos();
}

function hideFavBoard() {
  document.getElementById('prod-grid-wrap').style.display = '';
  document.getElementById('prod-search-wrap').style.display = '';
  var wrap = document.getElementById('fav-board-wrap');
  wrap.classList.remove('active');
  state.favEditMode = false;
}

function updateFavEditBtn() {
  var btn = document.getElementById('btn-fav-edit');
  var hint = document.getElementById('fav-board-hint');
  if (state.favEditMode) {
    btn.textContent = 'Listo';
    btn.classList.add('active');
    hint.textContent = 'Toca celda para cambiar o quitar';
  } else {
    btn.textContent = 'Editar';
    btn.classList.remove('active');
    hint.textContent = '';
  }
}

function loadFavoritos() {
  if (!state.ubicacionId) return;
  apiGet('favoritos', { ubicacion_id: state.ubicacionId }).then(function(res) {
    state.favMap = {};
    if (res.ok && res.data) {
      res.data.forEach(function(fav) {
        state.favMap[parseInt(fav.posicion, 10)] = {
          producto_id: parseInt(fav.producto_id, 10),
          nombre: fav.nombre,
          foto: fav.foto || ''
        };
      });
    }
    renderFavBoard();
  }).catch(function() { toast('Error cargando favoritos', 'err'); });
}

function renderFavBoard() {
  var total = state.FAV_TOTAL; // 40
  var html = '';
  for (var pos = 0; pos < total; pos++) {
    var fav = state.favMap[pos];
    if (fav) {
      var imgHtml;
      if (fav.foto) {
        imgHtml = '<img src="' + esc(UPLOAD_URL + fav.foto) + '" alt="' + esc(fav.nombre) + '" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">'
                + '<div class="fav-img-ph" style="display:none">&#127828;</div>';
      } else {
        imgHtml = '<div class="fav-img-ph">&#127828;</div>';
      }
      var favProd = state.productos.find(function(p) { return p.id === fav.producto_id; });
      var favPrecioHtml = favProd && parseFloat(favProd.precio) > 0
        ? '<div class="prod-precio" style="padding:0 8px 8px;margin-top:-4px">' + fmt(favProd.precio) + '</div>'
        : '';
      html += '<div class="fav-cell filled' + (state.favEditMode ? ' edit-mode' : '') + '" data-pos="' + pos + '">'
            + imgHtml
            + '<div class="fav-nombre">' + esc(fav.nombre) + '</div>'
            + favPrecioHtml
            + '</div>';
    } else {
      html += '<div class="fav-cell empty' + (state.favEditMode ? ' editable' : '') + '" data-pos="' + pos + '">'
            + '<div class="fav-empty-img"><span class="fav-plus">+</span></div>'
            + '</div>';
    }
  }
  var grid = document.getElementById('fav-grid');
  grid.innerHTML = html;

  grid.querySelectorAll('.fav-cell').forEach(function(cell) {
    cell.addEventListener('click', function() {
      var pos = parseInt(this.dataset.pos, 10);
      var fav = state.favMap[pos];
      if (state.favEditMode) {
        if (fav) {
          // Confirm removal
          abrirFavQuitarModal(pos, fav);
        } else {
          // Pick a product to assign
          abrirPickerProducto(pos);
        }
      } else {
        if (fav) {
          // Sell the product
          var prod = state.productos.find(function(p) { return p.id === fav.producto_id; });
          if (!prod || !(parseFloat(prod.precio) > 0)) { toast('Producto no disponible', 'err'); return; }
          var precio = parseFloat(prod.precio);
          abrirItemModal({ id: fav.producto_id, nombre: fav.nombre, precio: precio }, undefined);
        }
        // Empty cell in non-edit mode: do nothing
      }
    });
  });
}

function abrirFavQuitarModal(posicion, fav) {
  var modal = document.getElementById('modal-content');
  modal.innerHTML = '<h3>' + esc(fav.nombre) + '</h3>'
    + '<p>¿Quitarlo de los favoritos?</p>'
    + '<div class="modal-row">'
    + '<button class="btn-modal-cancel" id="fq-cancel">Cancelar</button>'
    + '<button class="btn-modal-ok" id="fq-quitar" style="background:var(--red);color:#fff">Quitar</button>'
    + '</div>';
  document.getElementById('overlay').classList.add('active');
  document.getElementById('fq-cancel').addEventListener('click', closeModal);
  document.getElementById('fq-quitar').addEventListener('click', function() {
    closeModal();
    apiPost('fav_clear', { ubicacion_id: state.ubicacionId, posicion: posicion }).then(function(res) {
      if (!res.ok) { toast('Error al quitar favorito', 'err'); return; }
      loadFavoritos();
    }).catch(function() { toast('Error de red', 'err'); });
  });
}

function abrirPickerProducto(posicion) {
  var modal = document.getElementById('modal-content');
  modal.innerHTML = '<h3>Agregar a favoritos</h3>'
    + '<input class="picker-search" type="text" id="picker-q" placeholder="Buscar producto…" autocomplete="off" autocorrect="off" spellcheck="false">'
    + '<div class="picker-list" id="picker-list"></div>'
    + '<div class="modal-row" style="margin-top:8px">'
    + '<button class="btn-modal-cancel" id="picker-cancel">Cancelar</button>'
    + '</div>';
  document.getElementById('overlay').classList.add('active');
  renderPickerList('', posicion);
  var inp = document.getElementById('picker-q');
  inp.focus();
  inp.addEventListener('input', function() {
    renderPickerList(this.value.trim().toLowerCase(), posicion);
  });
  document.getElementById('picker-cancel').addEventListener('click', closeModal);
}

function renderPickerList(q, posicion) {
  var list = document.getElementById('picker-list');
  if (!list) return;
  var prods = q
    ? state.productos.filter(function(p) { return p.nombre.toLowerCase().indexOf(q) !== -1; })
    : state.productos;
  if (!prods.length) {
    list.innerHTML = '<div class="picker-empty">Sin resultados</div>';
    return;
  }
  list.innerHTML = prods.slice(0, 40).map(function(p) {
    var imgHtml;
    if (p.foto) {
      imgHtml = '<img class="picker-item-img" src="' + esc(UPLOAD_URL + p.foto) + '" alt="' + esc(p.nombre) + '" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">'
              + '<div class="picker-item-ph" style="display:none">&#127828;</div>';
    } else {
      imgHtml = '<div class="picker-item-ph">&#127828;</div>';
    }
    return '<div class="picker-item" data-id="' + esc(p.id) + '">'
          + imgHtml
          + '<div class="picker-item-info">'
          + '<div class="picker-item-nombre">' + esc(p.nombre) + '</div>'
          + '<div class="picker-item-precio">' + fmt(p.precio) + '</div>'
          + '</div></div>';
  }).join('');
  list.querySelectorAll('.picker-item').forEach(function(item) {
    item.addEventListener('click', function() {
      var prodId = parseInt(this.dataset.id, 10);
      closeModal();
      apiPost('fav_set', {
        ubicacion_id: state.ubicacionId,
        producto_id: prodId,
        posicion: posicion
      }).then(function(res) {
        if (!res.ok) { toast('Error al guardar favorito', 'err'); return; }
        loadFavoritos();
      }).catch(function() { toast('Error de red', 'err'); });
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
      abrirItemModal({
        id: parseInt(this.dataset.id, 10),
        nombre: this.dataset.nombre,
        precio: parseFloat(this.dataset.precio)
      }, undefined);
    });
  });
}

// ── lineTotal ──────────────────────────────────────────
function lineTotal(line) {
  var modSum = (line.modificadores || []).reduce(function(a, m) {
    return a + (parseFloat(m.precio) || 0);
  }, 0);
  var base = line.qty * (parseFloat(line.precio) + modSum);
  if (line.desc_tipo === 'porcentaje') {
    base = base * (1 - (parseFloat(line.desc_valor) || 0) / 100);
  } else if (line.desc_tipo === 'monto') {
    base = base - (parseFloat(line.desc_valor) || 0);
  }
  return Math.max(0, base);
}

// Cuánto se descuenta en una línea (soles), 0 si no hay descuento
function lineDiscAmount(line) {
  var modSum = (line.modificadores || []).reduce(function(a, m) { return a + (parseFloat(m.precio) || 0); }, 0);
  var gross = line.qty * (parseFloat(line.precio) + modSum);
  if (line.desc_tipo === 'porcentaje') return gross * (parseFloat(line.desc_valor) || 0) / 100;
  if (line.desc_tipo === 'monto') return Math.min(parseFloat(line.desc_valor) || 0, gross);
  return 0;
}

// ── Cart ───────────────────────────────────────────────
function addToCart(prod) {
  var existing = state.cart.find(function(l) { return l.id === prod.id; });
  if (existing) {
    existing.qty++;
  } else {
    state.cart.push({ id: prod.id, qty: 1, nombre: prod.nombre, precio: prod.precio, modificadores: [], nota: '', desc_tipo: null, desc_valor: 0 });
  }
  renderCart();
}

function cartTotal() {
  var sub = state.cart.reduce(function(acc, l) { return acc + lineTotal(l); }, 0);
  if (state.descuento) {
    if (state.descuento.tipo === 'porcentaje') {
      sub = sub * (1 - state.descuento.valor / 100);
    } else if (state.descuento.tipo === 'monto') {
      sub = sub - state.descuento.valor;
    }
  }
  return Math.max(0, sub);
}

function renderCart() {
  var linesEl = document.getElementById('cart-lines');
  if (!renderCart._empty) renderCart._empty = document.getElementById('cart-empty');
  var emptyEl = renderCart._empty;
  var count = state.cart.reduce(function(a, l) { return a + l.qty; }, 0);
  document.getElementById('cart-count').textContent = count;
  var total = cartTotal();
  document.getElementById('cart-total-val').textContent = fmt(total);

  // Discount display
  var discRow = document.getElementById('cart-disc-row');
  var discVal = document.getElementById('cart-disc-val');
  if (state.descuento && state.descuento.valor > 0) {
    discRow.style.display = 'flex';
    var sub = state.cart.reduce(function(acc, l) { return acc + lineTotal(l); }, 0);
    var discAmt;
    if (state.descuento.tipo === 'porcentaje') {
      discAmt = sub * state.descuento.valor / 100;
      discVal.textContent = '−' + fmt(discAmt) + ' (' + state.descuento.valor + '%)';
    } else {
      discAmt = Math.min(state.descuento.valor, sub);
      discVal.textContent = '−' + fmt(discAmt);
    }
    document.getElementById('btn-desc-global').classList.add('active');
  } else {
    discRow.style.display = 'none';
    document.getElementById('btn-desc-global').classList.remove('active');
  }

  // Nota general indicator
  var notaBtn = document.getElementById('btn-nota-general');
  if (state.notas && state.notas.trim()) {
    notaBtn.classList.add('active');
  } else {
    notaBtn.classList.remove('active');
  }

  if (!state.cart.length) {
    linesEl.innerHTML = '';
    linesEl.appendChild(emptyEl);
    emptyEl.style.display = 'flex';
    renderCobrarBtn();
    updateVerPedidoBar();
    setMobileView('prods');
    return;
  }
  emptyEl.style.display = 'none';
  linesEl.innerHTML = state.cart.map(function(line, idx) {
    var modText = (line.modificadores || []).filter(function(m) { return m.nombre; })
      .map(function(m) { return '+ ' + esc(m.nombre); }).join(', ');
    var notaText = (line.nota || '').trim();
    var subLines = '';
    if (modText) subLines += '<div class="cart-line-mods">' + modText + '</div>';
    if (notaText) subLines += '<div class="cart-line-nota">Nota: ' + esc(notaText) + '</div>';
    var dAmt = lineDiscAmount(line);
    if (line.desc_tipo && dAmt > 0) {
      var dLbl = line.desc_tipo === 'porcentaje' ? ' (' + (parseFloat(line.desc_valor) || 0) + '%)' : '';
      subLines += '<div class="cart-line-disc">Desc. −' + fmt(dAmt) + dLbl + '</div>';
    }
    return '<div class="cart-line" data-idx="' + idx + '">'
      + '<div class="cart-line-info" data-idx="' + idx + '">'
      + '<div class="cart-line-name">' + esc(line.nombre) + '</div>'
      + subLines
      + '</div>'
      + '<div class="qty-controls">'
      + '<button class="qty-btn btn-minus" data-idx="' + idx + '" aria-label="Menos">−</button>'
      + '<span class="qty-val">' + line.qty + '</span>'
      + '<button class="qty-btn btn-plus" data-idx="' + idx + '" aria-label="Mas">+</button>'
      + '</div>'
      + '<span class="cart-line-price">' + fmt(lineTotal(line)) + '</span>'
      + '<button class="cart-line-del btn-del" data-idx="' + idx + '" aria-label="Eliminar">'
      + '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>'
      + '</button>'
      + '</div>';
  }).join('');

  // Event listeners
  linesEl.querySelectorAll('.cart-line-info').forEach(function(info) {
    info.addEventListener('click', function(e) {
      var idx = parseInt(this.dataset.idx, 10);
      abrirItemModal(state.cart[idx], idx);
    });
  });
  linesEl.querySelectorAll('.btn-minus').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var idx = parseInt(this.dataset.idx, 10);
      if (state.cart[idx].qty > 1) { state.cart[idx].qty--; } else { state.cart.splice(idx, 1); }
      renderCart();
    });
  });
  linesEl.querySelectorAll('.btn-plus').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      state.cart[parseInt(this.dataset.idx, 10)].qty++;
      renderCart();
    });
  });
  linesEl.querySelectorAll('.btn-del').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      state.cart.splice(parseInt(this.dataset.idx, 10), 1);
      renderCart();
    });
  });

  // Swipe to delete
  linesEl.querySelectorAll('.cart-line').forEach(function(row) {
    attachSwipe(row);
  });

  renderCobrarBtn();
  updateVerPedidoBar();
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

// ── Item modal ─────────────────────────────────────────
function abrirItemModal(prod, lineIdx) {
  var isEditing = (typeof lineIdx !== 'undefined');
  var qty = isEditing ? (prod.qty || 1) : 1;
  var nota = isEditing ? (prod.nota || '') : '';
  var descTipo = isEditing ? (prod.desc_tipo || 'porcentaje') : 'porcentaje';
  var descValor = isEditing ? (prod.desc_valor || 0) : 0;
  var selectedMods = isEditing ? (prod.modificadores || []) : [];

  var modal = document.getElementById('modal-content');
  modal.innerHTML = '<div class="item-modal-name">' + esc(prod.nombre) + '</div>'
    + '<div class="item-qty-row">'
    + '<button class="item-qty-btn" id="im-minus">−</button>'
    + '<span class="item-qty-val" id="im-qty">' + qty + '</span>'
    + '<button class="item-qty-btn" id="im-plus">+</button>'
    + '<span class="item-price-live" id="im-price">' + fmt(qty * parseFloat(prod.precio)) + '</span>'
    + '</div>'
    + '<div>'
    + '<label style="display:block;margin-bottom:6px">Nota del ítem</label>'
    + '<textarea class="item-nota" id="im-nota" placeholder="Ej: sin cebolla, término medio…" maxlength="200">' + esc(nota) + '</textarea>'
    + '</div>'
    + '<div>'
    + '<label style="display:block;margin-bottom:6px">Descuento del ítem</label>'
    + '<div class="item-disc-row">'
    + '<div class="disc-toggle">'
    + '<button id="im-disc-pct">%</button>'
    + '<button id="im-disc-mnt">S/</button>'
    + '</div>'
    + '<input class="disc-input" type="text" inputmode="decimal" id="im-disc-val" placeholder="0" value="' + (descValor > 0 ? descValor : '') + '">'
    + '</div>'
    + '<div id="im-disc-amount" style="display:none;margin-top:6px;font-size:13px;font-weight:700;color:#4ade80"></div>'
    + '</div>'
    + '<div id="im-mods-section"></div>'
    + '<div class="modal-row" id="im-buttons">'
    + (isEditing ? '<button class="btn-del-line" id="im-del">Eliminar</button>' : '')
    + '<button class="btn-modal-cancel" id="im-cancel">Cancelar</button>'
    + '<button class="btn-modal-ok" id="im-confirm">' + (isEditing ? 'Guardar' : 'Agregar') + '</button>'
    + '</div>';

  document.getElementById('overlay').classList.add('active');

  // Local state for modal
  var modalState = {
    qty: qty,
    descTipo: descTipo,
    descValor: descValor,
    grupos: [],
    selectedMods: selectedMods.slice()
  };

  function calcLinePriceModal() {
    var modSum = modalState.selectedMods.reduce(function(a, m) {
      return a + (parseFloat(m.precio) || 0);
    }, 0);
    var base = modalState.qty * (parseFloat(prod.precio) + modSum);
    var dv = parseFloat(document.getElementById('im-disc-val').value) || 0;
    if (modalState.descTipo === 'porcentaje') {
      base = base * (1 - dv / 100);
    } else {
      base = base - dv;
    }
    return Math.max(0, base);
  }

  // Cuánto se está descontando en el modal (soles), 0 si no hay descuento
  function modalDiscAmount() {
    var modSum = modalState.selectedMods.reduce(function(a, m) { return a + (parseFloat(m.precio) || 0); }, 0);
    var gross = modalState.qty * (parseFloat(prod.precio) + modSum);
    var dv = parseFloat(document.getElementById('im-disc-val').value) || 0;
    if (dv <= 0) return 0;
    if (modalState.descTipo === 'porcentaje') return gross * dv / 100;
    return Math.min(dv, gross);
  }

  function updateModalPrice() {
    var el = document.getElementById('im-price');
    if (el) el.textContent = fmt(calcLinePriceModal());
    var da = document.getElementById('im-disc-amount');
    if (da) {
      var amt = modalDiscAmount();
      if (amt > 0) {
        var dv = parseFloat(document.getElementById('im-disc-val').value) || 0;
        var lbl = modalState.descTipo === 'porcentaje' ? ' (' + dv + '%)' : '';
        da.textContent = 'Descuento: −' + fmt(amt) + lbl;
        da.style.display = 'block';
      } else {
        da.style.display = 'none';
      }
    }
  }

  // Toggle tipo radio: siempre refleja el tipo elegido (seleccionable sin valor)
  function syncDiscToggle() {
    var pct = document.getElementById('im-disc-pct');
    var mnt = document.getElementById('im-disc-mnt');
    if (!pct || !mnt) return;
    pct.classList.toggle('active', modalState.descTipo === 'porcentaje');
    mnt.classList.toggle('active', modalState.descTipo === 'monto');
  }

  // Compara modificadores por id (fallback a nombre si falta el id)
  function sameMod(a, b) {
    if (a && b && a.id != null && b.id != null) return a.id === b.id;
    return !!(a && b && a.nombre === b.nombre);
  }

  function updateQtyDisplay() {
    var el = document.getElementById('im-qty');
    if (el) el.textContent = modalState.qty;
    updateModalPrice();
  }

  document.getElementById('im-minus').addEventListener('click', function() {
    if (modalState.qty > 1) { modalState.qty--; updateQtyDisplay(); }
  });
  document.getElementById('im-plus').addEventListener('click', function() {
    modalState.qty++;
    updateQtyDisplay();
  });
  document.getElementById('im-disc-pct').addEventListener('click', function() {
    modalState.descTipo = 'porcentaje';
    syncDiscToggle();
    updateModalPrice();
  });
  document.getElementById('im-disc-mnt').addEventListener('click', function() {
    modalState.descTipo = 'monto';
    syncDiscToggle();
    updateModalPrice();
  });
  document.getElementById('im-disc-val').addEventListener('input', function() {
    updateModalPrice();
    syncDiscToggle();
  });
  syncDiscToggle(); // estado inicial: activo solo si el ítem ya trae descuento

  if (isEditing) {
    document.getElementById('im-del').addEventListener('click', function() {
      state.cart.splice(lineIdx, 1);
      renderCart();
      closeModal();
    });
  }
  document.getElementById('im-cancel').addEventListener('click', closeModal);
  document.getElementById('im-confirm').addEventListener('click', function() {
    var dv = parseFloat(document.getElementById('im-disc-val').value) || 0;
    var notaVal = document.getElementById('im-nota').value.trim();
    var line = {
      id: prod.id,
      qty: modalState.qty,
      nombre: prod.nombre,
      precio: parseFloat(prod.precio),
      modificadores: modalState.selectedMods.slice(),
      nota: notaVal,
      desc_tipo: dv > 0 ? modalState.descTipo : null,
      desc_valor: dv > 0 ? dv : 0
    };
    if (isEditing) {
      state.cart[lineIdx] = line;
    } else {
      state.cart.push(line);
    }
    renderCart();
    closeModal();
  });

  // Load modifiers
  apiGet('producto_mods', { producto_id: prod.id }).then(function(res) {
    var section = document.getElementById('im-mods-section');
    if (!section) return;
    var grupos = (res && res.grupos) ? res.grupos : [];
    modalState.grupos = grupos;
    if (!grupos.length) { section.innerHTML = ''; return; }

    // Pre-select if editing
    if (isEditing && selectedMods.length) {
      modalState.selectedMods = selectedMods.slice();
    }

    section.innerHTML = '<div style="display:flex;flex-direction:column;gap:12px">'
      + grupos.map(function(g, gi) {
          var isSingle = (g.tipo === 'unico' || g.tipo === 'single' || g.max_opciones == 1);
          return '<div class="mod-group">'
            + '<div class="mod-group-name">' + esc(g.nombre)
            + (isSingle ? '' : (g.max_opciones ? ' (máx ' + esc(g.max_opciones) + ')' : ''))
            + '</div>'
            + '<div class="mod-chips">'
            + g.modificadores.map(function(m, mi) {
                var isSel = modalState.selectedMods.some(function(sm) { return sameMod(sm, m); });
                return '<button class="mod-chip' + (isSel ? ' selected' : '') + '" '
                  + 'data-gi="' + gi + '" data-mi="' + mi + '">'
                  + esc(m.nombre)
                  + (m.precio_adicional > 0 ? ' <span style="font-size:11px;opacity:.7">+' + fmt(m.precio_adicional) + '</span>' : '')
                  + '</button>';
              }).join('')
            + '</div></div>';
        }).join('')
      + '</div>';

    section.querySelectorAll('.mod-chip').forEach(function(chip) {
      chip.addEventListener('click', function() {
        var gi = parseInt(this.dataset.gi, 10);
        var mi = parseInt(this.dataset.mi, 10);
        var grupo = modalState.grupos[gi];
        var mod = grupo.modificadores[mi];
        var isSingle = (grupo.tipo === 'unico' || grupo.tipo === 'single' || grupo.max_opciones == 1);
        var idx = modalState.selectedMods.findIndex(function(sm) { return sameMod(sm, mod); });
        if (idx !== -1) {
          // deselect
          modalState.selectedMods.splice(idx, 1);
          this.classList.remove('selected');
        } else {
          if (isSingle) {
            // remove other chips in this group
            grupo.modificadores.forEach(function(gm) {
              var existIdx = modalState.selectedMods.findIndex(function(sm) { return sameMod(sm, gm); });
              if (existIdx !== -1) modalState.selectedMods.splice(existIdx, 1);
            });
            section.querySelectorAll('.mod-chip[data-gi="' + gi + '"]').forEach(function(c) {
              c.classList.remove('selected');
            });
          } else {
            // respect max_opciones
            if (grupo.max_opciones) {
              var selCount = modalState.selectedMods.filter(function(sm) {
                return grupo.modificadores.some(function(gm) { return sameMod(gm, sm); });
              }).length;
              if (selCount >= grupo.max_opciones) return;
            }
          }
          modalState.selectedMods.push({ id: mod.id, nombre: mod.nombre, precio: parseFloat(mod.precio_adicional) || 0 });
          this.classList.add('selected');
        }
        updateModalPrice();
      });
    });
    updateModalPrice();
  }).catch(function() {
    var section = document.getElementById('im-mods-section');
    if (section) section.innerHTML = '';
  });
}

// ── Descuento global ───────────────────────────────────
function abrirDescuentoGlobal() {
  var curr = state.descuento || { tipo: 'porcentaje', valor: 0 };
  var modal = document.getElementById('modal-content');
  modal.innerHTML = '<h3>Descuento global</h3>'
    + '<div>'
    + '<label style="display:block;margin-bottom:6px">Tipo y valor</label>'
    + '<div class="item-disc-row">'
    + '<div class="disc-toggle">'
    + '<button id="gd-pct" class="' + (curr.tipo === 'porcentaje' ? 'active' : '') + '">%</button>'
    + '<button id="gd-mnt" class="' + (curr.tipo === 'monto' ? 'active' : '') + '">S/</button>'
    + '</div>'
    + '<input class="disc-input" type="text" inputmode="decimal" id="gd-val" placeholder="0" value="' + (curr.valor > 0 ? curr.valor : '') + '">'
    + '</div>'
    + '</div>'
    + '<div class="modal-row">'
    + '<button class="btn-modal-cancel" id="gd-clear">Quitar</button>'
    + '<button class="btn-modal-cancel" id="gd-cancel">Cancelar</button>'
    + '<button class="btn-modal-ok" id="gd-ok">Aplicar</button>'
    + '</div>';
  document.getElementById('overlay').classList.add('active');
  var gdTipo = curr.tipo;
  document.getElementById('gd-pct').addEventListener('click', function() {
    gdTipo = 'porcentaje';
    document.getElementById('gd-pct').classList.add('active');
    document.getElementById('gd-mnt').classList.remove('active');
  });
  document.getElementById('gd-mnt').addEventListener('click', function() {
    gdTipo = 'monto';
    document.getElementById('gd-mnt').classList.add('active');
    document.getElementById('gd-pct').classList.remove('active');
  });
  document.getElementById('gd-clear').addEventListener('click', function() {
    state.descuento = null;
    renderCart();
    closeModal();
  });
  document.getElementById('gd-cancel').addEventListener('click', closeModal);
  document.getElementById('gd-ok').addEventListener('click', function() {
    var v = parseFloat(document.getElementById('gd-val').value) || 0;
    if (v > 0) {
      state.descuento = { tipo: gdTipo, valor: v };
    } else {
      state.descuento = null;
    }
    renderCart();
    closeModal();
  });
}

// ── Nota general ───────────────────────────────────────
function abrirNotaGeneral() {
  var modal = document.getElementById('modal-content');
  modal.innerHTML = '<h3>Nota general del pedido</h3>'
    + '<textarea class="nota-general-area" id="nota-gen-input" placeholder="Instrucciones especiales para todo el pedido…" maxlength="500">' + esc(state.notas) + '</textarea>'
    + '<div class="modal-row">'
    + '<button class="btn-modal-cancel" id="ng-cancel">Cancelar</button>'
    + '<button class="btn-modal-ok" id="ng-ok">Guardar</button>'
    + '</div>';
  document.getElementById('overlay').classList.add('active');
  document.getElementById('nota-gen-input').focus();
  document.getElementById('ng-cancel').addEventListener('click', closeModal);
  document.getElementById('ng-ok').addEventListener('click', function() {
    state.notas = document.getElementById('nota-gen-input').value.trim();
    renderCart();
    closeModal();
  });
}

// ── Cobrar ─────────────────────────────────────────────
function cobrar() {
  if (!state.cart.length) return;
  var metodo = activeMetodo();
  if (!metodo) { toast('Selecciona un método de pago', 'err'); return; }
  showModalCobro(metodo);
}

function showModalCobro(metodo) {
  var total = cartTotal();
  var isEfectivo = (metodo.tipo === 'efectivo');
  var modal = document.getElementById('modal-content');

  modal.innerHTML = '<h3>Cobrar ' + esc(fmt(total)) + '</h3>'
    // Comprobante selector
    + '<div>'
    + '<label style="display:block;margin-bottom:6px">Comprobante</label>'
    + '<div class="comp-tabs">'
    + '<button class="comp-tab active" data-tipo="ticket">Ticket</button>'
    + '<button class="comp-tab" data-tipo="boleta">Boleta</button>'
    + '<button class="comp-tab" data-tipo="factura">Factura</button>'
    + '</div>'
    + '</div>'
    // Cliente (hidden for ticket)
    + '<div id="cobro-cliente" style="display:none">'
    + '<label style="display:block;margin-bottom:6px">Datos del cliente</label>'
    + '<div class="cliente-fields">'
    + '<div class="cf-row">'
    + '<select id="cl-tipo" style="flex:0 0 80px"><option value="dni">DNI</option><option value="ruc">RUC</option></select>'
    + '<input type="text" id="cl-doc" placeholder="Número de documento" inputmode="numeric" maxlength="11">'
    + '<button class="btn-buscar-cliente" disabled title="Próximamente (RENIEC/SUNAT)">Buscar</button>'
    + '</div>'
    + '<input type="text" id="cl-nombre" placeholder="Nombre / Razón social">'
    + '</div>'
    + '</div>'
    // Efectivo fields (conditionally shown)
    + (isEfectivo
        ? '<div><label>Monto recibido (S/)</label>'
          + '<input type="number" id="modal-recibido" min="0" step="0.10" value="' + total.toFixed(2) + '" inputmode="decimal"></div>'
          + '<div class="vuelto-box" id="modal-vuelto-box"><span>Vuelto</span><span id="modal-vuelto">' + fmt(0) + '</span></div>'
        : '')
    + '<div class="modal-row">'
    + '<button class="btn-modal-cancel" id="modal-cancel">Cancelar</button>'
    + '<button class="btn-modal-ok" id="modal-confirm">Confirmar cobro</button>'
    + '</div>';

  document.getElementById('overlay').classList.add('active');

  var comprobanteTipo = 'ticket';

  // Comprobante tab switching
  modal.querySelectorAll('.comp-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      modal.querySelectorAll('.comp-tab').forEach(function(t) { t.classList.remove('active'); });
      this.classList.add('active');
      comprobanteTipo = this.dataset.tipo;
      var clienteDiv = document.getElementById('cobro-cliente');
      if (comprobanteTipo === 'boleta' || comprobanteTipo === 'factura') {
        clienteDiv.style.display = 'block';
        var clTipo = document.getElementById('cl-tipo');
        if (comprobanteTipo === 'factura' && clTipo) {
          clTipo.value = 'ruc';
        } else if (clTipo) {
          clTipo.value = 'dni';
        }
      } else {
        clienteDiv.style.display = 'none';
      }
    });
  });

  if (isEfectivo) {
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
  }

  document.getElementById('modal-cancel').addEventListener('click', closeModal);
  document.getElementById('modal-confirm').addEventListener('click', function() {
    var extraData = { comprobante_tipo: comprobanteTipo };
    if (comprobanteTipo === 'boleta' || comprobanteTipo === 'factura') {
      extraData.cliente_tipo = document.getElementById('cl-tipo').value;
      extraData.cliente_documento = document.getElementById('cl-doc').value.trim();
      extraData.cliente_nombre = document.getElementById('cl-nombre').value.trim();
    }
    if (comprobanteTipo === 'factura') {
      extraData.cliente_razon_social = document.getElementById('cl-nombre').value.trim();
    }
    closeModal();
    confirmarVenta(metodo, extraData);
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

function confirmarVenta(metodo, extraData) {
  var total = cartTotal();
  var btn = document.getElementById('btn-cobrar');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>';
  var payload = {
    ubicacion_id: state.ubicacionId,
    turno_id: state.turno.id,
    metodo_pago: metodo.nombre,
    total: total.toFixed(2),
    items: JSON.stringify(state.cart),
    notas_pos: state.notas || ''
  };
  if (state.descuento) {
    payload.descuento_tipo = state.descuento.tipo;
    payload.descuento_valor = state.descuento.valor;
  }
  if (extraData) {
    Object.keys(extraData).forEach(function(k) {
      payload[k] = extraData[k];
    });
  }
  apiPost('registrar_venta', payload).then(function(res) {
    btn.disabled = false;
    renderCobrarBtn();
    if (!res.ok) { toast('Error al registrar venta: ' + (res.error || ''), 'err'); return; }
    state.cart = [];
    state.descuento = null;
    state.notas = '';
    renderCart();
    refreshTurno();
    showPrintSnack(res.id);
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
  closeHistorialPanel();
  state.cajaOpen = true;
  renderCajaDetalle();
  document.getElementById('panel-caja').classList.add('active');
}

// ── Panel historial ────────────────────────────────────
function toggleHistorialPanel() {
  state.histOpen = !state.histOpen;
  if (state.histOpen) openHistorialPanel(); else closeHistorialPanel();
}
function openHistorialPanel() {
  if (!state.turno) return;
  closeCajaPanel();
  state.histOpen = true;
  document.getElementById('panel-historial').classList.add('active');
  loadHistorial();
}
function closeHistorialPanel() {
  state.histOpen = false;
  var p = document.getElementById('panel-historial');
  if (p) p.classList.remove('active');
  if (document.getElementById('nav-historial').classList.contains('active')) {
    setNavActive('nav-vender');
  }
}
function loadHistorial() {
  if (!state.turno) return;
  document.getElementById('historial-lista').innerHTML = '<div style="color:var(--text2);font-size:13px;text-align:center;padding:20px">Cargando…</div>';
  apiGet('historial_turno', { turno_id: state.turno.id }).then(function(res) {
    if (!res.ok) return;
    renderHistorial(res.ventas || []);
  }).catch(function() {
    document.getElementById('historial-lista').innerHTML = '<div style="color:var(--red);font-size:13px;text-align:center;padding:20px">Error al cargar</div>';
  });
}
function renderHistorial(ventas) {
  var box = document.getElementById('historial-lista');
  if (!ventas.length) {
    box.innerHTML = '<div style="color:var(--text2);font-size:13px;text-align:center;padding:20px">Sin ventas en este turno.</div>';
    return;
  }
  box.innerHTML = ventas.map(function(v) {
    var hora = (String(v.created_at || '').substr(11, 5)) || '';
    var comp;
    if (v.comprobante_tipo === 'boleta' || v.comprobante_tipo === 'factura') {
      var lbl = v.comprobante_tipo === 'factura' ? 'Factura' : 'Boleta';
      if (v.comprobante_estado === 'emitido') comp = '<span class="hist-comp emitido">' + lbl + ' ' + esc(v.comprobante_serie + '-' + v.comprobante_numero) + '</span>';
      else if (v.comprobante_estado === 'error') comp = '<span class="hist-comp err">' + lbl + ' · error</span>';
      else comp = '<span class="hist-comp pend">' + lbl + ' · pendiente</span>';
    } else {
      comp = '<span class="hist-comp ticket">Ticket</span>';
    }
    var pdf = (v.comprobante_estado === 'emitido' && v.comprobante_pdf) ? '<a href="' + esc(v.comprobante_pdf) + '" target="_blank">PDF</a>' : '';
    return '<div class="hist-row">'
      + '<div class="hist-info">'
      + '<div class="hist-top"><span class="hist-id">#' + v.id + '</span><span class="hist-hora">' + esc(hora) + '</span></div>'
      + '<div class="hist-meta">' + esc(v.metodo_pago || '') + ' ' + comp + '</div>'
      + '</div>'
      + '<span class="hist-total">' + fmt(v.total) + '</span>'
      + '<div class="hist-actions">'
      + '<button onclick="printRawBT(' + v.id + ')">Imprimir</button>'
      + '<button onclick="showTicketModal(' + v.id + ')">Ver</button>'
      + pdf
      + '</div></div>';
  }).join('');
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
  var t = state.turno;
  var cajaInicial   = parseFloat(t.monto_inicial) || 0;
  var ventasEfectivo = parseFloat(t.total_efectivo) || 0;

  // Local modal state
  var gastos = []; // [{concepto:'', monto:0}, ...]

  function calcCajaEsperada(ingreso) {
    var gastosTot = gastos.reduce(function(a, g) { return a + (parseFloat(g.monto) || 0); }, 0);
    return cajaInicial + (parseFloat(ingreso) || 0) + ventasEfectivo - gastosTot;
  }

  function recompute() {
    var ingreso   = parseFloat(document.getElementById('aq-ingreso').value) || 0;
    var cajaReal  = parseFloat(document.getElementById('aq-caja-real').value) || 0;
    // sync gastos from DOM
    document.querySelectorAll('.aq-gasto-row').forEach(function(row, i) {
      if (!gastos[i]) gastos[i] = { concepto: '', monto: 0 };
      gastos[i].concepto = row.querySelector('.aq-concepto').value.trim();
      gastos[i].monto    = parseFloat(row.querySelector('.aq-monto').value) || 0;
    });
    var esperada   = calcCajaEsperada(ingreso);
    var diferencia = esperada - cajaReal;
    var difEl = document.getElementById('aq-diferencia');
    var sign = diferencia > 0 ? '+' : '';
    difEl.textContent = sign + fmt(diferencia);
    var rounded = Math.round(diferencia * 100);
    difEl.style.color = rounded === 0 ? '#4ade80' : (diferencia < 0 ? '#f87171' : '#fbbf24');
    document.getElementById('aq-esperada').textContent = fmt(esperada);
  }

  function renderGastoRows() {
    var container = document.getElementById('aq-gastos-list');
    container.innerHTML = gastos.map(function(g, i) {
      return '<div class="aq-gasto-row" data-idx="' + i + '" style="display:flex;gap:6px;align-items:center;margin-bottom:6px">'
        + '<input class="aq-concepto" type="text" placeholder="Concepto" maxlength="60"'
        + ' style="flex:2;padding:7px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface2);color:var(--text);font-size:13px"'
        + ' value="' + esc(g.concepto) + '">'
        + '<input class="aq-monto" type="number" placeholder="0.00" min="0" step="0.01"'
        + ' style="flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface2);color:var(--text);font-size:13px;-moz-appearance:textfield"'
        + ' value="' + (g.monto > 0 ? g.monto : '') + '">'
        + '<button class="aq-del-gasto" data-idx="' + i + '"'
        + ' style="flex:0 0 28px;width:28px;height:28px;border-radius:50%;background:rgba(200,16,46,.2);color:#f87171;font-size:16px;display:flex;align-items:center;justify-content:center;border:none;cursor:pointer">✕</button>'
        + '</div>';
    }).join('');
    attachGastoEvents();
    recompute();
  }

  function attachGastoEvents() {
    document.querySelectorAll('.aq-gasto-row input').forEach(function(inp) {
      inp.addEventListener('input', recompute);
    });
    document.querySelectorAll('.aq-del-gasto').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var idx = parseInt(this.dataset.idx, 10);
        gastos.splice(idx, 1);
        renderGastoRows();
      });
    });
  }

  var modal = document.getElementById('modal-content');
  modal.style.maxWidth = '480px';
  modal.innerHTML = '<h3>Arqueo de caja</h3>'

    // Caja inicial (read-only)
    + '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm)">'
    + '<span style="font-size:13px;color:var(--text2)">Caja inicial</span>'
    + '<span style="font-size:15px;font-weight:700">' + esc(fmt(cajaInicial)) + '</span>'
    + '</div>'

    // Ventas en efectivo (read-only)
    + '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm)">'
    + '<span style="font-size:13px;color:var(--text2)">Ventas en efectivo</span>'
    + '<span style="font-size:15px;font-weight:700;color:#4ade80">' + esc(fmt(ventasEfectivo)) + '</span>'
    + '</div>'
    + '<p style="font-size:11px;color:var(--muted);margin-top:-8px">Tarjeta y Yape/QR no entran a la caja física</p>'

    // Ingreso de efectivo
    + '<div>'
    + '<label style="display:block;margin-bottom:6px">Ingreso de efectivo (extra)</label>'
    + '<input type="number" id="aq-ingreso" min="0" step="0.01" value="0" inputmode="decimal"'
    + ' placeholder="0.00" style="-moz-appearance:textfield">'
    + '</div>'

    // Gastos varios
    + '<div>'
    + '<label style="display:block;margin-bottom:6px">Gastos varios</label>'
    + '<div id="aq-gastos-list"></div>'
    + '<button id="aq-add-gasto"'
    + ' style="width:100%;padding:8px;border-radius:var(--radius-sm);background:var(--surface2);border:1px solid var(--border);color:var(--text2);font-size:13px;font-weight:600;cursor:pointer;transition:color .1s">'
    + '+ Agregar gasto</button>'
    + '</div>'

    // Caja esperada (computed, read-only)
    + '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm)">'
    + '<span style="font-size:13px;color:var(--text2)">Caja esperada</span>'
    + '<span id="aq-esperada" style="font-size:16px;font-weight:800">' + esc(fmt(cajaInicial + ventasEfectivo)) + '</span>'
    + '</div>'

    // Caja real (cashier counts)
    + '<div>'
    + '<label style="display:block;margin-bottom:6px">Caja real (contada)</label>'
    + '<input type="number" id="aq-caja-real" min="0" step="0.01" value="0" inputmode="decimal"'
    + ' placeholder="0.00" style="-moz-appearance:textfield">'
    + '</div>'

    // Diferencia (computed)
    + '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm)">'
    + '<span style="font-size:13px;color:var(--text2)">Diferencia</span>'
    + '<span id="aq-diferencia" style="font-size:18px;font-weight:800;color:#fbbf24">+' + esc(fmt(cajaInicial + ventasEfectivo)) + '</span>'
    + '</div>'

    + '<div class="modal-row">'
    + '<button class="btn-modal-cancel" id="aq-cancel">Cancelar</button>'
    + '<button class="btn-modal-ok" id="aq-confirm" style="background:var(--red);color:#fff">Cerrar turno</button>'
    + '</div>';

  document.getElementById('overlay').classList.add('active');

  // Wire events
  document.getElementById('aq-ingreso').addEventListener('input', recompute);
  document.getElementById('aq-caja-real').addEventListener('input', recompute);
  document.getElementById('aq-add-gasto').addEventListener('click', function() {
    gastos.push({ concepto: '', monto: 0 });
    renderGastoRows();
  });
  document.getElementById('aq-cancel').addEventListener('click', function() {
    modal.style.maxWidth = '';
    closeModal();
  });
  document.getElementById('aq-confirm').addEventListener('click', function() {
    // sync final gastos from DOM
    document.querySelectorAll('.aq-gasto-row').forEach(function(row, i) {
      if (!gastos[i]) gastos[i] = { concepto: '', monto: 0 };
      gastos[i].concepto = row.querySelector('.aq-concepto').value.trim();
      gastos[i].monto    = parseFloat(row.querySelector('.aq-monto').value) || 0;
    });
    var ingreso  = parseFloat(document.getElementById('aq-ingreso').value) || 0;
    var cajaReal = parseFloat(document.getElementById('aq-caja-real').value) || 0;
    modal.style.maxWidth = '';
    closeModal();
    cerrarTurno(cajaReal, ingreso, gastos);
  });

  // Initial render (empty gastos list)
  renderGastoRows();
  recompute();
  document.getElementById('aq-caja-real').focus();
}

function cerrarTurno(cajaReal, ingreso, gastos) {
  apiPost('cerrar_turno', {
    turno_id: state.turno.id,
    caja_real: cajaReal.toFixed(2),
    ingreso_efectivo: ingreso.toFixed(2),
    gastos: JSON.stringify(gastos.filter(function(g) { return g.monto > 0 || g.concepto !== ''; }))
  }).then(function(res) {
    if (!res.ok) { toast('Error al cerrar turno: ' + (res.error || ''), 'err'); return; }
    state.turno = null;
    state.cart = [];
    state.productos = [];
    state.metodos = [];
    state.activeCat = 'Todos';
    state.cajaOpen = false;
    state.descuento = null;
    state.notas = '';
    state.favMap = {};
    state.favEditMode = false;
    hideFavBoard();
    closeCajaPanel();
    disableNavCaja(true);
    updateCajaEstado(null);
    toast('Turno cerrado', 'ok');
    var ubiName = (UBIS.find(function(u) { return u.id == state.ubicacionId; }) || {}).nombre || '';
    document.getElementById('open-ubi-name').textContent = ubiName;
    document.getElementById('input-monto-inicial').value = '0';
    showScreen('open');
    setNavActive('nav-vender');
  }).catch(function() { toast('Error de red', 'err'); });
}

// ── RawBT thermal print ────────────────────────────────
function printRawBT(ventaId) {
  fetch(ESCPOS_BASE + '?id=' + ventaId, { credentials: 'same-origin' })
    .then(function(r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    })
    .then(function(b64) {
      b64 = b64.trim();
      if (!b64) throw new Error('empty');
      // Si RawBT abre, la página pierde visibilidad/foco. Si NO abre (RawBT no
      // instalado / nada maneja el esquema rawbt:), avisamos al cajero.
      var opened = false;
      var onHide = function() { if (document.hidden) opened = true; };
      var onBlur = function() { opened = true; };
      document.addEventListener('visibilitychange', onHide);
      window.addEventListener('blur', onBlur);
      window.location.href = 'rawbt:base64,' + b64;
      setTimeout(function() {
        document.removeEventListener('visibilitychange', onHide);
        window.removeEventListener('blur', onBlur);
        if (!opened) toast('No hay impresora conectada', 'err');
      }, 2000);
    })
    .catch(function() {
      toast('No se pudo imprimir', 'err');
    });
}

// ── Print snackbar ─────────────────────────────────────
var _printSnackTimer = null;
function showPrintSnack(ventaId) {
  var snack    = document.getElementById('print-snack');
  var msg      = document.getElementById('print-snack-msg');
  var rawbtBtn = document.getElementById('print-snack-rawbt-btn');
  var viewBtn  = document.getElementById('print-snack-view-btn');
  var emailBtn = document.getElementById('print-snack-email-btn');
  var close    = document.getElementById('print-snack-close');

  msg.textContent = 'Venta #' + ventaId + ' registrada';
  snack.classList.add('show');
  if (_printSnackTimer) clearTimeout(_printSnackTimer);
  _printSnackTimer = setTimeout(function() { snack.classList.remove('show'); }, 10000);

  rawbtBtn.onclick = function() {
    printRawBT(ventaId);
    snack.classList.remove('show');
    if (_printSnackTimer) clearTimeout(_printSnackTimer);
  };
  viewBtn.onclick = function() {
    snack.classList.remove('show');
    if (_printSnackTimer) clearTimeout(_printSnackTimer);
    showTicketModal(ventaId);
  };
  emailBtn.onclick = function() {
    snack.classList.remove('show');
    if (_printSnackTimer) clearTimeout(_printSnackTimer);
    showEmailModal(ventaId);
  };
  close.onclick = function() {
    snack.classList.remove('show');
    if (_printSnackTimer) clearTimeout(_printSnackTimer);
  };
}

// ── Ticket embebido en modal ───────────────────────────
function showTicketModal(ventaId) {
  var modal = document.getElementById('modal-content');
  modal.innerHTML = '<h3>Ticket · Pedido #' + esc(String(ventaId)) + '</h3>'
    + '<div class="ticket-embed-wrap"><iframe id="ticket-frame" src="' + esc(TICKET_BASE) + '?id=' + ventaId + '&embed=1" title="Ticket"></iframe></div>'
    + '<div class="modal-row">'
    + '<button class="btn-modal-cancel" id="tk-close">Cerrar</button>'
    + '<button class="btn-modal-cancel" id="tk-email">Correo</button>'
    + '<button class="btn-modal-ok" id="tk-print" style="background:var(--green);color:#000">Imprimir</button>'
    + '</div>';
  document.getElementById('overlay').classList.add('active');
  var fr = document.getElementById('ticket-frame');
  fr.onload = function() {
    try {
      var h = fr.contentDocument.body.scrollHeight;
      if (h > 0) fr.style.height = (h + 6) + 'px';
    } catch (e) {}
  };
  document.getElementById('tk-close').onclick = closeModal;
  document.getElementById('tk-email').onclick = function() { closeModal(); showEmailModal(ventaId); };
  document.getElementById('tk-print').onclick = function() { printRawBT(ventaId); };
}

// ── Email receipt modal ────────────────────────────────
function showEmailModal(ventaId) {
  var modal = document.getElementById('modal-content');
  modal.innerHTML = '<h3>Enviar recibo por correo</h3>'
    + '<p>Pedido #' + esc(String(ventaId)) + '</p>'
    + '<div>'
    + '<label style="display:block;margin-bottom:6px">Correo electrónico</label>'
    + '<input type="email" id="email-input" inputmode="email" autocomplete="email" placeholder="cliente@ejemplo.com"'
    + ' style="width:100%;padding:11px 13px;border:1px solid var(--border);border-radius:var(--radius);'
    + 'background:var(--surface2);color:var(--text);font-size:15px;">'
    + '</div>'
    + '<div class="modal-row">'
    + '<button class="btn-modal-cancel" id="email-cancel">Cancelar</button>'
    + '<button class="btn-modal-ok" id="email-send">Enviar</button>'
    + '</div>';
  document.getElementById('overlay').classList.add('active');
  var inp = document.getElementById('email-input');
  inp.focus();
  inp.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('email-send').click(); }
  });
  document.getElementById('email-cancel').addEventListener('click', closeModal);
  document.getElementById('email-send').addEventListener('click', function() {
    var email = inp.value.trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      inp.style.borderColor = 'var(--red)';
      inp.focus();
      return;
    }
    var sendBtn = document.getElementById('email-send');
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<span class="spin" style="border-top-color:#000"></span>';
    apiPost('enviar_recibo', { pedido_id: ventaId, email: email }).then(function(res) {
      closeModal();
      if (res.ok) {
        toast('Recibo enviado', 'ok');
      } else {
        toast('Error: ' + (res.error || 'No se pudo enviar'), 'err');
      }
    }).catch(function() {
      closeModal();
      toast('Error de red', 'err');
    });
  });
}

// ── Pantalla completa ──────────────────────────────────
var FS_EXPAND   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M16 21h3a2 2 0 0 0 2-2v-3M8 21H5a2 2 0 0 1-2-2v-3"/></svg>';
var FS_COMPRESS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M16 21v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/></svg>';
function isFullscreen() { return !!(document.fullscreenElement || document.webkitFullscreenElement); }
function toggleFullscreen() {
  var el = document.documentElement;
  if (!isFullscreen()) {
    var req = el.requestFullscreen || el.webkitRequestFullscreen;
    if (req) req.call(el);
  } else {
    var exit = document.exitFullscreen || document.webkitExitFullscreen;
    if (exit) exit.call(document);
  }
}
function updateFsIcon() {
  var b = document.getElementById('btn-fullscreen');
  if (b) b.innerHTML = isFullscreen() ? FS_COMPRESS : FS_EXPAND;
}
(function initFullscreen() {
  var btn = document.getElementById('btn-fullscreen');
  if (!btn) return;
  var el = document.documentElement;
  if (!(el.requestFullscreen || el.webkitRequestFullscreen)) { btn.style.display = 'none'; return; } // iPhone: sin Fullscreen API
  btn.addEventListener('click', toggleFullscreen);
  document.addEventListener('fullscreenchange', updateFsIcon);
  document.addEventListener('webkitfullscreenchange', updateFsIcon);
  updateFsIcon();
})();

// ── Boot ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', init);
</script>
<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('<?= APP_URL ?>/sw.js').catch(function(){});}</script>
</body>
</html>
