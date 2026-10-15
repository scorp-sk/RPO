# RPO – live clone of the Slovak Register of Legal Entities

A self-hosted, daily updated copy of the **Register of Legal Entities, Entrepreneurs and Public
Authorities of the Slovak Republic** (*Register právnických osôb, podnikateľov a orgánov verejnej
moci*, RPO) with a simple REST API. Given an IČO (Slovak company ID), it returns the current name,
registered address, dates of establishment and termination, and the entry in the source register
(e.g. the business register or the trade register).

- **Live data:** changes are downloaded every day from the official public RPO export published by
  the Statistical Office of the Slovak Republic, and once a month the whole database is reloaded
  from scratch.
- **Independent:** the API runs on your own MariaDB, so it does not depend on the availability or
  rate limits of the official API.
- **Compatible:** paths and error responses match the
  [official RPO API](https://rpo.minv.sk/rpo-api-doc.html); the response contains only the most
  commonly needed data.
- **Token access:** every API user gets their own token with request statistics.

> This project is not an official service of the Statistical Office of the SR or the Ministry of
> Interior of the SR. The data source is the public RPO export under the CC BY 4.0 license.

## How it works

```
public RPO export (Statistical Office of the SR)  ──►  rpo:sync (cron, daily)  ──►  MariaDB  ──►  REST API /rpo/v1/…
```

1. **Monthly full batch** (`batch-init/`) is loaded into the staging table `company_new` and then
   swapped for the `company` table with a single `RENAME TABLE`. During the import the API keeps
   serving the old data, and records deleted from RPO disappear.
2. **Daily batches** (`batch-daily/`) newer than the full batch are applied one by one, each in
   a single transaction.
3. Only the **current** state of each RPO record is stored, i.e. the value without `validTo` with
   the latest `validFrom`: name, registered address, IČO and source register. Postal codes are
   stored without spaces.

Batches are kept in the storage for 45 days only. If the sync does not run for longer than that,
`php bin/rpo rpo:sync --init` restores the data.

## API

### Access token

Every request needs a valid token, otherwise the API returns `401 Unauthorized`. The token is sent
in the `Authorization` header (recommended) or in the `token` query parameter:

```bash
curl -H "Authorization: Bearer rpo_…" "https://rpo.example.com/rpo/v1/search?identifier=31322832"
curl "https://rpo.example.com/rpo/v1/search?identifier=31322832&token=rpo_…"
```

The header is safer, because a token in the URL ends up in web server access logs and browser
history. The `token` parameter is never echoed back in responses (e.g. in `_links.self.href`).

### Search by IČO: `GET /rpo/v1/search?identifier={IČO}`

```json
{
  "results": [
    {
      "id": 9642643,
      "identifier": "42301769",
      "fullName": "JUDr. Soňa Petrusová, advokátka",
      "address": {
        "street": "Adyho",
        "regNumber": 34,
        "buildingNumber": "4624",
        "postalCode": "98401",
        "municipality": "Lučenec"
      },
      "establishment": "1996-07-18",
      "termination": null,
      "sourceRegister": {
        "code": "69",
        "registrationOffice": "Slovenská advokátska komora",
        "registrationNumber": "1609"
      }
    }
  ],
  "lastUpdate": "2026-10-09",
  "license": "1. ŠÚ SR ako správca…"
}
```

The IČO must match exactly. One IČO may have several records (e.g. a terminated entity and a new
one). At most 500 records are returned, ordered by `id`.

### Record detail: `GET /rpo/v1/entity/{id}`

Returns a single record in the same shape as an item of the `results` array, plus `lastUpdate`
and `license`.

### Response fields

| Field | Meaning |
| --- | --- |
| `id` | RPO record ID, same as in the official API |
| `identifier` | IČO; for a terminated entity the last known IČO |
| `fullName` | current full name |
| `address.street` | street |
| `address.regNumber` | land-registry number (*súpisné číslo*) |
| `address.buildingNumber` | street number (*orientačné číslo*) |
| `address.postalCode` | postal code without spaces |
| `address.municipality` | municipality |
| `establishment` / `termination` | date of establishment / termination (`null` if none) |
| `sourceRegister.code` | source register per codelist CL010112 (1 = business register, 2 = trade register, 18 = municipality…) |
| `sourceRegister.registrationOffice` | court or office where the entity is registered (e.g. `Okresný súd Žilina`) |
| `sourceRegister.registrationNumber` | registration number as stated in RPO (e.g. `Sro/13889/L`, `640-23223`) |
| `lastUpdate` | export date of the last imported batch, i.e. the day the data is from |
| `license` | license terms of the Statistical Office of the SR (CC BY 4.0), which every response must include |

For a terminated entity, `fullName` and `address` are `null`, because it no longer has a current
name or address. If RPO does not state a source register, `sourceRegister` is `null`.

### Error responses

| Situation | HTTP | `message` |
| --- | --- | --- |
| missing, invalid or revoked token | 401 | `Unauthorized` |
| `/search` without the `identifier` parameter | 400 | `No parameters specified in search request` |
| empty `identifier=` parameter | 400 | `Request not understood` |
| non-numeric `id` | 400 | `Invalid input parameter` |
| non-existent `id` | 404 | `No record found` |
| unknown path | 404 | `Page Not Found` |

## Installation

**Requirements:** PHP 8.4+ (extensions `pdo_mysql`, `zlib`, `curl`, `simplexml`), Composer,
MariaDB 10.11+, Apache with `mod_rewrite` and `AllowOverride All`. The first import downloads
about 1 GB of data.

```bash
composer install --no-dev
cp config.php.dist config.php        # fill in the database access
php bin/rpo rpo:schema               # create the tables
php bin/rpo rpo:sync -v              # first import: full batch + daily batches
php bin/rpo rpo:token:create "Name"  # first access token
```

The web server's DocumentRoot is the project root. `.htaccess` routes every request except
`.well-known/` to `index.php`, so neither the source files nor `config.php` are reachable over
the web.

### Configuration (`config.php`)

| Key | Meaning |
| --- | --- |
| `db` | MariaDB DSN, user and password |
| `bucket_url` | URL of the public storage with the RPO exports |
| `var_dir` | working directory for downloaded batches and the lock file |
| `base_path` | API path prefix (default `/rpo/v1`) |
| `timezone` | time zone for token statistics (default `Europe/Bratislava`) |
| `search_limit` | maximum number of `/search` results (default 500) |
| `license` | license text returned in responses |

The config file path can be changed with the `RPO_CONFIG` environment variable.

### Daily update (cron)

Daily batches show up in the storage around 03:00 UTC. The full batch is created once a month
(on the first Saturday) and uploaded on Sunday evening. `rpo:sync` works out on its own what needs
to be imported, so it is enough to run it once a day:

```cron
30 6 * * * cd /var/www/rpo && /usr/bin/php bin/rpo rpo:sync >> var/sync.log 2>&1
```

## Commands

| Command | Description |
| --- | --- |
| `rpo:sync` | imports a new full batch (if available) and all daily batches not yet applied |
| `rpo:sync --init` | forces a reload of the latest full batch |
| `rpo:sync --file=batch-daily/actual_2026-10-08.json.gz` | imports a single daily file |
| `rpo:sync --dry-run` | only prints what would be imported |
| `rpo:schema` | creates the tables (after an update also the missing ones) |
| `rpo:token:create "Name"` | creates a token for a user; the token is shown only once |
| `rpo:token:list` | lists tokens: status, last use and request counts for today, the last 30 days and in total |
| `rpo:token:usage 3 --days=30` | request count of token #3 per day |
| `rpo:token:revoke 3` | revokes token #3; it stays in the list for the statistics |

Commands are run as `php bin/rpo <command>`.

The database stores only the SHA-256 hash of each token, so a lost token cannot be shown again.
Create a new one and revoke the old one.

## Data source and license

The data comes from the public RPO export maintained by the Statistical Office of the Slovak
Republic ([export and API description](https://rpo.minv.sk/rpo-api-doc.html)) and is provided under
the [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) license. That is why every API
response includes the `license` field with the license terms of the Statistical Office of the SR.
