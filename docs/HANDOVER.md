# Custom ToDo 引き継ぎ資料

学生の授業内で作成するTODOアプリです。この資料は、Windowsの共同開発者と、そのPCで作業するAI向けの環境構築・開発再開手順です。

- 調査日：2026年10月20日
- 調査時のコード：`main` / `3f94814`（本資料追加前）
- 標準環境：Windows + WSL2のUbuntu + Docker Desktop（Linuxコンテナ）
- 再現する範囲：各自の空のDBから新規登録して開発を再開する。作者のアカウント・TODO・パスワードは不要。
- 完了条件：画面表示、アカウント登録、TODO操作、設定保存、3種類の自動テストを確認する。

## 1. 最初にAIへ渡す依頼文

このファイルとリポジトリを、ファイルの読み書き・コマンド実行ができるAIに渡してください。チャット専用のAIを使う場合、コマンド実行は共同開発者が担当します。

```text
Custom ToDoの開発環境を、このWindows PCに構築してください。
まずAGENTS.mdとdocs/HANDOVER.mdを読み、実際の設定ファイルとの整合を確認してください。
WSL2のUbuntuとDocker Desktopを使い、空のDBから開発を再開する方針です。

1. 現在のOS、実行シェル、WSL、Docker、チェックアウトの状態を調べる。
2. 導入済みのものを再利用し、不足分だけ準備する。
3. 各手順の成功条件を満たしてから次に進む。
4. 既存の.env、未コミット変更、DB、他のプロジェクトを保持する。
   パスワードや.envの全文をチャット・ログに出さない。
5. 失敗したら資料の診断手順に従い、原因に対応する範囲だけ修正して再確認する。
   DB削除、git reset --hard、無関係な環境変更で解決しない。
6. 再起動、管理者権限、Ubuntuの初回ユーザー作成、GitHub認証、
   GUI設定など自分で操作できない箇所は、必要な操作と再開地点を具体的に示す。
7. 起動とテストを実行し、ブラウザーを操作できれば画面も確認する。
   操作できない場合は、画面の確認項目を人間へ引き継ぐ。
8. 最後に、使用環境、チェックアウトのコミット、アクセスURL、
   サービス状態、テスト結果、画面の確認結果、未完了項目を報告する。

資料と実際のファイルに差がある場合は現物を優先し、差を報告してください。
セットアップのためにアプリの機能や依存バージョンを勝手に変更しないでください。
```

以降の `~/Projects/Custom_ToDo` は共同開発者のUbuntu内のパスです。作者の `/home/hasky/...` を作る必要はありません。Windows側から実行するAIは、Ubuntuのターミナルに切り替えるか `wsl.exe -d Ubuntu -- bash -lc 'コマンド'` を使ってください。後者は呼び出しごとに作業ディレクトリを明示します。

## 2. 環境一覧

### アプリを再現する設定

| 項目 | 設定・用途 | 確認元 |
| --- | --- | --- |
| Webサーバー | `php:8.4-apache`、PDO PostgreSQL拡張 | `Dockerfile` |
| 公開ディレクトリ | コンテナ内 `/var/www/html/public` | `Dockerfile` |
| PHPソース | `src` を `/var/www/html` にマウント | `compose.yaml` |
| データベース | `postgres:17` | `compose.yaml` |
| CSS用Node.js | `node:22-bookworm-slim`、ユーザー `node` | `compose.yaml` |
| CSSツール | Tailwind CSS / CLI `4.1.13` | `package.json` |
| 依存関係の固定 | `package-lock.json`、インストールは `npm ci` | `package-lock.json` |
| ブラウザーのURL | `http://localhost:8080` → コンテナの80番 | `compose.yaml` |
| DB接続 | Compose内部の `db:5432`。ホストにはDBポートを公開しない | `compose.yaml` |
| DBの永続化 | Compose管理の名前付きボリューム `postgres_data` | `compose.yaml` |
| 初期化SQL | `database/init/001` ～ `007` の順 | `database/init` |
| フロントエンド | PHPの画面 + JavaScript + CSS。フロント用サーバーは不要 | `src/public` |
| 外部アクセス | Dockerイメージ、npm、Tailwind CDN、Google Fonts、GitHub | 各設定・画面 |

