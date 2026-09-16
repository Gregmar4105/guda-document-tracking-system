<?php
/**
 * Document Processing Bottleneck Analyzer
 * 
 * This module analyzes document processing times across departments
 * and identifies potential bottlenecks based on processing delays.
 * 
 * Role-Based Access:
 * - MIS: Full access to all analytics
 * - Department Heads: Can see their own department metrics
 * - Admin/Management: Full access to all analytics
 */

require_once 'db_connect.php';

class BottleneckAnalyzer {
    private $conn;
    private $user_id;
    private $user_role;
    private $is_head;
    private $is_admin;
    
    public function __construct($conn, $user_id, $user_role, $is_head = false, $is_admin = false) {
        $this->conn = $conn;
        $this->user_id = $user_id;
        $this->user_role = $user_role;
        $this->is_head = $is_head;
        $this->is_admin = $is_admin;
    }
    
    /**
     * Calculate the processing time for a document between two departments
     * Returns time in hours
     */
    public function getDocumentSegmentTime($voucher_code, $from_dept, $to_dept) {
        $stmt = $this->conn->prepare("
            SELECT 
                MIN(CASE WHEN department = ? AND action_taken = 'Scan-to-Receive' THEN created_at END) as from_receive,
                MAX(CASE WHEN department = ? AND action_taken = 'Accepted' THEN created_at END) as from_accept,
                MIN(CASE WHEN department = ? AND action_taken = 'Scan-to-Receive' THEN created_at END) as to_receive
            FROM audit_logs
            WHERE voucher_code = ?
        ");
        
        $stmt->bind_param("ssss", $from_dept, $from_dept, $to_dept, $voucher_code);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        if ($data && $data['to_receive']) {
            $from_time = strtotime($data['from_accept'] ?? $data['from_receive']);
            $to_time = strtotime($data['to_receive']);
            return ($to_time - $from_time) / 3600; // Return hours
        }
        return null;
    }
    
    /**
     * Get department processing statistics
     * Shows average stay time, document count, and delay percentages
     */
    public function getDepartmentStats($department = null, $days = 30) {
        $access_check = $this->checkAccessControl($department);
        if (!$access_check['allowed']) {
            return ['error' => $access_check['message']];
        }
        
        $dept_filter = $access_check['department_filter'];
        
        $query = "
            SELECT 
                department,
                COUNT(DISTINCT voucher_code) as total_documents,
                COUNT(DISTINCT CASE 
                    WHEN action_taken IN ('Accepted', 'RETURNED', 'DECLINED', 'AUTO-SKIPPED')
                    THEN voucher_code 
                END) as completed_documents,
                COUNT(DISTINCT CASE 
                    WHEN action_taken NOT IN ('Accepted', 'RETURNED', 'DECLINED', 'AUTO-SKIPPED')
                    THEN voucher_code 
                END) as pending_documents,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, 
                    (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                    (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                )), 2) as avg_stay_hours,
                ROUND(MIN(TIMESTAMPDIFF(HOUR, 
                    (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                    (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                )), 2) as min_stay_hours,
                ROUND(MAX(TIMESTAMPDIFF(HOUR, 
                    (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                    (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                )), 2) as max_stay_hours
            FROM audit_logs al
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            $dept_filter
            GROUP BY department
            ORDER BY avg_stay_hours DESC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }
        $stmt->close();
        
        return $stats;
    }
    
    /**
     * Identify potential bottlenecks based on processing time anomalies
     * Returns departments with unusual delays
     */
    public function identifyBottlenecks($days = 30, $threshold_percentile = 75) {
        $access_check = $this->checkAccessControl();
        if (!$access_check['allowed']) {
            return ['error' => $access_check['message']];
        }
        
        $dept_filter = $access_check['department_filter'];
        
        // First, get per-department average stay times and compute percentile in PHP (more portable than DB PERCENTILE functions)
        $dept_avg_query = "
            SELECT 
                department,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, 
                    (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                    (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                )), 2) as avg_stay
            FROM audit_logs al
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            $dept_filter
            GROUP BY department
        ";

        $dept_avg_stmt = $this->conn->prepare($dept_avg_query);
        $dept_avg_stmt->bind_param("i", $days);
        $dept_avg_stmt->execute();
        $dept_avg_res = $dept_avg_stmt->get_result();
        $avg_values = [];
        while ($r = $dept_avg_res->fetch_assoc()) {
            if (isset($r['avg_stay'])) {
                $avg_values[] = (float)$r['avg_stay'];
            }
        }
        $dept_avg_stmt->close();

        // Compute percentile (threshold) in PHP
        if (empty($avg_values)) {
            $threshold = 0.0;
        } else {
            sort($avg_values);
            $p = max(0.0, min(1.0, $threshold_percentile / 100.0));
            $idx = (int)ceil($p * count($avg_values)) - 1;
            if ($idx < 0) $idx = 0;
            $threshold = $avg_values[$idx];
        }

        // Now identify bottlenecks
        $bottleneck_query = "
            SELECT 
                department,
                COUNT(DISTINCT voucher_code) as total_documents,
                COUNT(DISTINCT CASE 
                    WHEN TIMESTAMPDIFF(HOUR, 
                        (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                        (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                    ) > ? THEN voucher_code 
                END) as delayed_documents,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, 
                    (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                    (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                )), 2) as avg_stay_hours,
                ROUND((COUNT(DISTINCT CASE 
                    WHEN TIMESTAMPDIFF(HOUR, 
                        (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                        (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                    ) > ? THEN voucher_code 
                END) / COUNT(DISTINCT voucher_code)) * 100, 2) as delay_percentage,
                COUNT(DISTINCT CASE 
                    WHEN action_taken NOT IN ('Accepted', 'RETURNED', 'DECLINED', 'AUTO-SKIPPED')
                    THEN voucher_code 
                END) as pending_documents
            FROM audit_logs al
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            $dept_filter
            GROUP BY department
            HAVING avg_stay_hours > ?
            ORDER BY avg_stay_hours DESC
        ";
        
        $bottleneck_stmt = $this->conn->prepare($bottleneck_query);
        // bind_param types: threshold (double), threshold (double), days (int), threshold (double)
        $bottleneck_stmt->bind_param("ddid", $threshold, $threshold, $days, $threshold);
        $bottleneck_stmt->execute();
        $result = $bottleneck_stmt->get_result();
        
        $bottlenecks = [];
        while ($row = $result->fetch_assoc()) {
            $row['risk_level'] = $this->assessRiskLevel($row);
            $row['recommendation'] = $this->generateRecommendation($row);
            $bottlenecks[] = $row;
        }
        $bottleneck_stmt->close();
        
        return $bottlenecks;
    }
    
    /**
     * Get delayed documents for a specific department
     */
    public function getDelayedDocuments($department, $hours_threshold = 24, $limit = 50) {
        $access_check = $this->checkAccessControl($department);
        if (!$access_check['allowed']) {
            return ['error' => $access_check['message']];
        }
        
        $query = "
            SELECT DISTINCT
                al.voucher_code,
                al.department,
                v.date_submitted,
                MAX(CASE WHEN al.action_taken = 'Scan-to-Receive' THEN al.created_at END) as received_at,
                MAX(CASE WHEN al.action_taken = 'Accepted' THEN al.created_at END) as accepted_at,
                ROUND(TIMESTAMPDIFF(HOUR, 
                    MAX(CASE WHEN al.action_taken = 'Scan-to-Receive' THEN al.created_at END),
                    MAX(CASE WHEN al.action_taken = 'Accepted' THEN al.created_at END)
                ), 2) as stay_hours,
                MAX(CASE WHEN al.action_taken = 'Accepted' THEN 1 ELSE 0 END) as is_completed,
                COUNT(DISTINCT CASE WHEN al.action_taken IN ('Scan-to-Receive', 'Accepted') THEN al.log_id END) as action_count
            FROM audit_logs al
            LEFT JOIN vouchers v ON al.voucher_code = v.voucher_code
            WHERE al.department = ?
            AND TIMESTAMPDIFF(HOUR, 
                MAX(CASE WHEN al.action_taken = 'Scan-to-Receive' THEN al.created_at END),
                MAX(CASE WHEN al.action_taken = 'Accepted' THEN al.created_at END)
            ) > ?
            GROUP BY al.voucher_code, al.department
            ORDER BY stay_hours DESC
            LIMIT ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("sii", $department, $hours_threshold, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $documents = [];
        while ($row = $result->fetch_assoc()) {
            $documents[] = $row;
        }
        $stmt->close();
        
        return $documents;
    }
    
    /**
     * Get processing time trend for a department (for monitoring improvements)
     */
    public function getProcessingTimeTrend($department, $days = 60) {
        $access_check = $this->checkAccessControl($department);
        if (!$access_check['allowed']) {
            return ['error' => $access_check['message']];
        }
        
        $query = "
            SELECT 
                DATE(al.created_at) as date,
                COUNT(DISTINCT al.voucher_code) as documents_processed,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, 
                    (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                    (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                )), 2) as avg_stay_hours
            FROM audit_logs al
            WHERE al.department = ?
            AND al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND al.action_taken = 'Accepted'
            GROUP BY DATE(al.created_at)
            ORDER BY date ASC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $department, $days);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $trend = [];
        while ($row = $result->fetch_assoc()) {
            $trend[] = $row;
        }
        $stmt->close();
        
        return $trend;
    }
    
    /**
     * Access control check - determines what data the user can see
     */
    private function checkAccessControl($department = null) {
        $is_mis = $this->user_role === 'MIS';
        $is_management = $this->is_admin || $this->is_head;
        
        // Only MIS, Admins, or Department Heads can access analytics
        if (!$is_mis && !$is_management) {
            return [
                'allowed' => false,
                'message' => 'Access denied. Only Management and MIS can view bottleneck analytics.'
            ];
        }
        
        // If a specific department is requested
        if ($department && $is_management && !$is_mis) {
            // Department heads can only see their own department
            if ($this->user_role !== $department) {
                return [
                    'allowed' => false,
                    'message' => 'You can only view analytics for your own department.'
                ];
            }
        }
        
        // Build the department filter for the query
        $dept_filter = '';
        if ($department && $is_management && !$is_mis) {
            $dept_filter = " AND department = '$department'";
        }
        
        return [
            'allowed' => true,
            'department_filter' => $dept_filter
        ];
    }
    
    /**
     * Assess risk level based on processing metrics
     */
    private function assessRiskLevel($stats) {
        $delay_pct = $stats['delay_percentage'];
        $avg_hours = $stats['avg_stay_hours'];
        
        if ($delay_pct >= 50 && $avg_hours >= 24) {
            return 'CRITICAL';
        } elseif ($delay_pct >= 30 && $avg_hours >= 12) {
            return 'HIGH';
        } elseif ($delay_pct >= 10 || $avg_hours >= 8) {
            return 'MEDIUM';
        }
        return 'LOW';
    }
    
    /**
     * Generate a management recommendation based on bottleneck data
     */
    private function generateRecommendation($stats) {
        $dept = $stats['department'];
        $avg = $stats['avg_stay_hours'];
        $delay_pct = $stats['delay_percentage'];
        $pending = $stats['pending_documents'];
        
        $recommendations = [];
        
        if ($avg >= 24 && $delay_pct >= 40) {
            $recommendations[] = "Investigate potential staffing issues: {$delay_pct}% of documents are delayed.";
        }
        
        if ($pending > 5) {
            $recommendations[] = "Significant backlog detected: {$pending} documents currently pending. Consider prioritization strategies.";
        }
        
        if ($avg >= 48) {
            $recommendations[] = "Processing time exceeds 48 hours. Review workflow procedures and document completeness requirements.";
        }
        
        if (empty($recommendations)) {
            $recommendations[] = "Continue monitoring. Current metrics are within acceptable ranges.";
        }
        
        return implode(" | ", $recommendations);
    }
}

?>
