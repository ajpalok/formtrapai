<?php
/**
 * Quick test script to verify system functionality
 * Run: php test.php
 */

echo "=== Honeypot Security System - Quick Test ===\n\n";

// Test 1: Database Connection
echo "Test 1: Database Connection... ";
try {
    require_once __DIR__ . '/db.php';
    $db = Database::getInstance();
    $db->query("SELECT 1");
    echo "✓ PASS\n";
} catch (Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Normalizer
echo "Test 2: Normalizer... ";
try {
    require_once __DIR__ . '/normalizer.php';
    
    $input = "Contact me at test@example.com or call 555-1234";
    $template = InputNormalizer::extractTemplate($input);
    $expected = "contact me at <EMAIL> or call <PHONE>";
    
    if (strtolower($template) === strtolower($expected)) {
        echo "✓ PASS\n";
    } else {
        echo "✗ FAIL: Expected '$expected', got '$template'\n";
    }
} catch (Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
}

// Test 3: Logger
echo "Test 3: Logger... ";
try {
    require_once __DIR__ . '/logger.php';
    
    $logger = new Logger();
    $testData = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'subject' => 'Test',
        'message' => 'Test message',
        'form_render_time' => time() * 1000 - 3000, // 3 seconds ago
    ];
    
    // Simulate server variables
    $_SERVER['HTTP_USER_AGENT'] = 'TestBot/1.0';
    $_SERVER['HTTP_REFERER'] = 'http://test.local';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    
    $submissionId = $logger->logSubmission($testData);
    
    if ($submissionId > 0) {
        echo "✓ PASS (Submission ID: $submissionId)\n";
    } else {
        echo "✗ FAIL: Invalid submission ID\n";
    }
} catch (Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
}

// Test 4: Scorer
echo "Test 4: Scorer... ";
try {
    require_once __DIR__ . '/scorer.php';
    
    if (isset($submissionId) && $submissionId > 0) {
        $scorer = new Scorer();
        $score = $scorer->scoreSubmission($submissionId);
        
        if ($score['score'] >= 0 && isset($score['risk_level'])) {
            echo "✓ PASS (Score: {$score['score']}, Risk: {$score['risk_level']})\n";
        } else {
            echo "✗ FAIL: Invalid score result\n";
        }
    } else {
        echo "⊘ SKIP (No submission to score)\n";
    }
} catch (Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
}

// Test 5: Extractor
echo "Test 5: Extractor... ";
try {
    require_once __DIR__ . '/extractor.php';
    
    $extractor = new Extractor();
    
    // Create a few test submissions
    for ($i = 0; $i < 3; $i++) {
        $testData = [
            'name' => "Bot User $i",
            'email' => "bot$i@spam.com",
            'subject' => "Spam Subject",
            'message' => "Buy cheap products now! Click here!",
            'form_render_time' => time() * 1000,
        ];
        $logger->logSubmission($testData);
    }
    
    $result = $extractor->runExtraction(100);
    
    if ($result['signatures'] >= 0) {
        echo "✓ PASS (Signatures: {$result['signatures']}, Clusters: {$result['clusters']})\n";
    } else {
        echo "✗ FAIL: Extraction failed\n";
    }
} catch (Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
}

// Test 6: API Stats
echo "Test 6: API Stats... ";
try {
    $_GET['action'] = 'stats';
    ob_start();
    include __DIR__ . '/api_stats.php';
    $output = ob_get_clean();
    $data = json_decode($output, true);
    
    if (isset($data['total_submissions']) && $data['total_submissions'] >= 0) {
        echo "✓ PASS\n";
    } else {
        echo "✗ FAIL: Invalid API response\n";
    }
} catch (Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
}

// Test 7: Evaluator
echo "Test 7: Evaluator... ";
try {
    require_once __DIR__ . '/evaluator.php';
    
    $evaluator = new Evaluator();
    $metrics = $evaluator->calculateDetectionMetrics();
    
    if (isset($metrics['detection_rate'])) {
        echo "✓ PASS (Detection Rate: {$metrics['detection_rate']}%)\n";
    } else {
        echo "✗ FAIL: Metrics calculation failed\n";
    }
} catch (Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
}

echo "\n=== Test Summary ===\n";
echo "All critical components tested.\n";
echo "If all tests passed, the system is ready to use.\n";
echo "\nNext steps:\n";
echo "1. Visit http://localhost:8000/contact_form.php to test the form\n";
echo "2. Visit http://localhost:8000/dashboard.php to view analytics\n";
echo "3. Run 'php evaluate.php' for full evaluation report\n";
