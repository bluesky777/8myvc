START TRANSACTION;
UPDATE notas n
  JOIN rescate_quibdo_p3_20260924 r ON r.id = n.id
   SET n.nota = NULL
 WHERE n.nota = 0;
SELECT ROW_COUNT() AS devueltas_a_nulo;
COMMIT;
