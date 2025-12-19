# Deployment Guide - Jio Pay Gateway

## 🚀 Production Deployment

This guide covers deploying the Jio Pay Gateway plugin to production environments safely and efficiently.

## 📋 Pre-Deployment Checklist

### System Requirements Verification

#### Server Requirements
```bash
# Check PHP version (7.4+ required, 8.0+ recommended)
php -v

# Check WordPress version (5.0+ required)
wp core version

# Check WooCommerce version (3.0+ required)
wp plugin get woocommerce --field=version

# Check SSL certificate
curl -I https://yourdomain.com
```

#### Required PHP Extensions
```bash
# Verify required extensions
php -m | grep -E "(curl|json|mbstring|openssl|zip)"

# Should output:
# curl
# json
# mbstring
# openssl
# zip
```

#### Database Requirements
```sql
-- MySQL 5.6+ or MariaDB 10.1+ required
SELECT VERSION();

-- Check available storage
SELECT 
    table_schema "Database",
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) "DB Size in MB" 
FROM information_schema.tables 
WHERE table_schema = 'your_database_name';
```

### Security Prerequisites

#### SSL Certificate
```bash
# Verify SSL certificate is valid
openssl s_client -connect yourdomain.com:443 -servername yourdomain.com | grep -A 5 "Verify return code"

# Should return: Verify return code: 0 (ok)
```

#### File Permissions
```bash
# Set secure permissions
find /path/to/wordpress/ -type d -exec chmod 755 {} \;
find /path/to/wordpress/ -type f -exec chmod 644 {} \;

# wp-config.php extra security
chmod 600 wp-config.php

# Plugin directory permissions
chmod 755 wp-content/plugins/woo-jiopay/
chmod 644 wp-content/plugins/woo-jiopay/*.php
```

## 🔧 Deployment Methods

### Method 1: WordPress Admin Upload (Recommended for Small Sites)

#### Step 1: Prepare Plugin Package
```bash
# Create deployment package
cd /path/to/development/
zip -r woo-jiopay-v1.0.0.zip woo-jiopay/ -x "*.git*" "*.DS_Store*" "*node_modules*"
```

#### Step 2: Upload via WordPress Admin
1. Navigate to **WordPress Admin → Plugins → Add New**
2. Click **Upload Plugin**
3. Select the ZIP file
4. Click **Install Now**
5. **Activate** the plugin

#### Step 3: Configure Settings
1. Go to **WooCommerce → Settings → Payments**
2. Configure **Jio Pay Gateway** with production credentials
3. **Disable Test Mode**
4. **Save Changes**

### Method 2: FTP/SFTP Deployment

#### Step 1: Upload Files
```bash
# Using SFTP
sftp user@yourserver.com
put -r woo-jiopay/ /path/to/wordpress/wp-content/plugins/

# Using SCP
scp -r woo-jiopay/ user@yourserver.com:/path/to/wordpress/wp-content/plugins/

# Using rsync (recommended)
rsync -avz --exclude='.git' --exclude='.DS_Store' woo-jiopay/ user@yourserver.com:/path/to/wordpress/wp-content/plugins/woo-jiopay/
```

#### Step 2: Set Permissions
```bash
# Connect to server
ssh user@yourserver.com

# Set proper permissions
cd /path/to/wordpress/wp-content/plugins/
chown -R www-data:www-data woo-jiopay/
chmod -R 755 woo-jiopay/
find woo-jiopay/ -name "*.php" -exec chmod 644 {} \;
```

#### Step 3: Activate Plugin
```bash
# Using WP-CLI
wp plugin activate woo-jiopay

# Or activate via WordPress Admin
```

### Method 3: Git Deployment (Advanced)

#### Step 1: Setup Git Repository
```bash
# On production server
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/yourusername/woo-jiopay.git
cd woo-jiopay/
git checkout main
```

#### Step 2: Deploy Updates
```bash
# Deploy script (deploy.sh)
#!/bin/bash
set -e

echo "Starting deployment..."

# Pull latest changes
git fetch origin
git reset --hard origin/main

# Set permissions
chown -R www-data:www-data .
chmod -R 755 .
find . -name "*.php" -exec chmod 644 {} \;

# Clear cache if using object cache
wp cache flush

echo "Deployment completed successfully!"
```

#### Step 3: Automate with Webhooks
```bash
# webhook-handler.php (place outside document root)
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    
    // Verify webhook (GitHub/GitLab signature)
    if (verify_webhook_signature($payload)) {
        // Run deployment script
        exec('/path/to/deploy.sh > /dev/null 2>&1 &');
        http_response_code(200);
        echo 'Deployment triggered';
    } else {
        http_response_code(401);
        echo 'Unauthorized';
    }
}
?>
```

## ⚙️ Configuration Management

### Production Configuration

