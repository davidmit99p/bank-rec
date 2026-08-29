# Putting this live on Plesk

Written for the setup we actually landed on, after finding both subscriptions
are at their limits:

| | |
| --- | --- |
| **Address** | `https://www.davidmitchell.me.uk/recon/` |
| **Code lives in** | a folder of the existing site - no spare domain or subdomain |
| **Database** | a new `entigy_recon` on the **daveslist** subscription, which has room |
| **Protection** | the Basic authentication already covering `davidmitchell.me.uk` |

Nothing here needs your host or your dev ops person. All the databases sit on
the same MySQL server, so code on `davidmitchell.me.uk` can use a database
created under the daveslist subscription - the subscription boundary is an
administrative label, not a wall. And `pdo_mysql` is already working on
`davidmitchell.me.uk`, because the Accountant Toolkit runs on it.

---

## 1. Create the database (on the daveslist subscription)

Plesk → **Subscriptions** → **daveslist.co.uk** → **Databases** → **Add Database**

| Field | What to put |
| --- | --- |
| Database name | `entigy_recon` |
| Type | MySQL / MariaDB |

Then a user for it, on the same screen:

| Field | What to put |
| --- | --- |
| Database user name | `entigy_recon_user` |
| Password | **type your own, letters and numbers only** |
| Access control | **Allow local connections only** |

**Do not use Plesk's Generate button.** When the Accountant Toolkit was
deployed, a generated password containing special characters caused "Access
denied for user ... @localhost" and took a while to find. Make it long, but
keep it to letters and numbers.

Write down the database name, the user name and the password. You will need
them at step 5.

---

## 2. Create the tables

Plesk → **Databases** → `entigy_recon` → **phpMyAdmin**

1. Check `entigy_recon` is selected on the left.
2. **SQL** tab.
3. Paste the whole of `sql/schema.sql`, press **Go**.

Six tables should appear: `rec_ledger`, `rec_bank`, `rec_rules`, `rec_runs`,
`rec_match_groups`, `rec_match_lines`.

Optionally repeat with `sql/starter_rules.sql` for six ready-made rules.

---

## 3. Deploy the code into a folder

Plesk → **Websites & Domains** → `davidmitchell.me.uk` → **Git** →
**Add Repository**

This is a *second* repository on the same domain, alongside the Accountant
Toolkit's. Plesk allows that.

| Field | What to put |
| --- | --- |
| Remote repository | `https://github.com/davidmit99p/bank-rec` |
| Branch | `main` |
| Deployment path | `httpdocs/public/recon` |

That path matters. `httpdocs/public` is the Toolkit's document root, so
`httpdocs/public/recon` is what makes the app appear at
`davidmitchell.me.uk/recon/`.

Turn on automatic deployment and add the webhook to GitHub, the same as the
Toolkit, so future pushes go live on their own.

**Do not change the document root.** The Toolkit needs it where it is.

---

## 4. Check the protection is working

Because the app is inside a web folder rather than behind a document root,
three things keep the private files unreachable, and it is worth confirming
the first one by eye:

1. A `.htaccess` at the top of the app rewrites every request into `public/`,
   so `/recon/includes/config.php` resolves to a file that does not exist.
2. `includes/` and `storage/` carry their own deny-all rules.
3. The whole domain is behind Basic authentication.

After step 5, visit `https://www.davidmitchell.me.uk/recon/includes/config.php`
in a browser. You should get **404 or Forbidden**. If you see PHP code or a
download prompt, stop and tell me - `mod_rewrite` is not doing its job and the
database password is exposed.

---

## 5. Create the config file - ABOVE the web folder

This is the one step that differs from a normal install.

Plesk → **Files** → navigate to `httpdocs` (**not** `httpdocs/public`).

Create a file there called **`bank-rec-config.php`** with this in it, using
your own details from step 1:

```php
<?php
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'entigy_recon',
        'user'    => 'entigy_recon_user',
        'pass'    => 'the password you set in step 1',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Bank Reconciliation',
    ],
];
```

`httpdocs` is above the document root, so nothing on the web can reach this
file at all. The app knows to look there.

Use `localhost`, not `127.0.0.1` - that is what the Toolkit uses and what is
known to work on this server.

Finally, check `httpdocs/public/recon/storage/uploads` is writable by the web
server. It is only used as a scratch area while you confirm the columns of an
uploaded file.

---

## Checking it worked

Visit `https://www.davidmitchell.me.uk/recon/`. After the password prompt you
should get the home screen, showing zero ledger items and zero bank items.

| What you see | What it means |
| --- | --- |
| "Setup needed: copy includes/config.sample.php..." | Step 5 not done, or the file is in the wrong folder - it goes in `httpdocs`, not `httpdocs/public` |
| Blank page or 500 error | Wrong password in the config, or step 2 not done |
| A file listing instead of the app | `mod_rewrite` is off - tell me |
| Page loads but has no styling | The rewrite is not finding `public/assets` - tell me |
| "Class PDO not found" | Unlikely here, since the Toolkit works - but it would mean `pdo_mysql` is off |

Also check the Accountant Toolkit still works at
`https://www.davidmitchell.me.uk/` afterwards. It should be untouched, but it
shares a domain with this now, so it is worth thirty seconds to confirm.

---

## Tidying this up later

This layout is a workaround for the plan limits, not the ideal.

**Three things to ask your dev ops person for**, none of them urgent:

1. Raise the **domain / subdomain allowance** - there was no room for even a
   subdomain, which is why the app is in a folder of an existing site.
2. Raise the **database allowance** on `davidmitchell.me.uk` - it was full at
   2 of 2, which is why the database lives on the daveslist subscription.
3. Enable the **`zip` PHP extension** - without it the tool cannot read `.xlsx`
   files and you have to save spreadsheets as CSV first. No code change needed.

You will hit the first two again with Process Manager.

Once the allowances are raised:

1. Create a subdomain, say `recon.davidmitchell.me.uk`.
2. Point a new Git deployment at it, document root on `public`.
3. Move `bank-rec-config.php` into the app's `includes/` folder as `config.php`.
4. Switch on Basic authentication for the subdomain.
5. Delete `httpdocs/public/recon`.

The database does not need to move, and no code has to change. The root
`.htaccess` becomes dormant - it sits above the document root and is never
read.
