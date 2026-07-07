# RBAC Console — REST API

JSON REST API over the same `aareyrgp_claude` schema used by the web app.
Auth is a bearer API key (separate from the web app's session login) —
suited for server-to-server or script access, not for a public frontend
unless you add a proper OAuth/session layer on top.

## Authentication

Every request (except `/health`) needs:

```
Authorization: Bearer <api_key>
```

An initial key was generated for you:

```
rbac_mtbZn1Ulh0ORPi3Uz5eTKa0OPVrT7VzwVsP321ajBf4
```

**This is shown once and cannot be recovered — only its SHA-256 hash is stored in `api_keys`.**
If you lose it, generate a new one:

```bash
python3 -c "
import secrets, hashlib
key = 'rbac_' + secrets.token_urlsafe(32)
print('KEY:', key)
print('HASH:', hashlib.sha256(key.encode()).hexdigest())
"
```

Then insert the hash:

```sql
INSERT INTO api_keys (name, key_hash, key_prefix) VALUES ('My Key', '<HASH>', '<first 12 chars of KEY>');
```

To revoke a key: `UPDATE api_keys SET status = 'revoked' WHERE id = ...;`

## Base URL

```
https://your-domain.com/rbac-app/api/
```

(Adjust to wherever `rbac-app/` is deployed. Requires `mod_rewrite` enabled for the `.htaccess` in this folder to work — most shared hosts, including webhostbox-style cPanel hosting, have this on by default.)

## Endpoints

| Method | Path | Description |
|---|---|---|
| GET  | `/health` | No auth. Liveness check. |
| GET  | `/customers` | List all customers with contact/billing/contract counts |
| POST | `/customers` | Create a customer (+ optional nested `addresses[]`) |
| GET  | `/customers/{id}` | Full customer detail: addresses, contacts, billing addresses, contracts |
| GET  | `/customers/{id}/contacts` | List contacts for a customer |
| POST | `/customers/{id}/contacts` | Add a contact person |
| GET  | `/customers/{id}/billing-addresses` | List billing addresses |
| POST | `/customers/{id}/billing-addresses` | Add a billing address |
| GET  | `/contracts` | List all offers/contracts |
| POST | `/contracts` | Create a new offer |
| GET  | `/contracts/{id}` | Full contract detail: operators, rate groups + items, attachments |
| POST | `/contracts/{id}/operators` | Add an operator/contract mapping |
| POST | `/contracts/{id}/rate-groups` | Add a rate group (+ nested `items[]`) |
| POST | `/contracts/{id}/finalize` | Set start/end date, convert offer to contract |
| POST | `/contracts/{id}/attachments` | Upload a file (multipart/form-data, field name `attachment`) |

## Examples

### Create a customer

```bash
curl -X POST https://your-domain.com/rbac-app/api/customers \
  -H "Authorization: Bearer rbac_..." \
  -H "Content-Type: application/json" \
  -d '{
    "customer_code": "CUST-100",
    "customer_abbreviation": "ACME",
    "customer_name": "Acme Shipping Pvt Ltd",
    "company_type": "Principal",
    "pan_no": "ABCDE1234F",
    "addresses": [
      {
        "office_type": "corporate",
        "address_line1": "123 Marine Drive",
        "country": "India",
        "state": "Maharashtra",
        "city": "Mumbai",
        "zip_code": "400001",
        "email": "info@acme.com",
        "is_default_billing": true
      }
    ]
  }'
```

### Add a contact person

```bash
curl -X POST https://your-domain.com/rbac-app/api/customers/1/contacts \
  -H "Authorization: Bearer rbac_..." \
  -H "Content-Type: application/json" \
  -d '{
    "contact_name": "Priya Shah",
    "designation": "Finance Manager",
    "contact_type": "Billing",
    "address_line1": "123 Marine Drive",
    "country": "India", "state": "Maharashtra", "city": "Mumbai", "zip_code": "400001",
    "email": "priya@acme.com"
  }'
```

### Create an offer, add a rate group, finalize

```bash
# 1. Create offer
curl -X POST https://your-domain.com/rbac-app/api/contracts \
  -H "Authorization: Bearer rbac_..." -H "Content-Type: application/json" \
  -d '{"customer_id": 1, "effective_date": "2026-07-01", "contract_type": "Contract With Rate"}'

# 2. Add rate group + rows (assume contract id = 5)
curl -X POST https://your-domain.com/rbac-app/api/contracts/5/rate-groups \
  -H "Authorization: Bearer rbac_..." -H "Content-Type: application/json" \
  -d '{
    "rate_for": "Rate For MOD Clearance",
    "remarks": "GST will be payable at actuals",
    "items": [
      {"location": "MUMBAI", "per_unit": "PER VESSEL", "priority": "NORMAL", "mod_type": "FRESH", "rate": 6000},
      {"location": "MUMBAI", "per_unit": "PER VESSEL", "priority": "NORMAL", "mod_type": "EXTENSION", "rate": 6000}
    ]
  }'

# 3. Upload signed contract
curl -X POST https://your-domain.com/rbac-app/api/contracts/5/attachments \
  -H "Authorization: Bearer rbac_..." \
  -F "attachment=@/path/to/signed.pdf" \
  -F "description=Signed Contract"

# 4. Finalize
curl -X POST https://your-domain.com/rbac-app/api/contracts/5/finalize \
  -H "Authorization: Bearer rbac_..." -H "Content-Type: application/json" \
  -d '{"start_date": "2026-07-01", "end_date": "2027-06-30"}'
```

## Response shape

Success:
```json
{ "success": true, "data": { ... } }
```

Error:
```json
{ "success": false, "error": "Missing required field(s): customer_name" }
```

HTTP status codes: `200` OK, `201` Created, `400` bad request, `401` unauthorized,
`404` not found, `409` conflict (e.g. finalizing an already-finalised contract),
`422` validation error, `500` server error.

## Security notes

- Every write action is logged to `audit_logs` with `[api_key #N]` prefixed to the details field — same audit trail as the web app.
- API keys are stored as SHA-256 hashes only; there's no way to recover a lost plaintext key from the database.
- This API currently has **no rate limiting or per-key scoping** (all active keys have full read/write access to all endpoints). If you expose this beyond trusted internal use, add both.
- CORS is currently wide open (`Access-Control-Allow-Origin: *`) for ease of testing — tighten this to your actual frontend's origin before any production/public use.