#### wp-config.php Settings
```php
// Production environment settings
define('WP_ENVIRONMENT_TYPE', 'production');
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', false);

// Security settings
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', true);
define('FORCE_SSL_ADMIN', true);

// Jio Pay production credentials
define('JIO_PAY_API_KEY', 'your_production_api_key');
define('JIO_PAY_MERCHANT_ID', 'your_production_merchant_id');
define('JIO_PAY_ENVIRONMENT', 'production');
```

#### Gateway Configuration
```php
// Production gateway settings
$production_settings = array(
    'enabled' => 'yes',
    'title' => 'Jio Pay Gateway',
    'description' => 'Pay securely with Jio Pay',
    'merchant_id' => get_option('JIO_PAY_MERCHANT_ID'),
    'api_key' => get_option('JIO_PAY_API_KEY'),
    'testmode' => 'no',  // CRITICAL: Disable test mode
    'debug' => 'no'      // Disable debug in production
);

update_option('woocommerce_jio_pay_settings', $production_settings);
```

### Environment-Specific Configuration

#### Development Environment
```php
// wp-config-development.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);
define('JIO_PAY_ENVIRONMENT', 'development');
```

#### Staging Environment
```php
// wp-config-staging.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('JIO_PAY_ENVIRONMENT', 'staging');
```

#### Production Environment
```php
// wp-config-production.php
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('JIO_PAY_ENVIRONMENT', 'production');
```

## 🔐 Security Hardening

### Server-Level Security

#### Apache Configuration
```apache
# .htaccess for plugin directory
<Files "*.php">
    Order Deny,Allow
    Deny from All
</Files>

<Files "woo-jiopay.php">
    Order Allow,Deny
    Allow from All
</Files>

# Block direct access to sensitive files
<FilesMatch "\.(log|md|json)$">
    Order Deny,Allow
    Deny from All
</FilesMatch>

# Security headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
</IfModule>
```

#### Nginx Configuration
```nginx
# Security configuration for plugin
location ~* ^/wp-content/plugins/woo-jiopay/.*\.(php)$ {
    include fastcgi_params;
    fastcgi_pass php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}

# Block access to sensitive files
location ~* ^/wp-content/plugins/woo-jiopay/.*\.(log|md|json)$ {
    deny all;
    return 404;
}

# Rate limiting for payment endpoints
location ~ /wp-admin/admin-ajax.php {
    limit_req zone=payment burst=10 nodelay;
    include fastcgi_params;
    fastcgi_pass php;
}
```

### Database Security

#### Secure Database Configuration
```sql
-- Create dedicated database user for production
CREATE USER 'jiopay_prod'@'localhost' IDENTIFIED BY 'secure_random_password';

-- Grant minimal required permissions
GRANT SELECT, INSERT, UPDATE, DELETE ON wordpress_db.wp_posts TO 'jiopay_prod'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON wordpress_db.wp_postmeta TO 'jiopay_prod'@'localhost';
GRANT SELECT, INSERT, UPDATE ON wordpress_db.wp_options TO 'jiopay_prod'@'localhost';

FLUSH PRIVILEGES;
```

## 📊 Performance Optimization

### Caching Configuration

#### WordPress Object Cache
```php
// Enable object caching for better performance
if (!defined('WP_CACHE')) {
    define('WP_CACHE', true);
}

// Redis configuration (if using Redis)
define('WP_REDIS_HOST', '127.0.0.1');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_DATABASE', 0);
```

#### CDN Configuration
```php
// CDN settings for static assets
add_filter('jio_pay_script_url', function($url) {
    return str_replace(site_url(), 'https://cdn.yourdomain.com', $url);
});
```

### Database Optimization

#### Query Optimization
```sql
-- Add indexes for better performance
ALTER TABLE wp_postmeta ADD INDEX jio_pay_orders (meta_key, meta_value(10)) 
WHERE meta_key IN ('_payment_method', '_transaction_id');

-- Clean up old transients
DELETE FROM wp_options WHERE option_name LIKE '_transient_%' AND option_value < UNIX_TIMESTAMP();
```

## 🔍 Monitoring & Logging

### Production Monitoring

#### Health Check Endpoint
```php
// health-check.php
<?php
require_once 'wp-config.php';

$health_status = array(
    'timestamp' => current_time('mysql'),
    'wordpress' => get_bloginfo('version'),
    'woocommerce' => WC_VERSION,
    'jio_pay_gateway' => '1.0.0',
    'database' => 'ok',
    'ssl' => is_ssl() ? 'ok' : 'error'
);

// Check database connection
try {
    global $wpdb;
    $wpdb->get_var("SELECT 1");
    $health_status['database'] = 'ok';
} catch (Exception $e) {
    $health_status['database'] = 'error';
}

header('Content-Type: application/json');
echo json_encode($health_status);
?>
```

