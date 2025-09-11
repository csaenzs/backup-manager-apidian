/**
 * Backup Manager - Main JavaScript Application
 */

// Global variables
let progressInterval = null;
let currentBackupType = null;

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    loadDashboardData();
    loadHistory();
    loadSchedule();
    
    // Refresh data every 30 seconds
    setInterval(loadDashboardData, 30000);
});

/**
 * Load dashboard data
 */
function loadDashboardData() {
    fetch('api/status.php')
        .then(response => response.json())
        .then(data => {
            // Update storage size
            document.getElementById('storage-size').textContent = formatSize(data.storage_size);
            
            // Update database size
            document.getElementById('db-size').textContent = formatSize(data.db_size);
            
            // Update last backup info
            if (data.last_backup) {
                document.getElementById('last-backup').textContent = formatDate(data.last_backup.date);
                document.getElementById('last-backup').className = 'last-backup';
            } else {
                document.getElementById('last-backup').textContent = 'No hay backups';
                document.getElementById('last-backup').className = 'last-backup warning';
            }
            
            // Update next backup
            if (data.next_backup) {
                document.getElementById('next-backup').textContent = 'Próximo: ' + formatDate(data.next_backup);
            }
        })
        .catch(error => {
            console.error('Error loading dashboard data:', error);
        });
}

/**
 * Start a backup
 */
function startBackup(type) {
    if (confirm(`¿Iniciar backup ${type}? Esto puede tomar varios minutos.`)) {
        currentBackupType = type;
        
        // Show progress section
        document.getElementById('progress-section').style.display = 'block';
        document.getElementById('progress-fill').style.width = '0%';
        document.getElementById('progress-text').textContent = 'Iniciando backup...';
        document.getElementById('progress-log').textContent = '';
        
        // Disable backup buttons
        document.querySelectorAll('.action-buttons button').forEach(btn => {
            btn.disabled = true;
        });
        
        // Start backup via API
        fetch('api/backup.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ type: type })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Start monitoring progress
                monitorProgress();
            } else {
                alert('Error al iniciar backup: ' + data.message);
                resetBackupUI();
            }
        })
        .catch(error => {
            console.error('Error starting backup:', error);
            alert('Error al iniciar backup');
            resetBackupUI();
        });
    }
}

/**
 * Monitor backup progress
 */
function monitorProgress() {
    progressInterval = setInterval(() => {
        fetch('api/progress.php')
            .then(response => response.json())
            .then(data => {
                if (data.progress) {
                    const progress = data.progress;
                    
                    // Update progress bar
                    document.getElementById('progress-fill').style.width = progress.percentage + '%';
                    document.getElementById('progress-fill').textContent = progress.percentage + '%';
                    
                    // Update status text
                    document.getElementById('progress-text').textContent = progress.message;
                    
                    // Update log
                    if (progress.log) {
                        document.getElementById('progress-log').textContent += progress.log + '\n';
                        document.getElementById('progress-log').scrollTop = document.getElementById('progress-log').scrollHeight;
                    }
                    
                    // Check if completed
                    if (progress.percentage >= 100 || progress.percentage < 0) {
                        clearInterval(progressInterval);
                        progressInterval = null;
                        
                        if (progress.percentage >= 100) {
                            alert('Backup completado exitosamente!');
                        } else {
                            alert('Backup completado con errores. Revisa los logs.');
                        }
                        
                        // Refresh data
                        loadHistory();
                        loadDashboardData();
                        
                        // Reset UI after 5 seconds
                        setTimeout(resetBackupUI, 5000);
                    }
                }
            })
            .catch(error => {
                console.error('Error monitoring progress:', error);
            });
    }, 1000);
}

/**
 * Reset backup UI
 */
function resetBackupUI() {
    // Clear progress interval if running
    if (progressInterval) {
        clearInterval(progressInterval);
        progressInterval = null;
    }
    
    // Hide progress section after animation
    setTimeout(() => {
        document.getElementById('progress-section').style.display = 'none';
    }, 500);
    
    // Enable backup buttons
    document.querySelectorAll('.action-buttons button').forEach(btn => {
        btn.disabled = false;
    });
    
    currentBackupType = null;
}

/**
 * Load backup history
 */
function loadHistory() {
    fetch('api/history.php')
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('history-tbody');
            
            if (data.history && data.history.length > 0) {
                tbody.innerHTML = data.history.map(backup => `
                    <tr>
                        <td>${formatDate(backup.date)}</td>
                        <td>${translateType(backup.type)}</td>
                        <td>${formatSize(backup.size)}</td>
                        <td>${formatDuration(backup.duration)}</td>
                        <td><span class="status-badge status-${backup.status}">${translateStatus(backup.status)}</span></td>
                        <td>
                            <button onclick="downloadBackup('${backup.id}')" class="action-btn action-download" title="Descargar">⬇</button>
                            <button onclick="restoreBackup('${backup.id}')" class="action-btn action-restore" title="Restaurar">↻</button>
                            <button onclick="deleteBackup('${backup.id}')" class="action-btn action-delete" title="Eliminar">🗑</button>
                        </td>
                    </tr>
                `).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="6" class="loading">No hay backups disponibles</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading history:', error);
            document.getElementById('history-tbody').innerHTML = 
                '<tr><td colspan="6" class="loading">Error al cargar historial</td></tr>';
        });
}

