<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The physical size of the thing being printed.
 *
 * Until now the page was built from the artwork: its pixel dimensions were
 * read as pixels at 96 DPI and turned into millimetres. That works by accident
 * for a certificate, where nobody minds whether it comes out at A4 or a little
 * over, and not at all for an ID card, which has to be 85.6 by 54 millimetres
 * or it will not go in a badge holder. Artwork for one at any sensible
 * resolution would produce a page the size of a poster.
 *
 * Null keeps the old behaviour, so every certificate already in here prints
 * exactly as it did.
 */
class AddCertificatePageSize extends Migration
{
    public function up()
    {
        $this->forge->addColumn('certificates', [
            'page_width_mm' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'null'       => true,
                'after'      => 'orientation',
            ],
            'page_height_mm' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'null'       => true,
                'after'      => 'page_width_mm',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('certificates', ['page_width_mm', 'page_height_mm']);
    }
}
