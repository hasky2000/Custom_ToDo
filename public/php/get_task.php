<?php
    session_start();
    $error = "";
    $host = "localhost";
    $port = 5432;
    $dbname = "todo_db";
    $db_username = "todo_user";
    $db_password = getenv('POSTGRES_PASSWORD') ?: '';

    $user_id = $_SESSION["user"]["id"];
    
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sql = $pdo->prepare("SELECT * FROM todos WHERE user_id = ?");
    $sql->execute([$user_id]);

    header("Content-Type: application/json; charset=UTF-8");

    $todos = [];
    while ($todo = $sql->fetch(PDO::FETCH_ASSOC)) {
        $todos[] = [
            "id"=> $todo["todo_id"],
            "title" => $todo["todo_title"],
            "content"=> $todo["todo_content"],
            "priority" => $todo["todo_priority"],
        ];
    }
    echo json_encode($todos);
?>