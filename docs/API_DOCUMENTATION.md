# API Documentation - Jio Pay Gateway

## 📋 Overview

This document provides comprehensive API documentation for the Jio Pay Gateway WordPress plugin, including all endpoints, data formats, and integration methods.

## 🔗 Gateway Configuration API

### Gateway Settings

#### Get Gateway Settings
```php
// WordPress way
$settings = get_option('woocommerce_jio_pay_settings');

// WooCommerce way
$gateway = new WC_Jio_Pay_Gateway();
$settings = $gateway->get_option_key();
```

#### Gateway Configuration Options
```php
array(
    'enabled' => 'yes|no',              // Enable/disable gateway
    'title' => 'Jio Pay Gateway',       // Display title
    'description' => 'Pay with Jio Pay', // Description text
    'merchant_id' => 'JIO123456',       // Merchant ID from Jio Pay
    'api_key' => 'api_key_string',      // API authentication key
    'testmode' => 'yes|no',             // Test/Live mode
    'debug' => 'yes|no'                 // Debug logging
)
```

## 🌐 AJAX Endpoints

### 1. Create Payment Session

**Endpoint**: `wp_ajax_jio_pay_create_session`

**Method**: POST

**Parameters**:
```javascript
{
    action: 'jio_pay_create_session',
    nonce: 'wp_nonce_value',
    order_id: 123
}
```

**Response**:
```javascript
// Success
{
    success: true,
    data: {
        session_id: 'session_12345',
        amount: 750.00,
        merchant_id: 'JIO123456',
        order_id: 123
    }
}

// Error
{
    success: false,
    data: {
        message: 'Order not found',
        code: 'ORDER_NOT_FOUND'
    }
}
```

### 2. Verify Payment

**Endpoint**: `wp_ajax_jio_pay_verify_payment`

**Method**: POST

**Parameters**:
```javascript
{
    action: 'jio_pay_verify_payment',
    nonce: 'wp_nonce_value',
    order_id: 123,
    payment_data: {
        txnAuthID: '74986908063',
        txnResponseCode: '0000',
        txnRespDescription: 'Transaction successful',
        secureHash: 'hash_value',
        amount: '75000.00',
        txnDateTime: '20251103161256',
        merchantTrId: '3507027521'
    }
}
```

**Response**:
```javascript
// Success
{
    success: true,
    data: {
        message: 'Payment verified successfully',
        redirect: 'https://example.com/checkout/order-received/123/',
        order_id: 123,
        transaction_id: '74986908063'
    }
}

// Error
{
    success: false,
    data: {
        message: 'Payment verification failed: Amount mismatch',
        code: 'AMOUNT_MISMATCH',
        debug: {
            order_amount: 750.00,
            payment_amount: 75000.00,
            expected_format: 'paisa'
        }
    }
}
```

### 3. Test Endpoint (Development)

**Endpoint**: `wp_ajax_jio_pay_test`

**Method**: POST

**Parameters**:
```javascript
{
    action: 'jio_pay_test',
    nonce: 'wp_nonce_value'
}
```

**Response**:
```javascript
{
    success: true,
    data: {
        message: 'Test endpoint working',
        timestamp: '2025-11-03 16:12:56',
        test_mode: true
    }
}
```

## 🛒 WooCommerce Integration API

### Order Methods

#### Process Payment
```php
public function process_payment($order_id)
{
    // Return payment processing result
    return array(
        'result' => 'success',
        'redirect' => $this->get_return_url($order)
    );
}
```

#### Payment Fields
```php
public function payment_fields()
{
    // Display payment form fields
    echo '<div class="jio-pay-payment-fields">';
    echo '<p>You will be redirected to Jio Pay to complete your payment.</p>';
    echo '</div>';
}
```

#### Webhook Handler
```php
public function webhook_handler()
{
    // Process incoming webhooks from Jio Pay
    $payload = file_get_contents('php://input');
    $data = json_decode($payload, true);
    
    // Verify webhook authenticity
    if ($this->verify_webhook($data)) {
        $this->process_webhook_data($data);
    }
}
```

## 🎯 JavaScript API

### Core Functions

#### Initialize Payment
```javascript
function initializeJioPayment(orderData) {
    return new Promise((resolve, reject) => {
        // Initialize Jio Pay SDK
        if (typeof JioPaySDK !== 'undefined') {
            const config = {
                merchantId: orderData.merchant_id,
                amount: orderData.amount,
                orderId: orderData.order_id,
                onSuccess: resolve,
                onError: reject
            };
            
            JioPaySDK.initialize(config);
        } else {
            reject(new Error('Jio Pay SDK not loaded'));
        }
    });
}
```

#### Verify Payment
```javascript
function verifyPayment(orderID, paymentData) {
    return jQuery.ajax({
        url: jio_pay_ajax.ajax_url,
        type: 'POST',
        data: {
            action: 'jio_pay_verify_payment',
            nonce: jio_pay_ajax.nonce,
            order_id: orderID,
            payment_data: paymentData
        },
        dataType: 'json'
    });
}
```

#### Show Notification
```javascript
function showPaymentNotification(message, type = 'info', duration = 5000) {
    // Remove existing notifications
    jQuery('.jio-pay-notification').remove();
    
    // Create notification element
    const notification = jQuery(`
        <div class="jio-pay-notification jio-pay-${type}">
            <span class="notification-message">${message}</span>
            <button class="notification-close">&times;</button>
        </div>
    `);
    
    // Insert after place order button
    jQuery('#place_order').after(notification);
    
    // Auto-hide after duration
    setTimeout(() => {
        notification.fadeOut(300, () => notification.remove());
    }, duration);
}
```

