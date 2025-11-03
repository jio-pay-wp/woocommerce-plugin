# Troubleshooting Guide - Jio Pay Gateway

## 🔍 Common Issues & Solutions

This guide helps you diagnose and resolve common issues with the Jio Pay Gateway plugin.

## 🚨 Payment Issues

### Issue: "Payment verification failed. Please contact support."

**Symptoms:**
- Payment appears successful in Jio Pay but fails in WooCommerce
- Order status remains "pending payment"
- Customer receives failure message

**Causes & Solutions:**

#### 1. Amount Format Mismatch
```php
// Check debug logs for:
[03-Nov-2025 16:12:56 UTC] JIO_PAY_DEBUG: Amount mismatch - Order: 750.00, Payment: 75000.00

// Solution: The plugin automatically handles both formats, but check your Jio Pay configuration
```

**Fix:**
1. Go to **WooCommerce → Settings → Payments → Jio Pay Gateway**
2. Enable **Debug Mode** temporarily
3. Test a payment and check debug logs
4. The plugin should auto-detect format, but verify Jio Pay returns consistent format

#### 2. Invalid Secure Hash
```php
// Debug log example:
[03-Nov-2025 16:12:56 UTC] JIO_PAY_ERROR: Hash verification failed
```

**Fix:**
1. Verify your **API Key/Secret Key** in gateway settings
2. Ensure the same key is configured in your Jio Pay merchant dashboard
3. Check for any special characters or extra spaces in the key

#### 3. Network/SSL Issues
**Fix:**
1. Ensure your site has a valid SSL certificate
2. Test with `curl` to verify HTTPS connectivity:
```bash
curl -I https://your-domain.com/wp-admin/admin-ajax.php
```

### Issue: "Order not found" Error

**Symptoms:**
- Payment process stops with "Order not found"
- AJAX requests return order not found

**Solutions:**

#### Check Order Status
```php
// Add to functions.php temporarily for debugging:
add_action('wp_ajax_debug_order', 'debug_order_status');
add_action('wp_ajax_nopriv_debug_order', 'debug_order_status');

function debug_order_status() {
    $order_id = $_POST['order_id'];
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_die('Order not found: ' . $order_id);
    }
    
    wp_die('Order found. Status: ' . $order->get_status());
}
```

#### Clear Cache
1. Clear any caching plugins
2. Clear object cache if using Redis/Memcached
3. Deactivate page caching temporarily

### Issue: Payment Popup Doesn't Open

**Symptoms:**
- Click "Place Order" but Jio Pay popup doesn't appear
- JavaScript errors in browser console

**Solutions:**

#### 1. Check JavaScript Errors
Press `F12` in browser and check Console tab for errors:

```javascript
// Common errors and fixes:

// Error: "JioPaySDK is not defined"
// Fix: Ensure Jio Pay SDK is loaded
if (typeof JioPaySDK === 'undefined') {
    console.error('Jio Pay SDK not loaded');
}

// Error: "jQuery is not defined"
// Fix: Check for jQuery conflicts
jQuery(document).ready(function($) {
    // Your code here
});
```

#### 2. Script Loading Issues
Check if scripts are loaded properly:
```html
<!-- View page source and verify these are present -->
<script src="/wp-content/plugins/jio-pay-gateway/assets/jio-pay-integration.js"></script>
<script>
var jio_pay_ajax = {
    "ajax_url": "http://localhost:8080/takneekiinc/wp/wp-admin/admin-ajax.php",
    "nonce": "abc123..."
};
</script>
```

## 🔧 Configuration Issues

### Issue: Gateway Not Appearing at Checkout

**Symptoms:**
- Jio Pay option missing from payment methods
- Other payment methods work fine

**Solutions:**

#### 1. Check Gateway Status
1. Go to **WooCommerce → Settings → Payments**
2. Ensure **Jio Pay Gateway** is **Enabled**
3. Check if gateway is available for your currency (should support INR)

