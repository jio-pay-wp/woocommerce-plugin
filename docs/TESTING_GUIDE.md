# Testing Guide - Jio Pay Gateway

## 🧪 Testing Overview

This guide provides comprehensive testing procedures for the Jio Pay Gateway plugin to ensure reliability, security, and performance in production environments.

## 🎯 Testing Strategy

### Testing Pyramid
```
                    E2E Tests
                   ↗           ↖
            Integration Tests
           ↗                   ↖
    Unit Tests              Manual Tests
   ↗                                    ↖
Security Tests                    Performance Tests
```

### Test Categories
1. **Unit Tests** - Individual functions and methods
2. **Integration Tests** - WordPress/WooCommerce integration  
3. **End-to-End Tests** - Complete payment workflows
4. **Security Tests** - Vulnerability and penetration testing
5. **Performance Tests** - Load and stress testing
6. **Manual Tests** - User experience and edge cases

## 🔧 Test Environment Setup

### Local Development Environment

#### Prerequisites
```bash
# Required software
- PHP 7.4+ with extensions: curl, json, mbstring, openssl
- WordPress 5.0+
- WooCommerce 3.0+
- MySQL 5.6+ or MariaDB 10.1+
- Node.js 16+ (for testing tools)
- Git
```

#### Setup Commands
```bash
# Clone repository
git clone https://github.com/yourusername/jio-pay-gateway.git
cd jio-pay-gateway

# Install testing dependencies
npm install --save-dev jest puppeteer @wordpress/env

# Setup WordPress test environment
npx @wordpress/env start

# Install WooCommerce in test environment
npx wp plugin install woocommerce --activate
```

### Testing Configuration

#### wp-config-test.php
```php
<?php
// Test environment configuration
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);
define('SCRIPT_DEBUG', true);

// Test database
define('DB_NAME', 'wordpress_test');
define('DB_USER', 'test_user');
define('DB_PASSWORD', 'test_password');
define('DB_HOST', 'localhost');

// Jio Pay test credentials
define('JIO_PAY_TEST_MODE', true);
define('JIO_PAY_TEST_MERCHANT_ID', 'TEST123456');
define('JIO_PAY_TEST_API_KEY', 'test_api_key_here');

// Disable external HTTP requests during testing
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', 'api.jiopay.com,localhost');
?>
```

## 🔬 Unit Testing

### PHPUnit Setup

#### phpunit.xml
```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
    verbose="true">
    <testsuites>
        <testsuite name="Jio Pay Gateway">
            <directory>./tests/</directory>
        </testsuite>
    </testsuites>
    <filter>
        <whitelist>
            <directory suffix=".php">./includes/</directory>
            <file>./jio-pay-gateway.php</file>
        </whitelist>
    </filter>
</phpunit>
```

#### Bootstrap File
```php
<?php
// tests/bootstrap.php
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin() {
    require dirname(__FILE__) . '/../jio-pay-gateway.php';
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';

// Activate WooCommerce
activate_plugin('woocommerce/woocommerce.php');
?>
```

### Unit Test Examples

#### Gateway Configuration Tests
```php
<?php
// tests/test-gateway-config.php
class Test_Gateway_Config extends WP_UnitTestCase {
    
    private $gateway;
    
    public function setUp(): void {
        parent::setUp();
        $this->gateway = new WC_Jio_Pay_Gateway();
    }
    
    public function test_gateway_initialization() {
        $this->assertEquals('jio_pay', $this->gateway->id);
        $this->assertEquals('Jio Pay Gateway', $this->gateway->method_title);
        $this->assertTrue($this->gateway->has_fields);
    }
    
    public function test_form_fields_structure() {
        $form_fields = $this->gateway->init_form_fields();
        
        $this->assertArrayHasKey('enabled', $this->gateway->form_fields);
        $this->assertArrayHasKey('title', $this->gateway->form_fields);
        $this->assertArrayHasKey('merchant_id', $this->gateway->form_fields);
        $this->assertArrayHasKey('api_key', $this->gateway->form_fields);
    }
    
    public function test_payment_validation() {
        // Test valid payment data
        $valid_data = array(
            'txnAuthID' => '1234567890',
            'txnResponseCode' => '0000',
            'amount' => '750.00',
            'txnDateTime' => '20251103161256'
        );
        
        $errors = $this->gateway->validate_payment_data($valid_data);
        $this->assertEmpty($errors);
        
        // Test invalid payment data
        $invalid_data = array(
            'txnAuthID' => '',
            'txnResponseCode' => 'invalid',
            'amount' => 'not_a_number'
        );
        
        $errors = $this->gateway->validate_payment_data($invalid_data);
        $this->assertNotEmpty($errors);
    }
}
?>
```

