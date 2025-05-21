# FiQueLa API

**Version:** 1.0.0

FiQueLa API provides a powerful, RESTful interface for managing and querying structured file data using SQL-like syntax.
Built upon the versatile FiQueLa PHP library, it seamlessly supports formats such as XML, JSON, CSV, YAML, and NEON,
making it ideal for applications dealing with dynamic data imports, exports, and complex queries.

---

## 🚀 Key Features

### 📁 File Management

- **Upload Files**: Upload data files from remote URLs or directly from local storage.
- **File Information**: Retrieve detailed schema information, file size, encoding, delimiters, and metadata.
- **File Updates**: Modify file-specific settings such as encoding and delimiters.
- **Delete Files**: Easily remove files from storage.

### 🔍 Powerful Query Engine

- **SQL-inspired Queries**: Execute complex queries over structured file data.
- **Data Filtering & Aggregation**: Leverage powerful SQL-like clauses (`SELECT`, `WHERE`, `GROUP BY`, `ORDER BY`) and built-in functions (`SUM`, `AVG`, `COUNT`, etc.) directly through the API.
- **Pagination**: Efficiently handle large result sets with built-in pagination and limit controls.

### 📤 Export Functionality

- **Multiple Formats**: Export query results into formats such as CSV, TSV, or JSON.
- **Flexible Data Access**: Quickly access data snapshots via unique hash-based exports.

### 🕑 Query History

- **Historical Tracking**: Access complete query execution history.
- **Daily Logs**: Retrieve specific historical data by date.

---

## 📡 API Overview

### Authentication

#### Login

To authenticate, send a `POST` request to `/api/auth/login` with your credentials:

```http request
POST /api/auth/login

{
  "username": "your_username",
  "password": "your_password"
}
```

Response:

```json
{
  "token": "your_jwt_token"
}
```

### Endpoints

All endpoints are secured via `Bearer Authentication`. Obtain a valid token and include it in the header:

```http
Authorization: Bearer <your_token>
```

| Endpoint                 | Method   | Description                         |
|--------------------------| -------- | ----------------------------------- |
| `/api/auth/login`        | `POST`   | Authenticate and get token          |
| `/api/auth/revoke`       | `POST`   | Revoke token                        |
| `/api/v1/files`          | `GET`    | List all files                      |
| `/api/v1/files`          | `POST`   | Upload a file                       |
| `/api/v1/files/{uuid}`   | `GET`    | Get file details and schema         |
| `/api/v1/files/{uuid}`   | `POST`   | Update file details and export data |
| `/api/v1/files/{uuid}`   | `DELETE` | Delete file                         |
| `/api/v1/query`          | `POST`   | Execute SQL-like queries            |
| `/api/v1/export/{hash}`  | `GET`    | Export data in specified format     |
| `/api/v1/history`        | `GET`    | Get query history                   |
| `/api/v1/history/{date}` | `GET`    | Get query history by date           |
| `/api/v1/ping`           | `GET`    | API Health Check                    |

---

## 📖 Example Query

Execute a SQL-like query on an XML file:

```http request
POST /api/v1/query

{
  "query": "SELECT title, price FROM channel.item WHERE price < 250",
  "file": "products.xml",
  "limit": 10,
  "page": 1
}
```

## 💬 Example Response

```json
{
  "query": "SELECT title, price FROM channel.item WHERE price < 250",
  "hash": "ef0b589d95c65a6a4f7c075c59e1a3aa",
  "data": [
    { "title": "Item 1", "price": 100.0 },
    { "title": "Item 2", "price": 200.0 }
  ],
  "elapsed": 0.123,
  "pagination": {
    "page": 1,
    "pageCount": 10,
    "itemCount": 100,
    "itemsPerPage": 10,
    "offset": 0
  }
}
```

---

## 🔧 Installation & Setup

### 1. Clone the repository

```bash
git clone https://github.com/1biot/fiquela-api.git fiquela-api
cd fiquela-api
```

### 2. Configure your environment variables

```bash
touch .env
```

#### 🔐 Credentials

put your credentials to the `.env` file. You can create a random password using the following command:

```bash
composer passwd:generate 32
```

Generates a random password of 32 characters.

```text
5M5Dk!q1E8nE6w054J3fetruPLUXee20
$2y$10$cSjiZDZ.qoHBmNbXeBMJvutUtDQHqryy1e3.NrIjhE7ZXR4FoFwT6
```

Final `.env` file should look like this:

```bash
API_USER="your-username"
API_PASSWORD_HASH="$2y$10$cSjiZDZ..."
```

#### 💾 S3 backup configuration

If you want to use S3 for backup your workspace data you can set the following environment variables in your `.env` file:

```bash
S3_ENABLED=1
S3_BUCKET="your-bucket-name"
S3_REGION="auto"
S3_ENDPOINT="https://3nd901nth45h.r2.cloudflarestorage.com"
S3_ACCESS_KEY="4cc35k3y"
S3_SECRET_KEY="53cr3tk3y"
```

I recommend using [Cloudflare R2](https://www.cloudflare.com/r2/) for S3 storage, as it is free for the first 10GB
of data and 1 million requests per month. Be free to use any other S3-compatible storage provider.

If you don't want to use S3 for backup, you can set the following environment variable in your `.env` file:

```bash
S3_ENABLED=0
```

### 3. Without docker

#### install dependencies

```bash
composer install && mkdir ./workspace ./temp
cd ./public && php -S localhost:6917
```

### 4. Build the API server

#### Localhost

```bash
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml build
```

#### Coolify

```bash
docker compose -f docker-compose.yaml -f docker-compose.clf.yaml build
```

### 5. Launch the API server

#### Localhost

```bash
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml up -d
```

#### Coolify

```bash
docker compose -f docker-compose.yaml -f docker-compose.clf.yaml up -d
```

### 6. Digital Ocean

[![Deploy to DO](https://www.deploytodo.com/do-btn-blue.svg)](https://cloud.digitalocean.com/apps/new?repo=https://github.com/1biot/fiquela-api/tree/main?refcode=92025543cb9f)

---

## 🐞 Issues & Contributions

If you encounter any issues or have suggestions, please open an issue or submit a pull request. We welcome contributions from the community!

---

## 📜 License

FiQueLa API is released under the MIT License. See [LICENSE](LICENSE) for details.