#### Error Logging
```php
// Custom error logger for production
class JioPayProductionLogger {
    
    public function log_error($message, $context = array()) {
        $log_entry = array(
            'timestamp' => current_time('c'),
            'message' => $message,
            'context' => $context,
            'server' => $_SERVER['SERVER_NAME'] ?? 'unknown',
            'user_id' => get_current_user_id(),
            'ip' => $this->get_client_ip()
        );
        
        // Log to file
        error_log('JIO_PAY_PROD: ' . json_encode($log_entry));
        
        // Send to external logging service (optional)
        $this->send_to_logging_service($log_entry);
    }
    
    private function send_to_logging_service($log_entry) {
        // Send to external service like Papertrail, Loggly, etc.
        wp_remote_post('https://logs.papertrailapp.com/api/v1/logs', array(
            'headers' => array('X-Papertrail-Token' => 'your_token'),
            'body' => json_encode($log_entry)
        ));
    }
}
```

### Performance Monitoring

#### Response Time Tracking
```php
// Track payment processing time
add_action('jio_pay_payment_start', function() {
    update_option('jio_pay_payment_start_time', microtime(true));
});

add_action('jio_pay_payment_complete', function($order_id) {
    $start_time = get_option('jio_pay_payment_start_time');
    $end_time = microtime(true);
    $processing_time = $end_time - $start_time;
    
    // Log performance metrics
    error_log("JIO_PAY_PERFORMANCE: Order {$order_id} processed in {$processing_time}s");
    
    // Store in database for analysis
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'jio_pay_performance',
        array(
            'order_id' => $order_id,
            'processing_time' => $processing_time,
            'timestamp' => current_time('mysql')
        )
    );
});
```

## 🚦 Rollback Strategy

### Automated Rollback

#### Rollback Script
```bash
#!/bin/bash
# rollback.sh

set -e

BACKUP_DIR="/backups/woo-jiopay"
PLUGIN_DIR="/path/to/wordpress/wp-content/plugins/woo-jiopay"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo "Starting rollback to previous version..."

# Create current state backup before rollback
echo "Backing up current state..."
cp -r "$PLUGIN_DIR" "$BACKUP_DIR/current_$TIMESTAMP"

# Restore from last known good backup
echo "Restoring from backup..."
LATEST_BACKUP=$(ls -t "$BACKUP_DIR"/backup_* | head -1)
rm -rf "$PLUGIN_DIR"
cp -r "$LATEST_BACKUP" "$PLUGIN_DIR"

# Set proper permissions
chown -R www-data:www-data "$PLUGIN_DIR"
chmod -R 755 "$PLUGIN_DIR"

# Clear cache
wp cache flush

echo "Rollback completed successfully!"
```

### Database Rollback

#### Schema Migration Down
```php
// Database rollback functionality
class JioPayMigration {
    
    public function rollback_to_version($target_version) {
        $current_version = get_option('jio_pay_db_version', '1.0.0');
        
        while (version_compare($current_version, $target_version, '>')) {
            $this->rollback_one_version($current_version);
            $current_version = $this->get_previous_version($current_version);
        }
        
        update_option('jio_pay_db_version', $target_version);
    }
    
    private function rollback_one_version($version) {
        switch ($version) {
            case '1.1.0':
                // Rollback from 1.1.0 to 1.0.0
                global $wpdb;
                $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}jio_pay_logs");
                break;
        }
    }
}
```

## 📋 Post-Deployment Verification

### Deployment Checklist

#### Functional Testing
```bash
# Test payment flow
curl -X POST "https://yourdomain.com/wp-admin/admin-ajax.php" \
  -d "action=jio_pay_test" \
  -H "Content-Type: application/x-www-form-urlencoded"

# Expected response: {"success":true,"data":{"message":"Test endpoint working"}}
```

#### Security Testing
```bash
# Test SSL configuration
nmap --script ssl-enum-ciphers -p 443 yourdomain.com

# Test for common vulnerabilities
nikto -h https://yourdomain.com/wp-content/plugins/woo-jiopay/
```

#### Performance Testing
```bash
# Load testing with Apache Bench
ab -n 100 -c 10 https://yourdomain.com/checkout/

# Monitor response times
curl -w "@curl-format.txt" -o /dev/null -s https://yourdomain.com/checkout/
```

### Post-Deployment Monitoring

#### First 24 Hours
- [ ] Monitor error logs continuously
- [ ] Check payment success rates
- [ ] Verify SSL certificate status
- [ ] Monitor server resources
- [ ] Test payment flow manually

#### First Week
- [ ] Analyze payment performance metrics
- [ ] Review security logs
- [ ] Check for any customer complaints
- [ ] Monitor database performance
- [ ] Verify backup systems

---

**Document Version**: 1.0.0  
**Last Updated**: November 2025  
**Deployment Contact**: [devops@example.com]