# Document Processing Bottleneck Detection System
## Implementation & Integration Guide

---

## Overview

This enhancement adds a **workflow performance monitoring** module to your Decision Support System (DSS). It tracks document processing times across departments and identifies potential bottlenecks, providing management with objective operational data for decision-making.

### Key Design Principles

1. **Data-Driven, Not Punitive** — The system identifies *where* delays occur and *how much*, not *who to blame*
2. **Management-Focused** — Provides evidence for investigation, leaves decisions to management
3. **Measurable Impact** — Tracks improvements after interventions are implemented
4. **Complementary** — Works alongside your existing financial-document anomaly DSS

---

## Architecture Overview

### Components

```
QR Tracking (Existing audit_logs table)
    ↓
BottleneckAnalyzer Class (bottleneck_analyzer.php)
    ↓ Calculates processing times
    ↓ Identifies anomalies
    ↓ Generates recommendations
    ↓
Analytics Dashboard (analytics_bottleneck.php)
    ↓
RBAC Access Control
    ↓
Database Views & Stored Procedures
```

### Two Parallel Decision-Support Pillars

**1. Financial-Document Anomaly Monitoring** (Existing)
- Detects unusual increases/decreases in financial document values
- Provides financial compliance recommendations

**2. Workflow Performance Monitoring** (New)
- Identifies processing time bottlenecks
- Provides operational improvement data
- Enables management to track intervention effectiveness

---

## Implementation Steps

### Step 1: Apply Database Migration

Execute the SQL migration to create necessary tables and views:

```bash
mysql -h sql203.infinityfree.com -u if0_42343630 -pAndrioGuda123 if0_42343630_dts < migration_bottleneck_system.sql
```

Or execute the SQL file directly through phpMyAdmin.

**Tables Created:**
- `processing_baselines` — Expected processing times per department
- `bottleneck_alerts` — Tracked alerts and management actions
- `processing_metrics_history` — Historical performance data for trend analysis
- `analytics_access_log` — Audit trail of who accessed what reports

**Views Created:**
- `v_current_bottleneck_status` — Real-time bottleneck dashboard
- `v_document_processing_timeline` — Document delays by department

**Stored Procedures:**
- `sp_generate_bottleneck_alerts()` — Automated alert generation
- `sp_log_analytics_access()` — Access audit logging

### Step 2: Verify Core Files

The following new files have been added to your system:

1. **`bottleneck_analyzer.php`** — Core analytics engine
   - Calculates processing times
   - Identifies bottlenecks
   - Manages RBAC access control
   - Generates management recommendations

2. **`analytics_bottleneck.php`** — Web interface & dashboard
   - Three views: Overview, Bottleneck Analysis, Department Details
   - Trend analysis charts
   - Performance metrics
   - Detailed delayed document listings

### Step 3: Add Navigation Link

Update your navigation/sidebar to include a link to the bottleneck analytics:

**In `sidebar.php` (add under Analytics section):**
```php
<li>
    <a href="analytics_bottleneck.php" class="nav-link">
        <i class="icon-chart"></i> Processing Analytics
    </a>
</li>
```

**In `home.php` (add as a quick-access card for management):**
```php
<?php if ($is_mis || $is_admin || $is_head): ?>
<div class="dashboard-card">
    <h3>Workflow Performance</h3>
    <a href="analytics_bottleneck.php" class="btn">View Bottleneck Analysis →</a>
</div>
<?php endif; ?>
```

### Step 4: Configure Processing Baselines

Update the default processing baseline times based on your organization's actual workflows:

**Access via phpMyAdmin or command line:**
```sql
UPDATE processing_baselines 
SET expected_hours = 20, warning_threshold_hours = 30, critical_threshold_hours = 40
WHERE department = 'Budget Office';
```

**Current Defaults:**
- Budget Office: 24 hours expected, 48 hours critical
- Accounting Office: 24 hours expected, 48 hours critical
- Cash Services: 8 hours expected, 16 hours critical
- HR: 24 hours expected, 48 hours critical

