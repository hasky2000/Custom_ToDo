    <?php
    $error = "";
    $host = "localhost";
    $port = 5432;
    $dbname = "todo_db";
    $db_username = "todo_user";
    $db_password = "password";

    session_start();
    unset($_SESSION["user"]);

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $username = $_POST["username"] ?? "";
        $password = $_POST["password"] ?? "";
        $password_confirm = $_POST["password_confirm"] ?? "";

        if ($password != $password_confirm) {
            $error = "パスワードが一致しません";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $db_username, $db_password);
            $sql = $pdo->prepare("INSERT INTO users(username, password_hash) VALUES (?, ?)");
            $sql->execute([$username, $password_hash]);
            header("Location: index.php");
            exit();
        }
    }
    ?>


    <html>
        <?php $page_title = "SIGNUP"; require("components/head.php"); ?>
        <body class="bg-gray-100 flex justify-center items-center h-screen px-10">
            <div class="bg-white px-4 py-10 my-50 flex flex-col justify-center items-center rounded-lg w-full">
                <h1 class="mx-0 font-bold mt-0 mb-12 text-2xl text-center">Color-ToDo<br>アカウント作成</h1>
                <form class="flex flex-col w-full" action="signup.php", method="POST">
                    <p>アカウント名</p>
                    <input type="text" id="username" name="username" class="border-2 border-gray-400 rounded-2xl p-1 px-2">
                    <p>パスワード</p>
                    <input type="password" id="password" name="password" class="border-2 border-gray-400 rounded-2xl p-1 px-2">
                    <p>確認</p>
                    <input type="password" id="password_confirm" name="password_confirm" class="border-2 border-gray-400 rounded-2xl p-1 px-2">
                    <button type="submit" id="submit" class="mt-8 text-xl bg-indigo-50 py-2 rounded-4xl hover:bg-indigo-100">SignUp</button>
                </form>
                <p class="text-center">
                    <?= $error;  ?>
                </p>
            </div>
        </body>
    </html>
    <!--<script src="js/login.js"></script>-->

