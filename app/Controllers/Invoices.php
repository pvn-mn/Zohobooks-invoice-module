<?php

namespace App\Controllers;

use App\Libraries\InvoiceService;
use App\Models\InvoiceModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Invoice;

/**
 * Invoices: list, create, edit, send to Zoho Books, and print the Sri Lankan IRD tax invoice.
 *
 *   GET  invoices               invoice list + new invoice form
 *   GET  invoices/{id}/edit     edit an invoice
 *   GET  invoices/{id}          A4 tax invoice with Print button
 *   POST invoices               Save & Send (new or edit, by the posted id)
 *   POST invoices/{id}/resync   re-send a failed invoice to Zoho
 */
class Invoices extends BaseController
{
    public function index(): string
    {
        $today = date('Y-m-d');

        return $this->renderForm([
            'id' => 0, 'customer_id' => '', 'customer_label' => '', 'invoice_date' => $today, 'supply_date' => $today,
            'supply_place' => config(Invoice::class)->supplyPlaceDefault, 'po_number' => '', 'our_ref' => '', 'lines' => [],
        ]);
    }

    public function edit(int $id): string
    {
        $editing = $this->findOr404($id);

        return $this->renderForm([
            'id'             => (int) $editing['id'],
            'customer_id'    => $editing['customer_id'],
            'customer_label' => $editing['customer_name'] . ($editing['customer_tin'] ? ' — TIN ' . $editing['customer_tin'] : ''),
            'customer'       => [
                'name'    => $editing['customer_name'],
                'tin'     => $editing['customer_tin'],
                'phone'   => $editing['customer_phone'],
                'address' => $editing['customer_address'],
            ],
            'invoice_date' => $editing['invoice_date'],
            'supply_date'  => (string) $editing['supply_date'],
            'supply_place' => $editing['supply_place'],
            'po_number'    => $editing['po_number'],
            'our_ref'      => $editing['our_ref'],
            'lines'        => array_map(static fn ($l) => [
                'item_id'     => $l['item_id'], 'item_name' => $l['item_name'], 'reference' => $l['reference'],
                'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
            ], $editing['lines']),
        ], $editing);
    }

    public function show(int $id): string
    {
        $inv      = $this->findOr404($id);
        $mismatch = $inv['sync_status'] === 'synced' && $inv['zoho_total'] !== null
            && abs((float) $inv['zoho_total'] - (float) $inv['total']) > 0.01;

        return view('invoices/show', [
            'inv'      => $inv,
            'mismatch' => $mismatch,
            'sent'     => $this->request->getGet('sent') !== null,
            'config'   => config(Invoice::class),
        ]);
    }

    public function save(): RedirectResponse|string
    {
        $posted = (array) $this->request->getPost();
        $service = new InvoiceService();

        [$id, $error] = $service->saveFromPost($posted);
        if (! $error) {
            $ok = $service->syncToZoho($id);

            return redirect()->to(site_url('invoices/' . $id) . ($ok ? '?sent=1' : ''));
        }

        // redisplay the form with what the user typed
        $form = [
            'id'             => (int) ($posted['id'] ?? 0),
            'customer_id'    => (string) ($posted['customer_id'] ?? ''),
            'customer_label' => (string) ($posted['customer_label'] ?? ''),
            'invoice_date'   => (string) ($posted['invoice_date'] ?? ''),
            'supply_date'    => (string) ($posted['supply_date'] ?? ''),
            'supply_place'   => (string) ($posted['supply_place'] ?? ''),
            'po_number'      => (string) ($posted['po_number'] ?? ''),
            'our_ref'        => (string) ($posted['our_ref'] ?? ''),
            'lines'          => array_values(array_map(static fn ($l) => [
                'item_id' => (string) ($l['item_id'] ?? ''), 'item_name' => (string) ($l['item_label'] ?? ''),
                'reference' => (string) ($l['reference'] ?? ''), 'description' => (string) ($l['description'] ?? ''),
                'quantity' => (string) ($l['quantity'] ?? ''), 'unit_price' => (string) ($l['unit_price'] ?? ''),
            ], (array) ($posted['lines'] ?? []))),
        ];
        $editing = $form['id'] ? model(InvoiceModel::class)->findWithLines($form['id']) : null;

        return $this->renderForm($form, $editing, $error);
    }

    public function resync(int $id): RedirectResponse
    {
        $ok = model(InvoiceModel::class)->findWithLines($id) && (new InvoiceService())->syncToZoho($id);

        return redirect()->to(site_url('invoices/' . $id) . ($ok ? '?sent=1' : ''));
    }

    private function findOr404(int $id): array
    {
        $inv = model(InvoiceModel::class)->findWithLines($id);
        if (! $inv) {
            throw PageNotFoundException::forPageNotFound('Invoice not found.');
        }

        return $inv;
    }

    private function renderForm(array $form, ?array $editing = null, string $error = ''): string
    {
        $rows = model(InvoiceModel::class)
            ->select('id, invoice_number, customer_name, invoice_date, total, sync_status, zoho_invoice_number')
            ->orderBy('id', 'DESC')
            ->findAll();

        return view('invoices/index', [
            'title'   => 'Invoices',
            'active'  => 'invoices',
            'form'    => $form,
            'editing' => $editing,
            'error'   => $error,
            'rows'    => $rows,
            'vatRate' => config(Invoice::class)->vatRate,
        ]);
    }
}
