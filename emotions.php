<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'conexion.php';

// Log request data for debugging
$rawData = file_get_contents('php://input');
file_put_contents('debug.log', "Raw Input: " . $rawData . "\n", FILE_APPEND);

// Parse JSON
$data = json_decode($rawData, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    file_put_contents('debug.log', "JSON Parse Error: " . json_last_error_msg() . "\n", FILE_APPEND);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

// Validate required fields
if (!isset($data['studentId']) || !isset($data['videoId']) || !isset($data['dominantEmotion']) || !isset($data['emotionProbabilities'])) {
    file_put_contents('debug.log', "Invalid data received: " . print_r($data, true) . "\n", FILE_APPEND);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

// Verify foreign keys
$stmt = $mysqli->prepare("SELECT id FROM students WHERE id = ?");
$stmt->bind_param('s', $data['studentId']);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    file_put_contents('debug.log', "Invalid studentId: " . $data['studentId'] . "\n", FILE_APPEND);
    echo json_encode(['error' => 'Invalid studentId']);
    exit;
}
$stmt->close();

$stmt = $mysqli->prepare("SELECT id FROM videos WHERE id = ?");
$stmt->bind_param('s', $data['videoId']);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    file_put_contents('debug.log', "Invalid videoId: " . $data['videoId'] . "\n", FILE_APPEND);
    echo json_encode(['error' => 'Invalid videoId']);
    exit;
}
$stmt->close();

// Convert ISO 8601 timestamp to MySQL DATETIME format
$timestamp = $data['timestamp'];
try {
    $dateTime = new DateTime($timestamp);
    $mysqlTimestamp = $dateTime->format('Y-m-d H:i:s');
} catch (Exception $e) {
    file_put_contents('debug.log', "Timestamp conversion error: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['error' => 'Invalid timestamp format']);
    exit;
}

// Prepare insert query
$stmt = $mysqli->prepare("
    INSERT INTO emotions (studentId, videoId, timestamp, dominantEmotion, 
    prob_angry, prob_disgust, prob_fear, prob_happy, prob_sad, prob_surprise, prob_neutral)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    file_put_contents('debug.log', "Prepare failed: " . $mysqli->error . "\n", FILE_APPEND);
    echo json_encode(['error' => 'Prepare failed: ' . $mysqli->error]);
    exit;
}

// Bind parameters
$probAngry = (float)$data['emotionProbabilities']['angry'];
$probDisgust = (float)$data['emotionProbabilities']['disgust'];
$probFear = (float)$data['emotionProbabilities']['fear'];
$probHappy = (float)$data['emotionProbabilities']['happy'];
$probSad = (float)$data['emotionProbabilities']['sad'];
$probSurprise = (float)$data['emotionProbabilities']['surprise'];
$probNeutral = (float)$data['emotionProbabilities']['neutral'];

$stmt->bind_param(
    'ssssddddddd',
    $data['studentId'],
    $data['videoId'],
    $mysqlTimestamp,
    $data['dominantEmotion'],
    $probAngry,
    $probDisgust,
    $probFear,
    $probHappy,
    $probSad,
    $probSurprise,
    $probNeutral
);

// Execute
if ($stmt->execute()) {
    file_put_contents('debug.log', "Insert successful\n", FILE_APPEND);
    echo json_encode(['success' => true]);
} else {
    file_put_contents('debug.log', "Execute failed: " . $stmt->error . "\n", FILE_APPEND);
    echo json_encode(['error' => 'Execute failed: ' . $stmt->error]);
}

$stmt->close();
$mysqli->close();
?>