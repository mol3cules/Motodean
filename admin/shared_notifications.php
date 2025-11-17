<?php
// Shared notification functionality for all admin pages

// Get notification data
function getAdminNotifications($db) {
    $notifications = [
        'lowStockNotifications' => [],
        'outOfStockNotifications' => [],
        'unavailableProductNotifications' => [],
        'pendingOrderNotifications' => [],
        'paidOrderNotifications' => [],
        'totalNotifications' => 0
    ];
    
    try {
        // Low stock products (stock < reorder_threshold and available) - Get ALL
        $lowStockNotifQuery = "SELECT name, stock_quantity, reorder_threshold 
                              FROM products 
                              WHERE stock_quantity < reorder_threshold 
                              AND stock_quantity > 0 
                              AND is_available = 1 
                              ORDER BY (stock_quantity - reorder_threshold) ASC";
        $lowStockNotifStmt = $db->prepare($lowStockNotifQuery);
        $lowStockNotifStmt->execute();
        $notifications['lowStockNotifications'] = $lowStockNotifStmt->fetchAll();
        
        // Out of stock products (available but no stock) - Get ALL
        $outOfStockNotifQuery = "SELECT name, reorder_threshold 
                                FROM products 
                                WHERE stock_quantity = 0 
                                AND is_available = 1 
                                ORDER BY reorder_threshold DESC";
        $outOfStockNotifStmt = $db->prepare($outOfStockNotifQuery);
        $outOfStockNotifStmt->execute();
        $notifications['outOfStockNotifications'] = $outOfStockNotifStmt->fetchAll();
        
        // Unavailable products (admin disabled)
        $unavailableNotifQuery = "SELECT name, stock_quantity FROM products WHERE is_available = 0 LIMIT 5";
        $unavailableNotifStmt = $db->prepare($unavailableNotifQuery);
        $unavailableNotifStmt->execute();
        $notifications['unavailableProductNotifications'] = $unavailableNotifStmt->fetchAll();
        
        // Pending orders
        $pendingOrderNotifQuery = "SELECT o.order_number, CONCAT(u.first_name, ' ', u.last_name) as customer_name, o.total_amount, o.created_at 
                                   FROM orders o 
                                   LEFT JOIN users u ON o.user_id = u.id 
                                   WHERE o.status = 'pending' 
                                   ORDER BY o.created_at DESC LIMIT 5";
        $pendingOrderNotifStmt = $db->prepare($pendingOrderNotifQuery);
        $pendingOrderNotifStmt->execute();
        $notifications['pendingOrderNotifications'] = $pendingOrderNotifStmt->fetchAll();
        
        // Paid orders
        $paidOrderNotifQuery = "SELECT o.order_number, CONCAT(u.first_name, ' ', u.last_name) as customer_name, o.total_amount, o.created_at 
                               FROM orders o 
                               LEFT JOIN users u ON o.user_id = u.id 
                               WHERE o.status = 'paid' 
                               ORDER BY o.created_at DESC LIMIT 5";
        $paidOrderNotifStmt = $db->prepare($paidOrderNotifQuery);
        $paidOrderNotifStmt->execute();
        $notifications['paidOrderNotifications'] = $paidOrderNotifStmt->fetchAll();
        
        // Calculate total notification count
        $notifications['totalNotifications'] = count($notifications['lowStockNotifications']) + 
                                              count($notifications['outOfStockNotifications']) + 
                                              count($notifications['unavailableProductNotifications']) + 
                                              count($notifications['pendingOrderNotifications']) + 
                                              count($notifications['paidOrderNotifications']);
        
    } catch (Exception $e) {
        error_log('Notification query error: ' . $e->getMessage());
    }
    
    return $notifications;
}
?>
