# RPO – live clone of the Slovak Register of Legal Entities

A self-hosted, daily updated copy of the **Register of Legal Entities, Entrepreneurs and Public
Authorities of the Slovak Republic** (*Register právnických osôb, podnikateľov a orgánov verejnej
moci*, RPO) with a simple REST API. Given an IČO (Slovak company ID), it returns the current name,
registered address, dates of establishment and termination, and the entry in the source register
(e.g. the business register or the trade register). It also checks whether an IČ DPH (Slovak VAT
ID) is valid, using the list of registered VAT payers of the Financial Administration of the SR.

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

The **list of registered VAT payers** (`ds_dphs.zip`, open data of the Financial Administration of
the SR, updated daily around 01:40) is imported by `rpo:vat:sync` as a whole into `vat_payer_new`
and swapped for `vat_payer` the same way. A list with fewer than 100 000 payers is treated as
broken and the current data is kept.

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
      },
      "vatId": null
    }
  ],
  "lastUpdate": "2026-10-09",
  "license": "1. ŠÚ SR ako správca…"
}
```

The IČO must match exactly. One IČO may have several records (e.g. a terminated entity and a new
one). At most 500 records are returned, ordered by `id`.

### Several IČO in one call: `GET /rpo/v1/search?identifier={IČO}&identifier={IČO}…`

Repeat the `identifier` parameter (or use `identifier[]=`), up to 100 different IČO per call.
With two or more IČO, `results` is an object keyed by IČO in the order they were requested;
an IČO that was not found has an empty array. Duplicate IČO are returned once. A call with a
single IČO keeps the response shown above, so existing clients are not affected.

```json
{
  "results": {
    "31322832": [{ "id": 1003617, "identifier": "31322832", "fullName": "SLOVNAFT, a.s.", … }],
    "35880198": [{ "id": 566453, "identifier": "35880198", "fullName": "Numera Balance s. r. o.", … }],
    "99999998": []
  },
  "lastUpdate": "2026-11-12",
  "license": "1. ŠÚ SR ako správca…"
}
```

A batch call counts as one request in the token usage statistics.

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
| `vatId` | IČ DPH if the IČO is in the list of registered VAT payers, otherwise `null`; a member of a VAT group gets the group's IČ DPH |
| `lastUpdate` | export date of the last imported batch, i.e. the day the data is from |
| `license` | license terms of the Statistical Office of the SR (CC BY 4.0), which every response must include |

For a terminated entity, `fullName` and `address` are `null`, because it no longer has a current
name or address. If RPO does not state a source register, `sourceRegister` is `null`.

### IČ DPH validity: `GET /rpo/v1/vat?vatId={IČ DPH}`

Checks whether the IČ DPH is in the list of registered VAT payers of the Financial Administration
of the SR. Spaces and letter case do not matter, and ten digits without a prefix get `SK`
(`sk 1020 000 135` → `SK1020000135`). A value that is not `SK` + 10 digits is answered with
`valid: false`.

Instead of `vatId` you can send `identifier={IČO}` to get the IČ DPH of a company. Both parameters
can be repeated (or sent as `vatId[]=` / `identifier[]=`), up to 100 different values per call,
but not combined in one call. `results` always has one item per distinct requested value, in
request order, and a call counts as one request in the token usage statistics.

```json
{
  "results": [
    { "vatId": "SK1020000135", "valid": true, "identifier": "36151475", "fullName": "JUDr. Beáta Krausová" },
    { "vatId": "SK1234567890", "valid": false, "identifier": null, "fullName": null }
  ],
  "lastUpdate": "2026-11-12",
  "source": "Finančná správa SR – Zoznam registrovaných platiteľov DPH"
}
```

| Field | Meaning |
| --- | --- |
| `vatId` | IČ DPH (normalized); with `identifier=` the IČ DPH found for the IČO, or `null` |
| `valid` | `true` if the IČ DPH is in the list of registered VAT payers |
| `identifier` | IČO of the payer (`null` for payers without IČO and for a VAT group itself) |
| `fullName` | name of the payer as stated in the list; for a VAT group `Skupina DPH zastupovaná: …` |
| `lastUpdate` | date of the imported VAT payer list |

A VAT group (§4b) has one IČ DPH shared by all its members. `vatId=` returns the group itself,
`identifier=` returns the member with the group's IČ DPH.

### Error responses

| Situation | HTTP | `message` |
| --- | --- | --- |
| missing, invalid or revoked token | 401 | `Unauthorized` |
| `/search` without the `identifier` parameter, `/vat` without `vatId` or `identifier` | 400 | `No parameters specified in search request` |
| empty `identifier=` or `vatId=` parameter (also one of several) | 400 | `Request not understood` |
| more than 100 different values, or a URL longer than 4096 characters | 400 | `Invalid input parameter` |
| `/vat` with both `vatId` and `identifier` | 400 | `Invalid input parameter` |
| non-numeric `id` | 400 | `Invalid input parameter` |
| non-existent `id` | 404 | `No record found` |
| unknown path | 404 | `Page Not Found` |

## Installation

**Requirements:** PHP 8.4+ (extensions `pdo_mysql`, `zlib`, `curl`, `simplexml`, `xmlreader`, `zip`), Composer,
MariaDB 10.11+, Apache with `mod_rewrite` and `AllowOverride All`. The first import downloads
about 1 GB of data.

```bash
composer install --no-dev
cp config.php.dist config.php        # fill in the database access
php bin/rpo rpo:schema               # create the tables
php bin/rpo rpo:sync -v              # first import: full batch + daily batches
php bin/rpo rpo:vat:sync -v          # list of registered VAT payers
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
| `vat_url` | URL of the list of registered VAT payers (default `https://report.financnasprava.sk/ds_dphs.zip`) |

The config file path can be changed with the `RPO_CONFIG` environment variable.

### Daily update (cron)

Daily batches show up in the storage around 03:00 UTC. The full batch is created once a month
(on the first Saturday) and uploaded on Sunday evening. `rpo:sync` works out on its own what needs
to be imported, so it is enough to run it once a day:

```cron
30 6 * * * cd /var/www/rpo && /usr/bin/php bin/rpo rpo:sync >> var/sync.log 2>&1
0 3 * * * cd /var/www/rpo && /usr/bin/php bin/rpo rpo:vat:sync >> var/vat-sync.log 2>&1
```

The VAT payer list is published every night around 01:40; `rpo:vat:sync` skips a list date that
is already imported.

## Commands

| Command | Description |
| --- | --- |
| `rpo:sync` | imports a new full batch (if available) and all daily batches not yet applied |
| `rpo:sync --init` | forces a reload of the latest full batch |
| `rpo:sync --file=batch-daily/actual_2026-10-08.json.gz` | imports a single daily file |
| `rpo:sync --dry-run` | only prints what would be imported |
| `rpo:vat:sync` | imports the list of registered VAT payers if a newer one is published |
| `rpo:vat:sync --force` | imports the list even if its date is already imported |
| `rpo:vat:sync --dry-run` | only prints whether the list would be imported |
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

The list of registered VAT payers (*Zoznam registrovaných platiteľov DPH*) is open data of the
Financial Administration of the Slovak Republic
([open data](https://www.financnasprava.sk/sk/elektronicke-sluzby/verejne-sluzby/zoznamy)).
`/vat` responses name it in the `source` field.
