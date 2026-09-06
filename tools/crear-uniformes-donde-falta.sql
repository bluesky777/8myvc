-- =============================================================================
-- Crear la tabla `uniformes` en el colegio al que le falta
-- =============================================================================
--
-- QUÉ ARREGLA
--   `amiguitosdejesus` es el único de los diecisiete que no tiene `uniformes`
--   (87 tablas frente a las 94 de un colegio completo). Cinco ficheros de `app/`
--   la consultan y tres son pantallas de todos los días, así que allí contestan
--   500 HOY, sin desplegar nada:
--
--     ChangeAskedController:258   panel de inicio de un ALUMNO
--     ChangeAskedController:404   panel de inicio de un PROFESOR, por cada alumno
--     NotasController:1222        planilla de notas
--     DisciplinaController:306    disciplina
--     UniformesController         las cuatro rutas de `uniformes/*`
--
--   Creada vacía, esas consultas devuelven cero filas y las pantallas pintan.
--
-- DE DÓNDE SALE ESTE DDL — y es lo que lo hace seguro
--   Copiado literal de `database/schema/mysql-schema.sql`, que es el volcado
--   congelado desde producción y la verdad del esquema en este repositorio. NO
--   está escrito a mano: es la misma tabla que ya tienen los otros dieciséis
--   colegios, columna por columna, índice por índice. Comprobado además que
--   NINGUNA migración del repositorio ha tocado `uniformes` nunca, así que el
--   volcado sigue describiéndola entera.
--
-- POR QUÉ ESTO NO ES UNA MIGRACIÓN, Y QUÉ FALTA DESPUÉS
--   `CLAUDE.md` dice «ningún cambio de esquema a mano: migración o no existe», y
--   la regla es buena. Aquí se hace a mano por una razón con fecha: la tanda del
--   día 10 está CONGELADA en siete migraciones y ya se ensayó sobre esas siete;
--   una octava obliga a repetir el ensayo entero antes de tocar dieciséis
--   colegios. Decisión de Joseth, 5 sep 2026.
--
--   *** LO QUE QUEDA PENDIENTE: después del día 10, una migración con
--   `Schema::hasTable('uniformes')` que la cree donde falte. En este colegio será
--   un no-op, y deja el repositorio volviendo a ser la fuente de la verdad. ***
--
-- QUÉ NO HACE ESTE FICHERO
--   No hay un solo DROP, ALTER, DELETE, UPDATE ni INSERT sobre datos que existan.
--   La única sentencia que escribe es un CREATE TABLE IF NOT EXISTS de una tabla
--   que no está. Si algo va mal, va mal creando: no destruyendo.
--
-- CÓMO SE EJECUTA
--   phpMyAdmin > elige la base del colegio > pestaña SQL.
--   PRIMERO el paso 1 solo, y se LEE la columna `veredicto`. Si no dice OK, se
--   para ahí. Después el paso 2, y luego el 3.
--
-- QUÉ SE PROBÓ ANTES DE ENTREGARLO, porque un script que nadie ha corrido no es
-- seguro: es sólo texto
--   Corrido entero **en los dos motores**: MySQL 8.0.42 (el docker) y **MariaDB
--   10.5.29 en un contenedor levantado para esto**, que es la familia del motor de
--   producción (`10.5.25-MariaDB-cll-lve`). En los dos: veredicto OK, la tabla
--   creada, 22 columnas, 3 claves ajenas, 0 filas.
--
--   Y **los tres guards se vieron ABORTAR**, que es lo que dice que sirven:
--     · con la tabla ya creada      -> «PARA. Esta base YA tiene `uniformes`»
--     · con `periodos` en MyISAM    -> «PARA. ...o alguna no es InnoDB»
--     · con `periodos.id` en bigint -> «PARA. ...la clave ajena fallaria»
--
--   Y lo que de verdad protege, medido y no supuesto: **ignorando el veredicto y
--   corriendo el PASO 2 contra un destino MyISAM, la sentencia falla entera y la
--   tabla NO queda a medias** (`existe = 0`). El peor caso de este script es que
--   no haga nada.
--
--   También se comprobó que la tabla creada es **idéntica a la de un colegio que
--   sí la tiene**: 22 columnas, 4 renglones de índice y 3 claves ajenas, iguales
--   uno a uno en `information_schema`, incluidos charset y collation de cada
--   columna.
--
--   > **Y un aviso para quien compare después, porque cuesta un susto:** un
--   > `SHOW CREATE TABLE` de esta tabla puede dibujar `varchar(250) CHARACTER SET
--   > utf8mb4 COLLATE utf8mb4_unicode_ci` donde el del otro colegio dibuja sólo
--   > `COLLATE utf8mb4_unicode_ci`. **No es una diferencia**: el charset y la
--   > collation son los mismos: es cómo MySQL decide dibujar la columna según cómo
--   > se escribió al crearla. Lo que compara de verdad es `information_schema`, no
--   > el texto del `SHOW CREATE`.
--
-- =============================================================================


-- -----------------------------------------------------------------------------
-- PASO 1 — COMPROBACIONES PREVIAS. Sólo leen. Ninguna cambia nada.
--          Léelas antes de seguir: la columna `veredicto` dice si continuar.
-- -----------------------------------------------------------------------------

