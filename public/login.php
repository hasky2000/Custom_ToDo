    <?php
    $error = "";
    $host = "localhost";
    $port = 5432;
    $dbname = "todo_db";
    $db_username = "todo_user";
    $db_password = "password";


    $username = $_POST["username"] ?? "";
    $password = $_POST["password"] ?? "";

    session_start();
    unset($_SESSION["user"]);

    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $db_username, $db_password);
    $sql = $pdo->prepare("SELECT * FROM users WHERE username=?");
    $sql->execute([$username]);
    $row = $sql->fetch(PDO::FETCH_ASSOC); // $rowにデータを格納
    $username = $row["username"];
    if ($row === false) { // $userの有無でログイン可否を判断
        $error = "ユーザー名または<br>パスワードが違います";
    } elseif (password_verify($password, $row["password_hash"])) {
        $_SESSION["user"] = ["id"=>$row["id"], "username"=>$row["username"]];
        session_regenerate_id(true);
        header("Location: index.php");
        exit;
    } else {
        $error = "ユーザー名または<br>パスワードが違います";
    }
    ?>


    <html>
        <?php $page_title = "LOGIN"; require("components/head.php"); ?>
        <body class="bg-gray-100 flex justify-center items-center h-screen px-10">
            <div class="bg-white px-4 py-10 my-50 flex flex-col justify-center items-center rounded-lg w-full">
                <h1 class="mx-0 font-bold mt-0 mb-12 text-2xl">Color-ToDoにログイン</h1>
                <form class="flex flex-col w-full" action="login.php", method="POST">
                    <p>アカウント名</p>
                    <input type="text" id="username" name="username" class="border-2 border-gray-400 rounded-2xl p-1 px-2">
                    <p>パスワード</p>
                    <input type="password" id="password" name="password" class="border-2 border-gray-400 rounded-2xl p-1 px-2">
                    <button type="submit" id="submit" class="mt-8 text-xl bg-indigo-50 py-2 rounded-4xl hover:bg-indigo-100">Login</button>
                </form>
                <p class="text-center">
                    <?= $error;  ?>
                </p>
            </div>
        </body>
    </html>
    <!--<script src="js/login.js"></script>-->

