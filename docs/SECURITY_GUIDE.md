# Security Guide - Jio Pay Gateway

## 🔒 Security Overview

This guide outlines the security measures, best practices, and requirements for safely implementing and maintaining the Jio Pay Gateway plugin in production environments.

## 🛡️ Core Security Features

### Data Protection
- **SSL/TLS Encryption**: All communications encrypted in transit
- **No Sensitive Data Storage**: Payment data never stored locally
- **Secure Hash Verification**: All payments verified with cryptographic hashes
- **Input Sanitization**: All user inputs sanitized before processing
- **Output Escaping**: All outputs properly escaped to prevent XSS

### Authentication & Authorization
- **WordPress Nonces**: CSRF protection for all AJAX requests
- **User Capability Checks**: Proper permission verification
- **API Key Security**: Secure API credential management
- **Session Management**: Secure payment session handling

## 🔐 Implementation Security

### 1. Secure Configuration

#### SSL Certificate Requirements
```bash
# Verify SSL certificate
openssl s_client -connect yourdomain.com:443 -servername yourdomain.com

# Expected output should include:
# Verify return code: 0 (ok)
```

#### WordPress Security Headers
Add to `.htaccess` or server configuration:
```apache
# Security Headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"
Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
Header always set Content-Security-Policy "default-src 'self'"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

#### File Permissions
```bash
# WordPress root directory
chmod 755 /path/to/wordpress/

# WordPress files
find /path/to/wordpress/ -type f -exec chmod 644 {} \;

# WordPress directories
find /path/to/wordpress/ -type d -exec chmod 755 {} \;

# wp-config.php (extra security)
chmod 600 wp-config.php

