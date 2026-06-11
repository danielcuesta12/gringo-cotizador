-- ============================================================
-- Migración: colores personalizables por carta (Generador de cartas PDF)
-- Aplicar UNA vez en instalaciones que ya tienen la tabla `cartas`.
-- Defaults = paleta "Noche" (las cartas existentes mantienen su aspecto).
-- (Si alguna columna ya existe, MySQL dará error inofensivo: ignóralo.)
-- ============================================================
ALTER TABLE `cartas`
  ADD COLUMN `col_bg`          VARCHAR(20) NOT NULL DEFAULT '#161412',
  ADD COLUMN `col_surface`     VARCHAR(20) NOT NULL DEFAULT '#211e1b',
  ADD COLUMN `col_text`        VARCHAR(20) NOT NULL DEFAULT '#ffffff',
  ADD COLUMN `col_muted`       VARCHAR(20) NOT NULL DEFAULT '#9a9089',
  ADD COLUMN `col_accent`      VARCHAR(20) NOT NULL DEFAULT '#FFDF00',
  ADD COLUMN `col_section`     VARCHAR(20) NOT NULL DEFAULT '#FFEFBC',
  ADD COLUMN `col_divider`     VARCHAR(20) NOT NULL DEFAULT '#4a4640',
  ADD COLUMN `col_header_bg`   VARCHAR(20) NOT NULL DEFAULT '#FFDF00',
  ADD COLUMN `col_header_text` VARCHAR(20) NOT NULL DEFAULT '#1A1A1A';