/**
 * Refresh history
 */
function refreshHistory() {
    document.getElementById('history-tbody').innerHTML = 
        '<tr><td colspan="6" class="loading"><div class="spinner"></div> Cargando...</td></tr>';
    loadHistory();
}

/**
 * Filter history table
 */
function filterHistory() {
    const searchTerm = document.getElementById('search-history').value.toLowerCase();
    const rows = document.querySelectorAll('#history-tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
}

/**
 * Download backup
 */
function downloadBackup(backupId) {
    window.location.href = `api/download.php?id=${backupId}`;
}

/**
 * Restore backup
 */
function restoreBackup(backupId) {
    if (confirm('⚠️ ADVERTENCIA: Restaurar un backup sobrescribirá los datos actuales. ¿Estás seguro?')) {
        if (confirm('Esta es una operación peligrosa. ¿Realmente deseas continuar?')) {
            fetch('api/restore.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ id: backupId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'ready') {
                    alert('Backup listo para restaurar. Por seguridad, debes ejecutar el proceso manualmente:\n\n' + data.instructions);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error restoring backup:', error);
                alert('Error al restaurar backup');
            });
        }
    }
}

/**
 * Delete backup
 */
function deleteBackup(backupId) {
    if (confirm('¿Eliminar este backup? Esta acción no se puede deshacer.')) {
        fetch('api/delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id: backupId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadHistory();
                loadDashboardData();
            } else {
                alert('Error al eliminar backup: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error deleting backup:', error);
            alert('Error al eliminar backup');
        });
    }
}

/**
 * Load schedule settings
 */
function loadSchedule() {
    fetch('api/schedule.php')
        .then(response => response.json())
        .then(data => {
            if (data.enabled) {
                document.getElementById('enable-schedule').checked = true;
                document.getElementById('schedule-options').style.display = 'flex';
                document.getElementById('schedule-frequency').value = data.frequency || 'daily';
                document.getElementById('schedule-time').value = data.time || '01:00';
            } else {
                document.getElementById('enable-schedule').checked = false;
                document.getElementById('schedule-options').style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error loading schedule:', error);
        });
}

// Toggle schedule options
document.getElementById('enable-schedule').addEventListener('change', function() {
    document.getElementById('schedule-options').style.display = this.checked ? 'flex' : 'none';
});

/**
 * Save schedule settings
 */
function saveSchedule() {
    const enabled = document.getElementById('enable-schedule').checked;
    const frequency = document.getElementById('schedule-frequency').value;
    const time = document.getElementById('schedule-time').value;
    
    fetch('api/schedule.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            enabled: enabled,
            frequency: frequency,
            time: time
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Programación guardada exitosamente');
            loadDashboardData();
        } else {
            alert('Error al guardar programación: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error saving schedule:', error);
        alert('Error al guardar programación');
    });
}

/**
 * Save settings
 */
function saveSettings() {
    const settings = {
        retention_days: document.getElementById('retention-days').value,
        compression: document.getElementById('compression-level').value,
        backup_destination: document.getElementById('backup-destination').value,
        notification_email: document.getElementById('notification-email').value
    };
    
    fetch('api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(settings)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Configuración guardada exitosamente');
        } else {
            alert('Error al guardar configuración: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error saving settings:', error);
        alert('Error al guardar configuración');
    });
}

/**
 * Utility functions
 */
function formatSize(bytes) {
    if (!bytes || bytes === 0) return '0 MB';
    
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    
    if (i === 0) return bytes + ' ' + sizes[i];
    return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
}

function formatDate(dateString) {
    if (!dateString) return '-';
    
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;
    
    // If less than 24 hours ago, show relative time
    if (diff < 86400000) {
        const hours = Math.floor(diff / 3600000);
        if (hours < 1) {
            const minutes = Math.floor(diff / 60000);
            return `Hace ${minutes} minutos`;
        }
        return `Hace ${hours} horas`;
    }
    
    // Otherwise show date
    return date.toLocaleDateString('es-ES', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function formatDuration(seconds) {
    if (!seconds) return '-';
    
    if (seconds < 60) return seconds + ' seg';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' min';
    
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return `${hours}h ${minutes}m`;
}

function translateType(type) {
    const types = {
        'full': 'Completo',
        'database': 'Base de datos',
        'storage': 'Storage'
    };
    return types[type] || type;
}

function translateStatus(status) {
    const statuses = {
        'completed': 'Completado',
        'failed': 'Fallido',
        'running': 'En progreso'
    };
    return statuses[status] || status;
}