---

## Role-Based Access Control (RBAC)

### Access Levels

**1. MIS Role**
- ✅ View all department analytics
- ✅ View bottleneck analysis across entire organization
- ✅ View delayed documents in any department
- ✅ View processing time trends
- ✅ Edit processing baselines
- ✅ Access audit logs

**2. Department Heads** (HR Head, Budget Head, etc.)
- ✅ View analytics for their own department only
- ✅ View delayed documents in their department
- ✅ View their processing time trends
- ❌ Cannot see other departments' data
- ❌ Cannot edit baselines

**3. Regular Staff**
- ❌ No access to bottleneck analytics

**4. Admin**
- ✅ View all department analytics
- ✅ Edit processing baselines
- ✅ Access audit logs
- ✅ Manage alerts and recommendations

### Access Control Implementation

Access is enforced in `BottleneckAnalyzer::checkAccessControl()`:

```php
// MIS and Admins can see everything
$is_mis = $this->user_role === 'MIS';
$is_admin = $this->is_admin;

// Department heads can only see their own department
if ($is_head && !$is_mis && !$is_admin) {
    $department_filter = " AND department = '{user_department}'";
}
```

### Important RBAC Philosophy

The user was right to question "Why does HR have authority to evaluate every office?"

**Answer:** Only Management/Authorized Users can access this. Set `can_view_analytics` permission appropriately:

```sql
-- Grant to specific roles
UPDATE users SET can_view_analytics = 1 
WHERE role IN ('MIS', 'Admin');

-- Grant to department heads who need to monitor their team
UPDATE users SET can_view_analytics = 1 
WHERE is_head = 1 AND role IN ('Budget Office', 'Accounting Office');
```

---

## How the System Works

### Step 1: QR Tracking Collects Data (Existing)

Your system already collects this:
```
Document received by HR         → audit_logs entry: Scan-to-Receive
Document transferred to Budget  → audit_logs entry: Scan-to-Receive  
Document received at Budget     → audit_logs entry: Accepted
Document released               → audit_logs entry: Accepted
```

### Step 2: System Calculates Stay Time

```
HR → Budget: (Budget Received - Budget Released) = Processing Time
```

The `BottleneckAnalyzer::getDocumentSegmentTime()` method calculates this:
```php
$hours = (time_received_next_dept - time_accepted_current_dept) / 3600;
```

### Step 3: System Identifies Bottlenecks

The `identifyBottlenecks()` method:
1. Calculates 75th percentile processing time across all departments
2. Flags departments exceeding this threshold
3. Calculates percentage of delayed documents
4. Assesses risk level (LOW, MEDIUM, HIGH, CRITICAL)
5. Generates management recommendations

**Example Output:**
```
Department: Budget Office
- Risk Level: CRITICAL
- Avg Processing: 4.2 days (exceeds 2.5 day average)
- Delayed Documents: 12/30 (40%)
- Pending: 3 documents

Management Insight: "Budget Office is currently a potential bottleneck 
based on document processing time. 40% of documents experience delays. 
Consider investigating staffing levels, workload, or workflow procedures."
```

### Step 4: Management Takes Action

The system **doesn't automatically take action**. Management reviews the data and decides:
- Is the delay due to heavy workload?
- Are staff levels adequate?
- Are documents incomplete when arriving?
- Do procedures need improvement?
- Is additional training needed?

### Step 5: System Monitors Improvements

After intervention, track the metrics:

**Before:**
```
Processing Time: 4.2 days average
Delayed Documents: 40%
```

**After Intervention (e.g., added 1 staff member):**
```
Processing Time: 2.1 days average
Delayed Documents: 15%
```

This provides **measurable evidence** that the intervention worked.

---

## Views Explained

### 1. Overview View (Default)
- **Shows:** All department metrics at a glance
- **Users:** MIS, Admins, Department Heads (their dept only)
- **Displays:**
  - Average processing time per department
  - Total documents processed
  - Pending documents
  - Quick links to detailed analysis
  - Summary of identified bottlenecks with risk levels

