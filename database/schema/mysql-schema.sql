
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acudientes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombres` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellidos` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sexo` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `is_acudiente` tinyint(1) NOT NULL DEFAULT '0',
  `fecha_nac` date DEFAULT NULL,
  `ciudad_nac` int unsigned DEFAULT NULL,
  `foto_id` int DEFAULT NULL,
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ocupacion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barrio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_doc` int unsigned DEFAULT NULL,
  `documento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad_doc` int unsigned DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `acudientes_user_id_foreign` (`user_id`),
  KEY `acudientes_ciudad_nac_foreign` (`ciudad_nac`),
  KEY `acudientes_tipo_doc_foreign` (`tipo_doc`),
  KEY `acudientes_ciudad_doc_foreign` (`ciudad_doc`),
  CONSTRAINT `acudientes_ciudad_doc_foreign` FOREIGN KEY (`ciudad_doc`) REFERENCES `ciudades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `acudientes_ciudad_nac_foreign` FOREIGN KEY (`ciudad_nac`) REFERENCES `ciudades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `acudientes_tipo_doc_foreign` FOREIGN KEY (`tipo_doc`) REFERENCES `tipos_documentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `acudientes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agrupacion_puestos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imagen_id` int unsigned DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agrupacion_puestos_detalle` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `grupo_id` int unsigned DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumnos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `no_matricula` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombres` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellidos` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sexo` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `fecha_nac` date DEFAULT NULL,
  `ciudad_nac` int unsigned DEFAULT NULL,
  `tipo_doc` int unsigned DEFAULT NULL,
  `documento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad_doc` int unsigned DEFAULT NULL,
  `tipo_sangre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eps` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barrio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estrato` int DEFAULT '1',
  `ciudad_resid` int unsigned DEFAULT NULL,
  `is_urbana` tinyint(1) DEFAULT '1',
  `egresado` tinyint(1) DEFAULT '0',
  `religion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_id` int DEFAULT NULL,
  `pazysalvo` tinyint(1) DEFAULT '1',
  `deuda` int DEFAULT '0',
  `discapacidad` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_sisben` tinyint(1) DEFAULT '0',
  `nro_sisben` int DEFAULT NULL,
  `has_sisben_3` tinyint(1) DEFAULT '0',
  `nro_sisben_3` int DEFAULT NULL,
  `nee` tinyint(1) NOT NULL DEFAULT '0',
  `nee_descripcion` text COLLATE utf8mb4_unicode_ci,
  `presencial` tinyint(1) DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumnos_user_id_index` (`user_id`),
  KEY `alumnos_ciudad_nac_index` (`ciudad_nac`),
  KEY `alumnos_tipo_doc_index` (`tipo_doc`),
  KEY `alumnos_ciudad_doc_index` (`ciudad_doc`),
  KEY `alumnos_ciudad_resid_index` (`ciudad_resid`),
  CONSTRAINT `alumnos_ciudad_doc_foreign` FOREIGN KEY (`ciudad_doc`) REFERENCES `ciudades` (`id`),
  CONSTRAINT `alumnos_ciudad_nac_foreign` FOREIGN KEY (`ciudad_nac`) REFERENCES `ciudades` (`id`),
  CONSTRAINT `alumnos_ciudad_resid_foreign` FOREIGN KEY (`ciudad_resid`) REFERENCES `ciudades` (`id`),
  CONSTRAINT `alumnos_tipo_doc_foreign` FOREIGN KEY (`tipo_doc`) REFERENCES `tipos_documentos` (`id`),
  CONSTRAINT `alumnos_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `antecedentes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned NOT NULL,
  `cirugias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `varicela` tinyint(1) DEFAULT NULL,
  `medicamento_diario` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vac_influenza` tinyint(1) NOT NULL DEFAULT '0',
  `vac_fiebre_amarilla` tinyint(1) NOT NULL DEFAULT '0',
  `vac_tetano` tinyint(1) NOT NULL DEFAULT '0',
  `vac_sarampion` tinyint(1) NOT NULL DEFAULT '0',
  `vac_hepatitis_b` tinyint(1) NOT NULL DEFAULT '0',
  `vac_otra` tinyint(1) DEFAULT NULL,
  `vac_cual` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patol_asma` tinyint(1) NOT NULL DEFAULT '0',
  `patol_bronquis` tinyint(1) NOT NULL DEFAULT '0',
  `patol_diabetes` tinyint(1) NOT NULL DEFAULT '0',
  `patol_anemia` tinyint(1) NOT NULL DEFAULT '0',
  `patol_hipertension` tinyint(1) NOT NULL DEFAULT '0',
  `patol_dermatitis` tinyint(1) NOT NULL DEFAULT '0',
  `patol_depresion` tinyint(1) NOT NULL DEFAULT '0',
  `patol_otro` tinyint(1) DEFAULT NULL,
  `patol_cual` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fami_hipertension_arterial` tinyint(1) NOT NULL DEFAULT '0',
  `fami_diabetes` tinyint(1) NOT NULL DEFAULT '0',
  `fami_diabetes_mellitus` tinyint(1) NOT NULL DEFAULT '0',
  `fami_cancer` tinyint(1) NOT NULL DEFAULT '0',
  `fami_artritis` tinyint(1) NOT NULL DEFAULT '0',
  `fami_hipotiroidismo` tinyint(1) NOT NULL DEFAULT '0',
  `fami_otro` tinyint(1) DEFAULT NULL,
  `fami_cual` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observaciones` text COLLATE utf8mb4_unicode_ci,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `antecedentes_alumno_id_foreign` (`alumno_id`),
  KEY `antecedentes_updated_by_foreign` (`updated_by`),
  CONSTRAINT `antecedentes_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `antecedentes_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `areas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jefe_id` int unsigned DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `areas_jefe_id_foreign` (`jefe_id`),
  CONSTRAINT `areas_jefe_id_foreign` FOREIGN KEY (`jefe_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asignaturas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `materia_id` int unsigned NOT NULL,
  `grupo_id` int unsigned NOT NULL,
  `profesor_id` int unsigned DEFAULT NULL,
  `nuevo_responsable_id` int unsigned DEFAULT NULL,
  `creditos` int DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `domingo` tinyint(1) DEFAULT NULL,
  `lunes` tinyint(1) DEFAULT NULL,
  `martes` tinyint(1) DEFAULT NULL,
  `miercoles` tinyint(1) DEFAULT NULL,
  `jueves` tinyint(1) DEFAULT NULL,
  `viernes` tinyint(1) DEFAULT NULL,
  `sabado` tinyint(1) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `asignaturas_materia_id_foreign` (`materia_id`),
  KEY `asignaturas_grupo_id_foreign` (`grupo_id`),
  KEY `asignaturas_profesor_id_foreign` (`profesor_id`),
  CONSTRAINT `asignaturas_grupo_id_foreign` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `asignaturas_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE CASCADE,
  CONSTRAINT `asignaturas_profesor_id_foreign` FOREIGN KEY (`profesor_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ausencias` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asignatura_id` int unsigned DEFAULT NULL,
  `alumno_id` int unsigned DEFAULT NULL,
  `periodo_id` int unsigned DEFAULT NULL,
  `cantidad_ausencia` int DEFAULT NULL,
  `cantidad_tardanza` int DEFAULT NULL,
  `entrada` tinyint(1) NOT NULL DEFAULT '0',
  `tipo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_hora` datetime DEFAULT NULL,
  `uploaded` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ausencias_asignatura_id_foreign` (`asignatura_id`),
  KEY `ausencias_alumno_id_foreign` (`alumno_id`),
  KEY `ausencias_periodo_id_foreign` (`periodo_id`),
  CONSTRAINT `ausencias_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ausencias_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ausencias_periodo_id_foreign` FOREIGN KEY (`periodo_id`) REFERENCES `periodos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bitacoras` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `created_by` int NOT NULL,
  `historial_id` int unsigned DEFAULT NULL,
  `descripcion` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `affected_user_id` int DEFAULT NULL,
  `affected_person_id` int DEFAULT NULL,
  `affected_person_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `affected_person_type` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `affected_element_type` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `affected_element_id` int DEFAULT NULL,
  `affected_element_new_value_string` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
  `affected_element_old_value_string` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `affected_element_new_value_int` int DEFAULT NULL,
  `affected_element_old_value_int` int DEFAULT NULL,
  `periodo_id` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bitacoras_historial_id_foreign` (`historial_id`),
  CONSTRAINT `bitacoras_historial_id_foreign` FOREIGN KEY (`historial_id`) REFERENCES `historiales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendario` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_nombres` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `allDay` tinyint(1) DEFAULT '1',
  `start` datetime DEFAULT NULL,
  `end` datetime DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cumple_alumno_id` int unsigned DEFAULT NULL,
  `cumple_profe_id` int unsigned DEFAULT NULL,
  `solo_profes` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `calendario_created_by_foreign` (`created_by`),
  CONSTRAINT `calendario_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `change_asked` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asked_by_user_id` int NOT NULL,
  `asked_to_user_id` int DEFAULT NULL,
  `asked_for_user_id` int DEFAULT NULL,
  `tipo_user` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_id` int DEFAULT NULL,
  `assignment_id` int DEFAULT NULL,
  `comentario_pedido` int DEFAULT NULL,
  `comentario_respuesta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rechazado_at` datetime DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `periodo_asked_id` int DEFAULT NULL,
  `year_asked_id` int DEFAULT NULL,
  `answered_by` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `change_asked_assignment` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nota_id` int DEFAULT NULL,
  `nota_new` int DEFAULT NULL,
  `nota_accepted` tinyint(1) DEFAULT NULL,
  `nota_comport_id` int DEFAULT NULL,
  `nota_comport_new` int DEFAULT NULL,
  `nota_comport_accepted` tinyint(1) DEFAULT NULL,
  `frase_asignat_id` int DEFAULT NULL,
  `frase_asignat_accepted` tinyint(1) DEFAULT NULL,
  `defini_comport_id` int DEFAULT NULL,
  `defini_comport_accepted` tinyint(1) DEFAULT NULL,
  `asignatura_to_remove_id` int DEFAULT NULL,
  `asignatura_to_remove_accepted` tinyint(1) DEFAULT NULL,
  `materia_to_add_id` int DEFAULT NULL,
  `grupo_to_add_id` int DEFAULT NULL,
  `materia_to_add_accepted` tinyint(1) DEFAULT NULL,
  `asignatura_id` int DEFAULT NULL,
  `creditos_new` int DEFAULT NULL,
  `creditos_accepted` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `change_asked_data` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `no_matricula` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombres_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombres_accepted` tinyint(1) DEFAULT NULL,
  `apellidos_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `apellidos_accepted` tinyint(1) DEFAULT NULL,
  `sexo_new` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sexo_accepted` tinyint(1) DEFAULT NULL,
  `fecha_nac_new` date DEFAULT NULL,
  `fecha_nac_accepted` tinyint(1) DEFAULT NULL,
  `ciudad_nac_new` int unsigned DEFAULT NULL,
  `ciudad_nac_accepted` tinyint(1) DEFAULT NULL,
  `tipo_doc_new` int unsigned DEFAULT NULL,
  `tipo_doc_accepted` tinyint(1) DEFAULT NULL,
  `documento_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `documento_accepted` tinyint(1) DEFAULT NULL,
  `ciudad_doc_new` int unsigned DEFAULT NULL,
  `ciudad_doc_accepted` tinyint(1) DEFAULT NULL,
  `tipo_sangre_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_sangre_accepted` tinyint(1) DEFAULT NULL,
  `eps_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eps_accepted` tinyint(1) DEFAULT NULL,
  `telefono_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono_accepted` tinyint(1) DEFAULT NULL,
  `celular_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular_accepted` tinyint(1) DEFAULT NULL,
  `direccion_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion_accepted` tinyint(1) DEFAULT NULL,
  `barrio_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barrio_accepted` tinyint(1) DEFAULT NULL,
  `estrato_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estrato_accepted` tinyint(1) DEFAULT NULL,
  `ciudad_resid_new` int unsigned DEFAULT NULL,
  `ciudad_resid_accepted` tinyint(1) DEFAULT NULL,
  `religion_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `religion_accepted` tinyint(1) DEFAULT NULL,
  `email_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_accepted` tinyint(1) DEFAULT NULL,
  `facebook_new` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook_accepted` tinyint(1) DEFAULT NULL,
  `pazysalvo_new` tinyint(1) DEFAULT NULL,
  `pazysalvo_accepted` tinyint(1) DEFAULT NULL,
  `foto_id_new` int DEFAULT NULL,
  `foto_id_accepted` tinyint(1) DEFAULT NULL,
  `image_id_new` int DEFAULT NULL,
  `image_id_accepted` tinyint(1) DEFAULT NULL,
  `firma_id_new` int DEFAULT NULL,
  `firma_id_accepted` tinyint(1) DEFAULT NULL,
  `image_to_delete_id` int DEFAULT NULL,
  `image_to_delete_accepted` tinyint(1) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `change_asked_data_ciudad_nac_new_index` (`ciudad_nac_new`),
  KEY `change_asked_data_tipo_doc_new_index` (`tipo_doc_new`),
  KEY `change_asked_data_ciudad_doc_new_index` (`ciudad_doc_new`),
  KEY `change_asked_data_ciudad_resid_new_foreign` (`ciudad_resid_new`),
  CONSTRAINT `change_asked_data_ciudad_doc_new_foreign` FOREIGN KEY (`ciudad_doc_new`) REFERENCES `ciudades` (`id`),
  CONSTRAINT `change_asked_data_ciudad_nac_new_foreign` FOREIGN KEY (`ciudad_nac_new`) REFERENCES `ciudades` (`id`),
  CONSTRAINT `change_asked_data_ciudad_resid_new_foreign` FOREIGN KEY (`ciudad_resid_new`) REFERENCES `ciudades` (`id`),
  CONSTRAINT `change_asked_data_tipo_doc_new_foreign` FOREIGN KEY (`tipo_doc_new`) REFERENCES `tipos_documentos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ciudades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ciudad` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `departamento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais_id` int unsigned DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ciudades_pais_id_foreign` (`pais_id`),
  CONSTRAINT `ciudades_pais_id_foreign` FOREIGN KEY (`pais_id`) REFERENCES `paises` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comentarios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `publicacion_id` int unsigned DEFAULT NULL,
  `persona_id` int unsigned DEFAULT NULL,
  `tipo_persona` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comentario` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comentarios_publicacion_id_foreign` (`publicacion_id`),
  CONSTRAINT `comentarios_publicacion_id_foreign` FOREIGN KEY (`publicacion_id`) REFERENCES `publicaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `config_certificados` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `encabezado_img_id` int DEFAULT NULL,
  `encabezado_width` int DEFAULT '20',
  `encabezado_height` int DEFAULT '10',
  `encabezado_margin_top` int DEFAULT '10',
  `encabezado_margin_left` int DEFAULT '10',
  `encabezado_solo_primera_pagina` tinyint(1) DEFAULT '1',
  `piepagina_img_id` int DEFAULT NULL,
  `piepagina_width` int DEFAULT '20',
  `piepagina_height` int DEFAULT '10',
  `piepagina_margin_bottom` int DEFAULT '10',
  `piepagina_margin_left` int DEFAULT '10',
  `piepagina_solo_ultima_pagina` tinyint(1) DEFAULT '1',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contratos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `profesor_id` int unsigned DEFAULT NULL,
  `year_id` int unsigned DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contratos_profesor_id_index` (`profesor_id`),
  KEY `contratos_year_id_index` (`year_id`),
  CONSTRAINT `contratos_profesor_id_foreign` FOREIGN KEY (`profesor_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contratos_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `debugging` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `accion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dato1` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dato2` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `default_subunidades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `definicion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `porcentaje` int DEFAULT '0',
  `default_unidad_id` int unsigned NOT NULL,
  `nota_default` int DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `can_change_definicion` tinyint(1) NOT NULL,
  `can_change_porcentaje` tinyint(1) NOT NULL,
  `can_change_orden` tinyint(1) NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `default_subunidades_default_unidad_id_foreign` (`default_unidad_id`),
  CONSTRAINT `default_subunidades_default_unidad_id_foreign` FOREIGN KEY (`default_unidad_id`) REFERENCES `default_unidades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `default_unidades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `definicion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `porcentaje` int DEFAULT '0',
  `orden` int DEFAULT NULL,
  `year_id` int unsigned DEFAULT NULL,
  `profesor_id` int unsigned DEFAULT NULL,
  `show_definicion` tinyint(1) NOT NULL,
  `can_change_definicion` tinyint(1) NOT NULL,
  `can_change_porcentaje` tinyint(1) NOT NULL,
  `can_change_orden` tinyint(1) NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `default_unidades_year_id_foreign` (`year_id`),
  CONSTRAINT `default_unidades_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `definiciones_comportamiento` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `comportamiento_id` int unsigned NOT NULL,
  `frase_id` int unsigned DEFAULT NULL,
  `frase` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `fecha` date DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `definiciones_comportamiento_comportamiento_id_foreign` (`comportamiento_id`),
  KEY `definiciones_comportamiento_frase_id_foreign` (`frase_id`),
  CONSTRAINT `definiciones_comportamiento_comportamiento_id_foreign` FOREIGN KEY (`comportamiento_id`) REFERENCES `nota_comportamiento` (`id`) ON DELETE CASCADE,
  CONSTRAINT `definiciones_comportamiento_frase_id_foreign` FOREIGN KEY (`frase_id`) REFERENCES `frases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `df_alumnos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned DEFAULT NULL,
  `no_matricula` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombres` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellidos` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sexo` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `fecha_nac` date DEFAULT NULL,
  `ciudad_nac` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_doc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `documento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad_doc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_sangre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eps` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barrio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estrato` int DEFAULT '1',
  `ciudad_resid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `religion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_id` int DEFAULT NULL,
  `nombre_foto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pazysalvo` tinyint(1) DEFAULT '1',
  `deuda` int DEFAULT '0',
  `year_id` int unsigned NOT NULL,
  `year` int unsigned DEFAULT NULL,
  `grupo_id` int unsigned DEFAULT NULL,
  `grupo_id_df` int unsigned DEFAULT NULL,
  `year_puesto` int unsigned DEFAULT NULL,
  `year_puntaje` decimal(7,4) DEFAULT NULL,
  `year_comportamiento` decimal(7,4) DEFAULT NULL,
  `year_comportamiento_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_tardanzas_instituc` int DEFAULT NULL,
  `year_tardanzas_clases` int DEFAULT NULL,
  `year_ausencias_instituc` int DEFAULT NULL,
  `year_ausencias_clases` int DEFAULT NULL,
  `per1_puesto` int unsigned DEFAULT NULL,
  `per1_puntaje` decimal(7,4) DEFAULT NULL,
  `per1_notas_perdidas` int unsigned DEFAULT NULL,
  `per1_recuperado` tinyint(1) NOT NULL DEFAULT '0',
  `per1_comportamiento` decimal(7,4) DEFAULT NULL,
  `per1_comportamiento_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `per1_tardanzas_instituc` int DEFAULT NULL,
  `per1_tardanzas_clases` int DEFAULT NULL,
  `per1_ausencias_instituc` int DEFAULT NULL,
  `per1_ausencias_clases` int DEFAULT NULL,
  `per2_puesto` int unsigned DEFAULT NULL,
  `per2_puntaje` decimal(7,4) DEFAULT NULL,
  `per2_notas_perdidas` int unsigned DEFAULT NULL,
  `per2_recuperado` tinyint(1) NOT NULL DEFAULT '0',
  `per2_comportamiento` decimal(7,4) DEFAULT NULL,
  `per2_comportamiento_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `per2_tardanzas_instituc` int DEFAULT NULL,
  `per2_tardanzas_clases` int DEFAULT NULL,
  `per2_ausencias_instituc` int DEFAULT NULL,
  `per2_ausencias_clases` int DEFAULT NULL,
  `per3_puesto` int unsigned DEFAULT NULL,
  `per3_puntaje` decimal(7,4) DEFAULT NULL,
  `per3_notas_perdidas` int unsigned DEFAULT NULL,
  `per3_recuperado` tinyint(1) NOT NULL DEFAULT '0',
  `per3_comportamiento` decimal(7,4) DEFAULT NULL,
  `per3_comportamiento_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `per3_tardanzas_instituc` int DEFAULT NULL,
  `per3_tardanzas_clases` int DEFAULT NULL,
  `per3_ausencias_instituc` int DEFAULT NULL,
  `per3_ausencias_clases` int DEFAULT NULL,
  `per4_puesto` int unsigned DEFAULT NULL,
  `per4_puntaje` decimal(7,4) DEFAULT NULL,
  `per4_notas_perdidas` int unsigned DEFAULT NULL,
  `per4_recuperado` tinyint(1) NOT NULL DEFAULT '0',
  `per4_comportamiento` decimal(7,4) DEFAULT NULL,
  `per4_comportamiento_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `per4_tardanzas_instituc` int DEFAULT NULL,
  `per4_tardanzas_clases` int DEFAULT NULL,
  `per4_ausencias_instituc` int DEFAULT NULL,
  `per4_ausencias_clases` int DEFAULT NULL,
  `estado_matr` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'MATR',
  `fecha_retiro` date DEFAULT NULL,
  `fecha_matricula` date DEFAULT NULL,
  `se_recomienda` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `entra_con` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `created_by` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `df_alumnos_alumno_id_foreign` (`alumno_id`),
  KEY `df_alumnos_grupo_id_df_foreign` (`grupo_id_df`),
  CONSTRAINT `df_alumnos_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `df_alumnos_grupo_id_df_foreign` FOREIGN KEY (`grupo_id_df`) REFERENCES `df_grupos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `df_asignaturas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id_df` int unsigned DEFAULT NULL,
  `asignatura_id` int unsigned DEFAULT NULL,
  `materia_id` int DEFAULT NULL,
  `materia_nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `materia_alias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_orden` int DEFAULT NULL,
  `area_id` int DEFAULT NULL,
  `area_nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `area_orden` int DEFAULT NULL,
  `profesor_id` int DEFAULT NULL,
  `profesor_nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profesor_foto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profesor_firma` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creditos` int DEFAULT NULL,
  `year_definitiva` decimal(7,4) DEFAULT NULL,
  `year_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_notas_perdidas` int unsigned DEFAULT NULL,
  `year_recuperada` tinyint(1) NOT NULL DEFAULT '0',
  `year_tardanzas_clases` int DEFAULT NULL,
  `year_ausencias_clases` int DEFAULT NULL,
  `per1_definitiva` decimal(7,4) DEFAULT NULL,
  `per1_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `per1_notas_perdidas` int unsigned DEFAULT NULL,
  `per1_manual` tinyint(1) DEFAULT '0',
  `per1_recuperada` tinyint(1) NOT NULL DEFAULT '0',
  `per1_tardanzas_clases` int DEFAULT NULL,
  `per1_ausencias_clases` int DEFAULT NULL,
  `per2_definitiva` decimal(7,4) DEFAULT NULL,
  `per2_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `per2_notas_perdidas` int unsigned DEFAULT NULL,
  `per2_manual` tinyint(1) DEFAULT '0',
  `per2_recuperada` tinyint(1) NOT NULL DEFAULT '0',
  `per2_tardanzas_clases` int DEFAULT NULL,
  `per2_ausencias_clases` int DEFAULT NULL,
  `per3_definitiva` decimal(7,4) DEFAULT NULL,
  `per3_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `per3_notas_perdidas` int unsigned DEFAULT NULL,
  `per3_manual` tinyint(1) DEFAULT '0',
  `per3_recuperada` tinyint(1) NOT NULL DEFAULT '0',
  `per3_tardanzas_clases` int DEFAULT NULL,
  `per3_ausencias_clases` int DEFAULT NULL,
  `per4_definitiva` decimal(7,4) DEFAULT NULL,
  `per4_desempenio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `per4_notas_perdidas` int unsigned DEFAULT NULL,
  `per4_manual` tinyint(1) DEFAULT '0',
  `per4_recuperada` tinyint(1) NOT NULL DEFAULT '0',
  `per4_tardanzas_clases` int DEFAULT NULL,
  `per4_ausencias_clases` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `df_asignaturas_alumno_id_df_foreign` (`alumno_id_df`),
  KEY `df_asignaturas_asignatura_id_foreign` (`asignatura_id`),
  CONSTRAINT `df_asignaturas_alumno_id_df_foreign` FOREIGN KEY (`alumno_id_df`) REFERENCES `df_alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `df_asignaturas_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `df_grupos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `grupo_id` int unsigned DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abrev` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_id` int unsigned NOT NULL,
  `year` int unsigned DEFAULT NULL,
  `titular_id` int unsigned DEFAULT NULL,
  `nombre_titular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_img_titular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_firma_titular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `puesto` int unsigned DEFAULT NULL,
  `puntaje` decimal(7,4) DEFAULT NULL,
  `grado_id` int unsigned NOT NULL,
  `valormatricula` int DEFAULT NULL,
  `valorpension` int DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `caritas` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `df_grupos_grupo_id_foreign` (`grupo_id`),
  KEY `df_grupos_year_id_foreign` (`year_id`),
  KEY `df_grupos_titular_id_foreign` (`titular_id`),
  KEY `df_grupos_grado_id_foreign` (`grado_id`),
  CONSTRAINT `df_grupos_grado_id_foreign` FOREIGN KEY (`grado_id`) REFERENCES `grados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `df_grupos_grupo_id_foreign` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `df_grupos_titular_id_foreign` FOREIGN KEY (`titular_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `df_grupos_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `df_notas_finales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned DEFAULT NULL,
  `asignatura_id` int unsigned DEFAULT NULL,
  `periodo_id` int unsigned DEFAULT NULL,
  `nota` int NOT NULL DEFAULT '0',
  `recuperada` tinyint(1) DEFAULT '0',
  `manual` tinyint(1) DEFAULT '0',
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `df_notas_finales_alumno_id_foreign` (`alumno_id`),
  KEY `df_notas_finales_asignatura_id_foreign` (`asignatura_id`),
  KEY `df_notas_finales_periodo_id_foreign` (`periodo_id`),
  CONSTRAINT `df_notas_finales_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `df_notas_finales_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `df_notas_finales_periodo_id_foreign` FOREIGN KEY (`periodo_id`) REFERENCES `periodos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `df_subunidades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `unidad_id_df` int unsigned DEFAULT NULL,
  `unidad_id` int unsigned DEFAULT NULL,
  `definicion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `porcentaje` int DEFAULT '0',
  `nota` decimal(7,4) DEFAULT NULL,
  `periodo_id` int unsigned NOT NULL,
  `asignatura_id` int unsigned NOT NULL,
  `orden` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `df_subunidades_unidad_id_df_foreign` (`unidad_id_df`),
  CONSTRAINT `df_subunidades_unidad_id_df_foreign` FOREIGN KEY (`unidad_id_df`) REFERENCES `df_unidades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `df_unidades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asignatura_id_df` int unsigned DEFAULT NULL,
  `asignatura_id` int unsigned DEFAULT NULL,
  `definicion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `porcentaje` int DEFAULT '0',
  `nota` decimal(7,4) DEFAULT NULL,
  `periodo_id` int unsigned NOT NULL,
  `orden` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `df_unidades_asignatura_id_df_foreign` (`asignatura_id_df`),
  CONSTRAINT `df_unidades_asignatura_id_df_foreign` FOREIGN KEY (`asignatura_id_df`) REFERENCES `df_asignaturas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dis_acciones_restaurativas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ocasionada_por_proceso_id` int unsigned DEFAULT NULL,
  `fecha_colocacion` date DEFAULT NULL,
  `fecha_plazo` date DEFAULT NULL,
  `cumplida` tinyint(1) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dis_acciones_restaurativas_ocasionada_por_proceso_id_foreign` (`ocasionada_por_proceso_id`),
  KEY `dis_acciones_restaurativas_created_by_foreign` (`created_by`),
  KEY `dis_acciones_restaurativas_updated_by_foreign` (`updated_by`),
  KEY `dis_acciones_restaurativas_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `dis_acciones_restaurativas_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_acciones_restaurativas_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_acciones_restaurativas_ocasionada_por_proceso_id_foreign` FOREIGN KEY (`ocasionada_por_proceso_id`) REFERENCES `dis_procesos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_acciones_restaurativas_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dis_configuraciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `year_id` int unsigned DEFAULT NULL,
  `reinicia_por_periodo` tinyint(1) DEFAULT '0',
  `falta_tipo1_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Situación tipo 1',
  `faltas_tipo1_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Situaciones tipo 1',
  `genero_falta_t1` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'F',
  `falta_tipo2_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Situación tipo 2',
  `faltas_tipo2_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Situaciones tipo 2',
  `genero_falta_t2` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'F',
  `falta_tipo3_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Situación tipo 3',
  `faltas_tipo3_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Situaciones tipo 3',
  `genero_falta_t3` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'F',
  `cant_tard_to_ft1` int NOT NULL DEFAULT '5',
  `cant_ft1_to_ft2` int NOT NULL DEFAULT '3',
  `cant_ft2_to_ft3` int NOT NULL DEFAULT '3',
  `nombre_col1` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Observaciones sobre la convivencia',
  `nombre_col2` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Observaciones sobre lo académico',
  `nombre_col3` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `definicion_ft1` text COLLATE utf8mb4_unicode_ci,
  `definicion_ft2` text COLLATE utf8mb4_unicode_ci,
  `definicion_ft3` text COLLATE utf8mb4_unicode_ci,
  `updated_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dis_configuraciones_year_id_foreign` (`year_id`),
  KEY `dis_configuraciones_updated_by_foreign` (`updated_by`),
  CONSTRAINT `dis_configuraciones_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_configuraciones_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dis_libro_rojo` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `year_id` int unsigned NOT NULL,
  `alumno_id` int unsigned NOT NULL,
  `fecha_per1` date DEFAULT NULL,
  `per1_col1` text COLLATE utf8mb4_unicode_ci,
  `per1_col2` text COLLATE utf8mb4_unicode_ci,
  `per1_col3` text COLLATE utf8mb4_unicode_ci,
  `fecha_per2` date DEFAULT NULL,
  `per2_col1` text COLLATE utf8mb4_unicode_ci,
  `per2_col2` text COLLATE utf8mb4_unicode_ci,
  `per2_col3` text COLLATE utf8mb4_unicode_ci,
  `fecha_per3` date DEFAULT NULL,
  `per3_col1` text COLLATE utf8mb4_unicode_ci,
  `per3_col2` text COLLATE utf8mb4_unicode_ci,
  `per3_col3` text COLLATE utf8mb4_unicode_ci,
  `fecha_per4` date DEFAULT NULL,
  `per4_col1` text COLLATE utf8mb4_unicode_ci,
  `per4_col2` text COLLATE utf8mb4_unicode_ci,
  `per4_col3` text COLLATE utf8mb4_unicode_ci,
  `updated_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dis_libro_rojo_year_id_foreign` (`year_id`),
  KEY `dis_libro_rojo_alumno_id_foreign` (`alumno_id`),
  KEY `dis_libro_rojo_updated_by_foreign` (`updated_by`),
  KEY `dis_libro_rojo_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `dis_libro_rojo_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_libro_rojo_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_libro_rojo_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_libro_rojo_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dis_ordinales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `year_id` int unsigned NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ordinal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `pagina` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dis_ordinales_year_id_foreign` (`year_id`),
  KEY `dis_ordinales_updated_by_foreign` (`updated_by`),
  KEY `dis_ordinales_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `dis_ordinales_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_ordinales_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_ordinales_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dis_proceso_ordinales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ordinal_id` int unsigned DEFAULT NULL,
  `proceso_id` int unsigned DEFAULT NULL,
  `added_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dis_proceso_ordinales_ordinal_id_foreign` (`ordinal_id`),
  KEY `dis_proceso_ordinales_proceso_id_foreign` (`proceso_id`),
  KEY `dis_proceso_ordinales_added_by_foreign` (`added_by`),
  KEY `dis_proceso_ordinales_updated_by_foreign` (`updated_by`),
  KEY `dis_proceso_ordinales_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `dis_proceso_ordinales_added_by_foreign` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_proceso_ordinales_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_proceso_ordinales_ordinal_id_foreign` FOREIGN KEY (`ordinal_id`) REFERENCES `dis_ordinales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_proceso_ordinales_proceso_id_foreign` FOREIGN KEY (`proceso_id`) REFERENCES `dis_procesos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_proceso_ordinales_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dis_procesos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `alumno_id` int unsigned DEFAULT NULL,
  `year_id` int unsigned DEFAULT NULL,
  `periodo_id` int unsigned DEFAULT NULL,
  `tipo_situacion` int unsigned DEFAULT NULL,
  `become_id` int unsigned DEFAULT NULL,
  `profesor_id` int unsigned DEFAULT NULL,
  `deriva_de_tardanzas` tinyint(1) NOT NULL DEFAULT '0',
  `deriva_de_tipos1` tinyint(1) NOT NULL DEFAULT '0',
  `deriva_de_tipos2` tinyint(1) NOT NULL DEFAULT '0',
  `fecha_hora_aprox` datetime DEFAULT NULL,
  `asignatura_id` int unsigned DEFAULT NULL,
  `testigos` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descargo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `firma_alumno` tinyint(1) DEFAULT '0',
  `firma_acudiente` tinyint(1) DEFAULT NULL,
  `added_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dis_procesos_alumno_id_foreign` (`alumno_id`),
  KEY `dis_procesos_year_id_foreign` (`year_id`),
  KEY `dis_procesos_periodo_id_foreign` (`periodo_id`),
  KEY `dis_procesos_profesor_id_foreign` (`profesor_id`),
  KEY `dis_procesos_asignatura_id_foreign` (`asignatura_id`),
  KEY `dis_procesos_added_by_foreign` (`added_by`),
  KEY `dis_procesos_updated_by_foreign` (`updated_by`),
  KEY `dis_procesos_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `dis_procesos_added_by_foreign` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_procesos_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_procesos_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_procesos_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_procesos_periodo_id_foreign` FOREIGN KEY (`periodo_id`) REFERENCES `periodos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_procesos_profesor_id_foreign` FOREIGN KEY (`profesor_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_procesos_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dis_procesos_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `escalas_de_valoracion` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `desempenio` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valoracion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `porc_inicial` int NOT NULL,
  `porc_final` int NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `orden` int NOT NULL,
  `perdido` tinyint(1) NOT NULL,
  `year_id` int unsigned NOT NULL,
  `icono_infantil` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icono_adolescente` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `escalas_de_valoracion_year_id_foreign` (`year_id`),
  CONSTRAINT `escalas_de_valoracion_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `frases` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `frase` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `tipo_frase` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year_id` int unsigned NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `frases_year_id_foreign` (`year_id`),
  CONSTRAINT `frases_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `frases_asignatura` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int NOT NULL,
  `frase_id` int DEFAULT NULL,
  `frase` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asignatura_id` int NOT NULL,
  `periodo_id` int NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `frases_preescolar` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asignatura_id` int unsigned NOT NULL,
  `definicion` text COLLATE utf8mb4_unicode_ci,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `frases_preescolar_asignatura_id_foreign` (`asignatura_id`),
  CONSTRAINT `frases_preescolar_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `grados` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abrev` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `nivel_educativo_id` int unsigned DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `grados_nivel_educativo_id_foreign` (`nivel_educativo_id`),
  CONSTRAINT `grados_nivel_educativo_id_foreign` FOREIGN KEY (`nivel_educativo_id`) REFERENCES `niveles_educativos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `grupos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abrev` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_id` int unsigned NOT NULL,
  `titular_id` int unsigned DEFAULT NULL,
  `grado_id` int unsigned NOT NULL,
  `valormatricula` int DEFAULT NULL,
  `valorpension` int DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `caritas` tinyint(1) NOT NULL DEFAULT '0',
  `cupo` int DEFAULT '20',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `grupos_year_id_foreign` (`year_id`),
  KEY `grupos_titular_id_foreign` (`titular_id`),
  KEY `grupos_grado_id_foreign` (`grado_id`),
  CONSTRAINT `grupos_grado_id_foreign` FOREIGN KEY (`grado_id`) REFERENCES `grados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grupos_titular_id_foreign` FOREIGN KEY (`titular_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grupos_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `historiales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logout_at` datetime DEFAULT NULL,
  `browser_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `browser_version` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `browser_family` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `browser_engine` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entorno` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_family` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_family` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_model` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_grade` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `historiales_user_id_foreign` (`user_id`),
  CONSTRAINT `historiales_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `images` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `publica` tinyint(1) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `materias` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `materia` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `area_id` int unsigned DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `materias_area_id_foreign` (`area_id`),
  CONSTRAINT `materias_area_id_foreign` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `matriculas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned NOT NULL,
  `grupo_id` int unsigned NOT NULL,
  `estado` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'MATR',
  `prematriculado` date DEFAULT NULL,
  `fecha_retiro` date DEFAULT NULL,
  `fecha_matricula` date DEFAULT NULL,
  `fecha_pension` date DEFAULT NULL,
  `razon_retiro` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `programar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion_recomendacion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `efectuar_una` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `descripcion_efectuada` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profes_editar_notas` tinyint(1) DEFAULT NULL,
  `nuevo` tinyint(1) DEFAULT NULL,
  `repitente` tinyint(1) NOT NULL DEFAULT '0',
  `promovido` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Automático',
  `promedio` decimal(5,2) NOT NULL DEFAULT '0.00',
  `cant_asign_perdidas` int NOT NULL DEFAULT '0',
  `cant_areas_perdidas` int NOT NULL DEFAULT '0',
  `anios_in_cole` int NOT NULL DEFAULT '0',
  `nro_folio` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `matriculas_alumno_id_foreign` (`alumno_id`),
  KEY `matriculas_grupo_id_foreign` (`grupo_id`),
  CONSTRAINT `matriculas_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `matriculas_grupo_id_foreign` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `niveles_educativos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abrev` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nota_comportamiento` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned NOT NULL,
  `periodo_id` int unsigned NOT NULL,
  `nota` int NOT NULL,
  `familiar_nota` int DEFAULT NULL,
  `familiar_ausencias` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `nota_comportamiento_alumno_id_foreign` (`alumno_id`),
  KEY `nota_comportamiento_periodo_id_foreign` (`periodo_id`),
  CONSTRAINT `nota_comportamiento_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nota_comportamiento_periodo_id_foreign` FOREIGN KEY (`periodo_id`) REFERENCES `periodos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nota` int NOT NULL DEFAULT '0',
  `subunidad_id` int unsigned NOT NULL,
  `alumno_id` int unsigned NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notas_subunidad_id_foreign` (`subunidad_id`),
  KEY `notas_alumno_id_foreign` (`alumno_id`),
  CONSTRAINT `notas_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notas_subunidad_id_foreign` FOREIGN KEY (`subunidad_id`) REFERENCES `subunidades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_finales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned DEFAULT NULL,
  `asignatura_id` int unsigned DEFAULT NULL,
  `periodo_id` int unsigned DEFAULT NULL,
  `periodo` int unsigned DEFAULT NULL,
  `nota` int NOT NULL DEFAULT '0',
  `recuperada` tinyint(1) DEFAULT '0',
  `manual` tinyint(1) DEFAULT '0',
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notas_finales_alumno_id_foreign` (`alumno_id`),
  KEY `notas_finales_asignatura_id_foreign` (`asignatura_id`),
  KEY `notas_finales_periodo_id_foreign` (`periodo_id`),
  CONSTRAINT `notas_finales_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notas_finales_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notas_finales_periodo_id_foreign` FOREIGN KEY (`periodo_id`) REFERENCES `periodos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paises` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pais` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abrev` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parentescos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `acudiente_id` int NOT NULL,
  `alumno_id` int NOT NULL,
  `parentesco` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `observaciones` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reminders` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `password_reminders_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `periodos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `numero` int NOT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `actual` tinyint(1) NOT NULL DEFAULT '0',
  `profes_pueden_editar_notas` tinyint(1) NOT NULL DEFAULT '1',
  `profes_pueden_nivelar` tinyint(1) NOT NULL DEFAULT '1',
  `year_id` int unsigned NOT NULL,
  `fecha_plazo` date DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `periodos_year_id_foreign` (`year_id`),
  CONSTRAINT `periodos_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permission_role` (
  `permission_id` int unsigned NOT NULL,
  `role_id` int unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `permission_role_role_id_foreign` (`role_id`),
  CONSTRAINT `permission_role_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `permission_role_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piars_actas_acuerdo` (
  `id` int NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned NOT NULL,
  `year_id` int NOT NULL,
  `documento` varchar(255) DEFAULT NULL,
  `history` longtext,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piars_alumnos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned NOT NULL,
  `year_id` int unsigned NOT NULL,
  `valoracion_pedagogica` text COLLATE utf8mb4_unicode_ci,
  `ajustes_generales` text COLLATE utf8mb4_unicode_ci,
  `documento1` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `documento2` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reporte` text COLLATE utf8mb4_unicode_ci,
  `reporte_default` text COLLATE utf8mb4_unicode_ci,
  `history` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `piars_alumnos_before_insert` BEFORE INSERT ON `piars_alumnos` FOR EACH ROW BEGIN
  IF NEW.history IS NULL THEN
    SET NEW.history = '[]';
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piars_asignaturas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asignatura_id` int unsigned NOT NULL,
  `alumno_id` int unsigned NOT NULL,
  `year` int unsigned NOT NULL,
  `apoyo_razonable` text COLLATE utf8mb4_unicode_ci,
  `seguimientos` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_by` int unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piars_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `reporte_default` text COLLATE utf8mb4_unicode_ci,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `piars_config_chk_1` CHECK (json_valid(`config`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piars_grupos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `grupo_id` int unsigned NOT NULL,
  `titular_id` int NOT NULL,
  `year_id` int NOT NULL,
  `caracterizacion_grupo` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profesores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombres` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellidos` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sexo` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  `foto_id` int DEFAULT NULL,
  `firma_id` int DEFAULT NULL,
  `permiso_hasta` datetime DEFAULT NULL,
  `tipo_doc` int unsigned DEFAULT NULL,
  `num_doc` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad_doc` int unsigned DEFAULT NULL,
  `fecha_nac` date DEFAULT NULL,
  `ciudad_nac` int unsigned DEFAULT NULL,
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado_civil` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barrio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_profesor` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `profesores_tipo_doc_foreign` (`tipo_doc`),
  KEY `profesores_ciudad_doc_foreign` (`ciudad_doc`),
  KEY `profesores_ciudad_nac_foreign` (`ciudad_nac`),
  KEY `profesores_user_id_foreign` (`user_id`),
  CONSTRAINT `profesores_ciudad_doc_foreign` FOREIGN KEY (`ciudad_doc`) REFERENCES `ciudades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `profesores_ciudad_nac_foreign` FOREIGN KEY (`ciudad_nac`) REFERENCES `ciudades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `profesores_tipo_doc_foreign` FOREIGN KEY (`tipo_doc`) REFERENCES `tipos_documentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `profesores_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `publicaciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `persona_id` int unsigned DEFAULT NULL,
  `tipo_persona` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contenido` text COLLATE utf8mb4_unicode_ci,
  `imagen_id` int unsigned DEFAULT NULL,
  `imagen_nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `para_todos` tinyint(1) DEFAULT '0',
  `para_alumnos` tinyint(1) DEFAULT '0',
  `para_acudientes` tinyint(1) DEFAULT '0',
  `para_profes` tinyint(1) DEFAULT '0',
  `para_administradores` tinyint(1) DEFAULT '0',
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `publicaciones_imagen_id_foreign` (`imagen_id`),
  CONSTRAINT `publicaciones_imagen_id_foreign` FOREIGN KEY (`imagen_id`) REFERENCES `images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recuperacion_final` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned DEFAULT NULL,
  `asignatura_id` int unsigned DEFAULT NULL,
  `year` int unsigned DEFAULT NULL,
  `nota` int NOT NULL DEFAULT '0',
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recuperacion_final_alumno_id_foreign` (`alumno_id`),
  KEY `recuperacion_final_asignatura_id_foreign` (`asignatura_id`),
  CONSTRAINT `recuperacion_final_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recuperacion_final_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `registros_enfermeria` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned NOT NULL,
  `fecha_suceso` datetime DEFAULT NULL,
  `signo_fc` int DEFAULT NULL,
  `signo_fr` int DEFAULT NULL,
  `signo_t` decimal(4,1) DEFAULT NULL,
  `signo_glu` int DEFAULT NULL,
  `signo_spo2` int DEFAULT NULL,
  `signo_pa_dia` int DEFAULT NULL,
  `signo_pa_sis` int DEFAULT NULL,
  `asignatura` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `motivo_consulta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion_suceso` text COLLATE utf8mb4_unicode_ci,
  `tratamiento` text COLLATE utf8mb4_unicode_ci,
  `observaciones` text COLLATE utf8mb4_unicode_ci,
  `insumos_utilizados` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `registros_enfermeria_alumno_id_foreign` (`alumno_id`),
  KEY `registros_enfermeria_created_by_foreign` (`created_by`),
  KEY `registros_enfermeria_updated_by_foreign` (`updated_by`),
  CONSTRAINT `registros_enfermeria_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `registros_enfermeria_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `registros_enfermeria_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requisitos_alumno` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` int unsigned NOT NULL,
  `requisito_id` int unsigned NOT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Falta',
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `requisitos_alumno_alumno_id_foreign` (`alumno_id`),
  KEY `requisitos_alumno_requisito_id_foreign` (`requisito_id`),
  KEY `requisitos_alumno_updated_by_foreign` (`updated_by`),
  CONSTRAINT `requisitos_alumno_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `requisitos_alumno_requisito_id_foreign` FOREIGN KEY (`requisito_id`) REFERENCES `requisitos_matricula` (`id`) ON DELETE CASCADE,
  CONSTRAINT `requisitos_alumno_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requisitos_matricula` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `year_id` int unsigned NOT NULL,
  `orden` int unsigned DEFAULT '0',
  `requisito` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `editable_por_profe_id` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `requisitos_matricula_updated_by_foreign` (`updated_by`),
  CONSTRAINT `requisitos_matricula_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_user` (
  `user_id` int unsigned NOT NULL,
  `role_id` int unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `role_user_role_id_foreign` (`role_id`),
  CONSTRAINT `role_user_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `role_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subunidades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `definicion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `porcentaje` int DEFAULT '0',
  `unidad_id` int unsigned NOT NULL,
  `nota_default` int DEFAULT NULL,
  `obligatoria` tinyint(1) DEFAULT '0',
  `orden` int DEFAULT NULL,
  `por_defecto` tinyint(1) DEFAULT '0',
  `inicia_at` datetime DEFAULT NULL,
  `finaliza_at` datetime DEFAULT NULL,
  `actividad_id` int unsigned DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subunidades_unidad_id_foreign` (`unidad_id`),
  CONSTRAINT `subunidades_unidad_id_foreign` FOREIGN KEY (`unidad_id`) REFERENCES `unidades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subunidades_por_defecto` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `definicion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `porcentaje` int DEFAULT '0',
  `unidad_defec_id` int unsigned NOT NULL,
  `nota_default` int DEFAULT NULL,
  `obligatoria` tinyint(1) DEFAULT '0',
  `orden` int DEFAULT NULL,
  `inicia_at` datetime DEFAULT NULL,
  `finaliza_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subunidades_por_defecto_unidad_defec_id_foreign` (`unidad_defec_id`),
  CONSTRAINT `subunidades_por_defecto_unidad_defec_id_foreign` FOREIGN KEY (`unidad_defec_id`) REFERENCES `unidades_por_defecto` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tipos_documentos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abrev` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `unidades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `definicion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `porcentaje` int DEFAULT '0',
  `periodo_id` int unsigned NOT NULL,
  `asignatura_id` int unsigned NOT NULL,
  `obligatoria` tinyint(1) DEFAULT '0',
  `orden` int DEFAULT NULL,
  `por_defecto` tinyint(1) DEFAULT '0',
  `fecha` date DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `unidades_periodo_id_foreign` (`periodo_id`),
  KEY `unidades_asignatura_id_foreign` (`asignatura_id`),
  CONSTRAINT `unidades_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `unidades_periodo_id_foreign` FOREIGN KEY (`periodo_id`) REFERENCES `periodos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `unidades_por_defecto` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `definicion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `porcentaje` int DEFAULT '0',
  `year_id` int unsigned NOT NULL,
  `obligatoria` tinyint(1) DEFAULT '0',
  `orden` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `unidades_por_defecto_year_id_foreign` (`year_id`),
  CONSTRAINT `unidades_por_defecto_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `uniformes` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sexo` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'M',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imagen_id` int DEFAULT NULL,
  `is_superuser` tinyint(1) NOT NULL DEFAULT '0',
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `can_ask` tinyint(1) NOT NULL DEFAULT '1',
  `periodo_id` int unsigned DEFAULT NULL,
  `profesor_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`),
  KEY `users_periodo_id_foreign` (`periodo_id`),
  CONSTRAINT `users_periodo_id_foreign` FOREIGN KEY (`periodo_id`) REFERENCES `periodos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vt_aspiraciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `aspiracion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abrev` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `votacion_id` int unsigned NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vt_aspiraciones_votacion_id_foreign` (`votacion_id`),
  CONSTRAINT `vt_aspiraciones_votacion_id_foreign` FOREIGN KEY (`votacion_id`) REFERENCES `vt_votaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vt_candidatos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `aspiracion_id` int unsigned NOT NULL,
  `plancha` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vt_candidatos_user_id_foreign` (`user_id`),
  KEY `vt_candidatos_aspiracion_id_foreign` (`aspiracion_id`),
  CONSTRAINT `vt_candidatos_aspiracion_id_foreign` FOREIGN KEY (`aspiracion_id`) REFERENCES `vt_aspiraciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vt_candidatos_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vt_participantes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `grupo_profes_acudientes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `votacion_id` int unsigned NOT NULL,
  `locked` tinyint(1) NOT NULL,
  `intentos` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vt_participantes_votacion_id_foreign` (`votacion_id`),
  CONSTRAINT `vt_participantes_votacion_id_foreign` FOREIGN KEY (`votacion_id`) REFERENCES `vt_votaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vt_votaciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `year_id` int unsigned DEFAULT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `votan_profes` tinyint(1) NOT NULL DEFAULT '1',
  `votan_acudientes` tinyint(1) NOT NULL DEFAULT '0',
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `actual` tinyint(1) NOT NULL DEFAULT '0',
  `in_action` tinyint(1) NOT NULL DEFAULT '0',
  `can_see_results` tinyint(1) NOT NULL DEFAULT '0',
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vt_votaciones_user_id_foreign` (`user_id`),
  KEY `vt_votaciones_year_id_foreign` (`year_id`),
  CONSTRAINT `vt_votaciones_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vt_votaciones_year_id_foreign` FOREIGN KEY (`year_id`) REFERENCES `years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vt_votos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `candidato_id` int unsigned DEFAULT NULL,
  `blanco_aspiracion_id` int unsigned DEFAULT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vt_votos_user_id_foreign` (`user_id`),
  KEY `vt_votos_candidato_id_foreign` (`candidato_id`),
  CONSTRAINT `vt_votos_candidato_id_foreign` FOREIGN KEY (`candidato_id`) REFERENCES `vt_candidatos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vt_votos_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_actividades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `periodo_id` int unsigned DEFAULT NULL,
  `asignatura_id` int unsigned NOT NULL,
  `compartida` tinyint(1) DEFAULT '0',
  `para_alumnos` tinyint(1) NOT NULL DEFAULT '0',
  `para_profesores` tinyint(1) NOT NULL DEFAULT '0',
  `para_acudientes` tinyint(1) NOT NULL DEFAULT '0',
  `can_upload` tinyint(1) NOT NULL DEFAULT '0',
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Contenido',
  `in_action` tinyint(1) DEFAULT '0',
  `duracion_preg` int DEFAULT '60',
  `duracion_exam` int DEFAULT '20',
  `oportunidades` int DEFAULT '1',
  `one_by_one` tinyint(1) DEFAULT NULL,
  `tipo_calificacion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Por promedio',
  `contenido` text COLLATE utf8mb4_unicode_ci,
  `inicia_at` datetime DEFAULT NULL,
  `finaliza_at` datetime DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ws_actividades_periodo_id_foreign` (`periodo_id`),
  KEY `ws_actividades_asignatura_id_foreign` (`asignatura_id`),
  KEY `ws_actividades_created_by_foreign` (`created_by`),
  CONSTRAINT `ws_actividades_asignatura_id_foreign` FOREIGN KEY (`asignatura_id`) REFERENCES `asignaturas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ws_actividades_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ws_actividades_periodo_id_foreign` FOREIGN KEY (`periodo_id`) REFERENCES `periodos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_actividades_compartidas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `actividad_id` int unsigned DEFAULT NULL,
  `grupo_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ws_actividades_compartidas_actividad_id_foreign` (`actividad_id`),
  KEY `ws_actividades_compartidas_grupo_id_foreign` (`grupo_id`),
  CONSTRAINT `ws_actividades_compartidas_actividad_id_foreign` FOREIGN KEY (`actividad_id`) REFERENCES `ws_actividades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ws_actividades_compartidas_grupo_id_foreign` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_actividades_resueltas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `persona_id` int unsigned NOT NULL,
  `actividad_id` int unsigned NOT NULL,
  `respuesta_comentario` text COLLATE utf8mb4_unicode_ci,
  `autoevaluacion` int NOT NULL DEFAULT '0',
  `is_puntaje_manual` tinyint(1) NOT NULL DEFAULT '0',
  `puntaje_manual` int NOT NULL DEFAULT '0',
  `terminado` tinyint(1) NOT NULL DEFAULT '0',
  `timeout` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ws_actividades_resueltas_actividad_id_foreign` (`actividad_id`),
  CONSTRAINT `ws_actividades_resueltas_actividad_id_foreign` FOREIGN KEY (`actividad_id`) REFERENCES `ws_actividades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_contenidos_preg` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `enunciado` text COLLATE utf8mb4_unicode_ci,
  `actividad_id` int unsigned NOT NULL,
  `orden` int unsigned DEFAULT '0',
  `is_cuadricula` tinyint(1) DEFAULT '0',
  `added_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ws_contenidos_preg_actividad_id_foreign` (`actividad_id`),
  KEY `ws_contenidos_preg_added_by_foreign` (`added_by`),
  CONSTRAINT `ws_contenidos_preg_actividad_id_foreign` FOREIGN KEY (`actividad_id`) REFERENCES `ws_actividades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ws_contenidos_preg_added_by_foreign` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_opciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `definicion` text COLLATE utf8mb4_unicode_ci,
  `pregunta_id` int unsigned NOT NULL,
  `image_id` int unsigned DEFAULT NULL,
  `orden` int unsigned DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ws_opciones_pregunta_id_foreign` (`pregunta_id`),
  CONSTRAINT `ws_opciones_pregunta_id_foreign` FOREIGN KEY (`pregunta_id`) REFERENCES `ws_preguntas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_opciones_cuadricula` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `definicion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contenido_id` int unsigned NOT NULL,
  `icono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ws_opciones_cuadricula_contenido_id_foreign` (`contenido_id`),
  CONSTRAINT `ws_opciones_cuadricula_contenido_id_foreign` FOREIGN KEY (`contenido_id`) REFERENCES `ws_contenidos_preg` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_preguntas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `enunciado` text COLLATE utf8mb4_unicode_ci,
  `actividad_id` int unsigned DEFAULT NULL,
  `contenido_id` int unsigned DEFAULT NULL,
  `ayuda` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_pregunta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` int unsigned DEFAULT '0',
  `puntos` int unsigned NOT NULL DEFAULT '0',
  `duracion` int unsigned DEFAULT NULL,
  `aleatorias` tinyint(1) DEFAULT '0',
  `opcion_otra` tinyint(1) NOT NULL DEFAULT '0',
  `texto_arriba` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `texto_abajo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ws_preguntas_actividad_id_foreign` (`actividad_id`),
  KEY `ws_preguntas_contenido_id_foreign` (`contenido_id`),
  KEY `ws_preguntas_added_by_foreign` (`added_by`),
  CONSTRAINT `ws_preguntas_actividad_id_foreign` FOREIGN KEY (`actividad_id`) REFERENCES `ws_actividades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ws_preguntas_added_by_foreign` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ws_preguntas_contenido_id_foreign` FOREIGN KEY (`contenido_id`) REFERENCES `ws_contenidos_preg` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_respuestas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `actividad_resuelta_id` int unsigned DEFAULT NULL,
  `pregunta_id` int unsigned DEFAULT NULL,
  `tiempo` int unsigned DEFAULT NULL,
  `tipo_pregunta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opcion_id` int unsigned DEFAULT NULL,
  `opcion_cuadricula_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ws_respuestas_actividad_resuelta_id_foreign` (`actividad_resuelta_id`),
  KEY `ws_respuestas_pregunta_id_foreign` (`pregunta_id`),
  KEY `ws_respuestas_opcion_id_foreign` (`opcion_id`),
  KEY `ws_respuestas_opcion_cuadricula_id_foreign` (`opcion_cuadricula_id`),
  CONSTRAINT `ws_respuestas_actividad_resuelta_id_foreign` FOREIGN KEY (`actividad_resuelta_id`) REFERENCES `ws_actividades_resueltas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ws_respuestas_opcion_cuadricula_id_foreign` FOREIGN KEY (`opcion_cuadricula_id`) REFERENCES `ws_opciones_cuadricula` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ws_respuestas_opcion_id_foreign` FOREIGN KEY (`opcion_id`) REFERENCES `ws_opciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ws_respuestas_pregunta_id_foreign` FOREIGN KEY (`pregunta_id`) REFERENCES `ws_preguntas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `years` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `year` int NOT NULL,
  `nombre_colegio` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abrev_colegio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `genero_colegio` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'F',
  `ciudad_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_id` int unsigned DEFAULT NULL,
  `img_encabezado_id` int unsigned DEFAULT NULL,
  `rector_id` int DEFAULT NULL,
  `secretario_id` int DEFAULT NULL,
  `tesorero_id` int DEFAULT NULL,
  `coordinador_academico_id` int DEFAULT NULL,
  `coordinador_disciplinario_id` int DEFAULT NULL,
  `capellan_id` int DEFAULT NULL,
  `psicorientador_id` int DEFAULT NULL,
  `nota_minima_aceptada` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '70',
  `minu_hora_clase` int DEFAULT '50',
  `unidad_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unidad',
  `unidades_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unidades',
  `genero_unidad` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'F',
  `subunidad_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Subunidad',
  `subunidades_displayname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Subunidades',
  `genero_subunidad` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'F',
  `resolucion` text COLLATE utf8mb4_unicode_ci,
  `codigo_dane` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `caracter` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Privado',
  `calendario` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'A',
  `jornada` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Mañana y tarde',
  `encabezado_certificado` text COLLATE utf8mb4_unicode_ci,
  `frase_final_certificado` text COLLATE utf8mb4_unicode_ci,
  `actual` tinyint(1) NOT NULL DEFAULT '0',
  `telefono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_myvc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alumnos_can_see_notas` tinyint(1) NOT NULL DEFAULT '0',
  `profes_can_edit_alumnos` tinyint(1) NOT NULL DEFAULT '0',
  `mostrar_puesto_boletin` tinyint(1) NOT NULL DEFAULT '1',
  `puestos_alfabeticamente` tinyint(1) NOT NULL DEFAULT '0',
  `titulo_rector` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mostrar_nota_comport_boletin` tinyint(1) NOT NULL DEFAULT '1',
  `si_recupera_materia_recup_indicador` tinyint(1) NOT NULL DEFAULT '1',
  `year_pasado_en_bol` tinyint(1) NOT NULL DEFAULT '1',
  `show_fortaleza_bol` tinyint(1) NOT NULL DEFAULT '0',
  `solo_escalas_valorativas` tinyint(1) NOT NULL DEFAULT '0',
  `config_certificado_estudio_id` int unsigned DEFAULT NULL,
  `cant_areas_pierde_year` int DEFAULT '0',
  `cant_asignatura_pierde_year` int DEFAULT '0',
  `show_subasignaturas_en_finales` tinyint(1) NOT NULL DEFAULT '1',
  `mensaje_aprobo_con_pendientes` tinyint(1) NOT NULL DEFAULT '1',
  `show_materias_todas` tinyint(1) NOT NULL DEFAULT '1',
  `msg_when_students_blocked` text COLLATE utf8mb4_unicode_ci,
  `contador_certificados` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `contador_folios` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `texto_acta_eval` text COLLATE utf8mb4_unicode_ci,
  `prematr_antiguos` tinyint(1) DEFAULT '0',
  `prematr_nuevos` tinyint(1) DEFAULT '0',
  `compromiso_familiar_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` VALUES (1,'2014_01_09_092929_create_years_table',1);
INSERT INTO `migrations` VALUES (2,'2014_01_09_093229_create_pais_table',1);
INSERT INTO `migrations` VALUES (3,'2014_01_09_100831_create_periodos_table',1);
INSERT INTO `migrations` VALUES (4,'2014_01_09_102823_create_ciudades_table',1);
INSERT INTO `migrations` VALUES (5,'2014_01_09_105333_create_tipos_documentos_table',1);
INSERT INTO `migrations` VALUES (6,'2014_10_12_000000_create_users_table',1);
INSERT INTO `migrations` VALUES (7,'2014_10_12_100000_create_password_resets_table',1);
INSERT INTO `migrations` VALUES (8,'2014_12_16_002500_create_profesores_table',1);
INSERT INTO `migrations` VALUES (9,'2014_12_16_002600_create_alumnos_table',1);
INSERT INTO `migrations` VALUES (10,'2014_12_16_002700_create_acudientes_table',1);
INSERT INTO `migrations` VALUES (11,'2014_12_16_002800_create_areas_table',1);
INSERT INTO `migrations` VALUES (12,'2014_12_16_002900_create_materias_table',1);
INSERT INTO `migrations` VALUES (13,'2014_12_16_003000_create_niveles_educativos_table',1);
INSERT INTO `migrations` VALUES (14,'2014_12_16_003100_create_grados_table',1);
INSERT INTO `migrations` VALUES (15,'2014_12_16_003200_create_grupos_table',1);
INSERT INTO `migrations` VALUES (16,'2014_12_16_003300_create_asignaturas_table',1);
INSERT INTO `migrations` VALUES (17,'2014_12_16_003400_create_matriculas_table',1);
INSERT INTO `migrations` VALUES (18,'2014_12_16_003500_create_contratos_table',1);
INSERT INTO `migrations` VALUES (19,'2014_12_16_004000_create_unidades_table',1);
INSERT INTO `migrations` VALUES (20,'2014_12_16_004100_create_subunidades_table',1);
INSERT INTO `migrations` VALUES (21,'2014_12_16_004200_create_notas_table',1);
INSERT INTO `migrations` VALUES (22,'2014_12_16_004300_create_frases_table',1);
INSERT INTO `migrations` VALUES (23,'2014_12_16_004400_create_nota_comportamiento_table',1);
INSERT INTO `migrations` VALUES (24,'2014_12_16_004500_create_definiciones_comportamiento_table',1);
INSERT INTO `migrations` VALUES (25,'2014_12_16_004600_create_ausencias_table',1);
INSERT INTO `migrations` VALUES (26,'2014_12_16_005000_create_escalas_de_valoracion_table',1);
INSERT INTO `migrations` VALUES (27,'2015_01_27_140941_create_vt_votaciones_table',1);
INSERT INTO `migrations` VALUES (28,'2015_02_08_101748_create_password_reminders_table',1);
INSERT INTO `migrations` VALUES (29,'2015_02_11_204800_create_images_table',1);
INSERT INTO `migrations` VALUES (30,'2015_02_11_205425_create_change_asked_table',1);
INSERT INTO `migrations` VALUES (31,'2015_02_19_211324_create_parentescos_table',1);
INSERT INTO `migrations` VALUES (32,'2015_02_20_180106_create_bitacoras_table',1);
INSERT INTO `migrations` VALUES (33,'2015_03_10_092836_create_frases_asignatura_table',1);
INSERT INTO `migrations` VALUES (34,'2015_04_27_205649_entrust_setup_tables',1);
INSERT INTO `migrations` VALUES (35,'2015_10_26_092836_create_default_unidades_table',1);
INSERT INTO `migrations` VALUES (36,'2015_10_26_092837_create_debugging_table',1);
INSERT INTO `migrations` VALUES (37,'2017_06_05_092837_create_actividades_table',1);
INSERT INTO `migrations` VALUES (39,'2018_01_05_092837_create_definitivas_table',1);
INSERT INTO `migrations` VALUES (40,'2018_01_07_092837_create_notasfinales_table',1);
INSERT INTO `migrations` VALUES (41,'2018_10_10_092837_create_publicaciones_table',2);
INSERT INTO `migrations` VALUES (42,'2018_10_15_092837_create_calendario_table',3);
INSERT INTO `migrations` VALUES (45,'2018_10_19_174951_create_jobs_table',4);
INSERT INTO `migrations` VALUES (46,'2018_10_24_092837_create_requisitos_table',4);
INSERT INTO `migrations` VALUES (47,'2018_11_03_092837_create_enfermeria_table',5);
INSERT INTO `migrations` VALUES (48,'2018_11_16_092837_create_recuperacionfinal_table',5);
INSERT INTO `migrations` VALUES (49,'2018_11_16_092837_create_frasesfinalprees_table',6);
INSERT INTO `migrations` VALUES (50,'2018_12_25_092837_create_disciplina_table',7);
