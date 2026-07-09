<?php
/**
 * Contract endpoints
 * GET  /api/contracts                       -> list all contracts
 * POST /api/contracts                       -> create an offer
 * GET  /api/contracts/{id}                  -> single contract with operators, rate groups, attachments
 * POST /api/contracts/{id}/operators        -> add an operator mapping
 * POST /api/contracts/{id}/rate-groups      -> add a rate group + rate rows
 * POST /api/contracts/{id}/finalize         -> finalize (set start/end date, convert to contract)
 * POST /api/contracts/{id}/attachments      -> attach a file (multipart/form-data)
 */

/**
 * Generates the next contract number in the ARYACONT01, ARYACONT02, ... series.
 * Looks at the highest existing numeric suffix rather than a row count, so
 * gaps from any future deletions don't cause a collision or reuse a number.
 */
function generateContractNumber(PDO $pdo): string
{
    $stmt = $pdo->query(
        "SELECT contract_number FROM contracts
         WHERE contract_number REGEXP '^ARYACONT[0-9]+$'
         ORDER BY CAST(SUBSTRING(contract_number, 9) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $last = $stmt->fetchColumn();
    $nextNum = $last ? ((int)substr($last, 8) + 1) : 1;

    return 'ARYACONT' . str_pad((string)$nextNum, 2, '0', STR_PAD_LEFT);
}

function handleContractsList(PDO $pdo): void
{
    $rows = $pdo->query(
        "SELECT ct.*, c.customer_name, c.customer_code
         FROM contracts ct JOIN customers c ON c.id = ct.customer_id
         ORDER BY ct.id DESC"
    )->fetchAll();
    jsonSuccess($rows);
}

function handleContractCreate(PDO $pdo, int $apiKeyId): void
{
    $body = getJsonBody();
    requireFields($body, ['customer_id', 'effective_date', 'contract_type']);

    ensureCustomerExists($pdo, (int)$body['customer_id']);

    $pdo->beginTransaction();
    $contractNumber = generateContractNumber($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO contracts
            (contract_number, customer_id, agency_coordinator, effective_date, contract_type, bid_ref_no, bid_ref_date,
             bid_last_submission_date, bid_open_date, short_contract_no, remarks, invoicing_to_different_principal, currency)
         VALUES
            (:cnum, :cid, :agency, :eff, :ctype, :bid_ref, :bid_ref_date, :bid_last, :bid_open, :short_no, :remarks, :invoicing, :currency)"
    );
    $stmt->execute([
        'cnum' => $contractNumber,
        'cid' => (int)$body['customer_id'],
        'agency' => $body['agency_coordinator'] ?? null,
        'eff' => $body['effective_date'],
        'ctype' => $body['contract_type'],
        'bid_ref' => $body['bid_ref_no'] ?? null,
        'bid_ref_date' => $body['bid_ref_date'] ?? null,
        'bid_last' => $body['bid_last_submission_date'] ?? null,
        'bid_open' => $body['bid_open_date'] ?? null,
        'short_no' => $body['short_contract_no'] ?? null,
        'remarks' => $body['remarks'] ?? null,
        'invoicing' => $body['invoicing_to_different_principal'] ?? 'no',
        'currency' => $body['currency'] ?? 'INR',
    ]);
    $contractId = (int)$pdo->lastInsertId();
    $pdo->commit();

    logApiAction($apiKeyId, 'api.contract.create', "Created offer #$contractId ($contractNumber)");

    jsonSuccess(fetchFullContract($pdo, $contractId), 201);
}

function handleContractGet(PDO $pdo, int $id): void
{
    $contract = fetchFullContract($pdo, $id);
    if (!$contract) {
        jsonError('Contract not found.', 404);
    }
    jsonSuccess($contract);
}

function fetchFullContract(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT ct.*, c.customer_name, c.customer_code FROM contracts ct
         JOIN customers c ON c.id = ct.customer_id WHERE ct.id = :id"
    );
    $stmt->execute(['id' => $id]);
    $contract = $stmt->fetch();
    if (!$contract) {
        return null;
    }

    $ops = $pdo->prepare(
        "SELECT co.*, cb.address_code FROM contract_operators co
         LEFT JOIN customer_billing_addresses cb ON cb.id = co.billing_address_id
         WHERE co.contract_id = :id"
    );
    $ops->execute(['id' => $id]);
    $contract['operators'] = $ops->fetchAll();

    $groups = $pdo->prepare('SELECT * FROM contract_rate_groups WHERE contract_id = :id ORDER BY id');
    $groups->execute(['id' => $id]);
    $rateGroups = $groups->fetchAll();

    foreach ($rateGroups as &$rg) {
        $items = $pdo->prepare('SELECT * FROM contract_rate_items WHERE rate_group_id = :id');
        $items->execute(['id' => $rg['id']]);
        $rg['items'] = $items->fetchAll();
    }
    unset($rg);
    $contract['rate_groups'] = $rateGroups;

    $att = $pdo->prepare('SELECT * FROM contract_attachments WHERE contract_id = :id ORDER BY id DESC');
    $att->execute(['id' => $id]);
    $contract['attachments'] = $att->fetchAll();

    return $contract;
}

function ensureContractExists(PDO $pdo, int $contractId): array
{
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id = :id');
    $stmt->execute(['id' => $contractId]);
    $contract = $stmt->fetch();
    if (!$contract) {
        jsonError('Contract not found.', 404);
    }
    return $contract;
}

function handleContractOperatorCreate(PDO $pdo, int $apiKeyId, int $contractId): void
{
    ensureContractExists($pdo, $contractId);

    $body = getJsonBody();
    requireFields($body, ['operator', 'contract_no', 'project_name', 'project_abbreviation']);

    $stmt = $pdo->prepare(
        "INSERT INTO contract_operators (contract_id, operator, contract_no, project_name, project_abbreviation, billing_address_id)
         VALUES (:cid, :op, :no, :pname, :pabbr, :baddr)"
    );
    $stmt->execute([
        'cid' => $contractId, 'op' => $body['operator'], 'no' => $body['contract_no'],
        'pname' => $body['project_name'], 'pabbr' => $body['project_abbreviation'],
        'baddr' => $body['billing_address_id'] ?? null,
    ]);
    $opId = (int)$pdo->lastInsertId();
    logApiAction($apiKeyId, 'api.contract.operator_add', "Added operator #$opId to contract #$contractId");

    $stmt = $pdo->prepare('SELECT * FROM contract_operators WHERE id = :id');
    $stmt->execute(['id' => $opId]);
    jsonSuccess($stmt->fetch(), 201);
}

function handleContractRateGroupCreate(PDO $pdo, int $apiKeyId, int $contractId): void
{
    ensureContractExists($pdo, $contractId);

    $body = getJsonBody();
    requireFields($body, ['rate_for']);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO contract_rate_groups
                (contract_id, contract_clause_no, rate_for, effective_date, service_tax_applicable,
                 statutory_charges_applicable, estimated_quantity, disbursement_currency, remarks,
                 printing_remarks, printing_remarks2, chargeability_options)
             VALUES
                (:cid, :clause, :rate_for, :eff, :stax, :stat, :qty, :curr, :remarks, :pr1, :pr2, :chg)"
        );
        $stmt->execute([
            'cid' => $contractId, 'clause' => $body['contract_clause_no'] ?? null, 'rate_for' => $body['rate_for'],
            'eff' => $body['effective_date'] ?? null, 'stax' => $body['service_tax_applicable'] ?? 'yes',
            'stat' => $body['statutory_charges_applicable'] ?? 'yes', 'qty' => $body['estimated_quantity'] ?? null,
            'curr' => $body['disbursement_currency'] ?? 'INR', 'remarks' => $body['remarks'] ?? null,
            'pr1' => $body['printing_remarks'] ?? null, 'pr2' => $body['printing_remarks2'] ?? null,
            'chg' => $body['chargeability_options'] ?? null,
        ]);
        $rateGroupId = (int)$pdo->lastInsertId();

        // Rate rows: { "items": [ { location, per_unit, priority, mod_type, rate }, ... ] }
        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as $item) {
                requireFields($item, ['location', 'per_unit', 'rate']);
                $i = $pdo->prepare(
                    "INSERT INTO contract_rate_items (rate_group_id, location, per_unit, priority, mod_type, rate)
                     VALUES (:rg, :loc, :per, :prio, :mod, :rate)"
                );
                $i->execute([
                    'rg' => $rateGroupId, 'loc' => $item['location'], 'per' => $item['per_unit'],
                    'prio' => $item['priority'] ?? 'NORMAL', 'mod' => $item['mod_type'] ?? null, 'rate' => $item['rate'],
                ]);
            }
        }

        $pdo->commit();
        logApiAction($apiKeyId, 'api.contract.rate_add', "Added rate group #$rateGroupId to contract #$contractId");

        $stmt = $pdo->prepare('SELECT * FROM contract_rate_groups WHERE id = :id');
        $stmt->execute(['id' => $rateGroupId]);
        $group = $stmt->fetch();
        $items = $pdo->prepare('SELECT * FROM contract_rate_items WHERE rate_group_id = :id');
        $items->execute(['id' => $rateGroupId]);
        $group['items'] = $items->fetchAll();

        jsonSuccess($group, 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to create rate group: ' . $e->getMessage(), 500);
    }
}

