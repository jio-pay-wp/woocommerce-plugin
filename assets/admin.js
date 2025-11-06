/**
 * Jio Pay Gateway Admin JavaScript
 */

jQuery(document).ready(function($) {
    
    // Handle update check button
    $('#jio-pay-check-updates').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $status = $('#jio-pay-update-status');
        var originalText = $button.text();
        
        // Show loading state
        $button.prop('disabled', true)
               .html('<span class="dashicons dashicons-update spin"></span> ' + jio_pay_admin.checking_text);
        
        // Make AJAX request
        $.ajax({
            url: jio_pay_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'jio_pay_check_updates',
                nonce: jio_pay_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show result message
                    showNotification(response.data.message, response.data.update_available ? 'warning' : 'success');
                    
                    // Update status display
                    if (response.data.update_available) {
                        $status.removeClass('up-to-date')
                               .addClass('update-available')
                               .html('<span class="dashicons dashicons-update" style="color: #d63638;"></span> ' + jio_pay_admin.update_available_text);
                        
                        // Add update button if not exists
                        if (!$button.siblings('.button-primary').length) {
                            var updateUrl = createUpdateUrl();
                            var updateButton = '<a href="' + updateUrl + '" class="button button-primary" style="margin-left: 10px;">' +
                                              '<span class="dashicons dashicons-download"></span> Update Now</a>';
                            $button.after(updateButton);
                        }
                    } else {
                        $status.removeClass('update-available')
                               .addClass('up-to-date')
                               .html('<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> ' + jio_pay_admin.no_updates_text);
                    }
                } else {
                    showNotification(response.data.message || 'Failed to check for updates', 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotification('Error checking for updates: ' + error, 'error');
            },
            complete: function() {
                // Restore button state
                $button.prop('disabled', false)
                       .html('<span class="dashicons dashicons-update"></span> ' + originalText);
            }
        });
    });
    
    // Auto-check for updates on page load (every 24 hours)
    var lastCheck = localStorage.getItem('jio_pay_last_update_check');
    var now = Date.now();
    var twentyFourHours = 24 * 60 * 60 * 1000;
    
    if (!lastCheck || (now - parseInt(lastCheck)) > twentyFourHours) {
        // Delay auto-check by 2 seconds to let page load
        setTimeout(function() {
            $('#jio-pay-check-updates').trigger('click');
            localStorage.setItem('jio_pay_last_update_check', now.toString());
        }, 2000);
    }
    
    // Show notification function
    function showNotification(message, type) {
        type = type || 'info';
        
        var noticeClass = 'notice-' + type;
        var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible jio-pay-admin-notice">' +
                       '<p>' + message + '</p>' +
                       '<button type="button" class="notice-dismiss">' +
                       '<span class="screen-reader-text">Dismiss this notice.</span>' +
                       '</button>' +
                       '</div>');
        
        // Remove existing notices
        $('.jio-pay-admin-notice').remove();
        
        // Add new notice
        $('.wrap h1').after($notice);
        
        // Handle dismiss button
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeTo(100, 0, function() {
                $notice.slideUp(100, function() {
                    $notice.remove();
                });
            });
        });
        
        // Auto-hide success/info messages after 5 seconds
        if (type === 'success' || type === 'info') {
            setTimeout(function() {
                $notice.find('.notice-dismiss').trigger('click');
            }, 5000);
        }
    }
    
    // Create update URL
    function createUpdateUrl() {
        var pluginSlug = 'jio-pay-gateway/jio-pay-gateway.php'; // Adjust based on your plugin structure
        var baseUrl = ajaxurl.replace('/admin-ajax.php', '/update.php');
        return baseUrl + '?action=upgrade-plugin&plugin=' + encodeURIComponent(pluginSlug) + '&_wpnonce=' + jio_pay_admin.nonce;
    }
    
    // Add spinning animation for update icon
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .dashicons.spin {
                animation: spin 1s linear infinite;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .jio-pay-admin-notice {
                margin: 15px 0;
            }
            
            .jio-pay-admin-notice p {
                margin: 0.5em 0;
            }
        `)
        .appendTo('head');
    
    // Handle settings form submission
    $('form').on('submit', function() {
        var $submitButton = $(this).find('input[type="submit"]');
        $submitButton.prop('disabled', true).val('Saving...');
        
        // Re-enable after form submission
        setTimeout(function() {
            $submitButton.prop('disabled', false).val('Save Changes');
        }, 2000);
    });
    
    // Add confirmation for potentially destructive actions
    $('a[href*="action=upgrade-plugin"]').on('click', function(e) {
        var confirmed = confirm('Are you sure you want to update the Jio Pay Gateway plugin? This will replace the current version.');
        if (!confirmed) {
            e.preventDefault();
            return false;
        }
    });
    
    // Handle plugin activation/deactivation links
    $('a[href*="action=deactivate"][href*="jio-pay"]').on('click', function(e) {
        var confirmed = confirm('Are you sure you want to deactivate the Jio Pay Gateway? This will disable payment processing.');
        if (!confirmed) {
            e.preventDefault();
            return false;
        }
    });
    
    // Show helpful tooltips
    if (typeof tippy !== 'undefined') {
        tippy('[data-tippy-content]', {
            theme: 'light',
            placement: 'top',
            arrow: true
        });
    }
    
    // Debug information toggle
    $('#jio-pay-debug-toggle').on('click', function(e) {
        e.preventDefault();
        $('#jio-pay-debug-info').toggle();
        $(this).text($(this).text() === 'Show Debug Info' ? 'Hide Debug Info' : 'Show Debug Info');
    });
});