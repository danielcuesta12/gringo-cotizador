<?php
// Stub de analítica de la carta (Fase B). No-op por ahora; la analítica real
// (visitas, likes) se implementa en la fase de Analítica. Devuelve ok para que
// el menú no muestre errores.
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'total' => 0]);
