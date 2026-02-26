<?php
/**
 * Test Refund Payload - View the payload being sent to Jio Pay API
 * 
 * Usage: /test-refund-payload.php?order_id=162
 */

// Load WordPress
require_once('wp-load.php');

// Check if user is logged in
if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
    die('Access denied. You must be logged in as an admin.');
}

$order_id = intval($_GET['order_id'] ?? 0);

if (!$order_id) {
    die('Please provide order_id parameter: ?order_id=162');
}

// Get order
$order = wc_get_order($order_id);

if (!$order) {
    die('Order not found');
}

// Load refund class
if (!class_exists('WC_Jio_Pay_Refund')) {
    require_once(dirname(__FILE__) . '/wp-content/plugins/woo-jiopay/includes/class-jio-pay-refund.php');
}

// Get gateway settings
$gateway_class = 'WC_Jio_Pay_Gateway';
if (!class_exists($gateway_class)) {
    require_once(dirname(__FILE__) . '/wp-content/plugins/woo-jiopay/includes/class-jio-pay-gateway.php');
}

$gateway = new WC_Jio_Pay_Gateway();

// Build the refund payload manually to show what would be sent
$merchant_txn_no = $order->get_meta('_jio_pay_merchant_tr_id');
$auth_id = $order->get_transaction_id();
$amount = $order->get_total();

$refund_payload = [
    'merchantId' => $gateway->merchant_id,
    'merchantTxnNo' => $merchant_txn_no,
    'originalTxnNo' => $auth_id,
    'transactionType' => 'REFUND',
    'amount' => $amount
];

// Show details
?>
<!DOCTYPE html>
<html>
<head>
    <title>Refund Payload Debug - Order #<?php echo $order_id; ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 10px; }
        .info-box { background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa; margin: 15px 0; }
        .info-label { font-weight: bold; color: #555; }
        .payload-box { background: #f0f0f0; padding: 15px; border: 1px solid #ddd; border-radius: 3px; font-family: monospace; margin: 15px 0; overflow-x: auto; }
        .endpoint-box { background: #fffbcc; padding: 15px; border-left: 4px solid #ffb81c; margin: 15px 0; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; font-weight: bold; }
        tr:hover { background: #f9f9f9; }
        .success { color: #4caf50; }
        .error { color: #f44336; }
        .note { color: #ff9800; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Refund Payload Debug - Order #<?php echo $order_id; ?></h1>

        <div class="info-box">
            <p><span class="info-label">Order Status:</span> <?php echo $order->get_status(); ?></p>
            <p><span class="info-label">Payment Method:</span> <?php echo $order->get_payment_method(); ?></p>
            <p><span class="info-label">Order Total:</span> <?php echo wc_price($order->get_total()); ?></p>
        </div>

        <div class="info-box">
            <p><span class="info-label">Environment:</span> <?php echo strtoupper($gateway->environment); ?></p>
            <p><span class="info-label">Merchant ID:</span> <code><?php echo esc_html($gateway->merchant_id); ?></code></p>
            <p><span class="info-label">Merchant Transaction ID:</span> <code><?php echo esc_html($merchant_txn_no ?? 'NOT FOUND'); ?></code></p>
            <p><span class="info-label">Authorization ID (Auth ID):</span> <code><?php echo esc_html($auth_id ?? 'NOT FOUND'); ?></code></p>
        </div>

        <div class="endpoint-box">
            <strong>API Endpoint:</strong><br>
            <code style="word-break: break-all;">
                <?php echo $gateway->environment === 'prod' 
                    ? 'https://jiopay.co.in/tsp/pg/api/command'
                    : 'https://uat.jiopay.co.in/tsp/pg/api/command'; ?>
            </code>
        </div>

        <h2>Refund Payload (POST Data)</h2>
        
        <div class="payload-box">
            <strong>Raw POST Body:</strong><br>
            <?php echo urldecode(http_build_query($refund_payload)); ?>
        </div>

        <h2>Payload Parameters</h2>
        <table>
            <tr>
                <th>Parameter Name</th>
                <th>Value</th>
                <th>Notes</th>
            </tr>
            <tr>
                <td><code>merchantId</code></td>
                <td><code><?php echo esc_html($refund_payload['merchantId']); ?></code></td>
                <td>Your Jio Pay merchant ID</td>
            </tr>
            <tr>
                <td><code>merchantTxnNo</code></td>
                <td><code><?php echo esc_html($refund_payload['merchantTxnNo']); ?></code></td>
                <td>Original merchant transaction number (what you sent initially)</td>
            </tr>
            <tr>
                <td><code>originalTxnNo</code></td>
                <td><code><?php echo esc_html($refund_payload['originalTxnNo']); ?></code></td>
                <td>Authorization ID from the payment response (Auth ID)</td>
            </tr>
            <tr>
                <td><code>transactionType</code></td>
                <td><code><?php echo esc_html($refund_payload['transactionType']); ?></code></td>
                <td>Must be "REFUND"</td>
            </tr>
            <tr>
                <td><code>amount</code></td>
                <td><code><?php echo esc_html($refund_payload['amount']); ?></code></td>
                <td>Refund amount in rupees</td>
            </tr>
        </table>

        <h2>CURL Command to Test API</h2>
        <div class="payload-box">
            curl -X POST \<br>
            &nbsp;&nbsp;'<?php echo $gateway->environment === 'prod' 
                ? 'https://jiopay.co.in/tsp/pg/api/command'
                : 'https://uat.jiopay.co.in/tsp/pg/api/command'; ?>' \<br>
            &nbsp;&nbsp;-H 'Content-Type: application/x-www-form-urlencoded' \<br>
            &nbsp;&nbsp;-d '<?php echo urldecode(http_build_query($refund_payload)); ?>'
        </div>

        <div class="info-box" style="border-left-color: #ff9800;">
            <p><span class="note">⚠️ Common P1006 Error Causes:</span></p>
            <ul>
                <li>Transaction is more than 90 days old</li>
                <li>Transaction was not successful (wrong status)</li>
                <li>Original transaction number is incorrect</li>
                <li>Amount doesn't match original transaction</li>
                <li>Merchant ID or API credentials are wrong</li>
            </ul>
        </div>

        <div class="info-box">
            <p><strong>Debug Log Location:</strong></p>
            <p><code>/wp-content/plugins/woo-jiopay/logs/refund-audit.log</code></p>
            <p>Check this file for the actual API request/response details.</p>
        </div>
    </div>
</body>
</html>
