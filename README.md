# Custom ToDo

授業内のアプリ開発実習で作成するTODOアプリです。Docker Composeを使って、PHP、PostgreSQL、CSSビルド環境をまとめて起動します。

## 必要なもの

- Docker Desktop（Windowsの場合はWSL2バックエンドを有効にしたUbuntu環境）
- Docker Compose v2（`docker compose` コマンド）
- Git

## インストール

1. リポジトリを取得し、プロジェクトのディレクトリへ移動します。

   ```sh
   git clone https://github.com/hasky2000/Custom_ToDo.git
   cd Custom_ToDo
   ```

2. 環境設定ファイルを作成します。以降のコマンドは、Windowsの場合はWSL2のUbuntuで実行してください。

   ```sh
   bash scripts/setup-env.sh
   ```

   `.env.example` を基に `.env` を作成し、DB用パスワードをランダムに生成します。既存の `.env` は上書きしません。

3. CSSのビルドに使う依存パッケージをインストールします。

   ```sh
   docker compose run --rm --no-deps node npm ci
   ```

## 起動

プロジェクトのディレクトリで次のコマンドを実行します。

```sh
docker compose up -d --build
```

起動後、ブラウザーで [http://localhost:8080](http://localhost:8080) を開きます。初回起動時はデータベースの初期化に少し時間がかかることがあります。

## 停止・再起動

コンテナを停止して削除するには、次のコマンドを実行します。

```sh
docker compose down
```

再び起動する場合は、起動コマンドを実行します。

```sh
docker compose up -d
```

データベースの内容はDockerのボリュームに保存されるため、`docker compose down` では削除されません。

## 開発用コマンド

ログを確認します。

```sh
docker compose logs -f
```

CSSを本番向けにビルドします。

```sh
docker compose run --rm --no-deps node npm run build
```

## 使用する主な構成

- PHP 8.4.27 と Apache
- PostgreSQL 17
- Node.js 22.23.4 と Tailwind CSS 4

WebサーバーはIPv4の全インターフェース（`0.0.0.0:8080`）で待ち受けます。このPCでは `http://localhost:8080`、同じLANの別端末では `http://このPCのLAN側IPv4アドレス:8080` にアクセスします。別のアプリが同じポートを使用している場合は、`compose.yaml` のポート設定を変更してください。

`.env` には認証情報が含まれるため、Gitには登録しないでください。既存のDBを使っている場合は、`.env` のパスワードを変更するだけではDB側に反映されません。
