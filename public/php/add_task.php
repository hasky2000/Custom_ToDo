<?php
    session_start();
    $error = "";
    $host = "localhost";
    $port = 5432;
    $dbname = "todo_db";
    $db_username = "todo_user";
    $db_password = "password";

    $title = $_POST["title"];
    $content = $_POST["content"];
    $priority = $_POST["priority"];
    
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sql = $pdo->prepare("INSERT INTO todos(todo_title, todo_content, todo_priority, user_id)
        VALUES (:title, :content, :priority, :user_id)");
    $sql->execute([
        ":title"=> $title,
        ":content"=> $content,
        ":priority"=> $priority,
        ":user_id"=> $_SESSION["user"]["id"]
    ]);
?>