### 2. Bottleneck Analysis View
- **Shows:** Departments with processing delays
- **Risk Levels:**
  - 🔴 CRITICAL: ≥50% delayed + ≥24 hours avg
  - 🟠 HIGH: ≥30% delayed + ≥12 hours avg
  - 🟡 MEDIUM: ≥10% delayed OR ≥8 hours avg
  - 🟢 LOW: Below thresholds
- **Data Included:**
  - Risk assessment
  - Detailed metrics (avg time, delayed count, pending)
  - Management recommendations
  - Investigation suggestions

### 3. Department Details View
- **Shows:** In-depth analysis for one department
- **Includes:**
  - List of delayed documents with processing times
  - Processing time trend chart (60-day history)
  - Status indicators (Completed vs Pending)
  - Historical performance comparison

---

## Database Schema

### processing_baselines Table
```sql
CREATE TABLE processing_baselines (
  id INT PRIMARY KEY AUTO_INCREMENT,
  department VARCHAR(100),
  document_type VARCHAR(100),
  expected_hours DECIMAL(10, 2),           -- Normal processing time
  warning_threshold_hours DECIMAL(10, 2),  -- When to warn
  critical_threshold_hours DECIMAL(10, 2), -- When to alert
  description TEXT,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

**Purpose:** Defines what "normal" processing time should be. Helps identify when delays are unusual.

### bottleneck_alerts Table
```sql
CREATE TABLE bottleneck_alerts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  department VARCHAR(100),
  alert_type ENUM('AT_RISK', 'CRITICAL', 'RESOLVED'),
  severity ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL'),
  avg_processing_hours DECIMAL(10, 2),
  delayed_document_count INT,
  delay_percentage DECIMAL(5, 2),
  pending_documents INT,
  description TEXT,
  recommendation TEXT,
  management_action TEXT,                  -- What management decided to do
  action_taken_at TIMESTAMP,
  action_taken_by_user_id INT,
  follow_up_date DATE,                     -- When to re-evaluate
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

**Purpose:** Tracks identified bottlenecks, recommendations, and management actions. Enables follow-up and impact measurement.

### processing_metrics_history Table
```sql
CREATE TABLE processing_metrics_history (
  id INT PRIMARY KEY AUTO_INCREMENT,
  department VARCHAR(100),
  measurement_date DATE,
  total_documents INT,
  completed_documents INT,
  pending_documents INT,
  avg_processing_hours DECIMAL(10, 2),
  min_processing_hours DECIMAL(10, 2),
  max_processing_hours DECIMAL(10, 2),
  documents_exceeding_threshold INT,
  delay_percentage DECIMAL(5, 2),
  created_at TIMESTAMP,
  UNIQUE KEY (department, measurement_date)
);
```

**Purpose:** Historical data for trend analysis. Shows whether processing times improve/deteriorate over time.

---

## API Reference

### BottleneckAnalyzer Class

**Constructor:**
```php
$analyzer = new BottleneckAnalyzer($conn, $user_id, $user_role, $is_head, $is_admin);
```

**Methods:**

**1. identifyBottlenecks($days = 30, $threshold_percentile = 75)**
Returns departments with unusual processing delays.
```php
$bottlenecks = $analyzer->identifyBottlenecks(30);
// Returns:
// [
//   ['department' => 'Budget Office', 'avg_stay_hours' => 48.5, 'delay_percentage' => 45, 'risk_level' => 'CRITICAL', ...],
//   ['department' => 'Accounting', 'avg_stay_hours' => 30, 'delay_percentage' => 25, 'risk_level' => 'HIGH', ...]
// ]
```

**2. getDepartmentStats($department = null, $days = 30)**
Returns processing statistics for departments.
```php
$stats = $analyzer->getDepartmentStats('Budget Office', 30);
// Returns:
// [
//   ['department' => 'Budget Office', 'total_documents' => 30, 'pending_documents' => 3, 'avg_stay_hours' => 24.5, ...]
// ]
```