PHP、Node.js、npm、PostgreSQLをWindowsやUbuntuに個別インストールする必要はありません。Dockerイメージはタグ指定なので、パッチバージョンまで作者と一致する保証はありません。

### 作者のホスト環境（参考値）

| 項目 | 調査時の値 |
| --- | --- |
| OS / カーネル | EndeavourOS / Linux `7.2.7-arch1-1`、x86_64 |
| Docker / Compose | `29.7.2` / `5.5.1` |
| ホストPHP | `8.5.11` |
| ホストNode.js / npm | `24.19.0` / `11.17.0` |
| Git | `2.56.0` |
| Gitリモート | `github.com/hasky2000/Custom_ToDo` |

ホストのバージョンを揃える必要はありません。コンテナ設定を環境再現の基準にします。調査時は `.env` と `node_modules` が存在しましたが、これらはGitでは共有されません。

## 3. Windows・WSL・Dockerの準備

### 3.1 現在の状態を確認する

**実行場所：WindowsのPowerShell。** まず通常権限で確認します。

```powershell
wsl --version
wsl --list --verbose
docker version
docker compose version
```

成功条件は、UbuntuがWSLのVERSION `2`、DockerがClientとServer両方の情報を返し、Composeが利用できることです。未導入やDocker停止の場合は次へ進みます。Ubuntuの登録名が異なる場合、以降の `-d Ubuntu` は実際の名前に置き換えます。

### 3.2 不足しているものを導入する

1. WSLが未導入なら、**管理者PowerShell**で次を実行します。

   ```powershell
   wsl --install -d Ubuntu
   ```

