<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$slug  = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['slug'] ?? '');
$ubi   = $slug ? Database::fetch("SELECT * FROM ubicaciones WHERE slug = ? AND activa = 1", [$slug]) : null;
if (!$ubi) { http_response_code(404); echo 'Carta no encontrada.'; exit; }
$ubiId   = (int) $ubi['id'];
$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';
$ig      = ltrim($ubi['instagram'] ?? '', '@');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <link rel="icon" type="image/png" href="/img/favicon.png">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#FCDA13">
  <title>El Gringo Burger Joint · Carta</title>
  <style>
    @font-face {
      font-family: 'Kimmy';
      src: url('/marcona/fonts/Kimmy.woff2') format('woff2');
      font-display: swap;
    }

    @font-face {
      font-family: 'ArialNarrowBold';
      src: url('/marcona/fonts/Arial_Narrow_Bold.ttf') format('truetype');
      font-display: swap;
    }

    :root {
      --yellow: #FCDA13;
      --dark:   #1A1A1A;
      --card:   #242424;
      --sheet:  #1e1e1e;
      --text:   #FFFFFF;
      --muted:  #999999;
      --dim:    #666666;
      --pink:   #FAB8C0;
      --border: rgba(255,255,255,0.08);
      --font:   -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    html { scroll-behavior: smooth; }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--dark); color: var(--text);
      font-family: var(--font); font-size: 15px; line-height: 1.5;
      min-height: 100dvh; -webkit-font-smoothing: antialiased;
    }

    /* HEADER */
    header {
      background: var(--yellow); padding: 12px 20px;
      display: flex; align-items: center; gap: 12px;
      position: sticky; top: 0; z-index: 100;
    }
    .logo { height: 40px; width: auto; object-fit: contain; filter: brightness(0); }
    .schedule-badge {
      font-size: 11px; font-weight: 700;
      padding: 4px 10px; border-radius: 999px;
      background: rgba(0,0,0,0.12); color: #1A1A1A; letter-spacing: 0.03em;
      display: flex; align-items: center; gap: 6px;
    }
    .schedule-dot {
      position: relative; width: 8px; height: 8px; border-radius: 50%;
      background: #777; flex-shrink: 0;
    }
    .schedule-badge.open   .schedule-dot { background: #16a34a; }
    .schedule-badge.closed .schedule-dot { background: #dc2626; }
    .schedule-dot::after {
      content: ''; position: absolute; inset: 0; border-radius: 50%;
      background: inherit; animation: dotPulse 1.8s ease-out infinite;
    }
    @keyframes dotPulse {
      0%   { transform: scale(1);   opacity: .65; }
      70%  { transform: scale(2.8); opacity: 0; }
      100% { opacity: 0; }
    }
    .ig-link { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; color: #1A1A1A; font-size: 13px; font-weight: 700; }
    .ig-link svg { width: 18px; height: 18px; fill: #1A1A1A; }

    /* PAGE LAYOUT */
    .page-wrap { display: flex; max-width: 1100px; margin: 0 auto; }

    /* CARTA COLUMN */
    .carta-col { flex: 1; min-width: 0; padding: 16px 16px 80px; }
    @media (min-width: 900px) { .carta-col { padding: 24px 24px 60px; } }

    /* LOADING */
    .loading { text-align: center; padding: 60px 20px; color: var(--muted); }
    .spinner {
      width: 28px; height: 28px;
      border: 2.5px solid rgba(255,255,255,.1); border-top-color: var(--yellow);
      border-radius: 50%; animation: spin .7s linear infinite; margin: 0 auto 14px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* SECTION */
    .section { margin-bottom: 32px; }
    .section-title {
      font-family: 'ArialNarrowBold', 'Arial Narrow', Arial, sans-serif; font-size: 14px; font-weight: 700; color: var(--text);
      text-transform: uppercase; letter-spacing: 3px;
      margin-bottom: 12px;
      padding-bottom: 10px; border-bottom: 1px solid var(--border);
    }

    /* ITEM CARD */
    .item {
      display: flex; align-items: center; gap: 12px;
      background: var(--card); border-radius: 12px; padding: 12px;
      margin-bottom: 8px; cursor: pointer;
      transition: background .15s, transform .15s cubic-bezier(.23,1,.32,1);
      opacity: 0; transform: translateY(8px);
      animation: fadeUp .4s cubic-bezier(.16,1,.3,1) forwards;
    }
    .item:last-child { margin-bottom: 0; }
    .item:hover { background: #2c2c2c; transform: translateY(-1px); }
    .item:active { transform: scale(0.99); background: #303030; }
    @keyframes fadeUp { to { opacity: 1; transform: translateY(0); } }

    .item-foto {
      width: 90px; height: 90px; border-radius: 8px;
      object-fit: cover; background: #2a2a2a;
    }
    .item-info { flex: 1; min-width: 0; }
    .item-name { font-family: 'ArialNarrowBold', 'Arial Narrow', Arial, sans-serif; font-size: 22px; font-weight: 700; color: var(--text); text-transform: uppercase; letter-spacing: 1.5px; line-height: 1.2; margin-bottom: 3px; }
    .item-desc {
      font-size: 15px; color: var(--muted); line-height: 1.4;
      overflow: hidden; display: -webkit-box;
      -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    }
    .item-right {
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .item-price {
      font-family: 'ArialNarrowBold', 'Arial Narrow', Arial, sans-serif;
      font-size: 18px; font-weight: 700;
      background: #FCDA13; color: #1A1A1A;
      padding: 5px 16px; border-radius: 20px;
      white-space: nowrap;
    }
    .item-chevron { display: none; }

    .item-badge {
      display: inline-flex; align-items: center;
      padding: 2px 8px; border-radius: 999px;
      font-size: 10px; font-weight: 700;
      text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px;
    }
    .item-badge.popular     { background: rgba(252,218,19,.15); color: var(--yellow); }
    .item-badge.nuevo       { background: rgba(52,211,153,.15); color: #34d399; }
    .item-badge.recomendado { background: rgba(250,184,192,.2);  color: var(--pink); }

    /* BOTTOM BAR */
    .bottom-bar {
      background: #111;
      text-align: center; padding: 12px 20px;
      font-size: 11px; color: #444;
      letter-spacing: 1.5px; text-transform: uppercase;
      border-top: 1px solid rgba(255,255,255,0.04);
    }
    .bottom-bar em { color: var(--yellow); font-style: normal; }

    /* DETAIL OVERLAY */
    .detail-overlay {
      position: fixed; inset: 0;
      z-index: 200;
      display: flex; align-items: flex-end; justify-content: center;
      visibility: hidden; pointer-events: none;
      background: rgba(0,0,0,0);
      transition: background .25s;
    }
    .detail-overlay.open {
      background: rgba(0,0,0,0.72);
      visibility: visible; pointer-events: auto;
    }

    /* SHEET */
    .detail-sheet {
      background: var(--sheet);
      border-radius: 20px 20px 0 0;
      width: 100%; max-width: 600px;
      max-height: 90dvh;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      transform: translateY(100%);
      transition: transform .35s cubic-bezier(0.32, 0.72, 0, 1);
      position: relative;
    }
    .detail-overlay.open .detail-sheet { transform: translateY(0); }

    @media (min-width: 700px) {
      .detail-overlay { align-items: center; }
      .detail-sheet {
        border-radius: 20px;
        max-width: 480px;
        max-height: 85dvh;
        transform: scale(0.94) translateY(16px);
        opacity: 0;
        transition: transform .25s cubic-bezier(0.23,1,.32,1), opacity .2s;
      }
      .detail-overlay.open .detail-sheet {
        transform: scale(1) translateY(0);
        opacity: 1;
      }
    }

    /* SHEET TOP BAR */
    .detail-top {
      position: sticky; top: 0; z-index: 10;
      background: var(--sheet);
      display: flex; align-items: center; justify-content: center;
      padding: 10px 14px 6px;
    }
    .detail-handle {
      width: 36px; height: 4px; border-radius: 2px;
      background: rgba(255,255,255,0.18);
      flex: 1; max-width: 36px; margin: 0 auto;
    }
    .detail-close {
      position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
      width: 44px; height: 44px; border-radius: 50%;
      background: rgba(255,255,255,0.1); border: none;
      color: var(--text); font-size: 20px; line-height: 1;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      transition: background .15s;
    }
    .detail-close:hover { background: rgba(255,255,255,0.18); }
    @media (min-width: 700px) { .detail-handle { display: none; } }

    /* SHEET FOTO */
    .detail-img {
      width: 100%; aspect-ratio: 4/3; height: auto;
      object-fit: cover; display: block;
    }
    .detail-img-placeholder {
      width: 100%; aspect-ratio: 4/3;
      background: #2a2a2a;
      display: flex; align-items: center; justify-content: center;
    }

    /* SHEET BODY */
    .detail-body { padding: 18px 20px 36px; }

    .detail-badge {
      display: inline-flex; align-items: center;
      padding: 3px 10px; border-radius: 999px;
      font-size: 10px; font-weight: 700;
      text-transform: uppercase; letter-spacing: .05em; margin-bottom: 10px;
    }
    .detail-badge.popular     { background: rgba(252,218,19,.15); color: var(--yellow); }
    .detail-badge.nuevo       { background: rgba(52,211,153,.15); color: #34d399; }
    .detail-badge.recomendado { background: rgba(250,184,192,.2);  color: var(--pink); }

    .detail-name {
      font-family: 'ArialNarrowBold', 'Arial Narrow', Arial, sans-serif;
      font-size: 26px;
      text-transform: uppercase; letter-spacing: 1.5px;
      line-height: 1.1; color: var(--text);
      margin-bottom: 6px;
    }

    .detail-price-single {
      display: inline-block;
      font-family: 'ArialNarrowBold', 'Arial Narrow', Arial, sans-serif; font-size: 18px; font-weight: 700;
      background: #FCDA13; color: #1A1A1A;
      padding: 6px 18px; border-radius: 20px;
      margin-bottom: 16px;
    }

    .detail-variants {
      margin-bottom: 16px;
      border: 1px solid var(--border);
      border-radius: 10px; overflow: hidden;
    }
    .detail-variant-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 11px 14px;
      border-bottom: 1px solid var(--border);
      font-size: 14px;
    }
    .detail-variant-row:last-child { border-bottom: none; }
    .detail-variant-name { color: var(--text); font-weight: 600; }
    .detail-variant-price { color: var(--yellow); font-weight: 800; }

    .detail-desc {
      font-size: 15px; color: #aaa;
      line-height: 1.6; margin-bottom: 14px;
    }

    /* MODIFIER GROUPS */
    .mods-section { margin: 0 0 18px; }
    .mods-group-label {
      font-size: 11px; font-weight: 700; color: #FCDA13;
      text-transform: uppercase; letter-spacing: .08em;
      margin-bottom: 8px; display: flex; align-items: center; gap: 6px;
    }
    .mods-required-tag {
      font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
      background: rgba(252,218,19,.12); color: #FCDA13; padding: 2px 6px; border-radius: 999px;
    }
    .mods-count-tag {
      font-size: 10px; font-weight: 600; color: rgba(255,255,255,0.45);
      background: rgba(255,255,255,0.07); padding: 2px 7px; border-radius: 999px; margin-left: auto;
    }
    .mods-group-wrap { margin-bottom: 16px; }
    .mods-group-wrap.mods-error .mods-options { outline: 1.5px solid #e53935; border-radius: 8px; }
    .mods-options { display: flex; flex-wrap: wrap; gap: 8px; }
    .mod-option {
      display: flex; align-items: center; gap: 6px;
      padding: 9px 16px; cursor: pointer;
      background: #2a2a2a; border: 1.5px solid #333;
      border-radius: 10px; transition: all .15s;
      color: #fff; font-size: 14px; font-weight: 600;
      white-space: nowrap;
    }
    .mod-option:active { transform: scale(0.96); }
    .mod-option.selected {
      border-color: #FCDA13;
      background: rgba(252,218,19,.10);
      color: #FCDA13;
    }
    .mod-option-name { font-size: 14px; line-height: 1.2; }
    .mod-option-price { font-size: 12px; font-weight: 700; color: #888; }
    .mod-option.selected .mod-option-price { color: rgba(252,218,19,0.7); }
    .mod-option.mod-disabled { opacity: 0.35; cursor: not-allowed; pointer-events: none; }

    /* MOZO TEXT */

    /* REDUCED MOTION */
    @media (prefers-reduced-motion: reduce) {
      .item { animation: none; opacity: 1; transform: none; }
      .detail-sheet, .detail-overlay { transition: none; }
    }

    /* CATEGORY BAR */
    .category-bar {
      background: #111; position: sticky; z-index: 90;
      display: flex; align-items: center; gap: 8px;
      padding: 0 12px; overflow: hidden;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .cat-pills {
      display: flex; gap: 6px; overflow-x: auto; padding: 10px 0;
      scrollbar-width: none; flex: 1;
    }
    .cat-pills::-webkit-scrollbar { display: none; }
    .cat-pill {
      flex-shrink: 0; padding: 6px 14px; border-radius: 999px;
      font-size: 14px; font-weight: 700; cursor: pointer;
      background: #2a2a2a; color: #888; border: none;
      transition: background .15s, color .15s; letter-spacing: .02em;
      white-space: nowrap;
    }
    .cat-pill:active { transform: scale(.97); }
    .cat-pill.active { background: #FCDA13; color: #1A1A1A; }
    /* SEARCH BAR */
    .search-bar { position: sticky; z-index: 89; background: #1A1A1A; padding: 8px 14px; border-bottom: 1px solid rgba(255,255,255,0.05); }
    .search-bar-inner { display: flex; align-items: center; gap: 10px; background: #242424; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 8px 14px; }
    .search-bar-inner svg { flex-shrink: 0; color: #666; }
    .search-bar-input { flex: 1; background: none; border: none; outline: none; color: #fff; font-size: 14px; font-family: inherit; }
    .search-bar-input::placeholder { color: #555; }
    /* LIKE BUTTON */
    .detail-media-wrap { position: relative; }
    @keyframes likePop { 0%,100% { transform: scale(1); } 50% { transform: scale(1.05); } }
    .like-btn { width: 100%; padding: 8px 16px; border-radius: 20px; background: #2a2a2a; color: #fff; border: 1px solid transparent; font-size: 15px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: border-color .15s, color .15s; margin-top: 16px; letter-spacing: .01em; }
    .like-btn.liked { border-color: #FCDA13; color: #FCDA13; }
    .like-btn:active { opacity: .85; }
    .like-btn.pop { animation: likePop 150ms cubic-bezier(.32,.72,0,1); }
    .like-btn svg { stroke: #fff; fill: none; transition: stroke .15s, fill .15s; flex-shrink: 0; }
    .like-btn.liked svg { stroke: #FCDA13; fill: #FCDA13; }
    .like-count-chip { margin-left: auto; font-size: 12px; color: #666; min-width: 0; }
    .like-btn.liked .like-count-chip { color: rgba(252,218,19,0.7); }
    /* SKELETON */
    @keyframes shimmer {
      0%   { background-position: -200% 0; }
      100% { background-position:  200% 0; }
    }
    .loading { padding: 16px 16px 0; }
    .skel-card {
      display: flex; gap: 12px; background: #242424;
      border-radius: 12px; padding: 14px; margin-bottom: 10px;
    }
    .skel-foto {
      width: 90px; height: 90px; border-radius: 8px; flex-shrink: 0;
      background: linear-gradient(90deg,#2a2a2a 25%,#333 50%,#2a2a2a 75%);
      background-size: 200% 100%; animation: shimmer 1.4s infinite;
    }
    .skel-info { flex:1; display:flex; flex-direction:column; gap:8px; justify-content:center; }
    .skel-name {
      height: 16px; border-radius: 4px; width: 65%;
      background: linear-gradient(90deg,#2a2a2a 25%,#333 50%,#2a2a2a 75%);
      background-size: 200% 100%; animation: shimmer 1.4s infinite;
    }
    .skel-desc {
      height: 12px; border-radius: 4px; width: 45%;
      background: linear-gradient(90deg,#2a2a2a 25%,#333 50%,#2a2a2a 75%);
      background-size: 200% 100%; animation: shimmer 1.4s infinite .12s;
    }
    #carta-content { animation: fadeIn .35s ease; }
    @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
  </style>
</head>
<body>

  <header>
    <img class="logo" src="<?= htmlspecialchars($logoUrl) ?>" alt="El Gringo Burger Joint">
    <div id="schedule-badge" class="schedule-badge"></div>
    <?php if ($ig): ?>
    <a class="ig-link" href="https://www.instagram.com/<?= clean($ig) ?>/" target="_blank" rel="noopener">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
      @<?= clean($ig) ?>
    </a>
    <?php endif; ?>
  </header>

  <div class="category-bar" id="category-bar" style="top:64px">
    <div class="cat-pills" id="cat-pills"></div>
  </div>
  <div class="search-bar" id="search-bar">
    <div class="search-bar-inner">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input class="search-bar-input" id="search-input" type="search" placeholder="Buscar en la carta..." oninput="filtrarProductos(this.value)">
    </div>
  </div>

  <div class="page-wrap">
    <div class="carta-col">
      <div class="loading" id="loading">
    <div class="skel-card"><div class="skel-foto"></div><div class="skel-info"><div class="skel-name"></div><div class="skel-desc"></div></div></div>
    <div class="skel-card"><div class="skel-foto"></div><div class="skel-info"><div class="skel-name"></div><div class="skel-desc"></div></div></div>
    <div class="skel-card"><div class="skel-foto"></div><div class="skel-info"><div class="skel-name"></div><div class="skel-desc"></div></div></div>
  </div>
      <div id="carta-content"></div>
    </div>
  </div>

  <div class="bottom-bar">
    <em>Carta digital</em> &nbsp;·&nbsp; Escanea el QR
  </div>

  <!-- DETAIL DRAWER / MODAL -->
  <div class="detail-overlay" id="detail-overlay" onclick="handleOverlayClick(event)">
    <div class="detail-sheet" id="detail-sheet">

      <div class="detail-top">
        <div class="detail-handle"></div>
        <button class="detail-close" onclick="closeDetail()" aria-label="Cerrar">&#10005;</button>
      </div>

      <div id="detail-media"></div>

      <div class="detail-body">
        <div id="detail-badge-el"></div>
        <div class="detail-name" id="detail-name"></div>
        <div class="detail-desc" id="detail-desc"></div>
        <div id="detail-price-el"></div>
        <div id="detail-modifiers"></div>
        <div id="like-wrap"></div>
      </div>

    </div>
  </div>

  <script>
    const CARTA_ID = <?= (int)$ubiId ?>;
    const ANALYTICS_API = '<?= APP_URL ?>/api/';
    const _prods = {};
    let _closeTimer = null;

    /* SCHEDULE */
    const OPEN_H = <?= (int)($ubi['hora_apertura'] ?? 0) ?>, CLOSE_H = <?= (int)($ubi['hora_cierre'] ?? 0) ?>;
    const CERRADO_MANUAL = <?= !empty($ubi['cerrado_manual']) ? 'true' : 'false' ?>;
    function isStoreOpen() {
      if (CERRADO_MANUAL) return false;
      if (OPEN_H === CLOSE_H) return true;
      const h = new Date().getHours();
      return CLOSE_H > OPEN_H ? (h >= OPEN_H && h < CLOSE_H) : (h >= OPEN_H || h < CLOSE_H);
    }
    function updateScheduleBadge() {
      const badge = document.getElementById('schedule-badge');
      if (!badge) return;
      const open = isStoreOpen();
      badge.className = 'schedule-badge ' + (open ? 'open' : 'closed');
      let txt;
      if (open) {
        const closeLabel = (CLOSE_H % 24 === 0) ? '24:00' : (CLOSE_H + ':00');
        txt = (OPEN_H === CLOSE_H) ? 'Abierto' : ('Abierto · hasta las ' + closeLabel);
      } else {
        txt = CERRADO_MANUAL ? 'Cerrado' : ('Cerrado · abre a las ' + OPEN_H + ':00');
      }
      badge.innerHTML = `<div class="schedule-dot"></div>${txt}`;
    }
    updateScheduleBadge();
    setInterval(updateScheduleBadge, 60000);

    /* HELPERS */
    function badgeTag(badge, cls) {
      if (!badge || badge === 'ninguno') return '';
      return `<span class="${cls} ${badge}">${badge}</span>`;
    }

    function fotoSrc(foto) {
      if (!foto) return null;
      return foto.startsWith('img/') ? '../../' + foto : foto;
    }

    function priceLabel(p) {
      if (p.variantes && p.variantes.length > 0) {
        const min = Math.min(...p.variantes.map(v => parseFloat(v.precio)));
        return `Desde S/${Math.round(min)}`;
      }
      return p.precio ? 'S/' + Math.round(p.precio) : '';
    }

    /* BUILD ITEMS */
    function buildItem(p) {
      _prods[p.id] = p;
      const src = fotoSrc(p.foto);
      return `<div class="item" onclick="openDetail(${p.id})">
        ${src ? `<img class="item-foto" src="${src}" alt="" loading="lazy">` : ''}
        <div class="item-info">
          ${badgeTag(p.badge, 'item-badge')}
          <div class="item-name">${p.nombre}</div>
          ${p.descripcion ? `<div class="item-desc">${p.descripcion}</div>` : ''}
        </div>
        <div class="item-right">
          <span class="item-price">${priceLabel(p)}</span>
          <span class="item-chevron">›</span>
        </div>
      </div>`;
    }

    function buildSeccion(sec) {
      const prods = (sec.productos || []).filter(p => p.activo == 1);
      if (!prods.length) return '';
      const titulo = sec.subtitulo ? sec.nombre + ' — ' + sec.subtitulo : sec.nombre;
      return `<div class="section" id="sec-${sec.id}">
        <div class="section-title">${titulo}</div>
        <div class="section-body">${prods.map(buildItem).join('')}</div>
      </div>`;
    }


    /* CATEGORY BAR */
    function buildCategoryPills(secciones) {
      const bar = document.getElementById('cat-pills');
      if (!bar || !secciones.length) return;
      bar.innerHTML = secciones.map((s, i) =>
        `<button class="cat-pill${i === 0 ? ' active' : ''}" onclick="scrollToSeccion('sec-${s.id}')">${s.nombre}</button>`
      ).join('');
      const pills = [...bar.querySelectorAll('.cat-pill')];
      const io = new IntersectionObserver(entries => {
        entries.forEach(e => {
          if (e.isIntersecting) {
            const idx = secciones.findIndex(s => 'sec-' + s.id === e.target.id);
            if (idx >= 0) {
              pills.forEach(p => p.classList.remove('active'));
              if (pills[idx]) {
                pills[idx].classList.add('active');
                pills[idx].scrollIntoView({ inline: 'nearest', block: 'nearest' });
              }
            }
          }
        });
      }, { rootMargin: '-15% 0px -75% 0px' });
      secciones.forEach(s => { const el = document.getElementById('sec-' + s.id); if (el) io.observe(el); });
      const _sb = document.getElementById('search-bar');
      if (_sb) {
        const _hh = (document.querySelector('header') || {}).offsetHeight || 64;
        const _bh = (document.getElementById('category-bar') || {}).offsetHeight || 48;
        _sb.style.top = (_hh + _bh) + 'px';
      }
    }

    function scrollToSeccion(id) {
      const el = document.getElementById(id);
      if (!el) return;
      const hdr = document.querySelector('header')?.offsetHeight || 56;
      const bar = document.getElementById('category-bar')?.offsetHeight || 48;
      const srch = document.getElementById('search-bar')?.offsetHeight || 0;
      window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - hdr - bar - srch - 8, behavior: 'smooth' });
    }


    function filtrarProductos(q) {
      const query = (q || '').toLowerCase().trim();
      document.querySelectorAll('.section').forEach(sec => {
        let any = false;
        sec.querySelectorAll('.item:not(.item-parent):not(.item-variant), .item-variant, .kids-item').forEach(item => {
          const name = (item.querySelector('.item-name,.item-variant-name,.kids-name')?.textContent || '').toLowerCase();
          const desc = (item.querySelector('.item-desc,.kids-desc')?.textContent || '').toLowerCase();
          const show = !query || name.includes(query) || desc.includes(query);
          item.style.display = show ? '' : 'none';
          if (show) any = true;
        });
        sec.querySelectorAll('.item-parent').forEach(p => { p.style.display = any ? '' : 'none'; });
        sec.style.display = any ? '' : 'none';
      });
    }

    /* LOAD CARTA */
    async function loadCarta() {
      try {
        const res = await fetch('<?= APP_URL ?>/api/carta.php?ubicacion_id=' + CARTA_ID);
        const data = await res.json();
        const secciones = (data.secciones || []).filter(s => s.activo == 1);
        document.getElementById('loading').style.display = 'none';
        document.getElementById('carta-content').innerHTML = secciones.map(buildSeccion).join('');
        buildCategoryPills(secciones);

      } catch(e) {
        document.getElementById('loading').innerHTML =
          '<p style="color:#e53935;text-align:center">Error cargando la carta. Recarga la página.</p>';
      }
    }


    function toggleLike(id) {
      const key = 'like_' + id + '_' + CARTA_ID + '_menu';
      const wasLiked = localStorage.getItem(key) === '1';
      const newLiked = !wasLiked;
      if (newLiked) { localStorage.setItem(key, '1'); } else { localStorage.removeItem(key); }
      const btn = document.getElementById('heart-btn');
      if (btn) {
        btn.classList.toggle('liked', newLiked);
        btn.classList.remove('pop'); void btn.offsetWidth; btn.classList.add('pop');
      }
      fetch('<?= APP_URL ?>/api/carta_analytics.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle_like',producto_id:id,ubicacion_id:CARTA_ID,version:'menu'})})
        .then(r=>r.json()).then(function(d){ if(d.ok){ var el=document.getElementById('heart-count'); if(el) el.textContent=d.total>0?d.total:''; } }).catch(function(){});
    }
    /* DETAIL DRAWER */
    function openDetail(id) {
      const p = _prods[id];
      if (!p) return;

      // Media
      const mediaEl = document.getElementById('detail-media');
      const src = fotoSrc(p.foto);
      const _liked = localStorage.getItem('like_' + id + '_' + CARTA_ID + '_menu') === '1';
      const _hFill = _liked ? '#FCDA13' : 'none'; const _hStroke = _liked ? '#FCDA13' : '#ccc';
      const _photoHTML = src
        ? `<img class="detail-img" src="${src}" alt="${p.nombre.replace(/"/g,'')}" loading="lazy">`
        : `<div class="detail-img-placeholder"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16" stroke-width="2.5" stroke-linecap="round"/><path d="M4 10h16"/><path d="M4 14h16"/><path d="M6 18h12a2 2 0 0 0 2-2v0a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v0a2 2 0 0 0 2 2z"/><path d="M5 6a7 7 0 0 1 14 0"/></svg></div>`;
      mediaEl.innerHTML = `<div class="detail-media-wrap">${_photoHTML}</div>`;
      fetch('<?= APP_URL ?>/api/carta_analytics.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'registrar_vista',producto_id:id,ubicacion_id:CARTA_ID,version:'menu'})}).catch(()=>{});
      fetch('<?= APP_URL ?>/api/carta_analytics.php?action=get_likes&producto_id=' + id + '&ubicacion_id=' + CARTA_ID + '&version=menu').then(r=>r.json()).then(d=>{const el=document.getElementById('heart-count');if(el&&d.total>0)el.textContent=d.total;}).catch(()=>{});

      // Badge
      document.getElementById('detail-badge-el').innerHTML = badgeTag(p.badge, 'detail-badge');

      // Name
      document.getElementById('detail-name').textContent = p.nombre;

      // Price / variants
      const priceEl = document.getElementById('detail-price-el');
      if (p.variantes && p.variantes.length > 0) {
        priceEl.innerHTML = `<div class="detail-variants">${p.variantes.map(v =>
          `<div class="detail-variant-row">
            <span class="detail-variant-name">${v.nombre}</span>
            <span class="detail-variant-price">S/${Math.round(v.precio)}</span>
          </div>`
        ).join('')}</div>`;
      } else if (p.precio) {
        priceEl.innerHTML = `<div class="detail-price-single">S/${Math.round(p.precio)}</div>`;
      } else {
        priceEl.innerHTML = '';
      }

      // Modifier groups (display only, no selection for QR menu)
      const modsEl = document.getElementById('detail-modifiers');
      const grupos = p.grupos_modificadores || [];
      if (grupos.length > 0) {
        modsEl.innerHTML = `<div class="mods-section">${grupos.map(buildModGroupDisplay).join('')}</div>`;
      } else {
        modsEl.innerHTML = '';
      }

      // Description
      document.getElementById('detail-desc').textContent = p.descripcion || '';

      // Like button
      const _likeWrap = document.getElementById('like-wrap');
      if (_likeWrap) _likeWrap.innerHTML = `<button class="like-btn${_liked ? ' liked' : ''}" id="heart-btn" onclick="toggleLike(${id})"><svg width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg> Me gusta <span class="like-count-chip" id="heart-count"></span></button>`;

      // Animate open
      const overlay = document.getElementById('detail-overlay');
      if (_closeTimer) { clearTimeout(_closeTimer); _closeTimer = null; }
      overlay.style.visibility = 'visible';
      overlay.style.pointerEvents = 'auto';
      document.getElementById('detail-sheet').scrollTop = 0;
      requestAnimationFrame(() => requestAnimationFrame(() => overlay.classList.add('open')));
      document.body.style.overflow = 'hidden';
    }

    function buildModGroupDisplay(g) {
      const opts = (g.modificadores || []).map(m => {
        const extra = parseFloat(m.precio_adicional) > 0
          ? `<span class="mod-option-price">+S/${parseFloat(m.precio_adicional).toFixed(2).replace('.00','')}</span>`
          : '';
        return `<div class="mod-option" style="pointer-events:none;cursor:default;">
          <span class="mod-option-name">${m.nombre}</span>${extra}
        </div>`;
      }).join('');
      return `<div class="mods-group-wrap">
        <div class="mods-group-label">${g.nombre}</div>
        <div class="mods-options">${opts}</div>
      </div>`;
    }

    function closeDetail() {
      const overlay = document.getElementById('detail-overlay');
      overlay.classList.remove('open');
      document.body.style.overflow = '';
      _closeTimer = setTimeout(() => {
        overlay.style.visibility = 'hidden';
        overlay.style.pointerEvents = 'none';
      }, 380);
    }

    function handleOverlayClick(e) {
      if (e.target === document.getElementById('detail-overlay')) closeDetail();
    }

    // Close on Escape
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDetail(); });

    loadCarta();
  </script>
</body>
</html>
