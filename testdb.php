<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = mysqli_connect("localhost", "u130118146_ojt_tracker", "Ojt_tracker123", "u130118146_ojt_tracker");

if ($conn) {
    echo "✅ Connected successfully!";
} else {
    die("❌ Connection failed: " . mysqli_connect_error());
}
?>
