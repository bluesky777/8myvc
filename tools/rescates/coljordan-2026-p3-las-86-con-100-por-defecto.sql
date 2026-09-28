DROP TABLE IF EXISTS rescate_coljordan_p3b_20260928;
CREATE TABLE rescate_coljordan_p3b_20260928 AS
SELECT n.id, n.nota, n.updated_at, c.nota AS nota_20sep
  FROM notas n JOIN copia_coljordan_0920 c ON c.id = n.id
 WHERE n.deleted_at IS NULL AND n.updated_by IS NULL
   AND n.created_at <=> n.updated_at AND NOT (n.nota <=> c.nota);

START TRANSACTION;
UPDATE notas n
  JOIN rescate_coljordan_p3b_20260928 r ON r.id = n.id
   SET n.nota = r.nota_20sep
 WHERE n.nota <=> r.nota AND n.updated_by IS NULL AND n.updated_at <=> r.updated_at;
COMMIT;
