jQuery(document).ready(function ($) {

    // IMPORTANT: Hide any SDK elements that may be created on page load
    // This prevents dark mode overlays from showing before payment is initiated
    function hideSDKElements() {
        // Hide popup wrappers and containers created by SDK
        $('.popup-wrapper, .exit-popup-wrapper, .main-container, .main-payment').hide();
        
        // Also target any fixed positioned elements with high z-index that SDK might create
        $('body > div').each(function() {
            var $el = $(this);
            var pos = $el.css('position');
            var zIndex = parseInt($el.css('z-index')) || 0;
            
            // If it's a fixed/absolute positioned element with high z-index and not our notification
            if ((pos === 'fixed' || pos === 'absolute') && zIndex > 900) {
                if (!$el.is('#jio-pay-notification-container') && 
                    !$el.hasClass('jio-pay-notification') &&
                    !$el.hasClass('woocommerce-error') &&
                    !$el.hasClass('woocommerce-message')) {
                    $el.hide();
                }
            }
        });
    }
    
    // Run immediately
    hideSDKElements();
    
    // Run again after a short delay (in case SDK creates elements asynchronously)
    setTimeout(hideSDKElements, 100);
    setTimeout(hideSDKElements, 500);
    
    // Watch for new elements added to body and hide SDK elements
    // This catches any elements the SDK creates after page load
    if (typeof MutationObserver !== 'undefined') {
        var sdkObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) { // Element node
                            var $node = $(node);
                            // Check if it's an SDK element that should be hidden
                            if ($node.hasClass('popup-wrapper') || 
                                $node.hasClass('exit-popup-wrapper') ||
                                $node.hasClass('main-container') ||
                                $node.hasClass('main-payment')) {
                                // Only hide if payment is not being processed
                                if (!window.jioPayProcessing) {
                                    $node.hide();
                                }
                            }
                        }
                    });
                }
            });
        });
        
        sdkObserver.observe(document.body, { childList: true, subtree: false });
    }

    // Flag to track if payment has been handled (success or failure)
    window.jioPaymentHandled = false;
    
    // Flag to track if we've attached our handlers
    window.jioPayHandlersAttached = false;

    // Check for test payment completion message
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('test_payment') === 'completed') {
        // Show test completion notification
        showTestCompletionNotification();

        // Clean URL
        if (window.history && window.history.replaceState) {
            const cleanUrl = window.location.href.split('?')[0];
            window.history.replaceState({}, document.title, cleanUrl);
        }
    }

    // ============================================
    // CLASSIC CHECKOUT HANDLING
    // ============================================
    
    // Log checkout type for debugging
    console.log('[JioPay] Initializing - Blocks checkout:', !!document.querySelector('.wc-block-checkout'));
    console.log('[JioPay] Initializing - Classic checkout form:', !!document.querySelector('form.checkout'));
    console.log('[JioPay] Initializing - Order review form:', !!document.querySelector('form#order_review'));

    // Function to check if Jio Pay is selected
    function isJioPaySelected() {
        // Check multiple possible selectors for payment method
        const selectedMethod = $('input[name="payment_method"]:checked').val() ||
                               $('input[name="radio-control-wc-payment-method-options"]:checked').val();
        console.log('[JioPay] Selected payment method:', selectedMethod);
        return selectedMethod === 'jio_pay';
    }

    // Use WooCommerce's checkout validation hook - THIS IS THE PRIMARY HANDLER FOR CLASSIC CHECKOUT
    // This event is triggered by WooCommerce's checkout.js when the form is about to be submitted
    $(document.body).on('checkout_place_order', function () {
        console.log('[JioPay] checkout_place_order event triggered');
        
        // Only intercept if Jio Pay is selected
        if (!isJioPaySelected()) {
            console.log('[JioPay] Jio Pay not selected, allowing normal checkout');
            return true;
        }
        
        console.log('[JioPay] Jio Pay is selected, intercepting checkout');
        
        // For classic checkout, we want to intercept and create order via Jio Pay flow
        if ($('form.checkout').length && !window.jioPayProcessing) {
            // Mark that we're processing to avoid loops
            window.jioPayProcessing = true;

            // Process classic checkout
            setTimeout(() => {
                processClassicCheckout();
            }, 10);

            return false; // Stop WooCommerce's default AJAX processing
        }

        // For block checkout or if already processing, continue normally
        return true;
    });
    
    // Also listen to the specific jio_pay event (fallback)
    $(document.body).on('checkout_place_order_jio_pay', function () {
        console.log('[JioPay] checkout_place_order_jio_pay triggered');

        // For classic checkout, we want to intercept here and create order via Jio Pay flow
        if ($('form.checkout').length && !window.jioPayProcessing) {
            // Mark that we're processing to avoid loops
            window.jioPayProcessing = true;

            // Prevent the default order creation
            setTimeout(() => {
                processClassicCheckout();
            }, 10);

            return false; // Stop WooCommerce's default processing
        }

        // For block checkout or if already processing, continue normally
        return true;
    });

    // Handle form submission for classic checkout (backup handler)
    // Using high priority by attaching directly to the form
    $(document).on('submit', 'form.checkout', function (e) {
        console.log('[JioPay] form.checkout submit event');
        
        if (!isJioPaySelected()) {
            return true;
        }
        
        // If we're already processing, allow the form to continue
        if (window.jioPayProcessing) {
            console.log('[JioPay] Already processing, skipping submit handler');
            return true;
        }
        
        // Validate and process
        if (validateCheckoutForm()) {
            console.log('[JioPay] Validation passed, intercepting form submission');
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            window.jioPayProcessing = true;
            processClassicCheckout();
            return false;
        }
        
        return true;
    });
    
    // Handle Place Order button click (earliest interception point for classic checkout)
    $(document).on('click', '#place_order, .wc-block-components-checkout-place-order-button', function (e) {
        console.log('[JioPay] Place Order button clicked');
        
        if (!isJioPaySelected()) {
            console.log('[JioPay] Jio Pay not selected');
            return true;
        }
        
        // For WooCommerce Blocks, let the fetch interceptor handle it
        if (document.querySelector('.wc-block-checkout')) {
            console.log('[JioPay] Blocks checkout detected, letting fetch handle it');
            return true;
        }
        
        // For classic checkout, we'll let the form submit handler or WC event handler catch it
        // This is just for logging
        console.log('[JioPay] Classic checkout - will be handled by checkout event');
    });

    // Handle form submission for order-pay/pay-for-order page (form#order_review)
    $(document).on('submit', 'form#order_review', function (e) {
        const selectedPaymentMethod = $('input[name="payment_method"]:checked').val();

        console.log('[JioPay] form#order_review submit detected. Selected payment method:', selectedPaymentMethod);

        if (selectedPaymentMethod === 'jio_pay') {
            // Validate required fields if present, else allow (order-pay page may have limited fields)
            var valid = true;
            var $required = $(this).find('input[required], select[required], textarea[required]');
            $required.each(function() {
                if (!$(this).val()) {
                    $(this).addClass('woocommerce-invalid');
                    valid = false;
                } else {
                    $(this).removeClass('woocommerce-invalid');
                }
            });
            if (!valid) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
            // Prevent default and trigger Jio Pay popup
            e.preventDefault();
            e.stopImmediatePropagation();
            
            // For order-pay page, the order already exists - get order ID from URL and initiate payment directly
            processOrderPayPage();
            return false;
        }
        else {
            console.log('[JioPay] Jio Pay is not selected, normal submit.');
        }   
    });

    // ============================================
    // BLOCKS CHECKOUT HANDLING (REST API)
    // ============================================
    
    // Monitor for WooCommerce REST API responses (block checkout)
    let originalFetch = window.fetch;
    window.fetch = function (...args) {
        return originalFetch.apply(this, args).then(response => {
            if (args[0] && args[0].includes && args[0].includes('/wc/store/v1/checkout')) {
                response.clone().json().then(data => {
                    if (data.payment_method === 'jio_pay' && data.status === 'pending') {
                        // Store order details for success redirect
                        window.currentOrderId = data.order_id;
                        window.currentOrderKey = data.order_key;

                        // Store customer data from order if available
                        if (data.billing_address) {
                            window.currentCustomerData = {
                                email: data.billing_address.email || '',
                                name: (data.billing_address.first_name + ' ' + data.billing_address.last_name).trim(),
                                phone: data.billing_address.phone || ''
                            };
                            console.log('Stored customer data from order:', window.currentCustomerData);
                        }

                        setTimeout(() => {
                            initiateJioPaymentWithOrderId(data.order_id);
                        }, 500);
                    }
                }).catch(err => console.log('Could not parse checkout response:', err));
            }
            return response;
        });
    };
    
    // ============================================
    // CLASSIC CHECKOUT AJAX INTERCEPTOR
    // ============================================
    // WooCommerce Classic checkout uses jQuery AJAX, not fetch
    // We need to intercept BEFORE WooCommerce processes the response
    
    // Use ajaxPrefilter to modify the AJAX request and intercept response
    $.ajaxPrefilter(function(options, originalOptions, jqXHR) {
        // Only intercept WooCommerce checkout AJAX calls
        if (options.url && (options.url.includes('wc-ajax=checkout') || options.url.includes('action=woocommerce_checkout'))) {
            
            // Check if Jio Pay is selected BEFORE the request
            if (!isJioPaySelected()) {
                console.log('[JioPay] Not Jio Pay, skipping intercept');
                return;
            }
            
            console.log('[JioPay] Intercepting WooCommerce checkout AJAX');
            
            // Store the original success callback
            var originalSuccess = options.success;
            
            // Replace the success callback
            options.success = function(response, textStatus, jqXHR) {
                console.log('[JioPay] Checkout AJAX response intercepted:', response);
                
                // Check if order was created successfully
                if (response && response.result === 'success') {
                    // Get order ID - it might be in redirect URL or directly in response
                    var orderId = null;
                    
                    if (response.order_id) {
                        orderId = response.order_id;
                    } else if (response.redirect) {
                        var orderIdMatch = response.redirect.match(/order-received\/(\d+)/);
                        if (orderIdMatch) {
                            orderId = parseInt(orderIdMatch[1]);
                        }
                    }
                    
                    if (orderId) {
                        console.log('[JioPay] Order created successfully, ID:', orderId);
                        
                        // Store order ID
                        window.currentOrderId = orderId;
                        
                        // Remove processing state
                        $('body').removeClass('processing');
                        $('.blockUI').remove();
                        
                        // DO NOT call the original success - this prevents WooCommerce from redirecting
                        // Instead, initiate Jio Pay payment
                        setTimeout(() => {
                            initiateJioPayment();
                        }, 100);
                        
                        return; // Don't call original success
                    }
                }
                
                // For failures or non-Jio Pay responses, call original success
                if (originalSuccess) {
                    originalSuccess.call(this, response, textStatus, jqXHR);
                }
            };
        }
    });

    // For WooCommerce Blocks, ensure validation happens before order creation
    // Listen for checkout processing events
    $(document).on('checkout_place_order_jio_pay', function () {
        // This event is triggered by WooCommerce before processing the order
        // Return false to stop processing if validation fails
        return validateBlocksCheckout();
    });

    function validateBlocksCheckout() {
        // For blocks checkout, WooCommerce handles most validation
        // But we can add additional custom validation here if needed

        // Check if Jio Pay is selected and add any custom validation
        const selectedPaymentMethod = $('input[name="radio-control-wc-payment-method-options"]:checked').val() ||
            $('input[name="payment_method"]:checked').val();

        if (selectedPaymentMethod === 'jio_pay') {
            // Add any Jio Pay specific validation here
            console.log('Jio Pay selected - performing validation...');

            // For now, let WooCommerce handle the validation
            // Custom validation can be added here if needed
            return true;
        }

        return true;
    }

    // Function to validate checkout form before processing Jio Pay
    function validateCheckoutForm() {
        const $form = $('form.checkout');
        if (!$form.length) return true; // No form to validate

        // Clear previous errors
        $('.woocommerce-error, .woocommerce-message').remove();
        $form.find('input, select, textarea').removeClass('woocommerce-invalid woocommerce-invalid-required-field');

        let isValid = true;
        let errors = [];

        // Check required billing fields
        const requiredBillingFields = [
            { field: 'billing_first_name', label: 'First name' },
            { field: 'billing_last_name', label: 'Last name' },
            { field: 'billing_email', label: 'Email address' },
            { field: 'billing_phone', label: 'Phone' },
            { field: 'billing_address_1', label: 'Street address' },
            { field: 'billing_city', label: 'Town / City' },
            { field: 'billing_postcode', label: 'Postcode / ZIP' },
            { field: 'billing_country', label: 'Country' }
        ];

        requiredBillingFields.forEach(function (item) {
            const $field = $form.find('[name="' + item.field + '"]');
            if ($field.length && $field.is(':visible')) {
                const value = $field.val();
                if (!value || value.trim() === '') {
                    $field.addClass('woocommerce-invalid woocommerce-invalid-required-field');
                    errors.push(item.label + ' is a required field.');
                    isValid = false;
                }
            }
        });

        // Validate email format
        const $email = $form.find('[name="billing_email"]');
        if ($email.length && $email.val()) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test($email.val())) {
                $email.addClass('woocommerce-invalid woocommerce-invalid-email');
                errors.push('Please enter a valid email address.');
                isValid = false;
            }
        }

        // Check if shipping is different and validate shipping fields
        if ($form.find('#ship-to-different-address-checkbox:checked').length) {
            const requiredShippingFields = [
                { field: 'shipping_first_name', label: 'Shipping first name' },
                { field: 'shipping_last_name', label: 'Shipping last name' },
                { field: 'shipping_address_1', label: 'Shipping street address' },
                { field: 'shipping_city', label: 'Shipping town / city' },
                { field: 'shipping_postcode', label: 'Shipping postcode / ZIP' },
                { field: 'shipping_country', label: 'Shipping country' }
            ];

            requiredShippingFields.forEach(function (item) {
                const $field = $form.find('[name="' + item.field + '"]');
                if ($field.length && $field.is(':visible')) {
                    const value = $field.val();
                    if (!value || value.trim() === '') {
                        $field.addClass('woocommerce-invalid woocommerce-invalid-required-field');
                        errors.push(item.label + ' is a required field.');
                        isValid = false;
                    }
                }
            });
        }

        // Check terms and conditions if present
        const $terms = $form.find('input[name="terms"]:checkbox');
        if ($terms.length && !$terms.is(':checked')) {
            $terms.addClass('woocommerce-invalid');
            errors.push('You must accept the terms and conditions.');
            isValid = false;
        }

        // Check privacy policy if present
        const $privacy = $form.find('input[name="privacy_policy"]:checkbox');
        if ($privacy.length && !$privacy.is(':checked')) {
            $privacy.addClass('woocommerce-invalid');
            errors.push('You must accept the privacy policy.');
            isValid = false;
        }

        // Display errors if any
        if (!isValid && errors.length > 0) {
            const errorHtml = '<ul class="woocommerce-error" role="alert"><li>' + errors.join('</li><li>') + '</li></ul>';
            $form.prepend(errorHtml);

            // Scroll to the error (only if form exists and offset is available)
            if ($form.length && $form.offset()) {
                $('html, body').animate({
                    scrollTop: $form.offset().top - 100
                }, 500);
            }
        }

        return isValid;
    }

    // Function to get current checkout form data
    function getCheckoutFormData() {
        const $form = $('form.checkout');
        let formData = {
            email: '',
            name: '',
            phone: '',
            address: ''
        };

        if ($form.length) {
            // Get billing information
            const firstName = $form.find('[name="billing_first_name"]').val() || '';
            const lastName = $form.find('[name="billing_last_name"]').val() || '';
            const email = $form.find('[name="billing_email"]').val() || '';
            const phone = $form.find('[name="billing_phone"]').val() || '';
            const address1 = $form.find('[name="billing_address_1"]').val() || '';
            const city = $form.find('[name="billing_city"]').val() || '';
            const postcode = $form.find('[name="billing_postcode"]').val() || '';

            formData.email = email.trim();
            formData.name = (firstName + ' ' + lastName).trim();
            formData.phone = phone.trim();
            formData.address = (address1 + ', ' + city + ' ' + postcode).trim().replace(/^,\s*/, '').replace(/,\s*$/, '');
        } else {
            // For WooCommerce Blocks, try to get data from different selectors
            const email = $('[name*="email"]').val() || $('input[type="email"]').val() || '';
            const firstName = $('[name*="first_name"], [name*="firstName"]').val() || '';
            const lastName = $('[name*="last_name"], [name*="lastName"]').val() || '';

            formData.email = email.trim();
            formData.name = (firstName + ' ' + lastName).trim();
        }

        console.log('Extracted form data:', formData);
        return formData;
    }

    function processClassicCheckout() {
        console.log('[JioPay] Processing classic checkout for Jio Pay...');

        // Show loading
        $('body').addClass('processing');
        $('.checkout-button, #place_order').prop('disabled', true);

        // Get form data
        const $form = $('form.checkout');
        const formData = $form.serialize();
        
        // Determine the correct checkout URL
        // Priority: 1. wc_checkout_params (if available), 2. form action, 3. wc-ajax endpoint
        let checkoutUrl;
        if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.checkout_url) {
            checkoutUrl = wc_checkout_params.checkout_url;
        } else if ($form.attr('action') && $form.attr('action') !== '') {
            checkoutUrl = $form.attr('action');
        } else {
            // Fallback to wc-ajax endpoint
            checkoutUrl = '?wc-ajax=checkout';
        }
        
        console.log('[JioPay] Submitting to checkout URL:', checkoutUrl);

        // Submit the checkout form to create the order
        $.ajax({
            type: 'POST',
            url: checkoutUrl,
            data: formData,
            dataType: 'json',
            success: function (response) {
                console.log('[JioPay] Checkout response:', response);
                
                if (response.result === 'success') {
                    // Order created successfully, extract order ID from redirect URL
                    const redirectUrl = response.redirect;
                    const orderIdMatch = redirectUrl.match(/order-received\/(\d+)/);

                    if (orderIdMatch) {
                        window.currentOrderId = parseInt(orderIdMatch[1]);
                        console.log('[JioPay] Order created with ID:', window.currentOrderId);

                        // Now initiate Jio Pay payment
                        setTimeout(() => {
                            initiateJioPayment();
                        }, 500);
                    } else {
                        refreshCheckoutForRetry();
                        showErrorNotification(
                            'Order Creation Failed',
                            'Could not create order for payment processing.<br><br>Please check your details and try again.'
                        );
                    }
                } else {
                    refreshCheckoutForRetry();

                    // Show WooCommerce errors if available
                    if (response.messages) {
                        $('.woocommerce-error, .woocommerce-message').remove();
                        // Try both forms
                        var $formError = $('form.checkout');
                        if (!$formError.length) $formError = $('form#order_review');
                        $formError.prepend(response.messages);
                        if ($formError.length && $formError.offset()) {
                            $('html, body').animate({
                                scrollTop: $formError.offset().top - 100
                            }, 1000);
                        }
                    } else {
                        showErrorNotification(
                            'Checkout Error',
                            'There was an error processing your checkout.<br><br>Please check your details and try again.'
                        );
                    }
                }
            },
            error: function (xhr, status, error) {
                console.error('[JioPay] Checkout AJAX error:', status, error);
                refreshCheckoutForRetry();
                showErrorNotification(
                    'Connection Error',
                    'Unable to process checkout due to connection issues.<br><br>Please check your connection and try again.'
                );
            },
            complete: function () {
                // Reset processing flag
                window.jioPayProcessing = false;
            }
        });
    }

    // Function to handle order-pay page (pay for existing order)
    function processOrderPayPage() {
        console.log('Processing order-pay page for Jio Pay...');

        // Show loading
        $('body').addClass('processing');
        $('#place_order').prop('disabled', true);

        // Get order ID from URL - handle both permalink formats:
        // 1. Pretty permalinks: /checkout/order-pay/123/?key=wc_order_xxx
        // 2. Query string format: /checkout/?order-pay=123&key=wc_order_xxx
        const urlParams = new URLSearchParams(window.location.search);
        let orderId = urlParams.get('order-pay');
        const orderKey = urlParams.get('key');

        // If not found in query params, try to extract from URL path (pretty permalinks)
        if (!orderId) {
            const pathMatch = window.location.pathname.match(/\/order-pay\/(\d+)/);
            if (pathMatch && pathMatch[1]) {
                orderId = pathMatch[1];
                console.log('[JioPay] Order ID extracted from URL path:', orderId);
            }
        }

        console.log('[JioPay] Order ID from URL:', orderId, 'Order Key:', orderKey);

        if (!orderId) {
            refreshCheckoutForRetry();
            showErrorNotification(
                'Order Not Found',
                'Could not find order ID for payment processing.<br><br>Please refresh the page and try again.'
            );
            return;
        }

        // Store order details
        window.currentOrderId = parseInt(orderId);
        window.currentOrderKey = orderKey;

        // Initiate Jio Pay payment directly (order already exists)
        setTimeout(() => {
            initiateJioPayment();
        }, 500);
    }

    function initiateJioPayment() {
        // Check if we're in test mode
        if (jioPayVars.use_test_data) {
            // Show test mode confirmation
            if (!confirm('⚠️ TEST MODE ACTIVE\n\nYou are using test data because:\n• Cart amount is not available, OR\n• User is not logged in\n\nThis will NOT process a real payment.\n\nClick OK to continue with test payment or Cancel to abort.')) {
                $('body').removeClass('processing');
                $('.checkout-button').prop('disabled', false);
                return;
            }

            // Show test mode notification
            showTestModeNotification();
        }

        // Show loading
        $('body').addClass('processing');
        $('.checkout-button').prop('disabled', true);

        // console.log('Initiating Jio Pay payment...', jioPayVars);
        // console.log('Using Agregator ID:', jioPayVars.agregator_id);
        try {
            if (typeof jioPaySDK !== 'undefined') {
                // Get customer data - prioritize order data, then form data, then defaults
                let customerData = {
                    email: jioPayVars.customer_email,
                    name: jioPayVars.customer_name
                };

                // Use order data if available (from blocks checkout)
                if (window.currentCustomerData) {
                    customerData = {
                        email: window.currentCustomerData.email || customerData.email,
                        name: window.currentCustomerData.name || customerData.name
                    };
                    console.log('Using order customer data:', customerData);
                } else {
                    // Fall back to form data (for classic checkout or guest users)
                    const formData = getCheckoutFormData();
                    customerData = {
                        email: formData.email || customerData.email,
                        name: formData.name || customerData.name
                    };
                    console.log('Using form customer data:', customerData);
                }

                // Generate merchant transaction ID
                const merchantTrId = Math.floor(1000000000 + Math.random() * 9000000000).toString();

                // Store merchant transaction ID for later reference
                window.currentMerchantTrId = merchantTrId;
                
                // Reset payment handled flag before opening payment
                window.jioPaymentHandled = false;

                const paymentOptions = {
                    amount: parseFloat(jioPayVars.amount).toFixed(2),
                    env: jioPayVars.environment,
                    merchantId: jioPayVars.merchant_id,
                    aggId: jioPayVars.agregator_id,
                    customerEmailID: customerData.email,
                    email: customerData.email,
                    userName: customerData.name,
                    merchantName: jioPayVars.merchant_name,
                    merchantLogo: jioPayVars.merchantLogo || '',
                    allowedPaymentTypes: Array.isArray(jioPayVars.allowed_payment_types) ? jioPayVars.allowed_payment_types : (jioPayVars.allowed_payment_types ? jioPayVars.allowed_payment_types.split(',') : ["NB", "UPI_QR", "UPI_VPA", "CARD"]),
                    theme: jioPayVars.theme || { color: "#E39B2B" },
                    timeout: jioPayVars.timeout,
                    secretKey: jioPayVars.secret_key,
                    merchantTrId: merchantTrId,
                    returnURL: '', //jioPayVars.return_url, // Return URL for POST callback
                    onSuccess: handlePaymentSuccess,
                    onFailure: handlePaymentFailure,
                    onClose: handlePaymentCancel
                };

                console.log('Payment Options:', {
                    customerEmail: paymentOptions.customerEmailID,
                    userName: paymentOptions.userName,
                    amount: paymentOptions.amount,
                    merchantTrId: merchantTrId,
                    returnURL: paymentOptions.returnURL
                });

                // Store merchant transaction ID in order meta before opening payment
                if (window.currentOrderId) {
                    $.post(jioPayVars.ajax_url, {
                        action: 'jio_pay_store_merchant_tr_id',
                        nonce: jioPayVars.nonce,
                        order_id: window.currentOrderId,
                        merchant_tr_id: merchantTrId
                    }).done(function (response) {
                        console.log('Merchant transaction ID stored:', response);
                    }).fail(function (error) {
                        console.error('Failed to store merchant transaction ID:', error);
                    });
                }

                const jioPay = new jioPaySDK(paymentOptions);
                jioPay.open();

            } else {
                refreshCheckoutForRetry();
                showErrorNotification(
                    'Payment Gateway Unavailable',
                    'The payment gateway is currently unavailable.<br><br>Please try again in a few moments or contact support.'
                );
            }

        } catch (error) {
            console.error('Error initializing Jio Pay:', error);
            refreshCheckoutForRetry();
            showErrorNotification(
                'Payment Initialization Failed',
                'Could not initialize payment gateway.<br><strong>Error:</strong> ' + error.message + '<br><br>Please try again or contact support.'
            );
        } finally {
            $('body').removeClass('processing');
            $('.checkout-button').prop('disabled', false);
        }
    }

    function initiateJioPaymentWithOrderId(orderId) {
        window.currentOrderId = orderId;
        initiateJioPayment();
    }

    // Enhanced notification system for professional display without layout disruption
    function showPaymentNotification(type, title, message, autoHide = true) {
        // Remove any existing payment notifications
        jQuery('.jio-pay-notification').remove();

        // Choose colors and icons based on type
        let bgColor, borderColor, textColor, icon;
        switch (type) {
            case 'success':
                bgColor = '#d4edda';
                borderColor = '#c3e6cb';
                textColor = '#155724';
                icon = '✅';
                break;
            case 'error':
                bgColor = '#f8d7da';
                borderColor = '#f5c6cb';
                textColor = '#721c24';
                icon = '❌';
                break;
            case 'warning':
                bgColor = '#fff3cd';
                borderColor = '#ffeaa7';
                textColor = '#856404';
                icon = '⚠️';
                break;
            case 'info':
                bgColor = '#d1ecf1';
                borderColor = '#bee5eb';
                textColor = '#0c5460';
                icon = 'ℹ️';
                break;
            default:
                bgColor = '#f8f9fa';
                borderColor = '#dee2e6';
                textColor = '#495057';
                icon = '📝';
        }

        // Create or find notification container
        let notificationContainer = jQuery('#jio-pay-notification-container');
        if (!notificationContainer.length) {
            // Create a fixed container that doesn't affect layout
            notificationContainer = jQuery(`
                <div id="jio-pay-notification-container" style="
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    z-index: 99999;
                    pointer-events: none;
                    padding: 20px;
                "></div>
            `);
            jQuery('body').append(notificationContainer);
        }

        // Create notification HTML
        const notification = jQuery(`
            <div class="jio-pay-notification" style="
                background: ${bgColor};
                border: 2px solid ${borderColor};
                color: ${textColor};
                padding: 15px 20px;
                margin: 0 auto 15px auto;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                max-width: 500px;
                position: relative;
                pointer-events: auto;
                animation: slideDown 0.4s ease-out;
                transform: translateY(0);
            ">
                <div style="display: flex; align-items: flex-start; gap: 12px;">
                    <span style="font-size: 18px; flex-shrink: 0; margin-top: 1px;">${icon}</span>
                    <div style="flex: 1;">
                        <div style="font-weight: 600; font-size: 16px; margin-bottom: 5px;">${title}</div>
                        <div style="font-size: 14px; line-height: 1.4;">${message}</div>
                        ${type === 'error' || type === 'warning' ?
                '<button class="jio-pay-dismiss-btn" style="margin-top: 10px; padding: 6px 12px; background: ' + textColor + '; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.2s;">Dismiss</button>' :
                ''
            }
                    </div>
                    <button class="jio-pay-close-btn" style="
                        position: absolute; 
                        top: 8px; 
                        right: 12px; 
                        background: none; 
                        border: none; 
                        font-size: 20px; 
                        cursor: pointer; 
                        color: ${textColor}; 
                        opacity: 0.7; 
                        padding: 0; 
                        line-height: 1;
                        width: 24px;
                        height: 24px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        border-radius: 50%;
                        transition: all 0.2s;
                    ">&times;</button>
                </div>
            </div>
        `);

        // Bind click events for dismiss and close buttons
        notification.find('.jio-pay-dismiss-btn, .jio-pay-close-btn').on('click', function() {
            jQuery(this).closest('.jio-pay-notification').fadeOut(300, function() {
                jQuery(this).remove();
                // Clean up container if empty
                if (jQuery('#jio-pay-notification-container').children().length === 0) {
                    jQuery('#jio-pay-notification-container').remove();
                }
            });
        });

        // Add hover effects for close button
        notification.find('.jio-pay-close-btn').on('mouseover', function() {
            jQuery(this).css({'opacity': '1', 'backgroundColor': 'rgba(0,0,0,0.1)'});
        }).on('mouseout', function() {
            jQuery(this).css({'opacity': '0.7', 'backgroundColor': 'transparent'});
        });

        // Add CSS animation if not already added
        if (!jQuery('#jio-pay-animations').length) {
            jQuery('<style id="jio-pay-animations">@keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }</style>').appendTo('head');
        }

        // Add notification to container
        notificationContainer.append(notification);

        // Auto-hide after specified time
        if (autoHide) {
            const hideTime = type === 'success' ? 4000 : (type === 'info' ? 6000 : 8000);
            setTimeout(() => {
                notification.fadeOut(300, function () {
                    jQuery(this).remove();
                    // Clean up container if empty
                    if (notificationContainer.children().length === 0) {
                        notificationContainer.remove();
                    }
                });
            }, hideTime);
        }

        return notification;
    }

    // Simplified notification functions for common use cases
    function showSuccessNotification(title, message) {
        return showPaymentNotification('success', title, message, true);
    }

    function showErrorNotification(title, message) {
        return showPaymentNotification('error', title, message, false);
    }

    function showWarningNotification(title, message) {
        return showPaymentNotification('warning', title, message, false);
    }

    function showInfoNotification(title, message) {
        return showPaymentNotification('info', title, message, true);
    }

    // Function to refresh/reset the checkout page
    function refreshCheckoutForRetry() {
        // Remove processing states
        $('body').removeClass('processing').removeClass('woocommerce-checkout-processing');
        $('.checkout-button, #place_order').prop('disabled', false);

        // Reset processing flags
        window.jioPayProcessing = false;

        // Unblock WooCommerce checkout form (remove BlockUI overlay)
        const $form = $('form.checkout');
        const $orderReviewForm = $('form#order_review');
        
        // Try to unblock using WooCommerce's blockUI
        if ($.fn.unblock) {
            $form.unblock();
            $orderReviewForm.unblock();
            $('.woocommerce').unblock();
            $('body').unblock();
            $('.woocommerce-checkout').unblock();
            $('#payment').unblock();
        }
        
        // Also remove any blockUI overlay elements directly
        $('.blockUI, .blockOverlay').remove();
        
        // Remove any JioPay SDK overlay/iframe/modal that might be left behind
        // The SDK typically creates an iframe or div overlay
        $('[class*="jiopay"], [class*="jioPay"], [class*="jio-pay"]').each(function() {
            // Don't remove our notification elements
            if (!$(this).hasClass('jio-pay-notification') && 
                !$(this).hasClass('jio-pay-dismiss-btn') && 
                !$(this).hasClass('jio-pay-close-btn') &&
                !$(this).closest('.jio-pay-notification').length &&
                !$(this).is('#jio-pay-notification-container')) {
                $(this).remove();
            }
        });
        $('[id*="jiopay"], [id*="jioPay"]').remove();
        
        // Remove any SDK iframe overlays
        $('iframe[src*="jiopay"], iframe[src*="jioPay"], iframe[src*="jio-pay"]').remove();
        $('iframe[name*="jiopay"], iframe[name*="jioPay"]').remove();
        
        // Remove fixed/absolute positioned overlays that cover the screen
        $('div[style*="position: fixed"][style*="z-index"]').each(function() {
            var $el = $(this);
            var zIndex = parseInt($el.css('z-index'));
            // High z-index overlays from SDKs typically have z-index > 1000
            if (zIndex > 1000 && !$el.is('#jio-pay-notification-container') && !$el.closest('#jio-pay-notification-container').length) {
                // Check if it looks like an overlay (covers most of the screen)
                if ($el.width() > $(window).width() * 0.8 || $el.css('inset') === '0px') {
                    $el.remove();
                }
            }
        });
        
        // Remove any generic modal overlays
        $('.modal-backdrop, .overlay, [class*="-overlay"]').each(function() {
            // Don't remove our notification container
            if (!$(this).is('#jio-pay-notification-container') && !$(this).closest('#jio-pay-notification-container').length && !$(this).hasClass('jio-pay-notification')) {
                $(this).remove();
            }
        });

        // Clear any error states (but not our custom notifications)
        // Only remove WooCommerce error/message divs, not our jio-pay-notification
        // This allows error notifications to stay visible
        // $('.woocommerce-error, .woocommerce-message').remove();

        // Reset form if needed
        if ($form.length) {
            $form.find('input, select, textarea').removeClass('error');
        }

        // Clear order ID to allow new order creation
        window.currentOrderId = null;
        window.currentOrderKey = null;
        window.currentCustomerData = null;
    }

    function handlePaymentSuccess(paymentResult) {
        console.log('Payment success callback triggered');
        
        // Immediately mark payment as handled to prevent onClose from firing
        window.jioPaymentHandled = true;
        
        // Show loading while verifying
        $('body').addClass('processing');

        // If in test mode, show test completion message
        if (jioPayVars.use_test_data) {
            $('body').removeClass('processing');
            showSuccessNotification(
                'Test Payment Completed',
                'This was a test transaction using sample data. No real payment was processed.<br><strong>Payment ID:</strong> ' + (paymentResult.txnAuthID || paymentResult.payment_id || paymentResult.id || 'TEST_' + Date.now())
            );

            // For test mode, redirect to checkout instead of processing real order
            setTimeout(() => {
                window.location.href = window.location.href.split('?')[0] + '?test_payment=completed';
            }, 3000);
            return;
        }

        console.log('Verifying payment with order ID:', window.currentOrderId);

        // Call verify_payment endpoint
        $.post(jioPayVars.ajax_url, {
            action: 'jio_pay_verify_payment',
            nonce: jioPayVars.nonce,
            order_id: window.currentOrderId || 0,
            payment_data: {
                // Map Jio Pay response fields
                txnAuthID: paymentResult.txnAuthID,
                txnResponseCode: paymentResult.txnResponseCode,
                txnRespDescription: paymentResult.txnRespDescription,
                secureHash: paymentResult.secureHash,
                amount: paymentResult.amount,
                txnDateTime: paymentResult.txnDateTime,
                merchantTrId: paymentResult.merchantTrId,
                // Legacy fields for backward compatibility
                payment_id: paymentResult.txnAuthID || paymentResult.payment_id,
                transaction_id: paymentResult.merchantTrId || paymentResult.transaction_id,
                // Full response for debugging
                full_response: paymentResult
            }
        }).done(function (response) {
            console.log('Verification response:', response);
            $('body').removeClass('processing');

            if (response.success) {
                // Show success notification briefly before redirect
                showSuccessNotification(
                    'Payment Verified Successfully',
                    'Your payment has been processed. Redirecting to confirmation page...'
                );

                // Redirect after a brief moment
                setTimeout(() => {
                    window.location.href = response.data.redirect;
                }, 1500);
            } else {
                console.error('Payment verification failed:', response);
                refreshCheckoutForRetry();
                showErrorNotification(
                    'Payment Verification Failed',
                    (response.data?.message || 'Unknown error occurred') + '<br><br>Please try again or contact support if the issue persists.'
                );
            }
        }).fail(function (xhr, status, error) {
            console.error('AJAX request failed:', xhr, status, error);
            refreshCheckoutForRetry();
            showErrorNotification(
                'Connection Error',
                'Unable to verify payment due to connection issues.<br><strong>Status:</strong> ' + status + '<br><br>Please check your connection and try again.'
            );
        });
    }

    function handlePaymentFailure(error) {
        console.log('Payment failure callback triggered');
        
        // Immediately mark payment as handled to prevent onClose from firing
        window.jioPaymentHandled = true;
        
        refreshCheckoutForRetry();

        if (jioPayVars.use_test_data) {
            showWarningNotification(
                'Test Payment Failed',
                'This was a test transaction that failed.<br><strong>Error:</strong> ' + (error.message || 'Unknown error') + '<br><br>You can try again to test the payment flow.'
            );
        } else {
            showErrorNotification(
                'Payment Failed',
                'Your payment could not be processed.<br><strong>Error:</strong> ' + (error.message || 'Payment was declined') + '<br><br>Please check your payment details and try again.'
            );
        }
    }

    function handlePaymentCancel() {
        console.log('Payment cancel/close callback triggered, flag status:', window.jioPaymentHandled);
        
        // Add a small delay to allow success/failure callbacks to set the flag first
        setTimeout(function() {
            // Only handle if payment wasn't already processed (success/failure)
            if (window.jioPaymentHandled) {
                console.log('Payment already handled, ignoring onClose callback');
                return;
            }

            // Mark as handled to prevent multiple calls
            window.jioPaymentHandled = true;
            
            refreshCheckoutForRetry();

            if (jioPayVars.use_test_data) {
                showInfoNotification(
                    'Test Payment Cancelled',
                    'The test payment was cancelled by the user.<br><br>You can try again to continue testing the payment flow.'
                );
            } else {
                showInfoNotification(
                    'Payment Cancelled',
                    'Your payment was cancelled.<br><br>You can try again when you\'re ready to complete your order.'
                );

                setTimeout(() => {
                    // Remove beforeunload handlers to prevent "Changes may not be saved" dialog
                    removeBeforeUnloadHandlers();
                    
                    // Reload the page
                    window.location.reload();
                }, 2000);
            }
        }, 100); // Small delay to let success/failure callbacks execute first
    }
    
    // Helper function to remove beforeunload event handlers
    // This prevents the "Changes that you made may not be saved" dialog
    function removeBeforeUnloadHandlers() {
        // Remove jQuery beforeunload handlers
        $(window).off('beforeunload');
        
        // Also try to remove native handlers by setting onbeforeunload to null
        window.onbeforeunload = null;
        
        // WooCommerce specific - reset the form change flag
        if (typeof wc_checkout_form !== 'undefined' && wc_checkout_form) {
            wc_checkout_form.dirtyInput = false;
        }
        
        // Mark the form as not dirty/changed
        $('form.checkout').data('dirty', false);
        $('form.checkout').removeClass('dirty');
    }

    function showTestModeNotification() {
        // Create floating notification
        const notification = $('<div class="jio-pay-test-notification" style="position: fixed; top: 20px; right: 20px; background: #fff3cd; border: 2px solid #ffeaa7; color: #856404; padding: 15px 20px; border-radius: 8px; z-index: 10000; max-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-weight: 600;">⚠️ TEST MODE ACTIVE<br><small style="font-weight: normal;">Using sample data - No real payment will be processed</small></div>');

        $('body').append(notification);

        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.fadeOut(500, () => notification.remove());
        }, 5000);
    }

    function showTestCompletionNotification() {
        // Create completion notification
        const notification = $('<div class="jio-pay-test-completion" style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #d1ecf1; border: 2px solid #bee5eb; color: #0c5460; padding: 20px; border-radius: 8px; z-index: 10000; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-weight: 600; text-align: center;">✅ TEST PAYMENT COMPLETED<br><small style="font-weight: normal;">This was a demonstration using test data.<br>No real payment was processed.</small><br><button onclick="$(this).parent().fadeOut()" style="margin-top: 10px; padding: 5px 15px; background: #0c5460; color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button></div>');

        $('body').append(notification);

        // Auto remove after 10 seconds
        setTimeout(() => {
            notification.fadeOut(500, () => notification.remove());
        }, 10000);
    }
});