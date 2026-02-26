jQuery(document).ready(function($) {
    // Format JSON response into human-readable HTML table
    function formatResponseTable(data) {
        if (!data) return '<p>No data available</p>';
        
        var html = '<table style="width:100%;border-collapse:collapse;margin-top:12px;">';
        var keyMap = {
            'txnStatus': 'Transaction Status',
            'txnResponseCode': 'Response Code',
            'txnRespDescription': 'Response Description',
            'respDescription': 'Detailed Description',
            'merchantTxnNo': 'Merchant Transaction ID',
            'txnID': 'Jio Pay Transaction ID',
            'txnAuthID': 'Auth ID',
            'amount': 'Amount',
            'paymentMode': 'Payment Mode',
            'acqName': 'Acquirer Name',
            'paymentSubInstType': 'Payment Sub Type',
            'merchantId': 'Merchant ID',
            'customerEmailID': 'Customer Email',
            'transactionType': 'Transaction Type',
            'paymentDateTime': 'Payment Date/Time',
            'TransmissionDateTime': 'Transmission Date/Time',
            'oth_charge': 'Other Charges',
            'secureHash': 'Secure Hash'
        };
        
        for (var key in data) {
            if (data.hasOwnProperty(key) && key !== 'raw_response' && data[key] !== '' && data[key] !== null) {
                var displayKey = keyMap[key] || key.replace(/([A-Z])/g, ' $1').trim();
                var displayValue = data[key];
                
                // Format values for better readability
                if (key === 'amount' || key === 'oth_charge') {
                    displayValue = '₹' + displayValue;
                }
                if (key === 'txnStatus') {
                    var statusColor = displayValue === 'SUC' ? '#28a745' : (displayValue === 'PENDING' ? '#ffc107' : '#dc3545');
                    displayValue = '<span style="color:' + statusColor + ';font-weight:bold;">' + displayValue + '</span>';
                }
                if (key === 'txnResponseCode' || key === 'responseCode') {
                    var codeColor = displayValue === '0000' || displayValue === '000' ? '#28a745' : '#dc3545';
                    displayValue = '<span style="color:' + codeColor + ';font-weight:bold;">' + displayValue + '</span>';
                }
                
                html += '<tr style="border-bottom:1px solid #ddd;">';
                html += '<td style="padding:10px;font-weight:bold;width:40%;background:#f9f9f9;">' + displayKey + '</td>';
                html += '<td style="padding:10px;word-break:break-all;">' + displayValue + '</td>';
                html += '</tr>';
            }
        }
        html += '</table>';
        return html;
    }

    // Refund button logic already exists

    // Status check button click handler
    $(document).on('click', '.jio-pay-status-btn', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var type = $(this).data('type'); // 'order' or 'refund'
        var merchantTxnNo = $(this).data('merchant-txn-no');
        var authId = $(this).data('auth-id');
        var nonce = $(this).data('nonce');
        var $btn = $(this);
        $btn.prop('disabled', true);
        $btn.after('<span class="jio-pay-status-spinner" style="margin-left:8px;">Checking...</span>');

        // For refund status checks, use the admin handler that auto-updates order
        if (type === 'refund') {
            $.post(ajaxurl, {
                action: 'jio_pay_check_update_refund_status',
                order_id: orderId,
                merchant_txn_no: merchantTxnNo,
                nonce: nonce
            }, function(response) {
                $btn.prop('disabled', false);
                $('.jio-pay-status-spinner').remove();
                var msg = '';
                var data = response.data || {};
                var responseData = (data.response && data.response.raw_response) ? data.response.raw_response : data.response;
                
                if (data.status === 'refunded') {
                    msg = '<div style="padding:12px;background:#d4edda;border:1px solid #c3e6cb;color:#155724;border-radius:4px;margin-bottom:16px;"><strong>✓ Refund Confirmed!</strong><br>Order has been marked as refunded. Page reloading...</div>';
                    msg += formatResponseTable(responseData);
                } else {
                    msg = '<div style="padding:12px;background:#fff3cd;border:1px solid #ffeeba;color:#856404;border-radius:4px;margin-bottom:16px;"><strong>Status: Pending</strong><br>Refund is still processing. Please check again later.</div>';
                    msg += formatResponseTable(responseData);
                }
                
                // Show in popup
                var popup = $('<div class="jio-pay-status-popup" style="position:fixed;top:10%;left:50%;transform:translateX(-50%);background:#fff;padding:24px 32px;border:2px solid #0073aa;z-index:9999;max-width:800px;max-height:80vh;overflow:auto;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,0.15);"></div>');
                popup.append('<h2 style="margin-top:0;color:#0073aa;">Jio Pay Refund Status</h2>');
                popup.append(msg);
                popup.append('<button class="button jio-pay-status-close" style="margin-top:16px;width:100%;padding:10px;">Close</button>');
                $('body').append(popup);
                
                // Reload page after 3 seconds if refund is confirmed
                if (data.status === 'refunded') {
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                }
            });
        } else {
            // For order status checks, use frontend handler
            $.post(ajaxurl, {
                action: 'jio_pay_check_status',
                order_id: orderId,
                type: type,
                merchant_txn_no: merchantTxnNo,
                auth_id: authId,
                nonce: nonce
            }, function(response) {
                $btn.prop('disabled', false);
                $('.jio-pay-status-spinner').remove();
                var msg = '';
                if (response.success && response.data) {
                    var statusData = response.data;
                    
                    // The response from check_refund_status has raw_response nested inside
                    var displayData = statusData.raw_response || statusData;
                    
                    // Check if successful - success flag takes priority
                    var isSuccess = statusData.success === true || (displayData.response_code === '0000' || displayData.responseCode === '0000' || displayData.txnResponseCode === '0000');
                    
                    // Get status message
                    var displayStatus = displayData.txnStatus || displayData.respDescription || displayData.txnRespDescription || 'UNKNOWN';
                    
                    if (isSuccess) {
                        msg = '<div style="padding:12px;background:#d4edda;border:1px solid #c3e6cb;color:#155724;border-radius:4px;margin-bottom:16px;"><strong>✓ Transaction Successful!</strong></div>';
                    } else {
                        msg = '<div style="padding:12px;background:#fff3cd;border:1px solid #ffeeba;color:#856404;border-radius:4px;margin-bottom:16px;"><strong>Status: ' + displayStatus + '</strong></div>';
                    }
                    msg += formatResponseTable(displayData);
                } else {
                    msg = '<div style="padding:12px;background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;border-radius:4px;">' + (response.data && response.data.message ? response.data.message : 'Status check failed') + '</div>';
                }
                // Show in popup
                var popup = $('<div class="jio-pay-status-popup" style="position:fixed;top:10%;left:50%;transform:translateX(-50%);background:#fff;padding:24px 32px;border:2px solid #0073aa;z-index:9999;max-width:800px;max-height:80vh;overflow:auto;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,0.15);"></div>');
                popup.append('<h2 style="margin-top:0;color:#0073aa;">Jio Pay Order Status</h2>');
                popup.append(msg);
                popup.append('<button class="button jio-pay-status-close" style="margin-top:16px;width:100%;padding:10px;">Close</button>');
                $('body').append(popup);
            });
        }
    });

    // Close popup
    $(document).on('click', '.jio-pay-status-close', function() {
        $('.jio-pay-status-popup').remove();
    });
});
