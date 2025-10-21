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
            showNotification('Configuración guardada exitosamente', 'success');
            currentRemoteConfig = config;
            displayRemoteStatus({ config: config });
        } else {
            showNotification('Error al guardar: ' + (data.message || 'Error desconocido'), 'error');
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Error saving config:', error);
        showNotification('Error al guardar la configuración', 'error');
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

    if (method === 'ssh' || method === 'sftp') {
        server.host = document.getElementById('ssh-host').value.trim();
        server.port = parseInt(document.getElementById('ssh-port').value) || 22;
        server.user = document.getElementById('ssh-user').value.trim();
        server.path = document.getElementById('ssh-path').value.trim();

        const authType = document.getElementById('ssh-auth').value;
        if (authType === 'password') {
            server.password = document.getElementById('ssh-password').value;
        } else {
            server.key_file = document.getElementById('ssh-key').value.trim();
        }
    }

    // Add configurations for other methods here (FTP, Rsync, S3)

    return server;
}

/**
 * Validate server configuration
 */
function validateServerConfig(server) {
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

    if (server.method === 'ssh' || server.method === 'sftp') {
        if (!server.password && !server.key_file) {
            showNotification('Por favor ingresa una contraseña o archivo de clave', 'error');
            return false;
        }
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