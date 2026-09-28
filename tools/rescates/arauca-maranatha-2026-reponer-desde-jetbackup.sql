DROP TABLE IF EXISTS rescate_arauca_20260928;

CREATE TABLE rescate_arauca_20260928 AS
SELECT n.id, n.nota, n.subunidad_id, n.alumno_id,
       n.created_at, n.updated_at, n.updated_by, c.nota AS nota_20sep
  FROM notas n
  JOIN copia_arauca_0920 c ON c.id = n.id
 WHERE n.deleted_at IS NULL
   AND n.updated_by IS NULL
   AND n.created_at <=> n.updated_at
   AND NOT (n.nota <=> c.nota);

SELECT COUNT(*) AS cambiarian,
       SUM(nota = 0) AS hoy_en_cero,
       SUM(nota IS NULL) AS hoy_vacias
  FROM rescate_arauca_20260928;
START TRANSACTION;
UPDATE notas n
  JOIN rescate_arauca_20260928 r ON r.id = n.id
   SET n.nota = r.nota_20sep
 WHERE n.nota <=> r.nota
   AND n.updated_by IS NULL
   AND n.updated_at <=> r.updated_at;
COMMIT;