SELECT
    DATABASE() AS base_en_la_que_estoy,
    VERSION()  AS motor,

    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'uniformes')                       AS uniformes_ya_existe,

    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('alumnos', 'asignaturas', 'periodos')
        AND ENGINE       = 'InnoDB')                          AS destinos_innodb_de_3,

    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('alumnos', 'asignaturas', 'periodos')
        AND COLUMN_NAME  = 'id'
        AND DATA_TYPE    = 'int'
        AND COLUMN_TYPE LIKE '%unsigned%')                    AS ids_int_unsigned_de_3,

    CASE
        WHEN (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uniformes') > 0
            THEN 'PARA. Esta base YA tiene `uniformes`: no es el colegio al que le falta. No ejecutes el paso 2.'
        WHEN (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME IN ('alumnos','asignaturas','periodos')
                 AND ENGINE = 'InnoDB') <> 3
            THEN 'PARA. Falta alguna de alumnos/asignaturas/periodos, o alguna no es InnoDB: las claves ajenas no se pueden crear.'
        WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME IN ('alumnos','asignaturas','periodos')
                 AND COLUMN_NAME = 'id' AND DATA_TYPE = 'int'
                 AND COLUMN_TYPE LIKE '%unsigned%') <> 3
            THEN 'PARA. Alguna columna id no es INT UNSIGNED: la clave ajena fallaria con errno 150.'
        ELSE 'OK. Falta `uniformes`, los tres destinos existen, son InnoDB y sus id son INT UNSIGNED. Puedes ejecutar el PASO 2.'
    END AS veredicto;


-- -----------------------------------------------------------------------------
-- PASO 2 — LA CREACIÓN. Una sola sentencia.
--
--   `IF NOT EXISTS` es la red: si la tabla estuviera, esto no la toca ni la
--   pisa; avisa y sigue. Y si una clave ajena no se pudiera crear, MariaDB
--   aborta la sentencia ENTERA y no queda una tabla a medias.
--
--   Ojo: el DDL no va dentro de una transacción en MariaDB, así que no hay
--   ROLLBACK. No hace falta: lo único que puede pasar es que se cree una tabla
--   vacía que antes no estaba, y el paso 4 dice cómo deshacerla.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `uniformes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asignatura_id` int unsigned DEFAULT NULL,
  `materia` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alumno_id` int unsigned DEFAULT NULL,
  `periodo_id` int unsigned DEFAULT NULL,
  `contrario` tinyint(1) NOT NULL DEFAULT '0',
  `sin_uniforme` tinyint(1) NOT NULL DEFAULT '0',
  `incompleto` tinyint(1) NOT NULL DEFAULT '0',
  `cabello` tinyint(1) NOT NULL DEFAULT '0',
  `accesorios` tinyint(1) NOT NULL DEFAULT '0',
  `camara` tinyint(1) NOT NULL DEFAULT '0',
  `otro1` tinyint(1) NOT NULL DEFAULT '0',
  `excusado` tinyint(1) NOT NULL DEFAULT '0',
  `descripcion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `fecha_hora` datetime DEFAULT NULL,
  `uploaded` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `uniformes_asignatura_id_foreign` (`asignatura_id`),
  KEY `uniformes_alumno_id_foreign` (`alumno_id`),
  KEY `uniformes_periodo_id_foreign` (`periodo_id`),
  CONSTRAINT `uniformes_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `uniformes_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `uniformes_periodo_id_foreign` FOREIGN KEY (`periodo_id`) REFERENCES `periodos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- PASO 3 — COMPROBACIÓN POSTERIOR. Sólo lee.
--          Tiene que salir: 22 columnas, 3 claves ajenas, 0 filas, InnoDB.
-- -----------------------------------------------------------------------------

SELECT
    DATABASE() AS base,

    (SELECT ENGINE FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uniformes')  AS motor_de_la_tabla,

    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uniformes')  AS columnas_debe_dar_22,

    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'uniformes') AS claves_ajenas_debe_dar_3,

    (SELECT COUNT(*) FROM `uniformes`)                                AS filas_debe_dar_0,

    CASE
        WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uniformes') = 22
         AND (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'uniformes') = 3
            THEN 'OK. La tabla quedó igual que en los otros dieciséis colegios.'
        ELSE 'REVISA. El recuento no cuadra con el esquema de referencia.'
    END AS veredicto;

-- Y la comprobación que de verdad importa, que no es de esquema: entra a la
-- aplicación de ese colegio con una cuenta de alumno y abre el panel de inicio.
-- Antes daba 500. Es lo único que dice que esto sirvió.


-- -----------------------------------------------------------------------------
-- PASO 4 — DESHACER, si hiciera falta.
--
--   Sólo es seguro mientras la tabla esté VACÍA, o sea antes de que nadie ponga
--   una falla de uniforme. Comprueba primero que da 0:
--
--       SELECT COUNT(*) FROM `uniformes`;
--
--   Y sólo entonces, quitando el guion de delante:
--
--   DROP TABLE `uniformes`;
--
--   Con filas dentro, un DROP borra el trabajo de alguien. Por eso va comentado.
-- -----------------------------------------------------------------------------
