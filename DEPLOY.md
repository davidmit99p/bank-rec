# Putting this live on Plesk

Five steps. Do them in order. Steps 1 and 2 are the ones to do now; the rest
need the code on the server first.

---

## 1. Create the database

Plesk → **Databases** → **Add Database**

| Field | What to put |
| --- | --- |
| Database name | `entigy_recon` |
| Type | MySQL / MariaDB (whichever Plesk offers) |
| Related site | the domain this tool will live on |

Then create a user for it, on the same screen:

| Field | What to put |
| --- | --- |
| Database user name | `entigy_recon_user` |
| Password | type your own, **letters and numbers only** - see the warning below |
| Access control | **Allow local connections only** |

Two things to be deliberate about:

- **Give it its own user.** Don't reuse `entigy_demo_api`. If one is ever
  compromised, the other is untouched.
- **Local connections only.** Nothing outside the server ever needs to reach
  this database directly.
- **Do not use Plesk's Generate button for the password.** When the Accountant
  Toolkit was deployed, a generated password containing special characters
  caused "Access denied for user ... @localhost" and took a while to find. Use
  a long password made only of letters and numbers.

You will need three things later: the database name, the user name and that
password. The password never needs to be typed into a chat or committed to
GitHub - it only ever goes into one file on the server.

---

## 2. Create the tables

Plesk → **Databases** → find `entigy_recon` → **phpMyAdmin**

1. Make sure `entigy_recon` is selected on the left.
2. Click the **SQL** tab.
3. Paste the whole of `sql/schema.sql` and press **Go**.

You should end up with six tables: `ledger`, `bank`, `rules`, `runs`,
`match_groups`, `match_lines`.

Optionally repeat with `sql/starter_rules.sql` to load six ready-made rules.
You can always delete them and write your own.

---

## 3. Get the code onto the server

Plesk → **Websites & Domains** → your domain → **Git**

Point it at the GitHub repository. Plesk can pull on demand, or automatically
every time you push, using the webhook it gives you.

---

## 4. Point the domain at the `public` folder

Plesk → **Websites & Domains** → your domain → **Hosting Settings** →
**Document root**

Set it to the `public` folder inside wherever the repository was deployed.

This matters. It is what keeps `includes/config.php` - which holds your
database password - and the `storage/uploads` scratch folder unreachable from
the web. If the document root points at the top of the repository instead,
both are exposed.

---

## 5. Create the config file on the server

Plesk → **Files** → navigate to the `includes` folder.

Copy `config.sample.php` to `config.php` and fill in the three values from
step 1:

```php
'name' => 'entigy_recon',
'user' => 'entigy_recon_user',
'pass' => 'the password Plesk generated',
```

This file is deliberately **not** in GitHub, because it holds a password. So
it has to be made here, by hand, once. It then stays put and survives every
future deployment - which is what you want.

Also check that `storage/uploads` is writable by the web server. It is only
used as a scratch area while you confirm the columns of an uploaded file.

---

## Checking it worked

Visit the domain. You should get the home screen showing zero ledger items and
zero bank items.

If you get **"Setup needed: copy includes/config.sample.php..."**, step 5 has
not been done, or the file is in the wrong place.

If you get a blank page or a 500 error, the usual causes are a wrong password
in `config.php`, or the tables not having been created in step 2.

If you get **"Class PDO not found"**, the `pdo_mysql` PHP extension is not
enabled for this domain. It was switched on for `davidmitchell.me.uk` when the
Accountant Toolkit went live, but it may not be server-wide. Your host has to
enable it - no code change is needed.
