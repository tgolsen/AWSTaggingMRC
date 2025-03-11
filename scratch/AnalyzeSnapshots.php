<?php

// Database connection details
$host = '127.0.0.1'; // Update with your DB host
$dbname = 'AWSTagging'; // Update with your DB name
$username = 'root'; // Update with your DB username
$password = 'root'; // Update with your DB password

try {
    // Establish a connection
    echo "Connecting to database...\n";
    echo "mysql:host=$host;dbname=$dbname;charset=utf8;$username;$password\n";
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    echo "Connected!\n\n";

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Query to get records from the `taggables` table where `Type = 'Snapshot'`
    $stmt = $pdo->prepare("SELECT * FROM taggables WHERE Type = 'Snapshot'"); // AND helpful_string IS NULL");
    $stmt->execute();

    // Fetch all rows
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;

    foreach ($rows as $row) {

        $helpful_string = '';

//        var_dump($row);
//        echo "\n";
//        die;

        // Assume 'Identifier' is the Snapshot ID (adjust field name if necessary)
        $snapshotId = $row['Identifier'];

        if (!$snapshotId) {
            echo "No Identifier found for Snapshot row ID: {$row['Identifier']}\n";
            continue;
        }

//        echo "Processing Snapshot ID: {$snapshotId}\n";

        // Fetch Snapshot description using AWS CLI
        // Command: aws --profile=mrc ec2 describe-snapshots --snapshot-ids [...] --query 'Snapshots[0].Description' --output text
        $command = "aws --profile=mrc ec2 describe-snapshots --snapshot-ids {$snapshotId} --query 'Snapshots[0].Description' --output text 2> /dev/null";
        $description = trim("" . shell_exec($command));

//        echo $command;
//        var_dump($description);
//        die;

        if (!empty($description)) {
            // Extract the AMI ID from the description (e.g., "ami-...") if it exists
            preg_match('/ami-[a-zA-Z0-9]+/', $description, $matches);

            if (!empty($matches)) {
                $amiId = $matches[0];

                // Fetch the AMI Name using AWS CLI
                // Command: aws --profile=mrc ec2 describe-images --image-ids [...] --query 'Images[0].Name' --output text
                $command = "aws --profile=mrc ec2 describe-images --image-ids {$amiId} --query 'Images[0].Name' --output text 2> /dev/null";
                try {
                    $amiName = trim("" . shell_exec($command));
                } catch (Exception $e) {
                    $amiName = "";
                }

                // Display the results
                // echo "Snapshot ID: {$snapshotId}\n";
                // echo "Description: {$description}\n";
                // echo "AMI ID: {$amiId}\n";
                // echo "AMI Name: {$amiName}\n\n";
                $helpful_string .= "{$amiId}|{$amiName}";
            } else {
                // Otherwise save description, unless description is name
                if (preg_match('/^snap-/', $description)) {
                    $helpful_string .= "{$description}";
                }
            }
        }

        // if string is empty leave db field null
        if (trim($description) != "") {
            $stmt2 = $pdo->prepare("UPDATE taggables SET helpful_string = ? WHERE Identifier = ?");
            $stmt2->execute([$helpful_string, $row['Identifier']]);
        }

        if($count++ > 100) {
            die;
        }
    }

} catch (PDOException $e) {
    // Handle database connection errors
    echo "Database connection failed: " . $e->getMessage();
} catch (Exception $e) {
    // Handle general PHP errors
    echo "Error: " . $e->getMessage();
}
