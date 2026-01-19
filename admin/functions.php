<?php

// ---------------------
// TOTAL PROFIT
// ---------------------
function getTotalProfit() {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT SUM(net_amount) AS total FROM transactions";
    $stmt = $db->prepare($query);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return $row['total'] ?? 0;
}

// ---------------------
// PRODUCT COUNT
// ---------------------
function getProductCount() {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT COUNT(*) AS count FROM products";
    $stmt = $db->prepare($query);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return $row['count'] ?? 0;
}

// ---------------------
// MONTHLY TRANSACTION COUNT
// ---------------------
function getTransactionCount() {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT COUNT(*) AS count 
              FROM transactions 
              WHERE MONTH(transaction_date) = MONTH(CURRENT_DATE())";
    
    $stmt = $db->prepare($query);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return $row['count'] ?? 0;
}

// ---------------------
// PROFIT MARGIN
// ---------------------
function calculateProfitMargin($income) {
    // Example: assume 60% expenses
    $expenses = $income * 0.6;
    $profit = $income - $expenses;

    return $income > 0 ? round(($profit / $income) * 100, 2) : 0;
}

?>
