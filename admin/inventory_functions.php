<?php
// inventory_functions.php - Additional functions for inventory management

// Function to update inventory period closing balance
function updateInventoryPeriodClosingBalance($db, $period_id, $user_id) {
    // Get period data
    $sql = "SELECT opening_balance, period_month FROM inventory_periods WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $period_id);
    $stmt->execute();
    $period = $stmt->get_result()->fetch_assoc();
    
    if (!$period) return false;
    
    // Calculate current inventory value
    list($year, $month) = explode('-', $period['period_month']);
    $current_inventory = calculateCurrentInventoryValue($db, $user_id, $period['period_month']);
    
    // Calculate closing balance
    $closing_balance = $period['opening_balance'] + $current_inventory;
    
    // Update period
    $update_sql = "UPDATE inventory_periods 
                   SET current_inventory = ?, closing_balance = ?, updated_at = NOW()
                   WHERE id = ?";
    $stmt = $db->prepare($update_sql);
    $stmt->bind_param("ddi", $current_inventory, $closing_balance, $period_id);
    return $stmt->execute();
}

// Function to close a period and create new one
function closeAndCreateNewPeriod($db, $user_id, $period_id) {
    // Close current period
    $close_sql = "UPDATE inventory_periods SET status = 'closed', updated_at = NOW() WHERE id = ?";
    $stmt = $db->prepare($close_sql);
    $stmt->bind_param("i", $period_id);
    $stmt->execute();
    
    // Get closed period data
    $period_sql = "SELECT * FROM inventory_periods WHERE id = ?";
    $stmt = $db->prepare($period_sql);
    $stmt->bind_param("i", $period_id);
    $stmt->execute();
    $closed_period = $stmt->get_result()->fetch_assoc();
    
    // Create new period for next month
    $next_month = date('Y-m', strtotime($closed_period['period_month'] . '-01 +1 month'));
    $opening_balance = $closed_period['closing_balance'];
    
    $insert_sql = "INSERT INTO inventory_periods 
                  (user_id, period_month, opening_balance, current_inventory, closing_balance, status) 
                  VALUES (?, ?, ?, 0, ?, 'active')";
    $stmt = $db->prepare($insert_sql);
    $stmt->bind_param("isdd", $user_id, $next_month, $opening_balance, $opening_balance);
    
    if ($stmt->execute()) {
        $new_period_id = $stmt->insert_id;
        
        // Carry forward stock
        carryForwardStock($db, $user_id, $period_id, $new_period_id);
        
        return $new_period_id;
    }
    
    return false;
}

// Function to get inventory summary for dashboard
function getInventorySummary($db, $user_id) {
    $summary = [
        'total_products' => 0,
        'total_value' => 0,
        'low_stock_count' => 0,
        'out_of_stock_count' => 0,
        'recent_sales' => 0,
        'total_profit' => 0
    ];
    
    // Get product statistics
    $sql = "SELECT 
                COUNT(*) as total_products,
                SUM(stock_quantity * cost_price) as total_value,
                SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= min_stock THEN 1 ELSE 0 END) as low_stock_count
            FROM products 
            WHERE created_by = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $product_stats = $stmt->get_result()->fetch_assoc();
    
    if ($product_stats) {
        $summary['total_products'] = $product_stats['total_products'] ?? 0;
        $summary['total_value'] = $product_stats['total_value'] ?? 0;
        $summary['low_stock_count'] = $product_stats['low_stock_count'] ?? 0;
        $summary['out_of_stock_count'] = $product_stats['out_of_stock_count'] ?? 0;
    }
    
    // Get recent sales (last 30 days)
    $sales_sql = "SELECT 
                    COALESCE(SUM(ti.total_price), 0) as recent_sales,
                    COALESCE(SUM(ti.total_price - (ti.quantity * p.cost_price)), 0) as total_profit
                  FROM transaction_items ti
                  JOIN products p ON ti.product_id = p.id
                  JOIN transactions t ON ti.transaction_id = t.id
                  WHERE p.created_by = ? 
                  AND t.transaction_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $stmt = $db->prepare($sales_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $sales_stats = $stmt->get_result()->fetch_assoc();
    
    if ($sales_stats) {
        $summary['recent_sales'] = $sales_stats['recent_sales'] ?? 0;
        $summary['total_profit'] = $sales_stats['total_profit'] ?? 0;
    }
    
    return $summary;
}

// Function to get top selling products
function getTopSellingProducts($db, $user_id, $limit = 5) {
    $sql = "SELECT 
                p.id,
                p.name,
                p.sku,
                c.name as category_name,
                SUM(ti.quantity) as total_sold,
                SUM(ti.total_price) as total_revenue,
                SUM(ti.total_price - (ti.quantity * p.cost_price)) as total_profit
            FROM transaction_items ti
            JOIN products p ON ti.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.created_by = ?
            GROUP BY p.id, p.name, p.sku, c.name
            ORDER BY total_sold DESC
            LIMIT ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Function to get stock alerts
function getStockAlerts($db, $user_id) {
    $sql = "SELECT 
                p.id,
                p.name,
                p.sku,
                p.stock_quantity,
                p.min_stock,
                c.name as category_name,
                (p.stock_quantity * p.cost_price) as stock_value,
                CASE 
                    WHEN p.stock_quantity <= 0 THEN 'out'
                    WHEN p.stock_quantity <= p.min_stock THEN 'low'
                    ELSE 'ok'
                END as status
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.created_by = ? 
            AND (p.stock_quantity <= 0 OR p.stock_quantity <= p.min_stock)
            ORDER BY p.stock_quantity ASC, p.name ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Function to get inventory value by category
function getInventoryValueByCategory($db, $user_id) {
    $sql = "SELECT 
                c.id,
                c.name as category_name,
                COUNT(p.id) as product_count,
                SUM(p.stock_quantity * p.cost_price) as total_value,
                SUM(p.stock_quantity) as total_quantity
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.created_by = ?
            GROUP BY c.id, c.name
            ORDER BY total_value DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Function to get monthly inventory trend
function getMonthlyInventoryTrend($db, $user_id, $months = 6) {
    $sql = "SELECT 
                DATE_FORMAT(p.created_at, '%Y-%m') as month,
                COUNT(p.id) as products_added,
                SUM(p.stock_quantity * p.cost_price) as inventory_value,
                SUM(p.stock_quantity) as total_quantity
            FROM products p
            WHERE p.created_by = ?
            AND p.created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(p.created_at, '%Y-%m')
            ORDER BY month DESC
            LIMIT ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iii", $user_id, $months, $months);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>