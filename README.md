# SpråkApp Backend

PHP Backend med MariaDB för SpråkApp.

## Installation

### 1. Installera PHP och MariaDB

Se till att du har PHP 8.0+ och MariaDB installerat.

### 2. Konfigurera databas

Kör SQL-schemat:
```bash
mysql -u root -p < database/schema.sql
```

### 3. Installera PHP-beroenden

```bash
cd backend
composer install
```

### 4. Konfigurera miljövariabler

Kopiera `.env.example` till `.env` och uppdatera värdena:
```bash
cp .env.example .env
```

Redigera `.env` med dina databasinställningar och JWT-nyckel.

### 5. Starta PHP-server

```bash
php -S localhost:8000
```

## API Endpoints

### Autentisering
- `POST /api/auth.php` - Logga in
- `POST /api/auth.php?action=register` - Registrera ny användare
- `GET /api/auth.php?action=me` - Hämta inloggad användare
- `PUT /api/auth.php?action=logout` - Logga ut

### Kurser
- `GET /api/courses.php` - Lista alla kurser
- `GET /api/courses.php?id={id}` - Hämta en kurs
- `POST /api/courses.php` - Skapa ny kurs (admin)
- `PUT /api/courses.php?id={id}` - Uppdatera kurs (admin)
- `DELETE /api/courses.php?id={id}` - Ta bort kurs (admin)

### Kapitel
- `GET /api/chapters.php?course_id={id}` - Lista kapitel för en kurs
- `GET /api/chapters.php?id={id}` - Hämta ett kapitel
- `POST /api/chapters.php` - Skapa nytt kapitel (admin)
- `PUT /api/chapters.php?id={id}` - Uppdatera kapitel (admin)
- `DELETE /api/chapters.php?id={id}` - Ta bort kapitel (admin)

### Vocabulär
- `GET /api/vocabulary.php?chapter_id={id}` - Lista vocabulär för ett kapitel
- `POST /api/vocabulary.php` - Skapa nytt ord (admin)
- `PUT /api/vocabulary.php?id={id}` - Uppdatera ord (admin)
- `DELETE /api/vocabulary.php?id={id}` - Ta bort ord (admin)

### Övningar
- `GET /api/exercises.php?chapter_id={id}` - Lista övningar för ett kapitel
- `POST /api/exercises.php` - Skapa ny övning (admin)
- `PUT /api/exercises.php?id={id}` - Uppdatera övning (admin)
- `DELETE /api/exercises.php?id={id}` - Ta bort övning (admin)

## Autentisering

API:et använder JWT (JSON Web Tokens) för autentisering. Inkludera token i Authorization header:

```
Authorization: Bearer {token}
```

## Standard Admin-konto

Efter att ha kört schema.sql finns ett standard admin-konto:
- **Email:** admin@sprakapp.com
- **Lösenord:** admin123

⚠️ **VIKTIGT:** Ändra detta lösenord omedelbart efter installation!
