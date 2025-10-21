/**
 * Remote Backup Configuration Management
 */

let currentRemoteConfig = null;

/**
 * Load remote configuration
 */
function loadRemoteConfig() {
    fetch('api/remote-config.php')
        .then(response => response.json())
        .then(data => {
            currentRemoteConfig = data.config;
            displayRemoteStatus(data);
            populateRemoteForm(data.config);
            displayAvailableMethods(data.methods);
        })
        .catch(error => {
            console.error('Error loading remote config:', error);
            displayRemoteError('Error al cargar la configuración remota');
        });
}

/**
 * Display remote status
 */
function displayRemoteStatus(data) {
    const statusContent = document.getElementById('remote-status-content');
    if (!statusContent) return;

    let statusHTML = '';

    if (data.config && data.config.enabled) {
        const server = data.config.servers && data.config.servers[0];
        if (server) {
            statusHTML = `
                <div class="status-active">
                    <p><strong>✅ Backup Remoto Activo</strong></p>
                    <p>Método: ${server.method?.toUpperCase()}</p>
                    <p>Servidor: ${server.host}</p>
                    <p>Usuario: ${server.user}</p>
                    <p>Directorio: ${server.path}</p>
                    <p>Mantener local: ${data.config.keep_local ? 'Sí' : 'No'}</p>
                </div>
            `;
        } else {
            statusHTML = '<p>⚠️ Backup remoto habilitado pero sin servidor configurado</p>';
        }
    } else {
        statusHTML = '<p>❌ Backup remoto deshabilitado</p>';
    }

    statusContent.innerHTML = statusHTML;
}

/**
 * Display available methods
 */
function displayAvailableMethods(methods) {
    const methodSelect = document.getElementById('remote-method');
    if (!methodSelect || !methods) return;

    methods.forEach(method => {
        const option = methodSelect.querySelector(`option[value="${method.id}"]`);
        if (option && !method.available) {
            option.textContent += ' (No disponible)';
            option.disabled = true;
        }
    });
}

/**
 * Populate remote form with current config
 */
function populateRemoteForm(config) {
    if (!config) {
        document.getElementById('remote-enabled').checked = false;
        return;
    }

    document.getElementById('remote-enabled').checked = config.enabled || false;
    document.getElementById('remote-keep-local').checked = config.keep_local !== false;

    if (config.servers && config.servers[0]) {
        const server = config.servers[0];

        document.getElementById('remote-method').value = server.method || 'ssh';
        updateRemoteForm();

        // SSH specific fields
        if (server.method === 'ssh' || !server.method) {
            document.getElementById('ssh-host').value = server.host || '';
            document.getElementById('ssh-port').value = server.port || '22';
            document.getElementById('ssh-user').value = server.user || '';
            document.getElementById('ssh-path').value = server.path || '';

            if (server.key_file) {
                document.getElementById('ssh-auth').value = 'key';
                document.getElementById('ssh-key').value = server.key_file;
                toggleSSHAuth();
            } else if (server.password) {
                document.getElementById('ssh-auth').value = 'password';
                document.getElementById('ssh-password').value = server.password;
                toggleSSHAuth();
            }
        }
    }
}

/**
 * Save remote configuration
 */
function saveRemoteConfig() {
    const config = {
        enabled: document.getElementById('remote-enabled').checked,
        method: document.getElementById('remote-method').value,
        keep_local: document.getElementById('remote-keep-local').checked,
        max_retries: parseInt(document.getElementById('remote-max-retries')?.value || '3'),
        timeout: parseInt(document.getElementById('remote-timeout')?.value || '3600'),
        verify_checksum: document.getElementById('remote-verify-checksum')?.checked !== false,
        rate_limit_mbps: parseInt(document.getElementById('remote-rate-limit')?.value || '0'),
        servers: []
    };

    if (config.enabled) {
        const server = buildServerConfig();
        if (!validateServerConfig(server)) {
            return;
        }
        config.servers.push(server);
    }

    // Show loading
    showLoading('Guardando configuración...');

    fetch('api/remote-config.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'save',
            config: config
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('✅ ' + data.message, 'success');
            currentRemoteConfig = config;
            displayRemoteStatus({ config: config });
        } else {
            let errorMsg = data.message || 'Error desconocido';
            if (data.errors && data.errors.length > 0) {
                errorMsg += ':\n' + data.errors.join('\n');
            }
            showNotification('❌ Error: ' + errorMsg, 'error');
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Error saving config:', error);
        showNotification('❌ Error al guardar la configuración', 'error');
    });
}

