#!/bin/bash

#############################################
# Backup Manager - Installation Script
# 100% Compatible with existing API setup
#############################################

echo "======================================"
echo "🛡️  Backup Manager Installation"
echo "======================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if running as root or with sudo
if [[ $EUID -ne 0 ]]; then
   echo -e "${RED}Error: This script must be run as root or with sudo${NC}"
   exit 1
fi

# Installation directory
INSTALL_DIR="/var/www/html/backup-manager"

echo -e "${GREEN}✓${NC} Installation directory: $INSTALL_DIR"

# Step 1: Check prerequisites
echo ""
echo "Step 1: Checking prerequisites..."

# Check PHP
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -r 'echo PHP_VERSION;')
    echo -e "${GREEN}✓${NC} PHP installed: $PHP_VERSION"
else
    echo -e "${RED}✗${NC} PHP not found. Please install PHP 7.4+"
    exit 1
fi

# Check MySQL/MariaDB
if command -v mysql &> /dev/null; then
    echo -e "${GREEN}✓${NC} MySQL/MariaDB client found"
else
    echo -e "${YELLOW}⚠${NC} MySQL client not found. Installing..."
    apt-get update && apt-get install -y mysql-client
fi

# Check Apache
if systemctl is-active --quiet apache2; then
    echo -e "${GREEN}✓${NC} Apache is running"
else
    echo -e "${YELLOW}⚠${NC} Apache not running. Please ensure Apache is installed and running"
fi

# Check for required commands
for cmd in tar gzip mysqldump rsync; do
    if command -v $cmd &> /dev/null; then
        echo -e "${GREEN}✓${NC} $cmd found"
    else
        echo -e "${YELLOW}⚠${NC} $cmd not found. Installing..."
        apt-get install -y $cmd
    fi
done

# Step 2: Auto-detect API configuration
echo ""
echo "Step 2: Auto-detecting API configuration..."

# Find API installation
API_PATH=""
if [ -f "/var/www/html/apidian/artisan" ]; then
    API_PATH="/var/www/html/apidian"
elif [ -f "/var/www/apidian/artisan" ]; then
    API_PATH="/var/www/apidian"
else
    # Try to find it
    FOUND=$(find /var/www -name "artisan" -type f 2>/dev/null | head -1)
    if [ ! -z "$FOUND" ]; then
        API_PATH=$(dirname "$FOUND")
    fi
fi

