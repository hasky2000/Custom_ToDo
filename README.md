# Custom ToDo

## 環境構築

Docker、Docker Composeが使える環境で、以下を実行します。

1. 環境設定のひな形をコピーします。

   ```sh
   cp .env.example .env
   ```

2. `.env` の `POSTGRES_PASSWORD` を任意のパスワードに変更します。
3. CSSのビルドに必要な依存パッケージをインストールします。

   ```sh
   docker compose run --rm --no-deps node npm ci
   ```

4. アプリを起動します。

   ```sh
   docker compose up -d --build
   ```

ブラウザーで http://localhost:8080 を開きます。

## Gitで管理するファイル

`.env`、`node_modules/`、エディターの個人設定、ログはコミット対象から除外しています。
環境変数を追加した場合は、秘密情報を含めずに `.env.example` も更新してください。

`package-lock.json` とデータベースの初期化SQLは、環境を再現するために管理します。
`src/public/assets/app.css` も、初期表示に必要なCSSとして管理します。
