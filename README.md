<<<<<<< HEAD
# Homebrew

[![GitHub release](https://img.shields.io/github/release/Homebrew/brew.svg)](https://github.com/Homebrew/brew/releases)
[![License](https://img.shields.io/github/license/Homebrew/brew)](https://github.com/Homebrew/brew/blob/HEAD/LICENSE.txt)

Features, usage and installation instructions are [summarised on the homepage](https://brew.sh). Terminology (e.g. the difference between a Cellar, Tap, Cask and so forth) is [explained here](https://docs.brew.sh/Formula-Cookbook#homebrew-terminology).

## What Packages Are Available?

1. Type `brew formulae` for a list.
2. Or visit [formulae.brew.sh](https://formulae.brew.sh) to browse packages online.

## More Documentation

`brew help`, `man brew` or check [our documentation](https://docs.brew.sh/).

## Troubleshooting

First, please run `brew update` and `brew doctor`.

Second, read the [Troubleshooting Checklist](https://docs.brew.sh/Troubleshooting).

**If you don't read these it will take us far longer to help you with your problem.**

## Donations

Homebrew is a non-profit project run entirely by unpaid volunteers. We need your funds to pay for software, hardware and hosting around continuous integration and future improvements to the project. Every donation will be spent on making Homebrew better for our users.

Please consider a regular donation through [GitHub Sponsors](https://github.com/sponsors/Homebrew), [Open Collective](https://opencollective.com/homebrew) or [Patreon](https://www.patreon.com/homebrew). Homebrew is fiscally hosted by the [Open Source Collective](https://opencollective.com/opensource).

For questions about donations, including corporate giving, please email the Homebrew PLC at [plc@brew.sh](mailto:plc@brew.sh).

## Community

- [Homebrew/discussions (forum)](https://github.com/orgs/Homebrew/discussions)
- [@homebrew@fosstodon.org (Mastodon)](https://fosstodon.org/@homebrew)
- [@brew.sh (Bluesky)](https://bsky.app/profile/brew.sh)
- [@MacHomebrew (𝕏 (formerly known as Twitter))](https://x.com/MacHomebrew)

## Contributing

We'd love you to contribute to Homebrew. First, please read our [Contribution Guide](CONTRIBUTING.md) and [Code of Conduct](https://github.com/Homebrew/.github/blob/HEAD/CODE_OF_CONDUCT.md#code-of-conduct).

We explicitly welcome contributions from people who have never contributed to open-source before: we were all beginners once! We can help build on a partially working pull request with the aim of getting it merged. We are also actively seeking to diversify our contributors and especially welcome contributions from women from all backgrounds and people of colour.

A good starting point for contributing is to first [tap `homebrew/core`](https://docs.brew.sh/FAQ#can-i-edit-formulae-myself), then run `brew audit --strict` with some of the packages you use (e.g. `brew audit --strict wget` if you use `wget`) and read through the warnings. Try to fix them until `brew audit --strict` shows no results and [submit a pull request](https://docs.brew.sh/How-To-Open-a-Homebrew-Pull-Request). If no formulae you use have warnings you can run `brew audit --strict` without arguments to have it run on all packages and pick one.

Alternatively, for something more substantial, check out one of the issues labelled `help wanted` in [Homebrew/brew](https://github.com/homebrew/brew/issues?q=is%3Aopen+is%3Aissue+label%3A%22help+wanted%22) or [Homebrew/homebrew-core](https://github.com/homebrew/homebrew-core/issues?q=is%3Aopen+is%3Aissue+label%3A%22help+wanted%22).

Good luck!

## Security

Please report security issues by filling in [the security advisory form](https://github.com/homebrew/brew/security/advisories/new).

## Who We Are

Homebrew's [Project Leader](https://docs.brew.sh/Homebrew-Governance#project-leader) is [Mike McQuaid](https://github.com/MikeMcQuaid).

Homebrew's [Lead Maintainers](https://docs.brew.sh/Homebrew-Governance#lead-maintainer) are [Bevan Kay](https://github.com/bevanjkay), [Bo Anderson](https://github.com/Bo98), [Branch Vincent](https://github.com/branchvincent), [Carlo Cabrera](https://github.com/carlocab), [Dustin Rodrigues](https://github.com/dtrodrigues), [FX Coudert](https://github.com/fxcoudert), [Issy Long](https://github.com/issyl0), [Justin Krehel](https://github.com/krehel), [Michael Cho](https://github.com/cho-m), [Michka Popoff](https://github.com/iMichka), [Mike McQuaid](https://github.com/MikeMcQuaid), [Nanda H Krishna](https://github.com/nandahkrishna), [Patrick Linnane](https://github.com/p-linnane), [Rui Chen](https://github.com/chenrui333), [Ruoyu Zhong](https://github.com/ZhongRuoyu), [Sam Ford](https://github.com/samford), [Sean Molenaar](https://github.com/SMillerDev) and [Thierry Moisan](https://github.com/Moisan).

Homebrew's other Maintainers are [Anton Melnikov](https://github.com/botantony), [Caleb Xu](https://github.com/alebcay), [Daeho Ro](https://github.com/daeho-ro), [Douglas Eichelberger](https://github.com/dduugg), [Eric Knibbe](https://github.com/EricFromCanada), [Klaus Hipp](https://github.com/khipp), [Markus Reiter](https://github.com/reitermarkus), [Rylan Polster](https://github.com/Rylan12), [Štefan Baebler](https://github.com/stefanb) and [William Woodruff](https://github.com/woodruffw).

Former Maintainers with significant contributions include [Alexander Bayandin](https://github.com/bayandin), [Miccal Matthews](https://github.com/miccal), [Misty De Méo](https://github.com/mistydemeo), [Shaun Jackman](https://github.com/sjackman), [Vítor Galvão](https://github.com/vitorgalvao), [Claudia Pellegrino](https://github.com/claui), [Seeker](https://github.com/SeekingMeaning), [Jan Viljanen](https://github.com/javian), [JCount](https://github.com/jcount), [commitay](https://github.com/commitay), [Dominyk Tiller](https://github.com/DomT4), [Tim Smith](https://github.com/tdsmith), [Baptiste Fontaine](https://github.com/bfontaine), [Xu Cheng](https://github.com/xu-cheng), [Martin Afanasjew](https://github.com/UniqMartin), [Brett Koonce](https://github.com/asparagui), [Charlie Sharpsteen](https://github.com/Sharpie), [Jack Nagel](https://github.com/jacknagel), [Adam Vandenberg](https://github.com/adamv), [Andrew Janke](https://github.com/apjanke), [Alex Dunn](https://github.com/dunn), [neutric](https://github.com/neutric), [Tomasz Pajor](https://github.com/nijikon), [Uladzislau Shablinski](https://github.com/vladshablinsky), [Alyssa Ross](https://github.com/alyssais), [ilovezfs](https://github.com/ilovezfs), [Chongyu Zhu](https://github.com/lembacon) and Homebrew's creator: [Max Howell](https://github.com/mxcl).

## License

Code is under the [BSD 2-clause "Simplified" License](LICENSE.txt).
Documentation is under the [Creative Commons Attribution license](https://creativecommons.org/licenses/by/4.0/).

## Sponsors

Our macOS continuous integration infrastructure is hosted by [MacStadium's Orka](https://www.macstadium.com/customers/homebrew).

[![Powered by MacStadium](https://cloud.githubusercontent.com/assets/125011/22776032/097557ac-eea6-11e6-8ba8-eff22dfd58f1.png)](https://www.macstadium.com)

Secure password storage and syncing is provided by [1Password for Teams](https://1password.com/teams/).

[<img src="https://i.1password.com/akb/featured/1password-icon.svg" alt="1Password" height="64">](https://1password.com)

<https://brew.sh>'s DNS is [resolving with DNSimple](https://dnsimple.com/resolving/homebrew).

[![DNSimple](https://cdn.dnsimple.com/assets/resolving-with-us/logo-light.png)](https://dnsimple.com/resolving/homebrew#gh-light-mode-only)
[![DNSimple](https://cdn.dnsimple.com/assets/resolving-with-us/logo-dark.png)](https://dnsimple.com/resolving/homebrew#gh-dark-mode-only)

Homebrew is generously supported by [GitHub](https://github.com/github), [Custom Ink](https://github.com/customink), [Randy Reddig](https://github.com/ydnar), [Codecademy](https://github.com/Codecademy), [b.well](https://github.com/icanbwell), [thanks.dev](https://github.com/thnxdev), [Workbrew](https://github.com/Workbrew) and many other users and organisations via [GitHub Sponsors](https://github.com/sponsors/Homebrew).

[![GitHub](https://github.com/github.png?size=64)](https://github.com/github)
=======
# Jio Pay Gateway for WooCommerce

A complete payment gateway integration that allows WooCommerce stores to accept payments through Jio Pay's secure payment popup system. **Fully compatible with WooCommerce High-Performance Order Storage (HPOS)**.

## 🚀 Features

- **Complete WooCommerce Integration** - Works with both classic and block-based checkout
- **HPOS Compatible** - Full support for WooCommerce High-Performance Order Storage
- **Secure Payment Processing** - Uses Jio Pay's official SDK with popup-based payments
- **Order Management** - Automatic order status updates and payment verification
- **Multi-Environment Support** - UAT and Live environment configurations
- **Responsive Design** - Works seamlessly on desktop and mobile devices
- **Error Handling** - Comprehensive error handling and user feedback
- **Production Ready** - Clean, optimized code suitable for production environments
- **Auto-Updates** - Built-in update notification system

## 📋 Requirements

- **WordPress** 5.0 or higher
- **WooCommerce** 3.0 or higher
- **PHP** 7.4 or higher
- **SSL Certificate** (Required for production)
- **Jio Pay Merchant Account** with valid credentials

## ✅ WooCommerce Compatibility

- **Traditional Order Storage** ✅ Fully supported
- **High-Performance Order Storage (HPOS)** ✅ Fully supported
- **WooCommerce Blocks** ✅ Fully supported
- **Classic Checkout** ✅ Fully supported

## 📁 Repository Structure

```
woo-jiopay/
├── woo-jiopay.php              # Main plugin file - Entry point and configuration
├── includes/                        # Core functionality classes
│   ├── class-woo-jiopay.php    # Main payment gateway class
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
- **`woo-jiopay.php`** - Plugin entry point, handles WordPress hooks, script enqueuing, and gateway registration
- **`class-woo-jiopay.php`** - Main gateway class extending WC_Payment_Gateway, handles admin settings, payment processing, and AJAX endpoints
- **`class-jio-pay-blocks.php`** - WooCommerce Blocks integration for modern checkout experience

#### Frontend Assets
- **`jio-pay-sdk.js`** - Jio Pay's official SDK for secure payment popup functionality
- **`jio-pay-integration.js`** - Custom integration layer handling checkout events, payment flow, and server communication
- **`jio-pay-blocks.js`** - React components for WooCommerce block-based checkout
- **`jio-pay-blocks.asset.php`** - Webpack-generated dependency configuration for block assets

## 🏪 WooCommerce HPOS Compatibility

This plugin is **fully compatible** with WooCommerce's High-Performance Order Storage (HPOS), also known as Custom Order Tables. 

### What is HPOS?
HPOS is WooCommerce's modern order storage system that uses dedicated database tables instead of WordPress posts for better performance and scalability.

### Compatibility Features
- ✅ **Automatic Detection** - Plugin automatically detects and adapts to your store's order storage method
- ✅ **Seamless Migration** - Works with stores transitioning from traditional to HPOS storage
- ✅ **Performance Optimized** - Uses WooCommerce's recommended APIs for optimal performance
- ✅ **Future Proof** - Built with WooCommerce's latest standards and best practices

### Checking HPOS Status
You can check your HPOS status in the plugin admin page:
- **WooCommerce → Jio Pay Gateway**
- Look for the "HPOS Compatibility" and "Order Storage" status indicators

## 🔧 Installation Instructions

### Method 1: Manual Installation (Recommended)

1. **Download the Plugin**
   ```bash
   git clone git@github.com:jio-pay-wp/woocommerce-plugin.git
   cd woocommerce-plugin
   ```

2. **Upload to WordPress**
   - Copy the entire `woo-jiopay` folder to your WordPress installation:
   ```bash
   cp -r woo-jiopay /path/to/wordpress/wp-content/plugins/
   ```

3. **Set Correct Permissions**
   ```bash
   chmod -R 755 /path/to/wordpress/wp-content/plugins/woo-jiopay
   chown -R www-data:www-data /path/to/wordpress/wp-content/plugins/woo-jiopay
   ```

4. **Activate the Plugin**
   - Go to your WordPress Admin Dashboard
   - Navigate to **Plugins → Installed Plugins**
   - Find "Jio Pay Gateway" and click **Activate**

### Method 2: FTP Upload

1. **Download and Extract**
   - Download the repository as ZIP
   - Extract the `woo-jiopay` folder

2. **Upload via FTP**
   - Connect to your website via FTP
   - Upload the `woo-jiopay` folder to `/wp-content/plugins/`

3. **Activate via WordPress Admin**

### Method 3: WordPress Admin Upload

1. **Create ZIP File**
   - Compress the `woo-jiopay` folder into a ZIP file

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
   git clone git@github.com:jio-pay-wp/woocommerce-plugin.git
   cd woocommerce-plugin
   ```

2. **Install in Local WordPress**
   ```bash
   ln -s $(pwd)/woo-jiopay /path/to/local/wordpress/wp-content/plugins/
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

- **GitHub Issues**: https://github.com/jio-pay-wp/woocommerce-plugin/issues
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
>>>>>>> 40d977644135edadeeed48b9bd845475bdb6be8b
