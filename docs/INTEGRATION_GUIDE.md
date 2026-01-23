# Integration Guide - Jio Pay Gateway

## 🚀 Quick Start

This guide will help you integrate the Jio Pay Gateway plugin into your WordPress/WooCommerce store in just a few steps.

## 📋 Prerequisites

### System Requirements
- **WordPress**: 5.0 or higher
- **WooCommerce**: 3.0 or higher  
- **PHP**: 7.4 or higher (8.0+ recommended)
- **SSL Certificate**: Required for production
- **Jio Pay Account**: Active merchant account with API credentials

### Required Information
Before starting integration, gather these details from your Jio Pay account:
- Merchant ID
- API Key/Secret Key
- Webhook URL (will be provided during setup)
- Test/Production credentials

## 🔧 Installation Steps

### Step 1: Plugin Installation

#### Method A: WordPress Admin (Recommended)
1. Download the plugin ZIP file
2. Navigate to **WordPress Admin → Plugins → Add New**
3. Click **Upload Plugin**
4. Select the ZIP file and click **Install Now**
5. Click **Activate Plugin**

#### Method B: FTP Upload
1. Extract the plugin ZIP file
2. Upload the `woo-jiopay` folder to `/wp-content/plugins/`
3. Navigate to **WordPress Admin → Plugins**
4. Find "Jio Pay Gateway" and click **Activate**

#### Method C: Command Line
```bash
# Navigate to plugins directory
cd /path/to/wordpress/wp-content/plugins/

# Download and extract plugin
wget https://your-domain.com/woo-jiopay.zip
unzip woo-jiopay.zip

# Set proper permissions
chmod -R 755 woo-jiopay/
```

### Step 2: WooCommerce Configuration

1. Navigate to **WooCommerce → Settings → Payments**
2. Find **Jio Pay Gateway** in the payment methods list
3. Click **Set up** or **Manage**

### Step 3: Gateway Configuration

#### Basic Settings
```
✅ Enable Jio Pay Gateway: Yes
📝 Title: Jio Pay Gateway
📝 Description: Pay securely with Jio Pay
🔑 Merchant ID: [Your Jio Pay Merchant ID]
🔑 API Key: [Your Jio Pay API Key]
🧪 Test Mode: Yes (for testing) / No (for production)
🐛 Debug Mode: Yes (for development) / No (for production)
```

#### Advanced Settings
```
🔗 Webhook URL: https://yoursite.com/wp-admin/admin-ajax.php?action=jio_pay_webhook
⏰ Payment Timeout: 300 seconds
💰 Minimum Amount: 1.00 INR
💰 Maximum Amount: 100000.00 INR
🎨 Payment Button Text: "Pay with Jio Pay"
```

## 🛠️ Development Integration

### Frontend Integration

#### Basic Checkout Integration
The plugin automatically integrates with WooCommerce checkout. No additional code required for basic functionality.

#### Custom Payment Button (Optional)
```html
<!-- Custom payment form -->
<form id="custom-jio-pay-form">
    <input type="hidden" id="order-id" value="123">
    <button type="button" id="jio-pay-button">Pay with Jio Pay</button>
</form>

<script>
jQuery('#jio-pay-button').on('click', function() {
    const orderId = jQuery('#order-id').val();
    
    // Initialize payment
    initializeJioPayment({
        order_id: orderId,
        merchant_id: jio_pay_ajax.merchant_id,
        amount: jio_pay_ajax.amount
    }).then(function(result) {
        // Payment successful
        verifyPayment(orderId, result).then(function(response) {
            if (response.success) {
                window.location.href = response.data.redirect;
            }
        });
    }).catch(function(error) {
        showPaymentNotification(error.message, 'error');
    });
});
</script>
```

### Backend Integration

