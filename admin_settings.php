<?php
session_start();
// ACCESS CONTROL: Ensure only the correct admin role can access this page.
if (($_SESSION['role'] ?? '') !== 'Management Information System Office') {
    header("Location: home.php");
    exit();
}

require_once 'db_connect.php';

// Your existing PHP logic for handling form submissions for all sections would go here.
// For example, if a user is added, or settings are saved.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Administration - NAAP</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo @filemtime('sidebar.css'); ?>">
    <style>
        /* Styles for the tabbed interface */
        .main-content {
            /* Assuming you have a main content wrapper */
            padding: 20px 30px;
        }
        .tab-nav {
            display: flex;
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 25px;
        }
        .tab-button {
            padding: 12px 24px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px; /* Aligns with the container's border */
            transition: color 0.2s, border-color 0.2s;
        }
        .tab-button.active {
            color: #004a99; /* NAAP Navy */
            border-bottom-color: #004a99;
        }
        .tab-button:hover {
            color: #004a99;
            background-color: #f8f9fa;
        }
        .tab-panel {
            display: none; /* Hide all panels by default */
        }
        .tab-panel.active {
            display: block; /* Show the active panel */
        }
        /* Helper for section styling inside a tab panel */
        .admin-section {
            background-color: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .admin-section h2 {
            font-size: 1.5rem;
            margin: 0;
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
        }
        .admin-section-content {
            padding: 25px;
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
    <h1>System Administration</h1>
    <p>Institutional configuration, user management, and workflow protocols.</p>

    <!-- Tab Navigation -->
    <div class="tab-nav">
        <button class="tab-button" data-target="#tab-users-structure">Users & Structure</button>
        <button class="tab-button" data-target="#tab-workflows">Workflows & Templates</button>
        <button class="tab-button" data-target="#tab-settings">System Settings</button>
    </div>

    <!-- Tab Content Panels -->
    <div class="tab-content">
        <!-- ================================================== -->
        <!-- 1. USERS & STRUCTURE TAB -->
        <!-- ================================================== -->
        <div id="tab-users-structure" class="tab-panel">
            <div class="admin-section">
                <h2>User Management</h2>
                <div class="admin-section-content">
                    <!-- Your "User Account Provisioning" and "Manage Existing Accounts" PHP/HTML code goes here -->
                    <p><em>(Your user management forms and tables go here...)</em></p>
                </div>
            </div>
            <div class="admin-section">
                <h2>Institutional Structure</h2>
                <div class="admin-section-content">
                    <!-- Your "Departments & Job Titles" PHP/HTML code goes here -->
                    <p><em>(Your department and job title management UI goes here...)</em></p>
                </div>
            </div>
        </div>

        <!-- ================================================== -->
        <!-- 2. WORKFLOWS & TEMPLATES TAB -->
        <!-- ================================================== -->
        <div id="tab-workflows" class="tab-panel">
            <div class="admin-section">
                <h2>Document Type & Workflow Management</h2>
                <div class="admin-section-content">
                    <!-- Your "Add New Template" and "Existing Templates" PHP/HTML code goes here -->
                    <p><em>(Your document template management UI goes here...)</em></p>
                </div>
            </div>
            <div class="admin-section">
                <h2>Financial Voucher Types & Requirements</h2>
                <div class="admin-section-content">
                    <!-- Your "Add New Financial Type" and "Existing Financial Types" PHP/HTML code goes here -->
                    <p><em>(Your financial voucher type management UI goes here...)</em></p>
                </div>
            </div>
        </div>

        <!-- ================================================== -->
        <!-- 3. SYSTEM SETTINGS TAB -->
        <!-- ================================================== -->
        <div id="tab-settings" class="tab-panel">
            <div class="admin-section">
                <h2>Financial Guidelines</h2>
                <div class="admin-section-content">
                    <!-- Your "General Voucher Amount Guidelines" PHP/HTML code goes here -->
                    <p><em>(Your financial guidelines form goes here...)</em></p>
                </div>
            </div>
            <div class="admin-section">
                <h2>Decision Support System (DSS)</h2>
                <div class="admin-section-content">
                    <!-- Your "User History Analysis" PHP/HTML code goes here -->
                    <p><em>(Your DSS settings form goes here...)</em></p>
                </div>
            </div>
            <div class="admin-section">
                <h2>System Configuration</h2>
                <div class="admin-section-content">
                    <!-- Your "Holiday Management", "General System Settings", and "ARTA Level Management" PHP/HTML code goes here -->
                    <p><em>(Your holiday, general, and ARTA settings UI goes here...)</em></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabPanels = document.querySelectorAll('.tab-panel');

        function switchTab(targetId) {
            // Deactivate all panels and buttons
            tabPanels.forEach(panel => panel.classList.remove('active'));
            tabButtons.forEach(button => button.classList.remove('active'));

            // Activate the target panel and button
            const targetPanel = document.querySelector(targetId);
            const targetButton = document.querySelector(`[data-target="${targetId}"]`);

            if (targetPanel && targetButton) {
                targetPanel.classList.add('active');
                targetButton.classList.add('active');
                // Update URL hash without jumping for better UX and bookmarking
                if (history.pushState) {
                    history.pushState(null, null, targetId);
                } else {
                    window.location.hash = targetId;
                }
            }
        }

        tabButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = button.getAttribute('data-target');
                switchTab(targetId);
            });
        });

        // Handle page load: check for a hash or default to the first tab
        const currentHash = window.location.hash;
        if (currentHash && document.querySelector(currentHash)) {
            switchTab(currentHash);
        } else {
            // Activate the first tab by default
            if (tabButtons.length > 0) {
                switchTab(tabButtons[0].getAttribute('data-target'));
            }
        }
    });
</script>

</body>
</html>