<?php
/**
 * WooCommerce Jio Pay Refund Handler
 * 
 * Handles refund processing through Jio Pay API
 * Includes API calls, logging, and audit trail
 */

if (!defined('ABSPATH')) exit;

class WC_Jio_Pay_Refund {
    /**
     * Generate secureHash for JioPay API (HMAC-SHA256 of sorted values)
     *
     * @param array $data Payload data (associative array)
     * @param string $secret_key Secret key for HMAC
     * @return string Hex-encoded HMAC-SHA256
     */
    public function generate_secure_hash($data, $secret_key) {
        // Sort keys alphabetically (excluding secureHash)
        unset($data['secureHash']);
        ksort($data);
        // Concatenate values in order
        $value_string = '';
        foreach ($data as $key => $value) {
            $value_string .= $value;
        }
        // Generate HMAC-SHA256
        return hash_hmac('sha256', $value_string, $secret_key);
    }

    private $merchant_id;
    private $secret_key;
    private $environment;
    private $api_endpoint;
    private $log_file;

    public function __construct($merchant_id, $secret_key, $environment = 'uat') {
        $this->merchant_id = $merchant_id;
        $this->secret_key = $secret_key;
        $this->environment = $environment;
        
        // Set API endpoint based on environment
        $this->api_endpoint = $environment === 'prod' 
            ? 'https://jiopay.co.in/tsp/pg/api/command'
            : 'https://uat.jiopay.co.in/tsp/pg/api/command';
        
        // Set log file path
        $this->log_file = JIO_PAY_PLUGIN_DIR . 'logs/refund-audit.log';
        
        // Ensure logs directory exists
        $this->ensure_log_directory();
    }