#### Payment Processing Tests
```php
<?php
// tests/test-payment-processing.php
class Test_Payment_Processing extends WP_UnitTestCase {
    
    private $gateway;
    private $order;
    
    public function setUp(): void {
        parent::setUp();
        $this->gateway = new WC_Jio_Pay_Gateway();
        $this->order = $this->create_test_order();
    }
    
    private function create_test_order() {
        $order = wc_create_order();
        $order->set_total(750.00);
        $order->set_currency('INR');
        $order->set_billing_email('test@example.com');
        $order->save();
        return $order;
    }
    
    public function test_payment_processing() {
        $order_id = $this->order->get_id();
        
        $result = $this->gateway->process_payment($order_id);
        
        $this->assertEquals('success', $result['result']);
        $this->assertArrayHasKey('redirect', $result);
    }
    
    public function test_payment_verification_success() {
        $payment_data = array(
            'txnAuthID' => '1234567890',
            'txnResponseCode' => '0000',
            'txnRespDescription' => 'Transaction successful',
            'amount' => '75000', // In paisa
            'txnDateTime' => '20251103161256',
            'merchantTrId' => '3507027521',
            'secureHash' => $this->generate_test_hash()
        );
        
        $result = $this->gateway->verify_payment_data($this->order->get_id(), $payment_data);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('completed', $this->order->get_status());
    }
    
    public function test_payment_verification_failure() {
        $payment_data = array(
            'txnAuthID' => '1234567890',
            'txnResponseCode' => '0001', // Failed response code
            'txnRespDescription' => 'Transaction failed',
            'amount' => '75000',
            'txnDateTime' => '20251103161256'
        );
        
        $result = $this->gateway->verify_payment_data($this->order->get_id(), $payment_data);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $this->order->get_status());
    }
    
    private function generate_test_hash() {
        return hash('sha256', 'test_hash_data');
    }
}
?>
```

### Running Unit Tests
```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test file
./vendor/bin/phpunit tests/test-gateway-config.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/

# Run in watch mode
./vendor/bin/phpunit --watch
```

## 🔗 Integration Testing

### WordPress Integration Tests

#### WooCommerce Integration
```php
<?php
// tests/test-woocommerce-integration.php
class Test_WooCommerce_Integration extends WC_Unit_Test_Case {
    
    public function test_gateway_registration() {
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        $this->assertArrayHasKey('jio_pay', $gateways);
    }
    
    public function test_checkout_integration() {
        // Create product and add to cart
        $product = WC_Helper_Product::create_simple_product();
        WC()->cart->add_to_cart($product->get_id(), 1);
        
        // Go to checkout
        $checkout = WC()->checkout();
        $this->assertInstanceOf('WC_Checkout', $checkout);
        
        // Check if Jio Pay is available
        $available_gateways = $checkout->get_available_payment_gateways();
        $this->assertArrayHasKey('jio_pay', $available_gateways);
    }
    
    public function test_order_status_updates() {
        $order = WC_Helper_Order::create_order();
        $order->set_payment_method('jio_pay');
        $order->save();
        
        // Test status transitions
        $order->update_status('pending');
        $this->assertEquals('pending', $order->get_status());
        
        $order->payment_complete('123456789');
        $this->assertEquals('processing', $order->get_status());
    }
}
?>
```

