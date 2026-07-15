<?php
/**
 * Customer endpoints
 * GET    /api/customers              -> list all customers
 * POST   /api/customers               -> create a customer (+ optional addresses)
 * GET    /api/customers/{id}          -> single customer with addresses, contacts, billing addresses, contracts
 * GET    /api/customers/{id}/contacts -> list contacts for a customer
 * POST   /api/customers/{id}/contacts -> add a contact
 * GET    /api/customers/{id}/billing-addresses -> list billing addresses
 * POST   /api/customers/{id}/billing-addresses -> add a billing address
 */

function handleCustomersList(PDO $pdo): void
{
    $rows = $pdo->query(
        "SELECT c.*,
            (SELECT COUNT(*) FROM customer_contacts cc WHERE cc.customer_id = c.id) AS contact_count,
            (SELECT COUNT(*) FROM customer_billing_addresses cb WHERE cb.customer_id = c.id) AS billing_count,
            (SELECT COUNT(*) FROM contracts ct WHERE ct.customer_id = c.id) AS contract_count
         FROM customers c ORDER BY c.id DESC"
    )->fetchAll();
    jsonSuccess($rows);
}

function handleCustomerCreate(PDO $pdo, int $apiKeyId): void
{
    $body = getJsonBody();
    requireFields($body, ['customer_code', 'customer_abbreviation', 'customer_name']);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO customers
                (customer_code, customer_abbreviation, customer_name, company_type, turnover,
                 operating_since, reference_other_country, reference_india, pan_no,
                 description, service_required, info_source, crew_address)
             VALUES
                (:code, :abbr, :name, :ctype, :turnover, :op_since, :ref_other, :ref_india,
                 :pan, :desc, :service, :info, :crew)"
        );
        $stmt->execute([
            'code' => $body['customer_code'],
            'abbr' => $body['customer_abbreviation'],
            'name' => $body['customer_name'],
            'ctype' => $body['company_type'] ?? null,
            'turnover' => $body['turnover'] ?? null,
            'op_since' => $body['operating_since'] ?? null,
            'ref_other' => $body['reference_other_country'] ?? null,
            'ref_india' => $body['reference_india'] ?? null,
            'pan' => $body['pan_no'] ?? null,
            'desc' => $body['description'] ?? null,
            'service' => $body['service_required'] ?? null,
            'info' => $body['info_source'] ?? null,
            'crew' => $body['crew_address'] ?? null,
        ]);
        $customerId = (int)$pdo->lastInsertId();

        // Optional nested addresses: { "addresses": [ { office_type, address_line1, ... }, ... ] }
        if (!empty($body['addresses']) && is_array($body['addresses'])) {
            foreach ($body['addresses'] as $addr) {
                requireFields($addr, ['office_type', 'address_line1', 'country', 'state', 'city', 'zip_code']);
                $a = $pdo->prepare(
                    "INSERT INTO customer_addresses
                        (customer_id, office_type, address_line1, address_line2, address_line3,
                         country, state, city, zip_code, phone1, phone2, fax1, fax2, email, web_url, is_default_billing)
                     VALUES
                        (:cid, :otype, :l1, :l2, :l3, :country, :state, :city, :zip, :p1, :p2, :f1, :f2, :email, :web, :billing)"
                );
                $a->execute([
                    'cid' => $customerId, 'otype' => $addr['office_type'],
                    'l1' => $addr['address_line1'], 'l2' => $addr['address_line2'] ?? null, 'l3' => $addr['address_line3'] ?? null,
                    'country' => $addr['country'], 'state' => $addr['state'], 'city' => $addr['city'], 'zip' => $addr['zip_code'],
                    'p1' => $addr['phone1'] ?? null, 'p2' => $addr['phone2'] ?? null,
                    'f1' => $addr['fax1'] ?? null, 'f2' => $addr['fax2'] ?? null,
                    'email' => $addr['email'] ?? null, 'web' => $addr['web_url'] ?? null,
                    'billing' => !empty($addr['is_default_billing']) ? 1 : 0,
                ]);
            }
        }

        $pdo->commit();
        logApiAction($apiKeyId, 'api.customer.create', "Created customer #$customerId ({$body['customer_name']})");

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        jsonSuccess($stmt->fetch(), 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to create customer: ' . $e->getMessage(), 500);
    }
}

function handleCustomerGet(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        jsonError('Customer not found.', 404);
    }

    $addr = $pdo->prepare('SELECT * FROM customer_addresses WHERE customer_id = :id');
    $addr->execute(['id' => $id]);
    $customer['addresses'] = $addr->fetchAll();

    $contacts = $pdo->prepare('SELECT * FROM customer_contacts WHERE customer_id = :id ORDER BY id DESC');
    $contacts->execute(['id' => $id]);
    $customer['contacts'] = $contacts->fetchAll();

    $billing = $pdo->prepare('SELECT * FROM customer_billing_addresses WHERE customer_id = :id ORDER BY id DESC');
    $billing->execute(['id' => $id]);
    $customer['billing_addresses'] = $billing->fetchAll();

    $contracts = $pdo->prepare('SELECT * FROM contracts WHERE customer_id = :id ORDER BY id DESC');
    $contracts->execute(['id' => $id]);
    $customer['contracts'] = $contracts->fetchAll();

    jsonSuccess($customer);
}

