-- Datos mínimos para poder entrar y probar el sistema.
USE `sistema_requisiciones`;
SET NAMES utf8mb4;

-- Usuario demo: admin / admin123  (solo para pruebas locales; cámbiala).
INSERT INTO `usuarios` (`id`,`username`,`password`,`nombre_completo`,`email`,`tipo_usuario`,`activo`) VALUES
(1,'admin','$2y$10$mBJODSmxWNZ0xJ81DHjGGunDPF/oS1Yb0DqCXXlREG0aaK0dX45Yu','Administrador Demo','admin@ejemplo.com','admin',1);

INSERT INTO `categorias` (`id`,`nombre`,`descripcion`,`icono`,`activo`) VALUES
(1,'Componente Principal',NULL,NULL,1),
(2,'Conector',NULL,NULL,1),
(3,'Tornillería',NULL,NULL,1),
(4,'Materia Prima',NULL,NULL,1),
(5,'Material Eléctrico',NULL,NULL,1),
(6,'Conector Neumático',NULL,NULL,1),
(7,'Empresa Ejemplo',NULL,NULL,1),
(8,'Herramienta',NULL,NULL,1),
(9,'Equipo',NULL,NULL,1),
(10,'Accesorio',NULL,NULL,1),
(11,'Recubrimiento',NULL,NULL,1),
(12,'Consumible',NULL,NULL,1),
(13,'Instrumento',NULL,NULL,1),
(14,'Material Mecánico',NULL,NULL,1);