### AJAX Integration Tests
```php
<?php
// tests/test-ajax-integration.php
class Test_AJAX_Integration extends WP_Ajax_UnitTestCase {
    
    private $gateway;
    
    public function setUp(): void {
        parent::setUp();
        $this->gateway = new WC_Jio_Pay_Gateway();
        
        // Set up AJAX actions
        add_action('wp_ajax_jio_pay_verify_payment', array($this->gateway, 'verify_payment'));
        add_action('wp_ajax_nopriv_jio_pay_verify_payment', array($this->gateway, 'verify_payment'));
    }
    
    public function test_ajax_verify_payment() {
        $_POST['action'] = 'jio_pay_verify_payment';
        $_POST['nonce'] = wp_create_nonce('jio_pay_nonce');
        $_POST['order_id'] = 123;
        $_POST['payment_data'] = json_encode(array(
            'txnAuthID' => '1234567890',
            'txnResponseCode' => '0000'
        ));
        
        try {
            $this->_handleAjax('jio_pay_verify_payment');
        } catch (WPAjaxDieContinueException $e) {
            // AJAX call completed successfully
        }
        
        $response = json_decode($this->_last_response, true);
        $this->assertTrue($response['success']);
    }
    
    public function test_ajax_security() {
        $_POST['action'] = 'jio_pay_verify_payment';
        $_POST['nonce'] = 'invalid_nonce';
        
        try {
            $this->_handleAjax('jio_pay_verify_payment');
        } catch (WPAjaxDieStopException $e) {
            // Expected security failure
            $this->assertStringContains('Security check failed', $e->getMessage());
        }
    }
}
?>
```

## 🎭 End-to-End Testing

### Puppeteer E2E Tests

#### Package.json Setup
```json
{
  "devDependencies": {
    "puppeteer": "^19.0.0",
    "jest": "^29.0.0",
    "jest-puppeteer": "^8.0.0"
  },
  "scripts": {
    "test:e2e": "jest --config=jest-e2e.config.js",
    "test:e2e:headless": "HEADLESS=true jest --config=jest-e2e.config.js"
  }
}
```

#### Jest E2E Configuration
```javascript
// jest-e2e.config.js
module.exports = {
  preset: 'jest-puppeteer',
  testMatch: ['**/e2e/**/*.test.js'],
  setupFilesAfterEnv: ['<rootDir>/e2e/setup.js']
};
```

#### E2E Test Examples
```javascript
// e2e/payment-flow.test.js
describe('Jio Pay Payment Flow', () => {
  let page;
  
  beforeAll(async () => {
    page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 720 });
  });
  
  afterAll(async () => {
    await page.close();
  });
  
  test('Complete payment process', async () => {
    // Navigate to shop
    await page.goto('http://localhost:8080/shop');
    
    // Add product to cart
    await page.click('.add_to_cart_button');
    await page.waitForSelector('.woocommerce-message');
    
    // Go to checkout
    await page.goto('http://localhost:8080/checkout');
    
    // Fill billing details
    await page.type('#billing_first_name', 'Test');
    await page.type('#billing_last_name', 'User');
    await page.type('#billing_email', 'test@example.com');
    await page.type('#billing_phone', '9876543210');
    
    // Select Jio Pay payment method
    await page.click('#payment_method_jio_pay');
    
    // Place order
    await page.click('#place_order');
    
    // Wait for payment popup or redirect
    await page.waitForSelector('.jio-pay-notification', { timeout: 10000 });
    
    // Verify success message or payment completion
    const notification = await page.$eval('.jio-pay-notification', el => el.textContent);
    expect(notification).toContain('Payment');
  });
  
  test('Handle payment cancellation', async () => {
    await page.goto('http://localhost:8080/checkout');
    
    // Fill required fields and select Jio Pay
    await page.type('#billing_email', 'test@example.com');
    await page.click('#payment_method_jio_pay');
    
    // Mock payment cancellation
    await page.evaluate(() => {
      window.jioPayCancelPayment = true;
    });
    
    await page.click('#place_order');
    
    // Verify cancellation handling
    await page.waitForSelector('.jio-pay-error');
    const errorMessage = await page.$eval('.jio-pay-error', el => el.textContent);
    expect(errorMessage).toContain('cancelled');
  });
  
  test('Responsive design on mobile', async () => {
    // Set mobile viewport
    await page.setViewport({ width: 375, height: 667 });
    
    await page.goto('http://localhost:8080/checkout');
    
    // Test mobile layout
    const paymentSection = await page.$('.payment_methods');
    const boundingBox = await paymentSection.boundingBox();
    
    expect(boundingBox.width).toBeLessThanOrEqual(375);
    
    // Test mobile payment flow
    await page.click('#payment_method_jio_pay');
    const paymentButton = await page.$('#place_order');
    
    expect(await paymentButton.isIntersectingViewport()).toBe(true);
  });
});
```

