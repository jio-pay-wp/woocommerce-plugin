# Jio Pay Gateway for WooCommerce

A complete payment gateway integration that allows WooCommerce stores to accept payments through Jio Pay's secure payment popup system.

## 🚀 Features

- **Complete WooCommerce Integration** - Works with both classic and block-based checkout
- **Secure Payment Processing** - Uses Jio Pay's official SDK with popup-based payments
- **Order Management** - Automatic order status updates and payment verification
- **Multi-Environment Support** - UAT and Live environment configurations
- **Responsive Design** - Works seamlessly on desktop and mobile devices
- **Error Handling** - Comprehensive error handling and user feedback
- **Production Ready** - Clean, optimized code suitable for production environments

## 📋 Requirements

- **WordPress** 5.0 or higher
- **WooCommerce** 3.0 or higher
- **PHP** 7.4 or higher
- **SSL Certificate** (Required for production)
- **Jio Pay Merchant Account** with valid credentials

## 📁 Repository Structure

```
jio-pay-gateway/
├── jio-pay-gateway.php              # Main plugin file - Entry point and configuration
├── includes/                        # Core functionality classes
│   ├── class-jio-pay-gateway.php    # Main payment gateway class
│   └── class-jio-pay-blocks.php     # WooCommerce Blocks checkout support
├── assets/                          # Frontend resources
│   ├── jio-pay-sdk.js               # Jio Pay SDK library
│   ├── jio-pay-integration.js       # Payment integration and event handling
│   ├── jio-pay-blocks.js            # Block checkout React components
│   └── jio-pay-blocks.asset.php     # Block dependencies configuration
└── README.md                        # This documentation file
```

### File Descriptions

#### Core Files
- **`jio-pay-gateway.php`** - Plugin entry point, handles WordPress hooks, script enqueuing, and gateway registration
- **`class-jio-pay-gateway.php`** - Main gateway class extending WC_Payment_Gateway, handles admin settings, payment processing, and AJAX endpoints
- **`class-jio-pay-blocks.php`** - WooCommerce Blocks integration for modern checkout experience

#### Frontend Assets
- **`jio-pay-sdk.js`** - Jio Pay's official SDK for secure payment popup functionality
- **`jio-pay-integration.js`** - Custom integration layer handling checkout events, payment flow, and server communication
- **`jio-pay-blocks.js`** - React components for WooCommerce block-based checkout
- **`jio-pay-blocks.asset.php`** - Webpack-generated dependency configuration for block assets

## 🔧 Installation Instructions

### Method 1: Manual Installation (Recommended)

1. **Download the Plugin**
   ```bash
   git clone git@github.com:techfleek-code/jio-pay.git
   cd jio-pay
   ```

2. **Upload to WordPress**
   - Copy the entire `jio-pay-gateway` folder to your WordPress installation:
   ```bash
   cp -r jio-pay-gateway /path/to/wordpress/wp-content/plugins/
   ```

3. **Set Correct Permissions**
   ```bash
   chmod -R 755 /path/to/wordpress/wp-content/plugins/jio-pay-gateway
   chown -R www-data:www-data /path/to/wordpress/wp-content/plugins/jio-pay-gateway
   ```

4. **Activate the Plugin**
   - Go to your WordPress Admin Dashboard
   - Navigate to **Plugins → Installed Plugins**
   - Find "Jio Pay Gateway" and click **Activate**

### Method 2: FTP Upload

1. **Download and Extract**
   - Download the repository as ZIP
   - Extract the `jio-pay-gateway` folder

2. **Upload via FTP**
   - Connect to your website via FTP
   - Upload the `jio-pay-gateway` folder to `/wp-content/plugins/`

3. **Activate via WordPress Admin**

### Method 3: WordPress Admin Upload

1. **Create ZIP File**
   - Compress the `jio-pay-gateway` folder into a ZIP file

2. **Upload via WordPress**
   - Go to **Plugins → Add New → Upload Plugin**
   - Choose the ZIP file and click **Install Now**
   - Click **Activate Plugin**

## ⚙️ Configuration

### 1. Basic Setup

1. **Navigate to WooCommerce Settings**
   - Go to **WooCommerce → Settings → Payments**
   - Find "Jio Pay Gateway" and click **Set up** or **Manage**

