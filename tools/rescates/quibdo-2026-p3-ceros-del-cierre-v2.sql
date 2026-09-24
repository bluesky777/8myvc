DROP TABLE IF EXISTS rescate_quibdo_p3_20260924;

CREATE TABLE rescate_quibdo_p3_20260924 AS
SELECT n.id, n.nota, n.updated_by, n.updated_at
  FROM notas n
  JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
  JOIN unidades u    ON u.id = s.unidad_id    AND u.deleted_at IS NULL
 WHERE u.periodo_id = 31
   AND n.deleted_at IS NULL
   AND n.nota = 0
   AND n.updated_at >= '2026-09-24'
   AND EXISTS (
         SELECT 1 FROM auditoria a
          WHERE a.entidad = 'nota' AND a.accion = 'editar' AND a.entidad_id = n.id
            AND a.valor_nuevo IS NULL
            AND NOT EXISTS (
                  SELECT 1 FROM auditoria b
                   WHERE b.entidad = 'nota' AND b.accion = 'editar' AND b.entidad_id = n.id
                     AND (b.ocurrido_en > a.ocurrido_en
                          OR (b.ocurrido_en = a.ocurrido_en AND b.id > a.id))));

SELECT COUNT(*) AS a_devolver_a_nulo FROM rescate_quibdo_p3_20260924;