#### Custom Order Processing
```php
// Create custom order for Jio Pay
function create_jio_pay_order($customer_data, $items) {
    // Create WooCommerce order
    $order = wc_create_order();
    
    // Add customer information
    $order->set_billing_email($customer_data['email']);
    $order->set_billing_phone($customer_data['phone']);
    
    // Add items to order
    foreach ($items as $item) {
        $order->add_product(
            wc_get_product($item['product_id']), 
            $item['quantity']
        );
    }
    
    // Set payment method
    $order->set_payment_method('jio_pay');
    $order->set_payment_method_title('Jio Pay Gateway');
    
    // Calculate totals
    $order->calculate_totals();
    
    // Save order
    $order->save();
    
    return $order->get_id();
}
```

#### Custom Payment Verification
```php
// Custom payment verification hook
add_action('jio_pay_payment_verified', 'custom_payment_verified', 10, 2);

function custom_payment_verified($order_id, $transaction_id) {
    $order = wc_get_order($order_id);
    
    // Add custom order notes
    $order->add_order_note(
        sprintf('Payment verified via Jio Pay. Transaction ID: %s', $transaction_id)
    );
    
    // Send custom email notification
    wp_mail(
        get_option('admin_email'),
        'Payment Received',
        sprintf('Order #%d payment received via Jio Pay', $order_id)
    );
    
    // Update custom database table
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'custom_payments',
        array(
            'order_id' => $order_id,
            'transaction_id' => $transaction_id,
            'payment_method' => 'jio_pay',
            'created_at' => current_time('mysql')
        )
    );
}
```

## 🎨 Customization Options

### Styling the Payment Form

#### Custom CSS
```css
/* Payment method styling */
.payment_method_jio_pay {
    border: 2px solid #007cba;
    border-radius: 8px;
    padding: 15px;
    margin: 10px 0;
}

.payment_method_jio_pay label {
    font-weight: bold;
    color: #007cba;
}

/* Payment button styling */
#place_order {
    background: linear-gradient(45deg, #007cba, #00a0d2);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
}

#place_order:hover {
    background: linear-gradient(45deg, #005a87, #007cba);
    transform: translateY(-2px);
}

/* Notification styling */
.jio-pay-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    padding: 15px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideIn 0.3s ease-out;
}

.jio-pay-success {
    background: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
}

.jio-pay-error {
    background: #f8d7da;
    color: #721c24;
    border-left: 4px solid #dc3545;
}
```

#### JavaScript Customization
```javascript
// Custom payment flow with loading states
function customPaymentFlow(orderData) {
    // Show loading state
    jQuery('#place_order').prop('disabled', true).text('Processing...');
    
    // Add loading spinner
    jQuery('#place_order').after('<div class="jio-pay-spinner"></div>');
    
    return initializeJioPayment(orderData)
        .then(function(result) {
            // Remove loading state
            jQuery('.jio-pay-spinner').remove();
            jQuery('#place_order').prop('disabled', false).text('Place Order');
            
            // Show success message
            showPaymentNotification('Payment completed successfully!', 'success');
            
            return verifyPayment(orderData.order_id, result);
        })
        .catch(function(error) {
            // Remove loading state
            jQuery('.jio-pay-spinner').remove();
            jQuery('#place_order').prop('disabled', false).text('Place Order');
            
            // Show error message
            showPaymentNotification('Payment failed: ' + error.message, 'error');
            
            throw error;
        });
}
```

## 🔌 Third-Party Integrations

### Google Analytics Integration
```javascript
// Track payment events in Google Analytics
function trackJioPayEvent(action, order_id, amount) {
    if (typeof gtag !== 'undefined') {
        gtag('event', action, {
            'event_category': 'Payment',
            'event_label': 'Jio Pay',
            'value': amount,
            'currency': 'INR',
            'transaction_id': order_id
        });
    }
}

// Usage in payment flow
jQuery(document).on('jio_pay_success', function(e, data) {
    trackJioPayEvent('purchase', data.order_id, data.amount);
});
```

