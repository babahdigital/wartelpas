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
- **AJAX Load**: `$.get("aload.php?session=" + session + "&load=users", function(data) { $("#content").html(data); });`</content>
<parameter name="filePath">d:/Data/Projek/wartelpas/.github/copilot-instructions.md