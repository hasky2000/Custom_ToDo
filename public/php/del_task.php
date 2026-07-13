<?php
    session_start();
    $error = "";
    $host = "localhost";
    $port = 5432;
    $dbname = "todo_db";
    $db_username = "todo_user";
    $db_password = getenv('POSTGRES_PASSWORD') ?: '';

    $id = $_POST["id"];
    $user_id = $_SESSION["user"]["id"];
    
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sql = $pdo->prepare("DELETE FROM todos WHERE todo_id=:id AND user_id=:user_id");
    $sql->execute([":id" => $id, ":user_id" => $user_id]);

?>