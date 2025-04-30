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

All endpoints are secured via `Bearer Authentication`. Obtain a valid token and include it in the header:

```http
Authorization: Bearer <your_token>
```

### Endpoints

| Endpoint                   | Method   | Description                         |
|----------------------------| -------- | ----------------------------------- |
| `/api/v1/files`            | `GET`    | List all files                      |
| `/api/v1/files`            | `POST`   | Upload a file                       |
| `/api/v1/files/{uuid}`     | `GET`    | Get file details and schema         |
| `/api/v1/files/{uuid}`     | `POST`   | Update file details and export data |
| `/api/v1/files/{uuid}`     | `DELETE` | Delete file                         |
| `/api/v1/query`            | `POST`   | Execute SQL-like queries            |
| `/api/v1/export/{hash}`    | `GET`    | Export data in specified format     |
| `/api/v1/history`          | `GET`    | Get query history                   |
| `/api/v1/history/{date}`   | `GET`    | Get query history by date           |
| `/api/v1/ping`             | `GET`    | API Health Check                    |

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

put your `API_TOKEN` to `.env` file. You can create a random token using the following command:

```bash
echo "API_TOKEN=$(openssl rand -hex 32)" >> .env
```

Final setup should look like this:

```bash
API_TOKEN=da3f318b3286de70700abd7340e0b4117d43a80e8130caf532da3cc732128d80
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

### 4. Launch the API server

#### Localhost

```bash
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml up -d
```

#### Coolify

```bash
docker compose -f docker-compose.yaml -f docker-compose.clf.yaml up -d
```

### 5. Digital Ocean

[![Deploy to DO](https://www.deploytodo.com/do-btn-blue.svg)](https://cloud.digitalocean.com/apps/new?repo=https://github.com/1biot/fiquela-api/tree/main)

---

## 🐞 Issues & Contributions

If you encounter any issues or have suggestions, please open an issue or submit a pull request. We welcome contributions from the community!

---

## 📜 License

FiQueLa API is released under the MIT License. See [LICENSE](LICENSE) for details.
