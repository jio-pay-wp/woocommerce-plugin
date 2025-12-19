# Technical Specifications - Jio Pay Gateway

## 🏗️ Architecture Overview

### System Architecture
```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   WordPress     │    │   WooCommerce   │    │   Jio Pay SDK   │
│                 │    │                 │    │                 │
│ • Plugin System │◄──►│ • Gateway API   │◄──►│ • Payment Popup │
│ • AJAX Handlers │    │ • Order System  │    │ • Secure Comm   │
│ • User Management│   │ • Checkout Flow │    │ • Transaction   │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         ▲                        ▲                        ▲
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Frontend JS   │    │  Payment Flow   │    │  Jio Pay APIs   │
│                 │    │                 │    │                 │
│ • Event Handlers│    │ • Order Creation│    │ • Auth & Verify │
│ • UI Management │    │ • Status Updates│    │ • Webhook       │
│ • Error Display │    │ • Notifications │    │ • Settlement    │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## 🔧 Technical Stack

### Backend Technologies
- **PHP**: 7.4+ (WordPress compatible)
- **WordPress**: 5.0+ (Plugin architecture)
- **WooCommerce**: 3.0+ (Payment gateway framework)
- **MySQL**: Database for order and transaction storage

### Frontend Technologies
- **JavaScript**: ES6+ for modern browser support
- **jQuery**: 3.6+ for DOM manipulation and AJAX
- **React**: For WooCommerce Blocks integration
- **CSS3**: Modern styling with animations

### External Dependencies
- **Jio Pay SDK**: Official payment processing library
- **WordPress REST API**: For block checkout integration
- **WooCommerce API**: Payment gateway framework

## 📊 Data Flow

### Payment Process Flow
```
1. Customer clicks "Place Order"
   ↓
2. Order created in WooCommerce (status: pending)
   ↓
3. Jio Pay SDK popup opens
   ↓
4. Customer completes payment in popup
   ↓
5. Jio Pay returns payment result
   ↓
6. Plugin verifies payment via AJAX
   ↓
7. Order status updated (completed/failed)
   ↓
8. Customer redirected to success/failure page
```

### Data Security Flow
```
┌─────────────┐    HTTPS    ┌─────────────┐    Secure API    ┌─────────────┐
│   Browser   │────────────►│   Server    │─────────────────►│  Jio Pay    │
│             │             │             │                  │             │
│ • Form Data │             │ • Validation│                  │ • Payment   │
│ • Payment   │             │ • Nonce     │                  │ • Processing│
│ • User Info │             │ • Sanitize  │                  │ • Response  │
└─────────────┘             └─────────────┘                  └─────────────┘
```

## 🗄️ Database Schema

### WordPress Tables Used
```sql
-- Core WordPress tables
wp_posts                    -- Orders stored as custom post type
wp_postmeta                -- Order metadata and payment details
wp_options                 -- Plugin settings and configuration
wp_users                   -- Customer information
wp_usermeta                -- Additional customer data

-- WooCommerce specific tables
wp_woocommerce_order_items      -- Order line items
wp_woocommerce_order_itemmeta   -- Item metadata
wp_woocommerce_payment_tokens   -- Saved payment methods (if applicable)
wp_wc_order_stats              -- Order statistics
```

### Key Data Structures
```php
// Order Meta Keys Used
_payment_method              // 'jio_pay'
_payment_method_title        // 'Jio Pay Gateway'
_transaction_id              // Jio Pay transaction ID
_order_total                 // Order amount
_customer_user               // Customer user ID
_billing_email               // Customer email

// Plugin Options
woocommerce_jio_pay_settings // Gateway configuration
```

## 🔌 API Endpoints

### WordPress AJAX Endpoints
```php
// Payment session creation
wp_ajax_jio_pay_create_session
wp_ajax_nopriv_jio_pay_create_session

// Payment verification
wp_ajax_jio_pay_verify_payment  
wp_ajax_nopriv_jio_pay_verify_payment

