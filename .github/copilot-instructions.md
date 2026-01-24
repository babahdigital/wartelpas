# Mikhmon V3 AI Coding Instructions

## Overview
Mikhmon is a PHP-based web application for managing MikroTik router hotspots. It provides a dashboard for user management, profiles, reports, and voucher generation. The app connects to MikroTik routers via RouterOS API to perform hotspot operations.

## Architecture
- **Entry Point**: `index.php` handles session routing and redirects to `admin.php` for login.
- **Configuration**: Router details stored in `include/config.php` as associative arrays (e.g., `$data['session_id'] = ['id', 'user', 'pass', 'name', 'domain', 'currency', 'decimal', 'comment', 'disabled']`).
- **API Connection**: Uses `RouterosAPI` class from `lib/routeros_api.class.php` for MikroTik communication. Global `$API` instance connects using `$iphost`, `$userhost`, `$passwdhost`.
- **Database**: SQLite in `db_data/mikhmon_stats.db` for local logs (security_log, login_history). Use PDO for queries.
- **Sessions**: Multi-router support via URL `?session=session_id`. Session data stored in `mikhmon_session/`.
- **UI**: Themes in `css/`, languages in `lang/`, JS in `js/`. AJAX loads content dynamically (e.g., `dashboard/aload.php`).

## Key Components
- **Dashboard** (`dashboard/`): Home stats, traffic charts (Highcharts), logs.
- **Hotspot** (`hotspot/`): User/profiles management, active users, hosts.
- **Reports** (`report/`): Sales, live reports, sync to DB.
- **Process** (`process/`): Backend actions (add/remove users, schedulers/scripts on router).
- **Voucher** (`voucher/`): Print templates, QR codes.
- **DHCP** (`dhcp/`): Leases management.

## Workflows
- **Development**: Run `docker-compose up -d` for local setup (mounts config, DB, custom files).
- **Add Router**: Edit `include/config.php` array, e.g., `$data['new_session'] = ['1'=>'new_session!192.168.1.1', 'new_session@|@admin', ...]`.
- **User Management**: Profiles create router schedulers/scripts for expiration. Removal deletes associated router resources.
- **Debugging**: Check `logs/`, `error.php`. API calls logged in router.

## Conventions
- **Session Checks**: Every page starts with `if (!isset($_SESSION["mikhmon"])) { header("Location:../admin.php?id=login"); }`.
- **API Usage**: `$API->comm("/path/print", ["?key" => "value"])` for queries, `[".id" => "value"]` for actions.
- **Includes**: Common files from `include/` (config, menu, lang).
- **File Structure**: Actions in `process/`, views in component folders.
- **Expiration Logic**: Profiles use router schedulers for auto-removal. Update profiles via Mikhmon UI to sync.
- **Security**: Obfuscated session IDs, HTTP-only cookies.

## Examples
- **Connect API**: `if(isset($API) && !$API->connected) { $API->connect($iphost, $userhost, decrypt($passwdhost)); }`
- **Add User**: POST to `process/adduser.php` with profile data, creates router user + scheduler.
- **DB Query**: `$stmt = $db->prepare("SELECT * FROM login_history WHERE username = ?"); $stmt->execute([$username]);`
- **AJAX Load**: `$.get("aload.php?session=" + session + "&load=users", function(data) { $("#content").html(data); });`

## Coding Standards & Style
- **PHP Version**: Ensure compatibility with PHP 7.4+. Use strict typing where possible but maintain compatibility with legacy Mikhmon logic.
- **Indentation**: Use 4 spaces for indentation.
- **Naming Conventions**:
  - Variables: `snake_case` (e.g., `$user_profile`, `$session_data`).
  - Functions: `camelCase` (e.g., `getSystemStatus`, `formatBytes`).
  - Constants: `UPPER_CASE` (e.g., `DB_PATH`, `API_TIMEOUT`).
- **Code Completeness**:
  - **NEVER** use placeholders like `// ... rest of the code`, `/* code remains the same */`, or `// existing code`.
  - **ALWAYS** generate the full, complete, and updated file content when suggesting changes.
  - Do not use comments like `/*........*/` to skip sections.
- **Comments**: Keep comments minimal and strictly necessary. Avoid obvious comments.
- **Efficiency**: Avoid redundant logic. Verify variables before use (e.g., `isset()` or `!empty()`) to prevent PHP notices.

## Security Guidelines
- **Input Sanitization**: Always sanitize `$_GET` and `$_POST` inputs before using them in logic or API commands.
  - Use `htmlspecialchars()` for outputting to HTML.
  - Validate data types (e.g., ensure numeric values for limits).
- **Session Security**: Do not expose session IDs in the UI. Ensure `session_start()` is handled safely in `index.php` or `include/config.php`.
- **RouterOS API**:
  - Always check `$API->connected` before attempting commands.
  - Handle connection timeouts gracefully with try-catch blocks or connection checks.
  - Encrypt/Decrypt passwords properly using Mikhmon's internal logic when storing/retrieving from config.

## Development Constraints (Strict)
1. **No Assumptions**: Do not assume the existence of functions or variables not present in the provided context. If information is missing, ask the user for the relevant file content before generating code.
2. **Analysis First**: Before suggesting a fix, analyze the entire snippet for potential side effects. If an error is repetitive, suggest a holistic fix rather than a partial patch.
3. **Language**: Provide all explanations and conversations in **Indonesian (Bahasa Indonesia)**. Code comments should follow the existing file style (usually English or Indonesian).
4. **Output Quality**: Solutions must be production-ready (Siap Pakai). Test logic mentally for edge cases (e.g., what if the router is offline?).
5. **Formatting**: When presenting code, ensure it is in a single, copy-pasteable block.