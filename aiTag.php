<?php

require 'vendor/autoload.php';
require 'src/OpenAIChatClient.php';

$requiredEnvVars = ['DB_HOST', 'DB_DATABASE', 'DB_USER', 'DB_PASSWORD', 'OAI_SERVER', 'OAI_KEY', 'OAI_MODEL', 'AWS_PROFILE'];
require 'src/dotEnvLoad.php';

// Create an instance of the OpenAIChatClient
$client = new OpenAIChatClient($_ENV['OAI_SERVER'], $_ENV['OAI_KEY'], $_ENV['OAI_MODEL']);

// Connect to MySQL server
$pdo = new PDO("mysql:host={$_ENV['DB_HOST']}", $_ENV['DB_USER'], $_ENV['DB_PASSWORD']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
pdoQuery($pdo, "USE `{$_ENV['DB_DATABASE']}`");

$reanalyzeLimit = 3;
$lastQuery = "";
$awsCLICommands = [];
$awsCLIFailures = [];

$taggables = pdoQuery($pdo, "SELECT * FROM taggables WHERE Type IN ('Instance','Cluster','DBInstance','Bucket')");

foreach ($taggables as $data) {

    // Reset message history for each new conversation
    $messages = [
        [
            'role' => 'system',
            'content' => file_get_contents('prompts/system.prompt')
        ],
    ];

    // Convert data to a descriptive string
    $stringData = json_encode($data);
    echo "Processing  #{$data['id']}\n";

    // Prompt the AI with the structured data using headers
    $messages[] = [
        'role' => 'user',
        'content' => "Based on this data (headers mapped to values): \"$stringData\", generate the tag data."
    ];

    list($response, $messages) = $client->chat($messages);

    if (empty($response)) {
        echo "An error occurred while processing # {$data['id']}.\n";
        var_dump($response);
        continue;
    }

    // Check for memory usage
    $memoryUsage = memory_get_usage(true);
    echo "Memory Usage After # {$data['id']}: {$memoryUsage} bytes\n";
//    if ($memoryUsage > 4194304) {
//        global $awsCLICommands;
//        global $awsCLIFailures;
//        $awsCLICommands = [];
//        $awsCLIFailures = [];
//    }

    // Use the AWS CLI output for reanalysis, up to $reanalyzeLimit times
    $awsCLICommands = [];
    for ($i = 0; $i < $reanalyzeLimit; $i++) {

        // Step 2: Generate AWS CLI Request
        $messages[] = [
            'role' => 'user',
            'content' => "
        Create a new and unique AWS CLI command that will help retrieve more details about the provided data.
        If you can not create a new and useful command, explain why.
        If you can create a command, only output a shell command that can be run as-is; no mark-up or explanation.
        Use the list of failed commands to prevent retrying the same command.
        Always include --profile={$_ENV['AWS_PROFILE']} to specify the profile and --region=[the region from the current data] to indicate the region.
        Use context from the conversation to assist in generating new commands
        Limit the number of results returned by `aws s3 ls`, `aws s3api list-objects` or similar commands. These commands have options like `--max-items` and `--page-size`.
        Do not save any files or redirect output to files.
        Request output as text (if specified at all).
        Previously used commands are listed here:
        " . implode("\n", array_keys($awsCLICommands)) . "
        Previously failed commands are listed here:
        " . implode("\n", array_keys($awsCLIFailures)) . ""
        ];

        [$response, $messages] = $client->chat($messages);

        if ("[no command]" == $response) {
            echo "Out of ideas for CLI commands!\n";
            break;
        } else if (!empty($response) && isset($response)) {
            $awsCLICommand = $response;
            $awsData = executeAWSCLICommand($pdo, $data['id'], $awsCLICommand);

//            var_dump($awsData);

            if ($awsData !== null) {
                $messages[] = [
                    'role' => 'user',
                    'content' => "Based on all data gained in the conversation thus far, and this AWS data \"$awsData\", generate the tag data."
                ];
                [$response, $messages] = $client->chat($messages, maxTokens: 2048);

                if (empty($response) || !isset($response)) {
                    echo "An error occurred during reanalysis for Iteration $i on # {$data['id']}.\n";
                    break;
                }
            } else {
                echo "Failed to retrieve AWS CLI data for # {$data['id']}.\n";
            }
        } else {
            echo "Failed to generate AWS CLI command for # {$data['id']}.\n";
        }
    }

    $messages[] = [
        'role' => 'user',
        'content' => "What AWS data from this conversation was relevant to the reanalysis? Please provide a short 
            digest of the relevant data. This should be as succinct as possible, but it should be useful if we wanted to
            perform the analysis again without having to make the aws calls. I will refer to this digest as awsDigest.
            If no aws calls were made, return [no aws data].
            If a field within the awsDigest contains encrypted or otherwise non-human readable data, exclude that field.
            Actively remove Certificate contents (actual key contents) and similar encrypted or otherwise non-human readable data. Other Certificate properties (e.g. CertificateArn, DomainName) are OK"
    ];
    [$response, $messages] = $client->chat($messages);
    $awsDataDigest = $response;

    // Final output for the current line
    $messages[] = [
        'role' => 'user',
        'content' => "Summarize findings as json that can be parsed. Do not wrap or otherwise mark up the raw json content. 
        The fields must be: 
        - id
        - Identifier
        - ARN
        - Action
        - Name
        - Application
        - Brand
        - Department
        - Environment
        - Managed
        - Team
        - Notes
        - awsDigest
        "
    ];



    [$response, $messages] = ensureJSON($client, $response, $messages);
    $output = json_decode($response, true);

    if (empty($output)) {
        echo "Failed to decode JSON response: [$response]\n";
    }

    try {
        insertRow($pdo, 'tagged', $output);
    } catch (Exception $e) {
        echo "failed to insert data:\n" . $e->getMessage() . "\n";
        var_dump($output);
    }
}

function ensureJSON($client, $response, $messages)
{
    $retryAttempts = 3;
    [$response, $messages] = $client->chat($messages, maxTokens: 2048);
    for ($i=0; $i < $retryAttempts; $i++) {
        try {
            json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(json_last_error_msg());
            }
            echo "successfully decoded json\n";
            break;
        } catch (Exception $e) {
            echo "Failed to parse json response:\n" . $e->getMessage() . "\n";
            var_dump($response);

            $messages[] = [
                'role' => 'user',
                'content' => "The last response was supposed to be json but failed to parse, see error below. Please respond
            with parseable json only.
            Error:\n"
                    .$e->getMessage()
            ];
            [$response, $messages] = $client->chat($messages, maxTokens: 2048);
        }
    }

    return [$response, $messages];
}