// Test endpoint (development)
wp_ajax_jio_pay_test
wp_ajax_nopriv_jio_pay_test
```

### Request/Response Formats
```javascript
// Verify Payment Request
{
    action: 'jio_pay_verify_payment',
    nonce: 'wp_nonce_string',
    order_id: 123,
    payment_data: {
        txnAuthID: '74986908063',
        txnResponseCode: '0000',
        txnRespDescription: 'Transaction successful',
        secureHash: 'hash_string',
        amount: '75000.00',
        txnDateTime: '20251103161256',
        merchantTrId: '3507027521'
    }
}

// Success Response
{
    success: true,
    data: {
        message: 'Payment verified successfully',
        redirect: 'https://site.com/checkout/order-received/123/',
        order_id: 123
    }
}

// Error Response
{
    success: false,
    data: {
        message: 'Payment verification failed: Amount mismatch',
        debug: {
            order_amount: 750.00,
            payment_amount: 75000.00
        }
    }
}
```

## 🔐 Security Implementation

### Authentication & Authorization
- **WordPress Nonces**: CSRF protection for all AJAX requests
- **User Capabilities**: Proper permission checks
- **Data Sanitization**: All input sanitized before processing
- **Output Escaping**: All output properly escaped

### Data Protection
- **SSL/TLS**: Required for all payment communications
- **No Card Storage**: No sensitive payment data stored locally
- **Secure Hashing**: Payment verification using secure hashes
- **Input Validation**: Strict validation of all payment data

### Error Handling
- **Graceful Degradation**: Fails safely without exposing sensitive data
- **Logging**: Comprehensive error logging for debugging
- **User Feedback**: Clear, non-technical error messages for users

## 📱 Browser Compatibility

### Supported Browsers
- **Chrome**: 80+ ✅
- **Firefox**: 75+ ✅
- **Safari**: 13+ ✅
- **Edge**: 80+ ✅
- **Opera**: 67+ ✅
- **Mobile Browsers**: iOS Safari 13+, Android Chrome 80+ ✅

### JavaScript Features Used
- **ES6 Features**: Arrow functions, const/let, template literals
- **Promise/Async**: AJAX handling with modern patterns
- **DOM APIs**: Modern event handling and manipulation
- **Local Storage**: Session management (minimal usage)

## ⚡ Performance Considerations

### Frontend Optimization
- **Script Loading**: Assets loaded only on checkout pages
- **Minification**: Production scripts minified
- **Caching**: Browser caching for static assets
- **Lazy Loading**: SDK loaded on demand

### Backend Optimization
- **Database Queries**: Optimized WooCommerce queries
- **Caching**: WordPress object caching compatible
- **Memory Usage**: Minimal memory footprint
- **Server Load**: Efficient AJAX handling

## 🧪 Testing Framework

### Automated Testing
- **Unit Tests**: PHP classes and JavaScript functions
- **Integration Tests**: WordPress/WooCommerce integration
- **End-to-End Tests**: Complete payment flow testing

### Manual Testing Scenarios
- **Payment Success**: Valid payment completion
- **Payment Failure**: Various failure scenarios
- **Network Issues**: Offline/timeout handling
- **Browser Testing**: Cross-browser compatibility

## 📦 Deployment Requirements

### Server Requirements
```
PHP: 7.4+ (8.0+ recommended)
WordPress: 5.0+
WooCommerce: 3.0+
MySQL: 5.6+ or MariaDB 10.1+
SSL Certificate: Required for production
Memory: 256MB+ PHP memory limit
```

### File Permissions
```bash
# Plugin directory
chmod 755 woo-jiopay/

# PHP files
chmod 644 *.php

# JavaScript files  
chmod 644 assets/*.js

# Configuration files
chmod 644 *.json *.md
```

## 🔄 Version Control

### Git Workflow
- **Main Branch**: Production-ready code
- **Development**: Feature development
- **Release**: Version tagging
- **Hotfix**: Critical production fixes

### Versioning Scheme
- **Major.Minor.Patch** (e.g., 1.0.0)
- **Semantic Versioning**: Following SemVer standards
- **WordPress Standards**: Compatible with WordPress versioning

---

**Document Version**: 1.0.0  
**Last Updated**: November 2025  
**Technical Lead**: Development Team