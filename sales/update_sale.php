
<?php
// ============================================================
// FILE: sales/update_sale.php
// edit_sale.php ka backend (POST only)
// - Customer validation
// - Purana stock wapas, naya stock minus (stock kam ho to error)
// - Multiple print items (charge per piece x qty)
// - Paid amount yahan change nahi hota (payments page se hota hai)
// - Linked print service job sync (pending ho tabhi)
// ============================================================

require_once '../config/config.php';
require_permission('sales');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('sales_list.php');
}

$sale_id = (int)($_POST['sale_id'] ?? 0);

if ($sale_id <= 0) {
    set_flash('error', 'Invalid sale ID.');
    redirect('sales_list.php');
}

function back_with_error($sale_id, $msg) {
    $_SESSION['error'] = $msg;
    redirect('edit_sale.php?id=' . $sale_id);
}

// ============ FETCH SALE (+ linked service status) ============

$stmt = $conn->prepare("
    SELECT s.*, sj.status AS service_status
    FROM sales s
    LEFT JOIN services sj ON s.service_job_id = sj.id
    WHERE s.id = ?
");

$stmt->bind_param('i', $sale_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sale) {
    set_flash('error', 'Sale not found.');
    redirect('sales_list.php');
}

if ($sale['status'] === 'cancelled') {
    back_with_error($sale_id, 'Cancelled sale edit nahi ho sakti.');
}

// Service shuru/complete ho gayi ho toh edit block
if (
    !empty($sale['service_job_id']) &&
    !empty($sale['service_status']) &&
    $sale['service_status'] !== 'pending'
) {
    back_with_error(
        $sale_id,
        "Is sale ki print service ab '{$sale['service_status']}' hai, isliye sale edit nahi ho sakti."
    );
}

// ============ INPUTS ============

$client_id   = (int)($_POST['client_id'] ?? 0);
$sale_date   = trim($_POST['sale_date'] ?? date('Y-m-d'));
$gst_percent = (float)($_POST['gst_percent'] ?? 0);
$items_in    = $_POST['items'] ?? [];

// Validate selected customer
if ($client_id > 0) {
    $customerStmt = $conn->prepare("
        SELECT id
        FROM customers
        WHERE id = ? AND is_active = 1
        LIMIT 1
    ");

    $customerStmt->bind_param('i', $client_id);
    $customerStmt->execute();

    $customer = $customerStmt->get_result()->fetch_assoc();
    $customerStmt->close();

    if (!$customer) {
        back_with_error($sale_id, 'Selected customer valid nahi hai.');
    }
}

if (empty($items_in) || !is_array($items_in)) {
    back_with_error($sale_id, 'Kam se kam ek product select karein.');
}

// Product names (service notes ke liye)
$product_names = [];

$resP = $conn->query("
    SELECT id, product_name
    FROM products
");

if ($resP) {
    foreach ($resP->fetch_all(MYSQLI_ASSOC) as $p) {
        $product_names[(int)$p['id']] = $p['product_name'];
    }
}

// ============ PARSE ITEMS + PRINTS ============

$product_total = 0;
$print_total   = 0;
$items_to_save = [];

foreach ($items_in as $it) {

    $p_id = (int)($it['product_id'] ?? 0);
    $qty  = (int)($it['qty'] ?? 0);
    $rate = (float)($it['rate'] ?? 0);

    if ($p_id <= 0 || $qty <= 0 || $rate <= 0) {
        continue;
    }

    $print_charge = 0;
    $type_parts   = [];
    $note_lines   = [];

    $prints_in = (
        isset($it['prints']) && is_array($it['prints'])
    ) ? $it['prints'] : [];

    foreach ($prints_in as $pr) {

        $pos    = trim($pr['position'] ?? '');
        $type   = trim($pr['type'] ?? '');
        $charge = (float)($pr['charge'] ?? 0);
        $note   = trim($pr['note'] ?? '');

        if (
            $pos === '' &&
            $type === '' &&
            $charge <= 0 &&
            $note === ''
        ) {
            continue;
        }

        // Charge per piece -> total = charge x qty
        $print_charge += $charge * $qty;

        $label = trim(
            $pos . ($type !== '' ? ': ' . $type : ''),
            ': '
        );

        $type_parts[] = $label;

        $line = $label .
            ' (Rs.' . round($charge) .
            '/pc x ' . $qty .
            ' = Rs.' . round($charge * $qty) . ')';

        if ($note !== '') {
            $line .= ' - ' . $note;
        }

        $note_lines[] = $line;
    }

    $amount = $qty * $rate;

    $product_total += $amount;
    $print_total   += $print_charge;

    $items_to_save[] = [
        'product_id'   => $p_id,
        'product_name' => $product_names[$p_id] ?? ('Product #' . $p_id),
        'qty'          => $qty,
        'rate'         => $rate,
        'amount'       => $amount,
        'print_charge' => $print_charge,
        'print_type'   => implode(', ', $type_parts),
        'print_notes'  => implode("\n", $note_lines)
    ];
}

if (empty($items_to_save)) {
    back_with_error($sale_id, 'Sahi product, qty aur rate bharein.');
}

// ============ TOTALS ============

$sub_total   = $product_total + $print_total;
$gst_amount  = round(($sub_total * $gst_percent) / 100);
$grand_total = $sub_total + $gst_amount;

$paid_amount = (float)$sale['paid_amount'];

if ($grand_total < $paid_amount) {
    back_with_error(
        $sale_id,
        'Grand Total (₹' . $grand_total .
        ') pehle se mile paid amount (₹' . $paid_amount .
        ') se kam nahi ho sakta.'
    );
}

$due_amount = $grand_total - $paid_amount;
$status     = ($due_amount <= 0) ? 'completed' : 'pending';

// ============ SAVE (transaction) ============

$conn->begin_transaction();

try {

    // 1. Purana stock wapas
    $oldStmt = $conn->prepare("
        SELECT product_id, qty
        FROM sale_items
        WHERE sale_id = ?
    ");

    $oldStmt->bind_param('i', $sale_id);
    $oldStmt->execute();

    $old_items = $oldStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $oldStmt->close();

    $restore = $conn->prepare("
        UPDATE products
        SET stock_qty = stock_qty + ?
        WHERE id = ?
    ");

    foreach ($old_items as $o) {
        $restore->bind_param('ii', $o['qty'], $o['product_id']);
        $restore->execute();
    }

    $restore->close();

    // 2. Purane items hatao, naye daalo + stock minus
    $del = $conn->prepare("
        DELETE FROM sale_items
        WHERE sale_id = ?
    ");

    $del->bind_param('i', $sale_id);
    $del->execute();
    $del->close();

    $itemStmt = $conn->prepare("
        INSERT INTO sale_items
        (
            sale_id,
            product_id,
            qty,
            rate,
            amount,
            print_charge_total,
            print_type,
            print_notes
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stockStmt = $conn->prepare("
        UPDATE products
        SET stock_qty = stock_qty - ?
        WHERE id = ? AND stock_qty >= ?
    ");

    foreach ($items_to_save as $item) {

        $stockStmt->bind_param(
            'iii',
            $item['qty'],
            $item['product_id'],
            $item['qty']
        );

        $stockStmt->execute();

        if ($stockStmt->affected_rows < 1) {
            throw new Exception(
                "'{$item['product_name']}' ka stock kam hai (maang: {$item['qty']})."
            );
        }

        $itemStmt->bind_param(
            'iiidddss',
            $sale_id,
            $item['product_id'],
            $item['qty'],
            $item['rate'],
            $item['amount'],
            $item['print_charge'],
            $item['print_type'],
            $item['print_notes']
        );

        $itemStmt->execute();
    }

    $itemStmt->close();
    $stockStmt->close();

    // 3. Sale update
    // Existing database FK column client_id same rakha hai
    $upd = $conn->prepare("
        UPDATE sales
        SET
            client_id = ?,
            product_total = ?,
            print_total = ?,
            sub_total = ?,
            gst_percent = ?,
            gst_amount = ?,
            grand_total = ?,
            status = ?,
            due_amount = ?,
            sale_date = ?
        WHERE id = ?
    ");

    $upd->bind_param(
        'iddddddsdsi',
        $client_id,
        $product_total,
        $print_total,
        $sub_total,
        $gst_percent,
        $gst_amount,
        $grand_total,
        $status,
        $due_amount,
        $sale_date,
        $sale_id
    );

    $upd->execute();
    $upd->close();

    // Customer badla ho toh is sale ke payments ka client bhi badlo
    $updPay = $conn->prepare("
        UPDATE payments
        SET client_id = ?
        WHERE ref_type = 'sale' AND ref_id = ?
    ");

    $updPay->bind_param('ii', $client_id, $sale_id);
    $updPay->execute();
    $updPay->close();

    // 4. Linked print service sync
    $svc_id = (int)($sale['service_job_id'] ?? 0);

    if ($print_total > 0 && $client_id > 0) {

        $combined_notes = "Auto-generated from Sale: {$sale['sale_no']}\n\nPrint Details:\n";

        foreach ($items_to_save as $item) {

            if ($item['print_charge'] > 0 || $item['print_notes'] !== '') {

                $combined_notes .= "\n# {$item['product_name']} (Qty: {$item['qty']})\n";

                foreach (explode("\n", $item['print_notes']) as $ln) {
                    if (trim($ln) !== '') {
                        $combined_notes .= "  - $ln\n";
                    }
                }
            }
        }

        if ($svc_id > 0) {

            $srvUpd = $conn->prepare("
                UPDATE services
                SET
                    client_id = ?,
                    print_charge_per_pc = ?,
                    total_amount = ?,
                    notes = ?,
                    service_date = ?
                WHERE id = ?
            ");

            $srvUpd->bind_param(
                'iddssi',
                $client_id,
                $print_total,
                $print_total,
                $combined_notes,
                $sale_date,
                $svc_id
            );

            $srvUpd->execute();
            $srvUpd->close();

        } else {

            $service_no = generate_no(
                $conn,
                'services',
                'service_no',
                'SRV'
            );

            $srvStmt = $conn->prepare("
                INSERT INTO services
                (
                    service_no,
                    client_id,
                    sale_id,
                    service_type,
                    qty,
                    print_charge_per_pc,
                    total_amount,
                    notes,
                    status,
                    service_date
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ");

            $srv_qty  = 1;
            $srv_type = 'custom_print';

            $srvStmt->bind_param(
                'siisiddss',
                $service_no,
                $client_id,
                $sale_id,
                $srv_type,
                $srv_qty,
                $print_total,
                $print_total,
                $combined_notes,
                $sale_date
            );

            $srvStmt->execute();

            $new_svc = $srvStmt->insert_id;
            $srvStmt->close();

            $link = $conn->prepare("
                UPDATE sales
                SET service_job_id = ?
                WHERE id = ?
            ");

            $link->bind_param('ii', $new_svc, $sale_id);
            $link->execute();
            $link->close();
        }

    } elseif ($svc_id > 0) {

        // Ab print nahi hai (ya walk-in ho gaya) -> pending service hata do
        $unlink = $conn->prepare("
            UPDATE sales
            SET service_job_id = NULL
            WHERE id = ?
        ");

        $unlink->bind_param('i', $sale_id);
        $unlink->execute();
        $unlink->close();

        $svcPay = $conn->prepare("
            DELETE FROM payments
            WHERE ref_type = 'service' AND ref_id = ?
        ");

        $svcPay->bind_param('i', $svc_id);
        $svcPay->execute();
        $svcPay->close();

        $svcDel = $conn->prepare("
            DELETE FROM services
            WHERE id = ?
        ");

        $svcDel->bind_param('i', $svc_id);
        $svcDel->execute();
        $svcDel->close();
    }

    $conn->commit();

    set_flash(
        'success',
        "Sale ({$sale['sale_no']}) update ho gayi. Grand Total: ₹$grand_total, Due: ₹$due_amount"
    );

    redirect('view_sale_invoice.php?id=' . $sale_id);

} catch (Exception $e) {

    $conn->rollback();

    back_with_error(
        $sale_id,
        'Update error: ' . $e->getMessage()
    );
}
