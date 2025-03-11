<?php

// Database connection details
$host = '127.0.0.1'; // Update with your DB host
$dbname = 'AWSTagging'; // Update with your DB name
$username = 'root'; // Update with your DB username
$password = 'root'; // Update with your DB password

try {
    // Establish PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch table column metadata
    $table = 'taggables'; // Replace with your table name
    $query = $pdo->prepare("DESCRIBE `$table`");
    $query->execute();
    $columns = $query->fetchAll(PDO::FETCH_COLUMN);

    // Update columns containing "(not tagged)" to NULL
    foreach ($columns as $column) {
        $updateStmt = $pdo->prepare("
            UPDATE `$table` 
            SET `$column` = NULL 
            WHERE `$column` LIKE '%(not tagged)%'
        ");
        $updateStmt->execute();
    }

    echo "All fields containing '(not tagged)' have been cleared (set to NULL).";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