    /**
     * Ensure logs directory exists
     */
    private function ensure_log_directory() {
        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }
    }

    /**
     * Log refund transaction for audit trail
     * 
     * @param int $order_id WooCommerce Order ID
     * @param string $status Status of refund (pending, success, failed)
     * @param array $data Additional data to log
     */
    private function log_refund($order_id, $status, $data = []) {
        $timestamp = current_time('mysql');
        $user_id = get_current_user_id();
        $user = get_user_by('id', $user_id);
        $user_email = $user ? $user->user_email : 'unknown';
        
        $log_entry = [
            'timestamp' => $timestamp,
            'order_id' => $order_id,
            'user_id' => $user_id,
            'user_email' => $user_email,
            'status' => $status,
            'environment' => $this->environment,
            'merchant_id' => $this->merchant_id,
            'data' => $data
        ];

        $log_message = sprintf(
            "[%s] OrderID: %d | User: %s (%d) | Status: %s | Data: %s\n",
            $timestamp,
            $order_id,
            $user_email,
            $user_id,
            $status,
            json_encode($data)
        );

        // Write to log file
        error_log($log_message, 3, $this->log_file);

        // Also store in WordPress transient for dashboard display
        $refund_logs = get_transient('jio_pay_refund_logs');
        if (!is_array($refund_logs)) {
            $refund_logs = [];
        }

        // Keep only last 100 refund logs
        if (count($refund_logs) >= 100) {
            array_shift($refund_logs);
        }

        $refund_logs[] = $log_entry;
        set_transient('jio_pay_refund_logs', $refund_logs, DAY_IN_SECONDS);

        // Store in order meta for order-specific audit trail
        $order = wc_get_order($order_id);
        if ($order) {
            $refund_history = $order->get_meta('_jio_pay_refund_history');
            if (!is_array($refund_history)) {
                $refund_history = [];
            }
            $refund_history[] = $log_entry;
            $order->update_meta_data('_jio_pay_refund_history', $refund_history);
            $order->save();
        }
    }

    /**
     * Process full order refund through Jio Pay API
     * 
     * @param int $order_id WooCommerce Order ID
     * @return array Result array with status and message
     */
    public function process_full_refund($order_id) {
        $order = wc_get_order($order_id);
        
        // Validate order exists
        if (!$order) {
            $this->log_refund($order_id, 'failed', [
                'error' => 'Order not found',
                'error_code' => 'order_not_found'
            ]);
            return [
                'success' => false,
                'message' => 'Order not found',
                'error_code' => 'order_not_found'
            ];
        }

        // Check if order payment method is Jio Pay
        if ($order->get_payment_method() !== 'jio_pay') {
            $this->log_refund($order_id, 'failed', [
                'error' => 'Order not paid with Jio Pay',
                'payment_method' => $order->get_payment_method()
            ]);
            return [
                'success' => false,
                'message' => 'This order was not paid with Jio Pay',
                'error_code' => 'invalid_payment_method'
            ];
        }

        // Check if order is already refunded
        if ($order->get_status() === 'refunded') {
            $this->log_refund($order_id, 'failed', [
                'error' => 'Order already refunded',
                'order_status' => $order->get_status()
            ]);
            return [
                'success' => false,
                'message' => 'Order is already refunded',
                'error_code' => 'already_refunded'
            ];
        }


        // Get original merchant transaction number (from order meta)
        $original_merchant_txn_no = $order->get_meta('_jio_pay_merchant_tr_id');
        if (!$original_merchant_txn_no) {
            $this->log_refund($order_id, 'failed', [
                'error' => 'Original merchant transaction ID not found',
                'order_meta' => $order->get_meta_data()
            ]);
            return [
                'success' => false,
                'message' => 'Original merchant transaction ID not found in order',
                'error_code' => 'merchant_txn_not_found'
            ];
        }

        // Generate a new unique merchantTxnNo for this refund (must be integer)
        $refund_merchant_txn_no = (string) (time() . rand(100, 999));

        // Get the authorization ID (Auth ID from payment response - stored as transaction ID)
        $auth_id = $order->get_transaction_id();
        if (!$auth_id) {
            $this->log_refund($order_id, 'failed', [
                'error' => 'Authorization ID (transaction ID) not found',
                'original_merchant_txn_no' => $original_merchant_txn_no
            ]);
            return [
                'success' => false,
                'message' => 'Authorization ID not found in order',
                'error_code' => 'auth_id_not_found'
            ];
        }

        $amount = $order->get_total();

        // Log refund attempt (log both refund merchantTxnNo and originalTxnNo)
        $this->log_refund($order_id, 'pending', [
            'refund_merchant_txn_no' => $refund_merchant_txn_no,
            'original_merchant_txn_no' => $original_merchant_txn_no,
            'auth_id' => $auth_id,
            'refund_amount' => $amount,
            'order_status' => $order->get_status()
        ]);

        // Prepare refund API request (as associative array)
        $refund_data = [
            'merchantId' => $this->merchant_id,
            'merchantTxnNo' => $refund_merchant_txn_no,
            'originalTxnNo' => $original_merchant_txn_no,
            'transactionType' => 'REFUND',
            'amount' => $amount
        ];

        // Generate secureHash (sorted, excluding secureHash)
        $refund_data['secureHash'] = $this->generate_secure_hash($refund_data, $this->secret_key);

        // Re-sort all keys (including secureHash)
        ksort($refund_data);

        // Make API request
        $response = $this->call_refund_api($refund_data);

        // Handle API response
        if ($response['success']) {
            // Store refund merchant txn no in order meta
            $order->update_meta_data('_jio_pay_refund_merchant_txn_no', $refund_merchant_txn_no);
            $order->save();

            // Add order note when refund is initiated
            $order->add_order_note(sprintf(
                __('Refund initiated via JioPay. Refund Transaction ID: %s', 'woo-jiopay'),
                $refund_merchant_txn_no
            ));

            // Log refund initiation (accepted by API)
            $this->log_refund($order_id, 'accepted', [
                'refund_merchant_txn_no' => $refund_merchant_txn_no,
                'original_merchant_txn_no' => $original_merchant_txn_no,
                'refund_amount' => $amount,
                'refund_id' => $response['refund_id'],
                'api_response' => $response
            ]);

            // Now check the refund status using the refund merchant txn no
            $status_result = $this->check_refund_status($refund_merchant_txn_no);

            if ($status_result['success']) {
                // Refund confirmed successful
                $order->update_status('refunded', sprintf(
                    __('Full order refund processed. Refund Amount: %s. Jio Pay Refund ID: %s', 'woo-jiopay'),
                    wc_price($amount),
                    $status_result['refund_id']
                ));

                // Store refund response in order meta
                $order->update_meta_data('_jio_pay_refund_id', $status_result['refund_id']);
                $order->update_meta_data('_jio_pay_refund_response', $status_result);
                $order->update_meta_data('_jio_pay_refund_amount', $amount);
                $order->update_meta_data('_jio_pay_refund_date', current_time('mysql'));
                $order->save();

                // Add order note when refund is confirmed successful
                $order->add_order_note(sprintf(
                    __('Refund confirmed successful. Refund Txn ID: %s | Jio Pay Refund ID: %s', 'woo-jiopay'),
                    $refund_merchant_txn_no,
                    $status_result['refund_id']
                ));

                // Log successful refund
                $this->log_refund($order_id, 'success', [
                    'refund_merchant_txn_no' => $refund_merchant_txn_no,
                    'original_merchant_txn_no' => $original_merchant_txn_no,
                    'refund_amount' => $amount,
                    'refund_id' => $status_result['refund_id'],
                    'status_check_response' => $status_result
                ]);

                return [
                    'success' => true,
                    'message' => 'Refund processed successfully',
                    'refund_id' => $status_result['refund_id'],
                    'refund_merchant_txn_no' => $refund_merchant_txn_no,
                    'amount' => $amount
                ];
            } else {
                // Status check failed
                $order->update_status('on-hold', __('Refund accepted but status check pending', 'woo-jiopay'));
                $order->save();

                $this->log_refund($order_id, 'pending', [
                    'refund_merchant_txn_no' => $refund_merchant_txn_no,
                    'original_merchant_txn_no' => $original_merchant_txn_no,
                    'refund_amount' => $amount,
                    'status_check_error' => $status_result['message']
                ]);

                return [
                    'success' => false,
                    'message' => 'Refund accepted but status pending: ' . $status_result['message'],
                    'refund_merchant_txn_no' => $refund_merchant_txn_no,
                    'error_code' => 'status_pending'
                ];
            }
        } else {
            // Log failed refund
            $this->log_refund($order_id, 'failed', [
                'refund_merchant_txn_no' => $refund_merchant_txn_no,
                'original_merchant_txn_no' => $original_merchant_txn_no,
                'refund_amount' => $amount,
                'error' => $response['message'],
                'error_code' => $response['error_code'],
                'api_response' => $response
            ]);

            return [
                'success' => false,
                'message' => 'Refund failed: ' . $response['message'],
                'error_code' => $response['error_code'],
                'refund_merchant_txn_no' => $refund_merchant_txn_no
            ];
        }
    }

    /**
     * Check refund status by merchant transaction number
     * 
     * @param string $refund_merchant_txn_no Merchant transaction number from refund request
     * @return array Status result
     */
    public function check_refund_status($refund_merchant_txn_no) {
        try {
            error_log('=== Checking Refund Status ===');
            error_log('Refund MerchantTxnNo: ' . $refund_merchant_txn_no);

            // Prepare status payload (no secureHash needed for STATUS)
            $payload = [
                'merchantId' => $this->merchant_id,
                'originalTxnNo' => $refund_merchant_txn_no,
                'transactionType' => 'STATUS'
            ];
            
            // Build URL-encoded string
            $post_data = '';
            foreach ($payload as $key => $value) {
                $post_data .= urlencode($key) . '=' . urlencode($value) . '&';
            }
            $post_data = rtrim($post_data, '&');

            // Make POST request
            $response = wp_remote_post($this->api_endpoint, [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $post_data,
                'timeout' => 30,
                'sslverify' => true,
            ]);

            if (is_wp_error($response)) {
                error_log('Status Check Error: ' . $response->get_error_message());
                return [
                    'success' => false,
                    'message' => 'Status check failed: ' . $response->get_error_message(),
                    'error_code' => 'status_check_failed'
                ];
            }

            $response_body = wp_remote_retrieve_body($response);
            $parsed_response = $this->parse_api_response($response_body);

            error_log('Status Check Response: ' . json_encode($parsed_response));

            return $parsed_response;

        } catch (Exception $e) {
            error_log('Exception in check_refund_status: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Status check error: ' . $e->getMessage(),
                'error_code' => 'status_exception'
            ];
        }
    }

    /**
     * Call Jio Pay refund API
     * 
     * @param array $refund_data Refund request data
     * @return array API response
     */
    private function call_refund_api($refund_data) {
        try {
            error_log('=== Jio Pay Refund API Call Started ===');
            error_log('API Endpoint: ' . $this->api_endpoint);
            error_log('Refund Data: ' . json_encode($refund_data));

            // Prepare POST data (URL-encoded, sorted order)
            $post_data = '';
            foreach ($refund_data as $key => $value) {
                $post_data .= urlencode($key) . '=' . urlencode($value) . '&';
            }
            $post_data = rtrim($post_data, '&');
            error_log('POST Body: ' . $post_data);

            // Also log to audit file for easy access
            $audit_log = "=== Refund API Payload ===\n";
            $audit_log .= "Endpoint: " . $this->api_endpoint . "\n";
            $audit_log .= "Timestamp: " . current_time('mysql') . "\n";
            $audit_log .= "POST Data: " . $post_data . "\n";
            $audit_log .= "Formatted Parameters:\n";
            foreach ($refund_data as $key => $value) {
                $audit_log .= "  " . $key . " = " . $value . "\n";
            }
            $audit_log .= "\n";
            error_log($audit_log, 3, $this->log_file);

            // Make POST request
            $response = wp_remote_post($this->api_endpoint, [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $post_data,
                'timeout' => 30,
                'sslverify' => true,
            ]);

            // Check for WP errors
            if (is_wp_error($response)) {
                error_log('WP Error: ' . $response->get_error_message());
                error_log('WP Error Code: ' . $response->get_error_code());
                return [
                    'success' => false,
                    'message' => 'API request failed: ' . $response->get_error_message(),
                    'error_code' => 'api_request_failed',
                    'wp_error' => $response->get_error_code()
                ];
            }

            // Parse response
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $response_headers = wp_remote_retrieve_headers($response);

            error_log('Response Code: ' . $response_code);
            error_log('Response Headers: ' . json_encode($response_headers));
            error_log('Response Body: ' . $response_body);

            // Check HTTP status code
            if ($response_code !== 200 && $response_code !== 201) {
                // But if it's a true HTTP error (not 200/201), log it
                if ($response_code !== 200 && $response_code !== 201) {
                    // But only return error if status is truly bad
                    error_log('API HTTP Error: ' . $response_code);
                }
            }

            // Parse response (could be XML or JSON)
            $parsed_response = $this->parse_api_response($response_body);

            error_log('Parsed Response: ' . json_encode($parsed_response));

            // Check if refund was successful
            if (isset($parsed_response['success']) && $parsed_response['success']) {
                return [
                    'success' => true,
                    'message' => 'Refund processed successfully',
                    'refund_id' => $parsed_response['refund_id'] ?? $parsed_response['txnAuthID'] ?? '',
                    'response_code' => $parsed_response['response_code'] ?? '',
                    'raw_response' => $parsed_response,
                    'payload_sent' => $refund_data
                ];
            } else {
                $error_code = $parsed_response['error_code'] ?? 'unknown_error';
                $error_message = $parsed_response['message'] ?? 'Refund processing failed';
                $detailed_error = self::decode_error_code($error_code);
                
                return [
                    'success' => false,
                    'message' => $error_message . ' (' . $detailed_error . ')',
                    'error_code' => $error_code,
                    'detailed_error' => $detailed_error,
                    'raw_response' => $parsed_response,
                    'payload_sent' => $refund_data
                ];
            }

        } catch (Exception $e) {
            error_log('Exception in call_refund_api: ' . $e->getMessage());
            error_log('Exception Trace: ' . $e->getTraceAsString());
            
            return [
                'success' => false,
                'message' => 'Refund API error: ' . $e->getMessage(),
                'error_code' => 'api_exception'
            ];
        }
    }

    /**
     * Parse API response (handles both XML and JSON)
     * 
     * @param string $response_body Raw API response
     * @return array Parsed response data
     */
    private function parse_api_response($response_body) {
        // Try JSON first
        $json_response = json_decode($response_body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json_response)) {
            return $this->normalize_json_response($json_response);
        }

        // Try XML
        $xml_response = @simplexml_load_string($response_body);
        if ($xml_response) {
            return $this->normalize_xml_response($xml_response);
        }

        // Try parsing as URL-encoded response
        $parsed = [];
        parse_str($response_body, $parsed);
        if (!empty($parsed)) {
            return $this->normalize_url_encoded_response($parsed);
        }

        // Return raw response
        return [
            'success' => false,
            'message' => 'Could not parse API response',
            'error_code' => 'parse_error',
            'raw_body' => $response_body
        ];
    }

    /**
     * Normalize JSON response from API
     * 
     * @param array $response JSON response
     * @return array Normalized response
     */
    private function normalize_json_response($response) {
        // Check for success indicators
        $success = false;
        if (isset($response['success']) && $response['success'] === true) {
            $success = true;
        } elseif (isset($response['txnResponseCode']) && $response['txnResponseCode'] === '0000') {
            $success = true;
        } elseif (isset($response['responseCode']) && ($response['responseCode'] === '0000' || $response['responseCode'] === '000' || $response['responseCode'] === 'R1000')) {
            // R1000 = Request processed (accepted for processing) - success for refund requests
            $success = true;
        }

        $refund_id = $response['txnAuthID'] ?? $response['txnID'] ?? $response['refund_id'] ?? '';
        
        return [
            'success' => $success,
            'message' => $response['txnRespDescription'] ?? $response['respDescription'] ?? $response['message'] ?? ($success ? 'Refund successful' : 'Refund failed'),
            'error_code' => $response['txnResponseCode'] ?? $response['responseCode'] ?? '',
            'refund_id' => $refund_id,
            'response_code' => $response['txnResponseCode'] ?? $response['responseCode'] ?? '',
            'merchant_txn_no' => $response['merchantTxnNo'] ?? '',
            'raw_response' => $response
        ];
    }

    /**
     * Normalize XML response from API
     * 
     * @param SimpleXMLElement $response XML response
     * @return array Normalized response
     */
    private function normalize_xml_response($response) {
        $array = json_decode(json_encode($response), true);
        return $this->normalize_json_response($array);
    }

    /**
     * Normalize URL-encoded response from API
     * 
     * @param array $response URL-encoded response
     * @return array Normalized response
     */
    private function normalize_url_encoded_response($response) {
        return $this->normalize_json_response($response);
    }

    /**
     * Add entry to refund history
     * Used for logging refund status updates (like when marked as REFUNDED)
     * 
     * @param int $order_id WooCommerce Order ID
     * @param string $status Status to add to history
     * @param string $refund_id Optional refund ID to include
     * @return void
     */
    public static function add_refund_history_entry($order_id, $status = 'REFUNDED', $refund_id = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $refund_history = $order->get_meta('_jio_pay_refund_history');
        if (!is_array($refund_history)) {
            $refund_history = [];
        }

        // Create history entry with timestamp and status
        $entry = current_time('Y-m-d H:i:s') . ' - ' . strtoupper($status);
        if ($refund_id) {
            $entry .= ' (ID: ' . $refund_id . ')';
        }

        $refund_history[] = $entry;
        $order->update_meta_data('_jio_pay_refund_history', $refund_history);
        $order->save();
    }

    /**
     * Get refund history for an order
     * 
     * @param int $order_id WooCommerce Order ID
     * @return array Refund history
     */
    public static function get_refund_history($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return [];
        }

        $refund_history = $order->get_meta('_jio_pay_refund_history');
        return is_array($refund_history) ? $refund_history : [];
    }

    /**
     * Get all refund logs for audit purposes
     * 
     * @param int $limit Number of logs to retrieve
     * @return array Refund logs
     */
    public static function get_refund_logs($limit = 50) {
        $refund_logs = get_transient('jio_pay_refund_logs');
        if (!is_array($refund_logs)) {
            return [];
        }

        // Return only the last $limit logs
        return array_slice($refund_logs, -$limit);
    }

    /**
     * Test API connectivity
     * Used for debugging API connection issues
     * 
     * @return array Test result
     */
    public function test_api_connection() {
        error_log('=== Testing Jio Pay API Connection ===');
        error_log('Endpoint: ' . $this->api_endpoint);
        error_log('Merchant ID: ' . $this->merchant_id);
        error_log('Environment: ' . $this->environment);

        // Test with dummy refund data
        $test_data = [
            'merchantId' => $this->merchant_id,
            'merchantTxnNo' => 'TEST_CONNECTION_' . time(),
            'originalTxnNo' => 'TEST_0000001',
            'transactionType' => 'REFUND',
            'amount' => 0.01
        ];

        $post_data = http_build_query($test_data);

        $response = wp_remote_post($this->api_endpoint, [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $post_data,
            'timeout' => 30,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'API Connection Failed: ' . $response->get_error_message(),
                'error_code' => $response->get_error_code()
            ];
        }

        return [
            'success' => true,
            'message' => 'API Connection Successful',
            'response_code' => wp_remote_retrieve_response_code($response),
            'response_body' => wp_remote_retrieve_body($response)
        ];
    }

    /**
     * Decode Jio Pay error codes
     * 
     * @param string $error_code Error code from API
     * @return string Human readable error message
     */
    public static function decode_error_code($error_code) {
        $error_codes = [
            'P1001' => 'Invalid Merchant ID',
            'P1002' => 'Invalid Amount',
            'P1003' => 'Transaction Not Found - Original transaction does not exist',
            'P1004' => 'Transaction Already Refunded - Cannot refund twice',
            'P1005' => 'Invalid Transaction ID Format',
            'P1006' => 'Transaction Not Eligible for Refund - May be too old or in wrong state',
            'P1007' => 'Refund Amount Exceeds Original Amount',
            'P1008' => 'Daily Refund Limit Exceeded',
            'P1009' => 'Invalid Request Format',
            '0000' => 'Success',
            '2001' => 'Invalid Credentials',
            '2002' => 'Unauthorized',
            '5001' => 'Server Error - Try again later',
        ];

        return $error_codes[$error_code] ?? 'Unknown Error: ' . $error_code;
    }

    /**
     * Check if order can be refunded
     * 
     * @param int $order_id WooCommerce Order ID
     * @return array Status and reason
     */
    public static function can_refund_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return [
                'can_refund' => false,
                'reason' => 'Order not found'
            ];
        }

        if ($order->get_payment_method() !== 'jio_pay') {
            return [
                'can_refund' => false,
                'reason' => 'Order not paid with Jio Pay'
            ];
        }

        if ($order->get_status() === 'refunded') {
            return [
                'can_refund' => false,
                'reason' => 'Order already refunded'
            ];
        }

        // Check if refund has already been initiated
        if ($order->get_meta('_jio_pay_refund_merchant_txn_no')) {
            return [
                'can_refund' => false,
                'reason' => 'Refund already initiated. Use "Check Refund Status" to verify.'
            ];
        }

        if (!$order->get_meta('_jio_pay_merchant_tr_id')) {
            return [
                'can_refund' => false,
                'reason' => 'Merchant transaction ID not found'
            ];
        }

        $allowed_statuses = ['processing', 'completed', 'on-hold'];
        if (!in_array($order->get_status(), $allowed_statuses)) {
            return [
                'can_refund' => false,
                'reason' => 'Order status does not allow refund. Current status: ' . $order->get_status()
            ];
        }

        return [
            'can_refund' => true,
            'reason' => 'Order can be refunded'
        ];
    }
}