2. 再起動が必要なら再起動し、Ubuntuを開いてLinux用ユーザー名・パスワードを作成します。AIへパスワードを渡す必要はありません。
3. 既存のUbuntuがWSL1なら、PowerShellで `wsl --set-version Ubuntu 2` を実行します。WSL更新が必要なら `wsl --update` を実行します。
4. [Docker Desktop公式手順](https://docs.docker.com/desktop/setup/install/windows-install/)で、PCのCPUに合うWindows版を導入して起動します。OS・メモリー・仮想化などの要件も同ページで確認します。
5. Docker DesktopでWSL2エンジンを使い、**Settings → Resources → WSL Integration**で作業用Ubuntuを有効にして適用します。Linuxコンテナを使用します。WSL2の設定は環境によって既定で有効です。

Ubuntu内に別のDocker Engineを追加する必要はありません。既に独自のDocker環境がある場合は、削除せず接続先と競合の有無を確認してください。

参考：[MicrosoftのWSL導入](https://learn.microsoft.com/en-us/windows/wsl/install)、[DockerのWSL連携](https://docs.docker.com/desktop/features/wsl/)。

**再開地点：** 再起動やGUI設定後は3.1を再確認し、Ubuntuを開いて次を実行します。

```bash
uname -s
docker info --format '{{.OSType}}'
docker compose version
```

成功条件は順に `Linux`、`linux`、Composeのバージョンが返ることです。以降のコマンドは、明示した箇所を除きすべて**WSLのUbuntu / Bash**で実行します。

## 4. コード取得・初回起動

### 4.1 リポジトリを取得する

Gitとcurlがない場合だけUbuntuに導入します。

```bash
sudo apt-get update
sudo apt-get install -y git curl
```

新規取得の場合は次を実行します。作業場所はUbuntuのホーム以下に置きます。

```bash
mkdir -p ~/Projects
cd ~/Projects
git -c core.autocrlf=input clone https://github.com/hasky2000/Custom_ToDo.git
cd Custom_ToDo
git status --short --branch
git log -1 --oneline
```

取得先が既に存在する場合は再取得せず、そこへ移動して `git remote -v` と `git status --short --branch` を確認します。未コミット変更がある場合は保持します。更新方法は8章を参照してください。

GitHub認証が必要なら共同開発者自身のアカウントとリポジトリのアクセス権を使います。作者のSSH鍵は不要です。認証情報をURLや資料に書かないでください。

成功条件は、プロジェクト直下に `compose.yaml`、`Dockerfile`、`.env.example`、`package-lock.json` があることです。

### 4.2 `.env` を用意する

**実行場所：Ubuntuのプロジェクト直下。** 以降もこのディレクトリを使います。

```bash
cd ~/Projects/Custom_ToDo
if [ ! -e .env ]; then
    (
        umask 077
        cp .env.example .env
        database_password=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
        sed -i "s/^POSTGRES_PASSWORD=.*/POSTGRES_PASSWORD=${database_password}/" .env
    )
fi
docker compose config --quiet
```

このコマンドは `.env` がない場合だけ作成し、DB用パスワードを生成します。既存の `.env` がある場合は値を保持し、必須項目の有無を確認します。未使用の新規環境で `change_me` のままなら変更してください。既にDBがある場合、`.env` のパスワードだけを変えてもDB側には反映されません。

| 変数 | 新規環境の値 | 意味 |
| --- | --- | --- |
| `DB_HOST` | `db` | Compose内のDBサービス名。`localhost` にしない |
| `DB_PORT` | `5432` | DBのコンテナ内ポート |
| `POSTGRES_DB` | `todo_db` | アプリ用DB名 |
| `POSTGRES_USER` | `todo_user` | DB接続ユーザー |
| `POSTGRES_PASSWORD` | 上のコマンドで生成 | DB接続用。アプリのログイン用とは別 |

成功条件は `docker compose config --quiet` が終了コード0で終わり、変数不足の警告が出ないことです。通常の `docker compose config` はパスワードを表示するため、共有する診断には `--quiet` を使用します。

### 4.3 依存関係の導入と起動

```bash
docker compose run --rm --no-deps node npm ci
docker compose up -d --build
docker compose ps -a
curl --fail --silent --show-error -o /dev/null -w '%{http_code}\n' http://localhost:8080/
curl --silent --show-error -o /dev/null -w '%{http_code}\n' http://localhost:8080/api/me.php
```

成功条件：

- `npm ci` と起動コマンドが成功する。
- `php`、`db`、`node` が稼働し、`db` が `healthy` になる。初回はイメージ取得とビルドに時間がかかる。
- トップページがHTTP `200`、未ログインの `me.php` がHTTP `401` を返す。後者は正常。
- Windowsのブラウザーで `http://localhost:8080` を開くとログイン・登録画面が出る。

DBの初期化SQLは空のデータ領域の初回起動時に順番に実行されます。既存データがある場合は自動再実行されません。`node` はCSSの変更監視で動き続けます。

## 5. テストと画面確認

### 5.1 自動テスト

**実行場所：Ubuntuのプロジェクト直下。** `php` と `db` の起動完了後、次を1つずつ実行します。

```bash
docker compose run --rm --no-deps node npm run test:settings-sharing
bash tests/run.sh api
bash tests/run.sh appearance
```

| テスト | 確認内容 | 成功条件 |
| --- | --- | --- |
| `test:settings-sharing` | 配色の共有形式、不正値、保存順序、保存失敗・ログアウト時の扱い | 全件成功・終了コード0 |
| `api` | 認証、並べ替え、完了・復元、配色、他ユーザーとの分離、デバッグなど | 検証件数が出て終了コード0 |
| `appearance` | 枠・ブラー・影・フォントの保存と不正値など | 検証件数が出て終了コード0 |

APIと表示設定テストは一時的な `custom_todo_test_*` DBを作成し、初期化SQLを適用して検証後に削除します。開発用DBを対象にしません。テスト内のPHPサーバーはコンテナ内の8091番を使用し、ホストへの公開は不要です。2種類のPHPテストは同時に実行しないでください。

PHPの構文確認が必要な場合：

```bash
docker compose exec -T php sh -c 'for file in app/*.php public/*.php public/api/*.php; do php -l "$file" || exit 1; done'
```

### 5.2 ブラウザー確認

Windowsのブラウザーで以下を確認します。AIが画面操作できない場合は共同開発者が実施し、結果をAIに返してください。

1. 自分用のメールアドレスと8文字以上のパスワードで新規登録する。確認欄にも同じパスワードを入力する。
2. タイトル、内容、予定日時付きのTODOを2件追加する。
3. 内容と予定日時を編集し、進捗を変更する。
4. ドラッグや移動操作で並べ替え、再読み込み後も順序が残ることを確認する。
5. 完了させるとホームから消え、アーカイブへ移ることを確認する。進捗100%でも完了扱いになる。
6. アーカイブで検索し、復元する。未完了・進捗0%で一覧の末尾へ戻ることを確認する。
7. 配色、枠の太さ、影・ブラー・フォントを変更する。「アカウントに自動保存しました。」を確認し、再読み込み後も設定が残ることを確認する。
8. 配色の共有文字列をエクスポート・インポートする。共有されるのは色で、枠などの表示設定は含まれない。
9. TODOを削除し、ログアウト・再ログインできることを確認する。

画面未確認やテスト失敗が残る場合は「構築完了」とせず、残っている確認項目を報告します。

## 6. 構成と実装済み機能

```text
compose.yaml              php・db・nodeの構成
Dockerfile                PHP/ApacheとPDO PostgreSQL拡張
.env.example              共有用の環境変数ひな形
database/init/            DB初期化・既存構造への追加SQL
src/app/                  DB接続、セッション、並び順の共通処理
src/public/index.php      ログイン・TODO・アーカイブ・設定などの画面
src/public/api/            JSONを扱うPHP API
src/public/assets/        JavaScriptと生成済みCSS
src/css/input.css         配色の初期値・画面共通CSSの編集元
tests/                    自動テスト
```

### データと認証

| テーブル | 主な内容 |
| --- | --- |
| `users` | メールアドレス、一意なID、パスワードハッシュ、登録日時 |
| `tasks` | ユーザーID、タイトル・内容、予定日時、進捗0～100、完了状態・日時、並び順 |
| `user_colors` | ユーザーごとの配色・表示設定をJSONBで保存 |

`tasks` と `user_colors` は `users` を参照します。APIはセッションからユーザーIDを取得して、そのユーザーのデータを扱います。セッションCookie名は `custom_todo_session`、パスワードは `password_hash` / `password_verify` で処理します。PHPコンテナを作り直した後は再ログインが必要になる場合があります。

表示設定はDBに保存し、ブラウザーにも `custom-todo-settings-v1:<ユーザーID>` のキーで保存待ちの編集を保持します。保存はキューで順番に実行します。別アカウントへの応答混入を防ぐ処理もあります。

### API一覧

URLはすべて `/api/<ファイル名>`。リクエスト・レスポンスはJSON、認証は同じサイトのCookieを使います。成功は原則 `data`、エラーは `error` に格納します。

| ファイル名 | メソッド | 認証 | 用途・主な入力 |
| --- | --- | --- | --- |
| `register.php` | POST | 不要 | `email`, `password`, `password_confirmation` |
| `login.php` | POST | 不要 | `email`, `password` |
| `logout.php` | POST | 不要 | セッション終了 |
| `me.php` | GETで利用 | 必要 | ログイン中のユーザー。現実装にメソッド制限なし |
| `get_tasks.php` | GET | 必要 | 未完了の一覧を保存済み順序で取得 |
| `add_task.php` | POST | 必要 | `title`, 任意の `content`, `scheduled_at` |
| `edit_task.php` | PATCH | 必要 | `id`, `title`, `content`, `scheduled_at` |
| `update_task.php` | PATCH | 必要 | `id`, `progress` |
| `reorder_task.php` | PATCH | 必要 | `task_ids`：未完了TODO全件の整数IDを新しい順序で指定 |
| `complete_task.php` | PATCH | 必要 | `id` |
| `get_completed_tasks.php` | GET | 必要 | 完了済み一覧を完了日時の降順で取得 |
| `restore_task.php` | PATCH | 必要 | `id`。進捗0%、未完了、末尾へ復元 |
| `delete_task.php` | DELETE | 必要 | `id` |
| `colors.php` | GET / PUT | 必要 | 配色・表示設定の取得と保存 |
| `debug.php` | GET | 必要 | 自分のアカウント、タスク、認証・実行環境の確認 |

予定日時をAPIに直接渡す場合は `2026-10-20T10:00:00+09:00` のようにタイムゾーンを含めます。`colors.php` のPUTでは `colors` オブジェクトが必須で、色は6桁HEXです。`appearance` は任意で、省略時は保存済み設定を保持します。詳しい入力判定は各PHPとテストが基準です。

## 7. うまく動かない場合

コマンドの失敗時は先へ進まず、まず以下をUbuntuのプロジェクト直下で確認します。

```bash
pwd
git status --short --branch
docker context show
docker compose config --quiet
docker compose ps -a
docker compose logs --tail=100 php db node
```

ログを共有する前に、認証情報・メールアドレスなどを伏せてください。

| 症状 | 診断・対処 | 再確認 |
| --- | --- | --- |
| `docker` が見つからない・Serverへ接続できない | Docker Desktopを起動。Ubuntu向けWSL IntegrationとLinuxコンテナ設定を確認。WindowsとUbuntuで接続先が異ならないか確認 | Ubuntuの `docker info` |
| WSL導入に失敗する | OS要件、管理者権限、仮想化、再起動待ちを公式手順で確認。学校管理PCの場合は管理者へ依頼 | `wsl --list --verbose` |
| cloneで認証エラー・Repository not found | URLと共同開発者のGitHubアクセス権を確認。作者のSSH鍵を流用しない | `git ls-remote https://github.com/hasky2000/Custom_ToDo.git HEAD` |
| Composeの変数不足 | プロジェクト直下で実行しているか、`.env` の必須項目を確認。シェルに同名の変数があると優先されるので値を公開せず確認する | `docker compose config --quiet` |
| 8080が使用中 | WindowsのPowerShellで `Get-NetTCPConnection -LocalPort 8080 -State Listen`。他のアプリを勝手に停止せず、所有者が分かれば停止を相談。継続利用するならローカルのComposeポートを8081などへ変更し、以降のURLも更新する | `curl` とブラウザーで変更後URLへアクセス |
| `node` が終了する・tailwindcssが見つからない | `docker compose logs --tail=100 node` を確認。別OSで作ったnode_modulesを流用せずコンテナ内で `npm ci`。成功後 `docker compose up -d node` | `docker compose ps -a` |
| npmで `EACCES` | Ubuntuの `id`、プロジェクトとnode_modulesの所有者を確認。nodeサービスのユーザーはUID/GID 1000が標準。異なる場合は `docker compose run --rm --no-deps --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache node npm ci` を使用し、監視用nodeサービスもローカルoverrideで同じUID/GIDに揃える。`chmod -R 777` は使わない | npm再実行、nodeログ |
| Bashで `$'\r'` や構文エラー | PowerShellへBash用コマンドを貼っていないか確認。`file tests/run.sh` でCRLFならLFに直す。新規取得はUbuntuのGitを使う | `bash -n tests/run.sh`、テスト再実行 |
| DBがunhealthy・APIが500 | db/phpのログ、`.env`、既存ボリュームの有無を確認。初期化失敗と認証失敗を区別する。既存DBに対して.envのパスワードだけを変更しない | `docker compose ps -a`、APIテスト |
| column / relation does not exist | 既存DBでは追加SQLが自動適用されない。次節で構造と不足SQLを調べる | 構造の再確認、APIテスト |
| 画面の色・フォントが崩れる | app.cssの存在、nodeログ、ブラウザーのConsole/NetworkでTailwind CDNとGoogle Fontsの通信を確認 | 再読み込み、画面確認 |
| CSS変更が反映されない | `node` の監視状態と `src/css/input.css` の編集内容を確認。WSLのホーム配下で作業し、必要なら監視を再起動 | nodeログ、生成CSS、ブラウザー |
| 設定を変えても保存されない | ログイン状態と `/api/colors.php` の応答、ブラウザーのConsoleを確認。「今すぐ保存」で再試行 | 保存完了後に再読み込み |

### 既存DBの構造確認と追加SQL

通常の新規構築では不要です。既存環境の更新でSQL不足が疑われる場合だけ行います。

```bash
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "\d users" -c "\d tasks" -c "\d user_colors"'
```

| SQL | 主な役割 |
| --- | --- |
| `001_schema.sql` | 基本テーブル |
| `002_progress_constraint.sql` | 進捗の初期値・必須・範囲制約 |
| `003_add_scheduled_at.sql` | 予定日時 |
| `004_add_users.sql` | 旧構造へのユーザーと所有者の追加 |
| `005_add_task_order_and_completed_at.sql` | 並び順、完了日時、既存値の補完 |
| `006_add_user_colors.sql` | 配色テーブル |
| `007_add_user_appearance.sql` | 表示設定のJSONB列と制約 |

自動のマイグレーション履歴管理はありません。SQL本文と実DBを比較し、不足分と適用順を決めます。変更前には次のようにUbuntu内のGit管理外へバックアップします。

```bash
mkdir -p "$HOME/custom-todo-backups"
backup_file="$HOME/custom-todo-backups/before-migration-$(date +%Y%m%d-%H%M%S).sql"
(umask 077; docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > "$backup_file")
test -s "$backup_file"
```

バックアップの終了コードとファイルサイズを確認してから適用します。例えば `user_colors` は存在し `appearance` 列だけがない場合：

```bash
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 --single-transaction' < database/init/007_add_user_appearance.sql
```

全SQLを確認なしに一括適用しないでください。旧構造の所有者なしタスクは、新規登録時にそのユーザーへ紐付ける互換処理があります。既存データを扱う場合は対象を先に確認します。`docker compose down -v` はDBを消すため、通常の停止や復旧では使いません。

## 8. 日常の開発手順

**実行場所：Ubuntuのプロジェクト直下。**

| 作業 | コマンド・操作 |
| --- | --- |
| 再開 | Docker Desktopを起動後、`docker compose up -d` |
| 状態確認 | `docker compose ps -a` |
| 停止（DB保持） | `docker compose down` |
| PHPのログ | `docker compose logs --tail=100 php` |
| CSS監視のログ | `docker compose logs --tail=100 node` |
| CSSの編集 | `src/css/input.css` を編集。通常はnodeがapp.cssを再生成 |
| CSSを一度だけビルド | 監視との同時書き込みを避け `docker compose stop node` 後、`docker compose run --rm --no-deps node npm run build`。終わったら `docker compose up -d node` |
| PHP/JSの編集 | マウントされているため基本はブラウザー再読み込みで反映 |
| Dockerfileの変更 | `docker compose up -d --build` |

コード更新前は `git status --short --branch` を確認します。作業中の変更があればコミットなどで保持し、現在のブランチに応じて更新してください。未変更の `main` を更新する場合：

```bash
git pull --ff-only origin main
```

更新後は差分に応じて次を行います。

- `package-lock.json` 変更：nodeを停止し、コンテナで `npm ci` を実行して再起動する。
- `Dockerfile` 変更：再ビルドする。
- SQL追加：既存DBの構造を確認し、7章に従って不足分を適用する。
- 機能変更：関連テストと画面を確認する。

変更を共有するときは `git diff` と `git status` を確認します。`.env`、node_modules、DBバックアップ、個人設定は含めません。`package-lock.json` と生成済み `src/public/assets/app.css` はGit管理対象です。検証だけでapp.cssが変わった場合は差分を確認し、自分の変更と区別してください。

## 9. 確認済み事項と残っている制約

2026年10月20日の調査時点：

| 項目 | 状態 |
| --- | --- |
| ホストNode.jsでの設定共有テスト | 16件成功 |
| PHPファイルの構文チェック | 成功（ホストPHP 8.5.11） |
| Dockerコンテナの稼働確認 | Dockerソケットへの権限不足により未確認 |
| Docker経由のAPI・表示設定テスト | 未確認 |
| Windows / WSL2での実機確認 | 未確認。共同開発者のPCで本資料に従って確認する |
| ブラウザーでの操作確認 | 今回の引き継ぎ調査では未実施 |

- この資料は開発環境向けです。公開サーバーの配備手順は含みません。
- TailwindのCDNスクリプトとGoogle Fontsを画面から読み込むため、完全なオフライン動作は前提としていません。
- ログイン後のデバッグ画面/APIには、自分のメールアドレス・タスク・パスワードハッシュなどが表示されます。スクリーンショットや応答全文を共有資料に貼らないでください。
- 旧不具合でDB用パスワードのハッシュが保存されたアカウントは、デバッグで再設定が必要と判定する処理があります。新規環境には持ち込みません。

### AIの最終報告に含めること

```text
使用環境：Windows / WSL / Ubuntu / Docker / Composeのバージョン
作業場所：Ubuntu内のリポジトリパス
対象コード：ブランチとコミット
アクセス先：http://localhost:8080（変更した場合はそのURL）
サービス：php / db / nodeの状態、DBのhealthy判定
自動テスト：settings-sharing / api / appearance の結果
画面：登録、TODO操作、並べ替え、完了・復元、設定保存、再ログインの結果
ローカルで変更した設定：秘密値を除いて記載
未完了・人間の操作待ち：なければ「なし」、あれば操作と再開手順
```

Windowsで確認できたら、この節の未確認項目を実測結果・日付に更新して共有してください。
