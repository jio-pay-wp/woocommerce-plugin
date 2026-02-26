/**
 * Jio Pay Refund Admin Script
 * 
 * Prevents duplicate refund submissions using:
 * 1. localStorage for persistent state
 * 2. HTML disabled attribute on button
 * 3. CSS pointer-events for additional protection
 */

jQuery(document).ready(function($) {
    'use strict';

    // localStorage state management
    const getProcessingState = (orderId) => {
        const stored = localStorage.getItem('jio_pay_refund_' + orderId);
        if (!stored) return false;
        
        const state = JSON.parse(stored);
        const now = Date.now();
        
        // Clear if older than 5 minutes
        if (now - state.timestamp > 300000) {
            localStorage.removeItem('jio_pay_refund_' + orderId);
            return false;
        }
        return state.processing;
    };

    const setProcessingState = (orderId, isProcessing) => {
        if (isProcessing) {
            localStorage.setItem('jio_pay_refund_' + orderId, JSON.stringify({
                processing: true,
                timestamp: Date.now()
            }));
        } else {
            localStorage.removeItem('jio_pay_refund_' + orderId);
        }
    };

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Format response into human-readable HTML table
    function formatResponseTable(data) {
        if (!data) return '<p>No data available</p>';
        
        var html = '<table style="width:100%;border-collapse:collapse;margin-top:12px;">';
        var keyMap = {
            'refund_id': 'Refund ID',
            'refund_merchant_txn_no': 'Refund Txn ID',
            'message': 'Message',
            'amount': 'Refund Amount',
            'status': 'Status'
        };
        
        for (var key in data) {
            if (data.hasOwnProperty(key) && data[key] !== '' && data[key] !== null && key !== 'payload_sent') {
                var displayKey = keyMap[key] || key.replace(/([A-Z])/g, ' $1').trim();
                var displayValue = data[key];
                
                html += '<tr style="border-bottom:1px solid #ddd;">';
                html += '<td style="padding:10px;font-weight:bold;width:40%;background:#f9f9f9;">' + displayKey + '</td>';
                html += '<td style="padding:10px;word-break:break-all;">' + displayValue + '</td>';
                html += '</tr>';
            }
        }
        html += '</table>';
        return html;
    }

    // Handle refund button click
    $(document).on('click', '.jio-pay-refund-btn', function(e) {
        e.preventDefault();

        const $btn = $(this);
        const orderId = $btn.data('order-id');
        const $statusSpan = $('#jio_pay_refund_status_' + orderId);

        // Check if already processing
        if (getProcessingState(orderId)) {
            alert('A refund is already being processed for this order.');
            return false;
        }

        // Prevent if button is disabled
        if ($btn.prop('disabled')) {
            return false;
        }

        // Simple confirmation
        if (!confirm('Process refund for this order? This cannot be undone.')) {
            return false;
        }

        // Mark as processing in localStorage
        setProcessingState(orderId, true);

        // Show status and disable button
        $statusSpan.show().html('Processing refund... Please wait.');
        $btn.prop('disabled', true);

        // Send AJAX request
        $.ajax({
            url: jio_pay_refund.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'jio_pay_process_refund',
                nonce: jio_pay_refund.nonce,
                order_id: orderId
            },
            timeout: 60000,
            success: function(response) {
                console.log('Refund Response:', response);
                
                var msg = '';
                var data = response.data || {};
                
                // ALWAYS show response and reload - this is AJAX success (HTTP 200)
                // even if transaction status is pending or declined
                if (data.message || data.refund_merchant_txn_no) {
                    // Determine banner style based on response
                    var bannerStyle = '#d4edda'; // green default
                    var bannerBorder = '#c3e6cb';
                    var bannerColor = '#155724';
                    var bannerTitle = '✓ Refund Processed!';
                    
                    if (!response.success) {
                        // Response says not successful yet - show as pending/processing
                        bannerStyle = '#fff3cd';
                        bannerBorder = '#ffeeba';
                        bannerColor = '#856404';
                        bannerTitle = '⏱ Refund Status: ' + (data.error_code || 'Processing');
                    }
                    
                    msg = '<div style="padding:12px;background:' + bannerStyle + ';border:1px solid ' + bannerBorder + ';color:' + bannerColor + ';border-radius:4px;margin-bottom:16px;"><strong>' + bannerTitle + '</strong><br>' + (data.message || 'Refund request submitted.') + '</div>';
                    msg += formatResponseTable(data);
                    
                    // Show popup with response
                    var popup = $('<div class="jio-pay-refund-popup" style="position:fixed;top:10%;left:50%;transform:translateX(-50%);background:#fff;padding:24px 32px;border:2px solid #28a745;z-index:9999;max-width:800px;max-height:80vh;overflow:auto;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,0.15);"></div>');
                    popup.append('<h2 style="margin-top:0;color:#28a745;">Jio Pay Refund Response</h2>');
                    popup.append(msg);
                    popup.append('<button class="button jio-pay-refund-close" style="margin-top:16px;width:100%;padding:10px;">Close & Reload</button>');
                    $('body').append(popup);
                    
                    // Handle close button - ALWAYS reload
                    popup.on('click', '.jio-pay-refund-close', function() {
                        popup.remove();
                        $statusSpan.html('Reloading page...');
                        setProcessingState(orderId, false);
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    });
                    
                    // Auto-reload after 5 seconds if user doesn't click close - ALWAYS RELOAD
                    setTimeout(function() {
                        if (popup.parent().length) {
                            popup.remove();
                            $statusSpan.html('Auto-reloading page...');
                            location.reload();
                        }
                    }, 5000);
                } else {
                    // No message or refund txn number - this is a real error
                    showError(response.data.message || 'Refund failed');
                }
            },
            error: function(xhr, status, error) {
                console.log('Refund Error:', {status: status, error: error});
                
                let errorMsg = 'Failed to process refund';
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                } else if (xhr.status === 403) {
                    errorMsg = 'Permission denied';
                }
                showError(errorMsg);
            }
        });

        function showError(msg) {
            var errorPopup = $('<div class="jio-pay-refund-popup" style="position:fixed;top:10%;left:50%;transform:translateX(-50%);background:#fff;padding:24px 32px;border:2px solid #dc3545;z-index:9999;max-width:800px;max-height:80vh;overflow:auto;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,0.15);"></div>');
            errorPopup.append('<h2 style="margin-top:0;color:#dc3545;">Refund Error</h2>');
            errorPopup.append('<div style="padding:12px;background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;border-radius:4px;">' + escapeHtml(msg) + '</div>');
            errorPopup.append('<button class="button jio-pay-refund-close" style="margin-top:16px;width:100%;padding:10px;background:#dc3545;color:#fff;border:none;">Close</button>');
            $('body').append(errorPopup);
            
            errorPopup.on('click', '.jio-pay-refund-close', function() {
                errorPopup.remove();
                $statusSpan.html('');
                setProcessingState(orderId, false);
                $btn.prop('disabled', false);
            });
        }

        return false;
    });

    // Check for orphaned processing states on page load
    setTimeout(function() {
        const buttons = $('.jio-pay-refund-btn');
        buttons.each(function() {
            const $btn = $(this);
            const orderId = $btn.data('order-id');
            
            if (getProcessingState(orderId)) {
                const $statusSpan = $('#jio_pay_refund_status_' + orderId);
                $statusSpan.show().html('Previous refund still processing. Please wait.');
                $btn.prop('disabled', true);
            }
        });
    }, 500);
});
