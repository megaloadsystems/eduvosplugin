# block_onlineuserview

A block that shows a quick 5‑minute snapshot of online users and links to a full dashboard page with filters and a detailed list. Includes a CLI script to seed demo users and a course on fresh sites.

## Install
1. Upload the ZIP via **Site administration → Plugins → Install plugins** (or extract to `blocks/onlineuserview`).
2. Go to **Site administration → Notifications** to complete installation.
3. Add the **Online User View** block to a page (e.g., Dashboard or Site home).
4. Managers can open the full dashboard at `/blocks/onlineuserview/view.php`.

## Seeding demo users
From Moodle root:

```bash
php blocks/onlineuserview/cli/seed_users.php --students=20 --staff=3 --domain=mbse.local --course=MBSE101 --password='P@ssw0rd!23'
```

## Online logic & roles
- Online if `user.lastaccess >= (time() - window)` and user is active, confirmed, non-guest.
- Students: any role with archetype `student`.
- Staff: any role with archetype in `editingteacher`, `teacher`, `coursecreator`, `manager`.
- The block shows a 5‑minute summary; click **Open full dashboard** for filters and listings.
# eduvosplugin
