Online User View — Moodle Block Plugin

A Moodle block plugin that gives administrators and teachers visibility into who is online, segmented by time windows and roles. It also provides interactive reports, heatmaps, and drill-down lists of users (with full names and email addresses).

✨ Features

Dashboard Block

Quick snapshot of users online in the last 5 minutes, segmented by:

All users

Students only

Staff only

Summary counts for 5, 30, and 60 minutes.

Links to full reports, heatmaps, and user lists.

Reports (report.php)

View online activity by hour (for a single day) or by day (across a date range).

Interactive charts (line and bar) using Moodle’s chart API.

Clickable counts: drill down into the list of users (fullname, email, role, last activity) for the selected timeframe.

CSV export support.

Heatmap (heatmap.php)

Monthly calendar heatmap: rows = hours, columns = days.

Cells shaded green → yellow → red based on relative number of logins.

Each cell is clickable, showing the exact users for that hour.

Users List (users.php)

Lists users active in a given timeframe, with:

Fullname

Email

Role (Staff / Student / Other)

Last activity timestamp

Filters for All / Students / Staff.

Quick links for 5, 30, 60 minute windows.

CLI Seeder Tools (for test/demo data)

Generate dummy students and teachers.

Enrol teachers into random courses.

Create artificial login records so reports look realistic.

📦 Installation

Clone or download this repository.

Place the folder in your Moodle installation under:

moodle/blocks/onlineuserview


Log in as admin and run the upgrade process.

⚙️ CLI Tools

Run from your Moodle root:

# Create N teacher accounts
php blocks/onlineuserview/cli/seed_create_teachers.php --count=10 --prefix=teacher

# Enrol teachers into random courses
php blocks/onlineuserview/cli/seed_enrol_teachers.php --start=5 --count=16 --prefix=teacher --role=editingteacher --mincourses=2 --maxcourses=4

# Seed dummy login records
php blocks/onlineuserview/cli/seed_logins.php --days=30 --users=50 --avgperday=20

🔧 Configuration

Place the block on the dashboard or a course page.

Use the role filters (All / Students / Staff) to adjust reports and heatmaps.

Click counts in tables or cells to drill down into actual users by email.

📊 Example Use Cases

Identify busy times of day/week on your Moodle site.

See which staff are actively teaching online.

Export student activity snapshots for audits or management reports.

Populate a demo site with realistic activity data.

📝 Requirements

Moodle 4.5 or later (tested).

Standard logstore enabled (logstore_standard_log).