# Plugin directory
chmod 755 wp-content/plugins/woo-jiopay/
chmod 644 wp-content/plugins/woo-jiopay/*.php
```

### 2. API Key Management

#### Secure Storage
```php
// Store in wp-config.php (recommended)
define('JIO_PAY_API_KEY', 'your_secure_api_key_here');
define('JIO_PAY_MERCHANT_ID', 'your_merchant_id_here');

// Access in plugin
$api_key = defined('JIO_PAY_API_KEY') ? JIO_PAY_API_KEY : $this->get_option('api_key');
```

#### Environment Variables (Advanced)
```bash
# In server environment
export JIO_PAY_API_KEY="your_secure_api_key"
export JIO_PAY_MERCHANT_ID="your_merchant_id"
```

```php
// Access in PHP
$api_key = getenv('JIO_PAY_API_KEY') ?: $this->get_option('api_key');
```

### 3. Input Validation & Sanitization

#### Payment Data Validation
```php
public function validate_payment_data($data) {
    $errors = array();
    
    // Validate transaction ID
    if (empty($data['txnAuthID']) || !preg_match('/^[0-9]{10,15}$/', $data['txnAuthID'])) {
        $errors[] = 'Invalid transaction ID format';
    }
    
    // Validate amount
    if (empty($data['amount']) || !is_numeric($data['amount']) || floatval($data['amount']) <= 0) {
        $errors[] = 'Invalid amount';
    }
    
    // Validate response code
    if (empty($data['txnResponseCode']) || !preg_match('/^[0-9]{4}$/', $data['txnResponseCode'])) {
        $errors[] = 'Invalid response code';
    }
    
    // Validate timestamp
    if (empty($data['txnDateTime']) || !preg_match('/^[0-9]{14}$/', $data['txnDateTime'])) {
        $errors[] = 'Invalid timestamp format';
    }
    
    // Validate secure hash
    if (empty($data['secureHash']) || !preg_match('/^[a-f0-9]{64}$/', $data['secureHash'])) {
        $errors[] = 'Invalid secure hash';
    }
    
    return $errors;
}
```

#### Data Sanitization
```php
public function sanitize_payment_data($data) {
    return array(
        'txnAuthID' => sanitize_text_field($data['txnAuthID'] ?? ''),
        'txnResponseCode' => sanitize_text_field($data['txnResponseCode'] ?? ''),
        'txnRespDescription' => sanitize_textarea_field($data['txnRespDescription'] ?? ''),
        'secureHash' => sanitize_text_field($data['secureHash'] ?? ''),
        'amount' => sanitize_text_field($data['amount'] ?? ''),
        'txnDateTime' => sanitize_text_field($data['txnDateTime'] ?? ''),
        'merchantTrId' => sanitize_text_field($data['merchantTrId'] ?? '')
    );
}
```

### 4. Secure Hash Implementation

#### Hash Generation
```php
private function generate_secure_hash($data, $merchant_key) {
    // Create hash string with specific order
    $hash_string = implode('|', array(
        $data['merchantId'],
        $data['merchantTrId'],
        $data['amount'],
        $data['txnDateTime'],
        $merchant_key
    ));
    
    // Generate SHA-256 hash
    return hash('sha256', $hash_string);
}
```

#### Hash Verification
```php
public function verify_secure_hash($payment_data, $merchant_key) {
    $received_hash = $payment_data['secureHash'];
    $expected_hash = $this->generate_secure_hash($payment_data, $merchant_key);
    
    // Use hash_equals to prevent timing attacks
    if (!hash_equals($expected_hash, $received_hash)) {
        $this->log_security_event('Hash verification failed', array(
            'received_hash' => $received_hash,
            'expected_hash' => $expected_hash,
            'ip_address' => $this->get_client_ip()
        ));
        return false;
    }
    
    return true;
}
```

## 🔍 Security Monitoring

### 1. Logging Security Events

#### Security Event Logger
```php
class JioPaySecurityLogger {
    
    public function log_security_event($event_type, $data = array()) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'event_type' => $event_type,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'user_id' => get_current_user_id(),
            'data' => $data
        );
        
        // Log to WordPress debug log
        if (WP_DEBUG_LOG) {
            error_log('JIO_PAY_SECURITY: ' . json_encode($log_entry));
        }
        
        // Log to database for analysis
        $this->store_security_log($log_entry);
        
        // Send alert for critical events
        if (in_array($event_type, array('hash_verification_failed', 'multiple_failures'))) {
            $this->send_security_alert($log_entry);
        }
    }
    
    private function get_client_ip() {
        $ip_keys = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                return trim($ips[0]);
            }
        }
        
        return 'unknown';
    }
}
```

### 2. Rate Limiting

#### Payment Attempt Limiting
```php
public function check_rate_limit($ip_address, $max_attempts = 5, $time_window = 300) {
    $transient_key = 'jio_pay_attempts_' . md5($ip_address);
    $attempts = get_transient($transient_key);
    
    if ($attempts === false) {
        $attempts = 0;
    }
    
    if ($attempts >= $max_attempts) {
        $this->log_security_event('rate_limit_exceeded', array(
            'ip_address' => $ip_address,
            'attempts' => $attempts
        ));
        
        wp_die('Too many payment attempts. Please try again later.', 'Rate Limited', array(
            'response' => 429
        ));
    }
    
    // Increment attempt counter
    set_transient($transient_key, $attempts + 1, $time_window);
    
    return true;
}
```

### 3. Fraud Detection

#### Suspicious Activity Detection
```php
public function detect_suspicious_activity($payment_data, $order_data) {
    $flags = array();
    
    // Check for unusual amount patterns
    if ($this->is_unusual_amount($payment_data['amount'])) {
        $flags[] = 'unusual_amount';
    }
    
    // Check for rapid successive payments
    if ($this->has_rapid_payments($order_data['customer_email'])) {
        $flags[] = 'rapid_payments';
    }
    
    // Check for mismatched geolocation
    if ($this->has_geo_mismatch($order_data)) {
        $flags[] = 'geo_mismatch';
    }
    
    // Check for known fraud patterns
    if ($this->matches_fraud_pattern($payment_data)) {
        $flags[] = 'fraud_pattern';
    }
    
    if (!empty($flags)) {
        $this->log_security_event('suspicious_activity', array(
            'flags' => $flags,
            'order_id' => $order_data['order_id'],
            'customer_email' => $order_data['customer_email']
        ));
        
        // Hold order for manual review
        $this->hold_order_for_review($order_data['order_id'], $flags);
    }
    
    return $flags;
}
```

## 🚨 Incident Response

### 1. Security Incident Handling

#### Incident Response Plan
```php
class JioPayIncidentResponse {
    
    public function handle_security_incident($incident_type, $severity = 'medium') {
        switch ($severity) {
            case 'critical':
                $this->disable_gateway();
                $this->notify_admin_immediately();
                $this->log_incident($incident_type, $severity);
                break;
                
            case 'high':
                $this->enable_strict_mode();
                $this->notify_admin();
                $this->log_incident($incident_type, $severity);
                break;
                
            case 'medium':
                $this->increase_monitoring();
                $this->log_incident($incident_type, $severity);
                break;
        }
    }
    
    private function disable_gateway() {
        update_option('woocommerce_jio_pay_settings', array_merge(
            get_option('woocommerce_jio_pay_settings', array()),
            array('enabled' => 'no')
        ));
    }
    
    private function notify_admin_immediately() {
        wp_mail(
            get_option('admin_email'),
            '[CRITICAL] Jio Pay Gateway Security Incident',
            'A critical security incident has been detected. The gateway has been automatically disabled.',
            array('X-Priority: 1')
        );
    }
}
```

### 2. Backup and Recovery

#### Secure Configuration Backup
```php
public function backup_secure_configuration() {
    $config = array(
        'gateway_settings' => get_option('woocommerce_jio_pay_settings'),
        'security_logs' => $this->get_recent_security_logs(),
        'whitelist' => get_option('jio_pay_ip_whitelist', array()),
        'blacklist' => get_option('jio_pay_ip_blacklist', array())
    );
    
    // Encrypt sensitive data
    $encrypted_config = $this->encrypt_config($config);
    
    // Store securely
    update_option('jio_pay_config_backup', $encrypted_config);
    
    return true;
}
```

## 📊 Security Auditing

### 1. Regular Security Checks

#### Automated Security Audit
```php
public function run_security_audit() {
    $audit_results = array();
    
    // Check SSL certificate
    $audit_results['ssl'] = $this->check_ssl_certificate();
    
    // Check file permissions
    $audit_results['permissions'] = $this->check_file_permissions();
    
    // Check for known vulnerabilities
    $audit_results['vulnerabilities'] = $this->check_vulnerabilities();
    
    // Check configuration security
    $audit_results['configuration'] = $this->check_secure_configuration();
    
    // Generate audit report
    $this->generate_audit_report($audit_results);
    
    return $audit_results;
}
```

### 2. Compliance Requirements

#### PCI DSS Compliance Guidelines
```php
// PCI DSS compliance checks
public function check_pci_compliance() {
    $compliance_status = array();
    
    // Check 1: Secure network and systems
    $compliance_status['secure_network'] = $this->verify_secure_network();
    
    // Check 2: Protect cardholder data
    $compliance_status['data_protection'] = $this->verify_data_protection();
    
    // Check 3: Maintain vulnerability management
    $compliance_status['vulnerability_mgmt'] = $this->verify_vulnerability_management();
    
    // Check 4: Implement strong access control
    $compliance_status['access_control'] = $this->verify_access_control();
    
    // Check 5: Regularly monitor and test networks
    $compliance_status['monitoring'] = $this->verify_monitoring();
    
    // Check 6: Maintain information security policy
    $compliance_status['security_policy'] = $this->verify_security_policy();
    
    return $compliance_status;
}
```

## 🔧 Security Hardening

### 1. WordPress Hardening

#### Security-focused wp-config.php
```php
// Security keys and salts (generate unique values)
define('AUTH_KEY',         'your-unique-phrase-here');
define('SECURE_AUTH_KEY',  'your-unique-phrase-here');
define('LOGGED_IN_KEY',    'your-unique-phrase-here');
define('NONCE_KEY',        'your-unique-phrase-here');

// Security configurations
define('DISALLOW_FILE_EDIT', true);           // Disable file editing
define('DISALLOW_FILE_MODS', true);           // Disable file modifications
define('FORCE_SSL_ADMIN', true);              // Force SSL for admin
define('WP_DEBUG', false);                    // Disable debug in production
define('WP_DEBUG_LOG', true);                 // Enable error logging
define('WP_DEBUG_DISPLAY', false);            // Hide errors from display

// Limit login attempts
define('WP_FAIL2BAN_BLOCKED_USERS', 'admin,administrator');

// Database security
$table_prefix = 'wp_' . rand(100, 999) . '_';  // Random table prefix
```

### 2. Server-Level Security

#### Nginx Configuration
```nginx
# Rate limiting
limit_req_zone $binary_remote_addr zone=payment:10m rate=5r/m;

server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    
    # SSL configuration
    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    
    # Security headers
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;
    add_header X-XSS-Protection "1; mode=block";
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    
    # Rate limiting for payment endpoints
    location ~ /wp-admin/admin-ajax.php {
        limit_req zone=payment burst=10 nodelay;
        try_files $uri =404;
        fastcgi_pass php;
    }
}
```

#### Apache Configuration
```apache
# Enable security modules
LoadModule security2_module modules/mod_security2.so
LoadModule evasive24_module modules/mod_evasive24.so

# Security rules
<IfModule mod_security2.c>
    SecRuleEngine On
    SecDefaultAction "phase:1,deny,log,status:406"
    
    # Block suspicious payment requests
    SecRule ARGS "@detectSQLi" "id:1001,phase:2,block,msg:'SQL Injection Attack'"
    SecRule ARGS "@detectXSS" "id:1002,phase:2,block,msg:'XSS Attack'"
</IfModule>

# Rate limiting
<IfModule mod_evasive24.c>
    DOSHashTableSize    2048
    DOSPageCount        5
    DOSPageInterval     1
    DOSSiteCount        50
    DOSSiteInterval     1
    DOSBlockingPeriod   300
</IfModule>
```

## 📈 Security Monitoring & Alerts

### 1. Real-time Monitoring

#### Security Dashboard
```php
public function get_security_dashboard_data() {
    return array(
        'threat_level' => $this->calculate_threat_level(),
        'recent_incidents' => $this->get_recent_incidents(24), // Last 24 hours
        'failed_payments' => $this->get_failed_payment_count(24),
        'blocked_ips' => $this->get_blocked_ips(),
        'ssl_status' => $this->check_ssl_status(),
        'vulnerability_scan' => $this->get_last_vulnerability_scan()
    );
}
```

### 2. Automated Alerts

#### Security Alert System
```php
public function setup_security_alerts() {
    // Critical alerts (immediate notification)
    add_action('jio_pay_hash_verification_failed', array($this, 'send_critical_alert'));
    add_action('jio_pay_multiple_failures', array($this, 'send_critical_alert'));
    
    // Warning alerts (daily digest)
    add_action('jio_pay_suspicious_activity', array($this, 'queue_warning_alert'));
    add_action('jio_pay_rate_limit_exceeded', array($this, 'queue_warning_alert'));
    
    // Schedule daily security report
    if (!wp_next_scheduled('jio_pay_daily_security_report')) {
        wp_schedule_event(time(), 'daily', 'jio_pay_daily_security_report');
    }
}
```

---

**Document Version**: 1.0.0  
**Last Updated**: November 2025  
**Security Contact**: [security@example.com]