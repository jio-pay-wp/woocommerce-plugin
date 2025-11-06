jQuery(document).ready(function($) {
    
    // Test AJAX connection
    // console.log('Testing AJAX connection...');
    // $.post(jioPayVars.ajax_url, {
    //     action: 'jio_pay_test',
    //     test_data: 'hello'
    // }).done(function(response) {
    //     console.log('AJAX test successful:', response);
    // }).fail(function(xhr, status, error) {
    //     console.error('AJAX test failed:', xhr, status, error);
    // });
    
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
    
    // Handle place order button clicks for block checkout
    $(document).on('click', '#place_order, .wc-block-components-checkout-place-order-button, [data-block-name="woocommerce/checkout-actions-block"] button', function(e) {
        const selectedPaymentMethod = $('input[name="payment_method"]:checked').val() || 
                                    $('input[name="radio-control-wc-payment-method-options"]:checked').val();
        
        if (selectedPaymentMethod === 'jio_pay') {
            e.preventDefault();
            e.stopImmediatePropagation();
            
            // For classic checkout, we need to submit the form first to create the order
            if ($('form.checkout').length && !window.currentOrderId) {
                processClassicCheckout();
            } else {
                initiateJioPayment();
            }
            return false;
        }
    });
    
    // Handle form submission for classic checkout
    $(document).on('submit', 'form.checkout, form#order_review', function(e) {
        const selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (selectedPaymentMethod === 'jio_pay') {
            e.preventDefault();
            e.stopImmediatePropagation();
            processClassicCheckout();
            return false;
        }
    });
    
    // Monitor for WooCommerce REST API responses (block checkout)
    let originalFetch = window.fetch;
    window.fetch = function(...args) {
        return originalFetch.apply(this, args).then(response => {
            if (args[0] && args[0].includes && args[0].includes('/wc/store/v1/checkout')) {
                response.clone().json().then(data => {
                    if (data.payment_method === 'jio_pay' && data.status === 'pending') {
                        // Store order details for success redirect
                        window.currentOrderId = data.order_id;
                        window.currentOrderKey = data.order_key;
                        setTimeout(() => {
                            initiateJioPaymentWithOrderId(data.order_id);
                        }, 500);
                    }
                }).catch(err => console.log('Could not parse checkout response:', err));
            }
            return response;
        });
    };
    
    function processClassicCheckout() {
        // Show loading
        $('body').addClass('processing');
        $('.checkout-button').prop('disabled', true);
        
        // Get form data
        const $form = $('form.checkout');
        const formData = $form.serialize();
        
        // Submit the checkout form to create the order
        $.post($form.attr('action') || wc_checkout_params.checkout_url, formData)
            .done(function(response) {
                if (response.result === 'success') {
                    // Order created successfully, extract order ID from redirect URL
                    const redirectUrl = response.redirect;
                    const orderIdMatch = redirectUrl.match(/order-received\/(\d+)/);
                    
                    if (orderIdMatch) {
                        window.currentOrderId = parseInt(orderIdMatch[1]);
                        console.log('Order created with ID:', window.currentOrderId);
                        
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
                        $form.prepend(response.messages);
                        $('html, body').animate({
                            scrollTop: $form.offset().top - 100
                        }, 1000);
                    } else {
                        showErrorNotification(
                            'Checkout Error',
                            'There was an error processing your checkout.<br><br>Please check your details and try again.'
                        );
                    }
                }
            })
            .fail(function() {
                refreshCheckoutForRetry();
                showErrorNotification(
                    'Connection Error',
                    'Unable to process checkout due to connection issues.<br><br>Please check your connection and try again.'
                );
            });
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
                const paymentOptions = {
                    amount: parseFloat(jioPayVars.amount).toFixed(2) || "1.00",
                    env: jioPayVars.environment || "uat",
                    merchantId: jioPayVars.merchant_id || "JP2000000000031",
                    aggId: jioPayVars.agregator_id || "",
                    customerEmailID: jioPayVars.customer_email || "test@jm.com",
                    email: jioPayVars.customer_email || "test@gmail.in",
                    userName: jioPayVars.customer_name || "Test User",
                    merchantName: jioPayVars.merchant_name || "Reliance",
                    allowedPaymentTypes: Array.isArray(jioPayVars.allowed_payment_types) ? jioPayVars.allowed_payment_types : (jioPayVars.allowed_payment_types ? jioPayVars.allowed_payment_types.split(',') : ["NB","UPI_QR","UPI_VPA","CARD"]),
                    theme: jioPayVars.theme || { color: "#E39B2B" },
                    timeout: jioPayVars.timeout || 30000,
                    secretKey: jioPayVars.secret_key || "abc",
                    merchantTrId: Math.floor(1000000000 + Math.random() * 9000000000).toString(),
                    onSuccess: handlePaymentSuccess,
                    onFailure: handlePaymentFailure,
                    onClose: handlePaymentCancel
                };
                
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
        $('.jio-pay-notification').remove();
        
        // Choose colors and icons based on type
        let bgColor, borderColor, textColor, icon;
        switch(type) {
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
        let notificationContainer = $('#jio-pay-notification-container');
        if (!notificationContainer.length) {
            // Create a fixed container that doesn't affect layout
            notificationContainer = $(`
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
            $('body').append(notificationContainer);
        }
        
        // Create notification HTML
        const notification = $(`
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
                            '<button onclick="$(this).closest(\'.jio-pay-notification\').fadeOut(300, function() { $(this).remove(); })" style="margin-top: 10px; padding: 6px 12px; background: ' + textColor + '; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.2s;">Dismiss</button>' : 
                            ''
                        }
                    </div>
                    <button onclick="$(this).closest(\'.jio-pay-notification\').fadeOut(300, function() { $(this).remove(); })" style="
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
                    " onmouseover="this.style.opacity='1'; this.style.backgroundColor='rgba(0,0,0,0.1)'" onmouseout="this.style.opacity='0.7'; this.style.backgroundColor='transparent'">&times;</button>
                </div>
            </div>
        `);
        
        // Add CSS animation if not already added
        if (!$('#jio-pay-animations').length) {
            $('<style id="jio-pay-animations">@keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }</style>').appendTo('head');
        }
        
        // Add notification to container
        notificationContainer.append(notification);
        
        // Auto-hide after specified time
        if (autoHide) {
            const hideTime = type === 'success' ? 4000 : (type === 'info' ? 6000 : 8000);
            setTimeout(() => {
                notification.fadeOut(300, function() { 
                    $(this).remove();
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
        $('body').removeClass('processing');
        $('.checkout-button, #place_order').prop('disabled', false);
        
        // Clear any error states
        $('.woocommerce-error, .woocommerce-message').remove();
        
        // Reset form if needed
        const $form = $('form.checkout');
        if ($form.length) {
            $form.find('input, select, textarea').removeClass('error');
        }
        
        // Clear order ID to allow new order creation
        window.currentOrderId = null;
        window.currentOrderKey = null;
    }

    function handlePaymentSuccess(paymentResult) {
      //console.log('Payment successful:', paymentResult);
      
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
      }).done(function(response) {
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
      }).fail(function(xhr, status, error) {
          console.error('AJAX request failed:', xhr, status, error);
          refreshCheckoutForRetry();
          showErrorNotification(
              'Connection Error',
              'Unable to verify payment due to connection issues.<br><strong>Status:</strong> ' + status + '<br><br>Please check your connection and try again.'
          );
      });
    }
    
    function handlePaymentFailure(error) {
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
        }
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