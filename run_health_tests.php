<?php
// run_health_tests.php - Entry point for automated health tests
declare(strict_types=1);

require_once __DIR__ . '/tests/SystemHealthTest.php';

// Set up environment for CLI execution
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

// Set memory limit and execution time for tests
ini_set('memory_limit', '512M');
set_time_limit(300); // 5 minutes max

try {
    echo "PicFit.ai System Health Test Runner\n";
    echo str_repeat("=", 40) . "\n";
    echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

    // Create and run tests
    $healthTest = new SystemHealthTest();
    $results = $healthTest->runAllTests();

    // Calculate summary
    $totalTests = count($results);
    $passedTests = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
    $failedTests = $totalTests - $passedTests;

    echo "\n" . str_repeat("=", 40) . "\n";
    echo "TEST SUMMARY:\n";
    echo "Total Tests: {$totalTests}\n";
    echo "Passed: {$passedTests}\n";
    echo "Failed: {$failedTests}\n";

    if ($failedTests === 0) {
        echo "✅ All tests passed!\n";

        // Send success notification occasionally (only on Sundays at 9 AM to avoid spam)
        if (date('w') == 0 && date('H') == 9) {
            $healthTest->sendSuccessNotification();
        }
    } else {
        echo "❌ {$failedTests} test(s) failed!\n";
        echo "Check your email for details.\n";
    }

    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

    // Exit with appropriate code
    exit($failedTests > 0 ? 1 : 0);

} catch (Exception $e) {
    echo "❌ Test runner failed: " . $e->getMessage() . "\n";
    echo "Error in: " . $e->getFile() . " on line " . $e->getLine() . "\n";

    // Send critical error notification
    $subject = "🔥 PicFit.ai Critical Error - Test Runner Failed";
    $body = "The health test runner itself failed to execute.\n\n";
    $body .= "Error: " . $e->getMessage() . "\n";
    $body .= "File: " . $e->getFile() . "\n";
    $body .= "Line: " . $e->getLine() . "\n";
    $body .= "Time: " . date('Y-m-d H:i:s') . "\n\n";
    $body .= "Please investigate immediately.\n";

    $headers = [
        'From: PicFit.ai System <noreply@picfit.ai>',
        'Reply-To: noreply@picfit.ai',
        'Content-Type: text/plain; charset=UTF-8'
    ];

    mail('nate.mcguire@gmail.com', $subject, $body, implode("\r\n", $headers));

    exit(2);
}