/**
 * Navigation System for Backup Manager
 */

// Initialize navigation when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializeNavigation();
    loadRemoteConfig();

    // Check for URL hash to open specific tab
    const hash = window.location.hash.substring(1);
    if (hash) {
        switchTab(hash);
    }
});

/**
 * Initialize navigation system
 */
function initializeNavigation() {
    // Add click handlers to nav items
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function() {
            const tabName = this.dataset.tab;
            switchTab(tabName);
        });
    });

    // Initialize tooltips
    initTooltips();
}

/**
 * Switch to a specific tab
 */
function switchTab(tabName) {
    // Update nav items
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
        if (item.dataset.tab === tabName) {
            item.classList.add('active');
        }
    });

    // Update tab panes
    document.querySelectorAll('.tab-pane').forEach(pane => {
        pane.classList.remove('active');
    });

    const targetPane = document.getElementById(tabName + '-tab');
    if (targetPane) {
        targetPane.classList.add('active');

        // Update URL hash without scrolling
        history.replaceState(null, null, '#' + tabName);

        // Trigger specific actions based on tab
        onTabSwitch(tabName);
    }
}

/**
 * Handle tab-specific actions
 */
function onTabSwitch(tabName) {
    console.log('Switching to tab:', tabName);
    switch(tabName) {
        case 'dashboard':
            // Always reload dashboard data when switching to it
            if (typeof loadDashboardData === 'function') {
                loadDashboardData();
            }
            break;
        case 'history':
            if (typeof loadHistory === 'function') {
                loadHistory();
            }
            break;
        case 'remote':
            if (typeof loadRemoteConfig === 'function') {
                loadRemoteConfig();
            }
            break;
        case 'logs':
            if (typeof loadLogs === 'function') {
                loadLogs();
            }
            break;
        case 'schedule':
            if (typeof loadScheduleConfig === 'function') {
                loadScheduleConfig();
            }
            break;
        case 'backup':
            // Reset backup UI when switching to backup tab
            if (typeof resetBackupUI === 'function') {
                const progressSection = document.getElementById('progress-section');
                if (progressSection && progressSection.style.display === 'none') {
                    // Only reset if not currently running a backup
                    resetBackupUI();
                }
            }
            break;
    }
}

/**
 * Initialize tooltips
 */
function initTooltips() {
    // Add tooltips to nav items
    const tooltips = {
        'dashboard': 'Vista general del sistema',
        'backup': 'Crear nuevos backups',
        'history': 'Ver backups anteriores',
        'schedule': 'Programar backups automáticos',
        'remote': 'Configurar backup remoto',
        'settings': 'Configuración general',
        'security': 'Configuración de seguridad',
        'logs': 'Ver logs del sistema'
    };

    Object.keys(tooltips).forEach(tab => {
        const navItem = document.querySelector(`[data-tab="${tab}"]`);
        if (navItem) {
            navItem.title = tooltips[tab];
        }
    });
}

/**
 * Load and display logs
 */
function loadLogs() {
    const logType = document.getElementById('log-type')?.value || 'backup';
    const logDate = document.getElementById('log-date')?.value || '';

    fetch(`api/logs.php?type=${logType}&date=${logDate}`)
        .then(response => response.text())
        .then(data => {
            const logsContent = document.getElementById('logs-content');
            if (logsContent) {
                logsContent.textContent = data || 'No hay logs disponibles';
            }
        })
        .catch(error => {
            console.error('Error loading logs:', error);
            const logsContent = document.getElementById('logs-content');
            if (logsContent) {
                logsContent.textContent = 'Error al cargar los logs';
            }
        });
}

/**
 * Refresh logs
 */
function refreshLogs() {
    loadLogs();
    showNotification('Logs actualizados', 'success');
}

/**
 * Download logs
 */
function downloadLogs() {
    const logType = document.getElementById('log-type')?.value || 'backup';
    const logDate = document.getElementById('log-date')?.value || '';

    window.location.href = `api/logs.php?download=1&type=${logType}&date=${logDate}`;
}

/**
 * Load schedule configuration
 */
function loadScheduleConfig() {
    fetch('api/schedule.php')
        .then(response => response.json())
        .then(data => {
            if (data.schedule) {
                document.getElementById('schedule-enabled').checked = data.schedule.enabled;
                document.getElementById('schedule-frequency').value = data.schedule.frequency || 'daily';
                document.getElementById('schedule-time').value = data.schedule.time || '03:00';
                document.getElementById('schedule-type').value = data.schedule.type || 'full';
            }

            if (data.cron) {
                document.getElementById('cron-config').textContent = data.cron || 'No configurado';
            }
        })
        .catch(error => {
            console.error('Error loading schedule:', error);
        });
}

/**
 * Clear old backups
 */
function clearOldBackups() {
    if (confirm('¿Eliminar backups antiguos según la configuración de retención?')) {
        fetch('api/cleanup.php', {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(`Se eliminaron ${data.deleted || 0} backups antiguos`, 'success');
                loadHistory();
            } else {
                showNotification('Error al limpiar backups', 'error');
            }
        })
        .catch(error => {
            console.error('Error cleaning backups:', error);
            showNotification('Error al limpiar backups', 'error');
        });
    }
}

/**
 * Filter history by type
 */
function filterHistory() {
    const filter = document.getElementById('history-filter').value;
    const rows = document.querySelectorAll('#history-tbody tr');

    rows.forEach(row => {
        if (filter === 'all') {
            row.style.display = '';
        } else {
            const type = row.dataset.type;
            row.style.display = type === filter ? '' : 'none';
        }
    });
}

/**
 * Refresh history
 */
function refreshHistory() {
    loadHistory();
    showNotification('Historial actualizado', 'success');
}

/**
 * Show notification
 */
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;

    // Add to body
    document.body.appendChild(notification);

    // Animate in
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);

    // Remove after 3 seconds
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}

/**
 * Toggle SSH authentication method
 */
function toggleSSHAuth() {
    const authType = document.getElementById('ssh-auth').value;
    const passwordGroup = document.getElementById('ssh-password-group');
    const keyGroup = document.getElementById('ssh-key-group');

    if (authType === 'password') {
        passwordGroup.style.display = 'block';
        keyGroup.style.display = 'none';
    } else {
        passwordGroup.style.display = 'none';
        keyGroup.style.display = 'block';
    }
}

/**
 * Update remote form based on method
 */
function updateRemoteForm() {
    const method = document.getElementById('remote-method').value;

    // Hide all forms
    document.querySelectorAll('.remote-form').forEach(form => {
        form.style.display = 'none';
    });

    // Show selected form
    const selectedForm = document.getElementById(`remote-form-${method}`);
    if (selectedForm) {
        selectedForm.style.display = 'block';
    } else {
        // For now, show SSH form for all methods
        document.getElementById('remote-form-ssh').style.display = 'block';
    }
}

/**
 * Add notification styles
 */
const notificationStyles = `
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    background: var(--card-bg);
    color: var(--text-primary);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transform: translateX(400px);
    transition: transform 0.3s ease;
    z-index: 1000;
    max-width: 300px;
}

.notification.show {
    transform: translateX(0);
}

.notification-success {
    background: #10b981;
    color: white;
}

.notification-error {
    background: #ef4444;
    color: white;
}

.notification-warning {
    background: #f59e0b;
    color: white;
}

.notification-info {
    background: #3b82f6;
    color: white;
}
`;

// Add notification styles to page
const styleElement = document.createElement('style');
styleElement.textContent = notificationStyles;
document.head.appendChild(styleElement);