**3. getDelayedDocuments($department, $hours_threshold = 24, $limit = 50)**
Returns documents that exceeded processing threshold.
```php
$delayed = $analyzer->getDelayedDocuments('Budget Office', 24, 50);
// Returns:
// [
//   ['voucher_code' => 'NAAP-2026-8740', 'stay_hours' => 48.5, 'received_at' => '2026-07-10 11:43:44', ...],
// ]
```

**4. getProcessingTimeTrend($department, $days = 60)**
Returns daily average processing time for trend analysis.
```php
$trend = $analyzer->getProcessingTimeTrend('Budget Office', 60);
// Returns:
// [
//   ['date' => '2026-07-01', 'avg_stay_hours' => 22.5, 'documents_processed' => 5],
//   ['date' => '2026-07-02', 'avg_stay_hours' => 24.3, 'documents_processed' => 6],
// ]
```

**5. getDocumentSegmentTime($voucher_code, $from_dept, $to_dept)**
Returns processing time for a document between two departments.
```php
$hours = $analyzer->getDocumentSegmentTime('NAAP-2026-8740', 'HR', 'Budget Office');
// Returns: 2.5 (hours)
```

---

## Integration with Existing DSS

### Existing Financial-Document Anomaly Monitoring
Your existing `analytics.php` tracks:
- ARTA compliance
- Document value anomalies
- Approval delays

### New Workflow Performance Monitoring
`analytics_bottleneck.php` adds:
- Processing time tracking
- Bottleneck identification
- Performance trend monitoring
- Management action tracking

### How They Work Together

```
Document Submission
    ↓
┌───────────────────────────────────────────┐
│  Financial-Document Anomaly DSS           │
│  - Unusual value changes?                 │
│  - Compliance deadline risks?             │
│  → Recommendation generated               │
└───────────────────────────────────────────┘
    ↓
┌───────────────────────────────────────────┐
│  Workflow Performance DSS                 │
│  - Processing time exceeding baseline?    │
│  - Bottleneck detected?                   │
│  → Operational improvement recommendation│
└───────────────────────────────────────────┘
```

**Example:** A document in Budget Office might trigger:
1. **Financial DSS Alert:** "Document value exceeds normal range by 25%"
2. **Workflow DSS Alert:** "Budget Office experiencing bottleneck (48-hour average)"

Management reviews both recommendations for complete decision-making context.

---

## Customization Guide

### Adjusting Processing Thresholds

Modify the baseline expectations for each department:

```sql
UPDATE processing_baselines 
SET expected_hours = 36,
    warning_threshold_hours = 48,
    critical_threshold_hours = 72
WHERE department = 'Budget Office';
```

### Customizing Risk Assessment

In `bottleneck_analyzer.php`, modify `assessRiskLevel()`:

```php
private function assessRiskLevel($stats) {
    $delay_pct = $stats['delay_percentage'];
    $avg_hours = $stats['avg_stay_hours'];
    
    // Customize thresholds for your organization
    if ($delay_pct >= 50 && $avg_hours >= 24) {
        return 'CRITICAL';
    } elseif ($delay_pct >= 30 && $avg_hours >= 12) {
        return 'HIGH';
    }
    // ... etc
}
```

### Adding Custom Recommendations

Modify `generateRecommendation()` in `BottleneckAnalyzer`:

```php
private function generateRecommendation($stats) {
    $dept = $stats['department'];
    $recommendations = [];
    
    if ($stats['avg_stay_hours'] >= 24) {
        $recommendations[] = "Investigate staffing levels...";
    }
    
    // Add your custom logic here
    if ($dept === 'Budget Office' && $stats['pending_documents'] > 5) {
        $recommendations[] = "Budget Office-specific: Check for incomplete budget requests...";
    }
    
    return implode(" | ", $recommendations);
}
```

---

## Troubleshooting

### No Data Appears in Analytics

