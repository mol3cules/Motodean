<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/AuditTrail.php';

// Require admin login
requireAdminLogin();

// Only admins and staff can export audit trail
if (!in_array($_SESSION['admin_user_role'], ['admin', 'staff'])) {
    header('Location: audit-trail.php?message=access_denied');
    exit();
}

// Initialize AuditTrail
$auditTrail = new AuditTrail();

// Handle filters (same as audit-trail.php)
$filters = [];
if (!empty($_GET['action_type'])) {
    $filters['action_type'] = $_GET['action_type'];
}
if (!empty($_GET['entity_type'])) {
    $filters['entity_type'] = $_GET['entity_type'];
}
if (!empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}
if (!empty($_GET['user_email'])) {
    $filters['user_email'] = $_GET['user_email'];
}
if (!empty($_GET['search'])) {
    $filters['search'] = $_GET['search'];
}

// Get audit logs
$auditLogs = $auditTrail->getAuditLogs($filters);

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="audit_trail_' . date('Y-m-d_H-i-s') . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Open output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV headers
fputcsv($output, [
    'Timestamp',
    'User Email',
    'User Role',
    'Action Type',
    'Entity Type',
    'Entity ID',
    'Details',
    'IP Address',
    'User Agent',
    'Session ID'
]);

// Write audit log data
foreach ($auditLogs as $log) {
    fputcsv($output, [
        $log['timestamp'],
        $log['user_email'],
        $log['user_role'],
        $log['action_type'],
        $log['entity_type'],
        $log['entity_id'] ?? '',
        $log['additional_info'] ?? '',
        $log['ip_address'],
        $log['user_agent'] ?? '',
        $log['session_id'] ?? ''
    ]);
}

// Log this export action
$auditTrail->log('export', 'system', null, null, null, 'Exported audit trail CSV');

fclose($output);
exit();
?>
