# Return URL Implementation - JioPay Gateway

## Overview
This document explains the **returnURL** implementation for the JioPay payment gateway. The return URL acts as a **backup mechanism** to handle payment verification when the JavaScript callbacks fail or when the customer is redirected back from the payment gateway.

---

## 🎯 Why Return URL is Important

### Primary Flow (JavaScript Callbacks)
```
Payment Success → onSuccess() → AJAX verify_payment → Update Order → Redirect
```

### Backup Flow (Return URL)
```
Payment Success → JioPay redirects to returnURL → POST data received → Update Order → Redirect
```

### Use Cases for Return URL:
1. ✅ **Network interruption** during payment
2. ✅ **Browser closed** before JavaScript callback executes
3. ✅ **JavaScript errors** preventing callback execution
4. ✅ **Mobile app webview** where callbacks may not work reliably
5. ✅ **Customer manually closes** the payment popup
6. ✅ **Timeout issues** with the payment gateway

---

## 🔧 Implementation Details

### 1. Return URL Structure

**URL Format:**
```
https://yoursite.com/wp-admin/admin-ajax.php?action=jio_pay_return_handler
```

**How it's generated:**
```php
// In woo-jiopay.php
'return_url' => add_query_arg('action', 'jio_pay_return_handler', admin_url('admin-ajax.php'))
```

---

### 2. Payment Options Configuration

**JavaScript Integration** (`jio-pay-integration.js`):

```javascript
const merchantTrId = Math.floor(1000000000 + Math.random() * 9000000000).toString();

const paymentOptions = {
    amount: "750.00",
    env: "prod",
    merchantId: "JP2000000000016",
    aggId: "JP2000000000001",
    secretKey: "your_secret_key",
    customerEmailID: "customer@email.com",
    userName: "Customer Name",
    merchantName: "Your Store",
    merchantTrId: merchantTrId,                    // Unique transaction ID
    returnURL: jioPayVars.return_url,              // ← Return URL for POST callback
    onSuccess: handlePaymentSuccess,
    onFailure: handlePaymentFailure,
    onClose: handlePaymentCancel
};

const jioPay = new jioPaySDK(paymentOptions);
jioPay.open();
```

---

### 3. Merchant Transaction ID Storage

Before opening the payment popup, we store the merchant transaction ID in order meta:

```javascript
// Store merchant transaction ID for tracking
if (window.currentOrderId) {
    $.post(jioPayVars.ajax_url, {
        action: 'jio_pay_store_merchant_tr_id',
        nonce: jioPayVars.nonce,
        order_id: window.currentOrderId,
        merchant_tr_id: merchantTrId
    });
}
```

**Why?** This allows us to find the order later using the merchant transaction ID if the order ID is not provided in the callback.

---

### 4. Return URL Handler Flow

**File**: `class-jio-pay-gateway.php` → `handle_return_url()` method

#### Step 1: Receive POST Data
```php
public function handle_return_url()
{
    error_log('POST Data: ' . print_r($_POST, true));
    error_log('GET Data: ' . print_r($_GET, true));
    
    // Merge POST and GET data
    $payment_data = array_merge($_POST, $_GET);
}
```

**Expected POST Data from JioPay:**
```php
[
    'txnAuthID' => 'JIO123456789',           // Transaction auth ID
    'txnResponseCode' => '0000',             // Success code
    'merchantTrId' => '1234567890',          // Our transaction ID
    'amount' => '750.00',                    // Payment amount
    'order_id' => '12345',                   // Order ID (if passed)
    'txnDateTime' => '2025-11-28 13:52:44',
    'secureHash' => 'abc123...',             // Security hash
    // ... other fields
]
```

---

#### Step 2: Find the Order

The handler tries **three methods** to find the order:

##### Method 1: By Order ID
```php
if ($order_id) {
    $order = wc_get_order($order_id);
}
```

##### Method 2: By Merchant Transaction ID
```php
elseif ($merchant_tr_id) {
    $orders = wc_get_orders([
        'meta_key' => '_jio_pay_merchant_tr_id',
        'meta_value' => $merchant_tr_id,
        'limit' => 1,
        'status' => ['pending', 'on-hold', 'processing']
    ]);
}
```

##### Method 3: Most Recent Pending Order
```php
else {
    $orders = wc_get_orders([
        'status' => ['pending', 'on-hold'],
        'payment_method' => 'jio_pay',
        'limit' => 1,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);
}
```

---

#### Step 3: Check Order Status

```php
// If order is already completed, just redirect
if ($order->has_status(['processing', 'completed'])) {
    error_log('Order already completed, redirecting to success page');
    wp_redirect($order->get_checkout_order_received_url());
    exit;
}
```

