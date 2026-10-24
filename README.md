# Stride

Stride is a project and team workspace built with React, PHP, and MySQL. It supports multiple workspaces, project and task tracking, team roles, analytics, calendar views, and light, dark, or system appearance.

## Features

- Multiple workspaces with workspace membership and role management
- Projects with categories, status, due dates, colors, and saved stars
- Task assignment, priorities, due dates, status updates, and a drag-and-drop project board
- Workspace task list, calendar, project analytics, and activity feed
- Session-based sign-in and account activation for workspace members
- Light, dark, and system themes

## Requirements

- Node.js 20 or newer and npm
- PHP 7.4 or newer with PDO MySQL enabled
- MySQL 8.0 or compatible MariaDB

## Local setup

1. Clone the repository and enter its directory.

2. Create the database and starter data from the single SQL file. This initializes a fresh Stride database named `stride`:

   ```sh
   mysql -u root -p < database/schema.sql
   ```

   The seed data creates the `Northstar Studio` workspace, five members, four projects, and sample tasks. Users have no password initially; use **Activate your account** on the login page to set one for a seeded member email. This starter activation flow is intended for local/demo use.

3. Configure the PHP API. Set database credentials as environment variables before starting PHP. For example, in PowerShell:

   ```powershell
   $env:DB_HOST = "127.0.0.1"
   $env:DB_NAME = "stride"
   $env:DB_USER = "root"
   $env:DB_PASS = "your_mysql_password"
   $env:FRONTEND_ORIGIN = "http://localhost:5173"
   php -S localhost:8000 -t .
   ```

   If your local MySQL root account has no password, set `DB_PASS` to an empty string. Keep the PHP server running in this terminal. The API URL defaults to `http://localhost:8000/api/index.php`.

4. In another terminal, create the Vite environment file and start the frontend:

   ```sh
   cp .env.local.example .env.local
   npm ci
   npm run dev
   ```

   On Windows PowerShell, use `Copy-Item .env.local.example .env.local` instead of `cp` if needed. Open the URL printed by Vite (normally `http://localhost:5173`). Keep the frontend and PHP API on the `localhost` hostname so the browser sends the PHP session cookie.

## Configuration

The frontend API URL is set by `VITE_API_URL` in `.env.local`. See [.env.local.example](.env.local.example). PHP database settings are read from `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`; configure them in the shell or hosting environment. `FRONTEND_ORIGIN` controls the allowed browser origin for API requests.

Never commit `.env.local`, database credentials, or other secrets. The repository ignores local environment files.

## API overview

The PHP JSON API is `api/index.php`; use the `resource` query parameter to select `health`, `auth`, `workspaces`, `projects`, `tasks`, `members`, `activity`, or `analytics`. Authenticated requests use PHP session cookies. The API checks workspace membership and applies role restrictions to workspace and member management.

## Project structure

```text
api/                 PHP API and database configuration
database/schema.sql  Complete schema and demo seed data
src/                 React application and styles
.env.local.example   Frontend API URL example
```

## Notes and deployment

- Project attachments and help feedback are stored in the current browser's local storage; they are not uploaded to MySQL or shared with other members.
- The seeded account activation flow does not send invitation or password-reset emails.
- For production, use HTTPS, private environment-based credentials, a secure production session store, verified invitations and password recovery, and a CORS origin restricted to the deployed frontend. Replace demo seed data before real use.
- The database script uses `CREATE TABLE IF NOT EXISTS`; it is designed to initialize a fresh database. Back up and migrate an existing database separately rather than treating this seed script as an upgrade migration.
