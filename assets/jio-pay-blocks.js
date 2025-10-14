const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement } = window.wp.element;
const { __ } = window.wp.i18n;

const settings = window.wc.wcSettings.getSetting( 'jio_pay_data', {} );

const defaultLabel = __( 'Jio Pay', 'jio-pay-gateway' );
const label = settings.title || defaultLabel;

const Content = () => {
    return createElement( 'div', {
        style: { padding: '10px', background: '#f9f9f9', border: '1px solid #ddd', margin: '10px 0' }
    }, settings.description || __( 'You will be redirected to Jio Pay to complete your payment securely.', 'jio-pay-gateway' ) );
};

const Label = () => {
    return createElement( 'span', null, label );
};

const JioPayPaymentMethod = {
    name: "jio_pay",
    label: createElement( Label ),
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || []
    }
};

registerPaymentMethod( JioPayPaymentMethod );