/**
 * Build server configuration from form
 */
function buildServerConfig() {
    const method = document.getElementById('remote-method').value;
    const server = {
        id: 'server_' + Date.now(),
        method: method,
        active: true
    };

    switch(method) {
        case 'ssh':
        case 'sftp':
            server.host = document.getElementById('ssh-host')?.value.trim() || '';
            server.port = parseInt(document.getElementById('ssh-port')?.value) || 22;
            server.user = document.getElementById('ssh-user')?.value.trim() || '';
            server.path = document.getElementById('ssh-path')?.value.trim() || '';

            const authType = document.getElementById('ssh-auth')?.value;
            if (authType === 'password') {
                server.password = document.getElementById('ssh-password')?.value || '';
            } else {
                server.key_file = document.getElementById('ssh-key')?.value.trim() || '';
            }
            break;

        case 'rsync':
            server.host = document.getElementById('rsync-host')?.value.trim() ||
                         document.getElementById('ssh-host')?.value.trim() || '';
            server.port = parseInt(document.getElementById('rsync-port')?.value ||
                         document.getElementById('ssh-port')?.value) || 22;
            server.user = document.getElementById('rsync-user')?.value.trim() ||
                         document.getElementById('ssh-user')?.value.trim() || '';
            server.path = document.getElementById('rsync-path')?.value.trim() ||
                         document.getElementById('ssh-path')?.value.trim() || '';
            server.ssh = true; // Rsync over SSH

            const rsyncAuthType = document.getElementById('rsync-auth')?.value ||
                                 document.getElementById('ssh-auth')?.value;
            if (rsyncAuthType === 'password') {
                server.password = document.getElementById('rsync-password')?.value ||
                                 document.getElementById('ssh-password')?.value || '';
            } else {
                server.key_file = document.getElementById('rsync-key')?.value.trim() ||
                                 document.getElementById('ssh-key')?.value.trim() || '';
            }
            break;

        case 'ftp':
            server.host = document.getElementById('ftp-host')?.value.trim() ||
                         document.getElementById('ssh-host')?.value.trim() || '';
            server.port = parseInt(document.getElementById('ftp-port')?.value ||
                         document.getElementById('ssh-port')?.value) || 21;
            server.user = document.getElementById('ftp-user')?.value.trim() ||
                         document.getElementById('ssh-user')?.value.trim() || '';
            server.password = document.getElementById('ftp-password')?.value ||
                             document.getElementById('ssh-password')?.value || '';
            server.path = document.getElementById('ftp-path')?.value.trim() ||
                         document.getElementById('ssh-path')?.value.trim() || '';
            break;

        case 's3':
            server.bucket = document.getElementById('s3-bucket')?.value.trim() || '';
            server.path = document.getElementById('s3-path')?.value.trim() ||
                         document.getElementById('ssh-path')?.value.trim() || '';
            server.access_key = document.getElementById('s3-access-key')?.value.trim() || '';
            server.secret_key = document.getElementById('s3-secret-key')?.value || '';
            server.region = document.getElementById('s3-region')?.value.trim() || 'us-east-1';
            server.endpoint = document.getElementById('s3-endpoint')?.value.trim() || '';
            break;

        default:
            // Fallback: intentar obtener de campos SSH
            server.host = document.getElementById('ssh-host')?.value.trim() || '';
            server.port = parseInt(document.getElementById('ssh-port')?.value) || 22;
            server.user = document.getElementById('ssh-user')?.value.trim() || '';
            server.path = document.getElementById('ssh-path')?.value.trim() || '';
    }

    return server;
}

