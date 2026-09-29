DROP TABLE IF EXISTS rescate_coab_20260928;
CREATE TABLE rescate_coab_20260928 (id INT UNSIGNED PRIMARY KEY, nota_hoy INT NULL, nota_20sep INT NOT NULL);
INSERT INTO rescate_coab_20260928 VALUES
(62945,10,100),(66260,2,10),(66261,2,10),(66276,2,10),(66303,2,10),(67209,2,10),(67226,2,10),(67243,2,10),(67251,1,10),(82071,0,35)
;
START TRANSACTION;
UPDATE notas n JOIN rescate_coab_20260928 r ON r.id = n.id SET n.nota = r.nota_20sep
 WHERE n.deleted_at IS NULL AND n.nota <=> r.nota_hoy AND n.updated_by IS NULL AND n.created_at <=> n.updated_at;
COMMIT;