if [ ! -z "$API_PATH" ]; then
    echo -e "${GREEN}✓${NC} API found at: $API_PATH"
    
    # Read database configuration from .env
    if [ -f "$API_PATH/.env" ]; then
        DB_NAME=$(grep DB_DATABASE $API_PATH/.env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
        DB_USER=$(grep DB_USERNAME $API_PATH/.env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
        DB_PASS=$(grep DB_PASSWORD $API_PATH/.env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
        DB_HOST=$(grep DB_HOST $API_PATH/.env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
        
        echo -e "${GREEN}✓${NC} Database configuration detected:"
        echo "    Database: $DB_NAME"
        echo "    User: $DB_USER"
        echo "    Host: ${DB_HOST:-localhost}"
    else
        echo -e "${YELLOW}⚠${NC} .env file not found in API directory"
    fi
else
    echo -e "${YELLOW}⚠${NC} API installation not found. Manual configuration will be required."
fi

# Step 3: Set up directories and permissions
echo ""
echo "Step 3: Setting up directories and permissions..."

# Create necessary directories
mkdir -p $INSTALL_DIR/{backups,logs,temp}
echo -e "${GREEN}✓${NC} Directories created"

# Set proper ownership
chown -R www-data:www-data $INSTALL_DIR
chmod -R 755 $INSTALL_DIR
chmod 700 $INSTALL_DIR/backups  # Secure backup directory
echo -e "${GREEN}✓${NC} Permissions set"

# Step 4: Configure Apache
echo ""
echo "Step 4: Configuring Apache..."

# Check which port to use
PORT=8888
while netstat -tuln | grep -q ":$PORT "; do
    PORT=$((PORT + 1))
done

echo -e "${GREEN}✓${NC} Using port: $PORT"

# Create Apache configuration
APACHE_CONF="/etc/apache2/sites-available/backup-manager.conf"
cat > $APACHE_CONF << EOF
Listen $PORT

<VirtualHost *:$PORT>
    ServerName backup-manager
    DocumentRoot $INSTALL_DIR
    
    <Directory $INSTALL_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    <Directory $INSTALL_DIR/backups>
        Require all denied
    </Directory>
    
    <Directory $INSTALL_DIR/logs>
        Require all denied
    </Directory>
    
    <Directory $INSTALL_DIR/core>
        Require all denied
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/backup-manager-error.log
    CustomLog \${APACHE_LOG_DIR}/backup-manager-access.log combined
</VirtualHost>
EOF

echo -e "${GREEN}✓${NC} Apache configuration created"

# Enable the site
a2ensite backup-manager.conf > /dev/null 2>&1

# Reload Apache
systemctl reload apache2
echo -e "${GREEN}✓${NC} Apache reloaded"

# Step 5: Create initial configuration file
echo ""
echo "Step 5: Creating initial configuration..."

CONFIG_FILE="$INSTALL_DIR/config.local.php"
if [ ! -f "$CONFIG_FILE" ]; then
    cat > $CONFIG_FILE << EOF
<?php
\$config = array (
  'detected_at' => '$(date '+%Y-%m-%d %H:%M:%S')',
  'server_name' => '$(hostname)',
  'api_path' => '$API_PATH',
  'storage_path' => '$API_PATH/storage',
  'db_host' => '${DB_HOST:-localhost}',
  'db_port' => '3306',
  'db_name' => '$DB_NAME',
  'db_user' => '$DB_USER',
  'db_pass' => '$DB_PASS',
  'backup_path' => '$INSTALL_DIR/backups',
  'temp_path' => '$INSTALL_DIR/temp',
  'log_path' => '$INSTALL_DIR/logs',
  'retention_days' => 30,
  'compression' => 'medium',
  'admin_password' => '\$2y\$10\$YKcZr5VvZL2k5XYq1RAhNOJ9rUQjJoMpgUawugH1nlDpE7bIBqGFm', // admin123
);
EOF
    
    chmod 600 $CONFIG_FILE
    chown www-data:www-data $CONFIG_FILE
    echo -e "${GREEN}✓${NC} Configuration file created"
else
    echo -e "${YELLOW}⚠${NC} Configuration file already exists, skipping..."
fi

# Step 6: Test database connection and configure binary logging
echo ""
echo "Step 6: Testing database connection and configuring binary logging..."

if [ ! -z "$DB_USER" ] && [ ! -z "$DB_PASS" ] && [ ! -z "$DB_NAME" ]; then
    if mysql -h ${DB_HOST:-localhost} -u $DB_USER -p$DB_PASS -e "SELECT 1" $DB_NAME > /dev/null 2>&1; then
        echo -e "${GREEN}✓${NC} Database connection successful"
        
        # Check if binary logging is enabled
        BINARY_LOG_STATUS=$(mysql -h ${DB_HOST:-localhost} -u $DB_USER -p$DB_PASS -e "SHOW VARIABLES LIKE 'log_bin'" -s -N 2>/dev/null | awk '{print $2}')
        
        if [ "$BINARY_LOG_STATUS" = "ON" ]; then
            echo -e "${GREEN}✓${NC} Binary logging already enabled"
        else
            echo -e "${YELLOW}⚠${NC} Binary logging not enabled. Configuring for incremental backups..."
            
            # Detect MySQL configuration file location
            MYSQL_CONFIG=""
            for config_file in /etc/mysql/mysql.conf.d/mysqld.cnf /etc/mysql/my.cnf /etc/my.cnf; do
                if [ -f "$config_file" ]; then
                    MYSQL_CONFIG="$config_file"
                    break
                fi
            done
            
            if [ ! -z "$MYSQL_CONFIG" ]; then
                echo -e "${GREEN}✓${NC} Found MySQL config: $MYSQL_CONFIG"
                
                # Backup original config
                cp "$MYSQL_CONFIG" "$MYSQL_CONFIG.backup.$(date +%Y%m%d_%H%M%S)"
                
                # Check if [mysqld] section exists
                if grep -q "^\[mysqld\]" "$MYSQL_CONFIG"; then
                    # Add binary logging configuration after [mysqld] section
                    sed -i '/^\[mysqld\]/a\\n# Binary logging for incremental backups (added by Backup Manager)\nlog-bin = mysql-bin\nbinlog_format = ROW\nexpire_logs_days = 7\nmax_binlog_size = 100M\nserver-id = 1\n' "$MYSQL_CONFIG"
                else
                    # Add [mysqld] section with binary logging
                    echo "" >> "$MYSQL_CONFIG"
                    echo "# Binary logging configuration (added by Backup Manager)" >> "$MYSQL_CONFIG"
                    echo "[mysqld]" >> "$MYSQL_CONFIG"
                    echo "log-bin = mysql-bin" >> "$MYSQL_CONFIG"
                    echo "binlog_format = ROW" >> "$MYSQL_CONFIG"
                    echo "expire_logs_days = 7" >> "$MYSQL_CONFIG"
                    echo "max_binlog_size = 100M" >> "$MYSQL_CONFIG"
                    echo "server-id = 1" >> "$MYSQL_CONFIG"
                fi
                
                echo -e "${GREEN}✓${NC} Binary logging configuration added"
                echo -e "${YELLOW}⚠${NC} MySQL restart required for binary logging to take effect"
                echo -e "${YELLOW}⚠${NC} Run: sudo systemctl restart mysql"
                
                # Ask if user wants to restart MySQL now
                echo ""
                read -p "Would you like to restart MySQL now? (y/N): " -n 1 -r
                echo
                if [[ $REPLY =~ ^[Yy]$ ]]; then
                    echo "Restarting MySQL/MariaDB..."
                    if systemctl restart mysql 2>/dev/null || systemctl restart mariadb 2>/dev/null; then
                        echo -e "${GREEN}✓${NC} MySQL restarted successfully"
                        sleep 3
                        
                        # Verify binary logging is now enabled
                        BINARY_LOG_STATUS=$(mysql -h ${DB_HOST:-localhost} -u $DB_USER -p$DB_PASS -e "SHOW VARIABLES LIKE 'log_bin'" -s -N 2>/dev/null | awk '{print $2}')
                        if [ "$BINARY_LOG_STATUS" = "ON" ]; then
                            echo -e "${GREEN}✓${NC} Binary logging is now enabled!"
                        else
                            echo -e "${YELLOW}⚠${NC} Binary logging may require manual configuration. Check MySQL logs."
                        fi
                    else
                        echo -e "${RED}✗${NC} Failed to restart MySQL. Please restart manually."
                    fi
                else
                    echo -e "${YELLOW}⚠${NC} MySQL restart skipped. Remember to restart MySQL later."
                fi
            else
                echo -e "${YELLOW}⚠${NC} MySQL configuration file not found. Binary logging must be configured manually."
                echo -e "${YELLOW}⚠${NC} Add to MySQL config: log-bin=mysql-bin, binlog_format=ROW"
            fi
        fi
        
        # Test binary log functionality if enabled
        if [ "$BINARY_LOG_STATUS" = "ON" ] || [ "$REPLY" = "y" ] || [ "$REPLY" = "Y" ]; then
            echo ""
            echo "Testing binary log functionality..."
            MASTER_STATUS=$(mysql -h ${DB_HOST:-localhost} -u $DB_USER -p$DB_PASS -e "SHOW MASTER STATUS" 2>/dev/null)
            if [ ! -z "$MASTER_STATUS" ]; then
                echo -e "${GREEN}✓${NC} Binary logs are working correctly"
                echo "Current binary log position:"
                echo "$MASTER_STATUS" | head -2
            fi
        fi
    else
        echo -e "${YELLOW}⚠${NC} Could not connect to database. Please check credentials."
    fi
else
    echo -e "${YELLOW}⚠${NC} Database credentials not configured"
fi

# Step 7: Create systemd service for cleanup
echo ""
echo "Step 7: Creating cleanup service..."

cat > /etc/systemd/system/backup-manager-cleanup.service << EOF
[Unit]
Description=Backup Manager Cleanup Service
After=network.target

[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/php $INSTALL_DIR/api/cleanup.php

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/backup-manager-cleanup.timer << EOF
[Unit]
Description=Run Backup Manager Cleanup daily
Requires=backup-manager-cleanup.service

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable backup-manager-cleanup.timer > /dev/null 2>&1
systemctl start backup-manager-cleanup.timer
echo -e "${GREEN}✓${NC} Cleanup service created"

# Step 8: Create cleanup script
cat > $INSTALL_DIR/api/cleanup.php << 'EOF'
<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/BackupManager.php';

$backupManager = new BackupManager();
$deleted = $backupManager->cleanOldBackups();
error_log("Backup Manager: Cleaned $deleted old backups");
EOF

chown www-data:www-data $INSTALL_DIR/api/cleanup.php

# Final message
echo ""
echo "======================================"
echo -e "${GREEN}✅ Installation Complete!${NC}"
echo "======================================"
echo ""
echo "Access your Backup Manager at:"
echo -e "${GREEN}http://$(hostname -I | awk '{print $1}'):$PORT${NC}"
echo ""
echo "Default credentials:"
echo "  Password: admin123"
echo ""
echo -e "${YELLOW}⚠️  IMPORTANT:${NC}"
echo "  1. Change the default password after first login"
echo "  2. Configure backup schedule in the web interface"
echo "  3. Test a backup to ensure everything works"
echo ""
echo "Detected configuration:"
echo "  API Path: ${API_PATH:-Not detected}"
echo "  Database: ${DB_NAME:-Not detected}"
echo "  Storage: ${API_PATH}/storage"
echo ""
echo "Log file: $INSTALL_DIR/logs/backup_$(date +%Y-%m-%d).log"
echo ""
echo "For manual configuration, edit:"
echo "  $CONFIG_FILE"
echo ""
echo "======================================"