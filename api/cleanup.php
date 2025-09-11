<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/BackupManager.php';

$backupManager = new BackupManager();
$deleted = $backupManager->cleanOldBackups();
error_log("Backup Manager: Cleaned $deleted old backups");
