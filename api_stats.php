<?php
/**
 * JSON API endpoints for dashboard
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/extractor.php';

$action = $_GET['action'] ?? '';

try {
    $db = Database::getInstance();
    $response = [];

    switch ($action) {
        case 'stats':
            $response = getStats($db);
            break;

        case 'recent_submissions':
            $limit = intval($_GET['limit'] ?? 50);
            $response = getRecentSubmissions($db, $limit);
            break;

        case 'risk_distribution':
            $response = getRiskDistribution($db);
            break;

        case 'top_patterns':
            $limit = intval($_GET['limit'] ?? 10);
            $response = getTopPatterns($db, $limit);
            break;

        case 'timeline':
            $range = $_GET['range'] ?? '30d';
            $response = getTimeline($db, $range);
            break;

        case 'honeypot_effectiveness':
            $response = getHoneypotEffectiveness($db);
            break;

        case 'submission_detail':
            $id = intval($_GET['id'] ?? 0);
            $response = getSubmissionDetail($db, $id);
            break;

        case 'cluster_list':
            $response = getClusterList($db);
            break;

        default:
            http_response_code(400);
            $response = ['error' => 'Invalid action'];
    }

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get overall statistics
 */
function getStats($db) {
    $totalSubmissions = $db->fetchColumn("SELECT COUNT(*) FROM submissions");
    $todaySubmissions = $db->fetchColumn(
        "SELECT COUNT(*) FROM submissions WHERE DATE(created_at) = CURDATE()"
    );
    $honeypotTriggered = $db->fetchColumn(
        "SELECT COUNT(*) FROM submissions WHERE JSON_LENGTH(honeypot_hits) > 0"
    );
    $highRiskCount = $db->fetchColumn(
        "SELECT COUNT(*) FROM submission_scores WHERE risk_level IN ('high', 'critical')"
    );
    $uniqueSignatures = $db->fetchColumn("SELECT COUNT(*) FROM signatures");
    $totalClusters = $db->fetchColumn("SELECT COUNT(*) FROM clusters");

    $detectionRate = $totalSubmissions > 0 
        ? round(($honeypotTriggered / $totalSubmissions) * 100, 2) 
        : 0;

    return [
        'total_submissions' => $totalSubmissions,
        'today_submissions' => $todaySubmissions,
        'honeypot_triggered' => $honeypotTriggered,
        'high_risk_count' => $highRiskCount,
        'unique_signatures' => $uniqueSignatures,
        'total_clusters' => $totalClusters,
        'detection_rate' => $detectionRate
    ];
}

/**
 * Get recent submissions with scores
 */
function getRecentSubmissions($db, $limit) {
    return $db->fetchAll(
        "SELECT 
            s.id,
            s.created_at,
            s.ip,
            s.user_agent,
            JSON_LENGTH(s.honeypot_hits) as honeypot_count,
            s.client_render_time,
            ss.score,
            ss.risk_level
         FROM submissions s
         LEFT JOIN submission_scores ss ON s.id = ss.submission_id
         ORDER BY s.created_at DESC
         LIMIT ?",
        [$limit]
    );
}

/**
 * Get risk level distribution
 */
function getRiskDistribution($db) {
    $distribution = $db->fetchAll(
        "SELECT risk_level, COUNT(*) as count 
         FROM submission_scores 
         GROUP BY risk_level"
    );

    $result = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
    foreach ($distribution as $row) {
        $result[$row['risk_level']] = intval($row['count']);
    }

    return $result;
}

/**
 * Get top attack patterns
 */
function getTopPatterns($db, $limit) {
    return $db->fetchAll(
        "SELECT id, pattern, occurrence, last_seen 
         FROM signatures 
         ORDER BY occurrence DESC 
         LIMIT ?",
        [$limit]
    );
}

/**
 * Get timeline data
 */
function getTimeline($db, $range) {
    // Parse range parameter (e.g., '30m', '1h', '12h', '1d', '7d', '30d')
    $unit = substr($range, -1); // Last character (m, h, d)
    $value = intval(substr($range, 0, -1)); // Number part

    $interval = '';
    $groupBy = '';
    $dateFormat = '';

    switch ($unit) {
        case 'm': // minutes
            $interval = "INTERVAL {$value} MINUTE";
            $format = "DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:00')";
            $groupBy = $format;
            $dateFormat = $format;
            break;
        case 'h': // hours
            $interval = "INTERVAL {$value} HOUR";
            $format = "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')";
            $groupBy = $format;
            $dateFormat = $format;
            break;
        case 'd': // days
        default:
            $interval = "INTERVAL {$value} DAY";
            $groupBy = "DATE(created_at)";
            $dateFormat = "DATE(created_at)";
            break;
    }

    $unitMap = ['m' => 'MINUTE', 'h' => 'HOUR', 'd' => 'DAY'];
    $sqlUnit = $unitMap[$unit] ?? 'DAY';

    $sql = "SELECT 
            {$dateFormat} as date,
            COUNT(*) as total,
            SUM(CASE WHEN JSON_LENGTH(honeypot_hits) > 0 THEN 1 ELSE 0 END) as honeypot_triggered
         FROM submissions
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$value} {$sqlUnit})
         GROUP BY {$groupBy}
         ORDER BY date ASC";

    return $db->fetchAll($sql, []);
}

/**
 * Get honeypot effectiveness metrics
 */
function getHoneypotEffectiveness($db) {
    $config = require __DIR__ . '/config.php';
    $honeypots = $config['honeypot'];

    $effectiveness = [];
    
    // Count hits per honeypot type
    foreach (['text_hidden', 'semantic', 'time_trap'] as $type) {
        $fieldName = $honeypots[$type];
        
        $count = $db->fetchColumn(
            "SELECT COUNT(*) FROM submissions 
             WHERE JSON_SEARCH(honeypot_hits, 'one', ?) IS NOT NULL",
            [$fieldName]
        );

        $effectiveness[$type] = [
            'field_name' => $fieldName,
            'hits' => $count
        ];
    }

    return $effectiveness;
}

/**
 * Get detailed submission info
 */
function getSubmissionDetail($db, $id) {
    $submission = $db->fetchOne("SELECT * FROM submissions WHERE id = ?", [$id]);
    
    if (!$submission) {
        return ['error' => 'Submission not found'];
    }

    // Decode JSON fields
    $submission['raw_payload'] = json_decode($submission['raw_payload'], true);
    $submission['honeypot_hits'] = json_decode($submission['honeypot_hits'], true);
    $submission['flags'] = json_decode($submission['flags'], true);
    $submission['request_headers'] = json_decode($submission['request_headers'], true);

    // Get score
    $score = $db->fetchOne(
        "SELECT * FROM submission_scores WHERE submission_id = ?",
        [$id]
    );

    if ($score) {
        $score['rules_triggered'] = json_decode($score['rules_triggered'], true);
        $submission['score'] = $score;
    }

    // Analyze pattern
    $extractor = new Extractor();
    $analysis = $extractor->analyzeSubmission($id);
    $submission['analysis'] = $analysis;

    return $submission;
}

/**
 * Get cluster list
 */
function getClusterList($db) {
    $clusters = $db->fetchAll(
        "SELECT id, cluster_label, description, 
                JSON_LENGTH(signature_ids) as signature_count,
                updated_at
         FROM clusters 
         ORDER BY updated_at DESC"
    );

    return $clusters;
}
