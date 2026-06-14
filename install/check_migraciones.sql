-- Diagnóstico de migraciones aplicadas.
-- Pégalo en phpMyAdmin (pestaña SQL) sobre la base de datos del proyecto.
-- Cada fila dice si la migración ya está aplicada (mirando si su columna/tabla existe).
SELECT m.migracion, IF(m.existe > 0, '✅ aplicada', '❌ FALTA') AS estado FROM (
  SELECT 'pedidos_kds.sql            (pedidos.origen)'            AS migracion, COUNT(*) existe FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos'      AND column_name='origen'
  UNION ALL SELECT 'inventario.sql             (tabla insumos)',          COUNT(*) FROM information_schema.tables  WHERE table_schema=DATABASE() AND table_name='insumos'
  UNION ALL SELECT 'inventario_b.sql           (pedidos.stock_descontado)', COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos'      AND column_name='stock_descontado'
  UNION ALL SELECT 'inventario_c.sql           (tabla proveedores)',     COUNT(*) FROM information_schema.tables  WHERE table_schema=DATABASE() AND table_name='proveedores'
  UNION ALL SELECT 'permisos.sql               (users.permissions)',     COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users'        AND column_name='permissions'
  UNION ALL SELECT 'reservas.sql               (tabla reservas)',        COUNT(*) FROM information_schema.tables  WHERE table_schema=DATABASE() AND table_name='reservas'
  UNION ALL SELECT 'landing_tipo.sql           (landing_links.tipo)',    COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='landing_links' AND column_name='tipo'
  UNION ALL SELECT 'pos_arqueo.sql             (pos_turnos.ingreso_efectivo)', COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pos_turnos' AND column_name='ingreso_efectivo'
  UNION ALL SELECT 'cartas_badges.sql          (carta_items.badges)',    COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='carta_items'  AND column_name='badges'
  UNION ALL SELECT 'ubicaciones_cerrado.sql    (ubicaciones.cerrado_manual)', COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ubicaciones' AND column_name='cerrado_manual'
  UNION ALL SELECT 'ubicaciones_horario.sql    (ubicaciones.hora_apertura)',  COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ubicaciones' AND column_name='hora_apertura'
  UNION ALL SELECT 'agenda.sql                 (tabla agenda)',          COUNT(*) FROM information_schema.tables  WHERE table_schema=DATABASE() AND table_name='agenda'
  UNION ALL SELECT 'agenda_bloquea.sql         (agenda.bloquea)',        COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='agenda'       AND column_name='bloquea'
  UNION ALL SELECT 'agenda_rango.sql           (agenda.fecha_fin)',      COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='agenda'       AND column_name='fecha_fin'
  UNION ALL SELECT 'nubefact.sql               (pedidos.comprobante_estado)', COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='comprobante_estado'
  UNION ALL SELECT 'nubefact_email.sql         (pedidos.cliente_email)', COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos'      AND column_name='cliente_email'
  UNION ALL SELECT 'multilocal_facturacion.sql (ubicaciones.serie_boleta)', COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ubicaciones' AND column_name='serie_boleta'
) m;