#### 2. Verify Requirements
```php
// Check WooCommerce version
if (version_compare(WC_VERSION, '3.0', '<')) {
    // Update WooCommerce
}

// Check currency
if (get_woocommerce_currency() !== 'INR') {
    // Change currency to INR in WooCommerce settings
}
```

### Issue: Test Mode Not Working

**Symptoms:**
- Test payments being processed as real payments
- Unable to test without actual charges

**Solutions:**

#### Enable Test Mode Properly
1. **WooCommerce → Settings → Payments → Jio Pay Gateway**
2. Check **Enable Test Mode**
3. Use test credentials from Jio Pay
4. Verify test data is being used:

```php
// Check if test mode is active
if ($this->testmode === 'yes') {
    // Should see test data in checkout
    $test_amount = 750.00;
    $test_email = 'test@example.com';
}
```

## ⚠️ AJAX & API Issues

### Issue: AJAX Requests Returning "0"

**Symptoms:**
- Payment verification returns "0" instead of proper response
- AJAX endpoints not responding

**Solutions:**

#### 1. Check AJAX Handler Registration
```php
// Verify in jio-pay-gateway.php:
add_action('wp_ajax_jio_pay_verify_payment', array($gateway, 'verify_payment'));
add_action('wp_ajax_nopriv_jio_pay_verify_payment', array($gateway, 'verify_payment'));

// Ensure gateway class is instantiated
$gateway = new WC_Jio_Pay_Gateway();
```

#### 2. Test AJAX Endpoint
```bash
# Test the endpoint directly:
curl -X POST "http://localhost:8080/takneekiinc/wp/wp-admin/admin-ajax.php" \
  -d "action=jio_pay_test&nonce=your_nonce" \
  -H "Content-Type: application/x-www-form-urlencoded"

# Should return JSON response, not "0"
```

#### 3. Check WordPress Debug
Enable WordPress debugging:
```php
// In wp-config.php:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Check /wp-content/debug.log for errors
```

### Issue: Nonce Verification Failed

**Symptoms:**
- "Security check failed" errors
- AJAX requests rejected

**Solutions:**

#### 1. Regenerate Nonces
Clear cache and refresh the checkout page. WordPress nonces expire after 24 hours.

#### 2. Check Nonce Implementation
```javascript
// Verify nonce is being sent correctly:
jQuery.ajax({
    url: jio_pay_ajax.ajax_url,
    data: {
        action: 'jio_pay_verify_payment',
        nonce: jio_pay_ajax.nonce,  // Ensure this exists
        // ... other data
    }
});
```

## 🗄️ Database Issues

### Issue: Orders Not Updating

**Symptoms:**
- Payment successful but order status doesn't change
- Order remains in "pending payment"

**Solutions:**

#### 1. Check Database Permissions
```sql
-- Verify WordPress can update orders
SELECT post_status FROM wp_posts WHERE ID = [order_id];

-- Should be able to update:
UPDATE wp_posts SET post_status = 'wc-processing' WHERE ID = [order_id];
```

#### 2. Check Order Update Code
```php
// Verify this is working in your verification function:
$order = wc_get_order($order_id);
$order->payment_complete($transaction_id);
$order->add_order_note('Payment completed via Jio Pay');
$order->save();
```

### Issue: Transaction ID Not Saved

**Symptoms:**
- Payment works but no transaction ID in order
- Unable to track payments

**Solutions:**

```php
// Ensure transaction ID is saved:
$order->set_transaction_id($payment_data['txnAuthID']);
$order->add_meta_data('_jio_pay_transaction_id', $payment_data['txnAuthID']);
$order->save();
```

## 🔐 Security Issues

### Issue: SSL Certificate Errors

**Symptoms:**
- "SSL certificate verification failed"
- Payment requests failing

**Solutions:**

#### 1. Verify SSL Certificate
```bash
# Check SSL certificate
openssl s_client -connect yourdomain.com:443 -servername yourdomain.com

# Should show:
# Verify return code: 0 (ok)
```

