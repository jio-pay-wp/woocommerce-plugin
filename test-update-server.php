<?php
/**
 * Test Update Server Response
 * 
 * This file simulates what your update server should return
 * Use this for testing the update notifications
 */

// For testing purposes - simulate a new version
$test_response = array(
    'tag_name' => 'v1.1.0',
    'name' => 'Jio Pay Gateway v1.1.0',
    'html_url' => 'https://github.com/techfleek-code/jio-pay/releases/tag/v1.1.0',
    'zipball_url' => 'https://github.com/techfleek-code/jio-pay/archive/refs/tags/v1.1.0.zip',
    'body' => "## What's New in v1.1.0\n\n**🚀 New Features:**\n- Enhanced security with additional validation\n- Improved error handling and user feedback\n- Better mobile responsiveness\n- Advanced logging capabilities\n\n**🐛 Bug Fixes:**\n- Fixed payment verification edge cases\n- Resolved mobile popup issues\n- Improved SSL certificate handling\n\n**📚 Documentation:**\n- Updated API documentation\n- Added troubleshooting guides\n- Enhanced security guidelines",
    'published_at' => '2025-11-03T16:30:00Z',
    'prerelease' => false
);

// Output JSON response
header('Content-Type: application/json');
echo json_encode($test_response);
?>