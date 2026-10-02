<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The invoice module's tables (zb_ prefix), same definitions as the old
 * bootstrap.php. IF NOT EXISTS, so a database that already has them is untouched.
 * Zoho IDs are 19 digits, so they are stored as VARCHAR, not INT.
 */
class CreateZbTables extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS zb_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(40) NOT NULL UNIQUE,
            customer_id VARCHAR(32) NOT NULL,
            customer_name VARCHAR(200) NOT NULL,
            customer_tin VARCHAR(40) NOT NULL DEFAULT '',
            customer_address VARCHAR(500) NOT NULL DEFAULT '',
            customer_phone VARCHAR(60) NOT NULL DEFAULT '',
            invoice_date DATE NOT NULL,
            supply_date DATE NULL,
            supply_place VARCHAR(120) NOT NULL DEFAULT '',
            po_number VARCHAR(100) NOT NULL DEFAULT '',
            our_ref VARCHAR(100) NOT NULL DEFAULT '',
            exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
            sub_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            total DECIMAL(14,2) NOT NULL DEFAULT 0,
            zoho_invoice_id VARCHAR(32) NULL,
            zoho_invoice_number VARCHAR(40) NULL,
            zoho_total DECIMAL(14,2) NULL,
            sync_status VARCHAR(10) NOT NULL DEFAULT 'pending',
            sync_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS zb_invoice_lines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            line_no INT NOT NULL,
            item_id VARCHAR(32) NOT NULL,
            item_name VARCHAR(255) NOT NULL DEFAULT '',
            reference VARCHAR(100) NOT NULL DEFAULT '',
            description VARCHAR(500) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit_price DECIMAL(14,2) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            KEY (invoice_id)
        ) DEFAULT CHARSET=utf8mb4");

        $this->db->query('CREATE TABLE IF NOT EXISTS zb_cache (
            cache_key VARCHAR(100) PRIMARY KEY,
            payload LONGTEXT NOT NULL,
            fetched_at INT NOT NULL
        ) DEFAULT CHARSET=utf8mb4');

        $this->db->query('CREATE TABLE IF NOT EXISTS zb_counters (
            name VARCHAR(40) PRIMARY KEY,
            value INT NOT NULL
        ) DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        // Deliberately empty: these tables may pre-date this migration and hold
        // live invoices, so a rollback must not drop them. Drop them by hand if needed.
    }
}
