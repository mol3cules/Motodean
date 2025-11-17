<?php
// Clean order handler using same config as main application

// Complete output control
while (ob_get_level()) {
    ob_end_clean();
}

// Suppress all output except JSON
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Start fresh buffer
ob_start();

// Use same config as main application
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/AuditTrail.php';

// Clean any output from config
ob_clean();

// Check admin login (using same function as main app)
if (!isAdminLoggedIn()) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Admin login required']);
    exit();
}

// Get database connection (using same method as main app)
try {
    $db = getDB();
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get request data
$orderId = intval($_REQUEST['id'] ?? 0);
$newStatus = trim($_REQUEST['status'] ?? '');
$action = trim($_REQUEST['action'] ?? '');

// Validate CSRF token for state-changing actions
if ($action === 'update_status') {
    $csrfToken = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
        exit();
    }
}

// Handle different actions
if ($action === 'get_details') {
    handleGetDetails($db, $orderId);
} elseif ($action === 'update_status') {
    handleUpdateStatus($db, $orderId, $newStatus);
} else {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

function handleGetDetails($db, $orderId) {
    if ($orderId <= 0) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit();
    }
    
    try {
        // Use exact same query as the working original
        $orderQuery = "SELECT o.*, u.first_name, u.last_name, u.email, u.phone_number,
                              CONCAT(u.first_name, ' ', u.last_name) as customer_name
                       FROM orders o 
                       LEFT JOIN users u ON o.user_id = u.id 
                       WHERE o.id = :id";
        
        $orderStmt = $db->prepare($orderQuery);
        $orderStmt->bindParam(':id', $orderId);
        $orderStmt->execute();
        
        $order = $orderStmt->fetch();
        
        if (!$order) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        // Get order items
        $itemsQuery = "SELECT oi.*, p.name as product_name, p.price as unit_price
                       FROM order_items oi
                       JOIN products p ON oi.product_id = p.id
                       WHERE oi.order_id = :order_id";
        
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bindParam(':order_id', $orderId);
        $itemsStmt->execute();
        $items = $itemsStmt->fetchAll();
        
        // Skip history since table doesn't exist
        $history = [];
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items,
            'history' => $history
        ]);
        exit();
        
    } catch (Exception $e) {
        error_log('Get order details error: ' . $e->getMessage());
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Error loading order details: ' . $e->getMessage()]);
        exit();
    }
}

