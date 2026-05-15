<?php
// session_create_exec.php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

$sql = "SELECT manager_id FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();

$manager_id = $user['manager_id'] ?? null;

$session_date = $_POST['session_date'] ?? '';
$mentor_id = ($_POST['mentor_id'] ?? '') !== '' ? intval($_POST['mentor_id']) : null;

if ($session_date === '') {
    exit('面談日が未入力です');
}

$target_month = date('Y-m', strtotime($session_date));

$sql = "
INSERT INTO oneonone_sessions
(user_id, manager_id, mentor_id, session_date, target_month, status)
VALUES (?, ?, ?, ?, ?, '事前未入力')
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "iiiss",
    $user_id,
    $manager_id,
    $mentor_id,
    $session_date,
    $target_month
);

$stmt->execute();

$session_id = $conn->insert_id;

header("Location: session_detail.php?session_id=" . $session_id);
exit;