/**
 * Validate server configuration
 */
function validateServerConfig(server) {
    // Validación específica por método
    switch(server.method) {
        case 's3':
            if (!server.bucket) {
                showNotification('Por favor ingresa el nombre del bucket S3', 'error');
                return false;
            }
            if (!server.access_key) {
                showNotification('Por favor ingresa el Access Key de S3', 'error');
                return false;
            }
            if (!server.secret_key) {
                showNotification('Por favor ingresa el Secret Key de S3', 'error');
                return false;
            }
            break;

        case 'ssh':
        case 'sftp':
        case 'rsync':
        case 'ftp':
        default:
            if (!server.host) {
                showNotification('Por favor ingresa el host del servidor', 'error');
                return false;
            }

            if (!server.user) {
                showNotification('Por favor ingresa el usuario', 'error');
                return false;
            }

            if (!server.path) {
                showNotification('Por favor ingresa el directorio remoto', 'error');
                return false;
            }

            // Validación de autenticación para SSH/Rsync
            if (server.method === 'ssh' || server.method === 'sftp' || server.method === 'rsync') {
                if (!server.password && !server.key_file) {
                    showNotification('Por favor ingresa una contraseña o archivo de clave', 'error');
                    return false;
                }
            }

            // Validación de password para FTP
            if (server.method === 'ftp' && !server.password) {
                showNotification('Por favor ingresa la contraseña FTP', 'error');
                return false;
            }
            break;
    }

    return true;
}

/**
 * Test remote connection
 */
function testRemoteConnection() {
    const server = buildServerConfig();

    if (!validateServerConfig(server)) {
        return;
    }

    // Show loading
    showLoading('Probando conexión...');
    const resultDiv = document.getElementById('remote-test-result');
    resultDiv.style.display = 'none';

    fetch('api/remote-config.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'test',
            server: server
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();

        resultDiv.className = 'test-result';
        if (data.success) {
            resultDiv.classList.add('success');
            resultDiv.innerHTML = '✅ ' + (data.message || 'Conexión exitosa');
        } else {
            resultDiv.classList.add('error');
            resultDiv.innerHTML = '❌ ' + (data.message || 'Conexión fallida');
        }
        resultDiv.style.display = 'block';

        // Hide result after 10 seconds
        setTimeout(() => {
            resultDiv.style.display = 'none';
        }, 10000);
    })
    .catch(error => {
        hideLoading();
        console.error('Error testing connection:', error);
        resultDiv.className = 'test-result error';
        resultDiv.innerHTML = '❌ Error al probar la conexión';
        resultDiv.style.display = 'block';
    });
}

/**
 * Display remote error
 */
function displayRemoteError(message) {
    const statusContent = document.getElementById('remote-status-content');
    if (statusContent) {
        statusContent.innerHTML = `<p class="error">❌ ${message}</p>`;
    }
}

/**
 * Show loading indicator
 */
function showLoading(message = 'Cargando...') {
    const loadingDiv = document.createElement('div');
    loadingDiv.id = 'loading-indicator';
    loadingDiv.className = 'loading-overlay';
    loadingDiv.innerHTML = `
        <div class="loading-content">
            <div class="spinner"></div>
            <p>${message}</p>
        </div>
    `;
    document.body.appendChild(loadingDiv);
}

/**
 * Hide loading indicator
 */
function hideLoading() {
    const loadingDiv = document.getElementById('loading-indicator');
    if (loadingDiv) {
        loadingDiv.remove();
    }
}

// Add loading styles
const loadingStyles = `
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.loading-content {
    background: var(--card-bg);
    padding: 30px;
    border-radius: 12px;
    text-align: center;
}

.spinner {
    width: 40px;
    height: 40px;
    border: 4px solid var(--border-color);
    border-top-color: var(--primary-color);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 15px;
}

.status-active {
    padding: 15px;
    background: rgba(16, 185, 129, 0.1);
    border-radius: 8px;
    border: 1px solid #10b981;
}

.status-active p {
    margin: 5px 0;
}
`;

