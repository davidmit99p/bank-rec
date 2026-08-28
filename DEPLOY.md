# Putting this live on Plesk

Written for the actual setup we settled on: a subdomain of
`davidmitchell.me.uk`, sharing the Accountant Toolkit's database, protected by
the same kind of Basic authentication.

That combination was chosen for one reason - **nothing in it depends on anyone
else**. No new database (the subscription is at its limit), no new PHP
extension (`pdo_mysql` is already working on this subscription), no host
involvement.

---

## 1. Create the tables

No new database is needed. The six tables all start with `rec_`, so they sit
alongside the Toolkit's own `saved_items`, `emails` and `email_attachments`
without touching them.

Plesk → **Databases** → `accountant_toolkit` → **phpMyAdmin**

1. Make sure `accountant_toolkit` is selected on the left.
2. Click the **SQL** tab.
3. Paste the whole of `sql/schema.sql` and press **Go**.

You should now see six new tables: `rec_ledger`, `rec_bank`, `rec_rules`,
`rec_runs`, `rec_match_groups`, `rec_match_lines`.

Optionally repeat with `sql/starter_rules.sql` for six ready-made rules. You
can delete them and write your own later.

---

## 2. Add the subdomain

Plesk → **Websites & Domains** → **Add Subdomain**

| Field | What to put |
| --- | --- |
| Subdomain name | `recon` |
| Parent domain | `davidmitchell.me.uk` |

Then issue an SSL certificate for it - Plesk's Let's Encrypt button. Basic
authentication sends the password on every request, so it must be over HTTPS.

---

## 3. Get the code onto the server

Plesk → **Websites & Domains** → `recon.davidmitchell.me.uk` → **Git**

Point it at the GitHub repository, the same way the Toolkit is set up. Turn on
automatic deployment and add the webhook to GitHub, so pushes go live on their
own.

---

## 4. Point the document root at `public`

Plesk → **Websites & Domains** → `recon.davidmitchell.me.uk` →
**Hosting Settings** → **Document root**

Set it to the `public` folder inside wherever Git deployed the repository.

This matters. It is what keeps `includes/config.php` - which holds the database
password - and the `storage/uploads` scratch folder unreachable from the web.
If the document root points at the top of the repository instead, both are
exposed.

---

## 5. Switch on Basic authentication

Plesk → **Websites & Domains** → `recon.davidmitchell.me.uk` →
**Password-Protected Directories**

Protect `/` and add yourself as a user. This is the same protection that sits
in front of the Toolkit today.

**Do this before you import any real bank data**, not after.

---

## 6. Create the config file

Plesk → **Files** → the `includes` folder of the new subdomain.

Copy `config.sample.php` to `config.php`. The database details are the ones the
Toolkit already uses, so the quickest and safest way to get them right is to
open the Toolkit's own `config.php` (in its `includes` folder) and copy the
three values across:

```php
'host' => 'localhost',
'name' => 'accountant_toolkit',
'user' => 'toolkit_user',
'pass' => 'the same password the Toolkit uses',
```

Use `localhost`, not `127.0.0.1` - that is what the Toolkit uses and what is
known to work here.

This file is deliberately **not** in GitHub, because it holds a password. It
has to be made here by hand, once. It then stays put and survives every future
deployment.

Also check that `storage/uploads` is writable by the web server. It is only
used as a scratch area while you confirm the columns of an uploaded file.

---

## Checking it worked

Visit `https://recon.davidmitchell.me.uk`. You should get a password prompt,
then the home screen showing zero ledger items and zero bank items.

| What you see | What it means |
| --- | --- |
| "Setup needed: copy includes/config.sample.php..." | Step 6 not done, or the file is in the wrong folder |
| Blank page or 500 error | Wrong password in `config.php`, or step 1 not done |
| "Class PDO not found" | The `pdo_mysql` PHP extension is off for this subdomain. It works on this subscription for the Toolkit, so this is unlikely - but if it happens, the host has to enable it |
| Page loads but has no styling | Document root is not pointing at `public` |
| No password prompt | Step 5 not done - do not import real data until it is |

---

## Moving to a database of its own, later

If the database allowance is ever raised, moving is straightforward:

1. In phpMyAdmin, export just the six `rec_` tables from `accountant_toolkit`.
2. Create the new database and import them into it.
3. Change `name`, `user` and `pass` in `config.php`.
4. Once you are happy, drop the six `rec_` tables from `accountant_toolkit`.

Nothing in the code needs to change.
