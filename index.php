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

// Load main dashboard
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Manager - Panel de Control</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
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
                    <h3>📊 Último Backup</h3>
                    <div class="card-content">
                        <p class="last-backup" id="last-backup">Verificando...</p>
                        <p class="next-backup" id="next-backup">-</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h2>Acciones Rápidas</h2>
                <div class="action-buttons">
                    <button onclick="startBackup('full')" class="btn btn-primary">
                        🔄 Backup Completo Ahora
                    </button>
                    <button onclick="startBackup('database')" class="btn btn-secondary">
                        🗄️ Solo Base de Datos
                    </button>
                    <button onclick="startBackup('storage')" class="btn btn-secondary">
                        📁 Solo Storage
                    </button>
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
                    <pre class="progress-log" id="progress-log"></pre>
                </div>
            </div>

            <!-- Schedule Section -->
            <div class="schedule-section">
                <h2>⏰ Programación de Backups</h2>
                <div class="schedule-form">
                    <label>
                        <input type="checkbox" id="enable-schedule"> Habilitar backup automático
                    </label>
                    <div class="schedule-options" id="schedule-options">
                        <select id="schedule-frequency">
                            <option value="daily">Diario</option>
                            <option value="weekly">Semanal</option>
                            <option value="monthly">Mensual</option>
                        </select>
                        <input type="time" id="schedule-time" value="01:00">
                        <button onclick="saveSchedule()" class="btn btn-save">Guardar</button>
                    </div>
                </div>
            </div>

            <!-- Backup History -->
            <div class="history-section">
                <h2>📜 Historial de Backups</h2>
                <div class="history-controls">
                    <button onclick="refreshHistory()" class="btn btn-small">🔄 Actualizar</button>
                    <input type="search" id="search-history" placeholder="Buscar..." onkeyup="filterHistory()">
                </div>
                <table class="history-table">
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
                        <tr>
                            <td colspan="6" class="loading">Cargando historial...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Settings -->
            <div class="settings-section">
                <h2>⚙️ Configuración</h2>
                <div class="settings-grid">
                    <div class="setting-item">
                        <label>Retención de backups (días):</label>
                        <input type="number" id="retention-days" value="30" min="1" max="365">
                    </div>
                    <div class="setting-item">
                        <label>Compresión:</label>
                        <select id="compression-level">
                            <option value="none">Sin compresión</option>
                            <option value="low">Baja (rápida)</option>
                            <option value="medium" selected>Media</option>
                            <option value="high">Alta (lenta)</option>
                        </select>
                    </div>
                    <div class="setting-item">
                        <label>Destino de backups:</label>
                        <input type="text" id="backup-destination" value="<?php echo Config::get('backup_path'); ?>">
                    </div>
                    <div class="setting-item">
                        <label>Email de notificación:</label>
                        <input type="email" id="notification-email" placeholder="admin@ejemplo.com">
                    </div>
                </div>
                <button onclick="saveSettings()" class="btn btn-primary">Guardar Configuración</button>
            </div>

            <!-- Security Settings -->
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
                <button onclick="changePassword()" class="btn btn-primary">Cambiar Contraseña</button>
                <p class="security-note">⚠️ <strong>Importante:</strong> La contraseña por defecto es "admin123". Es altamente recomendable cambiarla.</p>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
</body>
</html>