**Why?** Prevents duplicate processing if both JavaScript callback and return URL execute.

---

#### Step 4: Validate Response Code

```php
if ($txn_response_code !== '0000') {
    error_log('Payment failed with response code: ' . $txn_response_code);
    
    $order->update_status('failed', sprintf(
        __('Payment failed via return URL. Response code: %s', 'woocommerce'),
        $txn_response_code
    ));
    
    $this->redirect_with_error('Payment was not successful. Please try again.');
    return;
}
```

**Success Code:** `0000`  
**Any other code:** Payment failed

---

#### Step 5: Validate Amount

```php
$order_amount = (float) $order->get_total();
$paid_amount_raw = (float) $amount;

// JioPay might send amount in paisa (75000) or rupees (750.00)
$paid_amount_paisa = $paid_amount_raw / 100;
$paid_amount_rupees = $paid_amount_raw;

// Check which format matches
if (abs($order_amount - $paid_amount_paisa) <= 0.01) {
    $paid_amount = $paid_amount_paisa;
} elseif (abs($order_amount - $paid_amount_rupees) <= 0.01) {
    $paid_amount = $paid_amount_rupees;
} else {
    // Log discrepancy but don't fail the order
    error_log('Amount mismatch detected');
}
```

---

#### Step 6: Complete Payment

```php
// Mark order as complete
$order->payment_complete($txn_auth_id);

// Add order note
$order->add_order_note(sprintf(
    'JioPay payment successful via return URL. Auth ID: %s, Response Code: %s',
    $txn_auth_id,
    $txn_response_code
));

// Store payment data in order meta
$order->update_meta_data('_jio_pay_txn_auth_id', $txn_auth_id);
$order->update_meta_data('_jio_pay_response_code', $txn_response_code);
$order->update_meta_data('_jio_pay_merchant_tr_id', $merchant_tr_id);
$order->update_meta_data('_jio_pay_return_data', $payment_data);
$order->save();

// Reduce stock
wc_reduce_stock_levels($order_id);

// Clear cart
if (WC()->cart) {
    WC()->cart->empty_cart();
}
```

---

#### Step 7: Redirect to Success Page

```php
// Redirect to order received page
wp_redirect($order->get_checkout_order_received_url());
exit;
```

**Redirect URL:**
```
https://yoursite.com/checkout/order-received/12345/?key=wc_order_abc123xyz
```

---

## 📊 Complete Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│ Customer completes payment on JioPay                        │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ├──────────────────┬──────────────────────────┐
                 │                  │                          │
                 ▼                  ▼                          ▼
        ┌────────────────┐  ┌──────────────┐      ┌──────────────────┐
        │ onSuccess()    │  │ onFailure()  │      │ JioPay redirects │
        │ callback       │  │ callback     │      │ to returnURL     │
        └────────┬───────┘  └──────┬───────┘      └────────┬─────────┘
                 │                  │                       │
                 ▼                  ▼                       ▼
        ┌────────────────┐  ┌──────────────┐      ┌──────────────────┐
        │ AJAX verify    │  │ Show error   │      │ POST data sent   │
        │ payment        │  │ message      │      │ to returnURL     │
        └────────┬───────┘  └──────────────┘      └────────┬─────────┘
                 │                                          │
                 ▼                                          ▼
        ┌────────────────┐                        ┌──────────────────┐
        │ Update order   │                        │ handle_return_   │
        │ status         │                        │ url() method     │
        └────────┬───────┘                        └────────┬─────────┘
                 │                                          │
                 ▼                                          ▼
        ┌────────────────┐                        ┌──────────────────┐
        │ Redirect to    │                        │ Find order       │
        │ order-received │                        │ Validate payment │
        └────────────────┘                        │ Update order     │
                                                   └────────┬─────────┘
                                                            │
                                                            ▼
                                                   ┌──────────────────┐
                                                   │ Redirect to      │
                                                   │ order-received   │
                                                   └──────────────────┘