function handleCustomerContactsList(PDO $pdo, int $customerId): void
{
    $stmt = $pdo->prepare('SELECT * FROM customer_contacts WHERE customer_id = :id ORDER BY id DESC');
    $stmt->execute(['id' => $customerId]);
    jsonSuccess($stmt->fetchAll());
}

function handleCustomerContactCreate(PDO $pdo, int $apiKeyId, int $customerId): void
{
    ensureCustomerExists($pdo, $customerId);

    $body = getJsonBody();
    requireFields($body, ['contact_name', 'designation', 'contact_type', 'address_line1', 'country', 'state', 'city', 'zip_code', 'email']);

    $stmt = $pdo->prepare(
        "INSERT INTO customer_contacts
            (customer_id, contact_name, nick_name, designation, contact_type,
             address_line1, address_line2, address_line3, country, state, city, zip_code,
             phone1, phone2, fax1, fax2, email, web_url)
         VALUES
            (:cid, :name, :nick, :desig, :ctype, :l1, :l2, :l3, :country, :state, :city, :zip,
             :p1, :p2, :f1, :f2, :email, :web)"
    );
    $stmt->execute([
        'cid' => $customerId, 'name' => $body['contact_name'], 'nick' => $body['nick_name'] ?? null,
        'desig' => $body['designation'], 'ctype' => $body['contact_type'],
        'l1' => $body['address_line1'], 'l2' => $body['address_line2'] ?? null, 'l3' => $body['address_line3'] ?? null,
        'country' => $body['country'], 'state' => $body['state'], 'city' => $body['city'], 'zip' => $body['zip_code'],
        'p1' => $body['phone1'] ?? null, 'p2' => $body['phone2'] ?? null,
        'f1' => $body['fax1'] ?? null, 'f2' => $body['fax2'] ?? null,
        'email' => $body['email'], 'web' => $body['web_url'] ?? null,
    ]);
    $contactId = (int)$pdo->lastInsertId();
    logApiAction($apiKeyId, 'api.customer.contact_add', "Added contact #$contactId to customer #$customerId");

    $stmt = $pdo->prepare('SELECT * FROM customer_contacts WHERE id = :id');
    $stmt->execute(['id' => $contactId]);
    jsonSuccess($stmt->fetch(), 201);
}

function handleCustomerBillingList(PDO $pdo, int $customerId): void
{
    $stmt = $pdo->prepare('SELECT * FROM customer_billing_addresses WHERE customer_id = :id ORDER BY id DESC');
    $stmt->execute(['id' => $customerId]);
    jsonSuccess($stmt->fetchAll());
}

function handleCustomerBillingCreate(PDO $pdo, int $apiKeyId, int $customerId): void
{
    ensureCustomerExists($pdo, $customerId);

    $body = getJsonBody();
    requireFields($body, ['address_code', 'address_line1', 'address_description', 'country', 'state', 'city', 'zip_code', 'email']);

    $stmt = $pdo->prepare(
        "INSERT INTO customer_billing_addresses
            (customer_id, address_code, address_line1, address_line2, address_line3, address_description,
             country, state, city, zip_code, phone1, phone2, fax1, fax2, email, web_url, gstin)
         VALUES
            (:cid, :code, :l1, :l2, :l3, :desc, :country, :state, :city, :zip, :p1, :p2, :f1, :f2, :email, :web, :gstin)"
    );
    $stmt->execute([
        'cid' => $customerId, 'code' => $body['address_code'],
        'l1' => $body['address_line1'], 'l2' => $body['address_line2'] ?? null, 'l3' => $body['address_line3'] ?? null,
        'desc' => $body['address_description'],
        'country' => $body['country'], 'state' => $body['state'], 'city' => $body['city'], 'zip' => $body['zip_code'],
        'p1' => $body['phone1'] ?? null, 'p2' => $body['phone2'] ?? null,
        'f1' => $body['fax1'] ?? null, 'f2' => $body['fax2'] ?? null,
        'email' => $body['email'], 'web' => $body['web_url'] ?? null, 'gstin' => $body['gstin'] ?? null,
    ]);
    $billingId = (int)$pdo->lastInsertId();
    logApiAction($apiKeyId, 'api.customer.billing_add', "Added billing address #$billingId to customer #$customerId");

    $stmt = $pdo->prepare('SELECT * FROM customer_billing_addresses WHERE id = :id');
    $stmt->execute(['id' => $billingId]);
    jsonSuccess($stmt->fetch(), 201);
}

function ensureCustomerExists(PDO $pdo, int $customerId): void
{
    $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
    $stmt->execute(['id' => $customerId]);
    if (!$stmt->fetch()) {
        jsonError('Customer not found.', 404);
    }
}