### Visual Regression Testing
```javascript
// e2e/visual-regression.test.js
describe('Visual Regression Tests', () => {
  test('Checkout page appearance', async () => {
    const page = await browser.newPage();
    await page.goto('http://localhost:8080/checkout');
    
    // Wait for page to fully load
    await page.waitForSelector('#payment_method_jio_pay');
    
    // Take screenshot
    const screenshot = await page.screenshot({
      fullPage: true,
      path: 'screenshots/checkout-page.png'
    });
    
    // Compare with baseline (requires additional setup)
    expect(screenshot).toMatchImageSnapshot();
  });
  
  test('Payment notification appearance', async () => {
    const page = await browser.newPage();
    await page.goto('http://localhost:8080/checkout');
    
    // Trigger notification
    await page.evaluate(() => {
      showPaymentNotification('Test notification', 'success');
    });
    
    await page.waitForSelector('.jio-pay-notification');
    
    const screenshot = await page.screenshot({
      clip: { x: 0, y: 0, width: 400, height: 200 }
    });
    
    expect(screenshot).toMatchImageSnapshot();
  });
});
```

## 🔐 Security Testing

### OWASP Security Tests

#### SQL Injection Tests
```php
<?php
// tests/test-security-sql-injection.php
class Test_SQL_Injection extends WP_UnitTestCase {
    
    private $gateway;
    
    public function setUp(): void {
        parent::setUp();
        $this->gateway = new WC_Jio_Pay_Gateway();
    }
    
    public function test_sql_injection_protection() {
        $malicious_inputs = array(
            "'; DROP TABLE wp_posts; --",
            "1' OR '1'='1",
            "1; SELECT * FROM wp_users; --"
        );
        
        foreach ($malicious_inputs as $input) {
            $payment_data = array(
                'txnAuthID' => $input,
                'amount' => $input,
                'merchantTrId' => $input
            );
            
            // Should not cause SQL injection
            $result = $this->gateway->verify_payment_data(1, $payment_data);
            $this->assertFalse($result['success']);
        }
    }
}
?>
```

#### XSS Protection Tests
```php
<?php
// tests/test-security-xss.php
class Test_XSS_Protection extends WP_UnitTestCase {
    
    public function test_xss_protection() {
        $xss_payloads = array(
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
            '<img src="x" onerror="alert(\'XSS\')">'
        );
        
        foreach ($xss_payloads as $payload) {
            $sanitized = sanitize_text_field($payload);
            $this->assertNotContains('<script>', $sanitized);
            $this->assertNotContains('javascript:', $sanitized);
            $this->assertNotContains('onerror=', $sanitized);
        }
    }
}
?>
```

### Penetration Testing

#### Automated Security Scanning
```bash
#!/bin/bash
# security-scan.sh

echo "Starting security scan..."

# Check for known vulnerabilities
echo "Checking for WordPress vulnerabilities..."
wp vuln status

# Scan for common security issues
echo "Running security scanner..."
nikto -h http://localhost:8080 -output nikto-report.xml

# Check SSL configuration
echo "Testing SSL configuration..."
nmap --script ssl-enum-ciphers -p 443 localhost

# Test for common injection attacks
echo "Testing for injection vulnerabilities..."
sqlmap -u "http://localhost:8080/wp-admin/admin-ajax.php" \
       --data="action=jio_pay_verify_payment&order_id=1" \
       --level=3 --risk=2

echo "Security scan completed. Check reports for issues."
```

## ⚡ Performance Testing

### Load Testing with Artillery

#### Artillery Configuration
```yaml
# artillery-config.yml
config:
  target: 'http://localhost:8080'
  phases:
    - duration: 60
      arrivalRate: 5
      name: "Warm up"
    - duration: 120
      arrivalRate: 10
      name: "Normal load"
    - duration: 60
      arrivalRate: 20
      name: "High load"
  payload:
    path: "test-data.csv"
    fields:
      - "email"
      - "phone"

scenarios:
  - name: "Payment flow"
    weight: 70
    flow:
      - get:
          url: "/checkout"
      - think: 5
      - post:
          url: "/wp-admin/admin-ajax.php"
          form:
            action: "jio_pay_verify_payment"
            order_id: "{{ $randomInt(1, 1000) }}"
            
  - name: "Browse products"
    weight: 30
    flow:
      - get:
          url: "/shop"
      - get:
          url: "/product/{{ $randomInt(1, 50) }}"
```