## 🔐 Security API

### Nonce Verification
```php
public function verify_nonce($nonce, $action = 'jio_pay_nonce') {
    if (!wp_verify_nonce($nonce, $action)) {
        wp_die('Security check failed', 'Unauthorized', array(
            'response' => 403
        ));
    }
    return true;
}
```

### Data Sanitization
```php
public function sanitize_payment_data($data) {
    return array(
        'txnAuthID' => sanitize_text_field($data['txnAuthID'] ?? ''),
        'txnResponseCode' => sanitize_text_field($data['txnResponseCode'] ?? ''),
        'txnRespDescription' => sanitize_text_field($data['txnRespDescription'] ?? ''),
        'secureHash' => sanitize_text_field($data['secureHash'] ?? ''),
        'amount' => sanitize_text_field($data['amount'] ?? ''),
        'txnDateTime' => sanitize_text_field($data['txnDateTime'] ?? ''),
        'merchantTrId' => sanitize_text_field($data['merchantTrId'] ?? '')
    );
}
```

### Hash Verification
```php
public function verify_secure_hash($data, $merchant_key) {
    $expected_hash = $this->generate_secure_hash($data, $merchant_key);
    return hash_equals($expected_hash, $data['secureHash']);
}

private function generate_secure_hash($data, $merchant_key) {
    $hash_string = $data['txnAuthID'] . '|' . 
                   $data['amount'] . '|' . 
                   $data['txnDateTime'] . '|' . 
                   $merchant_key;
    
    return hash('sha256', $hash_string);
}
```

## 📊 Data Models

### Order Data Structure
```php
class JioPayOrderData {
    public $order_id;           // WooCommerce order ID
    public $merchant_id;        // Jio Pay merchant ID  
    public $amount;             // Order total amount
    public $currency;           // Currency code (INR)
    public $customer_email;     // Customer email
    public $customer_phone;     // Customer phone number
    public $return_url;         // Success return URL
    public $cancel_url;         // Cancel return URL
    public $webhook_url;        // Webhook notification URL
}
```

### Payment Response Structure
```php
class JioPayResponse {
    public $txnAuthID;          // Transaction authorization ID
    public $txnResponseCode;    // Response code (0000 = success)
    public $txnRespDescription; // Response description
    public $secureHash;         // Security hash
    public $amount;             // Transaction amount
    public $txnDateTime;        // Transaction timestamp
    public $merchantTrId;       // Merchant transaction ID
}
```

## 🔄 State Management

### Order Status Flow
```php
// Order status transitions
'pending'    -> 'processing'  // Payment initiated
'processing' -> 'completed'   // Payment successful
'processing' -> 'failed'      // Payment failed
'processing' -> 'cancelled'   // Payment cancelled
```

### Payment Status Mapping
```php
const RESPONSE_CODES = array(
    '0000' => 'success',       // Transaction successful
    '0001' => 'failed',        // Transaction failed
    '0002' => 'pending',       // Transaction pending
    '0003' => 'cancelled',     // Transaction cancelled
    '0004' => 'timeout',       // Transaction timeout
    '9999' => 'error'          // System error
);
```

## 📝 Event Hooks

### WordPress Actions
```php
// Plugin activation
do_action('jio_pay_gateway_activated');

// Payment processed
do_action('jio_pay_payment_processed', $order_id, $payment_data);

// Payment verified
do_action('jio_pay_payment_verified', $order_id, $transaction_id);

// Payment failed
do_action('jio_pay_payment_failed', $order_id, $error_message);
```

### WordPress Filters
```php
// Modify payment data before processing
$payment_data = apply_filters('jio_pay_payment_data', $payment_data, $order_id);

// Customize return URL
$return_url = apply_filters('jio_pay_return_url', $return_url, $order_id);

// Modify verification logic
$is_verified = apply_filters('jio_pay_verify_payment', $is_verified, $payment_data);
```

## ⚠️ Error Codes

### Common Error Codes
```php
const ERROR_CODES = array(
    'INVALID_NONCE'        => 'Security verification failed',
    'ORDER_NOT_FOUND'      => 'Order not found',
    'INVALID_AMOUNT'       => 'Invalid payment amount',
    'AMOUNT_MISMATCH'      => 'Payment amount does not match order total',
    'HASH_VERIFICATION'    => 'Payment hash verification failed',
    'SDK_NOT_LOADED'       => 'Jio Pay SDK not loaded',
    'NETWORK_ERROR'        => 'Network communication error',
    'TIMEOUT_ERROR'        => 'Payment timeout',
    'USER_CANCELLED'       => 'Payment cancelled by user',
    'INSUFFICIENT_FUNDS'   => 'Insufficient funds',
    'CARD_DECLINED'        => 'Card declined by bank'
);
```

### Error Response Format
```javascript
{
    success: false,
    data: {
        message: 'Human-readable error message',
        code: 'ERROR_CODE',
        debug: {
            // Additional debug information (only in test mode)
            timestamp: '2025-11-03 16:12:56',
            order_id: 123,
            payment_data: {...}
        }
    }
}
```

---

**Document Version**: 1.0.0  
**Last Updated**: November 2025  
**API Version**: v1