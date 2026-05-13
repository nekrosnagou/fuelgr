# ⛽ FuelGR — Fuel Price Tracking Web App

> Academic project - E-Business | Digital Systems University of Thessaly

An interactive web application for tracking fuel prices at gas stations, powered by a custom-built REST API.

---

## 📸 Preview

<img width="1917" height="986" alt="image" src="https://github.com/user-attachments/assets/96ae1a05-3cda-4e0c-a352-698177cbad83" />


---

## ✨ Features

- 🗺️ **Interactive Google Maps** — gas stations shown as color-coded markers (green = cheapest, red = most expensive)
- ⛽ **Fuel type selector** — Αμόλυβδη 95/100, Diesel, LPG
- 📊 **Live stats** — station count, min / avg / max price per litre
- 🏪 **Station infobox** — full pricelist on marker click
- 🔐 **JWT Authentication** — stateless login for consumers & station owners
- 🛒 **Order system** — consumers can place fuel orders
- 👑 **Owner dashboard** — manage orders, execute them, update fuel prices
- ⭐ **Owner's station** highlighted with a gold star on the map

---

## 🏗️ Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend API | PHP 8.x + [Slim Framework 4](https://www.slimframework.com/) |
| Authentication | JWT ([firebase/php-jwt](https://github.com/firebase/php-jwt)) |
| Database | MySQL / MariaDB |
| Frontend | Vanilla JS + Fetch API |
| Maps | Google Maps JavaScript API v3 |
| Styling | Pure CSS (CSS Variables, Flexbox) |

---

## 📡 REST API Endpoints

| # | Method | Endpoint | Description | Auth |
|---|--------|----------|-------------|------|
| 1 | `GET` | `/stations/{fuelType}` | Stations + prices. Add `?format=xml` for XML | — |
| 2 | `GET` | `/stations/{fuelType}/stats` | Count, min, avg, max price | — |
| 3 | `GET` | `/stations/{id}/pricelist` | Full pricelist of a station | — |
| 4 | `POST` | `/auth/login` | Login → returns JWT token | — |
| 5 | `POST` | `/orders` | Place a fuel order | Consumer |
| 6 | `GET` | `/orders/station/{id}` | Get station's orders | Owner |
| 7 | `PUT` | `/pricelist/{stationId}/{subTypeId}` | Update fuel price | Owner |
| 8 | `PUT` | `/orders/{id}/execute` | Execute an order | Owner |
| 9 | `DELETE` | `/orders/{id}` | Delete an order | Owner / Consumer |

---

## 🚀 Local Setup

### Prerequisites
- [WampServer](https://www.wampserver.com/) or XAMPP (PHP 8.x + MySQL)
- [Composer](https://getcomposer.org/)
- A [Google Maps API Key](https://console.cloud.google.com/)

### 1. Clone the repository
```bash
git clone https://github.com/YOUR_USERNAME/fuelgr.git
cd fuelgr
```

### 2. Install PHP dependencies
```bash
composer install --no-audit
```

### 3. Set up the database

Open **phpMyAdmin** → create a database named `fuelgr`, then import in this order:

```
sql/schema.sql       ← creates all tables
gasstations.sql      ← station data   (provided separately)
pricedata.sql        ← price data     (provided separately)
users.sql            ← user accounts  (provided separately)
```

Then run this query to assign owner roles:
```sql
UPDATE users SET role='owner'
WHERE username IN (SELECT DISTINCT username FROM gasstations);
```

### 4. Add your Google Maps API Key

In `public/index.html`, find the last `<script>` tag and replace:
```
YOUR_GOOGLE_MAPS_API_KEY
```
with your actual key.

### 5. Open the app
```
http://localhost/fuelgr/public/index.html
```

---

## 👤 Test Credentials

| Role | Username | Password |
|------|----------|----------|
| Owner (Station #441) |  `user1`  |  `pass1`  |
| Consumer |  `user2`  |  `pass2`  |
|   ...    |    ...    |    ...    |
| Comsumer | `user160` | `pass160` |
> ⚠️ The seed data (`users.sql`, `gasstations.sql`, `pricedata.sql`) contains made up plain-text for development only.

---

## 📁 Project Structure

```
fuelgr/
├── api/
│   ├── index.php        ← REST API (Slim 4 — all 9 endpoints)
│   └── .htaccess        ← URL rewriting
├── public/
│   └── index.html       ← Single-page web app (map + sidebar + modals)
├── sql/
│   └── schema.sql       ← Database schema (tables + foreign keys)
├── composer.json
└── README.md
```

---

## 📝 Notes

- All foreign keys use `CASCADE` on update/delete
- JWT tokens expire after 24 hours
- Endpoint #1 supports both JSON (default) and XML (`?format=xml`)

---

## 📜 License

Academic project — University of Thessaly, Digital Systems