// Add loading styles to page
const loadingStyleElement = document.createElement('style');
loadingStyleElement.textContent = loadingStyles;
document.head.appendChild(loadingStyleElement);

/**
 * Load and display transfer history
 */
function loadTransferHistory() {
    fetch('api/remote-config.php?history=1&limit=100')
        .then(response => response.json())
        .then(data => {
            if (data.history && data.history.length > 0) {
                displayTransferHistory(data.history);
            }
        })
        .catch(error => {
            console.error('Error loading transfer history:', error);
        });
}

/**
 * Display transfer history in the UI
 */
function displayTransferHistory(history) {
    const historyContainer = document.getElementById('remote-history');
    if (!historyContainer) return;

    let html = '<div class="transfer-history"><h4>Historial de Transferencias</h4><div class="history-list">';

    history.forEach(entry => {
        const levelClass = entry.level.toLowerCase();
        const icon = {
            'success': '✅',
            'error': '❌',
            'warning': '⚠️',
            'info': 'ℹ️'
        }[levelClass] || '•';

        html += `
            <div class="history-entry ${levelClass}">
                <span class="history-icon">${icon}</span>
                <span class="history-time">${entry.timestamp}</span>
                <span class="history-message">${entry.message}</span>
            </div>
        `;
    });

    html += '</div></div>';
    historyContainer.innerHTML = html;
}

/**
 * Show notification to user
 */
function showNotification(message, type = 'info') {
    // Check if we have a notification system in the main app
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, type);
        return;
    }

    // Fallback: use alert
    alert(message);
}

/**
 * Populate form with advanced options
 */
function populateAdvancedOptions(config) {
    if (document.getElementById('remote-max-retries')) {
        document.getElementById('remote-max-retries').value = config.max_retries || 3;
    }
    if (document.getElementById('remote-timeout')) {
        document.getElementById('remote-timeout').value = config.timeout || 3600;
    }
    if (document.getElementById('remote-verify-checksum')) {
        document.getElementById('remote-verify-checksum').checked = config.verify_checksum !== false;
    }
    if (document.getElementById('remote-rate-limit')) {
        document.getElementById('remote-rate-limit').value = config.rate_limit_mbps || 0;
    }
}

/**
 * Initialize remote backup UI
 */
function initRemoteBackup() {
    loadRemoteConfig();
    loadTransferHistory();

    // Load history every 30 seconds
    setInterval(loadTransferHistory, 30000);
}

// CSS styles for history
const historyStyles = `
.transfer-history {
    margin-top: 20px;
    padding: 15px;
    background: var(--card-bg);
    border-radius: 8px;
}

.transfer-history h4 {
    margin-top: 0;
    margin-bottom: 15px;
    color: var(--text-primary);
}

.history-list {
    max-height: 400px;
    overflow-y: auto;
}

.history-entry {
    display: flex;
    align-items: center;
    padding: 8px 10px;
    margin-bottom: 5px;
    border-radius: 4px;
    font-size: 0.9em;
    border-left: 3px solid transparent;
}

.history-entry.success {
    background: rgba(16, 185, 129, 0.1);
    border-left-color: #10b981;
}

.history-entry.error {
    background: rgba(239, 68, 68, 0.1);
    border-left-color: #ef4444;
}

.history-entry.warning {
    background: rgba(251, 191, 36, 0.1);
    border-left-color: #fbbf24;
}

.history-entry.info {
    background: rgba(59, 130, 246, 0.1);
    border-left-color: #3b82f6;
}

.history-icon {
    margin-right: 10px;
    font-size: 1.2em;
}

.history-time {
    min-width: 150px;
    margin-right: 15px;
    color: var(--text-secondary);
    font-family: monospace;
    font-size: 0.85em;
}

.history-message {
    flex: 1;
    color: var(--text-primary);
}
`;

// Add history styles to page
const historyStyleElement = document.createElement('style');
historyStyleElement.textContent = historyStyles;
document.head.appendChild(historyStyleElement);