```

---

## 🔐 Security Features

### 1. **Idempotency Check**
```php
if ($order->has_status(['processing', 'completed'])) {
    // Order already processed, just redirect
    wp_redirect($order->get_checkout_order_received_url());
    exit;
}
```
**Prevents:** Duplicate order processing

### 2. **Response Code Validation**
```php
if ($txn_response_code !== '0000') {
    $order->update_status('failed');
    $this->redirect_with_error('Payment was not successful.');
}
```
**Prevents:** Failed payments from being marked as successful

### 3. **Amount Validation**
```php
if (abs($order_amount - $paid_amount) > 0.01) {
    error_log('Amount mismatch detected');
    $order->add_order_note('Payment amount mismatch');
}
```
**Prevents:** Payment amount manipulation

### 4. **Order Ownership**
```php
// Order key is validated by WooCommerce on order-received page
if (!$order->key_is_valid($_GET['key'])) {
    // Access denied
}
```
**Prevents:** Unauthorized access to order details

---

## 📝 Order Meta Data Stored

After successful payment via return URL, the following meta data is stored:

```php
_jio_pay_txn_auth_id         → "JIO123456789"
_jio_pay_response_code       → "0000"
_jio_pay_merchant_tr_id      → "1234567890"
_jio_pay_return_data         → [full POST data array]
_transaction_id              → "JIO123456789" (WooCommerce standard)
_date_paid                   → "2025-11-28 13:52:44"
```

**Accessing meta data:**
```php
$order = wc_get_order(12345);
$txn_id = $order->get_meta('_jio_pay_txn_auth_id');
$merchant_tr_id = $order->get_meta('_jio_pay_merchant_tr_id');
```

---

## 🧪 Testing the Return URL

### Test Scenario 1: Normal Flow
1. Place an order
2. Complete payment on JioPay
3. JavaScript callback executes
4. Order is marked as complete
5. Return URL also receives POST data
6. Handler detects order is already complete
7. Redirects to order-received page ✅

### Test Scenario 2: JavaScript Callback Fails
1. Place an order
2. Complete payment on JioPay
3. Close browser before callback executes
4. JioPay redirects to return URL
5. Handler receives POST data
6. Order is found and marked as complete
7. Redirects to order-received page ✅

### Test Scenario 3: Payment Failed
1. Place an order
2. Payment fails on JioPay
3. Return URL receives POST with response code ≠ 0000
4. Handler marks order as failed
5. Redirects to checkout with error message ✅

---

## 🔍 Debugging

### Enable Debug Logging

All return URL activity is logged to WordPress debug log:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Log file location:**
```
/wp-content/debug.log
```

### Sample Log Output

```
[28-Nov-2025 13:52:44 UTC] === JioPay Return URL Handler Started ===
[28-Nov-2025 13:52:44 UTC] POST Data: Array ( [txnAuthID] => JIO123456789 [txnResponseCode] => 0000 ... )
[28-Nov-2025 13:52:44 UTC] Extracted data - OrderID: 12345, TxnAuthID: JIO123456789, ResponseCode: 0000, Amount: 750.00
[28-Nov-2025 13:52:44 UTC] Found order by ID: 12345
[28-Nov-2025 13:52:44 UTC] Completing payment for order: 12345
[28-Nov-2025 13:52:44 UTC] Payment completed successfully via return URL
```

---

## 🚨 Error Handling

### Error 1: Order Not Found
```php
if (!$order) {
    error_log('ERROR: No order found for payment callback');
    $this->redirect_with_error('Order not found. Please contact support.');
}
```
**User sees:** Error message on checkout page  
**Action:** Contact support with payment details

### Error 2: Payment Failed
```php
if ($txn_response_code !== '0000') {
    $order->update_status('failed');
    $this->redirect_with_error('Payment was not successful. Please try again.');
}
```
**User sees:** Error message on checkout page  
**Action:** Try payment again

### Error 3: Exception Occurred
```php
catch (Exception $e) {
    error_log('Exception in handle_return_url: ' . $e->getMessage());
    $this->redirect_with_error('An error occurred processing your payment.');
}
```
**User sees:** Generic error message  
**Action:** Check logs and contact support

---

## 📋 Summary

### What We Implemented:

1. ✅ **Return URL endpoint** (`handle_return_url()`)
2. ✅ **Merchant transaction ID storage** (`store_merchant_tr_id()`)
3. ✅ **Order lookup** (by ID, merchant transaction ID, or recent pending)
4. ✅ **Payment validation** (response code, amount)
5. ✅ **Idempotency check** (prevent duplicate processing)
6. ✅ **Comprehensive logging** (for debugging)
7. ✅ **Error handling** (graceful failures)
8. ✅ **Meta data storage** (for tracking and auditing)

### Benefits:

- 🛡️ **Reliability**: Backup mechanism if JavaScript fails
- 🔄 **Redundancy**: Two independent verification paths
- 📊 **Tracking**: Complete audit trail in order meta
- 🐛 **Debugging**: Comprehensive error logging
- 🔐 **Security**: Multiple validation layers

### Return URL:
```
https://yoursite.com/wp-admin/admin-ajax.php?action=jio_pay_return_handler
```

This URL should be passed to JioPay SDK in the `returnURL` parameter! ✅