#### 2. Force SSL for Admin
```php
// In wp-config.php:
define('FORCE_SSL_ADMIN', true);
```

### Issue: Hash Verification Failing

**Symptoms:**
- "Hash verification failed" in logs
- Payments being rejected as fraudulent

**Solutions:**

#### 1. Check Hash Generation
```php
// Debug hash generation:
$expected_hash = hash('sha256', $merchant_id . '|' . $amount . '|' . $timestamp . '|' . $secret_key);
error_log('Expected: ' . $expected_hash);
error_log('Received: ' . $payment_data['secureHash']);
```

#### 2. Verify Secret Key
1. Ensure same secret key in both Jio Pay dashboard and plugin settings
2. Check for hidden characters or encoding issues

## 📱 Mobile Issues

### Issue: Payment Popup Not Working on Mobile

**Symptoms:**
- Popup doesn't open on mobile devices
- Mobile users can't complete payments

**Solutions:**

#### 1. Mobile-Specific JavaScript
```javascript
// Add mobile detection and handling:
function isMobileDevice() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
}

if (isMobileDevice()) {
    // Use mobile-optimized popup settings
    jioPayConfig.mobile = true;
}
```

#### 2. Viewport Configuration
```html
<!-- Ensure proper viewport meta tag -->
<meta name="viewport" content="width=device-width, initial-scale=1.0">
```

## 🔍 Debugging Tools

### Enable Debug Logging

#### 1. WordPress Debug Log
```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Check logs at: /wp-content/debug.log
```

#### 2. Plugin-Specific Debugging
```php
// Enable in gateway settings or add to functions.php:
add_filter('jio_pay_debug_mode', '__return_true');

// Custom debug function:
function jio_pay_debug($message, $data = null) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('JIO_PAY_DEBUG: ' . $message);
        if ($data) {
            error_log('JIO_PAY_DATA: ' . print_r($data, true));
        }
    }
}
```

### Browser Developer Tools

#### Console Commands for Testing
```javascript
// Test AJAX endpoint:
jQuery.post(ajaxurl, {
    action: 'jio_pay_test',
    nonce: jio_pay_ajax.nonce
}, function(response) {
    console.log('Response:', response);
});

// Check if scripts are loaded:
console.log('jQuery version:', jQuery.fn.jquery);
console.log('Jio Pay SDK:', typeof JioPaySDK);
console.log('AJAX config:', jio_pay_ajax);
```

### Database Queries for Debugging

```sql
-- Check recent orders
SELECT ID, post_status, post_date 
FROM wp_posts 
WHERE post_type = 'shop_order' 
ORDER BY post_date DESC 
LIMIT 10;

-- Check order metadata
SELECT meta_key, meta_value 
FROM wp_postmeta 
WHERE post_id = [order_id] 
AND meta_key LIKE '%payment%';

-- Check failed payments
SELECT * FROM wp_posts 
WHERE post_type = 'shop_order' 
AND post_status = 'wc-failed' 
ORDER BY post_date DESC;
```

## 📞 Getting Help

### Before Contacting Support

1. **Enable Debug Mode** and reproduce the issue
2. **Check Debug Logs** for specific error messages
3. **Test with Default Theme** to rule out theme conflicts
4. **Deactivate Other Plugins** to check for conflicts
5. **Verify System Requirements** (PHP, WordPress, WooCommerce versions)

### Information to Provide

When contacting support, include:

- WordPress version
- WooCommerce version  
- PHP version
- Plugin version
- Error messages from debug log
- Steps to reproduce the issue
- Browser and device information
- Whether issue occurs in test mode

### Common Log Locations

```bash
# WordPress debug log
/wp-content/debug.log

# Server error logs (Apache)
/var/log/apache2/error.log

# Server error logs (Nginx)
/var/log/nginx/error.log

# PHP error log
/var/log/php_errors.log
```

---

**Document Version**: 1.0.0  
**Last Updated**: November 2025  
**Support Contact**: [support@example.com]