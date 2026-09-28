-- coljordan, periodo 3 de 2026 (periodo_id = 36): reserva de las casillas en cero que
-- nadie tecleó (sin autor y created_at = updated_at). Es la población que la_casilla_vacia
-- vació el 20 sep y el rescate del 21 volvió a llenar con subunidades.nota_default.
-- Medido el 28 sep 2026: 9.738 filas, TODAS con nota_default = 0.
DROP TABLE IF EXISTS rescate_coljordan_p3_20260928;

CREATE TABLE rescate_coljordan_p3_20260928 AS
SELECT n.id, n.nota, n.subunidad_id, s.nota_default, n.alumno_id,
       n.created_at, n.updated_at, n.updated_by
  FROM notas n
  JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
  JOIN unidades u    ON u.id = s.unidad_id    AND u.deleted_at IS NULL
 WHERE u.periodo_id = 36
   AND n.deleted_at IS NULL
   AND n.nota = 0
   AND n.updated_by IS NULL
   AND n.created_at <=> n.updated_at;

SELECT COUNT(*) AS reservadas, SUM(nota_default <> 0) AS cambiarian_con_la_parte2
  FROM rescate_coljordan_p3_20260928;
