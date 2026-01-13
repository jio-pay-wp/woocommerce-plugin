(function() {
    'use strict';
    
    // console.log('=== Jio Pay Blocks Script Loading ===');
    // console.log('window.wc:', typeof window.wc);
    // console.log('window.wc.wcBlocksRegistry:', typeof window.wc?.wcBlocksRegistry);
    // console.log('window.wp:', typeof window.wp);
    
    if (!window.wc || !window.wc.wcBlocksRegistry) {
        console.error('WooCommerce Blocks Registry not available');
        return;
    }
    
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { createElement } = window.wp.element;
    const { __ } = window.wp.i18n;

    const jioPaySettings = window.wc.wcSettings.getSetting( 'jio_pay_data', {} );
    //console.log('Jio Pay Settings:', jioPaySettings);
    
    const jioPayLabel = jioPaySettings.title || __( 'Jio Pay', 'woo-jiopay' );

    const JioPayContent = () => {
        return createElement( 'div', {
            style: { 
                padding: '10px', 
                background: '#f9f9f9', 
                border: '1px solid #ddd', 
                margin: '10px 0',
                borderRadius: '4px'
            }
        }, jioPaySettings.description || __( 'You will be redirected to Jio Pay to complete your payment securely.', 'woo-jiopay' ) );
    };

    const JioPayLabel = () => {
        return createElement( 'span', { 
            style: { fontWeight: '500' } 
        }, jioPayLabel );
    };

    const JioPayPaymentMethod = {
        name: "jio_pay",
        label: createElement( JioPayLabel ),
        content: createElement( JioPayContent ),
        edit: createElement( JioPayContent ),
        canMakePayment: () => true,
        ariaLabel: jioPayLabel,
        supports: {
            features: jioPaySettings.supports || ['products']
        }
    };

    // Only register if not already registered
    if (window.wc && window.wc.wcBlocksRegistry && typeof window.wc.wcBlocksRegistry.registerPaymentMethod === 'function') {
        console.log('Registering Jio Pay payment method...');
        registerPaymentMethod( JioPayPaymentMethod );
        console.log('Jio Pay payment method registered successfully');
    } else {
        console.error('Cannot register Jio Pay payment method - registerPaymentMethod not available');
    }
})();
