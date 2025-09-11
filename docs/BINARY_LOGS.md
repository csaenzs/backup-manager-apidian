# 📊 Configuración de Binary Logs para Backups Incrementales

## 🎯 ¿Qué son los Binary Logs?

Los **Binary Logs** de MySQL/MariaDB registran todos los cambios (INSERT, UPDATE, DELETE) realizados en la base de datos. Esto permite crear **backups incrementales reales** que solo contienen los cambios desde el último backup completo.

## 🚀 Ventajas de los Backups Incrementales de BD

### Para Bases de Datos Grandes (50GB+):

| Tipo de Backup | Tamaño | Tiempo | Frecuencia |
|----------------|--------|---------|------------|
| **Completo** | 50GB → 15GB comprimido | 30-45 min | Semanal |
| **Incremental** | 50MB-2GB (solo cambios) | 2-5 min | Diario |

### 💰 Ahorro Real:
- **Espacio**: 90% menos espacio por backup incremental
- **Tiempo**: 95% menos tiempo por backup
- **Recursos**: Sin impacto significativo en el servidor

## ⚙️ Configuración Automática

El script de instalación configura automáticamente los binary logs:

```bash
sudo /var/www/html/backup-manager/install.sh
```

### Configuración que se aplica:

```ini
# /etc/mysql/mysql.conf.d/mysqld.cnf
[mysqld]
log-bin = mysql-bin
binlog_format = ROW
expire_logs_days = 7
max_binlog_size = 100M
server-id = 1
```

## 🔧 Configuración Manual

Si necesitas configurar manualmente:

### 1. Editar configuración MySQL:

```bash
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

### 2. Agregar configuración:

```ini
[mysqld]
# Binary logging para backups incrementales
log-bin = mysql-bin
binlog_format = ROW
expire_logs_days = 7
max_binlog_size = 100M
server-id = 1
```

### 3. Reiniciar MySQL:

```bash
sudo systemctl restart mysql
# o
sudo systemctl restart mariadb
```

### 4. Verificar configuración:

```sql
SHOW VARIABLES LIKE 'log_bin';
SHOW MASTER STATUS;
SHOW BINARY LOGS;
```

## 📈 Cómo Funcionan los Backups Incrementales

### 1. **Primer Backup (Completo)**:
- Dump completo de la base de datos
- Guarda posición actual del binary log: `mysql-bin.000001:1234`

### 2. **Backups Siguientes (Incrementales)**:
- Lee binary logs desde la última posición guardada
- Extrae solo los cambios (INSERT/UPDATE/DELETE)
- Guarda nueva posición: `mysql-bin.000001:5678`

### 3. **Restauración**:
- Restaurar último backup completo
- Aplicar backups incrementales en orden cronológico

## 🛠️ Comandos Útiles

### Verificar binary logs:
```bash
mysql -u usuario -p -e "SHOW BINARY LOGS;"
```

### Ver posición actual:
```bash
mysql -u usuario -p -e "SHOW MASTER STATUS;"
```

### Limpiar logs antiguos:
```bash
mysql -u usuario -p -e "PURGE BINARY LOGS BEFORE '2023-01-01';"
```

## 📊 Monitoreo del Sistema

El Backup Manager monitorea automáticamente:

- ✅ Estado de binary logging
- 📊 Posición actual de logs
- 🗑️ Limpieza automática de logs antiguos
- 📈 Estadísticas de espacio ahorrado

## ⚠️ Consideraciones Importantes

### Espacio en Disco:
- Binary logs ocupan espacio adicional (configurado: 100MB por archivo)
- Se limpian automáticamente después de 7 días
- Monitorear espacio en `/var/lib/mysql/`

### Rendimiento:
- Impacto mínimo en rendimiento (<5%)
- Los logs se escriben de forma asíncrona
- Compresión automática de backups incrementales

### Seguridad:
- Los binary logs contienen todos los cambios de datos
- Mantener permisos restrictivos
- Los backups incrementales se comprimen y protegen

## 🚨 Troubleshooting

### Binary logging no se habilita:

1. **Verificar permisos**: MySQL debe poder escribir en `/var/lib/mysql/`
2. **Verificar espacio**: Suficiente espacio en disco
3. **Verificar sintaxis**: Configuración correcta en `my.cnf`
4. **Ver logs**: `sudo journalctl -u mysql -f`

### Backups incrementales fallan:

1. **Verificar conexión**: Usuario debe tener permisos `REPLICATION SLAVE`
2. **Verificar logs**: Binary logs disponibles y accesibles
3. **Verificar posición**: Posición guardada es válida

### Comandos de diagnóstico:
```bash
# Ver estado de binary logging
mysql -e "SHOW VARIABLES LIKE 'log_bin%';"

# Ver logs de MySQL
sudo tail -f /var/log/mysql/error.log

# Verificar permisos de usuario
mysql -e "SHOW GRANTS FOR 'usuario'@'localhost';"
```

## 📚 Referencias

- [MySQL Binary Log](https://dev.mysql.com/doc/refman/8.0/en/binary-log.html)
- [MariaDB Binary Log](https://mariadb.com/kb/en/binary-log/)
- [Point-in-Time Recovery](https://dev.mysql.com/doc/refman/8.0/en/point-in-time-recovery.html)