#### Performance Test Runner
```bash
#!/bin/bash
# performance-test.sh

echo "Starting performance tests..."

# Install Artillery if not present
npm install -g artillery

# Run load tests
artillery run artillery-config.yml --output performance-report.json

# Generate HTML report
artillery report performance-report.json --output performance-report.html

# Database performance test
echo "Testing database performance..."
mysql -u root -p wordpress_test << EOF
EXPLAIN SELECT * FROM wp_posts WHERE post_type = 'shop_order' AND post_status = 'wc-processing';
SHOW PROCESSLIST;
EOF

echo "Performance tests completed. Check reports for results."
```

### Memory and Resource Testing
```php
<?php
// tests/test-performance.php
class Test_Performance extends WP_UnitTestCase {
    
    public function test_memory_usage() {
        $initial_memory = memory_get_usage();
        
        // Create and process multiple orders
        for ($i = 0; $i < 100; $i++) {
            $order = wc_create_order();
            $order->set_total(rand(100, 1000));
            $order->save();
            
            $gateway = new WC_Jio_Pay_Gateway();
            $gateway->process_payment($order->get_id());
        }
        
        $final_memory = memory_get_usage();
        $memory_increase = $final_memory - $initial_memory;
        
        // Memory increase should be reasonable (less than 50MB)
        $this->assertLessThan(50 * 1024 * 1024, $memory_increase);
    }
    
    public function test_database_queries() {
        global $wpdb;
        
        $initial_queries = $wpdb->num_queries;
        
        // Process payment
        $order = wc_create_order();
        $gateway = new WC_Jio_Pay_Gateway();
        $gateway->process_payment($order->get_id());
        
        $final_queries = $wpdb->num_queries;
        $query_count = $final_queries - $initial_queries;
        
        // Should not exceed reasonable number of queries
        $this->assertLessThan(10, $query_count);
    }
}
?>
```

## 📊 Test Reporting

### Coverage Reports

#### Generate Coverage Report
```bash
# PHP coverage
./vendor/bin/phpunit --coverage-html coverage/
./vendor/bin/phpunit --coverage-clover coverage.xml

# JavaScript coverage
npm run test:coverage

# Combined report
npm run report:combined
```

### Continuous Integration

#### GitHub Actions Workflow
```yaml
# .github/workflows/test.yml
name: Test Suite

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      mysql:
        image: mysql:5.7
        env:
          MYSQL_ROOT_PASSWORD: password
          MYSQL_DATABASE: wordpress_test
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.0'
        extensions: mbstring, intl, mysqli, zip, gd
        coverage: xdebug
    
    - name: Install dependencies
      run: composer install --no-progress --prefer-dist --optimize-autoloader
    
    - name: Setup WordPress
      run: |
        bash bin/install-wp-tests.sh wordpress_test root password localhost latest
    
    - name: Run tests
      run: |
        ./vendor/bin/phpunit --coverage-clover=coverage.xml
    
    - name: Upload coverage
      uses: codecov/codecov-action@v3
      with:
        file: ./coverage.xml
```

### Test Documentation

#### Test Results Summary
```bash
#!/bin/bash
# generate-test-report.sh

echo "# Test Results Summary" > test-report.md
echo "Generated: $(date)" >> test-report.md
echo "" >> test-report.md

# Unit test results
echo "## Unit Tests" >> test-report.md
./vendor/bin/phpunit --log-junit junit.xml
echo "Results: $(grep -c 'testcase' junit.xml) tests run" >> test-report.md

# E2E test results
echo "## E2E Tests" >> test-report.md
npm run test:e2e -- --reporter=json > e2e-results.json
echo "Results: $(jq '.numTotalTests' e2e-results.json) tests run" >> test-report.md

# Security scan results
echo "## Security Scan" >> test-report.md
echo "Last scan: $(date)" >> test-report.md

# Performance test results
echo "## Performance Tests" >> test-report.md
echo "Average response time: $(cat performance-report.json | jq '.aggregate.latency.mean')ms" >> test-report.md

echo "Test report generated: test-report.md"
```

---

**Document Version**: 1.0.0  
**Last Updated**: November 2025  
**Testing Contact**: [qa@example.com]