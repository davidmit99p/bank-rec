# Bank Reconciliation

Matches a file of ledger transactions against a file of bank transactions,
using a library of rules that grows over time.

Plain PHP and MariaDB, no frameworks and no build step. Every table is named
`rec_something`, so the six tables sit safely alongside other tables in a
database you already use - currently the Accountant Toolkit's.

## The golden rule

A match is only ever made when **both sides total exactly the same amount**.
This is enforced in three separate places:

- the matching engine will not create an unbalanced group,
- the Match ticked items button stays greyed out until your ticks agree,
- Finalise checks every ticked group again and refuses the whole batch if
  one of them is out.

## Setting it up

1. **Create the tables.** In Plesk go to Databases, open phpMyAdmin, pick your
   database, choose the SQL tab, paste in `sql/schema.sql` and press Go.
   Optionally do the same with `sql/starter_rules.sql` for a set of rules to
   begin with.

2. **Tell it where the database is.** Copy `includes/config.sample.php` to
   `includes/config.php` and fill in the database name, user and password.
   That file is not committed to GitHub, because it holds a password.

3. **Point the domain at the `public` folder.** In Plesk this is the
   "Document root" setting for the domain or subdomain.

4. **Check `storage/uploads` is writable** - it is only used as a scratch area
   while you are confirming the columns of an uploaded file.

Requires PHP 7.4 or later. Reading `.xlsx` files needs the standard `zip`
extension, which Plesk has switched on by default; if it is missing, save the
file as CSV instead.

## Using it

**1. Import.** Upload your ledger file on the left and your bank file on the
right. CSV or Excel. It shows you the first few rows and its guess at which
column is the date, the description and the value, so you can correct it before
anything is loaded. Importing adds to what is already there, so you can load a
month at a time.

**2. Rules.** A rule has two halves. The left form says which *ledger* lines
the rule applies to; the right form says which *bank* lines they should be
paired with. Leave a box on "anything" to ignore it. Underneath, you set how
the two sides may be paired:

| Setting | What it does |
| --- | --- |
| Shape of the match | one-to-one, several ledger lines to one bank line, or one ledger line to several bank lines |
| Most lines in a group | the largest group the engine will build (2 to 8) |
| Dates may differ by | how many days apart the two sides may be |
| Signs | whether the bank shows the same sign as the ledger, or the opposite |
| Descriptions share a word | only pair items whose wording overlaps |

Rules are tried in the order you give them, so put the tightest first. Once a
transaction is claimed by one rule, later rules leave it alone.

**3. Transactions.** Everything still to be matched, ledger on the left and
bank on the right. Two ways to match:

- **Process rules** applies every active rule and produces suggestions.
- **Ticking items yourself** on both sides. The running totals at the top show
  each side and the difference between them; the button only comes alive when
  they agree. These are recorded against rule reference `manual`.

**4. Review and finalise.** Suggestions are not committed until you say so.
Untick anything you disagree with, then press Finalise. Only the ticked
matches are written; anything unticked is thrown away and the items stay open.

Each matched transaction is stamped with the rule number that matched it and
the reference of the run that did it, so you can always see why. The
transactions screen only ever shows what is still open - it grows as you import
and shrinks as you finalise.

If you finalise something you should not have, **Unpick** on the Runs screen
reverses the whole run and puts those items back.

## Trying rules out first

`tools/dry_run.php` reads two files straight from disk and prints what would
have matched, without touching the database:

```
php tools/dry_run.php "ledger.xlsx" "bank.xlsx"
```

Useful for testing a rule idea before you commit to it. Edit the `$RULES`
array at the top of that file to try different combinations.

## What is in each folder

```
includes/config.sample.php   copy to config.php and fill in
includes/db.php              database connection and small helpers
includes/importer.php        reads CSV and .xlsx, works out dates and amounts
includes/matcher.php         the matching engine and the golden-rule checks
includes/layout.php          page header and footer
public/                      the screens - this is the document root
sql/schema.sql               run once to create the tables
sql/starter_rules.sql        optional set of rules to begin with
tools/dry_run.php            try rules against two files, no database needed
```

## Tables

| Table | Holds |
| --- | --- |
| `rec_ledger` | table 1 - the ledger transactions |
| `rec_bank` | table 2 - the bank transactions |
| `rec_rules` | the rule library, one row per rule |
| `rec_runs` | one row per matching run |
| `rec_match_groups` | one suggested or committed match |
| `rec_match_lines` | the individual transactions inside a match |
