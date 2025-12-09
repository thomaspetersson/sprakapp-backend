# Backend API Integration Guide

## Standard Response Format

All API responses följer detta format:

### Success Response:
```json
{
  "success": true,
  "data": { /* response data */ }
}
```

### Error Response:
```json
{
  "success": false,
  "error": "Error message",
  "status": 400
}
```

## Authentication

JWT token hanteras via `Authorization: Bearer <token>` header.

Token sparas i localStorage efter login:
```javascript
localStorage.setItem('jwt_token', token);
```

## API Endpoints Status

### ✅ Implemented with Standard Format

#### Config Helper Functions (config.php)
- `sendSuccess($data, $statusCode = 200)` - Skickar success response
- `sendError($message, $statusCode = 400)` - Skickar error response

#### Courses API (courses.php)
- ✅ `GET /courses.php` - Hämta alla publika kurser
- ✅ `GET /courses.php?id={id}` - Hämta specifik kurs
- ✅ `POST /courses.php` - Skapa ny kurs (admin)
- ✅ `PUT /courses.php?id={id}` - Uppdatera kurs (admin)
- ✅ `DELETE /courses.php?id={id}` - Ta bort kurs (admin)

### ⚠️ Needs Update to Standard Format

#### Auth API (auth.php)
- ❌ `POST /auth.php?action=register` - Behöver `sendSuccess()` wrapper
- ❌ `POST /auth.php` (login) - Behöver `sendSuccess()` wrapper  
- ❌ `GET /auth.php?action=me` - Behöver `sendSuccess()` wrapper
- ❌ `PUT /auth.php?action=logout` - Behöver `sendSuccess()` wrapper

**Current format:**
```json
{
  "user": {...},
  "token": "..."
}
```

**Should be:**
```json
{
  "success": true,
  "data": {
    "user": {...},
    "token": "..."
  }
}
```

#### Chapters API (chapters.php)
- ❌ Needs complete implementation with standard format
- Required endpoints:
  - `GET /chapters.php?course_id={id}` - Hämta alla kapitel för kurs
  - `GET /chapters.php?id={id}` - Hämta specifikt kapitel med innehåll
  - `POST /chapters.php` - Skapa nytt kapitel
  - `PUT /chapters.php?id={id}` - Uppdatera kapitel
  - `DELETE /chapters.php?id={id}` - Ta bort kapitel

#### Vocabulary API (vocabulary.php)
- ❌ Needs complete implementation with standard format
- Required endpoints:
  - `GET /vocabulary.php?chapter_id={id}` - Hämta ordlista för kapitel
  - `POST /vocabulary.php` - Skapa nytt ord
  - `PUT /vocabulary.php?id={id}` - Uppdatera ord
  - `DELETE /vocabulary.php?id={id}` - Ta bort ord

#### Exercises API (exercises.php)
- ❌ Needs complete implementation with standard format
- Required endpoints:
  - `GET /exercises.php?chapter_id={id}` - Hämta övningar för kapitel
  - `POST /exercises.php` - Skapa ny övning
  - `PUT /exercises.php?id={id}` - Uppdatera övning
  - `DELETE /exercises.php?id={id}` - Ta bort övning

### 🔜 Not Yet Implemented

#### Stats API (stats.php) - NEW FILE NEEDED
- `POST /stats.php?action=chapter` - Hämta användarstatistik för kapitel
- `POST /stats.php?action=update` - Uppdatera användarstatistik

#### Admin User Management API (admin.php) - NEW FILE NEEDED
- `GET /admin.php?action=profiles` - Hämta alla användarprofiler
- `POST /admin.php?action=assign` - Tilldela kurs till användare
- `DELETE /admin.php?action=revoke` - Ta bort kurstilldelning
- `PUT /admin.php?action=dates` - Uppdatera kursdatum för användare

## Frontend Configuration

### Environment Variables (.env)

```env
# Lokalt
VITE_API_URL=http://localhost:8000/api

# Produktion
VITE_API_URL=https://d90.se/api
```

### Start Backend Locally

```bash
cd backend
php -S localhost:8000
```

## Testing the Integration

1. **Start backend:**
   ```bash
   cd backend
   php -S localhost:8000
   ```

2. **Update frontend data source:**
   ```typescript
   // src/config/data-source.ts
   export const ACTIVE_DATA_SOURCE: DataSourceType = 'php_api';
   ```

3. **Start frontend:**
   ```bash
   pnpm dev
   ```

4. **Test login:**
   - Email: `admin@sprakapp.com`
   - Password: `admin123`

## Next Steps

1. ✅ Update remaining auth.php responses to use sendSuccess/sendError
2. ⚠️ Complete chapters.php implementation
3. ⚠️ Complete vocabulary.php implementation  
4. ⚠️ Complete exercises.php implementation
5. 🔜 Create stats.php
6. 🔜 Create admin.php for user management

## Migration Priority

**Phase 1 (Critical):**
- Auth (login/register/logout)
- Courses (list/detail)
- Chapters (list/detail)

**Phase 2 (Important):**
- Vocabulary (CRUD)
- Exercises (CRUD)

**Phase 3 (Admin):**
- Stats tracking
- User management
- Course assignments