function handleContractFinalize(PDO $pdo, int $apiKeyId, int $contractId): void
{
    $contract = ensureContractExists($pdo, $contractId);

    if ($contract['status'] === 'finalised') {
        jsonError('This contract has already been finalised.', 409);
    }

    $body = getJsonBody();
    requireFields($body, ['start_date', 'end_date']);

    $stmt = $pdo->prepare(
        "UPDATE contracts SET status = 'finalised', start_date = :start, end_date = :end WHERE id = :id"
    );
    $stmt->execute(['start' => $body['start_date'], 'end' => $body['end_date'], 'id' => $contractId]);
    logApiAction($apiKeyId, 'api.contract.finalize', "Finalised contract #$contractId");

    jsonSuccess(fetchFullContract($pdo, $contractId));
}

function handleContractAttachmentCreate(PDO $pdo, int $apiKeyId, int $contractId): void
{
    ensureContractExists($pdo, $contractId);

    if (empty($_FILES['attachment']['name'])) {
        jsonError('No file provided. Send multipart/form-data with a field named "attachment".', 422);
    }

    $uploadDir = __DIR__ . '/../../uploads/contracts/' . $contractId . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $safeName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES['attachment']['name']);
    $destPath = $uploadDir . $safeName;

    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $destPath)) {
        jsonError('File upload failed.', 500);
    }

    $relativePath = 'uploads/contracts/' . $contractId . '/' . $safeName;
    $description = $_POST['description'] ?? 'Signed Contract';

    $stmt = $pdo->prepare(
        'INSERT INTO contract_attachments (contract_id, description, file_path) VALUES (:cid, :desc, :path)'
    );
    $stmt->execute(['cid' => $contractId, 'desc' => $description, 'path' => $relativePath]);
    $attId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE contracts SET signed_contract_path = :path WHERE id = :id')
        ->execute(['path' => $relativePath, 'id' => $contractId]);

    logApiAction($apiKeyId, 'api.contract.attachment_upload', "Uploaded attachment #$attId to contract #$contractId");

    $stmt = $pdo->prepare('SELECT * FROM contract_attachments WHERE id = :id');
    $stmt->execute(['id' => $attId]);
    jsonSuccess($stmt->fetch(), 201);
}