function pdoQuery(PDO $pdo, string $sql, array $values = [])
{
    global $lastQuery;
    $stmt = $pdo->prepare($sql);

    $lastQuery = [$stmt->queryString, $values];

    echo "Executing query:\n" . substr($stmt->queryString, 0, 15) . " ... \n";

    try {
        $stmt->execute($values);
    } catch (Exception $e) {
        echo "Failed to execute query:\n" . $e->getMessage() . "\n";
        echo "query: " . $stmt->queryString ."\n";
        echo "values: \n";
        var_dump($values);
        var_dump($stmt->errorInfo());
    }

    return $stmt->fetchAll();
}

/**
 * Insert a single row into the database.
 *
 * @param PDO $pdo
 * @param string $tableName
 * @param array $row
 */
function insertRow($pdo, $tableName, $row)
{
    try {
        $columns = array_keys($row);
        $placeholders = array_fill(0, count($columns), '?');
        $columnsSql = implode(', ', array_map(function ($column) {
            return "`$column`";
        }, $columns));
        $placeholdersSql = implode(', ', $placeholders);

        $insertSql = "INSERT INTO `$tableName` ($columnsSql) VALUES ($placeholdersSql)";
        $stmt = $pdo->prepare($insertSql);

        // Process the row, serializing arrays/objects if needed
        $values = array_map(function ($value) {
            if (is_array($value) || is_object($value)) {
                return json_encode($value); // Serialize non-scalar values
            }
            return $value;
        }, array_values($row));

        try {
            $stmt->execute($values);
        } catch (PDOException $e) {
            $debug = str_replace($placeholders, $values, $stmt->queryString);
            echo "Database error (skipped): " . $e->getMessage() . "\n";
            echo "Last query: {$debug}\n";
        }
    } catch (Exception $e) {
        echo "Failed to insert row:\n" . $e->getMessage() . "\n";
    }
}

function executeAWSCLICommand($pdo, $taggable_id, $awsCLICommand): ?string
{
    // Cache in memory
    global $awsCLICommands;
    global $awsCLIFailures;
    if (array_key_exists($awsCLICommand,$awsCLICommands)) {
        echo "AWS CLI cached in memory:\n$awsCLICommand\n";
        return $awsCLICommands[$awsCLICommand];
    }

    // Also persistent cache in DB
    $result = pdoQuery($pdo, "SELECT response FROM awsCliCommands WHERE command = ?", [$awsCLICommand]);
    if (!empty($result[0]['response'])) {
        echo "AWS CLI cached in DB:\n$awsCLICommand\n";
//        var_dump($result[0]['response']);
        return $result[0]['response'];
    }

    echo "AWS CLI new command:\n$awsCLICommand\n";

    // Execute the AWS CLI command and retrieve the output
    // Note: this runs shell scripts from AI, but reasonable precaution is taken:
    //      Command executed starts with "aws "
    //      read-only aws profile is used
    try {
        if (str_starts_with($awsCLICommand, 'aws ')) {
            $awsData = shell_exec($awsCLICommand);
        } else {
            $awsData = null;
        }
    } catch (Exception $e) {
        $awsCLIFailures[$awsCLICommand] = $e->getMessage();
    }

    // output length of $awsData string
    if (!empty($awsData)) {
        $n = strlen($awsData);
        echo "data insert size (strlen awsData): $n\n";
        insertRow($pdo, 'awsCliCommands', [
            'command' => $awsCLICommand,
            'taggable_id' => $taggable_id,
            'response' => $awsData,
        ]);
        $awsCLICommands[$awsCLICommand] = $awsData;
    } else {
        $awsData = null;
    }

    return $awsData;
}
