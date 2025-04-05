<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You for Voting</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .thank-you-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 90%;
        }
        .check-icon {
            color: #28a745;
            font-size: 60px;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
        }
        p {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .home-button {
            background: #0d6efd;
            color: white;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 5px;
            display: inline-block;
            transition: background-color 0.3s;
        }
        .home-button:hover {
            background: #0b5ed7;
        }
    </style>
</head>
<body>
    <div class="thank-you-container">
        <div class="check-icon">✓</div>
        <h1>Thank You for Voting!</h1>
        <p>Your vote has been successfully recorded. Thank you for participating in this election.</p>
        <a href="index.php" class="home-button">Return to Home</a>
    </div>
</body>
</html> 