2. **Configure Payment Gateway**
   ```
   ✅ Enable/Disable: Check "Enable Jio Pay"
   📝 Title: "Jio Pay" (appears during checkout)
   📝 Description: "Pay securely via Jio Pay popup"
   🔑 Merchant ID: Your Jio Pay merchant ID
   🔐 Secret Key: Your Jio Pay secret key
   🌍 Environment: Select "UAT" for testing or "Live" for production
   ```

### 2. Test Configuration

**For UAT Environment:**
```
Merchant ID: JP2000000000031 (default test ID)
Secret Key: abc (default test key)
Environment: UAT
```

**For Production:**
- Use your actual Jio Pay merchant credentials
- Set Environment to "Live"
- Ensure SSL certificate is installed

### 3. Verify Installation

1. **Check Payment Methods**
   - Go to your store's checkout page
   - Add a product to cart and proceed to checkout
   - Verify "Jio Pay" appears as a payment option

2. **Test Payment Flow**
   - Select Jio Pay as payment method
   - Click "Place Order"
   - Verify the Jio Pay popup opens
   - Complete a test transaction

## 🔐 Security Considerations

### Production Checklist

- [ ] **SSL Certificate** installed and active
- [ ] **Live Merchant Credentials** configured
- [ ] **Environment** set to "Live"
- [ ] **Test Mode** disabled
- [ ] **WordPress and WooCommerce** updated to latest versions
- [ ] **File Permissions** properly set (755 for directories, 644 for files)

### Security Features

- **Nonce Verification** - All AJAX requests include WordPress nonces
- **Data Sanitization** - All input data is properly sanitized
- **Secure Communication** - Uses HTTPS for all payment communications
- **No Sensitive Data Storage** - Payment details are not stored locally

## 🛠️ Development

### Local Development Setup

1. **Clone Repository**
   ```bash
   git clone git@github.com:techfleek-code/jio-pay.git
   cd jio-pay
   ```

2. **Install in Local WordPress**
   ```bash
   ln -s $(pwd)/jio-pay-gateway /path/to/local/wordpress/wp-content/plugins/
   ```

3. **Enable WordPress Debug Mode**
   ```php
   // In wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

### Customization

The plugin is designed to be easily customizable:

- **Payment Options** - Modify `jio-pay-integration.js` line 47-62
- **Styling** - Add custom CSS for the payment form
- **Error Messages** - Customize messages in the gateway class
- **Success Flow** - Modify the success redirect behavior

## 📚 API Reference

### AJAX Endpoints

The plugin provides these AJAX endpoints:

- **`jio_pay_create_session`** - Creates payment session (currently mock)
- **`jio_pay_verify_payment`** - Verifies completed payment and updates order status

### JavaScript Events

- **Payment Success** - `handlePaymentSuccess(paymentResult)`
- **Payment Failure** - `handlePaymentFailure(error)`
- **Payment Cancel** - `handlePaymentCancel()`

## 🐛 Troubleshooting

### Common Issues

1. **Payment Option Not Showing**
   - Verify WooCommerce is active
   - Check if plugin is activated
   - Ensure Merchant ID and Secret Key are configured

2. **Popup Not Opening**
   - Check browser console for JavaScript errors
   - Verify Jio Pay SDK is loading
   - Test in different browsers

3. **Payment Not Completing**
   - Check if `verify_payment` endpoint is accessible
   - Verify AJAX URLs are correct
   - Check server error logs

### Debug Information

Enable WordPress debug mode and check logs at:
```
/wp-content/debug.log
/wp-content/uploads/wc-logs/
```

## 📞 Support

For technical support and questions:

- **GitHub Issues**: https://github.com/techfleek-code/jio-pay/issues
- **Documentation**: This README file
- **WooCommerce Documentation**: https://docs.woocommerce.com/

## 📄 License

This plugin is licensed under the GPL2 license. See the plugin header for full license information.

## 🔄 Changelog

### Version 1.0.0
- Initial release
- Complete Jio Pay integration
- Support for both classic and block checkout
- Payment verification workflow
- Production-ready codebase

---

**Made with ❤️ for WooCommerce stores using Jio Pay**