function handleUpdateStatus($db, $orderId, $newStatus) {
    // Validate
    if ($orderId <= 0 || empty($newStatus)) {
        error_log("Validation failed - OrderID: $orderId, NewStatus: '$newStatus'");
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit();
    }

    // Validate status
    $validStatuses = ['pending', 'processing', 'paid', 'shipped', 'completed', 'cancelled'];
    if (!in_array($newStatus, $validStatuses)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }

    try {
        // Get current order (using same query pattern as main app)
        $currentQuery = "SELECT * FROM orders WHERE id = :id";
        $currentStmt = $db->prepare($currentQuery);
        $currentStmt->bindParam(':id', $orderId);
        $currentStmt->execute();
        
        $currentOrder = $currentStmt->fetch();
        
        if (!$currentOrder) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        $db->beginTransaction();
        
        // Update order status (using same pattern as main app)
        $updateQuery = "UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':status', $newStatus);
        $updateStmt->bindParam(':id', $orderId);
        
        $updated = $updateStmt->execute();
        
        // Log the update attempt
        error_log("Order update attempt - ID: $orderId, Status: $newStatus, Updated: " . ($updated ? 'YES' : 'NO'));
        error_log("Rows affected: " . $updateStmt->rowCount());
        
        if (!$updated || $updateStmt->rowCount() === 0) {
            $db->rollback();
            error_log("Order update failed - No rows affected");
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to update order - order may not exist']);
            exit();
        }
        
        error_log("Order $orderId successfully updated to status: $newStatus");
        
        // Skip order history for now since table doesn't exist
        
        // Deduct stock when:
        // 1. Moving to completed (if stock hasn't been deducted yet)
        // 2. Moving from processing to paid (regular orders)
        // 3. Moving from processing to shipped (COD orders)
        $shouldDeductStock = ($newStatus === 'completed' && !in_array($currentOrder['status'], ['paid', 'shipped', 'completed'])) ||
                            ($newStatus === 'paid' && $currentOrder['status'] === 'processing') || 
                            ($newStatus === 'shipped' && $currentOrder['status'] === 'processing');
        
        if ($shouldDeductStock) {
            error_log("Stock deduction triggered for Order #$orderId (Status: {$currentOrder['status']} → $newStatus)");
            
            // Get order items with product names for logging
            $itemsQuery = "SELECT oi.product_id, oi.quantity, p.stock_quantity, p.name as product_name 
                          FROM order_items oi 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE oi.order_id = :order_id";
            
            $itemsStmt = $db->prepare($itemsQuery);
            $itemsStmt->bindParam(':order_id', $orderId);
            $itemsStmt->execute();
            $items = $itemsStmt->fetchAll();
            
            error_log("Found " . count($items) . " items in order #$orderId");
            
            // Check stock availability
            foreach ($items as $item) {
                error_log("Checking stock for {$item['product_name']} (ID: {$item['product_id']}): Stock={$item['stock_quantity']}, Ordered={$item['quantity']}");
                
                if ($item['stock_quantity'] < $item['quantity']) {
                    $db->rollback();
                    error_log("INSUFFICIENT STOCK: {$item['product_name']} - Available: {$item['stock_quantity']}, Needed: {$item['quantity']}");
                    ob_clean();
                    echo json_encode(['success' => false, 'message' => "Insufficient stock for {$item['product_name']}. Available: {$item['stock_quantity']}, Needed: {$item['quantity']}"]);
                    exit();
                }
            }
            
            // Deduct stock
            foreach ($items as $item) {
                $newStock = $item['stock_quantity'] - $item['quantity'];
                $stockUpdateQuery = "UPDATE products SET stock_quantity = :new_stock, updated_at = NOW() WHERE id = :id";
                $stockUpdateStmt = $db->prepare($stockUpdateQuery);
                $stockUpdateStmt->bindParam(':new_stock', $newStock, PDO::PARAM_INT);
                $stockUpdateStmt->bindParam(':id', $item['product_id'], PDO::PARAM_INT);
                $stockUpdateStmt->execute();
                
                error_log("✓ Stock deducted for {$item['product_name']} (ID: {$item['product_id']}): {$item['stock_quantity']} → $newStock");

                // Audit log per product stock deduction
                (new AuditTrail())->logProductChange('update', (int)$item['product_id'], [
                    'stock_quantity' => (int)$item['stock_quantity']
                ], [
                    'stock_quantity' => (int)$newStock
                ]);
            }
            
            error_log("Stock deduction completed successfully for Order #$orderId");
        } else {
            error_log("Stock deduction skipped for Order #$orderId (Status: {$currentOrder['status']} → $newStatus)");
        }
        
        $db->commit();

        // Audit log for order status change
        (new AuditTrail())->logOrderChange('update', (int)$orderId, [
            'status' => $currentOrder['status']
        ], [
            'status' => $newStatus
        ]);
        
        $messages = [
            'pending' => 'Order set back to pending',
            'processing' => 'Order moved to processing status',
            'paid' => 'Order payment confirmed. Stock deducted automatically.',
            'shipped' => $shouldDeductStock ? 'COD order marked as shipped. Stock deducted automatically.' : 'Order marked as shipped',
            'completed' => $shouldDeductStock ? 'Order completed successfully. Stock deducted from inventory.' : 'Order completed successfully',
            'cancelled' => 'Order cancelled'
        ];
        
        // Verify the update worked
        $verifyQuery = "SELECT status FROM orders WHERE id = :id";
        $verifyStmt = $db->prepare($verifyQuery);
        $verifyStmt->bindParam(':id', $orderId);
        $verifyStmt->execute();
        $verifyOrder = $verifyStmt->fetch();
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => $messages[$newStatus] ?? 'Order status updated successfully',
            'orderId' => $orderId,
            'oldStatus' => $currentOrder['status'],
            'newStatus' => $newStatus,
            'verifiedStatus' => $verifyOrder['status'] ?? 'unknown'
        ]);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        error_log('Order status update error: ' . $e->getMessage());
        error_log('Error trace: ' . $e->getTraceAsString());
        ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => 'Error updating order status', 
            'error' => $e->getMessage(),
            'orderId' => $orderId,
            'status' => $newStatus
        ]);
        exit();
    }
}

exit();
?>