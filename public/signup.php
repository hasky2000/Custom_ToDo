    <?php
    $error = "";
    $host = "localhost";
    $port = 5432;
    $dbname = "todo_db";
    $db_username = "todo_user";
    $db_password = getenv('POSTGRES_PASSWORD') ?: '';

    session_start();
    unset($_SESSION["user"]);

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $username = $_POST["username"] ?? "";
        $email = $_POST["email"] ?? "";
        $password = $_POST["password"] ?? "";
        $password_confirm = $_POST["password_confirm"] ?? "";

        if ($username == "" || $email == "" || $password == "") {
        } elseif ($password != $password_confirm) {
            $error = "パスワードが一致しません";
        } else {
            try {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $db_username, $db_password);
                $sql = $pdo->prepare("INSERT INTO users(username , email, password_hash) VALUES (?, ?, ?)");
                $sql->execute([$username, $email, $password_hash]);
                header("Location: login.php");
                exit();
            } catch (PDOException $e) {
                if ($e->errorInfo[0] == "23505") {
                    $error = "そのメールアドレスは既に使われています";
                } else {
                    $error = "エラーが発生しました";
                }
            }
        }
    }
    ?>


<!-- 仮デザイン -->
    <html>
        <?php $page_title = "SIGNUP"; require("components/head.php"); ?>
        <body class="bg-gray-100 flex justify-center items-center h-screen px-10">
            <div class="bg-white px-4 py-10 my-50 flex flex-col justify-center items-center rounded-lg w-full">
                <h1 class="mx-0 font-bold mt-0 mb-12 text-2xl text-center">Color-ToDo<br>アカウント作成</h1>
                <form class="flex flex-col w-full" action="signup.php", method="POST">
                    <p>アカウント名</p>
                    <input minlength="4" type="text" id="username" name="username" class="border-2 border-gray-400 rounded-2xl p-1 px-2">
                    <p>Email</p>
                    <input minlength="4" type="email" id="email" name="email" class="border-2 border-gray-400 rounded-2xl p-1 px-2">
                    <p>パスワード</p>
                    <input minlength="4" type="password" id="password" name="password" class="border-2 border-gray-400 rounded-2xl p-1 px-2">
                    <p>確認</p>
                    <input minlength="4" type="password" id="password_confirm" name="password_confirm" class="border-2 border-gray-400 rounded-2xl p-1 px-2">
                    <button type="submit" id="submit" class="mt-8 text-xl bg-indigo-50 py-2 rounded-4xl hover:bg-indigo-100">SignUp</button>
                </form>
                <p class="text-center">
                    <?= $error;  ?>
                </p>
            </div>
        </body>
    </html>
    <!--<script src="js/login.js"></script>-->

