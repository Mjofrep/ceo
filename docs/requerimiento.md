# Requerimiento del Sistema CEONext

## 1. Objetivo general

CEONext es una plataforma web para gestionar el ciclo completo de solicitudes, planificacion, evaluacion y habilitacion/formacion de personas en servicios operacionales, con trazabilidad de resultados, vigencias y reportes.

---

## 2. Grandes areas del sistema

## 2.1 Autenticacion, seguridad y sesion

### Que incluye
- Login de usuarios con control por roles.
- Gestion de sesion activa e inactividad.
- Proteccion CSRF en formularios.
- Restriccion de acceso a paginas protegidas.

### Que hace
- Permite acceso seguro segun perfil.
- Evita operaciones sin autenticacion.
- Reduce riesgo de solicitudes invalidas o no autorizadas.

---

## 2.2 Solicitudes y planificacion operativa

### Que incluye
- Creacion y gestion de solicitudes.
- Agenda/calendario de evaluaciones.
- Asignacion por cuadrilla, servicio, jornada y fecha.
- Estado de solicitud y trazabilidad.

### Que hace
- Ordena la demanda operativa de evaluacion.
- Define cuando, como y a quien evaluar.
- Permite seguimiento del flujo desde solicitud hasta cierre.

---

## 2.3 Evaluaciones teoricas y de terreno

### Que incluye
- Pruebas teoricas por banco de preguntas.
- Evaluacion en terreno por secciones y criterios.
- Registro de respuestas, resultados e intentos.
- Cierre por tiempo, cierre manual y salida controlada.

### Que hace
- Ejecuta evaluaciones formales con evidencia.
- Calcula resultado por participante.
- Permite validar desempeno en conocimiento y practica.

---

## 2.4 Habilitaciones y vigencias

### Que incluye
- Gestion de habilitaciones por servicio/proceso.
- Calculo y actualizacion de vigencias.
- Estados de aprobacion/reprobacion.
- Consolidacion de resultado final por servicio.

### Que hace
- Determina si la persona queda habilitada.
- Define periodo de validez de la habilitacion.
- Soporta decisiones operacionales y de cumplimiento.

---

## 2.5 Formaciones (modulo especializado)

### Que incluye
- Flujo propio de formacion (solicitud, programacion, evaluacion y revision).
- Banco de preguntas de formacion separado de habilitaciones.
- Seleccion de preguntas por area de competencia y porcentaje objetivo.
- Soporte de preguntas de alternativa y texto libre (con revision manual).
- Simulador de prueba sin persistencia de resultados.
- Reportes de cuadrilla, detalle por participante y exportacion.

### Que hace
- Ejecuta procesos de formacion con logica independiente.
- Evalua con ponderacion (`peso`) y metricas de desempeno.
- Permite detectar brechas por area de competencia y reforzamiento.

---

## 2.6 Mantenedores y catalogos maestros

### Que incluye
- Mantenedores de usuarios, roles, empresas, servicios, UO, procesos y areas.
- Parametrizacion de porcentajes de cumplimiento por area.
- Configuracion de preguntas, alternativas y agrupaciones.

### Que hace
- Centraliza la administracion funcional del sistema.
- Permite adaptar reglas sin cambios estructurales mayores.
- Mantiene coherencia de datos entre modulos.

---

## 2.7 Reportabilidad e integracion documental

### Que incluye
- Informes operativos y de gestion.
- Exportaciones a Excel.
- Soporte para generacion de documentos y notificaciones.

### Que hace
- Entrega visibilidad para gestion y auditoria.
- Facilita analisis por servicio, cuadrilla y participante.
- Mejora comunicacion entre areas involucradas.

---

## 3. Requerimientos funcionales principales

1. Autenticacion y autorizacion por rol.
2. Registro y administracion de solicitudes.
3. Programacion de evaluaciones por servicio y cuadrilla.
4. Ejecucion de pruebas teoricas y de terreno.
5. Calculo de resultados con ponderacion y estados.
6. Gestion de habilitaciones y vigencias.
7. Gestion integral del modulo de formaciones.
8. Revision manual de respuestas de texto libre.
9. Simulacion de evaluacion sin persistencia.
10. Reportes y exportables por proceso y participante.
11. Parametrizacion de areas de competencia y porcentajes objetivo.

---

## 4. Requerimientos no funcionales

- Seguridad de sesion, control de cache y tokens CSRF.
- Persistencia en MySQL mediante PDO.
- Compatibilidad con navegadores modernos (Bootstrap 5).
- Operacion en entorno PHP + MySQL (LAMP/MAMP).
- Mantenibilidad bajo arquitectura PHP procedural existente.
- Trazabilidad minima de eventos y resultados en base de datos.

---

## 5. Datos y entidades clave

- Solicitudes y participantes.
- Formaciones e habilitaciones.
- Evaluaciones programadas e intentos.
- Preguntas, alternativas y areas de competencia.
- Resultados por pregunta y resultados consolidados.
- Vigencias por servicio/proceso.
- Catalogos maestros (servicios, empresas, UO, procesos, roles, usuarios).

---

## 6. Criterios de valor del sistema

- Estandariza evaluaciones y decisiones de habilitacion.
- Mejora la trazabilidad de desempeno individual y por cuadrilla.
- Permite analisis por areas de competencia para reforzamiento.
- Reduce riesgo operativo mediante control formal del proceso.
