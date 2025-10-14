jQuery(document).ready(function($) {
    
    // Handle place order button clicks for block checkout
    $(document).on('click', '#place_order, .wc-block-components-checkout-place-order-button, [data-block-name="woocommerce/checkout-actions-block"] button', function(e) {
        const selectedPaymentMethod = $('input[name="payment_method"]:checked').val() || 
                                    $('input[name="radio-control-wc-payment-method-options"]:checked').val();
        
        if (selectedPaymentMethod === 'jio_pay') {
            e.preventDefault();
            e.stopImmediatePropagation();
            initiateJioPayment();
            return false;
        }
    });
    
    // Handle form submission for classic checkout
    $(document).on('submit', 'form.checkout, form#order_review', function(e) {
        const selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (selectedPaymentMethod === 'jio_pay') {
            e.preventDefault();
            e.stopImmediatePropagation();
            initiateJioPayment();
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
    
    function initiateJioPayment() {
        // Show loading
        $('body').addClass('processing');
        $('.checkout-button').prop('disabled', true);
        
        try {
            if (typeof jioPaySDK !== 'undefined') {
                const paymentOptions = {
                    amount: parseFloat(jioPayVars.amount).toFixed(2) || "1.00",
                    env: jioPayVars.environment || "uat",
                    merchantId: jioPayVars.merchant_id || "JP2000000000031",
                    aggId: "",
                    customerEmailID: jioPayVars.customer_email || "test@jm.com",
                    secretKey: "abc",
                    email: jioPayVars.customer_email || "test@gmail.in",
                    userName: jioPayVars.customer_name || "Test User",
                    merchantName: jioPayVars.merchant_name || "Reliance",
                    theme: { color: "#E39B2B" },
                    merchantTrId: Math.floor(1000000000 + Math.random() * 9000000000).toString(),
                    onSuccess: handlePaymentSuccess,
                    onFailure: handlePaymentFailure,
                    onClose: handlePaymentCancel
                };
                
                const jioPay = new jioPaySDK(paymentOptions);
                jioPay.open();
                
            } else {
                alert('Payment gateway not available. Please try again.');
            }
            
        } catch (error) {
            console.error('Error initializing Jio Pay:', error);
            alert('Payment initialization failed: ' + error.message);
        } finally {
            $('body').removeClass('processing');
            $('.checkout-button').prop('disabled', false);
        }
    }
    
    function initiateJioPaymentWithOrderId(orderId) {
        window.currentOrderId = orderId;
        initiateJioPayment();
    }
    
    function handlePaymentSuccess(paymentResult) {
      console.log('Payment successful:', paymentResult);
      
      // Show loading while verifying
      $('body').addClass('processing');
      
      // Call verify_payment endpoint
      $.post(jioPayVars.ajax_url, {
          action: 'jio_pay_verify_payment',
          nonce: jioPayVars.nonce,
          order_id: window.currentOrderId,
          payment_data: {
              payment_id: paymentResult.payment_id || paymentResult.id,
              transaction_id: paymentResult.transaction_id,
              amount: paymentResult.amount
          }
      }).done(function(response) {
          $('body').removeClass('processing');
          
          if (response.success) {
              // Option 1: Use server-provided redirect (current - redirects to specific order thank you page)
              window.location.href = response.data.redirect;
              
              // Option 2: Use generic thank you page (uncomment if you prefer this)
              // window.location.href = jioPayVars.return_url;
              
              // Option 3: Use WooCommerce checkout page with success message (uncomment if you prefer this)
              // window.location.href = jioPayVars.return_url + '?payment=success';
          } else {
              alert('Payment verification failed: ' + response.data.message);
          }
      }).fail(function() {
          $('body').removeClass('processing');
          alert('Payment verification failed. Please contact support.');
      });
    }
    
    function handlePaymentFailure(error) {
        alert('Payment failed. Please try again.');
    }
    
    function handlePaymentCancel() {
        alert('Payment was cancelled.');
    }
});