**Check:**
1. Ensure `audit_logs` table has entries with `Scan-to-Receive` and `Accepted` actions
2. Verify `created_at` timestamps are recent (within selected time period)
3. Run: `SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY);`

### Incorrect Processing Times

**Causes:**
1. Missing `Scan-to-Receive` entry for received_at
2. Missing `Accepted` entry for completion
3. Timestamps in wrong timezone (should be Asia/Manila)

**Fix:**
```php
// Verify timestamps are being set correctly
SELECT voucher_code, department, action_taken, created_at 
FROM audit_logs 
WHERE voucher_code = 'NAAP-2026-8740' 
ORDER BY created_at;
```

### Access Denied Error

**Check:**
1. User's `can_view_analytics` permission: `SELECT can_view_analytics FROM users WHERE user_id = ?`
2. User's role and is_head status
3. RBAC logic in `checkAccessControl()`

**Grant access:**
```sql
UPDATE users SET can_view_analytics = 1 WHERE user_id = 5;
```

---

## Monitoring & Maintenance

### Regular Tasks

**Weekly:** Review bottleneck alerts for critical issues
**Monthly:** Run trend analysis to measure improvement effectiveness
**Quarterly:** Update processing baselines based on actual performance

### Automated Tasks (Can be scheduled via cron)

**Generate daily metrics:**
```sql
INSERT INTO processing_metrics_history
SELECT department, DATE(NOW()), COUNT(*), ... 
FROM audit_logs 
WHERE DATE(created_at) = DATE(NOW())
GROUP BY department;
```

**Generate bottleneck alerts:**
```php
// Run daily via cron
$conn->query("CALL sp_generate_bottleneck_alerts(30)");
```

---

## Audit Trail

All analytics access is logged for compliance:

```sql
SELECT * FROM analytics_access_log 
WHERE access_timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY access_timestamp DESC;
```

**Fields logged:**
- user_id & role
- Report accessed
- Department viewed
- Filters used
- Access timestamp

---

## Security Considerations

1. **Access Control:** RBAC enforced at class level
2. **SQL Injection:** Use prepared statements throughout
3. **Audit Trail:** All analytics access logged
4. **Data Privacy:** Users only see authorized department data
5. **Baseline Editing:** Only MIS/Admin can modify thresholds

---

## FAQ

**Q: Why doesn't the system automatically reassign documents from slow departments?**
A: This is by design. The system provides data; management makes decisions. Automatic actions could hide root causes (staffing, training, procedures) that need investigation.

**Q: Can department heads see other departments' data?**
A: No. RBAC restricts heads to their own department only (unless they have MIS role).

**Q: How often are bottlenecks recalculated?**
A: Real-time on demand. Views calculate based on latest audit_logs data.

**Q: What if a department legitimately needs long processing times?**
A: Update its baseline in `processing_baselines` table. The system will use the updated threshold.

**Q: Can we track bottlenecks by document type?**
A: Yes. `processing_baselines` supports document_type. Modify queries in `BottleneckAnalyzer` to filter by type.

---

## Next Steps

1. ✅ Apply the database migration
2. ✅ Add navigation links to the dashboard
3. ✅ Configure processing baselines for your organization
4. ✅ Grant RBAC permissions to authorized users
5. ✅ Test with historical data (30-60 days)
6. ✅ Review bottleneck alerts for accuracy
7. ✅ Train management on how to interpret the data
8. ✅ Establish a process for management action tracking
9. ✅ Schedule regular review meetings
10. ✅ Monitor system effectiveness and adjust thresholds as needed

---

## Support & Questions

For implementation questions, refer to:
- `bottleneck_analyzer.php` — Core logic and RBAC implementation
- `analytics_bottleneck.php` — UI and visualization
- `migration_bottleneck_system.sql` — Database schema
- This guide — Architecture and integration

The system is designed to be transparent, data-driven, and management-focused.

---

**Version:** 1.0  
**Last Updated:** 2026-09-02  
**Status:** Ready for Implementation
