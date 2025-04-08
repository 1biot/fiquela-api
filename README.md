# FiQueLa API

**Version:** 1.0.0

FiQueLa API provides a powerful, RESTful interface for managing and querying structured file data using SQL-like syntax. Built upon the versatile FiQueLa PHP library, it seamlessly supports formats such as XML, JSON, CSV, YAML, and NEON, making it ideal for applications dealing with dynamic data imports, exports, and complex queries.

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

### Base URL

```
https://your-domain.com/api/v1
```

### Endpoints

| Endpoint          | Method   | Description                         |
| ----------------- | -------- | ----------------------------------- |
| `/files`          | `GET`    | List all files                      |
| `/files`          | `POST`   | Upload a file                       |
| `/files/{uuid}`   | `GET`    | Get file details and schema         |
| `/files/{uuid}`   | `POST`   | Update file details and export data |
| `/files/{uuid}`   | `DELETE` | Delete file                         |
| `/query`          | `POST`   | Execute SQL-like queries            |
| `/export/{hash}`  | `GET`    | Export data in specified format     |
| `/history`        | `GET`    | Get query history                   |
| `/history/{date}` | `GET`    | Get query history by date           |
| `/ping`           | `GET`    | API Health Check                    |

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

1. Clone the repository.
2. Configure your environment variables.
3. Run migrations (if applicable).
4. Launch the API server.

Detailed installation instructions can be found in the [official documentation](#).

---

## 📚 Documentation

Full API documentation and detailed examples are available in our [FiQueLa documentation](https://github.com/1biot/FiQueLa/blob/main/docs/file-query-language.md).

---

## 🐞 Issues & Contributions

If you encounter any issues or have suggestions, please open an issue or submit a pull request. We welcome contributions from the community!

---

## 📜 License

FiQueLa API is released under the MIT License. See [LICENSE](LICENSE) for details.
