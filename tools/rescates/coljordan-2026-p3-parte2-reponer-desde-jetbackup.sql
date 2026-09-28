SELECT COUNT(*) AS reservadas, COUNT(c.id) AS con_nota_en_la_copia
  FROM rescate_coljordan_p3_20260928 r
  LEFT JOIN copia_coljordan_0920 c ON c.id = r.id;

START TRANSACTION;
UPDATE notas n
  JOIN rescate_coljordan_p3_20260928 r ON r.id = n.id
  JOIN copia_coljordan_0920 c ON c.id = n.id
   SET n.nota = c.nota
 WHERE n.nota = 0
   AND n.updated_by IS NULL
   AND n.updated_at <=> r.updated_at;
SELECT ROW_COUNT() AS repuestas;
COMMIT;
