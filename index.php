<?php
session_start();
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Simple authentication check
if (!isset($_SESSION['authenticated']) && !isset($_POST['password'])) {
    include __DIR__ . '/views/login.php';
    exit;
}

if (isset($_POST['password'])) {
    if (Auth::login($_POST['password'])) {
        $_SESSION['authenticated'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Contraseña incorrecta';
        include __DIR__ . '/views/login.php';
        exit;
    }
}

// Check authentication
if (!Auth::isAuthenticated()) {
    header('Location: index.php?logout=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Manager - <?php echo gethostname(); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/navigation.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>🛡️ Backup Manager</h1>
            <div class="header-info">
                <span class="server-name"><?php echo gethostname(); ?></span>
                <a href="?logout=1" class="btn-logout">Cerrar Sesión</a>
            </div>
        </header>

        <!-- Navigation Menu -->
        <nav class="main-nav">
            <ul class="nav-tabs">
                <li class="nav-item active" data-tab="dashboard">
                    <span class="nav-icon">📊</span>
                    <span class="nav-text">Dashboard</span>
                </li>
                <li class="nav-item" data-tab="backup">
                    <span class="nav-icon">💾</span>
                    <span class="nav-text">Backup</span>
                </li>
                <li class="nav-item" data-tab="history">
                    <span class="nav-icon">📜</span>
                    <span class="nav-text">Historial</span>
                </li>
                <li class="nav-item" data-tab="schedule">
                    <span class="nav-icon">⏰</span>
                    <span class="nav-text">Programación</span>
                </li>
                <!-- BACKUP REMOTO DESHABILITADO - Usar rsync manual si es necesario
                <li class="nav-item" data-tab="remote">
                    <span class="nav-icon">🌐</span>
                    <span class="nav-text">Remoto</span>
                </li>
                -->
                <li class="nav-item" data-tab="settings">
                    <span class="nav-icon">⚙️</span>
                    <span class="nav-text">Configuración</span>
                </li>
                <li class="nav-item" data-tab="security">
                    <span class="nav-icon">🔒</span>
                    <span class="nav-text">Seguridad</span>
                </li>
                <li class="nav-item" data-tab="logs">
                    <span class="nav-icon">📋</span>
                    <span class="nav-text">Logs</span>
                </li>
            </ul>
        </nav>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- Dashboard Tab -->
            <div class="tab-pane active" id="dashboard-tab">
                <div class="dashboard">
                    <!-- Status Cards -->
                    <div class="status-cards">
                        <div class="card">
                            <h3>📁 Storage</h3>
                            <div class="card-content">
                                <p class="size" id="storage-size">Calculando...</p>
                                <p class="path"><?php echo Config::get('storage_path'); ?></p>
                            </div>
                        </div>
                        <div class="card">
                            <h3>🗄️ Base de Datos</h3>
                            <div class="card-content">
                                <p class="size" id="db-size">Calculando...</p>
                                <p class="path"><?php echo Config::get('db_name'); ?></p>
                            </div>
                        </div>
                        <div class="card">
                            <h3>📅 Último Backup</h3>
                            <div class="card-content">
                                <p class="size" id="last-backup-time">Sin datos</p>
                                <p class="path" id="last-backup-type">-</p>
                            </div>
                        </div>
                        <div class="card">
                            <h3>💿 Espacio en Disco</h3>
                            <div class="card-content">
                                <p class="size" id="disk-space">Calculando...</p>
                                <p class="path" id="disk-usage">-</p>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <h2>🚀 Acciones Rápidas</h2>
                        <div class="action-buttons">
                            <button onclick="switchTab('backup'); startBackup('full')" class="btn btn-primary">
                                🔄 Backup Completo
                            </button>
                            <button onclick="switchTab('backup'); startBackup('database')" class="btn btn-secondary">
                                🗄️ Solo Base de Datos
                            </button>
                            <button onclick="switchTab('backup'); startBackup('storage')" class="btn btn-secondary">
                                📁 Solo Storage
                            </button>
                            <button onclick="switchTab('history')" class="btn btn-info">
                                📜 Ver Historial
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Backup Tab -->
            <div class="tab-pane" id="backup-tab">
                <div class="backup-section">
                    <h2>💾 Crear Nuevo Backup</h2>

                    <!-- Backup Options -->
                    <div class="backup-options">
                        <div class="backup-card" onclick="startBackup('full')">
                            <div class="backup-icon">🔄</div>
                            <h3>Backup Completo</h3>
                            <p>Base de datos + Storage</p>
                            <button class="btn btn-primary">Iniciar</button>
                        </div>
                        <div class="backup-card" onclick="startBackup('database')">
                            <div class="backup-icon">🗄️</div>
                            <h3>Base de Datos</h3>
                            <p>Solo base de datos MySQL</p>
                            <button class="btn btn-secondary">Iniciar</button>
                        </div>
                        <div class="backup-card" onclick="startBackup('storage')">
                            <div class="backup-icon">📁</div>
                            <h3>Storage</h3>
                            <p>Solo archivos de storage</p>
                            <button class="btn btn-secondary">Iniciar</button>
                        </div>
                    </div>

                    <!-- Progress Section (hidden by default) -->
                    <div id="progress-section" class="progress-section" style="display: none;">
                        <h2>Progreso del Backup</h2>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" id="progress-fill"></div>
                            </div>
                            <p class="progress-text" id="progress-text">Iniciando...</p>
                            <p class="progress-details" id="progress-details"></p>
                        </div>
                        <pre class="progress-log" id="progress-log"></pre>
                    </div>
                </div>
            </div>

            <!-- History Tab -->
            <div class="tab-pane" id="history-tab">
                <div class="history-section">
                    <h2>📜 Historial de Backups</h2>
                    <div class="history-controls">
                        <button onclick="refreshHistory()" class="btn btn-small">🔄 Actualizar</button>
                        <button onclick="clearOldBackups()" class="btn btn-small btn-warning">🧹 Limpiar Antiguos</button>
                        <select id="history-filter" onchange="filterHistory()">
                            <option value="all">Todos</option>
                            <option value="full">Completos</option>
                            <option value="database">Base de Datos</option>
                            <option value="storage">Storage</option>
                        </select>
                    </div>
                    <div class="history-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Tamaño</th>
                                    <th>Duración</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="history-tbody">
                                <tr><td colspan="6">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Schedule Tab -->
            <div class="tab-pane" id="schedule-tab">
                <div class="schedule-section">
                    <h2>⏰ Programación de Backups</h2>
                    <div class="schedule-form">
                        <label>
                            <input type="checkbox" id="schedule-enabled"> Habilitar backups automáticos
                        </label>
                        <div class="schedule-options">
                            <label>Frecuencia:
                                <select id="schedule-frequency">
                                    <option value="daily">Diario</option>
                                    <option value="weekly">Semanal</option>
                                    <option value="monthly">Mensual</option>
                                </select>
                            </label>
                            <label>Hora:
                                <input type="time" id="schedule-time" value="03:00">
                            </label>
                            <label>Tipo:
                                <select id="schedule-type">
                                    <option value="full">Completo</option>
                                    <option value="incremental">Incremental</option>
                                </select>
                            </label>
                        </div>
                        <button onclick="saveSchedule()" class="btn btn-primary">💾 Guardar Programación</button>
                    </div>
                    <div class="cron-info">
                        <h3>📝 Configuración Cron Actual</h3>
                        <pre id="cron-config">No configurado</pre>
                        <p class="help-text">Para activar los backups automáticos, añade esta línea a tu crontab:</p>
                        <code>0 3 * * * php /var/www/html/backup-manager/cron/backup.php</code>
                    </div>
                </div>
            </div>

            <!-- BACKUP REMOTO DESHABILITADO
                 Razón: Usar rsync manual desde PuTTY es más práctico
                 Para reactivar: descomentar esta sección y el script remote.js

            <div class="tab-pane" id="remote-tab">
                <div class="remote-section">
                    <h2>🌐 Configuración de Backup Remoto</h2>
                    <div class="remote-status" id="remote-status">
                        <div class="status-card">
                            <h3>Estado de Configuración</h3>
                            <div id="remote-status-content">
                                <p>Cargando...</p>
                            </div>
                        </div>
                    </div>
                    <div class="remote-config">
                        <h3>⚙️ Configurar Servidor Remoto</h3>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="remote-enabled"> Habilitar backup remoto
                            </label>
                        </div>
                        <div class="form-group">
                            <label>Método de Transferencia:</label>
                            <select id="remote-method" onchange="updateRemoteForm()">
                                <option value="ssh">SSH/SCP</option>
                                <option value="ftp">FTP</option>
                                <option value="rsync">Rsync</option>
                                <option value="s3">S3 Compatible</option>
                            </select>
                        </div>
                        <div id="remote-form-ssh" class="remote-form">
                            <div class="form-group">
                                <label>Host/IP:</label>
                                <input type="text" id="ssh-host" placeholder="ejemplo.com o 192.168.1.100">
                            </div>
                            <div class="form-group">
                                <label>Puerto:</label>
                                <input type="number" id="ssh-port" value="22">
                            </div>
                            <div class="form-group">
                                <label>Usuario:</label>
                                <input type="text" id="ssh-user" placeholder="usuario">
                            </div>
                            <div class="form-group">
                                <label>Autenticación:</label>
                                <select id="ssh-auth" onchange="toggleSSHAuth()">
                                    <option value="password">Contraseña</option>
                                    <option value="key">Clave SSH</option>
                                </select>
                            </div>
                            <div class="form-group" id="ssh-password-group">
                                <label>Contraseña:</label>
                                <input type="password" id="ssh-password" placeholder="contraseña">
                            </div>
                            <div class="form-group" id="ssh-key-group" style="display:none;">
                                <label>Archivo de Clave:</label>
                                <input type="text" id="ssh-key" placeholder="/root/.ssh/id_rsa">
                            </div>
                            <div class="form-group">
                                <label>Directorio Remoto:</label>
                                <input type="text" id="ssh-path" placeholder="/backup/apidian">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="remote-keep-local" checked> Mantener copia local
                            </label>
                        </div>
                        <div class="form-actions">
                            <button onclick="testRemoteConnection()" class="btn btn-secondary">🧪 Probar Conexión</button>
                            <button onclick="saveRemoteConfig()" class="btn btn-primary">💾 Guardar Configuración</button>
                        </div>
                        <div id="remote-test-result" class="test-result"></div>
                    </div>
                </div>
            </div>
            FIN BACKUP REMOTO DESHABILITADO -->

            <!-- Settings Tab -->
            <div class="tab-pane" id="settings-tab">
                <div class="settings-section">
                    <h2>⚙️ Configuración General</h2>
                    <div class="settings-grid">
                        <div class="setting-item">
                            <label>Días de retención:</label>
                            <input type="number" id="retention-days" value="<?php echo Config::get('retention_days', 30); ?>" min="1" max="365">
                            <small>Backups más antiguos serán eliminados automáticamente</small>
                        </div>
                        <div class="setting-item">
                            <label>Compresión:</label>
                            <select id="compression-level">
                                <option value="none">Sin compresión</option>
                                <option value="low">Baja (rápida)</option>
                                <option value="medium" selected>Media (balanceada)</option>
                                <option value="high">Alta (lenta)</option>
                            </select>
                            <small>Mayor compresión = archivos más pequeños pero más tiempo</small>
                        </div>
                        <div class="setting-item">
                            <label>Estrategia de Backup:</label>
                            <select id="backup-strategy">
                                <option value="full">Siempre completo</option>
                                <option value="incremental">Incremental cuando sea posible</option>
                            </select>
                            <small>Incremental ahorra espacio pero requiere backup base</small>
                        </div>
                        <div class="setting-item">
                            <label>Notificaciones Email:</label>
                            <input type="email" id="notification-email" placeholder="admin@ejemplo.com">
                            <small>Recibe alertas de backups fallidos</small>
                        </div>
                    </div>
                    <button onclick="saveSettings()" class="btn btn-primary">💾 Guardar Configuración</button>
                </div>
            </div>

            <!-- Security Tab -->
            <div class="tab-pane" id="security-tab">
                <div class="settings-section">
                    <h2>🔒 Configuración de Seguridad</h2>
                    <div class="security-form">
                        <div class="setting-item">
                            <label>Contraseña actual:</label>
                            <input type="password" id="current-password" placeholder="Contraseña actual">
                        </div>
                        <div class="setting-item">
                            <label>Nueva contraseña:</label>
                            <input type="password" id="new-password" placeholder="Nueva contraseña (mínimo 8 caracteres)">
                        </div>
                        <div class="setting-item">
                            <label>Confirmar nueva contraseña:</label>
                            <input type="password" id="confirm-password" placeholder="Confirmar nueva contraseña">
                        </div>
                        <div class="password-strength">
                            <div class="strength-bar" id="strength-bar">
                                <div class="strength-fill" id="strength-fill"></div>
                            </div>
                            <span class="strength-text" id="strength-text">Fortaleza de la contraseña</span>
                        </div>
                    </div>
                    <button onclick="changePassword()" class="btn btn-primary">🔐 Cambiar Contraseña</button>
                    <p class="security-note">⚠️ <strong>Importante:</strong> La contraseña por defecto es "admin123". Es altamente recomendable cambiarla.</p>

                    <div class="security-additional">
                        <h3>🛡️ Configuración Adicional</h3>
                        <div class="setting-item">
                            <label>
                                <input type="checkbox" id="enable-2fa"> Habilitar autenticación de dos factores (2FA)
                            </label>
                        </div>
                        <div class="setting-item">
                            <label>
                                <input type="checkbox" id="enable-ip-whitelist"> Restringir acceso por IP
                            </label>
                            <input type="text" id="ip-whitelist" placeholder="192.168.1.0/24, 10.0.0.1" style="display:none;">
                        </div>
                        <div class="setting-item">
                            <label>Tiempo de sesión (minutos):</label>
                            <input type="number" id="session-timeout" value="30" min="5" max="1440">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Logs Tab -->
            <div class="tab-pane" id="logs-tab">
                <div class="logs-section">
                    <h2>📋 Logs del Sistema</h2>
                    <div class="logs-controls">
                        <select id="log-type" onchange="loadLogs()">
                            <option value="backup">Backup</option>
                            <option value="error">Errores</option>
                            <option value="security">Seguridad</option>
                            <option value="remote">Remoto</option>
                            <option value="all">Todos</option>
                        </select>
                        <input type="date" id="log-date" onchange="loadLogs()">
                        <button onclick="refreshLogs()" class="btn btn-small">🔄 Actualizar</button>
                        <button onclick="downloadLogs()" class="btn btn-small">📥 Descargar</button>
                    </div>
                    <div class="logs-viewer">
                        <pre id="logs-content">Selecciona un tipo de log para ver...</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
    <script src="assets/js/navigation.js"></script>
    <!-- <script src="assets/js/remote.js"></script> DESHABILITADO - Backup remoto desactivado -->
</body>
</html>