### CRM Integration (Example: HubSpot)
```php
// Send payment data to CRM
add_action('jio_pay_payment_verified', 'send_to_hubspot', 10, 2);

function send_to_hubspot($order_id, $transaction_id) {
    $order = wc_get_order($order_id);
    
    $hubspot_data = array(
        'email' => $order->get_billing_email(),
        'firstname' => $order->get_billing_first_name(),
        'lastname' => $order->get_billing_last_name(),
        'phone' => $order->get_billing_phone(),
        'order_total' => $order->get_total(),
        'payment_method' => 'Jio Pay',
        'transaction_id' => $transaction_id
    );
    
    // Send to HubSpot API
    wp_remote_post('https://api.hubapi.com/contacts/v1/contact/', array(
        'headers' => array(
            'Authorization' => 'Bearer YOUR_HUBSPOT_TOKEN',
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode($hubspot_data)
    ));
}
```

## 🌍 Multi-language Support

### WPML Integration
```php
// Register strings for translation
add_action('init', 'jio_pay_register_strings');

function jio_pay_register_strings() {
    if (function_exists('icl_register_string')) {
        icl_register_string('woo-jiopay', 'Payment Button Text', 'Pay with Jio Pay');
        icl_register_string('woo-jiopay', 'Payment Description', 'Pay securely with Jio Pay');
        icl_register_string('woo-jiopay', 'Success Message', 'Payment completed successfully');
        icl_register_string('woo-jiopay', 'Error Message', 'Payment failed. Please try again.');
    }
}

// Use translated strings
function get_translated_string($string, $default) {
    if (function_exists('icl_t')) {
        return icl_t('woo-jiopay', $string, $default);
    }
    return $default;
}
```

## 📱 Mobile Optimization

### Responsive Design
```css
/* Mobile-specific styles */
@media (max-width: 768px) {
    .payment_method_jio_pay {
        margin: 5px 0;
        padding: 10px;
    }
    
    #place_order {
        width: 100%;
        padding: 15px;
        font-size: 18px;
    }
    
    .jio-pay-notification {
        position: fixed;
        top: 10px;
        left: 10px;
        right: 10px;
        width: auto;
    }
}
```

### Touch-Friendly Interface
```javascript
// Add touch events for mobile
jQuery(document).ready(function($) {
    if ('ontouchstart' in window) {
        // Mobile-specific payment handling
        $('#place_order').on('touchstart', function() {
            $(this).addClass('touched');
        });
        
        $('#place_order').on('touchend', function() {
            $(this).removeClass('touched');
        });
    }
});
```

## 🧪 Testing Your Integration

### Test Mode Setup
1. Enable **Test Mode** in gateway settings
2. Use test credentials provided by Jio Pay
3. Test with various scenarios:
   - Successful payments
   - Failed payments
   - Cancelled payments
   - Network timeouts

### Test Scenarios
```javascript
// Test data for different scenarios
const testScenarios = {
    success: {
        txnResponseCode: '0000',
        txnRespDescription: 'Transaction successful'
    },
    failure: {
        txnResponseCode: '0001',
        txnRespDescription: 'Transaction failed'
    },
    timeout: {
        txnResponseCode: '0004',
        txnRespDescription: 'Transaction timeout'
    }
};
```

## 🚀 Go Live Checklist

### Pre-Launch Verification
- [ ] SSL certificate installed and working
- [ ] Test mode disabled
- [ ] Production credentials configured
- [ ] Webhook URL accessible
- [ ] Error logging enabled
- [ ] Payment flow tested thoroughly
- [ ] Mobile responsiveness verified
- [ ] Browser compatibility checked

### Security Checklist
- [ ] Nonce verification working
- [ ] Data sanitization implemented
- [ ] Hash verification enabled
- [ ] Debug mode disabled
- [ ] Error messages non-revealing
- [ ] Input validation in place

---

**Document Version**: 1.0.0  
**Last Updated**: November 2025  
**Integration Support**: [support@example.com]