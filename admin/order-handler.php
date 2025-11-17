<?php
// Complete output control
while (ob_get_level()) {
    ob_end_clean();
}

// Start clean
ob_start();

// Completely suppress all output and errors
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers immediately
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Now include config
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/AuditTrail.php';

// Clean any output that might have been generated
ob_clean();

// Function to output clean JSON
function outputJson($data) {
    // Clear any buffered output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start fresh
    ob_start();
    
    // Output only JSON
    echo json_encode($data);
    
    // End and flush
    ob_end_flush();
    exit();
}

// Check admin login without redirect
if (!isAdminLoggedIn()) {
    outputJson(['success' => false, 'message' => 'Admin login required']);
}

try {
    $db = getDB();
    $action = $_REQUEST['action'] ?? '';
    
    // Debug logging (only to error log, not output)
    error_log('Order handler called with action: ' . $action);
    error_log('POST data: ' . json_encode($_POST));
    
    switch ($action) {
        case 'get_details':
            handleGetOrderDetails($db);
            break;
            
        case 'update_status':
            handleUpdateStatus($db);
            break;
            
        default:
            outputJson(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Order handler error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    outputJson(['success' => false, 'message' => 'Server error occurred']);
}

function handleGetOrderDetails($db) {
    $orderId = $_GET['id'] ?? '';
    
    if (empty($orderId)) {
        echo json_encode(['success' => false, 'message' => 'Order ID required']);
        return;
    }
    
    try {
        // Get order details with customer information
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
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            return;
        }
        
        // Get order items with product details
        $itemsQuery = "SELECT oi.*, p.name as product_name, p.description as product_description
                       FROM order_items oi
                       LEFT JOIN products p ON oi.product_id = p.id
                       WHERE oi.order_id = :order_id";
        
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bindParam(':order_id', $orderId);
        $itemsStmt->execute();
        
        $items = $itemsStmt->fetchAll();
        
        // Get order status history
        $historyQuery = "SELECT * FROM order_status_history 
                        WHERE order_id = :order_id 
                        ORDER BY created_at ASC";
        
        $historyStmt = $db->prepare($historyQuery);
        $historyStmt->bindParam(':order_id', $orderId);
        $historyStmt->execute();
        
        $history = $historyStmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items,
            'history' => $history
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error loading order details']);
    }
}

function handleUpdateStatus($db) {
    $orderId = intval($_POST['id'] ?? 0);
    $newStatus = sanitizeInput($_POST['status'] ?? '');
    
    // Debug logging (to error log only)
    error_log('handleUpdateStatus called with orderId: ' . $orderId . ', newStatus: ' . $newStatus);
    
    if ($orderId <= 0 || empty($newStatus)) {
        error_log('Invalid parameters - orderId: ' . $orderId . ', newStatus: ' . $newStatus);
        outputJson(['success' => false, 'message' => 'Invalid order ID or status']);
    }
    
    // Validate status
    $validStatuses = ['pending', 'processing', 'paid', 'shipped', 'completed', 'cancelled'];
    if (!in_array($newStatus, $validStatuses)) {
        outputJson(['success' => false, 'message' => 'Invalid status']);
    }
    
    try {
        // Get current order details
        $currentQuery = "SELECT * FROM orders WHERE id = :id";
        $currentStmt = $db->prepare($currentQuery);
        $currentStmt->bindParam(':id', $orderId);
        $currentStmt->execute();
        
        $currentOrder = $currentStmt->fetch();
        
        if (!$currentOrder) {
            outputJson(['success' => false, 'message' => 'Order not found']);
        }
        
        $db->beginTransaction();
        
        try {
            // Update order status
            $updateQuery = "UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':status', $newStatus);
            $updateStmt->bindParam(':id', $orderId);
            
            if ($updateStmt->execute()) {
                // Log status change in history
                $historyQuery = "INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, notes, created_at) 
                                VALUES (:order_id, :old_status, :new_status, :changed_by, :notes, NOW())";
                
                $historyStmt = $db->prepare($historyQuery);
                $historyStmt->bindParam(':order_id', $orderId);
                $historyStmt->bindParam(':old_status', $currentOrder['status']);
                $historyStmt->bindParam(':new_status', $newStatus);
                $historyStmt->bindParam(':changed_by', $_SESSION['admin_user_id']);
                
                $notes = "Status updated by admin";
                if ($newStatus === 'paid') {
                    $notes = "Payment approved by admin";
                } elseif ($newStatus === 'pending' && $currentOrder['status'] !== 'pending') {
                    $notes = "Order rejected and set back to pending";
                } elseif ($newStatus === 'shipped') {
                    $notes = "Order marked as shipped";
                } elseif ($newStatus === 'completed') {
                    $notes = "Order marked as completed";
                } elseif ($newStatus === 'cancelled') {
                    $notes = "Order cancelled by admin";
                }
                
                $historyStmt->bindParam(':notes', $notes);
                $historyStmt->execute();
                
                // Auto stock deduction when order is marked as Paid
                if ($newStatus === 'paid' && $currentOrder['status'] !== 'paid') {
                    $stockResult = deductStock($db, $orderId);
                    if (!$stockResult['success']) {
                        $db->rollback();
                        echo json_encode(['success' => false, 'message' => $stockResult['message']]);
                        return;
                    }
                }
                
                // TODO: Send SMS notification via Brevo API
                // This will be implemented when Brevo API integration is ready
                
                $db->commit();

                // Audit log for order status change
                (new AuditTrail())->logOrderChange('update', (int)$orderId, [
                    'status' => $currentOrder['status']
                ], [
                    'status' => $newStatus
                ]);
                
                $statusMessages = [
                    'pending' => 'Order set back to pending',
                    'processing' => 'Order marked as processing',
                    'paid' => 'Order approved and marked as paid. Stock deducted automatically.',
                    'shipped' => 'Order marked as shipped',
                    'completed' => 'Order marked as completed',
                    'cancelled' => 'Order cancelled'
                ];
                
                outputJson([
                    'success' => true,
                    'message' => $statusMessages[$newStatus] ?? 'Order status updated successfully'
                ]);
            } else {
                $db->rollback();
                outputJson(['success' => false, 'message' => 'Failed to update order status']);
            }
            
        } catch (Exception $e) {
            $db->rollback();
            error_log('Order update error: ' . $e->getMessage());
            outputJson(['success' => false, 'message' => 'Error updating order status']);
        }
        
    } catch (Exception $e) {
        error_log('Order update outer error: ' . $e->getMessage());
        outputJson(['success' => false, 'message' => 'Error updating order status']);
    }
}

function deductStock($db, $orderId) {
    try {
        // Get order items
        $itemsQuery = "SELECT oi.product_id, oi.quantity, p.name as product_name, p.stock_quantity
                       FROM order_items oi
                       LEFT JOIN products p ON oi.product_id = p.id
                       WHERE oi.order_id = :order_id";
        
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bindParam(':order_id', $orderId);
        $itemsStmt->execute();
        
        $items = $itemsStmt->fetchAll();
        
        foreach ($items as $item) {
            // Check if enough stock is available
            if ($item['stock_quantity'] < $item['quantity']) {
                return [
                    'success' => false,
                    'message' => "Insufficient stock for {$item['product_name']}. Available: {$item['stock_quantity']}, Required: {$item['quantity']}"
                ];
            }
        }
        
        // Deduct stock for each item
        foreach ($items as $item) {
            $newStock = $item['stock_quantity'] - $item['quantity'];
            
            // Update product stock
            $updateStockQuery = "UPDATE products SET stock_quantity = :new_stock, updated_at = NOW() WHERE id = :product_id";
            $updateStockStmt = $db->prepare($updateStockQuery);
            $updateStockStmt->bindParam(':new_stock', $newStock);
            $updateStockStmt->bindParam(':product_id', $item['product_id']);
            $updateStockStmt->execute();
            
            // Log inventory change
            $logQuery = "INSERT INTO inventory_logs (product_id, action, old_quantity, quantity_change, new_quantity, reason, notes, updated_by, created_at) 
                        VALUES (:product_id, 'decrease', :old_quantity, :quantity_change, :new_quantity, 'order_payment', :notes, :updated_by, NOW())";
            
            $logStmt = $db->prepare($logQuery);
            $logStmt->bindParam(':product_id', $item['product_id']);
            $logStmt->bindParam(':old_quantity', $item['stock_quantity']);
            $logStmt->bindParam(':quantity_change', $item['quantity']);
            $logStmt->bindParam(':new_quantity', $newStock);
            $logStmt->bindParam(':notes', "Stock deducted for order #$orderId");
            $logStmt->bindParam(':updated_by', $_SESSION['admin_user_id']);
            $logStmt->execute();

            // Audit log per product stock deduction (optional granularity)
            (new AuditTrail())->logProductChange('update', (int)$item['product_id'], [
                'stock_quantity' => (int)$item['stock_quantity']
            ], [
                'stock_quantity' => (int)$newStock
            ]);
        }
        
        return ['success' => true, 'message' => 'Stock deducted successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error deducting stock: ' . $e->getMessage()];
    }
}

// TODO: SMS notification function via Brevo API
function sendSMSNotification($phoneNumber, $message) {
    // This will be implemented when Brevo API integration is ready
    // For now, we'll just log the notification
    error_log("SMS Notification to $phoneNumber: $message");
    return true;
}

function deductStock($db, $orderId) {
    try {
        // Get order items
        $itemsQuery = "SELECT oi.product_id, oi.quantity, p.name as product_name, p.stock_quantity 
                      FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE oi.order_id = :order_id";
        
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bindParam(':order_id', $orderId);
        $itemsStmt->execute();
        $orderItems = $itemsStmt->fetchAll();
        
        if (empty($orderItems)) {
            return ['success' => false, 'message' => 'No items found in order'];
        }
        
        // Check if all items have sufficient stock
        foreach ($orderItems as $item) {
            if ($item['stock_quantity'] < $item['quantity']) {
                return [
                    'success' => false, 
                    'message' => "Insufficient stock for {$item['product_name']}. Available: {$item['stock_quantity']}, Required: {$item['quantity']}"
                ];
            }
        }
        
        // Deduct stock for each item
        foreach ($orderItems as $item) {
            $newStock = $item['stock_quantity'] - $item['quantity'];
            
            // Update product stock
            $updateQuery = "UPDATE products SET stock_quantity = :new_stock, updated_at = NOW() WHERE id = :product_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':new_stock', $newStock);
            $updateStmt->bindParam(':product_id', $item['product_id']);
            $updateStmt->execute();
            
            // Log inventory change
            $logQuery = "INSERT INTO inventory_logs (product_id, action_type, old_quantity, quantity_changed, new_quantity, reason, notes, updated_by, created_at) 
                        VALUES (:product_id, 'decrease', :old_quantity, :quantity_changed, :new_quantity, :reason, :notes, :updated_by, NOW())";
            
            $logStmt = $db->prepare($logQuery);
            $logStmt->bindParam(':product_id', $item['product_id']);
            $logStmt->bindParam(':old_quantity', $item['stock_quantity']);
            $logStmt->bindParam(':quantity_changed', $item['quantity']);
            $logStmt->bindParam(':new_quantity', $newStock);
            $logStmt->bindParam(':reason', $reason = 'Order fulfillment');
            $logStmt->bindParam(':notes', $notes = "Stock deducted for order #$orderId");
            $logStmt->bindParam(':updated_by', $_SESSION['admin_user_id']);
            $logStmt->execute();
        }
        
        return ['success' => true, 'message' => 'Stock deducted successfully'];
        
    } catch (Exception $e) {
        error_log('Stock deduction error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error deducting stock: ' . $e->getMessage()];
    }
}
?>
