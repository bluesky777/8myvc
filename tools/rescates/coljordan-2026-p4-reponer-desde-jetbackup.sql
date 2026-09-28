DROP TABLE IF EXISTS rescate_coljordan_p4_20260928;

CREATE TABLE rescate_coljordan_p4_20260928 AS
SELECT n.id, n.nota, n.subunidad_id, s.nota_default, n.alumno_id,
       n.created_at, n.updated_at, n.updated_by
  FROM notas n
  JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
  JOIN unidades u    ON u.id = s.unidad_id    AND u.deleted_at IS NULL
 WHERE u.periodo_id = 37
   AND n.deleted_at IS NULL
   AND n.updated_by IS NULL
   AND n.created_at <=> n.updated_at
   AND n.nota <=> s.nota_default;

SELECT COUNT(*) AS reservadas, COUNT(c.id) AS en_la_copia,
       SUM(c.nota <> r.nota) AS cambiarian
  FROM rescate_coljordan_p4_20260928 r
  LEFT JOIN copia_coljordan_0920_p4 c ON c.id = r.id;
START TRANSACTION;
UPDATE notas n
  JOIN rescate_coljordan_p4_20260928 r ON r.id = n.id
  JOIN copia_coljordan_0920_p4 c ON c.id = n.id
   SET n.nota = c.nota
 WHERE n.nota <=> r.nota
   AND n.updated_by IS NULL
   AND n.updated_at <=> r.updated_at
   AND c.nota <> r.nota;
COMMIT;
